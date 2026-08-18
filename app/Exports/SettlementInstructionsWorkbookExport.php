<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class SettlementInstructionsWorkbookExport implements WithMultipleSheets
{
    private const ACCOUNTING_CURRENCY_FORMAT = '$#,##0.00;[Red]($#,##0.00);$0.00';

    /**
     * @param array<int, array<int, string|int|float|null>> $agraTransactionRows
     * @param array<int, array<int, string|int|float|null>> $agraSummaryRows
     * @param array<int, array<int, string|int|float|null>> $ltmTransactionRows
     * @param array<int, array<int, string|int|float|null>> $ltmSummaryRows
     */
    public function __construct(
        private readonly array $agraTransactionRows,
        private readonly array $agraSummaryRows,
        private readonly array $ltmTransactionRows,
        private readonly array $ltmSummaryRows
    ) {}

    public function sheets(): array
    {
        return [
            $this->transactionSheet($this->agraTransactionRows, 'FundSERV AGRA Transactions'),
            $this->summarySheet($this->agraSummaryRows, 'FundSERV AGRA Summary'),
            $this->transactionSheet($this->ltmTransactionRows, 'LTM Transactions'),
            $this->summarySheet($this->ltmSummaryRows, 'LTM Summary'),
        ];
    }

    private function transactionSheet(array $rows, string $title): VieFundReportSheetExport
    {
        $numberFormats = [];
        if (count($rows) > 1) {
            $lastRow = count($rows);
            $numberFormats = [
                'J2:L' . $lastRow => self::ACCOUNTING_CURRENCY_FORMAT,
            ];
        }

        return new VieFundReportSheetExport($rows, $title, true, true, $numberFormats);
    }

    private function summarySheet(array $rows, string $title): VieFundReportSheetExport
    {
        $numberFormats = [];
        if (count($rows) > 1) {
            $lastRow = count($rows);
            $lastAmountColumn = count($rows[0]) === 13 ? 'M' : 'L';
            $numberFormats = [
                'J2:' . $lastAmountColumn . $lastRow => self::ACCOUNTING_CURRENCY_FORMAT,
            ];
        }

        return new VieFundReportSheetExport($rows, $title, true, true, $numberFormats);
    }
}