<?php

namespace App\Console\Commands;

use App\Models\RemoteVieFundCustomer;
use App\Models\RemoteVieFundCustomerPlan;
use App\Models\RemoteVieFundCustomerTransaction;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SyncVieFundCustomersCommand extends Command
{
    protected $signature = 'viefund:sync-customers
                            {--customers-only : Only sync the customers and plans tables, skip transactions}
                            {--force : Reset all transaction sync progress and re-sync everything from scratch}
                            {--chunk=50 : Number of customers to process per SQL Server query (keeps connections short-lived)}';

    protected $description = 'Sync customers, plans, and transactions from the remote VieFund SQL Server into local lookup tables';

    private const CONNECTION = 'viefund_sqlsrv';

    public function handle(): int
    {
        $schema = env('VIEFUND_DB_SCHEMA', 'dbo');
        $chunk  = (int) $this->option('chunk');
        $now    = Carbon::now();

        $this->info('Starting remote VieFund sync…');

        // ── 1. Customers ──────────────────────────────────────────────────────
        $this->info('Syncing customers…');
        $syncedCustomers = 0;

        DB::connection(self::CONNECTION)
            ->table("{$schema}.UB_Customer")
            ->select('ID', 'FirstName', 'LastName')
            ->orderBy('ID')
            ->chunk($chunk, function ($rows) use ($now, &$syncedCustomers) {
                $upsert = $rows->map(fn($r) => [
                    'viefund_customer_id' => $r->ID,
                    'first_name'          => $r->FirstName,
                    'last_name'           => $r->LastName,
                    'full_name'           => trim(($r->FirstName ?? '') . ' ' . ($r->LastName ?? '')),
                    'synced_at'           => $now,
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ])->toArray();

                RemoteVieFundCustomer::upsert(
                    $upsert,
                    ['viefund_customer_id'],
                    ['first_name', 'last_name', 'full_name', 'synced_at', 'updated_at']
                );

                $syncedCustomers += count($upsert);
            });

        $this->line("  → {$syncedCustomers} customers synced.");

        // ── 2. Plans ──────────────────────────────────────────────────────────
        $this->info('Syncing plans…');
        $syncedPlans = 0;

        DB::connection(self::CONNECTION)
            ->table("{$schema}.UB_Plan")
            ->select('ID', 'iClientID', 'DealerAccountID')
            ->orderBy('ID')
            ->chunk($chunk, function ($rows) use ($now, &$syncedPlans) {
                $upsert = $rows->map(fn($r) => [
                    'viefund_plan_id'        => $r->ID,
                    'viefund_customer_id'    => $r->iClientID,
                    'plan_dealer_account_id' => $r->DealerAccountID,
                    'synced_at'              => $now,
                    'created_at'             => $now,
                    'updated_at'             => $now,
                ])->toArray();

                RemoteVieFundCustomerPlan::upsert(
                    $upsert,
                    ['viefund_plan_id'],
                    ['viefund_customer_id', 'plan_dealer_account_id', 'synced_at', 'updated_at']
                );

                $syncedPlans += count($upsert);
            });

        $this->line("  → {$syncedPlans} plans synced.");

        if ($this->option('customers-only')) {
            $this->info('Done (customers-only mode).');
            return self::SUCCESS;
        }

        // ── 3. Transactions ───────────────────────────────────────────────────
        // Process transactions in small per-customer batches so each SQL Server
        // connection is short-lived (avoids TCP timeout on long OFFSET streams).
        $this->info('Syncing transaction balances (this may take a while)…');

        // ── 3a. Handle --force: reset all completion flags and wipe transactions ──
        if ($this->option('force')) {
            $this->warn('  --force: truncating transaction table and resetting completion flags…');
            DB::table('remote_viefund_customer_transactions')->truncate();
            RemoteVieFundCustomer::query()->update(['transactions_completed' => false]);
        }

        // ── 3b. Detect already-completed customers that have new remote transactions ──
        // Uses the global max local cash_trx_id as a watermark: any customer with a
        // remote transaction beyond that ID needs to be re-synced.
        if (!$this->option('force')) {
            $localMax = DB::table('remote_viefund_customer_transactions')->max('cash_trx_id') ?? 0;

            if ($localMax > 0) {
                $customersWithNew = DB::connection(self::CONNECTION)
                    ->table("{$schema}.UB_FundTrxLookup as l")
                    ->join("{$schema}.UB_Plan as p",             'p.ID',      '=', 'l.iPlanID')
                    ->join("{$schema}.UB_Customer as c",         'c.ID',      '=', 'p.iClientID')
                    ->leftJoin("{$schema}.UB_FundTrxCash as fc", 'fc.iTrxID', '=', 'l.iTrxID')
                    ->leftJoin("{$schema}.UB_CashTrx as ct",     'ct.ID',     '=', 'fc.iCashTrxID')
                    ->whereNotNull('ct.ID')
                    ->where('ct.ID', '>', $localMax)
                    ->distinct()
                    ->pluck('c.ID')
                    ->toArray();

                if (!empty($customersWithNew)) {
                    // Only reset customers we've already completed (new customers are handled normally)
                    $completedSet = RemoteVieFundCustomer::where('transactions_completed', true)
                        ->whereIn('viefund_customer_id', $customersWithNew)
                        ->pluck('viefund_customer_id')
                        ->toArray();

                    if (!empty($completedSet)) {
                        RemoteVieFundCustomer::whereIn('viefund_customer_id', $completedSet)
                            ->update(['transactions_completed' => false]);
                        $this->line('  → ' . count($completedSet) . ' customer(s) have new transactions — will re-sync.');
                    }
                }
            }
        }

        // ── 3c. Clean up any partial rows from a previously interrupted run or re-sync reset ──
        $partialCustomerIds = DB::table('remote_viefund_customer_transactions')
            ->join('remote_viefund_customers as c', 'c.viefund_customer_id', '=', 'remote_viefund_customer_transactions.viefund_customer_id')
            ->where('c.transactions_completed', false)
            ->distinct()
            ->pluck('remote_viefund_customer_transactions.viefund_customer_id');

        if ($partialCustomerIds->isNotEmpty()) {
            $deleted = DB::table('remote_viefund_customer_transactions')
                ->whereIn('viefund_customer_id', $partialCustomerIds)
                ->delete();
            $this->warn("  → Cleaned up {$deleted} incomplete rows from {$partialCustomerIds->count()} partially-synced customer(s).");
        }

        // ── 3c. Get all incomplete customer IDs from local MySQL ──
        $incompleteIds = RemoteVieFundCustomer::where('transactions_completed', false)
            ->orderBy('viefund_customer_id')
            ->pluck('viefund_customer_id')
            ->toArray();

        $totalCustomers  = RemoteVieFundCustomer::count();
        $alreadyDone     = $totalCustomers - count($incompleteIds);
        $syncedTrx       = 0;

        if ($alreadyDone > 0) {
            $this->line("  → Resuming — {$alreadyDone}/{$totalCustomers} customers already completed.");
        }

        // ── 3d. Process in customer batches — one short SQL Server query per batch ──
        $customerBatches = array_chunk($incompleteIds, $chunk);
        $totalBatches    = count($customerBatches);

        foreach ($customerBatches as $batchIndex => $customerIds) {
            $batchNum = $batchIndex + 1;

            $rows = DB::connection(self::CONNECTION)
                ->table("{$schema}.UB_FundTrxLookup as l")
                ->join("{$schema}.UB_FundTrx as t",         't.ID',       '=', 'l.iTrxID')
                ->join("{$schema}.UB_Plan as p",             'p.ID',       '=', 'l.iPlanID')
                ->join("{$schema}.UB_Customer as c",         'c.ID',       '=', 'p.iClientID')
                ->leftJoin("{$schema}.UB_FundTrxCash as fc", 'fc.iTrxID',  '=', 'l.iTrxID')
                ->leftJoin("{$schema}.UB_CashTrx as ct",     'ct.ID',      '=', 'fc.iCashTrxID')
                ->whereNotNull('ct.ID')
                ->whereIn('c.ID', $customerIds)
                ->select(
                    DB::raw('c.ID       AS viefund_customer_id'),
                    DB::raw('l.iTrxID   AS trx_id'),
                    DB::raw('ct.ID      AS cash_trx_id'),
                    DB::raw('ct.mAmount AS amount'),
                )
                ->orderBy('c.ID')
                ->orderBy('l.iTrxID')
                ->orderBy('ct.ID')
                ->get();

            // Compute per-customer running balances and collect insert rows
            $currentCustomerId = null;
            $runningCents      = 0;
            $insertBatch       = [];

            foreach ($rows as $row) {
                if ($row->viefund_customer_id !== $currentCustomerId) {
                    if (!empty($insertBatch)) {
                        RemoteVieFundCustomerTransaction::insertOrIgnore($insertBatch);
                        $syncedTrx += count($insertBatch);
                        $insertBatch = [];
                    }
                    $currentCustomerId = $row->viefund_customer_id;
                    $runningCents      = 0;
                }

                // Remote amounts are sign-inverted: a remote negative = a positive balance
                $runningCents -= (int) round($row->amount * 10000);
                $insertBatch[] = [
                    'cash_trx_id'         => $row->cash_trx_id,
                    'viefund_customer_id' => $row->viefund_customer_id,
                    'amount'              => -$row->amount,
                    'running_balance'     => $runningCents / 10000,
                ];
            }

            if (!empty($insertBatch)) {
                RemoteVieFundCustomerTransaction::insertOrIgnore($insertBatch);
                $syncedTrx += count($insertBatch);
            }

            // Mark ALL customers in this batch as completed (including those with no transactions)
            RemoteVieFundCustomer::whereIn('viefund_customer_id', $customerIds)
                ->update(['transactions_completed' => true]);

            $doneCount = $alreadyDone + ($batchIndex + 1) * $chunk;
            $doneCount = min($doneCount, $totalCustomers);
            $this->line("  → Batch {$batchNum}/{$totalBatches} done — {$doneCount}/{$totalCustomers} customers, {$syncedTrx} transactions.");
        }

        $this->line("  → {$syncedTrx} transactions synced.");
        $this->info('Sync complete.');

        // Remove the lock file so the UI knows the sync has finished
        $lockFile = storage_path('app/viefund-sync.lock');
        if (file_exists($lockFile)) {
            @unlink($lockFile);
        }

        // Bust the dashboard stats cache so the next load reflects fresh data
        \Illuminate\Support\Facades\Cache::forget('viefund_dashboard_stats');
        // Also bust the sync watermark cache
        \Illuminate\Support\Facades\Cache::forget('viefund_remote_max_id');
        // Bust all cached calculated balances (keys prefixed viefund_calc_balances_)
        foreach (\Illuminate\Support\Facades\Cache::get('viefund_calc_balance_keys', []) as $key) {
            \Illuminate\Support\Facades\Cache::forget($key);
        }
        \Illuminate\Support\Facades\Cache::forget('viefund_calc_balance_keys');

        return self::SUCCESS;
    }
}
