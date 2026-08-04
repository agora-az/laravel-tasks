<?php

namespace App\Console\Commands;

use App\Exports\VieFundDailyBalanceWorkbookExport;
use App\Services\VieFund\VieFundRemoteService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;

class GenerateLegacyVieFundDailyBalanceReportCommand extends Command
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

    protected $signature = 'report:viefund-daily-balance-legacy
        {--date-from= : Report start date (YYYY-MM-DD)}
        {--date-to= : Report end date (YYYY-MM-DD)}
        {--date-basis=settlement_date : create_date|trade_date|processing_date|settlement_date}
        {--output-order=asc : asc|desc}
        {--status=* : Fund status IDs 0-6}
        {--trust-status=* : Trust status names; empty excludes trust}
        {--format=csv : csv|excel}
        {--output-file= : Relative output path under storage/app}
        {--status-file= : Optional status file path}
        {--lock-file= : Optional lock file path}';

    protected $description = 'Generate the legacy VieFund fund + trust daily balance comparison report';

    public function __construct(private readonly VieFundRemoteService $remoteService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $lockFile = $this->stringOption('lock-file');
        $statusFile = $this->stringOption('status-file');

        if ($lockFile) {
            @file_put_contents($lockFile, date('c'));
        }

        try {
            return $this->generate($statusFile);
        } catch (\Throwable $exception) {
            $this->writeStatus($statusFile, [
                'inProgress' => false,
                'success' => false,
                'message' => 'Legacy report failed: ' . $exception->getMessage(),
                'updated_at' => now()->toIso8601String(),
                'completed_at' => now()->toIso8601String(),
            ]);
            throw $exception;
        } finally {
            if ($lockFile && file_exists($lockFile)) {
                @unlink($lockFile);
            }
        }
    }

    private function generate(?string $statusFile): int
    {
        $dateFromRaw = $this->stringOption('date-from');
        $dateToRaw = $this->stringOption('date-to');
        $dateBasis = $this->stringOption('date-basis') ?? 'settlement_date';
        $outputOrder = $this->stringOption('output-order') ?? 'asc';
        $format = $this->stringOption('format') ?? 'csv';
        $outputRelativePath = $this->stringOption('output-file');

        if (!$dateFromRaw || !$dateToRaw || !$outputRelativePath) {
            $this->error('Missing required options: --date-from, --date-to, --output-file.');
            return self::FAILURE;
        }
        if (!isset(self::DATE_BASIS_LABELS[$dateBasis])) {
            $this->error('Invalid --date-basis value.');
            return self::FAILURE;
        }
        if (!isset(self::OUTPUT_ORDER_LABELS[$outputOrder]) || !in_array($format, ['csv', 'excel'], true)) {
            $this->error('Invalid output order or format.');
            return self::FAILURE;
        }

        $dateFrom = Carbon::parse($dateFromRaw)->startOfDay();
        $dateTo = Carbon::parse($dateToRaw)->startOfDay();
        if ($dateFrom->gt($dateTo)) {
            $this->error('date-from must be before or equal to date-to.');
            return self::FAILURE;
        }

        $statuses = $this->resolveStatuses();
        $trustStatuses = $this->resolveTrustStatuses();
        $statusLabel = implode(', ', array_map(fn(int $id) => self::FUND_STATUS_LABELS[$id], $statuses));
        $trustLabel = $trustStatuses ? implode(', ', $trustStatuses) : 'Excluded';
        $outputRelativePath = ltrim($outputRelativePath, '/');
        if (!str_starts_with($outputRelativePath, 'reports/')) {
            $outputRelativePath = 'reports/' . basename($outputRelativePath);
        }
        $outputAbsolutePath = storage_path('app/' . $outputRelativePath);
        if (!is_dir(dirname($outputAbsolutePath))) {
            @mkdir(dirname($outputAbsolutePath), 0775, true);
        }

        $totalDays = $dateFrom->diffInDays($dateTo) + 1;
        $startedAt = microtime(true);
        $startedAtIso = now()->toIso8601String();
        $this->writeStatus($statusFile, [
            'inProgress' => true,
            'success' => null,
            'message' => 'Preparing legacy report...',
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
            $chunkEnd = $chunkStart->copy()->addDays(self::FETCH_CHUNK_DAYS - 1)->min($dateTo);
            $chunkRows = $this->remoteService->fetchDailyNetTotalsByDateColumn($chunkStart, $chunkEnd, $dateBasis, [
                'status_ids' => $statuses,
                'trust_status_names' => $trustStatuses,
            ]);
            foreach ($chunkRows as $row) {
                $dateKey = Carbon::parse($row->total_date)->toDateString();
                $dailyMap[$dateKey] ??= ['transaction_count' => 0, 'net_total' => 0.0];
                $dailyMap[$dateKey]['transaction_count'] += (int) $row->transaction_count;
                $dailyMap[$dateKey]['net_total'] += (float) $row->net_total;
            }

            $fetchedDays += $chunkStart->diffInDays($chunkEnd) + 1;
            $progress = (int) floor(($fetchedDays / max(1, $totalDays)) * self::FETCH_WEIGHT * 100);
            $this->writeStatus($statusFile, [
                'inProgress' => true,
                'success' => null,
                'message' => "Fetching legacy totals ({$chunkStart->toDateString()} to {$chunkEnd->toDateString()})...",
                'processed_days' => min($totalDays, $fetchedDays),
                'total_days' => $totalDays,
                'progress_pct' => min(70, $progress),
                'output_relative_path' => $outputRelativePath,
                'started_at' => $startedAtIso,
                'updated_at' => now()->toIso8601String(),
            ]);
            $fetchCursor = $chunkEnd->copy()->addDay();
        }

        $rows = [];
        $runningBalance = 0.0;
        $cursor = $dateFrom->copy();
        $processedDays = 0;
        while ($cursor->lte($dateTo)) {
            $dateKey = $cursor->toDateString();
            $day = $dailyMap[$dateKey] ?? ['transaction_count' => 0, 'net_total' => 0.0];
            $dailyNet = (float) $day['net_total'];
            $runningBalance += $dailyNet;
            $rows[] = [
                $dateKey,
                $format === 'excel' ? (int) $day['transaction_count'] : number_format((int) $day['transaction_count']),
                $format === 'excel' ? $dailyNet : $this->formatCurrency($dailyNet),
                $format === 'excel' ? $runningBalance : $this->formatCurrency($runningBalance),
            ];
            $processedDays++;
            $progress = 70 + (int) floor(($processedDays / max(1, $totalDays)) * 30);
            $this->writeStatus($statusFile, [
                'inProgress' => true,
                'success' => null,
                'message' => "Writing legacy report rows ({$processedDays}/{$totalDays})...",
                'processed_days' => $processedDays,
                'total_days' => $totalDays,
                'progress_pct' => min(99, $progress),
                'output_relative_path' => $outputRelativePath,
                'started_at' => $startedAtIso,
                'updated_at' => now()->toIso8601String(),
            ]);
            $cursor->addDay();
        }

        if ($outputOrder === 'desc') {
            $rows = array_reverse($rows);
        }
        $metadataRows = [
            ['Report', 'Legacy VieFund Daily Net + Running Balance'],
            ['Date Basis', self::DATE_BASIS_LABELS[$dateBasis]],
            ['Date Range', $dateFrom->toDateString() . ' to ' . $dateTo->toDateString()],
            ['Output Order', self::OUTPUT_ORDER_LABELS[$outputOrder]],
            ['Balance Source', 'Legacy Fund + Trust Reconstruction (Live)'],
            ['Fund Statuses', $statusLabel],
            ['Trust Statuses', $trustLabel],
            ['Opening Balance Method', 'Zero at selected start date'],
            ['Snapshot Cache', 'Not used'],
            ['Generated At', now()->toDateTimeString()],
            ['Opening Balance', $format === 'excel' ? 0.0 : $this->formatCurrency(0.0)],
            ['Final Balance', $format === 'excel' ? $runningBalance : $this->formatCurrency($runningBalance)],
        ];

        if ($format === 'excel') {
            Excel::store(new VieFundDailyBalanceWorkbookExport(
                [['Report Date', 'Legacy Transactions', 'Daily Net Transactions', 'Running Daily Balance'], ...$rows],
                [['Summary Item', 'Value'], ...$metadataRows]
            ), $outputRelativePath, null, ExcelWriter::XLSX);
        } else {
            $this->writeCsv($outputAbsolutePath, $rows, $metadataRows);
        }

        $duration = round(microtime(true) - $startedAt, 2);
        $this->writeStatus($statusFile, [
            'inProgress' => false,
            'success' => true,
            'message' => "Legacy report completed in {$duration}s. Final balance: {$this->formatCurrency($runningBalance)}",
            'processed_days' => $totalDays,
            'total_days' => $totalDays,
            'progress_pct' => 100,
            'output_relative_path' => $outputRelativePath,
            'started_at' => $startedAtIso,
            'updated_at' => now()->toIso8601String(),
            'completed_at' => now()->toIso8601String(),
        ]);
        $this->info('Legacy report generated: ' . $outputRelativePath);

        return self::SUCCESS;
    }

    private function resolveStatuses(): array
    {
        $statuses = array_values(array_unique(array_map('intval', (array) $this->option('status'))));
        $statuses = array_values(array_filter($statuses, fn(int $id) => isset(self::FUND_STATUS_LABELS[$id])));
        return $statuses ?: [6];
    }

    private function resolveTrustStatuses(): array
    {
        return array_values(array_intersect(self::TRUST_STATUS_NAMES, (array) $this->option('trust-status')));
    }

    private function writeCsv(string $path, array $rows, array $metadataRows): void
    {
        $handle = fopen($path, 'w');
        if (!$handle) {
            throw new \RuntimeException('Unable to open legacy report output file.');
        }
        fwrite($handle, "\xEF\xBB\xBF");
        $dataRows = [['Report Date', 'Transaction Count', 'Daily Net Transactions', 'Running Daily Balance'], ...$rows];
        $maxRows = max(count($dataRows), count($metadataRows));
        for ($index = 0; $index < $maxRows; $index++) {
            $data = $dataRows[$index] ?? ['', '', '', ''];
            $metadata = $metadataRows[$index] ?? ['', ''];
            fputcsv($handle, [...$data, '', ...$metadata]);
        }
        fclose($handle);
    }

    private function writeStatus(?string $path, array $payload): void
    {
        if (!$path) {
            return;
        }
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0775, true);
        }
        @file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT));
    }

    private function stringOption(string $name): ?string
    {
        $value = trim((string) $this->option($name));
        return $value !== '' ? $value : null;
    }

    private function formatCurrency(float $amount): string
    {
        $formatted = '$' . number_format(abs($amount), 2, '.', ',');
        return $amount < 0 ? "({$formatted})" : $formatted;
    }
}