<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class EftFilesWorkbookExport implements WithMultipleSheets
{
    private const ACCOUNTING_CURRENCY_FORMAT = '$#,##0.00;[Red]($#,##0.00);$0.00';

    public function __construct(
        private readonly array $fileRows,
        private readonly array $itemRows
    ) {}

    public function sheets(): array
    {
        $fileFormats = count($this->fileRows) > 1
            ? ['I2:I' . count($this->fileRows) => self::ACCOUNTING_CURRENCY_FORMAT]
            : [];
        $itemFormats = count($this->itemRows) > 1
            ? ['I2:I' . count($this->itemRows) => self::ACCOUNTING_CURRENCY_FORMAT]
            : [];

        return [
            new VieFundReportSheetExport($this->fileRows, 'EFT Files', true, true, $fileFormats),
            new VieFundReportSheetExport($this->itemRows, 'EFT Items', true, true, $itemFormats),
        ];
    }
}