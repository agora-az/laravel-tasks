<?php

namespace App\Http\Controllers;

use App\Models\BankStatementEntry;
use App\Models\VieFundCashDailySnapshot;
use App\Models\VieFundDailyTotal;
use App\Services\VieFund\Repositories\SqlServerEftRemoteRepository;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class DailyTotalsComparisonController extends Controller
{
    private const PARSER_VERSION = 'v2';

    private const VIEFUND_CURRENCY_CODE = '00';
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

    public function __construct(
        private readonly SqlServerEftRemoteRepository $eftRepository
    ) {}

    public function index(Request $request): View
    {
        $earliestBankDate = BankStatementEntry::min('value_date');
        $defaultStart = $earliestBankDate
            ? Carbon::parse($earliestBankDate)->toDateString()
            : Carbon::today()->toDateString();

        $dateFrom = $request->filled('date_from') ? Carbon::parse($request->date_from)->toDateString() : $defaultStart;
        $dateTo = $request->filled('date_to') ? Carbon::parse($request->date_to)->toDateString() : Carbon::today()->toDateString();
        $include3000Sequences = $request->has('include_3000_sequences')
            ? $request->boolean('include_3000_sequences')
            : true;
        $perPage = (int) $request->query('per_page', self::DEFAULT_PER_PAGE);
        if (!in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = self::DEFAULT_PER_PAGE;
        }
        $sortField = in_array($request->query('sort'), [
            'total_date',
            'account_number',
            'currency',
            'bank_transaction_count',
            'bank_net_total',
            'settlement_transaction_count',
            'settlement_net_total',
        ], true)
            ? $request->query('sort')
            : 'total_date';
        $sortDir = $request->query('sort_dir') === 'asc' ? 'asc' : 'desc';

        if (!$request->boolean('_results')) {
            return view('reconciliations.daily-totals', compact(
                'dateFrom',
                'dateTo',
                'sortField',
                'sortDir',
                'perPage',
                'include3000Sequences',
            ));
        }

        $bankRows = DB::table('bank_statement_entries')
            ->leftJoin('bank_statement_entry_analyses as a', function ($join) {
                $join->on('a.bank_statement_entry_id', '=', 'bank_statement_entries.id')
                    ->where('a.parser_version', self::PARSER_VERSION);
            })
            ->whereBetween('bank_statement_entries.value_date', [$dateFrom, $dateTo])
            ->selectRaw('bank_statement_entries.value_date as total_date')
            ->selectRaw('bank_statement_entries.account_number')
            ->selectRaw("COALESCE(NULLIF(bank_statement_entries.currency, ''), '—') as currency")
            ->selectRaw('COUNT(*) as bank_transaction_count')
            ->selectRaw("SUM(CASE WHEN bank_statement_entries.credit_debit_indicator = 'DBIT' THEN -bank_statement_entries.amount ELSE bank_statement_entries.amount END) as bank_net_total")
            ->selectRaw("SUM(CASE WHEN a.settlement_number IS NOT NULL AND a.settlement_number <> '' THEN 1 ELSE 0 END) as settlement_transaction_count")
            ->selectRaw("SUM(CASE WHEN a.settlement_number IS NOT NULL AND a.settlement_number <> '' THEN CASE WHEN bank_statement_entries.credit_debit_indicator = 'DBIT' THEN -bank_statement_entries.amount ELSE bank_statement_entries.amount END ELSE 0 END) as settlement_net_total")
            ->groupBy('bank_statement_entries.value_date', 'bank_statement_entries.account_number', 'bank_statement_entries.currency')
            ->get();

        $settlementsByRow = DB::table('bank_statement_entries')
            ->join('bank_statement_entry_analyses as a', function ($join) {
                $join->on('a.bank_statement_entry_id', '=', 'bank_statement_entries.id')
                    ->where('a.parser_version', self::PARSER_VERSION);
            })
            ->whereBetween('bank_statement_entries.value_date', [$dateFrom, $dateTo])
            ->whereNotNull('a.settlement_number')
            ->where('a.settlement_number', '<>', '')
            ->selectRaw('bank_statement_entries.value_date as total_date')
            ->selectRaw('bank_statement_entries.account_number')
            ->selectRaw("COALESCE(NULLIF(bank_statement_entries.currency, ''), '—') as currency")
            ->selectRaw('a.settlement_number')
            ->selectRaw('COUNT(*) as transaction_count')
            ->selectRaw("SUM(CASE WHEN bank_statement_entries.credit_debit_indicator = 'DBIT' THEN -bank_statement_entries.amount ELSE bank_statement_entries.amount END) as net_total")
            ->groupBy('bank_statement_entries.value_date', 'bank_statement_entries.account_number', 'bank_statement_entries.currency', 'a.settlement_number')
            ->get()
            ->groupBy(fn($row) => Carbon::parse($row->total_date)->toDateString() . '|' . ($row->account_number ?? '') . '|' . ($row->currency ?? '—'))
            ->map(fn(Collection $rows) => $rows->keyBy(fn($item) => ctype_digit(trim((string) $item->settlement_number))
                ? (string) (int) trim((string) $item->settlement_number)
                : trim((string) $item->settlement_number)));

        $rows = $bankRows->map(function ($row) use ($settlementsByRow, $include3000Sequences) {
            $date = Carbon::parse($row->total_date)->toDateString();
            $account = (string) ($row->account_number ?? '');
            $currency = (string) ($row->currency ?? '—');
            $settlements = $settlementsByRow->get($date . '|' . $account . '|' . $currency, collect());
            if (!$include3000Sequences) {
                $settlements = $settlements->reject(function ($settlement, $sequence) {
                    return ctype_digit((string) $sequence)
                        && (int) $sequence >= 3000
                        && (int) $sequence < 4000;
                });
            }

            return [
                'total_date' => $date,
                'account_number' => $account,
                'currency' => $currency,
                'bank_transaction_count' => (int) $row->bank_transaction_count,
                'bank_net_total' => (float) $row->bank_net_total,
                'settlement_transaction_count' => $settlements->sum(fn($settlement) => (int) $settlement->transaction_count),
                'settlement_net_total' => $settlements->sum(fn($settlement) => (float) $settlement->net_total),
                'settlement_sequences' => $settlements->keys()->values(),
                'settlements_by_sequence' => $settlements,
            ];
        });

        // This reconciliation is only meaningful for statement date/account
        // rows that contain at least one eligible bank EFT sequence.
        $rows = $rows
            ->filter(fn(array $row) => $row['settlement_sequences']->count() > 0)
            ->values();

        // A settlement can be split across statement dates. The date/account row
        // determines which sequences are eligible, while its Bank EFT Net uses
        // every bank record belonging to those sequences for a like-for-like
        // comparison with the complete VieFund EFT files.
        $allSequences = $rows
            ->flatMap(fn(array $row) => $row['settlement_sequences'])
            ->filter(fn($sequence) => ctype_digit((string) $sequence))
            ->unique()
            ->values();
        $bankBySequence = DB::table('bank_statement_entries')
            ->join('bank_statement_entry_analyses as a', function ($join) {
                $join->on('a.bank_statement_entry_id', '=', 'bank_statement_entries.id')
                    ->where('a.parser_version', self::PARSER_VERSION);
            })
            ->whereIn(
                DB::raw('CAST(a.settlement_number AS UNSIGNED)'),
                $allSequences->map(fn($sequence) => (int) $sequence)->all()
            )
            ->selectRaw('a.settlement_number')
            ->selectRaw("COALESCE(NULLIF(bank_statement_entries.currency, ''), '—') as currency")
            ->selectRaw("SUM(CASE WHEN bank_statement_entries.credit_debit_indicator = 'DBIT' THEN -bank_statement_entries.amount ELSE bank_statement_entries.amount END) as net_total")
            ->groupBy('a.settlement_number', 'bank_statement_entries.currency')
            ->get()
            ->keyBy(fn($item) => (string) (int) $item->settlement_number . '|' . ($item->currency ?? '—'));

        $rows = $rows->map(function (array $row) use ($bankBySequence) {
            $row['settlement_net_total'] = $row['settlement_sequences']->sum(
                fn($sequence) => (float) ($bankBySequence->get((string) $sequence . '|' . $row['currency'])?->net_total ?? 0)
            );

            return $row;
        });

        $rows = $sortDir === 'asc'
            ? $rows->sortBy($sortField)->values()
            : $rows->sortByDesc($sortField)->values();

        $summary = [
            'days' => $rows->pluck('total_date')->unique()->count(),
            'accounts' => $rows->pluck('account_number')->unique()->count(),
            'rows' => $rows->count(),
            'bank_total' => $rows->sum('bank_net_total'),
            'settlement_total' => $rows->sum('settlement_net_total'),
        ];

        $currentPage = max(1, (int) $request->query('page', 1));
        $totalRows = $rows->count();
        $pageRows = $rows->forPage($currentPage, $perPage)->values();
        $pageSequences = $pageRows
            ->flatMap(fn(array $row) => $row['settlement_sequences'])
            ->filter(fn($sequence) => ctype_digit((string) $sequence))
            ->unique()
            ->values();
        $eftBySequence = $this->eftRepository
            ->totalsBySequences($pageSequences->all())
            ->groupBy(fn($row) => (string) (int) $row->sequence_number)
            ->map(fn(Collection $files) => [
                'transaction_count' => $files->sum(fn($file) => (int) $file->transaction_count),
                'net_total' => $files->sum(fn($file) => (float) $file->net_total),
            ]);

        $pageRows = $pageRows->map(function (array $row) use ($eftBySequence) {
            $matchedSequences = $row['settlement_sequences']
                ->filter(fn($sequence) => $eftBySequence->has((string) $sequence));
            $row['eft_transaction_count'] = $matchedSequences
                ->sum(fn($sequence) => (int) data_get($eftBySequence->get((string) $sequence), 'transaction_count', 0));
            $row['eft_net_total'] = $matchedSequences
                ->sum(fn($sequence) => (float) data_get($eftBySequence->get((string) $sequence), 'net_total', 0));
            $row['variance'] = (float) $row['settlement_net_total'] - (float) $row['eft_net_total'];
            $row['matched_sequences'] = $matchedSequences->values();
            $row['matched_sequence_count'] = $matchedSequences->count();
            return $row;
        });

        $rows = new LengthAwarePaginator(
            $pageRows,
            $totalRows,
            $perPage,
            $currentPage,
            [
                'path' => route('reconciliations.daily-totals'),
                'query' => $request->query(),
            ]
        );

        return view('reconciliations.partials.daily-totals-results', compact(
            'rows',
            'summary',
            'dateFrom',
            'dateTo',
            'sortField',
            'sortDir',
            'perPage',
            'include3000Sequences',
        ));
    }

    public function fspIndex(Request $request, string $source = 'agra'): View
    {
        $source = in_array($source, ['agra', '7960'], true) ? $source : 'agra';
        $sourceType = $source === '7960' ? 'ltm' : 'fundserv_agra';
        $sourceLabel = $source === '7960' ? '7960' : 'AGRA';
        $earliestBankDate = BankStatementEntry::min('value_date');
        $dateFrom = $request->filled('date_from')
            ? Carbon::parse($request->date_from)->toDateString()
            : ($earliestBankDate ? Carbon::parse($earliestBankDate)->toDateString() : Carbon::today()->toDateString());
        $dateTo = $request->filled('date_to')
            ? Carbon::parse($request->date_to)->toDateString()
            : Carbon::today()->toDateString();
        $perPage = (int) $request->query('per_page', self::DEFAULT_PER_PAGE);
        if (!in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = self::DEFAULT_PER_PAGE;
        }
        $sortField = in_array($request->query('sort'), [
            'total_date',
            'account_number',
            'currency',
            'bank_transaction_count',
            'bank_net_total',
            'fsp_item_count',
            'fsp_net_total',
            'variance',
        ], true) ? $request->query('sort') : 'total_date';
        $sortDir = $request->query('sort_dir') === 'asc' ? 'asc' : 'desc';

        if (!$request->boolean('_results')) {
            return view('reconciliations.bank-fsp', compact(
                'dateFrom',
                'dateTo',
                'sortField',
                'sortDir',
                'perPage',
                'source',
                'sourceLabel',
            ));
        }

        $bankByDate = DB::table('bank_statement_entries as b')
            ->join('bank_statement_entry_analyses as a', function ($join) {
                $join->on('a.bank_statement_entry_id', '=', 'b.id')
                    ->where('a.parser_version', self::PARSER_VERSION);
            })
            ->whereBetween('b.value_date', [$dateFrom, $dateTo])
            ->where('a.counterparty', 'like', 'fundserv%')
            ->selectRaw('b.value_date as total_date')
            ->selectRaw('b.id as bank_entry_id')
            ->selectRaw('b.account_number')
            ->selectRaw("CASE WHEN b.credit_debit_indicator = 'DBIT' THEN -b.amount ELSE b.amount END as net_total")
            ->get()
            ->groupBy(fn($row) => Carbon::parse($row->total_date)->toDateString());

        $fspByDate = DB::table('settlement_instructions')
            ->whereBetween('settlement_date', [$dateFrom, $dateTo])
            ->where('source_type', $sourceType)
            ->selectRaw('settlement_date as total_date')
            ->selectRaw('currency')
            ->selectRaw('COUNT(*) as item_count')
            ->selectRaw("SUM(CASE WHEN side = 'SELL' THEN COALESCE(settlement_amount, 0) WHEN side = 'BUY' THEN -COALESCE(settlement_amount, 0) ELSE 0 END) as net_total")
            ->groupBy('settlement_date', 'currency')
            ->get()
            ->groupBy(fn($row) => Carbon::parse($row->total_date)->toDateString());

        $rows = $fspByDate->flatMap(function (Collection $fspGroups, string $date) use ($bankByDate) {
            $availableBank = $bankByDate->get($date, collect())->keyBy('bank_entry_id');

            return $fspGroups
                ->sortByDesc(fn($fsp) => abs((float) $fsp->net_total))
                ->map(function ($fsp) use (&$availableBank, $date) {
                    $fspNet = (float) $fsp->net_total;
                    $bank = $availableBank
                        ->sortBy(fn($candidate) => abs((float) $candidate->net_total - $fspNet))
                        ->first();
                    if ($bank) {
                        $availableBank->forget($bank->bank_entry_id);
                    }
                    $bankNet = (float) ($bank?->net_total ?? 0);

                    return [
                        'total_date' => $date,
                        'bank_entry_id' => $bank?->bank_entry_id ? (int) $bank->bank_entry_id : null,
                        'account_number' => (string) ($bank?->account_number ?? ''),
                        'currency' => (string) ($fsp->currency ?? ''),
                        'bank_transaction_count' => $bank ? 1 : 0,
                        'bank_net_total' => $bankNet,
                        'fsp_item_count' => (int) $fsp->item_count,
                        'fsp_net_total' => $fspNet,
                        'variance' => $bankNet - $fspNet,
                    ];
                });
        });

        $rows = ($sortDir === 'asc' ? $rows->sortBy($sortField) : $rows->sortByDesc($sortField))->values();
        $summary = [
            'days' => $rows->pluck('total_date')->unique()->count(),
            'accounts' => $rows->pluck('account_number')->filter()->unique()->count(),
            'bank_transaction_count' => $rows->sum('bank_transaction_count'),
            'bank_net_total' => $rows->sum('bank_net_total'),
            'fsp_item_count' => $rows->sum('fsp_item_count'),
            'fsp_net_total' => $rows->sum('fsp_net_total'),
            'variance' => $rows->sum('variance'),
        ];

        $currentPage = max(1, (int) $request->query('page', 1));
        $rows = new LengthAwarePaginator(
            $rows->forPage($currentPage, $perPage)->values(),
            $rows->count(),
            $perPage,
            $currentPage,
            ['path' => route('reconciliations.bank-fsp.source', ['source' => $source]), 'query' => $request->query()]
        );

        return view('reconciliations.partials.bank-fsp-results', compact(
            'dateFrom',
            'dateTo',
            'rows',
            'summary',
            'sortField',
            'sortDir',
            'perPage',
            'source',
            'sourceLabel',
        ));
    }

    /**
    * Resolve the direct-cash snapshot criteria used by both Daily Totals and
    * the VieFund Daily Net + Running Balance report.
     *
    * @return array{0: string, 1: int[]}
     */
    private function resolveSelection(Request $request): array
    {
        $allowedBasis = array_keys(VieFundDailyTotal::DATE_BASIS_OPTIONS);
        $defaultBasis = (string) config('viefund.default_date_basis', 'settlement_date');

        if ($request->has('date_basis') || $request->has('statuses')) {
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

            return [$basis, $statuses];
        }

        return [
            $defaultBasis,
            (array) config('viefund.default_fund_status', [6]),
        ];
    }

    public function sync(Request $request): RedirectResponse
    {
        $request->validate([
            'statuses' => ['sometimes', 'array'],
            'statuses.*' => ['integer', 'between:0,6'],
            'date_basis' => ['sometimes', 'in:' . implode(',', array_keys(VieFundDailyTotal::DATE_BASIS_OPTIONS))],
        ]);

        $statusIds = array_values(array_unique(array_map('intval', (array) $request->input('statuses', []))));
        $statusIds = array_values(array_filter($statusIds, fn($id) => array_key_exists($id, self::FUND_STATUS_OPTIONS)));
        if (empty($statusIds)) {
            $statusIds = (array) config('viefund.default_fund_status', [6]);
        }
        $allowedBasis = array_keys(VieFundDailyTotal::DATE_BASIS_OPTIONS);
        $basis = in_array($request->input('date_basis'), $allowedBasis, true)
            ? $request->input('date_basis')
            : (string) config('viefund.default_date_basis', 'settlement_date');

        // Preserve the selected variant on redirect so the page shows it while the
        // background sync populates it.
        $selectionParams = [
            'date_basis' => $basis,
            'statuses' => $statusIds,
        ];

        $dispatched = $this->dispatchVariantSync($basis, $statusIds);

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
        return storage_path('app/viefund-cash-daily-totals-sync.lock');
    }

    private function syncStatusFile(): string
    {
        return storage_path('app/viefund-cash-daily-totals-sync-status.json');
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
    private function dispatchVariantSync(string $basis, array $statusIds): bool
    {
        if ($this->syncInProgress()) {
            return false;
        }

        $lockFile = $this->syncLockFile();
        $statusFile = $this->syncStatusFile();
        $logPath = storage_path('logs/viefund-cash-daily-totals-sync.log');
        $phpPath = env('PHP_PATH', '/usr/local/bin/php');
        $artisanPath = base_path('artisan');

        $to = Carbon::today()->toDateString();

        file_put_contents($lockFile, date('c'));
        file_put_contents($statusFile, json_encode([
            'inProgress' => true,
            'mode' => 'full',
            'message' => 'Sync queued...',
            'started_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
            'from' => null,
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
        $basisArg = '--date-basis=' . escapeshellarg($basis);

        $command = sprintf(
            '%s %s viefund:sync-cash-daily-snapshots --full --to=%s --currency=%s %s %s --status-file=%s --lock-file=%s >> %s 2>&1 &',
            escapeshellarg($phpPath),
            escapeshellarg($artisanPath),
            escapeshellarg($to),
            escapeshellarg(self::VIEFUND_CURRENCY_CODE),
            $statusArgs,
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
        $lockFile = $this->syncLockFile();
        $statusFile = $this->syncStatusFile();

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
