<?php

namespace App\Console\Commands;

use App\Models\VieFundCashDailySnapshot;
use App\Models\VieFundCashDailySnapshotChange;
use App\Models\VieFundCashSnapshotRun;
use App\Services\VieFund\VieFundRemoteService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncVieFundCashDailySnapshotsCommand extends Command
{
    private const FETCH_CHUNK_DAYS = 31;
    private const ALLOWED_BASES = ['create_date', 'trade_date', 'processing_date', 'settlement_date'];

    protected $signature = 'viefund:sync-cash-daily-snapshots
        {--days=90 : Rolling refresh window for an existing series}
        {--from= : Optional explicit start date (YYYY-MM-DD)}
        {--to= : Optional end date (YYYY-MM-DD), defaults to today}
        {--full : Recheck the full series from cash-ledger inception}
        {--date-basis=settlement_date : create_date|trade_date|processing_date|settlement_date}
        {--currency=00 : VieFund cash account currency code}
        {--statuses=* : Cash transaction status IDs 0-6, defaults to Confirmed (6)}
        {--status-file= : Optional progress JSON path}
        {--lock-file= : Optional lock file path}';

    protected $description = 'Build and verify audited direct-cash daily snapshots';

    public function __construct(
        private readonly VieFundRemoteService $remoteService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $lockFile = $this->stringOption('lock-file');
        $statusFile = $this->stringOption('status-file');

        if ($lockFile) {
            @file_put_contents($lockFile, date('c'));
        }

        $run = null;

        try {
            $basis = $this->resolveBasis();
            $currencyCode = strtoupper($this->stringOption('currency') ?? '00');
            $statusIds = $this->resolveStatuses();
            $criteriaKey = VieFundCashDailySnapshot::criteriaKey($basis, $currencyCode, $statusIds);

            config([
                'viefund.balance_report_cash_account_scope.currency_code' => $currencyCode,
                'viefund.balance_report_cash_account_scope.opened_before' => null,
            ]);

            $toDate = Carbon::parse($this->stringOption('to') ?? Carbon::today()->toDateString())->startOfDay();
            [$fromDate, $runType] = $this->resolveFromDate($toDate, $criteriaKey, $basis);

            if ($fromDate->gt($toDate)) {
                $this->error('The start date is after the end date.');
                return self::FAILURE;
            }

            $observedAt = now();
            $run = VieFundCashSnapshotRun::create([
                'run_type' => $runType,
                'status' => 'running',
                'criteria_key' => $criteriaKey,
                'algorithm_version' => VieFundCashDailySnapshot::ALGORITHM_VERSION,
                'date_basis' => $basis,
                'currency_code' => $currencyCode,
                'status_ids' => $statusIds,
                'requested_from' => $fromDate,
                'requested_to' => $toDate,
                'source_observed_at' => $observedAt,
                'started_at' => $observedAt,
            ]);

            $this->writeStatus($statusFile, [
                'inProgress' => true,
                'success' => null,
                'run_id' => $run->id,
                'run_type' => $runType,
                'from' => $fromDate->toDateString(),
                'to' => $toDate->toDateString(),
                'message' => 'Fetching direct cash-ledger daily totals...',
                'started_at' => $observedAt->toIso8601String(),
                'updated_at' => now()->toIso8601String(),
            ]);

            $remoteMap = $this->fetchDailyTotals($fromDate, $toDate, $basis, $statusIds, $statusFile, $run);
            $counts = $this->persistSnapshots(
                $run,
                $criteriaKey,
                $basis,
                $currencyCode,
                $statusIds,
                $fromDate,
                $toDate,
                $observedAt,
                $remoteMap
            );

            $run->update([
                'status' => 'completed',
                ...$counts,
                'completed_at' => now(),
            ]);

            $message = sprintf(
                'Verified %d days: %d inserted, %d changed, %d unchanged.',
                $counts['days_checked'],
                $counts['days_inserted'],
                $counts['days_changed'],
                $counts['days_unchanged']
            );
            $this->info($message);
            $this->writeStatus($statusFile, [
                'inProgress' => false,
                'success' => true,
                'run_id' => $run->id,
                'run_type' => $runType,
                ...$counts,
                'message' => $message,
                'updated_at' => now()->toIso8601String(),
                'completed_at' => now()->toIso8601String(),
            ]);

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            if ($run) {
                $run->update([
                    'status' => 'failed',
                    'error_message' => $exception->getMessage(),
                    'completed_at' => now(),
                ]);
            }

            $this->writeStatus($statusFile, [
                'inProgress' => false,
                'success' => false,
                'run_id' => $run?->id,
                'message' => 'Snapshot sync failed: ' . $exception->getMessage(),
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

    private function fetchDailyTotals(
        Carbon $fromDate,
        Carbon $toDate,
        string $basis,
        array $statusIds,
        ?string $statusFile,
        VieFundCashSnapshotRun $run
    ): array {
        $remoteMap = [];
        $cursor = $fromDate->copy();
        $totalDays = $fromDate->diffInDays($toDate) + 1;
        $fetchedDays = 0;

        while ($cursor->lte($toDate)) {
            $chunkStart = $cursor->copy();
            $chunkEnd = $chunkStart->copy()->addDays(self::FETCH_CHUNK_DAYS - 1)->min($toDate);
            $rows = $this->remoteService->fetchCustomerCashDailyNetTotalsByDateColumn(
                $chunkStart,
                $chunkEnd,
                $basis,
                [
                    'status_ids' => $statusIds,
                    'availability_as_of' => $toDate->toDateString(),
                ]
            );

            foreach ($rows as $row) {
                $dateKey = Carbon::parse($row->total_date)->toDateString();
                $remoteMap[$dateKey] = [
                    'transaction_count' => (int) $row->transaction_count,
                    'net_total' => round((float) $row->net_total, 4),
                ];
            }

            $fetchedDays += $chunkStart->diffInDays($chunkEnd) + 1;
            $this->writeStatus($statusFile, [
                'inProgress' => true,
                'success' => null,
                'run_id' => $run->id,
                'run_type' => $run->run_type,
                'from' => $fromDate->toDateString(),
                'to' => $toDate->toDateString(),
                'processed_days' => min($fetchedDays, $totalDays),
                'total_days' => $totalDays,
                'progress_pct' => (int) floor((min($fetchedDays, $totalDays) / max(1, $totalDays)) * 70),
                'message' => sprintf('Fetched %s through %s.', $chunkStart->toDateString(), $chunkEnd->toDateString()),
                'updated_at' => now()->toIso8601String(),
            ]);

            $cursor = $chunkEnd->copy()->addDay();
        }

        return $remoteMap;
    }

    private function persistSnapshots(
        VieFundCashSnapshotRun $run,
        string $criteriaKey,
        string $basis,
        string $currencyCode,
        array $statusIds,
        Carbon $fromDate,
        Carbon $toDate,
        $observedAt,
        array $remoteMap
    ): array {
        return DB::transaction(function () use ($run, $criteriaKey, $basis, $currencyCode, $statusIds, $fromDate, $toDate, $observedAt, $remoteMap) {
            $existingByDate = VieFundCashDailySnapshot::query()
                ->where('criteria_key', $criteriaKey)
                ->whereBetween('total_date', [$fromDate->toDateString(), $toDate->toDateString()])
                ->lockForUpdate()
                ->get()
                ->keyBy(fn(VieFundCashDailySnapshot $snapshot) => $snapshot->total_date->toDateString());

            $counts = [
                'days_checked' => 0,
                'days_inserted' => 0,
                'days_changed' => 0,
                'days_unchanged' => 0,
            ];
            $requiresBalanceRebuild = false;
            $cursor = $fromDate->copy();

            while ($cursor->lte($toDate)) {
                $dateKey = $cursor->toDateString();
                $remote = $remoteMap[$dateKey] ?? ['transaction_count' => 0, 'net_total' => 0.0];
                $snapshot = $existingByDate->get($dateKey);
                $counts['days_checked']++;

                if (!$snapshot) {
                    VieFundCashDailySnapshot::create([
                        'total_date' => $dateKey,
                        'criteria_key' => $criteriaKey,
                        'algorithm_version' => VieFundCashDailySnapshot::ALGORITHM_VERSION,
                        'date_basis' => $basis,
                        'currency_code' => $currencyCode,
                        'status_ids' => $statusIds,
                        'transaction_count' => $remote['transaction_count'],
                        'net_total' => $remote['net_total'],
                        'closing_balance' => 0,
                        'first_observed_at' => $observedAt,
                        'last_verified_at' => $observedAt,
                    ]);
                    $counts['days_inserted']++;
                    $requiresBalanceRebuild = true;
                    $cursor->addDay();
                    continue;
                }

                $countChanged = $snapshot->transaction_count !== $remote['transaction_count'];
                $amountChanged = abs((float) $snapshot->net_total - $remote['net_total']) >= 0.00005;

                if ($countChanged || $amountChanged) {
                    VieFundCashDailySnapshotChange::create([
                        'snapshot_id' => $snapshot->id,
                        'run_id' => $run->id,
                        'previous_transaction_count' => $snapshot->transaction_count,
                        'new_transaction_count' => $remote['transaction_count'],
                        'transaction_count_delta' => $remote['transaction_count'] - $snapshot->transaction_count,
                        'previous_net_total' => $snapshot->net_total,
                        'new_net_total' => $remote['net_total'],
                        'net_total_delta' => round($remote['net_total'] - (float) $snapshot->net_total, 4),
                        'algorithm_version' => VieFundCashDailySnapshot::ALGORITHM_VERSION,
                        'detected_at' => $observedAt,
                    ]);

                    $snapshot->fill([
                        'transaction_count' => $remote['transaction_count'],
                        'net_total' => $remote['net_total'],
                        'last_verified_at' => $observedAt,
                        'latest_changed_at' => $observedAt,
                        'change_count' => $snapshot->change_count + 1,
                        'has_unreviewed_change' => true,
                        'reviewed_at' => null,
                        'reviewed_by' => null,
                    ])->save();
                    $counts['days_changed']++;
                    $requiresBalanceRebuild = true;
                } else {
                    $snapshot->update(['last_verified_at' => $observedAt]);
                    $counts['days_unchanged']++;
                }

                $cursor->addDay();
            }

            if ($requiresBalanceRebuild) {
                $this->rebuildClosingBalances($criteriaKey);
            }

            return $counts;
        }, 3);
    }

    private function rebuildClosingBalances(string $criteriaKey): void
    {
        $runningBalance = 0.0;

        VieFundCashDailySnapshot::query()
            ->where('criteria_key', $criteriaKey)
            ->orderBy('total_date')
            ->orderBy('id')
            ->chunk(500, function ($snapshots) use (&$runningBalance) {
                foreach ($snapshots as $snapshot) {
                    $runningBalance = round($runningBalance + (float) $snapshot->net_total, 4);
                    DB::table('viefund_cash_daily_snapshots')
                        ->where('id', $snapshot->id)
                        ->update([
                            'closing_balance' => $runningBalance,
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    private function resolveFromDate(Carbon $toDate, string $criteriaKey, string $basis): array
    {
        if ($this->stringOption('from')) {
            return [Carbon::parse($this->stringOption('from'))->startOfDay(), 'manual'];
        }

        $seriesExists = VieFundCashDailySnapshot::where('criteria_key', $criteriaKey)->exists();
        if (!$seriesExists || $this->option('full')) {
            $inception = $this->remoteService->fetchInceptionDateByDateColumn($basis);
            return [Carbon::parse($inception ?? $toDate->toDateString())->startOfDay(), $seriesExists ? 'full_verification' : 'baseline'];
        }

        $days = max(1, (int) $this->option('days'));
        return [$toDate->copy()->subDays($days - 1)->startOfDay(), 'incremental'];
    }

    private function resolveBasis(): string
    {
        $basis = $this->stringOption('date-basis') ?? 'settlement_date';
        if (!in_array($basis, self::ALLOWED_BASES, true)) {
            throw new \InvalidArgumentException('Invalid --date-basis value.');
        }

        return $basis;
    }

    private function resolveStatuses(): array
    {
        $statuses = array_values(array_unique(array_filter(
            array_map('intval', (array) $this->option('statuses')),
            fn(int $status) => $status >= 0 && $status <= 6
        )));
        sort($statuses, SORT_NUMERIC);

        return $statuses ?: [6];
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);
        if ($value === null || $value === '') {
            return null;
        }

        return trim((string) $value);
    }

    private function writeStatus(?string $statusFile, array $payload): void
    {
        if (!$statusFile) {
            return;
        }

        $directory = dirname($statusFile);
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        @file_put_contents($statusFile, json_encode($payload, JSON_PRETTY_PRINT));
    }
}
