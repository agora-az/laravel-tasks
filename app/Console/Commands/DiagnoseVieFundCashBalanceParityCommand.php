<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DiagnoseVieFundCashBalanceParityCommand extends Command
{
    protected $signature = 'diagnostics:viefund-cash-balance-parity
        {--client-file= : Path to client cash balances CSV}
        {--report-date= : Report date (YYYY-MM-DD)}
        {--date-basis=settlement_date : create_date|trade_date|processing_date|settlement_date}
        {--cutoff= : Cash account opened-before cutoff (YYYY-MM-DD HH:MM:SS)}
        {--data-cutoff= : Latest transaction creation timestamp available to the historical report; defaults to --cutoff}
        {--currency=00 : Cash account currency code (00=CAD)}
        {--exclude-plan-accounts=39697 : Comma-separated DealerAccountID values to exclude}
        {--account-id=* : Optional normalized account IDs to focus diagnostics on}
        {--account-file= : Optional newline-delimited file of normalized account IDs to focus diagnostics on}
        {--output-file= : Optional CSV output path under storage/app for per-account diagnostics}
        {--direct-only : Run only the direct settled CashTrx rollup}
        {--status=6 : Comma-separated cash status IDs for as-of methods (e.g. 6 or 5,6)}
        {--exclude-standalone-trust-types= : Comma-separated standalone trust type names to exclude from customer_transactions_model}';

    protected $description = 'Diagnose client cash-balance parity using non-invasive as-of methods';

    public function handle(): int
    {
        $clientFile = trim((string) $this->option('client-file'));
        $reportDateRaw = trim((string) $this->option('report-date'));
        $dateBasis = trim((string) $this->option('date-basis')) ?: 'settlement_date';
        $cutoff = trim((string) $this->option('cutoff'));
        $dataCutoff = trim((string) $this->option('data-cutoff')) ?: $cutoff;
        $currency = trim((string) $this->option('currency'));
        $excludePlanAccounts = $this->parseCsvOption((string) $this->option('exclude-plan-accounts'));
        $statusIds = $this->parseIntCsvOption((string) $this->option('status'));
        $excludedStandaloneTrustTypesOption = trim((string) $this->option('exclude-standalone-trust-types'));
        $excludedStandaloneTrustTypes = $excludedStandaloneTrustTypesOption !== ''
            ? $this->parseCsvOption($excludedStandaloneTrustTypesOption)
            : array_values(array_filter(array_map(
                static fn($value) => trim((string) $value),
                (array) config('viefund.cash_balance_excluded_standalone_trust_types', [])
            )));
        $outputFile = trim((string) $this->option('output-file'));
        $accountFile = trim((string) $this->option('account-file'));
        $focusAccounts = collect((array) $this->option('account-id'))
            ->map(fn($value) => $this->normalizeAccountIdentifier((string) $value))
            ->filter()
            ->values();

        if ($accountFile !== '') {
            $accountFilePath = str_starts_with($accountFile, '/') ? $accountFile : base_path($accountFile);
            if (!is_file($accountFilePath)) {
                $this->error('Provided --account-file does not exist.');
                return self::FAILURE;
            }

            $fileAccounts = collect(file($accountFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])
                ->map(fn($value) => $this->normalizeAccountIdentifier((string) $value))
                ->filter();

            $focusAccounts = $focusAccounts
                ->concat($fileAccounts)
                ->unique()
                ->values();
        }

        if ($clientFile === '' || !is_file($clientFile)) {
            $this->error('Provide a valid --client-file path.');
            return self::FAILURE;
        }

        if ($reportDateRaw === '') {
            $this->error('Missing --report-date.');
            return self::FAILURE;
        }

        if ($cutoff === '') {
            $this->error('Missing --cutoff.');
            return self::FAILURE;
        }

        $reportDate = Carbon::parse($reportDateRaw)->startOfDay();
        $asOfUpperBound = $reportDate->copy()->addDay()->startOfDay()->toDateTimeString();
        $fundDateColumn = $this->fundDateColumn($dateBasis);
        $trustDateColumn = $this->trustDateColumn($dateBasis);

        $this->info('Loading client balances...');
        $clientBalances = $this->loadClientBalances($clientFile);

        $this->info('Loading scoped plan/account universe...');
        $scopedAccounts = $this->loadScopedAccounts($currency, $cutoff, $excludePlanAccounts);

        if ($focusAccounts->isNotEmpty()) {
            $focusLookup = $focusAccounts->flip();
            $clientBalances = $clientBalances
                ->filter(fn($_, $accountId) => $focusLookup->has($accountId));
            $scopedAccounts = $scopedAccounts
                ->filter(fn($row) => $focusLookup->has($this->scopedAccountKey($row)))
                ->values();
        }

        $this->info('Computing diagnostic methods...');
        $snapshotBalances = $scopedAccounts->mapWithKeys(fn($row) => [
            $this->scopedAccountKey($row) => (float) $row->cash_snapshot_balance,
        ]);

        $txRollupBalances = $this->loadTransactionRollupBalances($asOfUpperBound, $fundDateColumn, [6], [22, 45], $currency, $scopedAccounts);
        $txAllTypeBalances = $this->loadTransactionRollupBalances($asOfUpperBound, $fundDateColumn, $statusIds ?: [6], null, $currency, $scopedAccounts);
        $directCashTrxBalances = $this->loadDirectCashTrxRollupBalances(
            $asOfUpperBound,
            $dataCutoff,
            $statusIds ?: [6],
            $currency,
            $scopedAccounts
        );

        if ((bool) $this->option('direct-only')) {
            $this->compareMethod('direct_cashtrx_rollup', $clientBalances, $directCashTrxBalances);

            return self::SUCCESS;
        }

        $asOfLatestBalances = $this->loadAsOfLatestCashTrxBalances($asOfUpperBound, $fundDateColumn, $statusIds ?: [6], $currency, $scopedAccounts);
        $customerTransactionsModelBalances = $this->loadCustomerTransactionsModelBalances(
            $asOfUpperBound,
            $fundDateColumn,
            $trustDateColumn,
            $statusIds ?: [6],
            ['Settled'],
            $currency,
            $excludedStandaloneTrustTypes,
            $scopedAccounts
        );

        $this->line('');
        $this->line('Scope Summary');
        $this->line('- client accounts: ' . number_format($clientBalances->count()));
        $this->line('- scoped accounts: ' . number_format($scopedAccounts->count()));

        $this->compareMethod('cash_snapshot_now', $clientBalances, $snapshotBalances);
        $this->compareMethod('tx_rollup_types_22_45_status_6', $clientBalances, $txRollupBalances);
        $this->compareMethod('tx_rollup_all_types_status_filter', $clientBalances, $txAllTypeBalances);
        $this->compareMethod('direct_cashtrx_rollup', $clientBalances, $directCashTrxBalances);
        $this->compareMethod('asof_latest_cashtrx_mBalance', $clientBalances, $asOfLatestBalances);
        $this->compareMethod('customer_transactions_model', $clientBalances, $customerTransactionsModelBalances);

        if ($outputFile !== '') {
            $cashCurrencyProfiles = $this->loadCashAccountCurrencyProfiles($scopedAccounts);
            $trustCurrencyProfiles = $this->loadStandaloneTrustCurrencyProfiles(
                $asOfUpperBound,
                $trustDateColumn,
                ['Settled'],
                $scopedAccounts
            );
            $standaloneTrustGicProfiles = $this->loadStandaloneTrustGicProfiles(
                $asOfUpperBound,
                $trustDateColumn,
                ['Settled'],
                $currency,
                $scopedAccounts
            );

            $this->writeComparisonCsv($outputFile, $clientBalances, [
                'cash_snapshot_now' => $snapshotBalances,
                'tx_rollup_types_22_45_status_6' => $txRollupBalances,
                'tx_rollup_all_types_status_filter' => $txAllTypeBalances,
                'direct_cashtrx_rollup' => $directCashTrxBalances,
                'asof_latest_cashtrx_mBalance' => $asOfLatestBalances,
                'customer_transactions_model' => $customerTransactionsModelBalances,
            ], $cashCurrencyProfiles, $trustCurrencyProfiles, $standaloneTrustGicProfiles);
            $this->info('Wrote per-account diagnostics CSV: storage/app/' . ltrim($outputFile, '/'));
        }

        return self::SUCCESS;
    }

    private function compareMethod(string $label, Collection $clientBalances, Collection $methodBalances): void
    {
        $clientAccounts = $clientBalances->keys();
        $methodAccounts = $methodBalances->keys();
        $common = $clientAccounts->intersect($methodAccounts)->values();

        $clientOnly = $clientAccounts->diff($methodAccounts)->values();
        $methodOnly = $methodAccounts->diff($clientAccounts)->values();

        $different = [];
        $exact = 0;

        foreach ($common as $accountId) {
            $clientValue = (float) $clientBalances->get($accountId, 0.0);
            $methodValue = (float) $methodBalances->get($accountId, 0.0);
            $delta = $clientValue - $methodValue;

            if (abs($delta) < 0.00001) {
                $exact++;
                continue;
            }

            $different[] = [
                'abs_delta' => abs($delta),
                'account_id' => $accountId,
                'client' => $clientValue,
                'method' => $methodValue,
                'delta' => $delta,
            ];
        }

        usort($different, fn($a, $b) => $b['abs_delta'] <=> $a['abs_delta']);

        $clientTotal = $clientBalances->sum();
        $methodTotal = $methodBalances->sum();

        $clientTop50 = $clientBalances->sortDesc()->keys()->take(50);
        $methodTop50 = $methodBalances->sortDesc()->keys()->take(50);
        $top50Overlap = $clientTop50->intersect($methodTop50)->count();

        $this->line('');
        $this->info('Method: ' . $label);
        $this->line('- common accounts: ' . number_format($common->count()));
        $this->line('- exact balance matches: ' . number_format($exact));
        $this->line('- different balances: ' . number_format(count($different)));
        $this->line('- client-only accounts: ' . number_format($clientOnly->count()));
        $this->line('- method-only accounts: ' . number_format($methodOnly->count()));
        $this->line('- client total: ' . $this->formatMoney($clientTotal));
        $this->line('- method total: ' . $this->formatMoney($methodTotal));
        $this->line('- delta (client-method): ' . $this->formatMoney($clientTotal - $methodTotal));
        $this->line('- top-50 overlap: ' . $top50Overlap);

        if (!empty($different)) {
            $this->line('- largest deltas:');
            foreach (array_slice($different, 0, 8) as $row) {
                $this->line(sprintf(
                    '  %s client=%s method=%s delta=%s',
                    $row['account_id'],
                    $this->formatMoney($row['client']),
                    $this->formatMoney($row['method']),
                    $this->formatMoney($row['delta'])
                ));
            }
        }
    }

    private function loadClientBalances(string $clientFile): Collection
    {
        $handle = fopen($clientFile, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open client CSV file.');
        }

        $balances = collect();

        while (($row = fgetcsv($handle)) !== false) {
            $accountRaw = trim((string) ($row[4] ?? ''));
            if ($accountRaw === '' || $accountRaw === 'CASH AGCH' || $accountRaw === 'Account ID') {
                continue;
            }

            $accountId = $this->normalizeAccountIdentifier($accountRaw);
            $balances[$accountId] = (float) $balances->get($accountId, 0.0)
                + $this->parseMoney($row[7] ?? '0');
        }

        fclose($handle);

        return $balances;
    }

    private function loadScopedAccounts(string $currencyCode, string $cutoff, array $excludedPlanAccounts): Collection
    {
        $schema = env('VIEFUND_DB_SCHEMA', 'dbo');
        $normalizedAccountId = "CASE WHEN LEFT(ca.AccountID, 1) = '#' THEN SUBSTRING(ca.AccountID, 2, LEN(ca.AccountID) - 1) ELSE ca.AccountID END";

        $query = DB::connection('viefund_sqlsrv')
            ->table("{$schema}.UB_Plan as p")
            ->join("{$schema}.UB_CashAccount as ca", 'ca.iPlanID', '=', 'p.ID')
            ->whereNotNull('ca.AccountID')
            ->where('ca.AccountID', '<>', '')
            ->whereNotNull('p.DealerAccountID')
            ->where('p.DealerAccountID', '<>', '')
            ->where('p.iClientID', '<>', 28)
            ->where('ca.CurrencyCode', '=', $currencyCode)
            ->whereNotNull('ca.dtOpen')
            ->where('ca.dtOpen', '<=', $cutoff);

        if (!empty($excludedPlanAccounts)) {
            $query->whereNotIn('p.DealerAccountID', $excludedPlanAccounts);
        }

        $ranked = $query
            ->selectRaw(
                "p.ID AS plan_id, p.DealerAccountID AS plan_account_id, {$normalizedAccountId} AS account_id, CAST(ISNULL(ca.mBalance, 0) AS decimal(38,2)) AS cash_snapshot_balance, " .
                    "ROW_NUMBER() OVER (PARTITION BY p.ID ORDER BY " .
                    "CASE WHEN {$normalizedAccountId} = p.ThirdPartyAccount THEN 0 ELSE 1 END, " .
                    "CASE WHEN {$normalizedAccountId} = p.DealerAccountID THEN 0 ELSE 1 END, " .
                    "CASE WHEN ca.AccountStatus IN ('A', 'Active') THEN 0 WHEN ca.AccountStatus IN ('T', 'Terminated') THEN 1 ELSE 2 END, " .
                    "ISNULL(ca.dtCreated, '1900-01-01') ASC, {$normalizedAccountId} ASC" .
                    ") AS cash_rank"
            );

        return DB::connection('viefund_sqlsrv')
            ->query()
            ->fromSub($ranked, 'ranked_cash')
            ->where('cash_rank', '=', 1)
            ->selectRaw('plan_id, plan_account_id, account_id, cash_snapshot_balance')
            ->get();
    }

    private function loadTransactionRollupBalances(string $asOfUpperBound, string $fundDateColumn, array $statusIds, ?array $typeIds, string $currencyCode, Collection $scopedAccounts): Collection
    {
        $schema = env('VIEFUND_DB_SCHEMA', 'dbo');
        $scopedPlanIds = $scopedAccounts->pluck('plan_id')->map(fn($id) => (int) $id)->values()->all();

        if (empty($scopedPlanIds)) {
            return collect();
        }

        $perPlanSums = [];
        foreach (array_chunk($scopedPlanIds, 1800) as $planIdChunk) {
            $fundDistinct = DB::connection('viefund_sqlsrv')
                ->table("{$schema}.UB_FundTrxLookup as l")
                ->join("{$schema}.UB_FundTrx as t", 't.ID', '=', 'l.iTrxID')
                ->leftJoin("{$schema}.UB_FundTrxCash as fc", 'fc.iTrxID', '=', 'l.iTrxID')
                ->leftJoin("{$schema}.UB_CashTrx as ct", 'ct.ID', '=', 'fc.iCashTrxID')
                ->leftJoin("{$schema}.UB_CashAccount as ca", 'ca.ID', '=', 'ct.iCashAccountID')
                ->whereIn('l.iPlanID', $planIdChunk)
                ->where($fundDateColumn, '<', $asOfUpperBound)
                ->whereNotNull($fundDateColumn)
                ->whereNotNull('ct.mAmount')
                ->where('ca.CurrencyCode', '=', $currencyCode)
                ->when(!empty($statusIds), function ($query) use ($statusIds) {
                    $query->whereIn('ct.iStatus', $statusIds);
                })
                ->when(!empty($typeIds), function ($query) use ($typeIds) {
                    $query->whereIn('ct.iType', $typeIds);
                })
                ->distinct()
                ->selectRaw('l.iPlanID AS plan_id, ct.ID AS cash_id, ct.mAmount AS cash_amount');

            $chunkRows = DB::connection('viefund_sqlsrv')
                ->query()
                ->fromSub($fundDistinct, 'fund_distinct')
                ->selectRaw('plan_id, SUM(cash_amount) AS balance')
                ->groupBy('plan_id')
                ->get();

            foreach ($chunkRows as $row) {
                $planId = (int) $row->plan_id;
                $balance = (float) ($row->balance ?? 0.0);
                $perPlanSums[$planId] = ($perPlanSums[$planId] ?? 0.0) + $balance;
            }
        }

        return $scopedAccounts->mapWithKeys(function ($row) use ($perPlanSums) {
            $accountId = $this->scopedAccountKey($row);
            $planId = (int) $row->plan_id;
            $balance = (float) ($perPlanSums[$planId] ?? 0.0);

            return [$accountId => $balance];
        });
    }

    private function loadDirectCashTrxRollupBalances(string $asOfUpperBound, string $dataCutoff, array $statusIds, string $currencyCode, Collection $scopedAccounts): Collection
    {
        $schema = env('VIEFUND_DB_SCHEMA', 'dbo');
        $scopedPlanIds = $scopedAccounts->pluck('plan_id')->map(fn($id) => (int) $id)->values()->all();

        if (empty($scopedPlanIds)) {
            return collect();
        }

        $deletedAfterCutoffIds = DB::connection('viefund_sqlsrv')
            ->table("{$schema}.UB_CashTrx as deleted_ct")
            ->leftJoin("{$schema}.UB_FundTrxCash as deleted_fc", 'deleted_fc.iCashTrxID', '=', 'deleted_ct.ID')
            ->leftJoin("{$schema}.UB_FundTrx as deleted_ft", 'deleted_ft.ID', '=', 'deleted_fc.iTrxID')
            ->leftJoin("{$schema}.UB_TrustTrx as deleted_tr", 'deleted_tr.ID', '=', 'deleted_ct.iTrustTrxID')
            ->where('deleted_ct.iStatus', 0)
            ->where('deleted_ct.dtCreated', '<=', $dataCutoff)
            ->where(function ($linkedChange) use ($dataCutoff) {
                $linkedChange->where('deleted_ft.dtLastModified', '>', $dataCutoff)
                    ->orWhere('deleted_tr.dtLastModified', '>', $dataCutoff);
            })
            ->distinct()
            ->pluck('deleted_ct.ID')
            ->map(fn($id) => (int) $id)
            ->all();

        $perPlanSums = [];
        foreach (array_chunk($scopedPlanIds, 1800) as $planIdChunk) {
            $chunkRows = DB::connection('viefund_sqlsrv')
                ->table("{$schema}.UB_CashTrx as ct")
                ->join("{$schema}.UB_CashAccount as ca", 'ca.ID', '=', 'ct.iCashAccountID')
                ->whereIn('ct.iPlanID', $planIdChunk)
                ->where('ca.CurrencyCode', '=', $currencyCode)
                ->whereNotNull('ct.dtSettlement')
                ->where('ct.dtSettlement', '<', $asOfUpperBound)
                ->whereNotNull('ct.dtCreated')
                ->where('ct.dtCreated', '<=', $dataCutoff)
                ->whereNotNull('ct.mAmount')
                ->when(!empty($statusIds), function ($query) use ($statusIds, $deletedAfterCutoffIds) {
                    $query->where(function ($statusQuery) use ($statusIds, $deletedAfterCutoffIds) {
                        $statusQuery->whereIn('ct.iStatus', $statusIds)
                            ->when(!empty($deletedAfterCutoffIds), fn($deletedQuery) => $deletedQuery->orWhereIn('ct.ID', $deletedAfterCutoffIds));
                    });
                })
                ->selectRaw('ct.iPlanID AS plan_id, SUM(ct.mAmount) AS balance')
                ->groupBy('ct.iPlanID')
                ->get();

            foreach ($chunkRows as $row) {
                $perPlanSums[(int) $row->plan_id] = (float) ($row->balance ?? 0.0);
            }
        }

        return $scopedAccounts->reduce(function (Collection $balances, $row) use ($perPlanSums) {
            $accountId = $this->scopedAccountKey($row);
            $planId = (int) $row->plan_id;
            $balances[$accountId] = (float) $balances->get($accountId, 0.0)
                + (float) ($perPlanSums[$planId] ?? 0.0);

            return $balances;
        }, collect());
    }

    private function loadAsOfLatestCashTrxBalances(string $asOfUpperBound, string $fundDateColumn, array $statusIds, string $currencyCode, Collection $scopedAccounts): Collection
    {
        $schema = env('VIEFUND_DB_SCHEMA', 'dbo');
        $scopedPlanIds = $scopedAccounts->pluck('plan_id')->map(fn($id) => (int) $id)->values()->all();

        if (empty($scopedPlanIds)) {
            return collect();
        }

        $latestPerPlan = [];
        foreach (array_chunk($scopedPlanIds, 1800) as $planIdChunk) {
            $chunkRows = DB::connection('viefund_sqlsrv')
                ->table("{$schema}.UB_FundTrxLookup as l")
                ->join("{$schema}.UB_FundTrx as t", 't.ID', '=', 'l.iTrxID')
                ->leftJoin("{$schema}.UB_FundTrxCash as fc", 'fc.iTrxID', '=', 'l.iTrxID')
                ->leftJoin("{$schema}.UB_CashTrx as ct", 'ct.ID', '=', 'fc.iCashTrxID')
                ->leftJoin("{$schema}.UB_CashAccount as ca", 'ca.ID', '=', 'ct.iCashAccountID')
                ->whereIn('l.iPlanID', $planIdChunk)
                ->where($fundDateColumn, '<', $asOfUpperBound)
                ->whereNotNull($fundDateColumn)
                ->whereNotNull('ct.mBalance')
                ->where('ca.CurrencyCode', '=', $currencyCode)
                ->when(!empty($statusIds), function ($query) use ($statusIds) {
                    $query->whereIn('ct.iStatus', $statusIds);
                })
                ->selectRaw("l.iPlanID AS plan_id, CAST(ISNULL(ct.mBalance, 0) AS decimal(38,2)) AS cash_balance, ROW_NUMBER() OVER (PARTITION BY l.iPlanID ORDER BY {$fundDateColumn} DESC, ct.ID DESC) AS rn")
                ->get()
                ->filter(fn($row) => (int) ($row->rn ?? 0) === 1);

            foreach ($chunkRows as $row) {
                $latestPerPlan[(int) $row->plan_id] = (float) ($row->cash_balance ?? 0.0);
            }
        }

        return $scopedAccounts->mapWithKeys(function ($row) use ($latestPerPlan) {
            $accountId = $this->scopedAccountKey($row);
            $planId = (int) $row->plan_id;
            $balance = (float) ($latestPerPlan[$planId] ?? 0.0);

            return [$accountId => $balance];
        });
    }

    private function loadCustomerTransactionsModelBalances(
        string $asOfUpperBound,
        string $fundDateColumn,
        string $trustDateColumn,
        array $statusIds,
        array $trustStatusNames,
        string $currencyCode,
        array $excludedStandaloneTrustTypes,
        Collection $scopedAccounts
    ): Collection {
        $fundBalances = $this->loadTransactionRollupBalances($asOfUpperBound, $fundDateColumn, $statusIds, [22, 45], $currencyCode, $scopedAccounts);
        $standaloneTrustBalances = $this->loadStandaloneTrustBalances(
            $asOfUpperBound,
            $trustDateColumn,
            $trustStatusNames,
            $currencyCode,
            $excludedStandaloneTrustTypes,
            $scopedAccounts
        );

        return $scopedAccounts->mapWithKeys(function ($row) use ($fundBalances, $standaloneTrustBalances) {
            $accountId = $this->scopedAccountKey($row);
            $balance = (float) ($fundBalances->get($accountId, 0.0))
                + (float) ($standaloneTrustBalances->get($accountId, 0.0));

            return [$accountId => $balance];
        });
    }

    private function loadStandaloneTrustBalances(
        string $asOfUpperBound,
        string $trustDateColumn,
        array $trustStatusNames,
        string $currencyCode,
        array $excludedStandaloneTrustTypes,
        Collection $scopedAccounts
    ): Collection {
        $schema = env('VIEFUND_DB_SCHEMA', 'dbo');
        $scopedPlanIds = $scopedAccounts->pluck('plan_id')->map(fn($id) => (int) $id)->values()->all();

        if (empty($scopedPlanIds)) {
            return collect();
        }

        $perPlanSums = [];
        foreach (array_chunk($scopedPlanIds, 1800) as $planIdChunk) {
            $fundLinkedTrustIds = [];

            $fundRows = DB::connection('viefund_sqlsrv')
                ->table("{$schema}.UB_FundTrxLookup as l")
                ->join("{$schema}.UB_FundTrx as t", 't.ID', '=', 'l.iTrxID')
                ->leftJoin("{$schema}.UB_FundTrxCash as fc", 'fc.iTrxID', '=', 'l.iTrxID')
                ->leftJoin("{$schema}.UB_CashTrx as ct", 'ct.ID', '=', 'fc.iCashTrxID')
                ->leftJoin("{$schema}.UB_CashAccount as ca", 'ca.ID', '=', 'ct.iCashAccountID')
                ->whereIn('l.iPlanID', $planIdChunk)
                ->where($this->fundDateColumn('settlement_date'), '<', $asOfUpperBound)
                ->whereNotNull($this->fundDateColumn('settlement_date'))
                ->whereNotNull('ct.mAmount')
                ->whereIn('ct.iStatus', [6])
                ->whereIn('ct.iType', [22, 45])
                ->where('ca.CurrencyCode', '=', $currencyCode)
                ->distinct()
                ->selectRaw('l.iPlanID AS plan_id, ISNULL(ct.iTrustTrxID, 0) AS linked_trust_trx_id')
                ->get();

            foreach ($fundRows as $row) {
                $planId = (int) $row->plan_id;
                $linkedTrustTrxId = (int) ($row->linked_trust_trx_id ?? 0);
                if ($linkedTrustTrxId <= 0) {
                    continue;
                }

                $fundLinkedTrustIds[$planId][$linkedTrustTrxId] = ($fundLinkedTrustIds[$planId][$linkedTrustTrxId] ?? 0) + 1;
            }

            $query = DB::connection('viefund_sqlsrv')
                ->table("{$schema}.UB_TrustTrx as tr")
                ->leftJoin("{$schema}.UB_Def_TrustStatus as ts", 'ts.ID', '=', 'tr.iStatus')
                ->leftJoin("{$schema}.UB_Def_TrustType as tt", 'tt.ID', '=', 'tr.iType')
                ->whereIn('tr.iPlanID', $planIdChunk)
                ->whereRaw('ISNULL(tr.iTrxID, 0) = 0')
                ->where($trustDateColumn, '<', $asOfUpperBound)
                ->whereNotNull($trustDateColumn)
                ->whereNotNull('tr.mAmount')
                ->where('tr.CurrencyCode', '=', $currencyCode)
                ->when(!empty($trustStatusNames), function ($query) use ($trustStatusNames) {
                    $query->where(function ($nested) use ($trustStatusNames) {
                        $nested->whereIn('ts.NameEN', $trustStatusNames)
                            ->orWhereNull('ts.NameEN');
                    });
                })
                ->when(!empty($excludedStandaloneTrustTypes), function ($query) use ($excludedStandaloneTrustTypes) {
                    $query->where(function ($nested) use ($excludedStandaloneTrustTypes) {
                        $nested->whereNotIn('tt.NameEN', $excludedStandaloneTrustTypes)
                            ->orWhereNull('tt.NameEN');
                    });
                })
                ->selectRaw('tr.ID AS trust_trx_id, tr.iPlanID AS plan_id, CAST(tr.mAmount AS decimal(38,2)) AS amount')
                ->get();

            foreach ($query as $row) {
                $planId = (int) $row->plan_id;
                $trustTrxId = (int) ($row->trust_trx_id ?? 0);
                $amount = (float) ($row->amount ?? 0.0);

                if ($trustTrxId > 0 && (($fundLinkedTrustIds[$planId][$trustTrxId] ?? 0) > 0)) {
                    $fundLinkedTrustIds[$planId][$trustTrxId]--;
                    continue;
                }

                $perPlanSums[$planId] = ($perPlanSums[$planId] ?? 0.0) + $amount;
            }
        }

        return $scopedAccounts->mapWithKeys(function ($row) use ($perPlanSums) {
            $accountId = $this->scopedAccountKey($row);
            $planId = (int) $row->plan_id;
            $balance = (float) ($perPlanSums[$planId] ?? 0.0);

            return [$accountId => $balance];
        });
    }

    private function fundDateColumn(string $basis): string
    {
        return match ($basis) {
            'create_date' => 't.dtCreated',
            'trade_date' => 'ct.dtTrade',
            'processing_date' => 'ct.dtProcessing',
            'settlement_date' => 'ct.dtSettlement',
            default => throw new \InvalidArgumentException('Unsupported date basis.'),
        };
    }

    private function trustDateColumn(string $basis): string
    {
        return match ($basis) {
            'create_date' => 'tr.dtCreated',
            'trade_date', 'processing_date' => 'tr.dtEffective',
            'settlement_date' => 'tr.dtSettlement',
            default => throw new \InvalidArgumentException('Unsupported date basis.'),
        };
    }

    private function parseCsvOption(string $value): array
    {
        return array_values(array_filter(array_map(
            static fn($item) => trim($item),
            explode(',', $value)
        )));
    }

    private function parseIntCsvOption(string $value): array
    {
        return array_values(array_filter(array_map(
            static fn($item) => (int) trim($item),
            explode(',', $value)
        ), static fn($id) => $id >= 0));
    }

    private function normalizeAccountIdentifier(string $value): string
    {
        $normalized = trim($value);
        $normalized = preg_replace('/^AGRA\s+CASH\s+#?/i', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/^AGRP\s+CASH\s+#?/i', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/^AGRA\s+/i', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/^AGRP\s+/i', '', $normalized) ?? $normalized;
        if (str_starts_with($normalized, '#')) {
            $normalized = substr($normalized, 1);
        }

        return trim($normalized);
    }

    private function scopedAccountKey(object $row): string
    {
        $planAccountId = $this->normalizeAccountIdentifier((string) ($row->plan_account_id ?? ''));
        if ($planAccountId !== '') {
            return $planAccountId;
        }

        return $this->normalizeAccountIdentifier((string) ($row->account_id ?? ''));
    }

    private function parseMoney(mixed $value): float
    {
        $text = trim((string) $value);
        if ($text === '') {
            return 0.0;
        }

        $negative = str_starts_with($text, '(') && str_ends_with($text, ')');
        if ($negative) {
            $text = substr($text, 1, -1);
        }

        $text = str_replace(['$', ',', ' '], '', $text);
        if (str_starts_with($text, '-')) {
            $negative = true;
            $text = substr($text, 1);
        }

        $number = (float) ($text !== '' ? $text : 0);

        return $negative ? -$number : $number;
    }

    private function formatMoney(float $amount): string
    {
        $formatted = '$' . number_format(abs($amount), 2, '.', ',');

        return $amount < 0 ? '(' . $formatted . ')' : $formatted;
    }

    private function writeComparisonCsv(
        string $outputFile,
        Collection $clientBalances,
        array $methods,
        Collection $cashCurrencyProfiles,
        Collection $trustCurrencyProfiles,
        Collection $standaloneTrustGicProfiles
    ): void {
        $methodOrder = [
            'cash_snapshot_now',
            'tx_rollup_types_22_45_status_6',
            'tx_rollup_all_types_status_filter',
            'asof_latest_cashtrx_mBalance',
            'customer_transactions_model',
        ];

        $relativePath = ltrim($outputFile, '/');
        if (!str_starts_with($relativePath, 'reports/')) {
            $relativePath = 'reports/' . $relativePath;
        }

        $absolutePath = storage_path('app/' . $relativePath);
        $directory = dirname($absolutePath);
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $handle = fopen($absolutePath, 'w');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open diagnostics CSV output file for writing.');
        }

        fputcsv($handle, [
            'account_id',
            'client_balance',
            'cash_snapshot_now',
            'cash_snapshot_now_delta',
            'tx_rollup_types_22_45_status_6',
            'tx_rollup_types_22_45_status_6_delta',
            'tx_rollup_all_types_status_filter',
            'tx_rollup_all_types_status_filter_delta',
            'asof_latest_cashtrx_mBalance',
            'asof_latest_cashtrx_mBalance_delta',
            'customer_transactions_model',
            'customer_transactions_model_delta',
            'cash_account_currencies',
            'standalone_trust_currencies',
            'standalone_gic_account_ids',
            'standalone_gic_total',
            'standalone_gic_accrued_interest',
            'standalone_gic_maturity_payment',
            'standalone_trust_interest',
            'best_matching_method',
            'best_delta',
            'best_delta_abs',
        ]);

        foreach ($clientBalances->sortDesc() as $accountId => $clientBalance) {
            $row = [$accountId, $this->formatPlainNumber((float) $clientBalance)];
            $bestMethod = '';
            $bestDelta = 0.0;
            $bestAbsDelta = INF;

            foreach ($methodOrder as $label) {
                $methodBalance = (float) ($methods[$label]->get($accountId, 0.0));
                $delta = (float) $clientBalance - $methodBalance;
                $row[] = $this->formatPlainNumber($methodBalance);
                $row[] = $this->formatPlainNumber($delta);

                $absDelta = abs($delta);
                if ($absDelta < $bestAbsDelta) {
                    $bestAbsDelta = $absDelta;
                    $bestDelta = $delta;
                    $bestMethod = $label;
                }
            }

            $row[] = (string) $cashCurrencyProfiles->get($accountId, '');
            $row[] = (string) $trustCurrencyProfiles->get($accountId, '');
            $gicProfile = (array) $standaloneTrustGicProfiles->get($accountId, []);
            $row[] = (string) ($gicProfile['gic_account_ids'] ?? '');
            $row[] = $this->formatPlainNumber((float) ($gicProfile['gic_total'] ?? 0.0));
            $row[] = $this->formatPlainNumber((float) ($gicProfile['gic_accrued_interest'] ?? 0.0));
            $row[] = $this->formatPlainNumber((float) ($gicProfile['gic_maturity_payment'] ?? 0.0));
            $row[] = $this->formatPlainNumber((float) ($gicProfile['trust_interest'] ?? 0.0));
            $row[] = $bestMethod;
            $row[] = $this->formatPlainNumber($bestDelta);
            $row[] = is_finite($bestAbsDelta) ? $this->formatPlainNumber($bestAbsDelta) : '';

            fputcsv($handle, $row);
        }

        fclose($handle);
    }

    private function formatPlainNumber(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    private function loadCashAccountCurrencyProfiles(Collection $scopedAccounts): Collection
    {
        $schema = env('VIEFUND_DB_SCHEMA', 'dbo');
        $planToAccount = $scopedAccounts
            ->mapWithKeys(fn($row) => [(int) $row->plan_id => $this->scopedAccountKey($row)]);
        $scopedPlanIds = $planToAccount->keys()->values()->all();

        if (empty($scopedPlanIds)) {
            return collect();
        }

        $codesByAccount = [];

        foreach (array_chunk($scopedPlanIds, 1800) as $planIdChunk) {
            $rows = DB::connection('viefund_sqlsrv')
                ->table("{$schema}.UB_CashAccount as ca")
                ->whereIn('ca.iPlanID', $planIdChunk)
                ->whereNotNull('ca.CurrencyCode')
                ->selectRaw('ca.iPlanID AS plan_id, ca.CurrencyCode AS currency_code')
                ->get();

            foreach ($rows as $row) {
                $planId = (int) $row->plan_id;
                $accountId = (string) $planToAccount->get($planId, '');
                $currencyCode = trim((string) ($row->currency_code ?? ''));

                if ($accountId === '' || $currencyCode === '') {
                    continue;
                }

                $codesByAccount[$accountId][$currencyCode] = true;
            }
        }

        return collect($codesByAccount)->map(function (array $codes) {
            $currencyCodes = array_keys($codes);
            sort($currencyCodes, SORT_STRING);

            return implode('|', $currencyCodes);
        });
    }

    private function loadStandaloneTrustCurrencyProfiles(
        string $asOfUpperBound,
        string $trustDateColumn,
        array $trustStatusNames,
        Collection $scopedAccounts
    ): Collection {
        $schema = env('VIEFUND_DB_SCHEMA', 'dbo');
        $planToAccount = $scopedAccounts
            ->mapWithKeys(fn($row) => [(int) $row->plan_id => $this->scopedAccountKey($row)]);
        $scopedPlanIds = $planToAccount->keys()->values()->all();

        if (empty($scopedPlanIds)) {
            return collect();
        }

        $codesByAccount = [];

        foreach (array_chunk($scopedPlanIds, 1800) as $planIdChunk) {
            $rows = DB::connection('viefund_sqlsrv')
                ->table("{$schema}.UB_TrustTrx as tr")
                ->leftJoin("{$schema}.UB_Def_TrustStatus as ts", 'ts.ID', '=', 'tr.iStatus')
                ->whereIn('tr.iPlanID', $planIdChunk)
                ->whereRaw('ISNULL(tr.iTrxID, 0) = 0')
                ->where($trustDateColumn, '<', $asOfUpperBound)
                ->whereNotNull($trustDateColumn)
                ->whereNotNull('tr.mAmount')
                ->when(!empty($trustStatusNames), function ($query) use ($trustStatusNames) {
                    $query->where(function ($nested) use ($trustStatusNames) {
                        $nested->whereIn('ts.NameEN', $trustStatusNames)
                            ->orWhereNull('ts.NameEN');
                    });
                })
                ->whereNotNull('tr.CurrencyCode')
                ->selectRaw('tr.iPlanID AS plan_id, tr.CurrencyCode AS currency_code')
                ->get();

            foreach ($rows as $row) {
                $planId = (int) $row->plan_id;
                $accountId = (string) $planToAccount->get($planId, '');
                $currencyCode = trim((string) ($row->currency_code ?? ''));

                if ($accountId === '' || $currencyCode === '') {
                    continue;
                }

                $codesByAccount[$accountId][$currencyCode] = true;
            }
        }

        return collect($codesByAccount)->map(function (array $codes) {
            $currencyCodes = array_keys($codes);
            sort($currencyCodes, SORT_STRING);

            return implode('|', $currencyCodes);
        });
    }

    private function loadStandaloneTrustGicProfiles(
        string $asOfUpperBound,
        string $trustDateColumn,
        array $trustStatusNames,
        string $currencyCode,
        Collection $scopedAccounts
    ): Collection {
        $schema = env('VIEFUND_DB_SCHEMA', 'dbo');
        $planToAccount = $scopedAccounts
            ->mapWithKeys(fn($row) => [(int) $row->plan_id => $this->scopedAccountKey($row)]);
        $scopedPlanIds = $planToAccount->keys()->values()->all();

        if (empty($scopedPlanIds)) {
            return collect();
        }

        $profilesByAccount = [];

        foreach (array_chunk($scopedPlanIds, 1800) as $planIdChunk) {
            $rows = DB::connection('viefund_sqlsrv')
                ->table("{$schema}.UB_TrustTrx as tr")
                ->leftJoin("{$schema}.UB_Def_TrustStatus as ts", 'ts.ID', '=', 'tr.iStatus')
                ->leftJoin("{$schema}.UB_Def_TrustType as tt", 'tt.ID', '=', 'tr.iType')
                ->whereIn('tr.iPlanID', $planIdChunk)
                ->whereRaw('ISNULL(tr.iTrxID, 0) = 0')
                ->where($trustDateColumn, '<', $asOfUpperBound)
                ->whereNotNull($trustDateColumn)
                ->whereNotNull('tr.mAmount')
                ->where('tr.CurrencyCode', '=', $currencyCode)
                ->when(!empty($trustStatusNames), function ($query) use ($trustStatusNames) {
                    $query->where(function ($nested) use ($trustStatusNames) {
                        $nested->whereIn('ts.NameEN', $trustStatusNames)
                            ->orWhereNull('ts.NameEN');
                    });
                })
                ->selectRaw('tr.iPlanID AS plan_id, tr.iGICAccountID AS gic_account_id, tt.NameEN AS trust_type, CAST(tr.mAmount AS decimal(38,2)) AS amount')
                ->get();

            foreach ($rows as $row) {
                $planId = (int) $row->plan_id;
                $accountId = (string) $planToAccount->get($planId, '');

                if ($accountId === '') {
                    continue;
                }

                if (!isset($profilesByAccount[$accountId])) {
                    $profilesByAccount[$accountId] = [
                        'gic_account_ids' => [],
                        'gic_total' => 0.0,
                        'gic_accrued_interest' => 0.0,
                        'gic_maturity_payment' => 0.0,
                        'trust_interest' => 0.0,
                    ];
                }

                $amount = (float) ($row->amount ?? 0.0);
                $trustType = trim((string) ($row->trust_type ?? ''));
                $gicAccountId = (int) ($row->gic_account_id ?? 0);

                if ($gicAccountId > 0) {
                    $profilesByAccount[$accountId]['gic_account_ids'][$gicAccountId] = true;
                    $profilesByAccount[$accountId]['gic_total'] += $amount;
                }

                if ($trustType === 'GIC Accrued Interest') {
                    $profilesByAccount[$accountId]['gic_accrued_interest'] += $amount;
                }

                if ($trustType === 'GIC Maturity Payment') {
                    $profilesByAccount[$accountId]['gic_maturity_payment'] += $amount;
                }

                if ($trustType === 'Interest') {
                    $profilesByAccount[$accountId]['trust_interest'] += $amount;
                }
            }
        }

        return collect($profilesByAccount)->map(function (array $profile) {
            $gicAccountIds = array_keys($profile['gic_account_ids']);
            sort($gicAccountIds, SORT_NUMERIC);
            $profile['gic_account_ids'] = implode('|', array_map('strval', $gicAccountIds));

            return $profile;
        });
    }
}
