<?php

namespace App\Http\Controllers;

use App\Services\VieFund\VieFundRemoteService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VieFundReportsController extends Controller
{
    private const LOCK_TTL_SECONDS = 43200;

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
        private readonly VieFundRemoteService $vieFundRemoteService
    ) {}

    public function index(Request $request): View
    {
        $selectedDateBasis = $request->query('date_basis', 'create_date');
        if (!isset(self::DATE_BASIS_OPTIONS[$selectedDateBasis])) {
            $selectedDateBasis = 'create_date';
        }

        $inceptionDates = [];
        foreach (array_keys(self::DATE_BASIS_OPTIONS) as $basisKey) {
            $inceptionDates[$basisKey] = $this->resolveInceptionDate($basisKey);
        }

        $defaultFrom = Carbon::today()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $defaultTo = Carbon::today()->subMonthNoOverflow()->endOfMonth()->toDateString();
        $selectedOutputOrder = $request->query('output_order', 'asc');
        if (!isset(self::OUTPUT_ORDER_OPTIONS[$selectedOutputOrder])) {
            $selectedOutputOrder = 'asc';
        }

        $selectedStatuses = $this->resolveStatuses($request->query('status'));
        $selectedTrustStatuses = $this->resolveTrustStatuses($request->query('trust_status'));
        $customerBalanceDateBasis = $request->query('customer_balance_date_basis', 'create_date');
        if (!isset(self::DATE_BASIS_OPTIONS[$customerBalanceDateBasis])) {
            $customerBalanceDateBasis = 'create_date';
        }

        $customerBalanceStatuses = $this->resolveStatuses($request->query('customer_balance_status'));
        $customerBalanceTrustStatuses = $this->resolveTrustStatuses($request->query('customer_balance_trust_status'));

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
            'selectedTrustStatuses' => $selectedTrustStatuses,
            'customerBalanceDate' => $request->query('customer_balance_date', Carbon::today()->toDateString()),
            'customerBalanceDateBasis' => $customerBalanceDateBasis,
            'customerBalanceStatuses' => $customerBalanceStatuses,
            'customerBalanceTrustStatuses' => $customerBalanceTrustStatuses,
            'inceptionDates' => $inceptionDates,
        ]);
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

    public function exportDailyBalance(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'date_basis' => ['required', 'in:' . implode(',', array_keys(self::DATE_BASIS_OPTIONS))],
            'output_order' => ['required', 'in:asc,desc'],
            'status' => ['sometimes', 'array'],
            'status.*' => ['integer', 'between:0,6'],
            'trust_status' => ['sometimes', 'array'],
            'trust_status.*' => ['in:' . implode(',', self::TRUST_STATUS_OPTIONS)],
            'format' => ['required', 'in:csv,excel'],
        ]);

        $dateFrom = Carbon::parse($validated['date_from'])->startOfDay();
        $dateTo = Carbon::parse($validated['date_to'])->startOfDay();
        $dateBasis = $validated['date_basis'];
        $outputOrder = $validated['output_order'];
        $statuses = $this->resolveStatuses($validated['status'] ?? null);
        $trustStatuses = $this->resolveTrustStatuses($validated['trust_status'] ?? null, []);
        $format = $validated['format'];

        $dailyTotals = $this->vieFundRemoteService->fetchDailyNetTotalsByDateColumn($dateFrom, $dateTo, $dateBasis, [
            'status_ids' => $statuses,
            'trust_status_names' => $trustStatuses,
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
        $trustLabel = $trustStatuses ? implode(', ', $trustStatuses) : 'Excluded';

        $metadataRows = [
            ['Report', 'VieFund Daily Net + Running Balance'],
            ['Date Basis', $dateBasisLabel],
            ['Date Range', $dateFrom->toDateString() . ' to ' . $dateTo->toDateString()],
            ['Output Order', $outputOrderLabel],
            ['Fund Statuses', $statusLabel],
            ['Trust Statuses', $trustLabel],
            ['Generated At', now()->toDateTimeString()],
            ['Final Balance', $this->formatAccountingCurrency($finalBalance)],
        ];

        if ($format === 'excel') {
            return $this->streamExcelTsv($rows, $baseName . '.xls', $metadataRows);
        }

        return $this->streamCsv($rows, $baseName . '.csv', $metadataRows);
    }

    public function runDailyBalanceReport(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'date_basis' => ['required', 'in:' . implode(',', array_keys(self::DATE_BASIS_OPTIONS))],
            'output_order' => ['required', 'in:asc,desc'],
            'status' => ['sometimes', 'array'],
            'status.*' => ['integer', 'between:0,6'],
            'trust_status' => ['sometimes', 'array'],
            'trust_status.*' => ['in:' . implode(',', self::TRUST_STATUS_OPTIONS)],
            'format' => ['required', 'in:csv,excel'],
        ]);

        $lockFile = storage_path('app/reports/viefund-daily-balance.lock');
        $statusFile = storage_path('app/reports/viefund-daily-balance-status.json');
        $logPath = storage_path('logs/viefund-daily-balance-report.log');
        $phpPath = env('PHP_PATH', '/usr/local/bin/php');
        $artisanPath = base_path('artisan');

        $hasLiveLock = file_exists($lockFile) && (time() - filemtime($lockFile)) < self::LOCK_TTL_SECONDS;
        if ($hasLiveLock) {
            return redirect()
                ->route('reports.index')
                ->with('report_error', 'A VieFund daily balance report is already running.');
        }

        $dateFrom = Carbon::parse($validated['date_from'])->toDateString();
        $dateTo = Carbon::parse($validated['date_to'])->toDateString();
        $dateBasis = $validated['date_basis'];
        $outputOrder = $validated['output_order'];
        $statuses = $this->resolveStatuses($validated['status'] ?? null);
        $trustStatuses = $this->resolveTrustStatuses($validated['trust_status'] ?? null, []);
        $format = $validated['format'];

        $extension = $format === 'excel' ? 'xls' : 'csv';
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
            'trust_status' => $trustStatuses ? implode(', ', $trustStatuses) : 'Excluded',
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

        $trustStatusArgs = implode(' ', array_map(
            fn(string $name): string => '--trust-status=' . escapeshellarg($name),
            $trustStatuses
        ));

        $command = sprintf(
            '%s %s report:viefund-daily-balance --date-from=%s --date-to=%s --date-basis=%s --output-order=%s %s %s --format=%s --output-file=%s --status-file=%s --lock-file=%s >> %s 2>&1 &',
            escapeshellarg($phpPath),
            escapeshellarg($artisanPath),
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

        return redirect()
            ->route('reports.index')
            ->with('report_success', 'VieFund daily balance report started in background.');
    }

    public function runCustomerBalancesReport(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'customer_balance_date' => ['required', 'date'],
            'customer_balance_date_basis' => ['required', 'in:' . implode(',', array_keys(self::DATE_BASIS_OPTIONS))],
            'customer_balance_status' => ['sometimes', 'array'],
            'customer_balance_status.*' => ['integer', 'between:0,6'],
            'customer_balance_trust_status' => ['sometimes', 'array'],
            'customer_balance_trust_status.*' => ['in:' . implode(',', self::TRUST_STATUS_OPTIONS)],
            'format' => ['required', 'in:csv,excel'],
        ]);

        $lockFile = storage_path('app/reports/viefund-customer-balances.lock');
        $statusFile = storage_path('app/reports/viefund-customer-balances-status.json');
        $logPath = storage_path('logs/viefund-customer-balances-report.log');
        $phpPath = env('PHP_PATH', '/usr/local/bin/php');
        $artisanPath = base_path('artisan');

        $hasLiveLock = file_exists($lockFile) && (time() - filemtime($lockFile)) < self::LOCK_TTL_SECONDS;
        if ($hasLiveLock) {
            return redirect()
                ->route('reports.index')
                ->with('customer_balance_report_error', 'A VieFund customer balances report is already running.');
        }

        $reportDate = Carbon::parse($validated['customer_balance_date'])->toDateString();
        $dateBasis = $validated['customer_balance_date_basis'];
        $statuses = $this->resolveStatuses($validated['customer_balance_status'] ?? null);
        $trustStatuses = $this->resolveTrustStatuses($validated['customer_balance_trust_status'] ?? null, []);
        $format = $validated['format'];

        $extension = $format === 'excel' ? 'xls' : 'csv';
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

        $command = sprintf(
            '%s %s report:viefund-customer-balances --report-date=%s --date-basis=%s %s %s --format=%s --output-file=%s --status-file=%s --lock-file=%s >> %s 2>&1 &',
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

    private function streamExcelTsv(array $rows, string $filename, array $metadataRows): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows, $metadataRows) {
            $out = fopen('php://output', 'w');

            $writeTsv = function (array $values) use ($out): void {
                $escaped = array_map(function ($value): string {
                    $text = (string) $value;
                    $text = str_replace(["\t", "\r", "\n"], ' ', $text);
                    return $text;
                }, $values);

                fwrite($out, implode("\t", $escaped) . "\r\n");
            };

            $sheetRows = $this->buildSheetRowsWithSideMetadata($rows, $metadataRows);
            foreach ($sheetRows as $sheetRow) {
                $writeTsv($sheetRow);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
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
