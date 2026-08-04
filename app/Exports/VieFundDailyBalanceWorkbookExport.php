<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class VieFundDailyBalanceWorkbookExport implements WithMultipleSheets
{
    private const ACCOUNTING_CURRENCY_FORMAT = '$#,##0.00;[Red]($#,##0.00);$0.00';

    /**
     * @param array<int, array<int, string|int|float|null>> $reportRows
     * @param array<int, array<int, string|int|float|null>> $summaryRows
     */
    public function __construct(
        private readonly array $reportRows,
        private readonly array $summaryRows
    ) {}

    public function sheets(): array
    {
        $lastReportRow = count($this->reportRows);

        return [
            new VieFundReportSheetExport(
                $this->reportRows,
                'Daily Balance',
                true,
                true,
                [
                    'B2:B' . $lastReportRow => '#,##0',
                    'C2:D' . $lastReportRow => self::ACCOUNTING_CURRENCY_FORMAT,
                ]
            ),
            new VieFundReportSheetExport(
                $this->summaryRows,
                'Summary',
                false,
                false,
                [
                    'B12:B13' => self::ACCOUNTING_CURRENCY_FORMAT,
                ]
            ),
        ];
    }
}