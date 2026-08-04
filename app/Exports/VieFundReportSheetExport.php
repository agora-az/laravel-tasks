<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class VieFundReportSheetExport implements FromArray, WithColumnWidths, WithEvents, WithStrictNullComparison, WithTitle
{
    /**
     * @param array<int, array<int, string|int|float|null>> $rows
     */
    public function __construct(
        private readonly array $rows,
        private readonly string $title,
        private readonly bool $freezeHeaderRow = false,
        private readonly bool $autoFilter = false,
        private readonly array $numberFormats = []
    ) {}

    public function array(): array
    {
        return $this->rows;
    }

    /**
     * @return array<string, float>
     */
    public function columnWidths(): array
    {
        $widths = [];

        foreach ($this->rows as $row) {
            foreach (array_values($row) as $index => $value) {
                $column = Coordinate::stringFromColumnIndex($index + 1);
                $widths[$column] = max($widths[$column] ?? 0, $this->measureWidth($value));
            }
        }

        foreach ($widths as $column => $width) {
            $widths[$column] = $width + 2;
        }

        return $widths;
    }

    public function title(): string
    {
        return mb_substr($this->title, 0, 31);
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $highestColumn = $event->sheet->getHighestColumn();
                $highestRow = $event->sheet->getHighestRow();
                $event->sheet->getStyle('A1:' . $highestColumn . '1')->getFont()->setBold(true);

                if ($this->freezeHeaderRow) {
                    $event->sheet->freezePane('A2');
                }

                if ($this->autoFilter) {
                    $event->sheet->setAutoFilter('A1:' . $highestColumn . $highestRow);
                }

                foreach ($this->numberFormats as $range => $formatCode) {
                    $event->sheet->getStyle($range)->getNumberFormat()->setFormatCode($formatCode);
                }
            },
        ];
    }

    private function measureWidth(mixed $value): int
    {
        if ($value === null) {
            return 0;
        }

        $text = str_replace(["\r", "\n"], ' ', (string) $value);

        return mb_strlen($text);
    }
}
