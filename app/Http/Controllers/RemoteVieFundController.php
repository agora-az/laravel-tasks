<?php

namespace App\Http\Controllers;

use App\Exports\VieFundReportSheetExport;
use App\Models\RemoteVieFundCustomerTransaction;
use App\Services\VieFund\VieFundRemoteService;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\View\View;

class RemoteVieFundController extends Controller
{
    private const ALL_TRANSACTIONS_EXPORT_LOCK_TTL_SECONDS = 14400;
    private const ALL_TRANSACTION_DATE_BASES = [
        'create_date' => 'Created date',
        'trade_date' => 'Trade date',
        'processing_date' => 'Processing date',
        'settlement_date' => 'Settlement date',
    ];
    private const ALL_TRANSACTION_INCEPTION_ENV_KEYS = [
        'create_date' => 'VIEFUND_REPORT_INCEPTION_CREATE_DATE',
        'trade_date' => 'VIEFUND_REPORT_INCEPTION_TRADE_DATE',
        'processing_date' => 'VIEFUND_REPORT_INCEPTION_PROCESSING_DATE',
        'settlement_date' => 'VIEFUND_REPORT_INCEPTION_SETTLEMENT_DATE',
    ];
    private const ALL_TRANSACTION_OUTPUT_ORDERS = [
        'asc' => 'Earliest first',
        'desc' => 'Latest first',
    ];
    private const ALL_TRANSACTION_CURRENCIES = [
        '00' => 'CAD',
        '01' => 'USD',
    ];
    private const ALL_TRANSACTION_STATUSES = [
        0 => 'Deleted',
        1 => 'Rejected',
        2 => 'Cancelled',
        3 => 'Pending',
        4 => 'Accepted',
        5 => 'Contracted',
        6 => 'Confirmed',
    ];

    public function __construct(
        private readonly VieFundRemoteService $remoteService
    ) {}

    public function allTransactions(Request $request): View
    {
        $defaultFrom = Carbon::today()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $defaultTo = Carbon::today()->subMonthNoOverflow()->endOfMonth()->toDateString();
        $search = trim((string) $request->query('search', ''));
        $trxTypesSelected = array_values(array_filter((array) $request->query('filter_trx_type', [])));
        $dateBasis = (string) $request->query('filter_date_basis', 'settlement_date');
        $dateBasis = array_key_exists($dateBasis, self::ALL_TRANSACTION_DATE_BASES) ? $dateBasis : 'settlement_date';
        $outputOrder = (string) $request->query('filter_output_order', 'desc');
        $outputOrder = array_key_exists($outputOrder, self::ALL_TRANSACTION_OUTPUT_ORDERS) ? $outputOrder : 'desc';
        $currencyCode = (string) $request->query('filter_currency_code', '00');
        $currencyCode = array_key_exists($currencyCode, self::ALL_TRANSACTION_CURRENCIES) ? $currencyCode : '00';
        $statusIds = array_values(array_unique(array_filter(
            array_map('intval', (array) $request->query('filter_status', [6])),
            fn($status) => array_key_exists($status, self::ALL_TRANSACTION_STATUSES)
        )));
        $statusIds = $statusIds ?: [6];
        $filters = array_filter([
            'customer_name' => trim((string) $request->query('filter_customer_name', '')),
            'plan_account_id' => trim((string) $request->query('filter_plan_account_id', '')),
            'trx_id' => trim((string) $request->query('filter_trx_id', '')),
            'source_id' => trim((string) $request->query('filter_source_id', '')),
            'date_from' => trim((string) $request->query('filter_date_from', $request->query('filter_created_from', $defaultFrom))),
            'date_to' => trim((string) $request->query('filter_date_to', $request->query('filter_created_to', $defaultTo))),
            'date_basis' => $dateBasis,
            'output_order' => $outputOrder,
            'currency_code' => $currencyCode,
            'status_ids' => $statusIds,
            'trx_type' => $trxTypesSelected ?: null,
        ]);
        $perPage = in_array((int) $request->query('per_page', 100), [50, 100, 250], true)
            ? (int) $request->query('per_page', 100)
            : 100;
        $connectionError = null;
        $transactions = null;
        $availableTrxTypes = [];

        try {
            $transactions = $this->remoteService->fetchAllTransactions(
                $perPage,
                max(1, (int) $request->query('page', 1)),
                $search ?: null,
                $filters
            );
            $availableTrxTypes = $this->remoteService->fetchDistinctTrxTypes();
        } catch (Exception $e) {
            Log::error('Unable to load the complete VieFund transaction ledger.', ['exception' => $e]);
            $connectionError = 'Could not connect to the remote VieFund database: ' . $e->getMessage();
        }

        $dateBasisOptions = self::ALL_TRANSACTION_DATE_BASES;
        $outputOrderOptions = self::ALL_TRANSACTION_OUTPUT_ORDERS;
        $currencyOptions = self::ALL_TRANSACTION_CURRENCIES;
        $statusOptions = self::ALL_TRANSACTION_STATUSES;
        $inceptionDates = [];
        foreach (array_keys(self::ALL_TRANSACTION_DATE_BASES) as $basis) {
            $inceptionDates[$basis] = $this->resolveAllTransactionInceptionDate($basis);
        }

        return view('viefund-transactions.index', compact(
            'transactions',
            'connectionError',
            'perPage',
            'search',
            'filters',
            'availableTrxTypes',
            'dateBasis',
            'outputOrder',
            'currencyCode',
            'statusIds',
            'dateBasisOptions',
            'outputOrderOptions',
            'currencyOptions',
            'statusOptions',
            'inceptionDates'
        ));
    }

    public function startAllTransactionsExport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'filter_customer_name' => ['nullable', 'string', 'max:255'],
            'filter_plan_account_id' => ['nullable', 'string', 'max:100'],
            'filter_trx_id' => ['nullable', 'string', 'max:500'],
            'filter_source_id' => ['nullable', 'string', 'max:500'],
            'filter_date_from' => ['required', 'date'],
            'filter_date_to' => ['required', 'date', 'after_or_equal:filter_date_from'],
            'filter_date_basis' => ['required', 'in:' . implode(',', array_keys(self::ALL_TRANSACTION_DATE_BASES))],
            'filter_output_order' => ['required', 'in:' . implode(',', array_keys(self::ALL_TRANSACTION_OUTPUT_ORDERS))],
            'filter_currency_code' => ['required', 'in:' . implode(',', array_keys(self::ALL_TRANSACTION_CURRENCIES))],
            'filter_status' => ['sometimes', 'array'],
            'filter_status.*' => ['integer', 'between:0,6'],
            'filter_trx_type' => ['sometimes', 'array'],
            'filter_trx_type.*' => ['string', 'max:255'],
            'split_sheets' => ['sometimes', 'boolean'],
        ]);

        $userId = (int) auth()->id();
        $runId = (string) Str::uuid();
        $reportsDirectory = storage_path('app/reports');
        if (!is_dir($reportsDirectory)) {
            @mkdir($reportsDirectory, 0775, true);
        }

        $lockFile = $reportsDirectory . "/viefund-all-transactions-{$userId}.lock";
        $statusFile = $this->allTransactionsStatusFile($userId, $runId);
        $hasLiveLock = file_exists($lockFile)
            && (time() - filemtime($lockFile)) < self::ALL_TRANSACTIONS_EXPORT_LOCK_TTL_SECONDS;
        if ($hasLiveLock) {
            return response()->json([
                'success' => false,
                'message' => 'Your previous All Transactions export is still running.',
            ], 409);
        }

        $outputBase = sprintf(
            'reports/viefund_all_transactions_%d_%s_%s',
            $userId,
            now()->format('Ymd_His'),
            substr(str_replace('-', '', $runId), 0, 8)
        );
        file_put_contents($lockFile, json_encode([
            'run_id' => $runId,
            'user_id' => $userId,
            'started_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT));
        file_put_contents($statusFile, json_encode([
            'run_id' => $runId,
            'inProgress' => true,
            'success' => null,
            'message' => 'All Transactions export queued...',
            'progress_pct' => 0,
            'started_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT));

        $repeatArgs = static function (string $option, array $values): string {
            return implode(' ', array_map(
                static fn($value): string => $option . '=' . escapeshellarg((string) $value),
                $values
            ));
        };
        $transactionTypeArgs = $repeatArgs('--transaction-type', (array) ($validated['filter_trx_type'] ?? []));
        $statusIds = array_values(array_unique(array_map('intval', (array) ($validated['filter_status'] ?? [6]))));
        $statusArgs = $repeatArgs('--status', $statusIds ?: [6]);
        $splitSheetsArg = $request->boolean('split_sheets') ? '--split-sheets' : '';
        $phpPath = env('PHP_PATH', PHP_BINARY);
        $logPath = storage_path('logs/viefund-all-transactions-export.log');

        $command = sprintf(
            '%s %s report:viefund-all-transactions --run-id=%s --search=%s --customer-name=%s --plan-account-id=%s --transaction-id=%s --source-id=%s --date-from=%s --date-to=%s --date-basis=%s --output-order=%s --currency-code=%s %s %s %s --output-base=%s --status-file=%s --lock-file=%s >> %s 2>&1 &',
            escapeshellarg($phpPath),
            escapeshellarg(base_path('artisan')),
            escapeshellarg($runId),
            escapeshellarg(trim((string) ($validated['search'] ?? ''))),
            escapeshellarg(trim((string) ($validated['filter_customer_name'] ?? ''))),
            escapeshellarg(trim((string) ($validated['filter_plan_account_id'] ?? ''))),
            escapeshellarg(trim((string) ($validated['filter_trx_id'] ?? ''))),
            escapeshellarg(trim((string) ($validated['filter_source_id'] ?? ''))),
            escapeshellarg(trim((string) ($validated['filter_date_from'] ?? ''))),
            escapeshellarg(trim((string) ($validated['filter_date_to'] ?? ''))),
            escapeshellarg((string) $validated['filter_date_basis']),
            escapeshellarg((string) $validated['filter_output_order']),
            escapeshellarg((string) $validated['filter_currency_code']),
            $transactionTypeArgs,
            $statusArgs,
            $splitSheetsArg,
            escapeshellarg($outputBase),
            escapeshellarg($statusFile),
            escapeshellarg($lockFile),
            escapeshellarg($logPath)
        );

        Log::info('Dispatching background VieFund All Transactions export.', [
            'user_id' => $userId,
            'run_id' => $runId,
            'output_base' => $outputBase,
        ]);

        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            @unlink($lockFile);
            file_put_contents($statusFile, json_encode([
                'run_id' => $runId,
                'inProgress' => false,
                'success' => false,
                'message' => 'The export process could not be started.',
                'completed_at' => now()->toIso8601String(),
            ], JSON_PRETTY_PRINT));

            return response()->json(['success' => false, 'message' => 'The export process could not be started.'], 500);
        }

        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return response()->json([
            'success' => true,
            'message' => 'All Transactions Excel export started.',
            'run_id' => $runId,
        ], 202);
    }

    public function allTransactionsExportStatus(Request $request): JsonResponse
    {
        $runId = $this->allTransactionsRunId($request);
        if ($runId === null) {
            return response()->json([
                'run_id' => null,
                'inProgress' => false,
                'success' => null,
                'message' => 'Idle',
                'progress_pct' => null,
                'download_url' => null,
            ])->withHeaders($this->exportStatusNoCacheHeaders());
        }

        $userId = (int) auth()->id();
        $lockFile = storage_path("app/reports/viefund-all-transactions-{$userId}.lock");
        $statusFile = $this->allTransactionsStatusFile($userId, $runId);
        $hasLiveLock = $this->allTransactionsRunHasLiveLock($lockFile, $runId, $userId);
        $payload = [
            'run_id' => $runId,
            'inProgress' => $hasLiveLock,
            'success' => null,
            'message' => $hasLiveLock ? 'Export in progress...' : 'Idle',
            'progress_pct' => null,
            'download_url' => null,
        ];

        if (is_file($statusFile)) {
            $parsed = json_decode((string) file_get_contents($statusFile), true);
            if (is_array($parsed)) {
                $payload = array_merge($payload, $parsed);
                $payload['inProgress'] = $hasLiveLock
                    && (($parsed['inProgress'] ?? false) === true)
                    && (($parsed['success'] ?? null) === null);
            }
        }

        $workerPid = (int) ($payload['worker_pid'] ?? 0);
        if ($hasLiveLock && $workerPid === 0 && !empty($payload['updated_at'])) {
            try {
                if (Carbon::parse((string) $payload['updated_at'])->lt(now()->subMinutes(5))) {
                    @unlink($lockFile);
                    $payload['inProgress'] = false;
                    $payload['success'] = false;
                    $payload['message'] = 'The export process stopped unexpectedly. Please retry the export.';
                    $payload['completed_at'] = now()->toIso8601String();
                    $payload['updated_at'] = now()->toIso8601String();
                    $this->writeJsonAtomically($statusFile, $payload);
                    $hasLiveLock = false;
                }
            } catch (Exception) {
                // Leave the normal lock timeout as the fallback for malformed legacy status data.
            }
        }
        if ($hasLiveLock && $workerPid > 0 && function_exists('posix_kill')) {
            $workerIsRunning = @posix_kill($workerPid, 0);
            if (!$workerIsRunning && function_exists('posix_get_last_error') && posix_get_last_error() === 1) {
                // EPERM means the process exists but this PHP process cannot signal it.
                $workerIsRunning = true;
            }

            if (!$workerIsRunning) {
                @unlink($lockFile);
                $payload['inProgress'] = false;
                $payload['success'] = false;
                $payload['message'] = 'The export process stopped unexpectedly. Please retry the export.';
                $payload['completed_at'] = now()->toIso8601String();
                $payload['updated_at'] = now()->toIso8601String();
                $this->writeJsonAtomically($statusFile, $payload);
            }
        }

        if (($payload['success'] ?? null) === true && !empty($payload['output_relative_path'])) {
            $relativePath = ltrim((string) $payload['output_relative_path'], '/');
            if (str_starts_with($relativePath, 'reports/') && is_file(storage_path('app/' . $relativePath))) {
                $payload['download_url'] = route('viefund-transactions.export.download', [
                    'run_id' => $runId,
                ]);
            }
        }

        return response()->json($payload)->withHeaders($this->exportStatusNoCacheHeaders());
    }

    public function downloadAllTransactionsExport(Request $request): BinaryFileResponse|RedirectResponse
    {
        $runId = $this->allTransactionsRunId($request);
        if ($runId === null) {
            return redirect()->route('viefund-transactions.index')->with('error', 'No transaction export run was selected.');
        }

        $userId = (int) auth()->id();
        $statusFile = $this->allTransactionsStatusFile($userId, $runId);
        $parsed = is_file($statusFile)
            ? json_decode((string) file_get_contents($statusFile), true)
            : null;
        $relativePath = is_array($parsed) ? ltrim((string) ($parsed['output_relative_path'] ?? ''), '/') : '';

        if ($relativePath === '' || !str_starts_with($relativePath, 'reports/viefund_all_transactions_' . $userId . '_')) {
            return redirect()->route('viefund-transactions.index')->with('error', 'No completed transaction export was found.');
        }

        $absolutePath = storage_path('app/' . $relativePath);
        if (!is_file($absolutePath)) {
            return redirect()->route('viefund-transactions.index')->with('error', 'The transaction export file was not found.');
        }

        return response()->download($absolutePath, basename($absolutePath));
    }

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $trxTypesSelected  = array_values(array_filter((array) $request->query('filter_trx_type', [])));
        $statusGroupSelected = array_values(array_intersect(
            (array) $request->query('filter_status_group', []),
            ['not_completed', 'open', 'completed']
        ));
        $filters = array_filter([
            'customer_id'     => trim((string) $request->query('filter_customer_id', '')),
            'customer_name'   => trim((string) $request->query('filter_customer_name', '')),
            'account_id'      => trim((string) $request->query('filter_account_id', '')),
            'trx_id'          => trim((string) $request->query('filter_trx_id', '')),
            'trust_trx_id'    => trim((string) $request->query('filter_trust_trx_id', '')),
            'source_id'       => trim((string) $request->query('filter_source_id', '')),
            'plan_account_id' => trim((string) $request->query('filter_plan_account_id', '')),
            'trx_type'        => $trxTypesSelected ?: null,
            'status_group'    => $statusGroupSelected ?: null,
            'created_from'    => trim((string) $request->query('filter_created_from', '')),
            'created_to'      => trim((string) $request->query('filter_created_to', '')),
        ]);
        $connectionError    = null;
        $transactions       = null;
        $planAccounts       = null;   // non-null → show plan-list mode instead of transaction table
        $totalRecords       = 0;
        $syncNeeded         = false;
        $localBalances      = collect();
        $currentBalance     = null;
        $calculatedBalance  = null;
        $calculatedBalances = [];
        $pageStartBalances  = [];
        $bannerName         = null;
        $availableTrxTypes  = [];

        // ── Sync status ───────────────────────────────────────────────────────
        // Three states: in-progress (lock file present) → needs sync → up to date
        $syncInProgress = false;
        $syncNeeded     = false;
        $lastSyncedAt   = $this->lastSuccessfulTransactionSyncAt();

        $lockFile = storage_path('app/viefund-sync.lock');
        if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 14400) {
            // Lock file present and less than 4 hours old → sync is running
            $syncInProgress = true;
        } elseif (env('VIEFUND_FORCE_SYNC_NEEDED', false)) {
            // Dev/test override in .env
            $syncNeeded = true;
        } else {
            // Watermark check: compare local max cash_trx_id against remote max.
            // Cache the remote MAX for 5 minutes to avoid a round-trip on every page load.
            try {
                $schema    = env('VIEFUND_DB_SCHEMA', 'dbo');
                $localMax  = (int) (RemoteVieFundCustomerTransaction::max('cash_trx_id') ?? 0);
                $remoteMax = (int) \Illuminate\Support\Facades\Cache::remember(
                    'viefund_remote_max_id',
                    300,
                    fn() =>
                    DB::connection('viefund_sqlsrv')
                        ->table("{$schema}.UB_FundTrxCash as fc")
                        ->join("{$schema}.UB_CashTrx as ct", 'ct.ID', '=', 'fc.iCashTrxID')
                        ->max('ct.ID')
                );
                $syncNeeded = $remoteMax > $localMax;
            } catch (Exception) {
                // Suppress silently — main query will surface any real connection error
            }
        }

        try {
            // Only fetch transactions when the user has provided a filter or search term.
            // On the initial (unfiltered) page load we show a prompt instead.
            $hasQuery = $search || !empty(array_filter($filters));

            // Mode switching:
            // • account_id set                → full transaction table (focused view)
            // • customer_id only (no account) → plan-list mode filtered to that customer
            // • any other filter/search       → show matching plan account list
            $isTransactionMode = !empty($filters['account_id']);

            // Defer the expensive COUNT query — only needed when displaying the summary card
            if ($hasQuery && $isTransactionMode) {
                $totalRecords = $this->remoteService->countTransactions();
            }

            if ($hasQuery && !$isTransactionMode) {
                // Plan-list mode: return distinct matching plan accounts
                $planAccounts = $this->remoteService->fetchMatchingPlanAccounts($search ?: null, $filters);
            } elseif ($hasQuery) {
                $transactions = $this->remoteService->fetchTransactions($search ?: null, $filters);
                $availableTrxTypes = $this->remoteService->fetchDistinctTrxTypes($filters);
                $cashTrxIds = $transactions->getCollection()
                    ->pluck('cash_trx_id')
                    ->filter()
                    ->values();

                if ($cashTrxIds->isNotEmpty()) {
                    $localBalances = RemoteVieFundCustomerTransaction::whereIn('cash_trx_id', $cashTrxIds)
                        ->pluck('running_balance', 'cash_trx_id');
                }
            }

            // Banner name + current balance for focused (transaction) mode only
            if ($isTransactionMode && !empty($filters['customer_id'])) {
                $bannerName         = $filters['customer_name'] ?? null;
                $currentBalance     = $this->remoteService->getLatestBalance($filters);
                $calculatedBalances = $this->remoteService->getCalculatedBalancesByPlan($filters);
                $calculatedBalance  = !empty($calculatedBalances) ? array_sum($calculatedBalances) : null;
                $pageStartBalances  = $this->remoteService->getPageStartBalancesByPlan(
                    $filters,
                    $transactions->currentPage(),
                    $transactions->perPage(),
                    $search ?: null
                );
            } elseif (!empty($filters['account_id'])) {
                $customer = $this->remoteService->getCustomerForPlanAccount($filters['account_id']);
                if ($customer) {
                    $bannerName         = $customer['customer_name'];
                    $currentBalance     = $this->remoteService->getLatestBalance($filters);
                    $calculatedBalances = $this->remoteService->getCalculatedBalancesByPlan($filters);
                    $calculatedBalance  = !empty($calculatedBalances) ? array_sum($calculatedBalances) : null;
                    $pageStartBalances  = $this->remoteService->getPageStartBalancesByPlan(
                        $filters,
                        $transactions->currentPage(),
                        $transactions->perPage(),
                        $search ?: null
                    );
                }
            }
        } catch (Exception $e) {
            $connectionError = 'Could not connect to the remote VieFund database: ' . $e->getMessage();
        }

        return view('remote-viefund.index', compact(
            'transactions',
            'planAccounts',
            'totalRecords',
            'search',
            'filters',
            'connectionError',
            'localBalances',
            'currentBalance',
            'calculatedBalance',
            'calculatedBalances',
            'pageStartBalances',
            'bannerName',
            'availableTrxTypes',
            'syncNeeded',
            'syncInProgress',
            'lastSyncedAt'
        ));
    }

    public function export(Request $request): BinaryFileResponse|StreamedResponse
    {
        $format = $request->query('format', 'csv');
        $search = trim((string) $request->query('search', ''));
        $trxTypesSelected  = array_values(array_filter((array) $request->query('filter_trx_type', [])));
        $statusGroupSelected = array_values(array_intersect(
            (array) $request->query('filter_status_group', []),
            ['not_completed', 'open', 'completed']
        ));
        $filters = array_filter([
            'customer_id'     => trim((string) $request->query('filter_customer_id', '')),
            'customer_name'   => trim((string) $request->query('filter_customer_name', '')),
            'account_id'      => trim((string) $request->query('filter_account_id', '')),
            'trx_id'          => trim((string) $request->query('filter_trx_id', '')),
            'trust_trx_id'    => trim((string) $request->query('filter_trust_trx_id', '')),
            'source_id'       => trim((string) $request->query('filter_source_id', '')),
            'trx_type'        => $trxTypesSelected ?: null,
            'status_group'    => $statusGroupSelected ?: null,
            'created_from'    => trim((string) $request->query('filter_created_from', '')),
            'created_to'      => trim((string) $request->query('filter_created_to', '')),
        ]);

        $rows = $this->remoteService->exportTransactions($search ?: null, $filters)
            ->sortBy([['plan_dealer_account_id', 'asc'], ['created_date', 'asc'], ['trx_id', 'asc']])
            ->values();

        $runningBalances = [];
        $rows = $rows->map(function ($row) use (&$runningBalances) {
            $planId = (string) ($row->plan_dealer_account_id ?? '');
            $runningBalances[$planId] = $runningBalances[$planId] ?? 0.0;

            $amount = $row->amount !== null ? (float) $row->amount : 0.0;
            $runningBalances[$planId] = round(($runningBalances[$planId] + $amount) * 10000) / 10000;
            $row->calculated_balance = $runningBalances[$planId];
            $row->display_trx_id = $this->buildDisplayTrxId($row);

            return $row;
        });

        $accountId = preg_replace('/[^A-Za-z0-9_-]/', '-', (string) ($filters['account_id'] ?? 'export'));
        $timestamp = now()->format('Ymd_His');
        $filename = "viefund_trx_{$accountId}_{$timestamp}";

        $headers = [
            'Display Txn ID',
            'Fund Trx ID',
            'Cash Trx ID',
            'Trust Trx ID',
            'Source ID',
            'Client Name',
            'Rep Code',
            'Plan Account ID',
            'Txn Type',
            'Txn Type Detail',
            'Status',
            'Created Date',
            'Trade Date',
            'Processing Date',
            'Settlement Date',
            'Amount -',
            'Amount +',
            'Calculated Balance',
            'Notes',
        ];

        if ($format === 'excel') {
            return Excel::download(
                new VieFundReportSheetExport(
                    $this->buildExportSheetRows($headers, $rows),
                    'Transactions'
                ),
                $filename . '.xlsx',
                ExcelWriter::XLSX
            );
        }
        return $this->streamCsv($filename, $headers, $rows);
    }

    /**
     * @param array<int, string> $headers
     * @param iterable<object> $rows
     * @return array<int, array<int, string|int|float|null>>
     */
    private function buildExportSheetRows(array $headers, iterable $rows): array
    {
        $sheetRows = [$headers];

        foreach ($rows as $row) {
            $amount  = (float) $row->amount;
            $balance = $row->calculated_balance !== null ? (float) $row->calculated_balance : null;
            $sheetRows[] = [
                $row->display_trx_id,
                $row->fund_trx_id,
                $row->row_source === 'fund' ? $row->cash_trx_id : '',
                $row->trust_trx_id ?? $row->linked_trust_trx_id ?? '',
                $row->source_id,
                trim($row->client_name),
                $row->rep_code,
                $row->plan_dealer_account_id,
                $row->trx_type,
                $row->cash_trx_type,
                $row->status ?? '',
                $row->created_date,
                $row->trade_date,
                $row->processing_date,
                $row->settlement_date,
                $amount < 0 ? number_format(abs($amount), 2) : '',
                $amount >= 0 ? number_format($amount, 2) : '',
                $balance !== null ? number_format($balance, 2) : '',
                $row->notes ?? '',
            ];
        }

        return $sheetRows;
    }

    private function streamCsv(string $filename, array $headers, $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            // UTF-8 BOM so Excel opens it correctly
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                $amount  = (float) $row->amount;
                $balance = $row->calculated_balance !== null ? (float) $row->calculated_balance : null;
                fputcsv($out, [
                    $row->display_trx_id,
                    $row->fund_trx_id,
                    $row->row_source === 'fund' ? $row->cash_trx_id : '',
                    $row->trust_trx_id ?? $row->linked_trust_trx_id ?? '',
                    $row->source_id,
                    trim($row->client_name),
                    $row->rep_code,
                    $row->plan_dealer_account_id,
                    $row->trx_type,
                    $row->cash_trx_type,
                    $row->status ?? '',
                    $row->created_date,
                    $row->trade_date,
                    $row->processing_date,
                    $row->settlement_date,
                    $amount < 0  ? number_format(abs($amount), 2) : '',
                    $amount >= 0 ? number_format($amount, 2) : '',
                    $balance !== null ? number_format($balance, 2) : '',
                    $row->notes ?? '',
                ]);
            }
            fclose($out);
        }, $filename . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function buildDisplayTrxId(object $row): string
    {
        if (($row->row_source ?? '') === 'trust') {
            $trustId = $row->trust_trx_id ?? $row->cash_trx_id ?? null;
            return $trustId ? 'T-' . $trustId : 'T-UNKNOWN';
        }

        if (!empty($row->cash_trx_id)) {
            return 'C-' . $row->cash_trx_id;
        }

        if (!empty($row->fund_trx_id)) {
            return 'F-' . $row->fund_trx_id;
        }

        return 'F-UNKNOWN';
    }

    public function syncStatus(): JsonResponse
    {
        $lockFile   = storage_path('app/viefund-sync.lock');
        $inProgress = file_exists($lockFile) && (time() - filemtime($lockFile)) < 14400;

        $syncNeeded = false;
        if (!$inProgress) {
            try {
                $schema    = env('VIEFUND_DB_SCHEMA', 'dbo');
                $localMax  = (int) (RemoteVieFundCustomerTransaction::max('cash_trx_id') ?? 0);
                $remoteMax = (int) DB::connection('viefund_sqlsrv')
                    ->table("{$schema}.UB_FundTrxCash as fc")
                    ->join("{$schema}.UB_CashTrx as ct", 'ct.ID', '=', 'fc.iCashTrxID')
                    ->max('ct.ID');
                $syncNeeded = $remoteMax > $localMax;
            } catch (Exception) {
                // Suppress
            }
        }

        $lastSyncedAt = $this->lastSuccessfulTransactionSyncAt();

        return response()->json([
            'inProgress' => $inProgress,
            'syncNeeded' => $syncNeeded,
            'lastSyncedAt' => $lastSyncedAt?->timezone(config('app.display_timezone', 'America/Toronto'))->format('M j, Y g:i A T'),
        ]);
    }

    private function lastSuccessfulTransactionSyncAt(): ?Carbon
    {
        $statusFile = storage_path('app/viefund-transactions-sync-status.json');
        if (!file_exists($statusFile)) {
            return null;
        }

        try {
            $status = json_decode((string) file_get_contents($statusFile), true, flags: JSON_THROW_ON_ERROR);

            return isset($status['completed_at']) ? Carbon::parse($status['completed_at']) : null;
        } catch (Exception) {
            return null;
        }
    }

    public function sync(Request $request): RedirectResponse
    {
        $artisanPath = base_path('artisan');
        $lockFile    = storage_path('app/viefund-sync.lock');
        $logPath     = storage_path('logs/viefund-sync.log');
        $phpPath     = env('PHP_PATH', '/usr/local/bin/php');

        // Create lock file so the UI shows "in progress" immediately
        file_put_contents($lockFile, date('c'));

        $command = sprintf(
            '%s %s viefund:sync-customers >> %s 2>&1 &',
            escapeshellarg($phpPath),
            escapeshellarg($artisanPath),
            escapeshellarg($logPath)
        );

        Log::info('Dispatching viefund:sync-customers in background: ' . $command);

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

        return redirect()->route('remote-viefund.index', $request->query());
    }

    public function planAccounts(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        if (mb_strlen($search) < 2) {
            return response()->json([]);
        }
        try {
            return response()->json($this->remoteService->searchPlanAccounts($search));
        } catch (Exception $e) {
            return response()->json([], 500);
        }
    }

    public function customers(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        if (mb_strlen($search) < 2) {
            return response()->json([]);
        }
        try {
            return response()->json($this->remoteService->searchCustomers($search));
        } catch (Exception $e) {
            return response()->json([], 500);
        }
    }

    public function planAccountSnapshot(Request $request): JsonResponse
    {
        $accountId = trim((string) $request->query('account_id', ''));
        if ($accountId === '') {
            return response()->json(['error' => 'account_id is required'], 422);
        }
        try {
            $snapshot = $this->remoteService->getPlanAccountSnapshot($accountId);
            if (!$snapshot) {
                return response()->json(['error' => 'No snapshot found for this account.'], 404);
            }
            return response()->json($snapshot);
        } catch (Exception $e) {
            Log::error('Plan account snapshot error', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Could not load snapshot.'], 500);
        }
    }

    private function allTransactionsRunId(Request $request): ?string
    {
        $runId = strtolower(trim((string) $request->input('run_id', '')));

        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $runId)
            ? $runId
            : null;
    }

    private function allTransactionsStatusFile(int $userId, string $runId): string
    {
        return storage_path("app/reports/viefund-all-transactions-{$userId}-{$runId}-status.json");
    }

    private function allTransactionsRunHasLiveLock(string $lockFile, string $runId, int $userId): bool
    {
        if (!is_file($lockFile) || (time() - filemtime($lockFile)) >= self::ALL_TRANSACTIONS_EXPORT_LOCK_TTL_SECONDS) {
            return false;
        }

        $lock = json_decode((string) file_get_contents($lockFile), true);

        return is_array($lock)
            && hash_equals((string) ($lock['run_id'] ?? ''), $runId)
            && (int) ($lock['user_id'] ?? 0) === $userId;
    }

    private function exportStatusNoCacheHeaders(): array
    {
        return [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ];
    }

    private function writeJsonAtomically(string $path, array $payload): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $encoded = json_encode($payload, JSON_PRETTY_PRINT);
        $temporaryFile = $encoded !== false ? tempnam($directory, basename($path) . '.tmp.') : false;
        if ($temporaryFile === false) {
            return;
        }

        if (@file_put_contents($temporaryFile, $encoded, LOCK_EX) === false || !@rename($temporaryFile, $path)) {
            @unlink($temporaryFile);
        }
    }

    /** Resolve the configured or cached inception date for a cash date basis. */
    private function resolveAllTransactionInceptionDate(string $dateBasis): ?string
    {
        $specificEnvKey = self::ALL_TRANSACTION_INCEPTION_ENV_KEYS[$dateBasis] ?? null;
        $configured = $specificEnvKey ? env($specificEnvKey) : null;
        $configured = $configured ?: env('VIEFUND_REPORT_INCEPTION_DATE');

        if (is_string($configured) && trim($configured) !== '') {
            try {
                return Carbon::createFromFormat('Y-m-d', trim($configured))->toDateString();
            } catch (\Throwable) {
                // Fall through to the cached date refreshed by the reports workflow.
            }
        }

        return Cache::get("viefund:inception-date:{$dateBasis}");
    }
}
