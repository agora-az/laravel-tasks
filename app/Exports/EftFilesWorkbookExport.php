<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class EftFilesWorkbookExport implements WithMultipleSheets
{
    private const ACCOUNTING_CURRENCY_FORMAT = '$#,##0.00;[Red]($#,##0.00);$0.00';

    public function __construct(
        private readonly array $fileRows,
        private readonly array $itemRows,
        private readonly array $otherSettlementDateRows,
        private readonly array $otherSettlementDateSubtotalRows,
        private readonly array $otherSettlementDateOutlineGroups
    ) {}

    public function sheets(): array
    {
        $fileFormats = count($this->fileRows) > 1
            ? ['I2:I' . count($this->fileRows) => self::ACCOUNTING_CURRENCY_FORMAT]
            : [];
        $itemFormats = count($this->itemRows) > 1
            ? [
                'I2:I' . count($this->itemRows) => '0',
                'K2:K' . count($this->itemRows) => self::ACCOUNTING_CURRENCY_FORMAT,
            ]
            : [];
        $otherSettlementDateFormats = count($this->otherSettlementDateRows) > 1
            ? [
                'I2:I' . count($this->otherSettlementDateRows) => '0',
                'K2:K' . count($this->otherSettlementDateRows) => self::ACCOUNTING_CURRENCY_FORMAT,
            ]
            : [];

        return [
            new VieFundReportSheetExport($this->fileRows, 'EFT Files', true, true, $fileFormats),
            new VieFundReportSheetExport($this->itemRows, 'EFT Items', true, true, $itemFormats),
            new VieFundReportSheetExport(
                $this->otherSettlementDateRows,
                'Other Settlement Dates',
                true,
                false,
                $otherSettlementDateFormats,
                $this->otherSettlementDateSubtotalRows,
                $this->otherSettlementDateOutlineGroups
            ),
        ];
    }
}
