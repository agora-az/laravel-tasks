<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class VieFundCustomerBalancesWorkbookExport implements WithMultipleSheets
{
    private const ACCOUNTING_CURRENCY_FORMAT = '$#,##0.00;[Red]($#,##0.00);$0.00';

    /**
     * @param array<int, array<int, string|int|float|null>> $reportRows
     * @param array<int, array<int, string|int|float|null>> $summaryRows
     * @param array<int, array<int, string|int|float|null>> $reviewRows
     */
    public function __construct(
        private readonly array $reportRows,
        private readonly array $summaryRows,
        private readonly array $reviewRows
    ) {}

    public function sheets(): array
    {
        $lastReportRow = count($this->reportRows);
        $reviewNumberFormats = [];
        if (count($this->reviewRows) > 1) {
            $reviewNumberFormats = [
                'H2:H' . count($this->reviewRows) => self::ACCOUNTING_CURRENCY_FORMAT,
                'L2:L' . count($this->reviewRows) => self::ACCOUNTING_CURRENCY_FORMAT,
                'N2:N' . count($this->reviewRows) => self::ACCOUNTING_CURRENCY_FORMAT,
            ];
        }

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
                    'B13:B14' => self::ACCOUNTING_CURRENCY_FORMAT,
                    'B17:B18' => self::ACCOUNTING_CURRENCY_FORMAT,
                ]
            ),
            new VieFundReportSheetExport(
                $this->reviewRows,
                'Cutoff Review',
                true,
                true,
                $reviewNumberFormats
            ),
        ];
    }
}
