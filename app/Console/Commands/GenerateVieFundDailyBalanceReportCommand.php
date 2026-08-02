<?php

namespace App\Console\Commands;

use App\Services\VieFund\VieFundRemoteService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class GenerateVieFundDailyBalanceReportCommand extends Command
{
    private const FETCH_CHUNK_DAYS = 31;
    private const FETCH_WEIGHT = 0.7;
    private const DATE_BASIS_LABELS = [
        'create_date' => 'Created date',
        'trade_date' => 'Trade date',
        'processing_date' => 'Processing date',
        'settlement_date' => 'Settlement date',
    ];
    private const OUTPUT_ORDER_LABELS = [
        'asc' => 'Earliest first',
        'desc' => 'Latest first',
    ];
    private const STATUS_GROUP_LABELS = [
        'completed' => 'Completed',
        'open' => 'Open',
        'not_completed' => 'Not Completed',
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
    /** Legacy status-group → fund status IDs / trust status names, for back-compat. */
    private const STATUS_GROUP_IDS = [
        'not_completed' => [0, 1, 2],
        'open' => [3, 4],
        'completed' => [5, 6],
    ];
    private const STATUS_GROUP_TRUST_NAMES = [
        'not_completed' => ['Deleted'],
        'open' => ['Unsettled'],
        'completed' => ['Settled'],
    ];
    private const DEFAULT_STATUSES = [6];
    private const DEFAULT_TRUST_STATUSES = ['Settled'];

    protected $signature = 'report:viefund-daily-balance
        {--date-from= : Report start date (YYYY-MM-DD)}
        {--date-to= : Report end date (YYYY-MM-DD)}
        {--date-basis=settlement_date : create_date|trade_date|processing_date|settlement_date}
        {--output-order=asc : asc|desc}
        {--status=* : Fund status IDs 0-6 (Deleted..Confirmed). Falls back to legacy --status-group}
        {--trust-status=* : Trust status names (Deleted|Unsettled|Settled). Empty excludes trust}
        {--status-group=* : DEPRECATED: completed|open|not_completed (mapped to --status when --status is absent)}
        {--include-trust=1 : DEPRECATED: 1/0 (used with --status-group when --trust-status is absent)}
        {--format=csv : csv|excel}
        {--output-file= : Relative output path under storage/app}
        {--status-file= : Optional status file path}
        {--lock-file= : Optional lock file path}';

    protected $description = 'Generate VieFund daily net + running balance report asynchronously';

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
        $dateFromRaw = $this->resolveString($this->option('date-from'));
        $dateToRaw = $this->resolveString($this->option('date-to'));
        $dateBasis = $this->resolveString($this->option('date-basis')) ?? 'settlement_date';
        $outputOrder = $this->resolveString($this->option('output-order')) ?? 'asc';
        [$statuses, $trustStatuses] = $this->resolveStatusFilters();
        $format = $this->resolveString($this->option('format')) ?? 'csv';
        $outputRelativePath = $this->resolveString($this->option('output-file'));

        if (!$dateFromRaw || !$dateToRaw || !$outputRelativePath) {
            $this->error('Missing required options: --date-from, --date-to, --output-file.');
            return self::FAILURE;
        }

        if (!in_array($dateBasis, ['create_date', 'trade_date', 'processing_date', 'settlement_date'], true)) {
            $this->error('Invalid --date-basis value.');
            return self::FAILURE;
        }

        $dateBasisLabel = self::DATE_BASIS_LABELS[$dateBasis];

        if (!in_array($outputOrder, ['asc', 'desc'], true)) {
            $this->error('Invalid --output-order value.');
            return self::FAILURE;
        }

        $outputOrderLabel = self::OUTPUT_ORDER_LABELS[$outputOrder];
        $statusLabel = $this->describeStatuses($statuses);
        $trustLabel = $trustStatuses ? implode(', ', $trustStatuses) : 'Excluded';

        if (!in_array($format, ['csv', 'excel'], true)) {
            $this->error('Invalid --format value.');
            return self::FAILURE;
        }

        $dateFrom = Carbon::parse($dateFromRaw)->startOfDay();
        $dateTo = Carbon::parse($dateToRaw)->startOfDay();
        if ($dateFrom->gt($dateTo)) {
            $this->error('date-from must be before or equal to date-to.');
            return self::FAILURE;
        }

        $outputRelativePath = ltrim($outputRelativePath, '/');
        if (!str_starts_with($outputRelativePath, 'reports/')) {
            $outputRelativePath = 'reports/' . basename($outputRelativePath);
        }

        $outputAbsolutePath = storage_path('app/' . $outputRelativePath);
        $outputDir = dirname($outputAbsolutePath);
        if (!is_dir($outputDir)) {
            @mkdir($outputDir, 0775, true);
        }

        $totalDays = $dateFrom->diffInDays($dateTo) + 1;
        $startedAt = microtime(true);
        $startedAtIso = now()->toIso8601String();

        $this->writeStatus($statusFile, [
            'inProgress' => true,
            'success' => null,
            'message' => 'Preparing report generation...',
            'date_from' => $dateFrom->toDateString(),
            'date_to' => $dateTo->toDateString(),
            'date_basis' => $dateBasisLabel,
            'output_order' => $outputOrderLabel,
            'status' => $statusLabel,
            'trust_status' => $trustLabel,
            'format' => strtoupper($format),
            'processed_days' => 0,
            'total_days' => $totalDays,
            'progress_pct' => 0,
            'output_relative_path' => $outputRelativePath,
            'started_at' => $startedAtIso,
            'updated_at' => now()->toIso8601String(),
        ]);

        $dailyMap = [];
        $fetchCursor = $dateFrom->copy();
        $fetchedDays = 0;

        while ($fetchCursor->lte($dateTo)) {
            $chunkStart = $fetchCursor->copy();
            $chunkEnd = $chunkStart->copy()->addDays(self::FETCH_CHUNK_DAYS - 1);
            if ($chunkEnd->gt($dateTo)) {
                $chunkEnd = $dateTo->copy();
            }

            $chunkRows = $this->vieFundRemoteService->fetchDailyNetTotalsByDateColumn($chunkStart, $chunkEnd, $dateBasis, [
                'status_ids' => $statuses,
                'trust_status_names' => $trustStatuses,
            ]);
            foreach ($chunkRows as $row) {
                $key = Carbon::parse($row->total_date)->toDateString();
                if (!isset($dailyMap[$key])) {
                    $dailyMap[$key] = [
                        'transaction_count' => 0,
                        'net_total' => 0.0,
                    ];
                }
                $dailyMap[$key]['transaction_count'] += (int) $row->transaction_count;
                $dailyMap[$key]['net_total'] += (float) $row->net_total;
            }

            $chunkDays = $chunkStart->diffInDays($chunkEnd) + 1;
            $fetchedDays += $chunkDays;
            $fetchProgress = (int) floor((min($totalDays, $fetchedDays) / max(1, $totalDays)) * (self::FETCH_WEIGHT * 100));
            $processedEquivalent = (int) floor((min($totalDays, $fetchedDays) / max(1, $totalDays)) * (self::FETCH_WEIGHT * $totalDays));

            $this->writeStatus($statusFile, [
                'inProgress' => true,
                'success' => null,
                'message' => sprintf('Fetching source totals (%s to %s)...', $chunkStart->toDateString(), $chunkEnd->toDateString()),
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
                'date_basis' => $dateBasisLabel,
                'output_order' => $outputOrderLabel,
                'status' => $statusLabel,
                'trust_status' => $trustLabel,
                'format' => strtoupper($format),
                'processed_days' => min($totalDays, $processedEquivalent),
                'total_days' => $totalDays,
                'progress_pct' => min(99, $fetchProgress),
                'output_relative_path' => $outputRelativePath,
                'started_at' => $startedAtIso,
                'updated_at' => now()->toIso8601String(),
            ]);

            $fetchCursor = $chunkEnd->copy()->addDay();
        }

        $rows = [];

        $cursor = $dateFrom->copy();
        $processedDays = 0;
        $runningBalance = 0.0;

        while ($cursor->lte($dateTo)) {
            $dateKey = $cursor->toDateString();
            $day = $dailyMap[$dateKey] ?? ['transaction_count' => 0, 'net_total' => 0.0];

            $dailyNet = (float) $day['net_total'];
            $runningBalance += $dailyNet;

            $rows[] = [
                $dateKey,
                number_format((int) $day['transaction_count']),
                $this->formatAccountingCurrency($dailyNet),
                $this->formatAccountingCurrency($runningBalance),
            ];

            $processedDays++;
            $overallFraction = self::FETCH_WEIGHT + ((1 - self::FETCH_WEIGHT) * ($processedDays / max(1, $totalDays)));
            $progressPct = (int) floor($overallFraction * 100);
            $processedEquivalent = (int) floor($overallFraction * $totalDays);

            $this->writeStatus($statusFile, [
                'inProgress' => true,
                'success' => null,
                'message' => sprintf('Writing report rows (%d/%d)...', $processedDays, $totalDays),
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
                'date_basis' => $dateBasisLabel,
                'output_order' => $outputOrderLabel,
                'status' => $statusLabel,
                'trust_status' => $trustLabel,
                'format' => strtoupper($format),
                'processed_days' => min($totalDays, $processedEquivalent),
                'total_days' => $totalDays,
                'progress_pct' => min(99, $progressPct),
                'output_relative_path' => $outputRelativePath,
                'started_at' => $startedAtIso,
                'updated_at' => now()->toIso8601String(),
            ]);

            $cursor->addDay();
        }

        $finalBalance = $runningBalance;
        if ($outputOrder === 'desc') {
            $rows = array_reverse($rows);
        }

        $metadataRows = [
            ['Report', 'VieFund Daily Net + Running Balance'],
            ['Date Basis', $dateBasisLabel],
            ['Date Range', $dateFrom->toDateString() . ' to ' . $dateTo->toDateString()],
            ['Output Order', $outputOrderLabel],
            ['Fund Statuses', $statusLabel],
            ['Trust Statuses', $trustLabel],
            ['Generated At', now()->toDateTimeString()],
            ['Final Balance', $this->formatAccountingCurrency($finalBalance)],
        ];

        $sheetRows = $this->buildSheetRowsWithSideMetadata($rows, $metadataRows);
        $writer = $this->openWriter($outputAbsolutePath, $format);
        foreach ($sheetRows as $sheetRow) {
            $writer($sheetRow);
        }

        $writer(null);

        $duration = round(microtime(true) - $startedAt, 2);

        $this->writeStatus($statusFile, [
            'inProgress' => false,
            'success' => true,
            'message' => sprintf('Report completed in %ss. Final balance: %s', $duration, $this->formatAccountingCurrency($runningBalance)),
            'date_from' => $dateFrom->toDateString(),
            'date_to' => $dateTo->toDateString(),
            'date_basis' => $dateBasisLabel,
            'output_order' => $outputOrderLabel,
            'status' => $statusLabel,
            'trust_status' => $trustLabel,
            'format' => strtoupper($format),
            'processed_days' => $totalDays,
            'total_days' => $totalDays,
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

            if (empty($row)) {
                fwrite($handle, "\r\n");
                return;
            }

            $escaped = array_map(function ($value): string {
                $text = (string) $value;
                return str_replace(["\t", "\r", "\n"], ' ', $text);
            }, $row);

            fwrite($handle, implode("\t", $escaped) . "\r\n");
        };
    }

    private function resolveString(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = collect($value)->first(fn($v) => $v !== null && $v !== '');
        }

        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function resolveStatusGroups(mixed $value): array
    {
        $raw = is_array($value) ? $value : (array) $value;
        $groups = array_values(array_intersect($raw, array_keys(self::STATUS_GROUP_LABELS)));

        if (empty($groups)) {
            return ['completed'];
        }

        return $groups;
    }

    /**
     * Resolve fund status IDs + trust status names. Prefers the explicit
     * --status / --trust-status options; an explicit run (any --status given)
     * treats an empty trust list as "exclude trust". With no --status, falls
     * back to mapping the legacy --status-group / --include-trust options.
     *
     * @return array{0: int[], 1: string[]}
     */
    private function resolveStatusFilters(): array
    {
        $statusOption = array_values(array_filter((array) $this->option('status'), fn($v) => $v !== null && $v !== ''));
        $trustOption = array_values(array_filter((array) $this->option('trust-status'), fn($v) => $v !== null && $v !== ''));
        $statusExplicit = !empty($statusOption);

        if ($statusExplicit) {
            $statuses = array_map('intval', $statusOption);
        } else {
            $statuses = [];
            foreach ($this->resolveStatusGroups($this->option('status-group')) as $group) {
                $statuses = array_merge($statuses, self::STATUS_GROUP_IDS[$group] ?? []);
            }
        }
        $statuses = array_values(array_unique(array_filter(
            $statuses,
            fn($id) => array_key_exists($id, self::FUND_STATUS_LABELS)
        )));
        if (empty($statuses)) {
            $statuses = self::DEFAULT_STATUSES;
        }

        if (!empty($trustOption)) {
            $trustStatuses = array_values(array_intersect(self::TRUST_STATUS_NAMES, $trustOption));
        } elseif ($statusExplicit) {
            $trustStatuses = [];
        } elseif ($this->resolveBoolean($this->option('include-trust'), true)) {
            $trustStatuses = [];
            foreach ($this->resolveStatusGroups($this->option('status-group')) as $group) {
                $trustStatuses = array_merge($trustStatuses, self::STATUS_GROUP_TRUST_NAMES[$group] ?? []);
            }
            $trustStatuses = array_values(array_unique($trustStatuses));
        } else {
            $trustStatuses = [];
        }

        return [$statuses, $trustStatuses];
    }

    private function describeStatuses(array $statuses): string
    {
        $labels = array_map(fn($id) => self::FUND_STATUS_LABELS[$id] ?? $id, $statuses);

        return $labels ? implode(', ', $labels) : 'None';
    }

    private function resolveBoolean(mixed $value, bool $default = false): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_array($value)) {
            $value = collect($value)->first(fn($v) => $v !== null && $v !== '');
            if ($value === null || $value === '') {
                return $default;
            }
        }

        $normalized = strtolower(trim((string) $value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return $default;
    }

    private function buildSheetRowsWithSideMetadata(array $rows, array $metadataRows): array
    {
        $sheetRows = [];

        $dataRows = [];
        $dataRows[] = [
            'Report Date',
            'Transaction Count',
            'Daily Net Transactions',
            'Running Daily Balance',
        ];

        foreach ($rows as $row) {
            $dataRows[] = $row;
        }

        $maxRows = max(count($dataRows), count($metadataRows));
        for ($i = 0; $i < $maxRows; $i++) {
            $dataPart = $dataRows[$i] ?? ['', '', '', ''];
            $metadataPart = $metadataRows[$i] ?? ['', ''];
            $sheetRows[] = [
                $dataPart[0],
                $dataPart[1],
                $dataPart[2],
                $dataPart[3],
                '',
                $metadataPart[0],
                $metadataPart[1],
            ];
        }

        return $sheetRows;
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
