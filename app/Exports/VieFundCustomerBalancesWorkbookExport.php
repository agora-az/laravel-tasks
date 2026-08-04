<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class VieFundCustomerBalancesWorkbookExport implements WithMultipleSheets
{
    private const ACCOUNTING_CURRENCY_FORMAT = '$#,##0.00;[Red]($#,##0.00)';

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
                'Customer Balances',
                true,
                true,
                [
                    'F2:F' . $lastReportRow => '#,##0',
                    'G2:G' . $lastReportRow => self::ACCOUNTING_CURRENCY_FORMAT,
                    'H2:H' . $lastReportRow => '#,##0',
                    'I2:I' . $lastReportRow => self::ACCOUNTING_CURRENCY_FORMAT,
                ]
            ),
            new VieFundReportSheetExport(
                $this->summaryRows,
                'Summary',
                false,
                false,
                [
                    'B11:B15' => self::ACCOUNTING_CURRENCY_FORMAT,
                ]
            ),
        ];
    }
}