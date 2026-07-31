<?php

namespace App\Http\Controllers;

use App\Models\BankStatementEntry;
use App\Models\VieFundDailyTotal;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class DailyTotalsComparisonController extends Controller
{
    private const PARSER_VERSION = 'v2';

    private const VIEFUND_CASH_STATUS_CONFIRMED = 6;
    private const VIEFUND_CASH_TYPES = [22, 45];
    private const LOCK_TTL_SECONDS = 43200;
    private const PER_PAGE_OPTIONS = [25, 50, 100, 250];
    private const DEFAULT_PER_PAGE = 50;

    /** Fund transaction statuses (UB_Def_TrxStatus id => label). */
    public const FUND_STATUS_OPTIONS = [
        0 => 'Deleted',
        1 => 'Rejected',
        2 => 'Cancelled',
        3 => 'Pending',
        4 => 'Accepted',
        5 => 'Contracted',
        6 => 'Confirmed',
    ];

    /** Trust transaction statuses (UB_Def_TrustStatus NameEN). */
    public const TRUST_STATUS_OPTIONS = ['Deleted', 'Unsettled', 'Settled'];

    public function index(Request $request): View
    {
        $earliestBankDate = BankStatementEntry::min('value_date');
        $earliestVieFundDate = VieFundDailyTotal::min('total_date');
        $earliestAvailableDate = collect([$earliestBankDate, $earliestVieFundDate])
            ->filter()
            ->map(fn($date) => Carbon::parse($date)->startOfDay())
            ->sort()
            ->first();

        $defaultStart = $earliestAvailableDate
            ? $earliestAvailableDate->toDateString()
            : Carbon::today()->toDateString();

        $dateFrom = $request->filled('date_from') ? Carbon::parse($request->date_from)->toDateString() : $defaultStart;
        $dateTo = $request->filled('date_to') ? Carbon::parse($request->date_to)->toDateString() : Carbon::today()->toDateString();
        $showZeroDays = $request->boolean('show_zero_days');
        $onlyFundservBank = $request->has('only_fundserv_bank')
            ? $request->boolean('only_fundserv_bank')
            : true;
        $includeIncomplete = $request->has('include_incomplete')
            ? $request->boolean('include_incomplete')
            : true;
        $perPage = (int) $request->query('per_page', self::DEFAULT_PER_PAGE);
        if (!in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = self::DEFAULT_PER_PAGE;
        }
        $sortField = in_array($request->query('sort'), ['total_date', 'bank_net_total', 'viefund_net_total', 'variance', 'discrepancy_pct'], true)
            ? $request->query('sort')
            : 'total_date';
        $sortDir = $request->query('sort_dir') === 'asc' ? 'asc' : 'desc';

        $bankRowsQuery = DB::table('bank_statement_entries')
            ->leftJoin('bank_statement_entry_analyses as a', function ($join) {
                $join->on('a.bank_statement_entry_id', '=', 'bank_statement_entries.id')
                    ->where('a.parser_version', self::PARSER_VERSION);
            })
            ->whereBetween('value_date', [$dateFrom, $dateTo])
            ->when($onlyFundservBank, function ($query) {
                $query->whereRaw('LOWER(a.counterparty) LIKE ?', ['%fundserv%']);
            })
            ->selectRaw("value_date as total_date, COUNT(*) as transaction_count, SUM(CASE WHEN credit_debit_indicator = 'DBIT' THEN -amount ELSE amount END) as net_total")
            ->groupBy('value_date')
            ->orderBy('value_date');

        $bankRows = $bankRowsQuery->get();

        [$selectedBasis, $selectedStatuses, $selectedTrustStatuses] = $this->resolveSelection($request);
        $variantKey = VieFundDailyTotal::variantKey($selectedBasis, $selectedStatuses, $selectedTrustStatuses);
        $viefundVariantSynced = VieFundDailyTotal::where('variant_key', $variantKey)->exists();
        $viefundLastSynced = VieFundDailyTotal::where('variant_key', $variantKey)->max('synced_at');
        $syncInProgress = $this->syncInProgress();
        // No cached data for this combo and nothing running → the page will auto-start a sync.
        $autoSync = !$viefundVariantSynced && !$syncInProgress;

        $viefundRows = VieFundDailyTotal::query()
            ->where('variant_key', $variantKey)
            ->whereBetween('total_date', [$dateFrom, $dateTo])
            ->orderBy('total_date')
            ->get();

        $byDate = [];
        foreach ($bankRows as $row) {
            $dateKey = Carbon::parse($row->total_date)->toDateString();

            $byDate[$dateKey] = [
                'total_date' => $dateKey,
                'bank_transaction_count' => (int) $row->transaction_count,
                'bank_net_total' => (float) $row->net_total,
                'viefund_transaction_count' => 0,
                'viefund_net_total' => 0.0,
                'has_bank_data' => true,
                'has_viefund_data' => false,
            ];
        }

        foreach ($viefundRows as $row) {
            $dateKey = Carbon::parse($row->total_date)->toDateString();

            $byDate[$dateKey] = array_merge($byDate[$dateKey] ?? [
                'total_date' => $dateKey,
                'bank_transaction_count' => 0,
                'bank_net_total' => 0.0,
                'has_bank_data' => false,
            ], [
                'viefund_transaction_count' => (int) $row->transaction_count,
                'viefund_net_total' => (float) $row->net_total,
                'has_viefund_data' => true,
            ]);
        }

        $rows = collect(array_values($byDate))->map(function (array $row) {
            if (!($row['has_bank_data'] ?? false) && ($row['has_viefund_data'] ?? false)) {
                $row['status'] = 'missing-bank';
            } elseif (($row['has_bank_data'] ?? false) && !($row['has_viefund_data'] ?? false)) {
                $row['status'] = 'missing-viefund';
            }

            $row['variance'] = $row['bank_net_total'] - $row['viefund_net_total'];
            $bankAbs = abs($row['bank_net_total']);
            $row['discrepancy_pct'] = $bankAbs < 0.0001
                ? null
                : (abs($row['variance']) / $bankAbs) * 100;

            if (!isset($row['status'])) {
                $row['status'] = abs($row['variance']) < 0.01 ? 'match' : ($row['variance'] > 0 ? 'bank-higher' : 'viefund-higher');
            }

            return $row;
        });

        if ($sortField === 'discrepancy_pct') {
            $rows = $sortDir === 'asc'
                ? $rows->sortBy(fn(array $row) => $row['discrepancy_pct'] ?? INF)->values()
                : $rows->sortByDesc(fn(array $row) => $row['discrepancy_pct'] ?? -INF)->values();
        } else {
            $rows = $sortDir === 'asc'
                ? $rows->sortBy($sortField)->values()
                : $rows->sortByDesc($sortField)->values();
        }

        // Default behavior: hide days where both sources net to zero.
        if (!$showZeroDays) {
            $rows = $rows->filter(function (array $row) {
                return !(abs($row['bank_net_total']) < 0.0001 && abs($row['viefund_net_total']) < 0.0001);
            })->values();
        }

        if (!$includeIncomplete) {
            $rows = $rows->filter(function (array $row) {
                return !in_array($row['status'], ['missing-bank', 'missing-viefund'], true);
            })->values();
        }

        $summary = [
            'days' => $rows->count(),
            'bank_total' => $rows->sum('bank_net_total'),
            'viefund_total' => $rows->sum('viefund_net_total'),
            'variance_total' => $rows->sum('variance'),
            'mismatch_days' => $rows->where('status', '!=', 'match')->count(),
        ];

        $currentPage = max(1, (int) $request->query('page', 1));
        $totalRows = $rows->count();
        $rows = new LengthAwarePaginator(
            $rows->forPage($currentPage, $perPage)->values(),
            $totalRows,
            $perPage,
            $currentPage,
            [
                'path' => route('reconciliations.daily-totals'),
                'query' => $request->query(),
            ]
        );

        $fundStatusOptions = self::FUND_STATUS_OPTIONS;
        $trustStatusOptions = self::TRUST_STATUS_OPTIONS;
        $dateBasisOptions = VieFundDailyTotal::DATE_BASIS_OPTIONS;

        return view('reconciliations.daily-totals', compact('rows', 'summary', 'dateFrom', 'dateTo', 'sortField', 'sortDir', 'showZeroDays', 'onlyFundservBank', 'includeIncomplete', 'perPage', 'fundStatusOptions', 'trustStatusOptions', 'dateBasisOptions', 'selectedStatuses', 'selectedTrustStatuses', 'selectedBasis', 'variantKey', 'viefundVariantSynced', 'viefundLastSynced', 'syncInProgress', 'autoSync'));
    }

    /**
     * Resolve the variant (date basis + fund statuses + trust statuses) the page
     * should display. An explicit request selection wins; otherwise default to
     * the most-recently-synced variant so real data shows on load, falling back
     * to the configured defaults when nothing has been synced.
     *
     * @return array{0: string, 1: int[], 2: string[]}
     */
    private function resolveSelection(Request $request): array
    {
        $allowedBasis = array_keys(VieFundDailyTotal::DATE_BASIS_OPTIONS);
        $defaultBasis = (string) config('viefund.default_date_basis', 'settlement_date');

        if ($request->has('date_basis') || $request->has('statuses') || $request->has('trust_statuses')) {
            $basis = in_array($request->query('date_basis'), $allowedBasis, true)
                ? $request->query('date_basis')
                : $defaultBasis;

            $statuses = array_values(array_filter(
                array_map('intval', (array) $request->query('statuses', [])),
                fn($id) => array_key_exists($id, self::FUND_STATUS_OPTIONS)
            ));
            if (empty($statuses)) {
                $statuses = (array) config('viefund.default_fund_status', [6]);
            }

            $trust = array_values(array_intersect(self::TRUST_STATUS_OPTIONS, (array) $request->query('trust_statuses', [])));

            return [$basis, $statuses, $trust];
        }

        $latest = VieFundDailyTotal::whereNotNull('variant_key')
            ->orderByDesc('synced_at')
            ->orderByDesc('id')
            ->first();

        if ($latest) {
            $basis = in_array($latest->date_basis, $allowedBasis, true) ? $latest->date_basis : $defaultBasis;
            $statuses = array_values(array_filter(
                array_map('intval', (array) $latest->status_ids),
                fn($id) => array_key_exists($id, self::FUND_STATUS_OPTIONS)
            )) ?: (array) config('viefund.default_fund_status', [6]);
            $trust = array_values(array_intersect(self::TRUST_STATUS_OPTIONS, (array) $latest->trust_status_names));

            return [$basis, $statuses, $trust];
        }

        return [
            $defaultBasis,
            (array) config('viefund.default_fund_status', [6]),
            (array) config('viefund.default_trust_status', ['Settled']),
        ];
    }

    public function sync(Request $request): RedirectResponse
    {
        $request->validate([
            'statuses' => ['sometimes', 'array'],
            'statuses.*' => ['integer', 'between:0,6'],
            'trust_statuses' => ['sometimes', 'array'],
            'trust_statuses.*' => ['in:' . implode(',', self::TRUST_STATUS_OPTIONS)],
            'date_basis' => ['sometimes', 'in:' . implode(',', array_keys(VieFundDailyTotal::DATE_BASIS_OPTIONS))],
        ]);

        // Fund selection defaults to Confirmed when nothing is checked; trust is
        // exactly what was checked (empty = exclude trust from the snapshot).
        $statusIds = array_values(array_unique(array_map('intval', (array) $request->input('statuses', []))));
        $statusIds = array_values(array_filter($statusIds, fn($id) => array_key_exists($id, self::FUND_STATUS_OPTIONS)));
        if (empty($statusIds)) {
            $statusIds = (array) config('viefund.default_fund_status', [6]);
        }
        $trustStatusNames = array_values(array_intersect(self::TRUST_STATUS_OPTIONS, (array) $request->input('trust_statuses', [])));

        $allowedBasis = array_keys(VieFundDailyTotal::DATE_BASIS_OPTIONS);
        $basis = in_array($request->input('date_basis'), $allowedBasis, true)
            ? $request->input('date_basis')
            : (string) config('viefund.default_date_basis', 'settlement_date');

        // Preserve the selected variant on redirect so the page shows it while the
        // background sync populates it.
        $selectionParams = [
            'date_basis' => $basis,
            'statuses' => $statusIds,
            'trust_statuses' => $trustStatusNames,
        ];

        $dispatched = $this->dispatchVariantSync($basis, $statusIds, $trustStatusNames);

        return redirect()
            ->route('reconciliations.daily-totals', $selectionParams)
            ->with(
                $dispatched ? 'sync_success' : 'sync_error',
                $dispatched
                    ? 'VieFund daily totals sync started for this basis/criteria.'
                    : 'A daily totals sync is already in progress.'
            );
    }

    private function syncLockFile(): string
    {
        return storage_path('app/viefund-daily-totals-sync.lock');
    }

    private function syncStatusFile(): string
    {
        return storage_path('app/viefund-daily-totals-sync-status.json');
    }

    private function syncInProgress(): bool
    {
        $lockFile = $this->syncLockFile();

        return file_exists($lockFile) && (time() - filemtime($lockFile)) < self::LOCK_TTL_SECONDS;
    }

    /**
     * Dispatch a background full-range sync for one variant (basis + criteria).
     * Returns false when a sync is already running.
     */
    private function dispatchVariantSync(string $basis, array $statusIds, array $trustStatusNames): bool
    {
        if ($this->syncInProgress()) {
            return false;
        }

        $lockFile = $this->syncLockFile();
        $statusFile = $this->syncStatusFile();
        $logPath = storage_path('logs/viefund-daily-totals-sync.log');
        $phpPath = env('PHP_PATH', '/usr/local/bin/php');
        $artisanPath = base_path('artisan');

        $schema = env('VIEFUND_DB_SCHEMA', 'dbo');
        $minSettlement = DB::connection('viefund_sqlsrv')
            ->table("{$schema}.UB_CashTrx as ct")
            ->whereNotNull('ct.dtSettlement')
            ->whereNotNull('ct.mAmount')
            ->where('ct.iStatus', '=', self::VIEFUND_CASH_STATUS_CONFIRMED)
            ->whereIn('ct.iType', self::VIEFUND_CASH_TYPES)
            ->min('ct.dtSettlement');

        $from = $minSettlement ? Carbon::parse($minSettlement)->toDateString() : Carbon::today()->toDateString();
        $to = Carbon::today()->toDateString();

        file_put_contents($lockFile, date('c'));
        file_put_contents($statusFile, json_encode([
            'inProgress' => true,
            'mode' => 'full',
            'message' => 'Sync queued...',
            'started_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
            'from' => $from,
            'to' => $to,
            'progress_pct' => 0,
            'processed_days' => 0,
            'total_days' => null,
            'eta_seconds' => null,
            'estimated_finish_at' => null,
        ], JSON_PRETTY_PRINT));

        $statusArgs = implode(' ', array_map(
            fn($id) => '--statuses=' . escapeshellarg((string) $id),
            $statusIds
        ));
        $trustArgs = implode(' ', array_map(
            fn($name) => '--trust-statuses=' . escapeshellarg($name),
            $trustStatusNames
        ));
        $basisArg = '--date-basis=' . escapeshellarg($basis);

        $command = sprintf(
            '%s %s viefund:sync-daily-totals --from=%s --to=%s --sync-mode=full %s %s %s --status-file=%s --lock-file=%s >> %s 2>&1 &',
            escapeshellarg($phpPath),
            escapeshellarg($artisanPath),
            escapeshellarg($from),
            escapeshellarg($to),
            $statusArgs,
            $trustArgs,
            $basisArg,
            escapeshellarg($statusFile),
            escapeshellarg($lockFile),
            escapeshellarg($logPath)
        );

        Log::info('Dispatching background daily totals sync: ' . $command);

        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (is_resource($process)) {
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }

        return true;
    }

    public function syncStatus(): JsonResponse
    {
        $lockFile = storage_path('app/viefund-daily-totals-sync.lock');
        $statusFile = storage_path('app/viefund-daily-totals-sync-status.json');

        $inProgress = file_exists($lockFile) && (time() - filemtime($lockFile)) < self::LOCK_TTL_SECONDS;
        $payload = [
            'inProgress' => $inProgress,
            'mode' => null,
            'message' => $inProgress ? 'Sync in progress...' : 'Idle',
            'progress_pct' => null,
            'processed_days' => null,
            'total_days' => null,
            'eta_seconds' => null,
            'estimated_finish_at' => null,
            'from' => null,
            'to' => null,
            'started_at' => null,
            'updated_at' => null,
            'completed_at' => null,
            'success' => null,
        ];

        if (file_exists($statusFile)) {
            $json = file_get_contents($statusFile);
            $parsed = json_decode($json ?: '{}', true);
            if (is_array($parsed)) {
                $payload = array_merge($payload, $parsed);
                $payload['inProgress'] = $inProgress;
            }
        }

        return response()->json($payload);
    }
}
