<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class BankStatementEntriesWorkbookExport implements WithMultipleSheets
{
    private const ACCOUNTING_CURRENCY_FORMAT = '$#,##0.00;[Red]($#,##0.00);$0.00';

    /**
     * @param array<int, array<int, string|int|float|null>> $transactionRows
     * @param array<int, array<int, string|int|float|null>> $summaryRows
     */
    public function __construct(
        private readonly array $transactionRows,
        private readonly array $summaryRows
    ) {}

    public function sheets(): array
    {
        $transactionNumberFormats = [];
        if (count($this->transactionRows) > 1) {
            $lastTransactionRow = count($this->transactionRows);
            $transactionNumberFormats = [
                'D2:D' . $lastTransactionRow => self::ACCOUNTING_CURRENCY_FORMAT,
            ];
        }

        $summaryNumberFormats = [];
        if (count($this->summaryRows) > 1) {
            $lastSummaryRow = count($this->summaryRows);
            $summaryNumberFormats = [
                'F2:G' . $lastSummaryRow => self::ACCOUNTING_CURRENCY_FORMAT,
                'I2:I' . $lastSummaryRow => self::ACCOUNTING_CURRENCY_FORMAT,
                'K2:K' . $lastSummaryRow => self::ACCOUNTING_CURRENCY_FORMAT,
            ];
        }

        return [
            new VieFundReportSheetExport(
                $this->transactionRows,
                'Bank Entries',
                true,
                true,
                $transactionNumberFormats
            ),
            new VieFundReportSheetExport(
                $this->summaryRows,
                'CAMT Summaries',
                true,
                true,
                $summaryNumberFormats
            ),
        ];
    }
}
