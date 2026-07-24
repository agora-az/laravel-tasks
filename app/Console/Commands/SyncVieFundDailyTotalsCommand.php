<?php

namespace App\Console\Commands;

use App\Models\BankStatementEntry;
use App\Models\VieFundDailyTotal;
use App\Services\VieFund\VieFundRemoteService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncVieFundDailyTotalsCommand extends Command
{
    private const FETCH_CHUNK_DAYS = 31;
    private const FETCH_WEIGHT = 0.7;

    protected $signature = 'viefund:sync-daily-totals
        {--days=90 : Rolling refresh window when the table already has rows}
        {--from= : Optional explicit start date (YYYY-MM-DD)}
        {--to= : Optional explicit end date (YYYY-MM-DD)}
        {--sync-mode=incremental : incremental|full marker for status output}
        {--status-file= : Optional path to write sync progress JSON}
        {--lock-file= : Optional lock file path to mark sync in progress}';

    protected $description = 'Sync daily VieFund net transaction totals into a local reporting table';

    public function __construct(
        private readonly VieFundRemoteService $remoteService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $lockFile = $this->option('lock-file') ?: null;
        $statusFile = $this->option('status-file') ?: null;
        $mode = (string) $this->option('sync-mode');

        if ($lockFile) {
            @file_put_contents($lockFile, date('c'));
        }

        try {
            $toDate = $this->option('to')
                ? Carbon::parse($this->option('to'))->startOfDay()
                : Carbon::today();

            $fromDate = $this->resolveFromDate($toDate);
            if ($fromDate->greaterThan($toDate)) {
                $this->error('The start date is after the end date.');
                $this->writeStatus($statusFile, [
                    'inProgress' => false,
                    'success' => false,
                    'mode' => $mode,
                    'message' => 'Start date is after end date.',
                    'updated_at' => now()->toIso8601String(),
                ]);
                return self::FAILURE;
            }

            $this->info(sprintf(
                'Syncing VieFund daily totals from %s to %s...',
                $fromDate->toDateString(),
                $toDate->toDateString()
            ));

            $totalDays = $fromDate->diffInDays($toDate) + 1;
            $startedAt = microtime(true);
            $startedAtIso = now()->toIso8601String();
            $this->writeStatus($statusFile, [
                'inProgress' => true,
                'success' => null,
                'mode' => $mode,
                'from' => $fromDate->toDateString(),
                'to' => $toDate->toDateString(),
                'total_days' => $totalDays,
                'processed_days' => 0,
                'progress_pct' => 0,
                'eta_seconds' => null,
                'estimated_finish_at' => null,
                'started_at' => $startedAtIso,
                'updated_at' => now()->toIso8601String(),
                'message' => 'Preparing sync...',
            ]);

            $remoteMap = collect();
            $fetchedDays = 0;
            $fetchCursor = $fromDate->copy();

            while ($fetchCursor->lte($toDate)) {
                $chunkStart = $fetchCursor->copy();
                $chunkEnd = $chunkStart->copy()->addDays(self::FETCH_CHUNK_DAYS - 1);
                if ($chunkEnd->gt($toDate)) {
                    $chunkEnd = $toDate->copy();
                }

                $chunkRows = $this->remoteService->fetchDailyNetTotals($chunkStart, $chunkEnd);
                foreach ($chunkRows as $chunkRow) {
                    $key = Carbon::parse($chunkRow->total_date)->toDateString();
                    $existing = $remoteMap->get($key);
                    if ($existing) {
                        $existing->transaction_count += (int) $chunkRow->transaction_count;
                        $existing->net_total += (float) $chunkRow->net_total;
                    } else {
                        $remoteMap->put($key, (object) [
                            'total_date' => $key,
                            'transaction_count' => (int) $chunkRow->transaction_count,
                            'net_total' => (float) $chunkRow->net_total,
                        ]);
                    }
                }

                $chunkDays = $chunkStart->diffInDays($chunkEnd) + 1;
                $fetchedDays += $chunkDays;

                $elapsed = max(0.001, microtime(true) - $startedAt);
                $fetchRate = $fetchedDays / $elapsed;
                $remainingDays = max(0, $totalDays - $fetchedDays);
                $etaSeconds = $fetchRate > 0 ? (int) round($remainingDays / $fetchRate) : null;
                $estimatedFinish = $etaSeconds !== null ? now()->addSeconds($etaSeconds)->toIso8601String() : null;

                $fetchProgress = (int) floor((min($totalDays, $fetchedDays) / max(1, $totalDays)) * (self::FETCH_WEIGHT * 100));
                $processedEquivalent = (int) floor((min($totalDays, $fetchedDays) / max(1, $totalDays)) * (self::FETCH_WEIGHT * $totalDays));

                $this->writeStatus($statusFile, [
                    'inProgress' => true,
                    'success' => null,
                    'mode' => $mode,
                    'from' => $fromDate->toDateString(),
                    'to' => $toDate->toDateString(),
                    'total_days' => $totalDays,
                    'processed_days' => min($totalDays, $processedEquivalent),
                    'progress_pct' => min(99, $fetchProgress),
                    'eta_seconds' => $etaSeconds,
                    'estimated_finish_at' => $estimatedFinish,
                    'started_at' => $startedAtIso,
                    'updated_at' => now()->toIso8601String(),
                    'message' => sprintf(
                        'Fetching remote totals (%s to %s) • %d/%d day windows',
                        $chunkStart->toDateString(),
                        $chunkEnd->toDateString(),
                        min($totalDays, $fetchedDays),
                        $totalDays
                    ),
                ]);

                $fetchCursor = $chunkEnd->copy()->addDay();
            }

            $now = now();

            $batch = [];
            $written = 0;
            $processedWriteDays = 0;
            $cursor = $fromDate->copy();

            while ($cursor->lte($toDate)) {
                $dateKey = $cursor->toDateString();
                $remoteRow = $remoteMap->get($dateKey);

                $batch[] = [
                    'total_date' => $dateKey,
                    'net_total' => $remoteRow ? (float) $remoteRow->net_total : 0.0,
                    'transaction_count' => $remoteRow ? (int) $remoteRow->transaction_count : 0,
                    'source_window_start' => $fromDate->toDateString(),
                    'source_window_end' => $toDate->toDateString(),
                    'synced_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (count($batch) >= 500) {
                    VieFundDailyTotal::upsert(
                        $batch,
                        ['total_date'],
                        ['net_total', 'transaction_count', 'source_window_start', 'source_window_end', 'synced_at', 'updated_at']
                    );
                    $written += count($batch);
                    $batch = [];
                }

                $processedWriteDays++;
                $elapsed = max(0.001, microtime(true) - $startedAt);
                $completedFraction = ($processedWriteDays / max(1, $totalDays));
                $overallFraction = self::FETCH_WEIGHT + ((1 - self::FETCH_WEIGHT) * $completedFraction);
                $overallProcessedEquivalent = (int) floor($overallFraction * $totalDays);
                $rate = max(0.0001, $overallProcessedEquivalent / $elapsed);
                $remaining = max(0, $totalDays - $overallProcessedEquivalent);
                $etaSeconds = $rate > 0 ? (int) round($remaining / $rate) : null;
                $estimatedFinish = $etaSeconds !== null ? now()->addSeconds($etaSeconds)->toIso8601String() : null;
                $progressPct = (int) floor($overallFraction * 100);

                $this->writeStatus($statusFile, [
                    'inProgress' => true,
                    'success' => null,
                    'mode' => $mode,
                    'from' => $fromDate->toDateString(),
                    'to' => $toDate->toDateString(),
                    'total_days' => $totalDays,
                    'processed_days' => min($totalDays, $overallProcessedEquivalent),
                    'progress_pct' => min(100, $progressPct),
                    'eta_seconds' => $etaSeconds,
                    'estimated_finish_at' => $estimatedFinish,
                    'started_at' => $startedAtIso,
                    'updated_at' => now()->toIso8601String(),
                    'message' => "Writing local totals for {$dateKey} ({$processedWriteDays}/{$totalDays})",
                ]);

                $cursor->addDay();
            }

            if (!empty($batch)) {
                VieFundDailyTotal::upsert(
                    $batch,
                    ['total_date'],
                    ['net_total', 'transaction_count', 'source_window_start', 'source_window_end', 'synced_at', 'updated_at']
                );
                $written += count($batch);
            }

            $this->info(sprintf(
                'Synced %d daily totals (%d remote days returned).',
                $written,
                $remoteMap->count()
            ));

            $this->writeStatus($statusFile, [
                'inProgress' => false,
                'success' => true,
                'mode' => $mode,
                'from' => $fromDate->toDateString(),
                'to' => $toDate->toDateString(),
                'total_days' => $totalDays,
                'processed_days' => $totalDays,
                'progress_pct' => 100,
                'eta_seconds' => 0,
                'estimated_finish_at' => now()->toIso8601String(),
                'updated_at' => now()->toIso8601String(),
                'completed_at' => now()->toIso8601String(),
                'message' => sprintf('Completed: synced %d days (%d remote days returned).', $written, $remoteMap->count()),
            ]);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->writeStatus($statusFile, [
                'inProgress' => false,
                'success' => false,
                'mode' => (string) $this->option('sync-mode'),
                'updated_at' => now()->toIso8601String(),
                'message' => 'Sync failed: ' . $e->getMessage(),
            ]);
            throw $e;
        } finally {
            if ($lockFile && file_exists($lockFile)) {
                @unlink($lockFile);
            }
        }
    }

    private function writeStatus(?string $statusFile, array $payload): void
    {
        if (!$statusFile) {
            return;
        }

        @file_put_contents($statusFile, json_encode($payload, JSON_PRETTY_PRINT));
    }

    private function resolveFromDate(Carbon $toDate): Carbon
    {
        if ($this->option('from')) {
            return Carbon::parse($this->option('from'))->startOfDay();
        }

        $earliestBankDate = BankStatementEntry::min('value_date');
        $earliestBank = $earliestBankDate ? Carbon::parse($earliestBankDate)->startOfDay() : $toDate->copy();

        if (!VieFundDailyTotal::exists()) {
            return $earliestBank;
        }

        $days = max(1, (int) $this->option('days'));
        $rollingStart = $toDate->copy()->subDays($days - 1)->startOfDay();

        return $rollingStart->lt($earliestBank) ? $earliestBank : $rollingStart;
    }
}
