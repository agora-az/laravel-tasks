<?php

namespace App\Http\Controllers;

use App\Exports\VieFundDailyBalanceWorkbookExport;
use App\Models\VieFundCashDailySnapshot;
use App\Models\VieFundCashDailySnapshotChange;
use App\Models\VieFundCashSnapshotRun;
use App\Services\VieFund\VieFundCashSnapshotService;
use App\Services\VieFund\VieFundRemoteService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VieFundReportsController extends Controller
{
    private const LOCK_TTL_SECONDS = 43200;
    private const LEGACY_FETCH_CHUNK_DAYS = 31;

    private const DATE_BASIS_OPTIONS = [
        'create_date' => 'Created date',
        'trade_date' => 'Trade date',
        'processing_date' => 'Processing date',
        'settlement_date' => 'Settlement date',
    ];

    private const DATE_BASIS_FILE_CODES = [
        'create_date' => 'cr',
        'trade_date' => 'tr',
        'processing_date' => 'pr',
        'settlement_date' => 'se',
    ];

    private const DATE_BASIS_INCEPTION_ENV_KEYS = [
        'create_date' => 'VIEFUND_REPORT_INCEPTION_CREATE_DATE',
        'trade_date' => 'VIEFUND_REPORT_INCEPTION_TRADE_DATE',
        'processing_date' => 'VIEFUND_REPORT_INCEPTION_PROCESSING_DATE',
        'settlement_date' => 'VIEFUND_REPORT_INCEPTION_SETTLEMENT_DATE',
    ];

    private const OUTPUT_ORDER_OPTIONS = [
        'asc' => 'Earliest first',
        'desc' => 'Latest first',
    ];

    /** Individual fund transaction statuses (UB_Def_TrxStatus id => label). */
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

    public function __construct(
        private readonly VieFundRemoteService $vieFundRemoteService,
        private readonly VieFundCashSnapshotService $snapshotService
    ) {}

    public function index(Request $request): View
    {
        $selectedDateBasis = $request->query('date_basis', 'settlement_date');
        if (!isset(self::DATE_BASIS_OPTIONS[$selectedDateBasis])) {
            $selectedDateBasis = 'settlement_date';
        }

        $dailyBalanceCurrencyCode = $this->normalizeCustomerBalanceCurrencyCode((string) $request->query('daily_balance_currency_code', 'CAD')) ?? '00';
        $dailyBalanceOpenedBefore = trim((string) $request->query(
            'daily_balance_opened_before',
            (string) env('VIEFUND_BALANCE_REPORT_CASH_OPENED_BEFORE', '')
        ));
        config([
            'viefund.balance_report_cash_account_scope.currency_code' => $dailyBalanceCurrencyCode,
            'viefund.balance_report_cash_account_scope.opened_before' => $dailyBalanceOpenedBefore !== '' ? $dailyBalanceOpenedBefore : null,
        ]);

        $inceptionDates = [];
        $legacyInceptionDates = [];
        foreach (array_keys(self::DATE_BASIS_OPTIONS) as $basisKey) {
            $inceptionDates[$basisKey] = $this->resolveInceptionDate($basisKey);
            $legacyInceptionDates[$basisKey] = $this->resolveLegacyInceptionDate($basisKey);
        }

        $defaultFrom = Carbon::today()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $defaultTo = Carbon::today()->subMonthNoOverflow()->endOfMonth()->toDateString();
        $selectedOutputOrder = $request->query('output_order', 'asc');
        if (!isset(self::OUTPUT_ORDER_OPTIONS[$selectedOutputOrder])) {
            $selectedOutputOrder = 'asc';
        }

        $selectedStatuses = $this->resolveStatuses($request->query('status'));
        $customerBalanceDateBasis = $request->query('customer_balance_date_basis', 'settlement_date');
        if (!isset(self::DATE_BASIS_OPTIONS[$customerBalanceDateBasis])) {
            $customerBalanceDateBasis = 'settlement_date';
        }

        $customerBalanceStatuses = $this->resolveStatuses($request->query('customer_balance_status'));
        $customerBalanceTrustStatuses = $this->resolveTrustStatuses($request->query('customer_balance_trust_status'));
        $customerBalanceCurrencyCode = strtoupper(trim((string) $request->query(
            'customer_balance_currency_code',
            'CAD'
        )));
        $customerBalanceCurrencyCode = $this->normalizeCustomerBalanceCurrencyCode($customerBalanceCurrencyCode);
        if ($customerBalanceCurrencyCode === null) {
            $customerBalanceCurrencyCode = '00';
        }
        $customerBalanceOpenedBefore = trim((string) $request->query(
            'customer_balance_opened_before',
            (string) env('VIEFUND_BALANCE_REPORT_CASH_OPENED_BEFORE', '')
        ));
        $cashSnapshotChanges = VieFundCashDailySnapshotChange::query()
            ->with('snapshot')
            ->whereHas('snapshot', fn($query) => $query->where('has_unreviewed_change', true))
            ->latest('detected_at')
            ->limit(20)
            ->get();
        $cashSnapshotRuns = VieFundCashSnapshotRun::query()
            ->latest('started_at')
            ->limit(8)
            ->get();

        return view('reports.index', [
            'dateBasisOptions' => self::DATE_BASIS_OPTIONS,
            'selectedDateBasis' => $selectedDateBasis,
            'dateFrom' => $request->query('date_from', $defaultFrom),
            'dateTo' => $request->query('date_to', $defaultTo),
            'outputOrderOptions' => self::OUTPUT_ORDER_OPTIONS,
            'selectedOutputOrder' => $selectedOutputOrder,
            'fundStatusOptions' => self::FUND_STATUS_OPTIONS,
            'trustStatusOptions' => self::TRUST_STATUS_OPTIONS,
            'selectedStatuses' => $selectedStatuses,
            'dailyBalanceCurrencyLabel' => $this->customerBalanceCurrencyLabelFromCode($dailyBalanceCurrencyCode),
            'dailyBalanceOpenedBefore' => $dailyBalanceOpenedBefore,
            'customerBalanceDate' => $request->query('customer_balance_date', Carbon::today()->toDateString()),
            'customerBalanceDateBasis' => $customerBalanceDateBasis,
            'customerBalanceStatuses' => $customerBalanceStatuses,
            'customerBalanceTrustStatuses' => $customerBalanceTrustStatuses,
            'customerBalanceCurrencyCode' => $customerBalanceCurrencyCode,
            'customerBalanceCurrencyLabel' => $this->customerBalanceCurrencyLabelFromCode($customerBalanceCurrencyCode),
            'customerBalanceOpenedBefore' => $customerBalanceOpenedBefore,
            'cashSnapshotChanges' => $cashSnapshotChanges,
            'cashSnapshotRuns' => $cashSnapshotRuns,
            'inceptionDates' => $inceptionDates,
            'legacyInceptionDates' => $legacyInceptionDates,
        ]);
    }

    public function acknowledgeCashSnapshotChange(Request $request, VieFundCashDailySnapshot $snapshot): RedirectResponse
    {
        $snapshot->update([
            'has_unreviewed_change' => false,
            'reviewed_at' => now(),
            'reviewed_by' => null,
            'reviewed_by_label' => (string) $request->session()->get('user', 'authenticated user'),
        ]);

        return redirect()
            ->route('reports.index')
            ->with('snapshot_review_success', 'Snapshot change acknowledged. Its audit history was retained.');
    }

    private function resolveInceptionDate(string $dateBasis): ?string
    {
        $specificEnvKey = self::DATE_BASIS_INCEPTION_ENV_KEYS[$dateBasis] ?? null;
        $configured = $specificEnvKey ? env($specificEnvKey) : null;
        if (!$configured) {
            $configured = env('VIEFUND_REPORT_INCEPTION_DATE');
        }

        if (is_string($configured) && trim($configured) !== '') {
            try {
                return Carbon::createFromFormat('Y-m-d', trim($configured))->toDateString();
            } catch (\Throwable) {
                // Ignore invalid env override and fall back to remote lookup.
            }
        }

        return $this->vieFundRemoteService->fetchInceptionDateByDateColumn($dateBasis);
    }

    private function resolveLegacyInceptionDate(string $dateBasis): ?string
    {
        return Cache::remember(
            "viefund:legacy-inception-date:{$dateBasis}",
            now()->addDay(),
            fn() => $this->vieFundRemoteService->fetchLegacyInceptionDateByDateColumn($dateBasis)
        );
    }

    public function exportDailyBalance(Request $request): BinaryFileResponse|StreamedResponse
    {
        $validated = $request->validate([
            'date_from' => ['required', 'date', 'before_or_equal:today'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from', 'before_or_equal:today'],
            'date_basis' => ['required', 'in:' . implode(',', array_keys(self::DATE_BASIS_OPTIONS))],
            'output_order' => ['required', 'in:asc,desc'],
            'status' => ['sometimes', 'array'],
            'status.*' => ['integer', 'between:0,6'],
            'daily_balance_currency_code' => ['required', 'in:CAD,USD'],
            'daily_balance_opened_before' => ['nullable', 'date', 'before_or_equal:now'],
            'format' => ['required', 'in:csv,excel'],
        ]);

        $dateFrom = Carbon::parse($validated['date_from'])->startOfDay();
        $dateTo = Carbon::parse($validated['date_to'])->startOfDay();
        $dateBasis = $validated['date_basis'];
        $outputOrder = $validated['output_order'];
        $statuses = $this->resolveStatuses($validated['status'] ?? null);
        $format = $validated['format'];

        config([
            'viefund.balance_report_cash_account_scope.currency_code' => $this->normalizeCustomerBalanceCurrencyCode($validated['daily_balance_currency_code']),
            'viefund.balance_report_cash_account_scope.opened_before' => !empty($validated['daily_balance_opened_before'])
                ? Carbon::parse($validated['daily_balance_opened_before'])->format('Y-m-d H:i:s')
                : null,
        ]);

        $openedBefore = !empty($validated['daily_balance_opened_before'])
            ? Carbon::parse($validated['daily_balance_opened_before'])->format('Y-m-d H:i:s')
            : null;
        $currencyCode = $this->normalizeCustomerBalanceCurrencyCode($validated['daily_balance_currency_code']) ?? '00';
        $snapshotResult = $openedBefore === null
            ? $this->snapshotService->completeSeries($dateFrom, $dateTo, $dateBasis, $currencyCode, $statuses)
            : null;
        $dailyTotals = $snapshotResult
            ? $snapshotResult['rows']->map(fn($snapshot) => (object) [
                'total_date' => $snapshot->total_date,
                'transaction_count' => $snapshot->transaction_count,
                'net_total' => $snapshot->net_total,
            ])
            : $this->vieFundRemoteService->fetchCustomerCashDailyNetTotalsByDateColumn($dateFrom, $dateTo, $dateBasis, [
                'status_ids' => $statuses,
                'availability_as_of' => $dateTo->toDateString(),
            ]);

        $byDate = [];
        foreach ($dailyTotals as $row) {
            $key = Carbon::parse($row->total_date)->toDateString();
            if (!isset($byDate[$key])) {
                $byDate[$key] = [
                    'transaction_count' => 0,
                    'net_total' => 0.0,
                ];
            }

            $byDate[$key]['transaction_count'] += (int) $row->transaction_count;
            $byDate[$key]['net_total'] += (float) $row->net_total;
        }

        $rows = [];
        if ($snapshotResult) {
            $openingBalance = $snapshotResult['opening_balance'];
            $endingBalance = $snapshotResult['ending_balance'];
        } else {
            $periodNetTotal = array_sum(array_column($byDate, 'net_total'));
            $endingBalance = (float) $this->vieFundRemoteService
                ->fetchCustomerBalancesByDate($dateTo, $dateBasis, ['status_ids' => $statuses])
                ->sum(fn($row) => (float) ($row->total_balance ?? 0));
            $openingBalance = $endingBalance - $periodNetTotal;
        }
        $runningBalance = $openingBalance;
        $cursor = $dateFrom->copy();

        while ($cursor->lte($dateTo)) {
            $dateKey = $cursor->toDateString();
            $day = $byDate[$dateKey] ?? [
                'transaction_count' => 0,
                'net_total' => 0.0,
            ];

            $dailyNet = (float) $day['net_total'];
            $runningBalance += $dailyNet;

            $rows[] = [
                'report_date' => $dateKey,
                'transaction_count' => (int) $day['transaction_count'],
                'daily_net_transactions' => $dailyNet,
                'running_daily_balance' => $runningBalance,
            ];

            $cursor->addDay();
        }

        $finalBalance = $runningBalance;
        if ($outputOrder === 'desc') {
            $rows = array_reverse($rows);
        }

        $baseName = sprintf(
            'viefund_bal_report_%s-%s_%s',
            $dateFrom->format('Ymd'),
            $dateTo->format('Ymd'),
            self::DATE_BASIS_FILE_CODES[$dateBasis]
        );

        $dateBasisLabel = self::DATE_BASIS_OPTIONS[$dateBasis];
        $outputOrderLabel = self::OUTPUT_ORDER_OPTIONS[$outputOrder];
        $statusLabel = $this->describeStatuses($statuses);

        $metadataRows = [
            ['Report', 'VieFund Daily Net + Running Balance'],
            ['Date Basis', $dateBasisLabel],
            ['Date Range', $dateFrom->toDateString() . ' to ' . $dateTo->toDateString()],
            ['Output Order', $outputOrderLabel],
            ['Balance Source', $snapshotResult ? 'Audited Daily Cash Snapshots' : 'Direct Cash Ledger (Live)'],
            ['Cash Transaction Statuses', $statusLabel],
            ['Simulated Generation Time', !empty($validated['daily_balance_opened_before']) ? Carbon::parse($validated['daily_balance_opened_before'])->format('Y-m-d H:i:s') : 'Not set'],
            ['Snapshot Last Verified At', $snapshotResult['last_verified_at'] ?? 'Not applicable'],
            ['Unreviewed Changed Days', $snapshotResult['changed_days'] ?? 0],
            ['Generated At', now()->toDateTimeString()],
            ['Opening Balance', $openingBalance],
            ['Final Balance', $finalBalance],
        ];

        if ($format === 'excel') {
            return Excel::download(
                new VieFundDailyBalanceWorkbookExport(
                    [[
                        'Report Date',
                        'Cash Transactions',
                        'Daily Net Transactions',
                        'Running Daily Balance',
                    ], ...array_map(fn(array $row) => array_values($row), $rows)],
                    [['Summary Item', 'Value'], ...$metadataRows]
                ),
                $baseName . '.xlsx',
                ExcelWriter::XLSX
            );
        }

        return $this->streamCsv($rows, $baseName . '.csv', $metadataRows);
    }

    public function exportLegacyDailyBalance(Request $request): BinaryFileResponse|StreamedResponse
    {
        $validated = $request->validate([
            'legacy_date_from' => ['required', 'date', 'before_or_equal:today'],
            'legacy_date_to' => ['required', 'date', 'after_or_equal:legacy_date_from', 'before_or_equal:today'],
            'legacy_date_basis' => ['required', 'in:' . implode(',', array_keys(self::DATE_BASIS_OPTIONS))],
            'legacy_output_order' => ['required', 'in:asc,desc'],
            'legacy_status' => ['sometimes', 'array'],
            'legacy_status.*' => ['integer', 'between:0,6'],
            'legacy_trust_status' => ['sometimes', 'array'],
            'legacy_trust_status.*' => ['in:' . implode(',', self::TRUST_STATUS_OPTIONS)],
            'legacy_format' => ['required', 'in:csv,excel'],
        ]);

        $dateFrom = Carbon::parse($validated['legacy_date_from'])->startOfDay();
        $dateTo = Carbon::parse($validated['legacy_date_to'])->startOfDay();
        $dateBasis = $validated['legacy_date_basis'];
        $outputOrder = $validated['legacy_output_order'];
        $statuses = $this->resolveStatuses($validated['legacy_status'] ?? null);
        $trustStatuses = $this->resolveTrustStatuses($validated['legacy_trust_status'] ?? null, []);
        $format = $validated['legacy_format'];

        $byDate = [];
        $fetchCursor = $dateFrom->copy();
        while ($fetchCursor->lte($dateTo)) {
            $chunkEnd = $fetchCursor->copy()
                ->addDays(self::LEGACY_FETCH_CHUNK_DAYS - 1)
                ->min($dateTo);
            $dailyTotals = $this->vieFundRemoteService->fetchDailyNetTotalsByDateColumn(
                $fetchCursor,
                $chunkEnd,
                $dateBasis,
                [
                    'status_ids' => $statuses,
                    'trust_status_names' => $trustStatuses,
                ]
            );

            foreach ($dailyTotals as $row) {
                $dateKey = Carbon::parse($row->total_date)->toDateString();
                $byDate[$dateKey] ??= [
                    'transaction_count' => 0,
                    'net_total' => 0.0,
                ];
                $byDate[$dateKey]['transaction_count'] += (int) $row->transaction_count;
                $byDate[$dateKey]['net_total'] += (float) $row->net_total;
            }

            $fetchCursor = $chunkEnd->copy()->addDay();
        }

        $rows = [];
        $runningBalance = 0.0;
        $cursor = $dateFrom->copy();

        while ($cursor->lte($dateTo)) {
            $dateKey = $cursor->toDateString();
            $day = $byDate[$dateKey] ?? [
                'transaction_count' => 0,
                'net_total' => 0.0,
            ];
            $dailyNet = (float) $day['net_total'];
            $runningBalance += $dailyNet;

            $rows[] = [
                'report_date' => $dateKey,
                'transaction_count' => (int) $day['transaction_count'],
                'daily_net_transactions' => $dailyNet,
                'running_daily_balance' => $runningBalance,
            ];

            $cursor->addDay();
        }

        if ($outputOrder === 'desc') {
            $rows = array_reverse($rows);
        }

        $baseName = sprintf(
            'viefund_legacy_bal_report_%s-%s_%s',
            $dateFrom->format('Ymd'),
            $dateTo->format('Ymd'),
            self::DATE_BASIS_FILE_CODES[$dateBasis]
        );
        $trustStatusLabel = $trustStatuses ? implode(', ', $trustStatuses) : 'Excluded';
        $metadataRows = [
            ['Report', 'Legacy VieFund Daily Net + Running Balance'],
            ['Date Basis', self::DATE_BASIS_OPTIONS[$dateBasis]],
            ['Date Range', $dateFrom->toDateString() . ' to ' . $dateTo->toDateString()],
            ['Output Order', self::OUTPUT_ORDER_OPTIONS[$outputOrder]],
            ['Balance Source', 'Legacy Fund + Trust Reconstruction (Live)'],
            ['Fund Statuses', $this->describeStatuses($statuses)],
            ['Trust Statuses', $trustStatusLabel],
            ['Opening Balance Method', 'Zero at selected start date'],
            ['Snapshot Cache', 'Not used'],
            ['Generated At', now()->toDateTimeString()],
            ['Opening Balance', 0.0],
            ['Final Balance', $runningBalance],
        ];

        if ($format === 'excel') {
            return Excel::download(
                new VieFundDailyBalanceWorkbookExport(
                    [[
                        'Report Date',
                        'Legacy Transactions',
                        'Daily Net Transactions',
                        'Running Daily Balance',
                    ], ...array_map(fn(array $row) => array_values($row), $rows)],
                    [['Summary Item', 'Value'], ...$metadataRows]
                ),
                $baseName . '.xlsx',
                ExcelWriter::XLSX
            );
        }

        return $this->streamCsv($rows, $baseName . '.csv', $metadataRows);
    }

    public function runDailyBalanceReport(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'date_basis' => ['required', 'in:' . implode(',', array_keys(self::DATE_BASIS_OPTIONS))],
            'output_order' => ['required', 'in:asc,desc'],
            'status' => ['sometimes', 'array'],
            'status.*' => ['integer', 'between:0,6'],
            'daily_balance_currency_code' => ['required', 'in:CAD,USD'],
            'daily_balance_opened_before' => ['nullable', 'date', 'before_or_equal:now'],
            'format' => ['required', 'in:csv,excel'],
        ]);

        $lockFile = storage_path('app/reports/viefund-daily-balance.lock');
        $statusFile = storage_path('app/reports/viefund-daily-balance-status.json');
        $logPath = storage_path('logs/viefund-daily-balance-report.log');
        $phpPath = env('PHP_PATH', '/usr/local/bin/php');
        $artisanPath = base_path('artisan');

        $hasLiveLock = file_exists($lockFile) && (time() - filemtime($lockFile)) < self::LOCK_TTL_SECONDS;
        if ($hasLiveLock) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'A VieFund daily balance report is already running.',
                ], 409);
            }

            return redirect()
                ->route('reports.index')
                ->with('report_error', 'A VieFund daily balance report is already running.');
        }

        $dateFrom = Carbon::parse($validated['date_from'])->toDateString();
        $dateTo = Carbon::parse($validated['date_to'])->toDateString();
        $dateBasis = $validated['date_basis'];
        $outputOrder = $validated['output_order'];
        $statuses = $this->resolveStatuses($validated['status'] ?? null);
        $currencyCode = $this->normalizeCustomerBalanceCurrencyCode($validated['daily_balance_currency_code']) ?? '00';
        $openedBefore = !empty($validated['daily_balance_opened_before'])
            ? Carbon::parse($validated['daily_balance_opened_before'])->format('Y-m-d H:i:s')
            : null;
        $format = $validated['format'];

        $extension = $format === 'excel' ? 'xlsx' : 'csv';
        $outputFileName = sprintf(
            'viefund_bal_report_%s-%s_%s.%s',
            Carbon::parse($dateFrom)->format('Ymd'),
            Carbon::parse($dateTo)->format('Ymd'),
            self::DATE_BASIS_FILE_CODES[$dateBasis],
            $extension
        );
        $outputRelativePath = 'reports/' . $outputFileName;

        $reportsDir = dirname($statusFile);
        if (!is_dir($reportsDir)) {
            @mkdir($reportsDir, 0775, true);
        }

        file_put_contents($lockFile, date('c'));
        file_put_contents($statusFile, json_encode([
            'inProgress' => true,
            'success' => null,
            'message' => 'VieFund daily balance report queued...',
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'date_basis' => self::DATE_BASIS_OPTIONS[$dateBasis],
            'output_order' => self::OUTPUT_ORDER_OPTIONS[$outputOrder],
            'status' => $this->describeStatuses($statuses),
            'cash_currency_code' => $currencyCode,
            'cash_currency_label' => $this->customerBalanceCurrencyLabelFromCode($currencyCode),
            'cash_opened_before' => $openedBefore ?: 'Not set',
            'format' => strtoupper($format),
            'processed_days' => 0,
            'total_days' => null,
            'progress_pct' => 0,
            'output_relative_path' => $outputRelativePath,
            'started_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT));

        $statusArgs = implode(' ', array_map(
            fn($id): string => '--status=' . escapeshellarg((string) $id),
            $statuses
        ));

        $envAssignments = [
            'VIEFUND_BALANCE_REPORT_CASH_CURRENCY_CODE=' . escapeshellarg($currencyCode),
        ];
        if ($openedBefore !== null) {
            $envAssignments[] = 'VIEFUND_BALANCE_REPORT_CASH_OPENED_BEFORE=' . escapeshellarg($openedBefore);
        }
        $envPrefix = implode(' ', $envAssignments) . ' ';

        $command = sprintf(
            '%s%s %s report:viefund-daily-balance --date-from=%s --date-to=%s --date-basis=%s --output-order=%s %s --format=%s --output-file=%s --status-file=%s --lock-file=%s >> %s 2>&1 &',
            $envPrefix,
            escapeshellarg($phpPath),
            escapeshellarg($artisanPath),
            escapeshellarg($dateFrom),
            escapeshellarg($dateTo),
            escapeshellarg($dateBasis),
            escapeshellarg($outputOrder),
            $statusArgs,
            escapeshellarg($format),
            escapeshellarg($outputRelativePath),
            escapeshellarg($statusFile),
            escapeshellarg($lockFile),
            escapeshellarg($logPath)
        );

        Log::info('Dispatching background VieFund daily balance report: ' . $command);

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

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'VieFund daily balance report started.',
            ], 202);
        }

        return redirect()
            ->route('reports.index')
            ->with('report_success', 'VieFund daily balance report started in background.');
    }

    public function runLegacyDailyBalanceReport(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'legacy_date_from' => ['required', 'date', 'before_or_equal:today'],
            'legacy_date_to' => ['required', 'date', 'after_or_equal:legacy_date_from', 'before_or_equal:today'],
            'legacy_date_basis' => ['required', 'in:' . implode(',', array_keys(self::DATE_BASIS_OPTIONS))],
            'legacy_output_order' => ['required', 'in:asc,desc'],
            'legacy_status' => ['sometimes', 'array'],
            'legacy_status.*' => ['integer', 'between:0,6'],
            'legacy_trust_status' => ['sometimes', 'array'],
            'legacy_trust_status.*' => ['in:' . implode(',', self::TRUST_STATUS_OPTIONS)],
            'legacy_format' => ['required', 'in:csv,excel'],
        ]);

        $lockFile = storage_path('app/reports/viefund-daily-balance-legacy.lock');
        $statusFile = storage_path('app/reports/viefund-daily-balance-legacy-status.json');
        $logPath = storage_path('logs/viefund-daily-balance-legacy-report.log');
        $hasLiveLock = file_exists($lockFile) && (time() - filemtime($lockFile)) < self::LOCK_TTL_SECONDS;
        if ($hasLiveLock) {
            $message = 'A legacy VieFund daily balance report is already running.';
            return $request->expectsJson()
                ? response()->json(['success' => false, 'message' => $message], 409)
                : redirect()->route('reports.index')->with('legacy_report_error', $message);
        }

        $dateFrom = Carbon::parse($validated['legacy_date_from'])->toDateString();
        $dateTo = Carbon::parse($validated['legacy_date_to'])->toDateString();
        $dateBasis = $validated['legacy_date_basis'];
        $outputOrder = $validated['legacy_output_order'];
        $statuses = $this->resolveStatuses($validated['legacy_status'] ?? null);
        $trustStatuses = $this->resolveTrustStatuses($validated['legacy_trust_status'] ?? null, []);
        $format = $validated['legacy_format'];
        $extension = $format === 'excel' ? 'xlsx' : 'csv';
        $outputRelativePath = sprintf(
            'reports/viefund_legacy_bal_report_%s-%s_%s.%s',
            Carbon::parse($dateFrom)->format('Ymd'),
            Carbon::parse($dateTo)->format('Ymd'),
            self::DATE_BASIS_FILE_CODES[$dateBasis],
            $extension
        );

        if (!is_dir(dirname($statusFile))) {
            @mkdir(dirname($statusFile), 0775, true);
        }
        file_put_contents($lockFile, date('c'));
        file_put_contents($statusFile, json_encode([
            'inProgress' => true,
            'success' => null,
            'message' => 'Legacy VieFund daily balance report queued...',
            'processed_days' => 0,
            'total_days' => Carbon::parse($dateFrom)->diffInDays(Carbon::parse($dateTo)) + 1,
            'progress_pct' => 0,
            'output_relative_path' => $outputRelativePath,
            'started_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT));

        $statusArgs = implode(' ', array_map(
            fn(int $id): string => '--status=' . escapeshellarg((string) $id),
            $statuses
        ));
        $trustStatusArgs = implode(' ', array_map(
            fn(string $name): string => '--trust-status=' . escapeshellarg($name),
            $trustStatuses
        ));
        $command = sprintf(
            '%s %s report:viefund-daily-balance-legacy --date-from=%s --date-to=%s --date-basis=%s --output-order=%s %s %s --format=%s --output-file=%s --status-file=%s --lock-file=%s >> %s 2>&1 &',
            escapeshellarg((string) env('PHP_PATH', PHP_BINARY)),
            escapeshellarg(base_path('artisan')),
            escapeshellarg($dateFrom),
            escapeshellarg($dateTo),
            escapeshellarg($dateBasis),
            escapeshellarg($outputOrder),
            $statusArgs,
            $trustStatusArgs,
            escapeshellarg($format),
            escapeshellarg($outputRelativePath),
            escapeshellarg($statusFile),
            escapeshellarg($lockFile),
            escapeshellarg($logPath)
        );
        Log::info('Dispatching background legacy VieFund daily balance report: ' . $command);
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (is_resource($process)) {
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }

        return $request->expectsJson()
            ? response()->json(['success' => true, 'message' => 'Legacy VieFund daily balance report started.'], 202)
            : redirect()->route('reports.index')->with('legacy_report_success', 'Legacy report started in background.');
    }

    public function runCustomerBalancesReport(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'customer_balance_date' => ['required', 'date', 'before_or_equal:today'],
            'customer_balance_date_basis' => ['required', 'in:' . implode(',', array_keys(self::DATE_BASIS_OPTIONS))],
            'customer_balance_status' => ['sometimes', 'array'],
            'customer_balance_status.*' => ['integer', 'between:0,6'],
            'customer_balance_trust_status' => ['sometimes', 'array'],
            'customer_balance_trust_status.*' => ['in:' . implode(',', self::TRUST_STATUS_OPTIONS)],
            'customer_balance_currency_code' => ['required', 'in:CAD,USD'],
            'customer_balance_opened_before' => ['nullable', 'date', 'before_or_equal:now'],
            'format' => ['required', 'in:csv,excel'],
        ]);

        $lockFile = storage_path('app/reports/viefund-customer-balances.lock');
        $statusFile = storage_path('app/reports/viefund-customer-balances-status.json');
        $logPath = storage_path('logs/viefund-customer-balances-report.log');
        $phpPath = env('PHP_PATH', '/usr/local/bin/php');
        $artisanPath = base_path('artisan');

        $hasLiveLock = file_exists($lockFile) && (time() - filemtime($lockFile)) < self::LOCK_TTL_SECONDS;
        if ($hasLiveLock) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'A VieFund customer balances report is already running.',
                ], 409);
            }

            return redirect()
                ->route('reports.index')
                ->with('customer_balance_report_error', 'A VieFund customer balances report is already running.');
        }

        $reportDate = Carbon::parse($validated['customer_balance_date'])->toDateString();
        $dateBasis = $validated['customer_balance_date_basis'];
        $statuses = $this->resolveStatuses($validated['customer_balance_status'] ?? null);
        $trustStatuses = $this->resolveTrustStatuses($validated['customer_balance_trust_status'] ?? null, []);
        $selectedCurrency = strtoupper(trim((string) $validated['customer_balance_currency_code']));
        $currencyCode = $this->normalizeCustomerBalanceCurrencyCode($selectedCurrency);
        if ($currencyCode === null) {
            $currencyCode = '00';
        }
        $openedBefore = null;
        if (!empty($validated['customer_balance_opened_before'])) {
            $openedBefore = Carbon::parse((string) $validated['customer_balance_opened_before'])->format('Y-m-d H:i:s');
        }
        $format = $validated['format'];

        $extension = $format === 'excel' ? 'xlsx' : 'csv';
        $outputFileName = sprintf(
            'viefund_customer_balances_%s_%s.%s',
            Carbon::parse($reportDate)->format('Ymd'),
            self::DATE_BASIS_FILE_CODES[$dateBasis],
            $extension
        );
        $outputRelativePath = 'reports/' . $outputFileName;

        $reportsDir = dirname($statusFile);
        if (!is_dir($reportsDir)) {
            @mkdir($reportsDir, 0775, true);
        }

        file_put_contents($lockFile, date('c'));
        file_put_contents($statusFile, json_encode([
            'inProgress' => true,
            'success' => null,
            'message' => 'VieFund customer balances report queued...',
            'report_date' => $reportDate,
            'date_basis' => self::DATE_BASIS_OPTIONS[$dateBasis],
            'status' => $this->describeStatuses($statuses),
            'trust_status' => $trustStatuses ? implode(', ', $trustStatuses) : 'Excluded',
            'cash_currency_code' => $currencyCode ?: 'Not set',
            'cash_currency_label' => $this->customerBalanceCurrencyLabelFromCode($currencyCode) ?: 'Not set',
            'cash_opened_before' => $openedBefore ?: 'Not set',
            'format' => strtoupper($format),
            'processed_accounts' => 0,
            'total_accounts' => null,
            'progress_pct' => 0,
            'output_relative_path' => $outputRelativePath,
            'started_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT));

        $statusArgs = implode(' ', array_map(
            fn($id): string => '--status=' . escapeshellarg((string) $id),
            $statuses
        ));

        $trustStatusArgs = implode(' ', array_map(
            fn(string $name): string => '--trust-status=' . escapeshellarg($name),
            $trustStatuses
        ));

        $envAssignments = [];
        if ($currencyCode !== null) {
            $envAssignments[] = 'VIEFUND_BALANCE_REPORT_CASH_CURRENCY_CODE=' . escapeshellarg($currencyCode);
        }
        if ($openedBefore !== null) {
            $envAssignments[] = 'VIEFUND_BALANCE_REPORT_CASH_OPENED_BEFORE=' . escapeshellarg($openedBefore);
        }
        $envPrefix = $envAssignments ? implode(' ', $envAssignments) . ' ' : '';

        $command = sprintf(
            '%s%s %s report:viefund-customer-balances --report-date=%s --date-basis=%s %s %s --format=%s --output-file=%s --status-file=%s --lock-file=%s >> %s 2>&1 &',
            $envPrefix,
            escapeshellarg($phpPath),
            escapeshellarg($artisanPath),
            escapeshellarg($reportDate),
            escapeshellarg($dateBasis),
            $statusArgs,
            $trustStatusArgs,
            escapeshellarg($format),
            escapeshellarg($outputRelativePath),
            escapeshellarg($statusFile),
            escapeshellarg($lockFile),
            escapeshellarg($logPath)
        );

        Log::info('Dispatching background VieFund customer balances report: ' . $command);

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

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'VieFund customer balances report started.',
            ], 202);
        }

        return redirect()
            ->route('reports.index')
            ->with('customer_balance_report_success', 'VieFund customer balances report started in background.');
    }

    public function reportStatus(): JsonResponse
    {
        $lockFile = storage_path('app/reports/viefund-daily-balance.lock');
        $statusFile = storage_path('app/reports/viefund-daily-balance-status.json');

        $inProgress = file_exists($lockFile) && (time() - filemtime($lockFile)) < self::LOCK_TTL_SECONDS;

        $payload = [
            'inProgress' => $inProgress,
            'success' => null,
            'message' => $inProgress ? 'Report in progress...' : 'Idle',
            'processed_days' => null,
            'total_days' => null,
            'progress_pct' => null,
            'download_url' => null,
            'updated_at' => null,
            'started_at' => null,
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
            $payload['message'] = 'Report stopped before reporting completion. Check logs and retry.';
            $payload['completed_at'] = $payload['completed_at'] ?? now()->toIso8601String();
        }

        if (($payload['success'] ?? null) === true && !empty($payload['output_relative_path'])) {
            $outputPath = storage_path('app/' . ltrim((string) $payload['output_relative_path'], '/'));
            if (is_file($outputPath)) {
                $payload['download_url'] = route('reports.viefund-daily-balance.download-latest');
            }
        }

        return response()->json($payload);
    }

    public function legacyDailyBalanceReportStatus(): JsonResponse
    {
        $lockFile = storage_path('app/reports/viefund-daily-balance-legacy.lock');
        $statusFile = storage_path('app/reports/viefund-daily-balance-legacy-status.json');
        $inProgress = file_exists($lockFile) && (time() - filemtime($lockFile)) < self::LOCK_TTL_SECONDS;
        $payload = [
            'inProgress' => $inProgress,
            'success' => null,
            'message' => $inProgress ? 'Legacy report in progress...' : 'Idle',
            'processed_days' => null,
            'total_days' => null,
            'progress_pct' => null,
            'download_url' => null,
        ];
        $parsed = file_exists($statusFile)
            ? json_decode((string) file_get_contents($statusFile), true)
            : null;
        if (is_array($parsed)) {
            $payload = array_merge($payload, $parsed);
            $payload['inProgress'] = $inProgress;
        }
        if (!$inProgress && is_array($parsed) && ($parsed['inProgress'] ?? false) && ($parsed['success'] ?? null) === null) {
            $payload['success'] = false;
            $payload['message'] = 'Legacy report stopped before completion. Check logs and retry.';
        }
        if (($payload['success'] ?? null) === true && !empty($payload['output_relative_path'])) {
            $outputPath = storage_path('app/' . ltrim((string) $payload['output_relative_path'], '/'));
            if (is_file($outputPath)) {
                $payload['download_url'] = route('reports.viefund-daily-balance-legacy.download-latest');
            }
        }

        return response()->json($payload);
    }

    public function customerBalancesReportStatus(): JsonResponse
    {
        $lockFile = storage_path('app/reports/viefund-customer-balances.lock');
        $statusFile = storage_path('app/reports/viefund-customer-balances-status.json');

        $inProgress = file_exists($lockFile) && (time() - filemtime($lockFile)) < self::LOCK_TTL_SECONDS;

        $payload = [
            'inProgress' => $inProgress,
            'success' => null,
            'message' => $inProgress ? 'Report in progress...' : 'Idle',
            'processed_accounts' => null,
            'total_accounts' => null,
            'progress_pct' => null,
            'download_url' => null,
            'updated_at' => null,
            'started_at' => null,
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
            $payload['message'] = 'Report stopped before reporting completion. Check logs and retry.';
            $payload['completed_at'] = $payload['completed_at'] ?? now()->toIso8601String();
        }

        if (($payload['success'] ?? null) === true && !empty($payload['output_relative_path'])) {
            $outputPath = storage_path('app/' . ltrim((string) $payload['output_relative_path'], '/'));
            if (is_file($outputPath)) {
                $payload['download_url'] = route('reports.viefund-customer-balances.download-latest');
            }
        }

        return response()->json($payload);
    }

    public function downloadLatestReport(): BinaryFileResponse|RedirectResponse
    {
        $statusFile = storage_path('app/reports/viefund-daily-balance-status.json');
        if (!file_exists($statusFile)) {
            return redirect()->route('reports.index')->with('report_error', 'No report output found to download.');
        }

        $parsed = json_decode((string) file_get_contents($statusFile), true);
        $relativePath = is_array($parsed) ? ($parsed['output_relative_path'] ?? null) : null;
        if (!$relativePath) {
            return redirect()->route('reports.index')->with('report_error', 'No report output found to download.');
        }

        $relativePath = ltrim((string) $relativePath, '/');
        if (!str_starts_with($relativePath, 'reports/')) {
            return redirect()->route('reports.index')->with('report_error', 'Invalid report output path.');
        }

        $absolutePath = storage_path('app/' . $relativePath);
        if (!is_file($absolutePath)) {
            return redirect()->route('reports.index')->with('report_error', 'Report file was not found.');
        }

        return response()->download($absolutePath, basename($absolutePath));
    }

    public function downloadLatestLegacyDailyBalanceReport(): BinaryFileResponse|RedirectResponse
    {
        $statusFile = storage_path('app/reports/viefund-daily-balance-legacy-status.json');
        if (!file_exists($statusFile)) {
            return redirect()->route('reports.index')->with('legacy_report_error', 'No legacy report output found.');
        }
        $parsed = json_decode((string) file_get_contents($statusFile), true);
        $relativePath = is_array($parsed) ? ($parsed['output_relative_path'] ?? null) : null;
        $relativePath = $relativePath ? ltrim((string) $relativePath, '/') : null;
        if (!$relativePath || !str_starts_with($relativePath, 'reports/')) {
            return redirect()->route('reports.index')->with('legacy_report_error', 'Invalid legacy report output path.');
        }
        $absolutePath = storage_path('app/' . $relativePath);
        if (!is_file($absolutePath)) {
            return redirect()->route('reports.index')->with('legacy_report_error', 'Legacy report file was not found.');
        }

        return response()->download($absolutePath, basename($absolutePath));
    }

    public function downloadLatestCustomerBalancesReport(): BinaryFileResponse|RedirectResponse
    {
        $statusFile = storage_path('app/reports/viefund-customer-balances-status.json');
        if (!file_exists($statusFile)) {
            return redirect()->route('reports.index')->with('customer_balance_report_error', 'No report output found to download.');
        }

        $parsed = json_decode((string) file_get_contents($statusFile), true);
        $relativePath = is_array($parsed) ? ($parsed['output_relative_path'] ?? null) : null;
        if (!$relativePath) {
            return redirect()->route('reports.index')->with('customer_balance_report_error', 'No report output found to download.');
        }

        $relativePath = ltrim((string) $relativePath, '/');
        if (!str_starts_with($relativePath, 'reports/')) {
            return redirect()->route('reports.index')->with('customer_balance_report_error', 'Invalid report output path.');
        }

        $absolutePath = storage_path('app/' . $relativePath);
        if (!is_file($absolutePath)) {
            return redirect()->route('reports.index')->with('customer_balance_report_error', 'Report file was not found.');
        }

        return response()->download($absolutePath, basename($absolutePath));
    }

    public function dismissLatestReport(): JsonResponse
    {
        $lockFile = storage_path('app/reports/viefund-daily-balance.lock');
        $statusFile = storage_path('app/reports/viefund-daily-balance-status.json');

        $hasLiveLock = file_exists($lockFile) && (time() - filemtime($lockFile)) < self::LOCK_TTL_SECONDS;
        if ($hasLiveLock) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot dismiss while report is still running.',
            ], 409);
        }

        $deletedOutput = false;
        $deletedStatus = false;

        if (file_exists($statusFile)) {
            $parsed = json_decode((string) file_get_contents($statusFile), true);
            $relativePath = is_array($parsed) ? ($parsed['output_relative_path'] ?? null) : null;

            if ($relativePath) {
                $relativePath = ltrim((string) $relativePath, '/');
                if (str_starts_with($relativePath, 'reports/')) {
                    $absolutePath = storage_path('app/' . $relativePath);
                    if (is_file($absolutePath)) {
                        $deletedOutput = @unlink($absolutePath);
                    }
                }
            }

            $deletedStatus = @unlink($statusFile);
        }

        return response()->json([
            'success' => true,
            'deleted_output' => $deletedOutput,
            'deleted_status' => $deletedStatus,
        ]);
    }

    public function dismissLatestLegacyDailyBalanceReport(): JsonResponse
    {
        $lockFile = storage_path('app/reports/viefund-daily-balance-legacy.lock');
        $statusFile = storage_path('app/reports/viefund-daily-balance-legacy-status.json');
        if (file_exists($lockFile) && (time() - filemtime($lockFile)) < self::LOCK_TTL_SECONDS) {
            return response()->json(['success' => false, 'message' => 'Cannot dismiss while the legacy report is running.'], 409);
        }
        $deletedOutput = false;
        if (file_exists($statusFile)) {
            $parsed = json_decode((string) file_get_contents($statusFile), true);
            $relativePath = is_array($parsed) ? ($parsed['output_relative_path'] ?? null) : null;
            if ($relativePath && str_starts_with(ltrim((string) $relativePath, '/'), 'reports/')) {
                $absolutePath = storage_path('app/' . ltrim((string) $relativePath, '/'));
                $deletedOutput = is_file($absolutePath) ? @unlink($absolutePath) : false;
            }
            @unlink($statusFile);
        }

        return response()->json(['success' => true, 'deleted_output' => $deletedOutput]);
    }

    public function dismissLatestCustomerBalancesReport(): JsonResponse
    {
        $lockFile = storage_path('app/reports/viefund-customer-balances.lock');
        $statusFile = storage_path('app/reports/viefund-customer-balances-status.json');

        $hasLiveLock = file_exists($lockFile) && (time() - filemtime($lockFile)) < self::LOCK_TTL_SECONDS;
        if ($hasLiveLock) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot dismiss while report is still running.',
            ], 409);
        }

        $deletedOutput = false;
        $deletedStatus = false;

        if (file_exists($statusFile)) {
            $parsed = json_decode((string) file_get_contents($statusFile), true);
            $relativePath = is_array($parsed) ? ($parsed['output_relative_path'] ?? null) : null;

            if ($relativePath) {
                $relativePath = ltrim((string) $relativePath, '/');
                if (str_starts_with($relativePath, 'reports/')) {
                    $absolutePath = storage_path('app/' . $relativePath);
                    if (is_file($absolutePath)) {
                        $deletedOutput = @unlink($absolutePath);
                    }
                }
            }

            $deletedStatus = @unlink($statusFile);
        }

        return response()->json([
            'success' => true,
            'deleted_output' => $deletedOutput,
            'deleted_status' => $deletedStatus,
        ]);
    }

    private function streamCsv(array $rows, string $filename, array $metadataRows): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows, $metadataRows) {
            $out = fopen('php://output', 'w');

            $sheetRows = $this->buildSheetRowsWithSideMetadata($rows, $metadataRows);
            foreach ($sheetRows as $sheetRow) {
                fputcsv($out, $sheetRow);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function buildSheetRowsWithSideMetadata(array $rows, array $metadataRows): array
    {
        $sheetRows = [];

        $dataRows = [];
        $dataRows[] = [
            'Report Date',
            'Transaction Count',
            'Daily Net Transactions',
            'Running Daily Balance',
        ];

        foreach ($rows as $row) {
            $dataRows[] = [
                $row['report_date'],
                number_format((int) $row['transaction_count']),
                $this->formatAccountingCurrency((float) $row['daily_net_transactions']),
                $this->formatAccountingCurrency((float) $row['running_daily_balance']),
            ];
        }

        $maxRows = max(count($dataRows), count($metadataRows));
        for ($i = 0; $i < $maxRows; $i++) {
            $dataPart = $dataRows[$i] ?? ['', '', '', ''];
            $metadataPart = $metadataRows[$i] ?? ['', ''];
            $sheetRows[] = [
                $dataPart[0],
                $dataPart[1],
                $dataPart[2],
                $dataPart[3],
                '',
                $metadataPart[0],
                $metadataPart[1],
            ];
        }

        return $sheetRows;
    }

    private function buildGenericSheetRowsWithSideMetadata(array $headers, array $rows, array $metadataRows): array
    {
        $sheetRows = [];

        $dataRows = [];
        $dataRows[] = $headers;
        foreach ($rows as $row) {
            $dataRows[] = $row;
        }

        $dataWidth = count($headers);
        $blankDataPart = array_fill(0, $dataWidth, '');
        $maxRows = max(count($dataRows), count($metadataRows));

        for ($i = 0; $i < $maxRows; $i++) {
            $dataPart = $dataRows[$i] ?? $blankDataPart;
            $metadataPart = $metadataRows[$i] ?? ['', ''];
            $sheetRows[] = [...$dataPart, '', $metadataPart[0], $metadataPart[1]];
        }

        return $sheetRows;
    }

    private function formatAccountingCurrency(float $amount): string
    {
        $formatted = '$' . number_format(abs($amount), 2, '.', ',');
        if ($amount < 0) {
            return '(' . $formatted . ')';
        }

        return $formatted;
    }

    private function normalizeCustomerBalanceCurrencyCode(string $value): ?string
    {
        $normalized = strtoupper(trim($value));

        return match ($normalized) {
            'CAD', '00' => '00',
            'USD', '01' => '01',
            '' => null,
            default => null,
        };
    }

    private function customerBalanceCurrencyLabelFromCode(?string $code): ?string
    {
        return match ($code) {
            '00' => 'CAD',
            '01' => 'USD',
            default => null,
        };
    }

    /**
     * Sanitize the submitted fund status IDs (0-6). Falls back to $default when
     * nothing valid is provided — the display GET passes Confirmed; report/export
     * POSTs pass Confirmed too so an all-unchecked submit never yields no rows.
     *
     * @return int[]
     */
    private function resolveStatuses(mixed $raw, ?array $default = null): array
    {
        $default ??= (array) config('viefund.default_fund_status', [6]);
        $values = array_map('intval', (array) $raw);
        $values = array_values(array_unique(array_filter(
            $values,
            fn($id) => array_key_exists($id, self::FUND_STATUS_OPTIONS)
        )));

        return $values ?: $default;
    }

    /**
     * Sanitize the submitted trust status names. Display GET passes the Settled
     * default; report/export POSTs pass an empty default so an all-unchecked
     * submit excludes trust entirely.
     *
     * @return string[]
     */
    private function resolveTrustStatuses(mixed $raw, ?array $default = null): array
    {
        $default ??= (array) config('viefund.default_trust_status', ['Settled']);
        $values = array_values(array_intersect(self::TRUST_STATUS_OPTIONS, (array) $raw));

        return $values ?: $default;
    }

    private function describeStatuses(array $statuses): string
    {
        $labels = array_map(fn($id) => self::FUND_STATUS_OPTIONS[$id] ?? $id, $statuses);

        return $labels ? implode(', ', $labels) : 'None';
    }
}
