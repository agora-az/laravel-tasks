<?php

namespace App\Console\Commands;

use App\Services\VieFund\VieFundRemoteService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class GenerateVieFundCustomerBalancesReportCommand extends Command
{
    private const DATE_BASIS_LABELS = [
        'create_date' => 'Created date',
        'trade_date' => 'Trade date',
        'processing_date' => 'Processing date',
        'settlement_date' => 'Settlement date',
    ];
    private const FUND_STATUS_LABELS = [
        0 => 'Deleted',
        1 => 'Rejected',
        2 => 'Cancelled',
        3 => 'Pending',
        4 => 'Accepted',
        5 => 'Contracted',
        6 => 'Confirmed',
    ];
    private const TRUST_STATUS_NAMES = ['Deleted', 'Unsettled', 'Settled'];
    private const DEFAULT_STATUSES = [6];
    private const BALANCE_SOURCE_LABELS = [
        'transaction_rollup' => 'Transaction Rollup',
        'cash_snapshot' => 'Cash Snapshot',
    ];

    protected $signature = 'report:viefund-customer-balances
        {--report-date= : Report date (YYYY-MM-DD)}
        {--date-basis=settlement_date : create_date|trade_date|processing_date|settlement_date}
        {--status=* : Fund status IDs 0-6}
        {--trust-status=* : Trust status names (Deleted|Unsettled|Settled). Empty excludes trust}
        {--balance-source=transaction_rollup : transaction_rollup|cash_snapshot}
        {--format=csv : csv|excel}
        {--output-file= : Relative output path under storage/app}
        {--status-file= : Optional status file path}
        {--lock-file= : Optional lock file path}';

    protected $description = 'Generate VieFund customer balances report asynchronously';

    public function __construct(
        private readonly VieFundRemoteService $vieFundRemoteService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $lockFile = $this->resolveString($this->option('lock-file'));
        $statusFile = $this->resolveString($this->option('status-file'));

        if ($lockFile) {
            @file_put_contents($lockFile, date('c'));
        }

        try {
            return $this->runReport($statusFile);
        } catch (\Throwable $e) {
            $this->writeStatus($statusFile, [
                'inProgress' => false,
                'success' => false,
                'message' => 'Report failed: ' . $e->getMessage(),
                'updated_at' => now()->toIso8601String(),
                'completed_at' => now()->toIso8601String(),
            ]);
            throw $e;
        } finally {
            if ($lockFile && file_exists($lockFile)) {
                @unlink($lockFile);
            }
        }
    }

    private function runReport(?string $statusFile): int
    {
        $reportDateRaw = $this->resolveString($this->option('report-date'));
        $dateBasis = $this->resolveString($this->option('date-basis')) ?? 'settlement_date';
        $statuses = $this->resolveStatuses($this->option('status'));
        $trustStatuses = $this->resolveTrustStatuses($this->option('trust-status'));
        $balanceSource = $this->resolveString($this->option('balance-source')) ?? 'transaction_rollup';
        $format = $this->resolveString($this->option('format')) ?? 'csv';
        $outputRelativePath = $this->resolveString($this->option('output-file'));

        if (!$reportDateRaw || !$outputRelativePath) {
            $this->error('Missing required options: --report-date, --output-file.');
            return self::FAILURE;
        }

        if (!isset(self::DATE_BASIS_LABELS[$dateBasis])) {
            $this->error('Invalid --date-basis value.');
            return self::FAILURE;
        }

        if (!in_array($format, ['csv', 'excel'], true)) {
            $this->error('Invalid --format value.');
            return self::FAILURE;
        }

        if (!isset(self::BALANCE_SOURCE_LABELS[$balanceSource])) {
            $this->error('Invalid --balance-source value.');
            return self::FAILURE;
        }

        $reportDate = Carbon::parse($reportDateRaw)->startOfDay();
        $dateBasisLabel = self::DATE_BASIS_LABELS[$dateBasis];
        $statusLabel = $this->describeStatuses($statuses);
        $trustLabel = $trustStatuses ? implode(', ', $trustStatuses) : 'Excluded';
        $simulatedGenerationTime = trim((string) env('VIEFUND_BALANCE_REPORT_CASH_OPENED_BEFORE', ''));

        $outputRelativePath = ltrim($outputRelativePath, '/');
        if (!str_starts_with($outputRelativePath, 'reports/')) {
            $outputRelativePath = 'reports/' . basename($outputRelativePath);
        }

        $outputAbsolutePath = storage_path('app/' . $outputRelativePath);
        $outputDir = dirname($outputAbsolutePath);
        if (!is_dir($outputDir)) {
            @mkdir($outputDir, 0775, true);
        }

        $startedAt = microtime(true);
        $startedAtIso = now()->toIso8601String();

        $this->writeStatus($statusFile, [
            'inProgress' => true,
            'success' => null,
            'message' => 'Fetching plan account balances...',
            'report_date' => $reportDate->toDateString(),
            'date_basis' => $dateBasisLabel,
            'status' => $statusLabel,
            'trust_status' => $trustLabel,
            'format' => strtoupper($format),
            'processed_accounts' => 0,
            'total_accounts' => null,
            'progress_pct' => 0,
            'output_relative_path' => $outputRelativePath,
            'started_at' => $startedAtIso,
            'updated_at' => now()->toIso8601String(),
        ]);

        $balances = $this->vieFundRemoteService->fetchCustomerBalancesByDate($reportDate, $dateBasis, [
            'status_ids' => $statuses,
            'trust_status_names' => $trustStatuses,
        ]);

        $cashSnapshotByAccountId = collect();
        $cashSnapshotByPlanId = collect();
        if ($balanceSource === 'cash_snapshot') {
            $cashSnapshots = $this->vieFundRemoteService->fetchCustomerCashBalancesSnapshot();
            $cashSnapshotByAccountId = $cashSnapshots->keyBy(fn($row) => $this->normalizeAccountIdentifier((string) ($row->account_id ?? '')));
            $cashSnapshotByPlanId = $cashSnapshots->keyBy(fn($row) => $this->normalizeAccountIdentifier((string) ($row->plan_account_id ?? '')));
        }

        $totalAccounts = $balances->count();
        $simulatedClientCashTotal = $this->vieFundRemoteService->fetchCustomerCashBalanceTotal();
        $totalBalance = 0.0;
        $rows = [];

        foreach ($balances->values() as $index => $row) {
            $computedBalance = (float) ($row->total_balance ?? 0);
            if ($balanceSource === 'cash_snapshot') {
                $normalizedAccountId = $this->normalizeAccountIdentifier((string) ($row->account_id ?? ''));
                $normalizedPlanId = $this->normalizeAccountIdentifier((string) ($row->plan_account_id ?? ''));
                $snapshotRow = $cashSnapshotByAccountId->get($normalizedAccountId)
                    ?? $cashSnapshotByPlanId->get($normalizedPlanId);
                if ($snapshotRow !== null) {
                    $computedBalance = (float) ($snapshotRow->cash_balance ?? 0);
                } else {
                    $computedBalance = 0.0;
                }
            }

            $totalBalance += $computedBalance;
            $rows[] = [
                trim((string) ($row->client_name ?? '')),
                (string) ($row->rep_code ?? ''),
                (string) ($row->plan_account_id ?? ''),
                (string) ($row->account_id ?? ''),
                (string) ($row->account_status ?? ''),
                number_format((int) ($row->fund_transaction_count ?? 0)),
                number_format((int) ($row->trust_transaction_count ?? 0)),
                $this->formatAccountingCurrency((float) ($row->fund_balance ?? 0)),
                $this->formatAccountingCurrency((float) ($row->trust_balance ?? 0)),
                $this->formatAccountingCurrency($computedBalance),
            ];

            $processedAccounts = $index + 1;
            $progressPct = $totalAccounts > 0
                ? (int) floor(($processedAccounts / $totalAccounts) * 100)
                : 100;

            $this->writeStatus($statusFile, [
                'inProgress' => true,
                'success' => null,
                'message' => sprintf('Writing report rows (%d/%d)...', $processedAccounts, $totalAccounts),
                'report_date' => $reportDate->toDateString(),
                'date_basis' => $dateBasisLabel,
                'status' => $statusLabel,
                'trust_status' => $trustLabel,
                'format' => strtoupper($format),
                'processed_accounts' => $processedAccounts,
                'total_accounts' => $totalAccounts,
                'progress_pct' => min(99, $progressPct),
                'output_relative_path' => $outputRelativePath,
                'started_at' => $startedAtIso,
                'updated_at' => now()->toIso8601String(),
            ]);
        }

        $metadataRows = [
            ['Report', 'VieFund Customer Balances'],
            ['Report Date', $reportDate->toDateString()],
            ['Date Basis', $dateBasisLabel],
            ['Balance Source', self::BALANCE_SOURCE_LABELS[$balanceSource]],
            ['Fund Statuses', $statusLabel],
            ['Trust Statuses', $trustLabel],
            ['Simulated Generation Time', $simulatedGenerationTime !== '' ? $simulatedGenerationTime : 'Not set'],
            ['Plan Accounts', number_format($totalAccounts)],
            ['Generated At', now()->toDateTimeString()],
            ['Simulated Client Cash Total', $this->formatAccountingCurrency($simulatedClientCashTotal)],
            ['Total Balance', $this->formatAccountingCurrency($totalBalance)],
        ];

        $sheetRows = $this->buildSheetRowsWithSideMetadata([
            'Client Name',
            'Rep Code',
            'Plan Account ID',
            'Account ID',
            'Account Status',
            'Fund Transactions',
            'Trust Transactions',
            'Fund Balance',
            'Trust Balance',
            'Balance (CAD)',
        ], $rows, $metadataRows);

        $writer = $this->openWriter($outputAbsolutePath, $format);
        foreach ($sheetRows as $sheetRow) {
            $writer($sheetRow);
        }
        $writer(null);

        $duration = round(microtime(true) - $startedAt, 2);

        $this->writeStatus($statusFile, [
            'inProgress' => false,
            'success' => true,
            'message' => sprintf('Report completed in %ss. %s plan accounts written.', $duration, number_format($totalAccounts)),
            'report_date' => $reportDate->toDateString(),
            'date_basis' => $dateBasisLabel,
            'status' => $statusLabel,
            'trust_status' => $trustLabel,
            'format' => strtoupper($format),
            'processed_accounts' => $totalAccounts,
            'total_accounts' => $totalAccounts,
            'progress_pct' => 100,
            'output_relative_path' => $outputRelativePath,
            'started_at' => $startedAtIso,
            'updated_at' => now()->toIso8601String(),
            'completed_at' => now()->toIso8601String(),
        ]);

        $this->info('Report generated: ' . $outputRelativePath);

        return self::SUCCESS;
    }

    private function writeStatus(?string $statusFile, array $payload): void
    {
        if (!$statusFile) {
            return;
        }

        $statusDir = dirname($statusFile);
        if (!is_dir($statusDir)) {
            @mkdir($statusDir, 0775, true);
        }

        @file_put_contents($statusFile, json_encode($payload, JSON_PRETTY_PRINT));
    }

    private function openWriter(string $absolutePath, string $format): callable
    {
        $handle = fopen($absolutePath, 'w');
        if (!$handle) {
            throw new \RuntimeException('Unable to open report output file for writing.');
        }

        if ($format === 'csv') {
            // Prefix CSV with UTF-8 BOM so Excel auto-detects encoding correctly.
            fwrite($handle, "\xEF\xBB\xBF");

            return function (?array $row) use ($handle): void {
                if ($row === null) {
                    fclose($handle);
                    return;
                }

                fputcsv($handle, $row);
            };
        }

        return function (?array $row) use ($handle): void {
            if ($row === null) {
                fclose($handle);
                return;
            }

            $escaped = array_map(function ($value): string {
                $text = (string) $value;
                return str_replace(["\t", "\r", "\n"], ' ', $text);
            }, $row);

            fwrite($handle, implode("\t", $escaped) . "\r\n");
        };
    }

    private function buildSheetRowsWithSideMetadata(array $headers, array $rows, array $metadataRows): array
    {
        $sheetRows = [];
        $dataRows = [$headers, ...$rows];
        $blankDataPart = array_fill(0, count($headers), '');
        $maxRows = max(count($dataRows), count($metadataRows));

        for ($i = 0; $i < $maxRows; $i++) {
            $dataPart = $dataRows[$i] ?? $blankDataPart;
            $metadataPart = $metadataRows[$i] ?? ['', ''];
            $sheetRows[] = [...$dataPart, '', $metadataPart[0], $metadataPart[1]];
        }

        return $sheetRows;
    }

    private function resolveString(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = collect($value)->first(fn($item) => $item !== null && $item !== '');
        }

        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function resolveStatuses(mixed $raw): array
    {
        $values = array_map('intval', (array) $raw);
        $values = array_values(array_unique(array_filter(
            $values,
            fn($id) => array_key_exists($id, self::FUND_STATUS_LABELS)
        )));

        return $values ?: self::DEFAULT_STATUSES;
    }

    private function resolveTrustStatuses(mixed $raw): array
    {
        return array_values(array_intersect(self::TRUST_STATUS_NAMES, (array) $raw));
    }

    private function describeStatuses(array $statuses): string
    {
        $labels = array_map(fn($id) => self::FUND_STATUS_LABELS[$id] ?? $id, $statuses);

        return $labels ? implode(', ', $labels) : 'None';
    }

    private function normalizeAccountIdentifier(string $value): string
    {
        $normalized = trim($value);
        if (str_starts_with($normalized, '#')) {
            $normalized = substr($normalized, 1);
        }

        return trim($normalized);
    }

    private function formatAccountingCurrency(float $amount): string
    {
        $formatted = '$' . number_format(abs($amount), 2, '.', ',');
        if ($amount < 0) {
            return '(' . $formatted . ')';
        }

        return $formatted;
    }
}