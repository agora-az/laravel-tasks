<?php

namespace App\Http\Controllers;

use App\Exports\BankStatementEntriesWorkbookExport;
use App\Exports\VieFundReportSheetExport;
use App\Models\BankStatementEntry;
use App\Models\BankStatementSummary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BankStatementEntryController extends Controller
{
    private const PARSER_VERSION = 'v2';
    private const LOCK_TTL_SECONDS = 14400;
    private const MEMO_TYPE_GROUPS = [
        'instant_teller_deposit' => [
            'label' => 'Instant Teller Deposit',
            'patterns' => ['^INSTANT[[:space:]]+TELLER[[:space:]]+DEPOSIT'],
        ],
        'debit_memo' => [
            'label' => 'Debit Memo',
            'patterns' => ['^DEBIT[[:space:]]+MEMO'],
        ],
        'credit_memo' => [
            'label' => 'Credit Memo',
            'patterns' => ['^CREDIT[[:space:]]+MEMO'],
        ],
        'wire_payment' => [
            'label' => 'Wire Payment',
            'patterns' => ['^WIRE[[:space:]]+PAYMENT'],
        ],
        'wire_tsf' => [
            'label' => 'Wire TSF',
            'patterns' => ['^WIRE[[:space:]]+TSF'],
        ],
        'cmo_transfer' => [
            'label' => 'CMO Transfer',
            'patterns' => ['^CMO[[:space:]]+TRANSFER'],
        ],
        'eft_returned_item' => [
            'label' => 'EFT Returned Item',
            'patterns' => ['^EFT[[:space:]]+RETURNED[[:space:]]+ITEM'],
        ],
        'misc_payment' => [
            'label' => 'Miscellaneous Payment',
            'patterns' => ['^MISCELLANEOUS[[:space:]]+PAYMENT'],
        ],
        'cheque' => [
            'label' => 'Cheque / Returned Cheque',
            'patterns' => ['^CHEQUE', '^RETURNED[[:space:]]+CHEQUE'],
        ],
        'deposit' => [
            'label' => 'Deposit',
            'patterns' => ['^DEPOSIT'],
        ],
        'fees_and_charges' => [
            'label' => 'Fees and Charges',
            'patterns' => ['(^|[[:space:]])(FEE|SERVICE[[:space:]]+CHARGE|INSURANCE)'],
        ],
    ];

    public function index(Request $request)
    {
        $query = $this->baseEntriesQuery();
        $this->applyFilters($query, $request);
        [$sortField, $sortDir] = $this->resolveSort($request);
        $this->applySorting($query, $sortField, $sortDir);

        $entries = $query->paginate(50)
            ->appends(array_merge($request->except('page'), ['view' => 'transactions']));

        // Summary totals (respecting filters but not pagination)
        $totalsQuery = BankStatementEntry::query()
            ->leftJoin(
                'bank_statement_entry_analyses as a',
                function ($join) {
                    $join->on('a.bank_statement_entry_id', '=', 'bank_statement_entries.id')
                        ->where('a.parser_version', self::PARSER_VERSION);
                }
            );

        $this->applyFilters($totalsQuery, $request);

        $totalCount = (clone $totalsQuery)->count();
        $currencyTotals = $totalsQuery
            ->selectRaw(
                'bank_statement_entries.currency,
                 sum(case when bank_statement_entries.credit_debit_indicator = "CRDT" then bank_statement_entries.amount else 0 end) as total_credits,
                 sum(case when bank_statement_entries.credit_debit_indicator = "DBIT" then bank_statement_entries.amount else 0 end) as total_debits'
            )
            ->groupBy('bank_statement_entries.currency')
            ->orderBy('bank_statement_entries.currency')
            ->get();

        $totals = (object) [
            'total_count' => $totalCount,
            'currency_totals' => $currencyTotals,
        ];

        $summaryIdQuery = BankStatementEntry::query()
            ->leftJoin(
                'bank_statement_entry_analyses as a',
                function ($join) {
                    $join->on('a.bank_statement_entry_id', '=', 'bank_statement_entries.id')
                        ->where('a.parser_version', self::PARSER_VERSION);
                }
            );
        $this->applyFilters($summaryIdQuery, $request);

        $summaryIds = $summaryIdQuery
            ->whereNotNull('bank_statement_entries.bank_statement_summary_id')
            ->select('bank_statement_entries.bank_statement_summary_id')
            ->distinct()
            ->pluck('bank_statement_entries.bank_statement_summary_id');

        $statementSummaryTotals = BankStatementSummary::query()
            ->whereIn('id', $summaryIds)
            ->selectRaw(
                'count(*) as statement_count,
                 count(distinct account_number) as account_count,
                 min(statement_created_at) as oldest_statement_created_at,
                 max(statement_created_at) as newest_statement_created_at,
                 sum(total_credit_sum) as summed_credit_total,
                 sum(total_debit_sum) as summed_debit_total'
            )
            ->first();

        $statementSummaries = BankStatementSummary::query()
            ->whereIn('id', $summaryIds)
            ->orderByDesc('statement_created_at')
            ->orderByDesc('id')
            ->paginate(50, ['*'], 'summary_page')
            ->appends(array_merge($request->except('summary_page'), ['view' => 'summaries']));

        // Filter options
        $channels = DB::table('bank_statement_entry_analyses')
            ->where('parser_version', self::PARSER_VERSION)
            ->whereNotNull('inferred_channel')
            ->distinct()
            ->orderBy('inferred_channel')
            ->pluck('inferred_channel');

        $currencies = BankStatementEntry::query()
            ->whereNotNull('currency')
            ->where('currency', '<>', '')
            ->distinct()
            ->orderBy('currency')
            ->pluck('currency');

        $rawMemoTypes = DB::table('bank_statement_entry_analyses')
            ->where('parser_version', self::PARSER_VERSION)
            ->whereNotNull('memo_type')
            ->selectRaw('memo_type, count(*) as c')
            ->groupBy('memo_type')
            ->orderByDesc('c')
            ->get();

        $memoTypes = $this->buildMemoTypeFilterOptions($rawMemoTypes);

        return view('bank-entries.index', compact(
            'entries',
            'totals',
            'statementSummaryTotals',
            'statementSummaries',
            'channels',
            'currencies',
            'memoTypes',
        ));
    }

    public function export(Request $request): BinaryFileResponse|StreamedResponse
    {
        $format = strtolower((string) $request->query('format', 'csv'));
        if (!in_array($format, ['csv', 'excel'], true)) {
            abort(422, 'Invalid export format.');
        }

        $query = $this->baseEntriesQuery();
        $this->applyFilters($query, $request);
        [$sortField, $sortDir] = $this->resolveSort($request);
        $this->applySorting($query, $sortField, $sortDir);

        $entries = $query->get();

        $summaryIds = $entries->pluck('bank_statement_summary_id')
            ->filter(fn($id) => $id !== null)
            ->unique()
            ->values();

        $summaries = collect();
        if ($summaryIds->isNotEmpty()) {
            $summaries = BankStatementSummary::query()
                ->whereIn('id', $summaryIds)
                ->orderByDesc('statement_created_at')
                ->orderByDesc('id')
                ->get();
        }
        $summariesById = $summaries->keyBy('id');

        $headers = [
            'Date',
            'Channel',
            'Direction',
            'Amount',
            'Memo Type',
            'Counterparty',
            'Settlement #',
            'Wire Ref',
            'Source File',
        ];

        if ($format === 'csv') {
            $headers = array_merge($headers, [
                'Statement Date',
                'Statement Created At',
                'Statement Account',
                'Statement ID',
                'Statement Currency',
                'Opening Balance',
                'Closing Balance',
                'Statement Credit Entries',
                'Statement Credit Sum',
                'Statement Debit Entries',
                'Statement Debit Sum',
            ]);
        }

        $rows = [$headers];
        foreach ($entries as $entry) {
            $isCredit = $entry->credit_debit_indicator === 'CRDT';
            $summary = $summariesById->get($entry->bank_statement_summary_id);
            $statementDate = $summary?->closing_balance_date ?? $summary?->opening_balance_date;
            $statementCreatedAt = $summary?->statement_created_at ?? $summary?->group_created_at;
            $statementCurrency = (string) ($summary?->closing_balance_currency ?: ($summary?->opening_balance_currency ?: ''));

            $row = [
                (string) $entry->value_date,
                (string) ($entry->inferred_channel ?? ''),
                (string) $entry->credit_debit_indicator,
                $isCredit ? (float) $entry->amount : (float) $entry->amount * -1,
                (string) ($entry->memo_type ?? ''),
                (string) ($entry->counterparty ?? ''),
                (string) ($entry->settlement_number ?? ''),
                (string) ($entry->wire_payment_reference ?? ''),
                (string) $entry->source_file,
            ];

            if ($format === 'csv') {
                $row = array_merge($row, [
                    $statementDate?->format('Y-m-d') ?? '',
                    $statementCreatedAt?->format('Y-m-d H:i:s') ?? '',
                    (string) ($summary?->account_number ?? ''),
                    (string) ($summary?->statement_id ?? ''),
                    $statementCurrency,
                    $summary?->opening_balance_signed_amount !== null ? (float) $summary->opening_balance_signed_amount : null,
                    $summary?->closing_balance_signed_amount !== null ? (float) $summary->closing_balance_signed_amount : null,
                    $summary?->total_credit_entries !== null ? (int) $summary->total_credit_entries : null,
                    $summary?->total_credit_sum !== null ? (float) $summary->total_credit_sum : null,
                    $summary?->total_debit_entries !== null ? (int) $summary->total_debit_entries : null,
                    $summary?->total_debit_sum !== null ? (float) $summary->total_debit_sum : null,
                ]);
            }

            $rows[] = $row;
        }

        $filename = sprintf(
            'bank_statement_entries_%s.%s',
            now()->format('Ymd_His'),
            $format === 'excel' ? 'xlsx' : 'csv'
        );

        if ($format === 'excel') {
            $summaryHeaders = [
                'Statement Date',
                'Statement Created At',
                'Account',
                'Statement ID',
                'Currency',
                'Opening Balance',
                'Closing Balance',
                'Credit Entries',
                'Credit Sum',
                'Debit Entries',
                'Debit Sum',
                'Source File',
            ];
            $summaryRows = [$summaryHeaders];

            foreach ($summaries as $summary) {
                $statementDate = $summary->closing_balance_date ?? $summary->opening_balance_date;
                $statementCreatedAt = $summary->statement_created_at ?? $summary->group_created_at;
                $currency = (string) ($summary->closing_balance_currency ?: ($summary->opening_balance_currency ?: ''));

                $summaryRows[] = [
                    $statementDate?->format('Y-m-d') ?? '',
                    $statementCreatedAt?->format('Y-m-d H:i:s') ?? '',
                    (string) ($summary->account_number ?? ''),
                    (string) ($summary->statement_id ?? ''),
                    $currency,
                    $summary->opening_balance_signed_amount !== null ? (float) $summary->opening_balance_signed_amount : null,
                    $summary->closing_balance_signed_amount !== null ? (float) $summary->closing_balance_signed_amount : null,
                    $summary->total_credit_entries !== null ? (int) $summary->total_credit_entries : null,
                    $summary->total_credit_sum !== null ? (float) $summary->total_credit_sum : null,
                    $summary->total_debit_entries !== null ? (int) $summary->total_debit_entries : null,
                    $summary->total_debit_sum !== null ? (float) $summary->total_debit_sum : null,
                    (string) $summary->source_file,
                ];
            }

            return Excel::download(
                new BankStatementEntriesWorkbookExport($rows, $summaryRows),
                $filename,
                ExcelWriter::XLSX
            );
        }

        return Excel::download(
            new VieFundReportSheetExport($rows, 'Bank Entries'),
            $filename,
            ExcelWriter::CSV,
            [
                'Content-Type' => 'text/csv',
            ]
        );
    }

    public function sync(Request $request): RedirectResponse
    {
        $artisanPath = base_path('artisan');
        $lockFile = storage_path('app/bank-entries-sync.lock');
        $statusFile = storage_path('app/bank-entries-sync-status.json');
        $logPath = storage_path('logs/bank-entries-sync.log');
        $phpPath = env('PHP_PATH', '/usr/local/bin/php');
        $dryRun = $this->resolveBooleanEnv('BANK_SFTP_DRY_RUN', false);

        $hasLiveLock = file_exists($lockFile) && (time() - filemtime($lockFile)) < self::LOCK_TTL_SECONDS;
        if ($hasLiveLock) {
            return redirect()
                ->route('bank-entries.index', $request->except('_token'))
                ->with('sync_error', 'A bank entries sync is already in progress.');
        }

        // Create lock immediately so UI reflects in-progress state without delay.
        file_put_contents($lockFile, date('c'));
        file_put_contents($statusFile, json_encode([
            'inProgress' => true,
            'success' => null,
            'dry_run' => $dryRun,
            'message' => $dryRun ? 'Bank entries dry run queued...' : 'Bank entries sync queued...',
            'processed_files' => 0,
            'total_files' => null,
            'progress_pct' => 0,
            'started_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT));

        $extraArgs = $dryRun ? ' --dry-run' : '';

        $command = sprintf(
            '%s %s bank:sync-entries --parser=%s --lock-file=%s --status-file=%s%s >> %s 2>&1 &',
            escapeshellarg($phpPath),
            escapeshellarg($artisanPath),
            escapeshellarg(self::PARSER_VERSION),
            escapeshellarg($lockFile),
            escapeshellarg($statusFile),
            $extraArgs,
            escapeshellarg($logPath)
        );

        Log::info('Dispatching bank:sync-entries in background: ' . $command);

        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptorspec, $pipes);
        if (is_resource($process)) {
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }

        return redirect()
            ->route('bank-entries.index', $request->except('_token'))
            ->with('sync_success', $dryRun
                ? 'Bank entries dry run started. A preview will be generated without downloading or importing files.'
                : 'Bank entries sync started. Files will be downloaded from SFTP and processed in the background.');
    }

    public function syncStatus(): JsonResponse
    {
        $lockFile = storage_path('app/bank-entries-sync.lock');
        $statusFile = storage_path('app/bank-entries-sync-status.json');

        $inProgress = file_exists($lockFile) && (time() - filemtime($lockFile)) < self::LOCK_TTL_SECONDS;

        $payload = [
            'inProgress' => $inProgress,
            'success' => null,
            'message' => $inProgress ? 'Bank entries sync in progress...' : 'Idle',
            'processed_files' => null,
            'total_files' => null,
            'progress_pct' => null,
            'started_at' => null,
            'updated_at' => null,
            'completed_at' => null,
        ];

        $parsed = null;
        if (file_exists($statusFile)) {
            $json = file_get_contents($statusFile);
            $parsed = json_decode($json ?: '{}', true);
            if (is_array($parsed)) {
                $payload = array_merge($payload, $parsed);
                $payload['inProgress'] = $inProgress;
            }
        }

        $staleInProgress = !$inProgress
            && is_array($parsed)
            && (($parsed['inProgress'] ?? false) === true)
            && (($parsed['success'] ?? null) === null);

        if ($staleInProgress) {
            $payload['success'] = false;
            $payload['message'] = 'Bank sync stopped before reporting completion. Check the sync log and retry.';
            $payload['completed_at'] = $payload['completed_at'] ?? now()->toIso8601String();
        }

        return response()->json($payload);
    }

    private function resolveBooleanEnv(string $envKey, bool $default = false): bool
    {
        $envValue = env($envKey);
        if ($envValue === null || $envValue === '') {
            return $default;
        }

        if (is_bool($envValue)) {
            return $envValue;
        }

        if (is_int($envValue)) {
            return $envValue !== 0;
        }

        if (is_string($envValue)) {
            $normalized = strtolower(trim($envValue));
            return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
        }

        return $default;
    }

    private function baseEntriesQuery(): Builder
    {
        return BankStatementEntry::query()
            ->leftJoin(
                'bank_statement_entry_analyses as a',
                function ($join) {
                    $join->on('a.bank_statement_entry_id', '=', 'bank_statement_entries.id')
                        ->where('a.parser_version', self::PARSER_VERSION);
                }
            )
            ->select([
                'bank_statement_entries.id',
                'bank_statement_entries.bank_statement_summary_id',
                'bank_statement_entries.source_file',
                'bank_statement_entries.account_number',
                'bank_statement_entries.value_date',
                'bank_statement_entries.credit_debit_indicator',
                'bank_statement_entries.currency',
                'bank_statement_entries.amount',
                'bank_statement_entries.additional_info',
                'a.memo_type',
                'a.settlement_number',
                'a.wire_payment_reference',
                'a.counterparty',
                'a.inferred_channel',
                'a.confidence',
            ]);
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        if ($request->filled('date_from')) {
            $query->where('bank_statement_entries.value_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('bank_statement_entries.value_date', '<=', $request->date_to);
        }
        if ($request->filled('channel')) {
            $channels = array_values(array_unique(array_filter(
                array_map('trim', (array) $request->input('channel')),
                fn($channel) => $channel !== ''
            )));
            $query->whereIn('a.inferred_channel', $channels);
        }
        if ($request->filled('direction')) {
            $query->where('bank_statement_entries.credit_debit_indicator', $request->direction);
        }
        if ($request->filled('currency')) {
            $query->where('bank_statement_entries.currency', trim((string) $request->input('currency')));
        }
        if ($request->filled('memo_type')) {
            $memoTypes = array_values(array_unique(array_filter(
                array_map('trim', (array) $request->input('memo_type')),
                fn($memoType) => $memoType !== ''
            )));

            $query->where(function ($memoQuery) use ($memoTypes) {
                $hasValidMemoType = false;

                foreach ($memoTypes as $memoType) {
                    if (str_starts_with($memoType, 'group:')) {
                        $groupKey = substr($memoType, 6);
                        $patterns = self::MEMO_TYPE_GROUPS[$groupKey]['patterns'] ?? [];

                        foreach ($patterns as $pattern) {
                            $memoQuery->orWhereRaw('UPPER(a.memo_type) REGEXP ?', [$pattern]);
                            $hasValidMemoType = true;
                        }

                        continue;
                    }

                    $memoQuery->orWhere('a.memo_type', $memoType);
                    $hasValidMemoType = true;
                }

                if (!$hasValidMemoType) {
                    $memoQuery->whereRaw('1 = 0');
                }
            });
        }
        if ($request->filled('search')) {
            $term = (string) $request->search;
            $query->where(function ($nested) use ($term) {
                $nested->where('bank_statement_entries.additional_info', 'like', '%' . $term . '%')
                    ->orWhere('a.counterparty', 'like', '%' . $term . '%')
                    ->orWhere('a.settlement_number', 'like', '%' . $term . '%')
                    ->orWhere('a.wire_payment_reference', 'like', '%' . $term . '%');
            });
        }
    }

    /**
     * @return array{0:string,1:string}
     */
    private function resolveSort(Request $request): array
    {
        $sortField = in_array($request->sort, ['value_date', 'account_number', 'amount', 'inferred_channel', 'memo_type', 'counterparty'], true)
            ? $request->sort
            : 'value_date';

        $sortDir = $request->sort_dir === 'asc' ? 'asc' : 'desc';

        return [$sortField, $sortDir];
    }

    private function applySorting(Builder $query, string $sortField, string $sortDir): void
    {
        $columnMap = [
            'value_date' => 'bank_statement_entries.value_date',
            'account_number' => 'bank_statement_entries.account_number',
            'amount' => 'bank_statement_entries.amount',
            'inferred_channel' => 'a.inferred_channel',
            'memo_type' => 'a.memo_type',
            'counterparty' => 'a.counterparty',
        ];

        $query->orderBy($columnMap[$sortField], $sortDir)
            ->orderBy('bank_statement_entries.id');
    }

    /**
     * @param Collection<int, object{memo_type:string,c:int|string}> $rawMemoTypes
     * @return array<int, array{value:string,label:string,count:int}>
     */
    private function buildMemoTypeFilterOptions(Collection $rawMemoTypes): array
    {
        $groupedCounts = [];
        $ungrouped = [];

        foreach ($rawMemoTypes as $row) {
            $memoType = trim((string) ($row->memo_type ?? ''));
            $count = (int) ($row->c ?? 0);
            if ($memoType === '') {
                continue;
            }

            $groupKey = $this->detectMemoTypeGroup($memoType);
            if ($groupKey !== null) {
                $groupedCounts[$groupKey] = ($groupedCounts[$groupKey] ?? 0) + $count;
                continue;
            }

            $ungrouped[] = [
                'value' => $memoType,
                'label' => $memoType,
                'count' => $count,
            ];
        }

        $options = [];
        foreach (self::MEMO_TYPE_GROUPS as $groupKey => $groupDef) {
            $groupCount = $groupedCounts[$groupKey] ?? 0;
            if ($groupCount <= 0) {
                continue;
            }

            $options[] = [
                'value' => 'group:' . $groupKey,
                'label' => (string) $groupDef['label'],
                'count' => $groupCount,
            ];
        }

        usort($options, fn($a, $b) => $b['count'] <=> $a['count']);
        usort($ungrouped, fn($a, $b) => $b['count'] <=> $a['count']);

        return array_merge($options, $ungrouped);
    }

    private function detectMemoTypeGroup(string $memoType): ?string
    {
        $memoType = strtoupper($memoType);

        foreach (self::MEMO_TYPE_GROUPS as $groupKey => $groupDef) {
            foreach ($groupDef['patterns'] as $pattern) {
                if (preg_match('/' . str_replace('/', '\\/', $pattern) . '/i', $memoType) === 1) {
                    return $groupKey;
                }
            }
        }

        return null;
    }
}
