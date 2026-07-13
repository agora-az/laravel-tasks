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
    protected $signature = 'viefund:sync-daily-totals
        {--days=90 : Rolling refresh window when the table already has rows}
        {--from= : Optional explicit start date (YYYY-MM-DD)}
        {--to= : Optional explicit end date (YYYY-MM-DD)}';

    protected $description = 'Sync daily VieFund net transaction totals into a local reporting table';

    public function __construct(
        private readonly VieFundRemoteService $remoteService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $toDate = $this->option('to')
            ? Carbon::parse($this->option('to'))->startOfDay()
            : Carbon::today();

        $fromDate = $this->resolveFromDate($toDate);
        if ($fromDate->greaterThan($toDate)) {
            $this->error('The start date is after the end date.');
            return self::FAILURE;
        }

        $this->info(sprintf(
            'Syncing VieFund daily totals from %s to %s...',
            $fromDate->toDateString(),
            $toDate->toDateString()
        ));

        $remoteRows = $this->remoteService->fetchDailyNetTotals($fromDate, $toDate);
        $remoteMap = $remoteRows->keyBy('total_date');
        $now = now();

        $batch = [];
        $written = 0;
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
            $remoteRows->count()
        ));

        return self::SUCCESS;
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
