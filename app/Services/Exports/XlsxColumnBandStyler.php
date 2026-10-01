<?php

namespace App\Services\Exports;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use ZipArchive;

class XlsxColumnBandStyler
{
    /**
     * Apply existing workbook styles as the defaults for complete column bands.
     *
     * This keeps empty cells visually grouped without materializing a styled cell
     * for every row in the range.
     *
     * @param array<int, array{start: int, end: int, background: string}> $bands
     */
    public function apply(string $workbookPath, int $sheetCount, array $bands): void
    {
        if ($sheetCount < 1 || $bands === []) {
            return;
        }

        $archive = new ZipArchive();
        if ($archive->open($workbookPath) !== true) {
            throw new RuntimeException('Unable to open the generated Excel workbook for column styling.');
        }

        $temporaryFiles = [];

        try {
            $stylesXml = $archive->getFromName('xl/styles.xml');
            if ($stylesXml === false) {
                throw new RuntimeException('The generated Excel workbook does not contain a style definition.');
            }

            $styleIds = $this->styleIdsByBackground($stylesXml, $bands);

            for ($sheetIndex = 1; $sheetIndex <= $sheetCount; ++$sheetIndex) {
                $entryName = sprintf('xl/worksheets/sheet%d.xml', $sheetIndex);
                $temporaryPath = tempnam(sys_get_temp_dir(), 'xlsx-column-bands-');
                if ($temporaryPath === false) {
                    throw new RuntimeException('Unable to create a temporary worksheet for column styling.');
                }
                $temporaryFiles[] = $temporaryPath;

                $this->writeStyledWorksheet($archive, $entryName, $temporaryPath, $bands, $styleIds);
            }

            foreach ($temporaryFiles as $sheetOffset => $temporaryPath) {
                $entryName = sprintf('xl/worksheets/sheet%d.xml', $sheetOffset + 1);
                if (!$archive->deleteName($entryName) || !$archive->addFile($temporaryPath, $entryName)) {
                    throw new RuntimeException("Unable to update {$entryName} with compact column styling.");
                }
                $archive->setCompressionName($entryName, ZipArchive::CM_DEFLATE, 9);
            }

            if (!$archive->close()) {
                throw new RuntimeException('Unable to finalize the generated Excel workbook after column styling.');
            }
        } catch (\Throwable $exception) {
            $archive->unchangeAll();
            $archive->close();
            throw $exception;
        } finally {
            foreach ($temporaryFiles as $temporaryPath) {
                @unlink($temporaryPath);
            }
        }
    }

    /**
     * @param array<int, array{start: int, end: int, background: string}> $bands
     * @return array<string, int>
     */
    private function styleIdsByBackground(string $stylesXml, array $bands): array
    {
        $document = new DOMDocument();
        if (!$document->loadXML($stylesXml, LIBXML_NONET)) {
            throw new RuntimeException('Unable to parse Excel styles for column styling.');
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $fillIdsByBackground = [];
        $fills = $xpath->query('/x:styleSheet/x:fills/x:fill');
        if ($fills === false) {
            throw new RuntimeException('Unable to locate Excel fill styles for column styling.');
        }
        foreach ($fills as $fillId => $fill) {
            $foreground = $xpath->query('x:patternFill/x:fgColor', $fill)?->item(0);
            if ($foreground instanceof DOMElement && $foreground->hasAttribute('rgb')) {
                $fillIdsByBackground[$this->normalizeColor($foreground->getAttribute('rgb'))] = $fillId;
            }
        }

        $cellStyles = $xpath->query('/x:styleSheet/x:cellXfs/x:xf');
        if ($cellStyles === false) {
            throw new RuntimeException('Unable to locate Excel cell styles for column styling.');
        }
        $requestedBackgrounds = collect($bands)
            ->pluck('background')
            ->map(fn(string $background) => $this->normalizeColor($background))
            ->unique()
            ->values();
        $styleIds = [];

        foreach ($requestedBackgrounds as $background) {
            $fillId = $fillIdsByBackground[$background] ?? null;
            if ($fillId === null) {
                throw new RuntimeException("Unable to find the Excel fill {$background} for column styling.");
            }

            foreach ($cellStyles as $styleId => $cellStyle) {
                if ($cellStyle instanceof DOMElement && (int) $cellStyle->getAttribute('fillId') === $fillId) {
                    $styleIds[$background] = $styleId;
                    break;
                }
            }

            if (!array_key_exists($background, $styleIds)) {
                throw new RuntimeException("Unable to find an Excel cell style for fill {$background}.");
            }
        }

        return $styleIds;
    }

    /**
     * @param resource $worksheetStream
     * @param resource $temporaryStream
     * @param array<int, array{start: int, end: int, background: string}> $bands
     * @param array<string, int> $styleIds
     */
    private function writeStyledWorksheet(
        ZipArchive $archive,
        string $entryName,
        string $temporaryPath,
        array $bands,
        array $styleIds
    ): void {
        $worksheetStream = $archive->getStream($entryName);
        if ($worksheetStream === false) {
            throw new RuntimeException("Unable to read {$entryName} from the generated Excel workbook.");
        }

        $temporaryStream = fopen($temporaryPath, 'wb');
        if ($temporaryStream === false) {
            fclose($worksheetStream);
            throw new RuntimeException('Unable to open a temporary worksheet for column styling.');
        }

        try {
            $prefix = '';
            while (!str_contains($prefix, '</cols>') && !feof($worksheetStream)) {
                $chunk = fread($worksheetStream, 65536);
                if ($chunk === false) {
                    throw new RuntimeException("Unable to read {$entryName} while applying column styling.");
                }
                $prefix .= $chunk;
                if (strlen($prefix) > 2 * 1024 * 1024) {
                    throw new RuntimeException("Unable to locate column definitions near the start of {$entryName}.");
                }
            }

            $columnsEnd = strpos($prefix, '</cols>');
            if ($columnsEnd === false) {
                throw new RuntimeException("The worksheet {$entryName} does not contain column definitions.");
            }
            $columnsEnd += strlen('</cols>');

            $worksheetStart = substr($prefix, 0, $columnsEnd);
            $remainingPrefix = substr($prefix, $columnsEnd);
            $styledStart = preg_replace_callback(
                '/<col\b([^>]*)\/>/',
                function (array $match) use ($bands, $styleIds): string {
                    if (
                        !preg_match('/\bmin="(\d+)"/', $match[1], $minimumMatch)
                        || !preg_match('/\bmax="(\d+)"/', $match[1], $maximumMatch)
                    ) {
                        return $match[0];
                    }

                    $minimum = (int) $minimumMatch[1];
                    $maximum = (int) $maximumMatch[1];
                    foreach ($bands as $band) {
                        if ($minimum >= $band['start'] && $maximum <= $band['end']) {
                            $background = $this->normalizeColor($band['background']);
                            $attributes = preg_replace('/\sstyle="\d+"/', '', $match[1]);

                            return '<col' . $attributes . ' style="' . $styleIds[$background] . '"/>';
                        }
                        if ($minimum <= $band['end'] && $maximum >= $band['start']) {
                            throw new RuntimeException('A worksheet column-width range crosses a matched-data style boundary.');
                        }
                    }

                    return $match[0];
                },
                $worksheetStart
            );
            if ($styledStart === null) {
                throw new RuntimeException("Unable to apply compact column styling to {$entryName}.");
            }

            fwrite($temporaryStream, $styledStart);
            fwrite($temporaryStream, $remainingPrefix);
            stream_copy_to_stream($worksheetStream, $temporaryStream);
        } finally {
            fclose($worksheetStream);
            fclose($temporaryStream);
        }
    }

    private function normalizeColor(string $color): string
    {
        return strtoupper(substr(ltrim($color, '#'), -6));
    }
}
