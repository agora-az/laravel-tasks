<?php

namespace App\Services\Bank;

use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class PdfOcrTextExtractor
{
    private const RENDER_DPI = 300;

    private const MAX_PAGES = 100;

    public function extract(string $pdfPath): string
    {
        $ghostscript = $this->findExecutable(['gs', 'gswin64c']);
        $tesseract = $this->findExecutable(['tesseract']);
        if ($ghostscript === null || $tesseract === null) {
            throw new RuntimeException(
                'The PDF contains no extractable text and local OCR is unavailable. '
                . 'Install Ghostscript and Tesseract OCR.'
            );
        }

        $directory = storage_path('app/bank-pdf-ocr/' . bin2hex(random_bytes(12)));
        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create OCR temporary directory: {$directory}");
        }

        try {
            $this->renderPages($ghostscript, $pdfPath, $directory);
            $pages = glob($directory . DIRECTORY_SEPARATOR . 'page-*.png') ?: [];
            sort($pages, SORT_NATURAL);
            if (empty($pages)) {
                throw new RuntimeException('Ghostscript did not render any PDF pages for OCR.');
            }
            if (count($pages) > self::MAX_PAGES) {
                throw new RuntimeException(
                    'The PDF contains ' . count($pages) . ' pages; the OCR safety limit is ' . self::MAX_PAGES . '.'
                );
            }

            $metadata = $this->extractSparseText($tesseract, $pages[0]);
            $metadata = preg_replace(
                '/(?<=\d)[ \t]+(?=[,.\d])|(?<=[,.])[ \t]+(?=\d)/',
                '',
                $metadata
            ) ?? $metadata;
            $metadata = preg_split('/\bTransaction details\b/i', $metadata, 2)[0] ?? $metadata;
            $identity = $this->extractIdentity($tesseract, $pages);
            $transactionLines = [];
            foreach ($pages as $page) {
                array_push(
                    $transactionLines,
                    ...$this->extractTransactionLines($tesseract, $page)
                );
            }

            if (empty($transactionLines)) {
                throw new RuntimeException('OCR did not identify any CIBC transaction rows.');
            }

            return $identity
                . "\n"
                . trim($metadata)
                . "\n\nTransaction details\n"
                . implode("\n", $transactionLines)
                . "\n";
        } finally {
            $this->cleanup($directory);
        }
    }

    private function renderPages(string $ghostscript, string $pdfPath, string $directory): void
    {
        $process = new Process([
            $ghostscript,
            '-q',
            '-dSAFER',
            '-dBATCH',
            '-dNOPAUSE',
            '-sDEVICE=pnggray',
            '-r' . self::RENDER_DPI,
            '-dTextAlphaBits=4',
            '-dGraphicsAlphaBits=4',
            '-sOutputFile=' . $directory . DIRECTORY_SEPARATOR . 'page-%04d.png',
            $pdfPath,
        ]);
        $process->setTimeout(300);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new RuntimeException(
                'Unable to render the scanned PDF for OCR: ' . trim($process->getErrorOutput())
            );
        }
    }

    private function extractSparseText(string $tesseract, string $page): string
    {
        $process = new Process([
            $tesseract,
            $page,
            'stdout',
            '-l',
            'eng',
            '--psm',
            '11',
        ]);
        $process->setTimeout(120);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new RuntimeException(
                'Tesseract could not read the PDF metadata: ' . trim($process->getErrorOutput())
            );
        }

        return $process->getOutput();
    }

    /**
     * @param array<int, string> $pages
     */
    private function extractIdentity(string $tesseract, array $pages): string
    {
        foreach (array_slice($pages, 0, 3) as $page) {
            $text = $this->extractSparseText($tesseract, $page);
            $hasAccount = preg_match(
                '/Account number.{0,120}?([0-9]{2}-[0-9]{5,})/is',
                $text,
                $accountMatches
            );
            $hasTransit = preg_match(
                '/Branch transit number.{0,400}?\b([0-9]{5})\b/is',
                $text,
                $transitMatches
            );
            if ($hasAccount && $hasTransit) {
                return "Account number\n{$accountMatches[1]}\n"
                    . "Branch transit number\n{$transitMatches[1]}\n";
            }
        }

        throw new RuntimeException('OCR could not identify the CIBC account and branch transit numbers.');
    }

    /**
     * @return array<int, string>
     */
    private function extractTransactionLines(string $tesseract, string $page): array
    {
        $dimensions = getimagesize($page);
        if ($dimensions === false) {
            throw new RuntimeException("Unable to read the rendered OCR page: {$page}");
        }

        $sparseRows = $this->groupWordsByRow(
            $this->extractTsv($tesseract, $page, '11'),
            $dimensions[0]
        );
        $blockRows = $this->groupWordsByRow(
            $this->extractTsv($tesseract, $page, '6'),
            $dimensions[0]
        );
        $rows = $this->mergeRows($sparseRows, $blockRows);
        $tableStartY = null;
        foreach ($rows as $row) {
            if (preg_match('/\btransaction details\b/i', $row['date'] . ' ' . $row['description'])) {
                $tableStartY = $row['center_y'];
                break;
            }
        }
        if ($tableStartY === null) {
            throw new RuntimeException('OCR could not locate the CIBC transaction table on a page.');
        }

        $coreRows = [];
        foreach ($rows as $index => $row) {
            if ($row['center_y'] <= $tableStartY) {
                continue;
            }
            if (($row['withdrawal'] !== null) xor ($row['deposit'] !== null)) {
                $coreRows[$index] = 'transaction';
            } elseif (
                $row['balance'] !== null
                && $row['withdrawal'] === null
                && $row['deposit'] === null
                && preg_match('/\b(opening balance|balance forward|closing balance)\b/i', $row['description'])
            ) {
                $coreRows[$index] = str_contains(strtolower($row['description']), 'closing')
                    ? 'closing'
                    : 'balance';
            }
        }

        $lines = [];
        $currentBalanceCents = null;
        $coreIndexes = array_keys($coreRows);
        foreach ($coreIndexes as $position => $rowIndex) {
            $row = $rows[$rowIndex];
            $type = $coreRows[$rowIndex];
            $date = $this->normalizeDateLabel($row['date']);

            if ($type === 'transaction') {
                $descriptionParts = [$row['description']];
                $nextCoreIndex = $coreIndexes[$position + 1] ?? count($rows);
                for ($detailIndex = $rowIndex + 1; $detailIndex < $nextCoreIndex; $detailIndex++) {
                    $detail = $rows[$detailIndex]['description'];
                    if ($this->isUsefulDetail($detail)) {
                        $descriptionParts[] = $detail;
                    }
                }
                $description = implode(', ', array_values(array_unique($descriptionParts)));
                $amount = $row['withdrawal'] ?? $row['deposit'];
                $amountCents = $this->moneyToCents($amount);
                if ($row['balance'] === null) {
                    if ($currentBalanceCents === null) {
                        throw new RuntimeException(
                            'OCR found a transaction amount before finding the page opening balance.'
                        );
                    }
                    $derivedBalanceCents = $row['withdrawal'] !== null
                        ? $currentBalanceCents - $amountCents
                        : $currentBalanceCents + $amountCents;
                    $row['balance'] = $this->centsToMoney($derivedBalanceCents);
                }

                $reportedBalanceCents = $this->moneyToCents($row['balance']);
                if ($currentBalanceCents !== null) {
                    $derivedAmountCents = $row['withdrawal'] !== null
                        ? $currentBalanceCents - $reportedBalanceCents
                        : $reportedBalanceCents - $currentBalanceCents;
                    if ($derivedAmountCents <= 0) {
                        throw new RuntimeException(
                            'OCR transaction values do not reconcile near: ' . $description
                            . '. Previous ' . $this->centsToMoney($currentBalanceCents)
                            . ', amount ' . $amount
                            . ', reported ' . $row['balance'] . '.'
                        );
                    }
                    if ($derivedAmountCents !== $amountCents) {
                        $amountCents = $derivedAmountCents;
                        $amount = $this->centsToMoney($derivedAmountCents);
                    }
                }
                $currentBalanceCents = $reportedBalanceCents;
                $lines[] = trim("{$date} {$description} {$amount} {$row['balance']}");
            } elseif ($type === 'balance') {
                $balanceCents = $this->moneyToCents($row['balance']);
                if ($currentBalanceCents !== null && $currentBalanceCents !== $balanceCents) {
                    throw new RuntimeException(
                        'OCR balance forward does not match the reconstructed prior page balance.'
                    );
                }
                $currentBalanceCents = $balanceCents;
                $lines[] = trim("{$date} {$row['description']} {$row['balance']}");
            } else {
                $balanceCents = $this->moneyToCents($row['balance']);
                if ($currentBalanceCents !== null && $currentBalanceCents !== $balanceCents) {
                    throw new RuntimeException(
                        'OCR closing balance ' . $this->centsToMoney($balanceCents)
                        . ' does not match the reconstructed transaction balance '
                        . $this->centsToMoney($currentBalanceCents) . '.'
                    );
                }
                $lines[] = 'Closing balance ' . $row['balance'];
            }
        }

        return $lines;
    }

    /**
     * @return array<int, array{
     *     center_y: int,
     *     date: string,
     *     description: string,
     *     withdrawal: ?string,
     *     deposit: ?string,
     *     balance: ?string
     * }>
     */
    private function groupWordsByRow(string $tsv, int $pageWidth): array
    {
        $words = [];
        foreach (preg_split('/\r\n|\r|\n/', $tsv) ?: [] as $line) {
            $columns = explode("\t", $line, 12);
            if (count($columns) !== 12 || $columns[0] !== '5') {
                continue;
            }

            $confidence = (float) $columns[10];
            $text = trim($columns[11]);
            if ($confidence < 15 || $text === '') {
                continue;
            }

            $words[] = [
                'x' => (int) $columns[6],
                'center_y' => (int) $columns[7] + ((int) $columns[9] / 2),
                'height' => (int) $columns[9],
                'text' => $text,
            ];
        }

        usort($words, fn(array $left, array $right) => $left['center_y'] <=> $right['center_y']);
        $grouped = [];
        foreach ($words as $word) {
            $lastIndex = array_key_last($grouped);
            $tolerance = max(14, (int) round($word['height'] * 0.45));
            if (
                $lastIndex === null
                || abs($grouped[$lastIndex]['center_y'] - $word['center_y']) > $tolerance
            ) {
                $grouped[] = [
                    'center_y' => $word['center_y'],
                    'words' => [$word],
                ];
            } else {
                $grouped[$lastIndex]['words'][] = $word;
                $count = count($grouped[$lastIndex]['words']);
                $grouped[$lastIndex]['center_y'] = (int) round(
                    (($grouped[$lastIndex]['center_y'] * ($count - 1)) + $word['center_y']) / $count
                );
            }
        }

        $rows = [];
        foreach ($grouped as $group) {
            usort($group['words'], fn(array $left, array $right) => $left['x'] <=> $right['x']);
            $dateWords = [];
            $descriptionWords = [];
            $withdrawalWords = [];
            $depositWords = [];
            $balanceWords = [];

            foreach ($group['words'] as $word) {
                $ratio = $word['x'] / $pageWidth;
                if ($ratio < 0.145) {
                    $dateWords[] = $word['text'];
                } elseif ($ratio < 0.49) {
                    $descriptionWords[] = $word['text'];
                } elseif ($ratio < 0.64) {
                    $withdrawalWords[] = $word['text'];
                } elseif ($ratio < 0.78) {
                    $depositWords[] = $word['text'];
                } else {
                    $balanceWords[] = $word['text'];
                }
            }

            $withdrawal = $this->normalizeMoneyWords($withdrawalWords);
            $deposit = $this->normalizeMoneyWords($depositWords);
            $balance = $this->normalizeMoneyWords($balanceWords);
            $description = trim(implode(' ', $descriptionWords));
            if (
                empty($dateWords)
                && $description === ''
                && $withdrawal === null
                && $deposit === null
                && $balance === null
            ) {
                continue;
            }

            $rows[] = [
                'center_y' => $group['center_y'],
                'date' => trim(implode(' ', $dateWords)),
                'description' => $description,
                'withdrawal' => $withdrawal,
                'deposit' => $deposit,
                'balance' => $balance,
            ];
        }

        return $rows;
    }

    private function extractTsv(string $tesseract, string $page, string $pageSegmentationMode): string
    {
        $process = new Process([
            $tesseract,
            $page,
            'stdout',
            '-l',
            'eng',
            '--psm',
            $pageSegmentationMode,
            'tsv',
        ]);
        $process->setTimeout(120);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new RuntimeException(
                'Tesseract could not read a PDF transaction page: ' . trim($process->getErrorOutput())
            );
        }

        return $process->getOutput();
    }

    /**
     * @param array<int, array<string, mixed>> $primary
     * @param array<int, array<string, mixed>> $fallback
     * @return array<int, array<string, mixed>>
     */
    private function mergeRows(array $primary, array $fallback): array
    {
        foreach ($fallback as $fallbackRow) {
            $closestIndex = null;
            $closestDistance = PHP_INT_MAX;
            foreach ($primary as $index => $primaryRow) {
                $distance = abs($primaryRow['center_y'] - $fallbackRow['center_y']);
                if ($distance <= 18 && $distance < $closestDistance) {
                    $closestIndex = $index;
                    $closestDistance = $distance;
                }
            }

            if ($closestIndex === null) {
                $primary[] = $fallbackRow;
                continue;
            }

            foreach (['date', 'description', 'withdrawal', 'deposit', 'balance'] as $field) {
                if (($primary[$closestIndex][$field] ?? null) === null || $primary[$closestIndex][$field] === '') {
                    $primary[$closestIndex][$field] = $fallbackRow[$field];
                } elseif (
                    in_array($field, ['withdrawal', 'deposit', 'balance'], true)
                    && $fallbackRow[$field] !== null
                    && strlen(preg_replace('/\D/', '', $fallbackRow[$field]))
                        > strlen(preg_replace('/\D/', '', $primary[$closestIndex][$field]))
                ) {
                    $primary[$closestIndex][$field] = $fallbackRow[$field];
                }
            }
        }

        usort($primary, fn(array $left, array $right) => $left['center_y'] <=> $right['center_y']);

        return $primary;
    }

    /**
     * @param array<int, string> $words
     */
    private function normalizeMoneyWords(array $words): ?string
    {
        if (count($words) > 1) {
            $combined = $this->normalizeMoneyToken(implode('', $words));
            if ($combined !== null) {
                return $combined;
            }
        }

        foreach ($words as $word) {
            $money = $this->normalizeMoneyToken($word);
            if ($money !== null) {
                return $money;
            }
        }

        return null;
    }

    private function normalizeMoneyToken(string $value): ?string
    {
        $normalized = ltrim(trim($value), '$');
        if (!preg_match('/^\d{1,3}(?:,\d{3})*\.\d{2}$/', $normalized)) {
            return null;
        }

        return $normalized;
    }

    private function moneyToCents(string $value): int
    {
        [$whole, $fraction] = explode('.', str_replace(['$', ','], '', $value), 2);

        return ((int) $whole * 100) + (int) $fraction;
    }

    private function centsToMoney(int $cents): string
    {
        return number_format($cents / 100, 2, '.', ',');
    }

    private function normalizeDateLabel(string $value): string
    {
        if (preg_match(
            '/\b(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s*(\d{1,2})\b/i',
            $value,
            $matches
        )) {
            return ucfirst(strtolower($matches[1])) . ' ' . $matches[2];
        }

        return '';
    }

    private function isUsefulDetail(string $value): bool
    {
        if ($value === '' || strlen($value) > 160) {
            return false;
        }

        return preg_match('/[A-Za-z0-9]{2}/', $value) === 1
            && preg_match('/^(date|description|withdrawals|deposits|balance)$/i', $value) !== 1;
    }

    /**
     * @param array<int, string> $names
     */
    private function findExecutable(array $names): ?string
    {
        $finder = new ExecutableFinder();
        foreach ($names as $name) {
            $path = $finder->find($name);
            if ($path !== null) {
                return $path;
            }
        }

        return null;
    }

    private function cleanup(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file) && !unlink($file)) {
                throw new RuntimeException("Unable to remove OCR temporary file: {$file}");
            }
        }

        if (!rmdir($directory)) {
            throw new RuntimeException("Unable to remove OCR temporary directory: {$directory}");
        }
    }
}
