<?php

namespace App\Http\Controllers;

use App\Models\RemoteVieFundCustomerTransaction;
use App\Services\VieFund\VieFundRemoteService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\View\View;

class RemoteVieFundController extends Controller
{
    public function __construct(
        private readonly VieFundRemoteService $remoteService
    ) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $trxTypesSelected  = array_values(array_filter((array) $request->query('filter_trx_type', [])));
        $directionSelected = array_values(array_intersect(
            (array) $request->query('filter_direction', []),
            ['debit', 'credit']
        ));
        $filters = array_filter([
            'customer_id'     => trim((string) $request->query('filter_customer_id', '')),
            'customer_name'   => trim((string) $request->query('filter_customer_name', '')),
            'account_id'      => trim((string) $request->query('filter_account_id', '')),
            'trx_id'          => trim((string) $request->query('filter_trx_id', '')),
            'source_id'       => trim((string) $request->query('filter_source_id', '')),
            'plan_account_id' => trim((string) $request->query('filter_plan_account_id', '')),
            'trx_type'        => $trxTypesSelected ?: null,
            'direction'       => $directionSelected ?: null,
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
            'syncInProgress'
        ));
    }

    public function export(Request $request): StreamedResponse
    {
        $format = $request->query('format', 'csv');
        $search = trim((string) $request->query('search', ''));
        $trxTypesSelected  = array_values(array_filter((array) $request->query('filter_trx_type', [])));
        $directionSelected = array_values(array_intersect(
            (array) $request->query('filter_direction', []),
            ['debit', 'credit']
        ));
        $filters = array_filter([
            'customer_id'     => trim((string) $request->query('filter_customer_id', '')),
            'customer_name'   => trim((string) $request->query('filter_customer_name', '')),
            'account_id'      => trim((string) $request->query('filter_account_id', '')),
            'trx_id'          => trim((string) $request->query('filter_trx_id', '')),
            'source_id'       => trim((string) $request->query('filter_source_id', '')),
            'trx_type'        => $trxTypesSelected ?: null,
            'direction'       => $directionSelected ?: null,
            'created_from'    => trim((string) $request->query('filter_created_from', '')),
            'created_to'      => trim((string) $request->query('filter_created_to', '')),
        ]);

        $rows = $this->remoteService->exportTransactions($search ?: null, $filters);

        $customerName = $filters['customer_name'] ?? 'export';
        $filename = 'viefund-transactions-' . preg_replace('/[^A-Za-z0-9_-]/', '-', $customerName);

        $headers = [
            'Txn ID',
            'Source ID',
            'Client Name',
            'Rep Code',
            'Plan Account ID',
            'Txn Type',
            'Txn Type Detail',
            'Order Status',
            'Created Date',
            'Trade Date',
            'Processing Date',
            'Settlement Date',
            'Amount -',
            'Amount +',
            'Balance',
            'Notes',
        ];

        if ($format === 'excel') {
            return $this->streamExcel($filename, $headers, $rows);
        }
        return $this->streamCsv($filename, $headers, $rows);
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
                $balance = $row->balance !== null ? (float) $row->balance : null;
                fputcsv($out, [
                    $row->trx_id,
                    $row->source_id,
                    trim($row->client_name),
                    $row->rep_code,
                    $row->plan_dealer_account_id,
                    $row->trx_type,
                    $row->cash_trx_type,
                    $row->order_status ?? '',
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

    private function streamExcel(string $filename, array $headers, $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
            echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
            echo '  xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n";
            echo '<Worksheet ss:Name="Transactions"><Table>' . "\n";

            // Header row
            echo '<Row>';
            foreach ($headers as $h) {
                echo '<Cell><Data ss:Type="String">' . htmlspecialchars($h, ENT_XML1) . '</Data></Cell>';
            }
            echo '</Row>' . "\n";

            foreach ($rows as $row) {
                $amount  = (float) $row->amount;
                $balance = $row->balance !== null ? (float) $row->balance : null;
                $cells = [
                    ['String', $row->trx_id],
                    ['String', $row->source_id],
                    ['String', trim($row->client_name)],
                    ['String', $row->rep_code],
                    ['String', $row->plan_dealer_account_id],
                    ['String', $row->trx_type],
                    ['String', $row->cash_trx_type],
                    ['String', $row->order_status ?? ''],
                    ['String', $row->created_date],
                    ['String', $row->trade_date],
                    ['String', $row->processing_date],
                    ['String', $row->settlement_date],
                    ['Number', $amount < 0  ? number_format(abs($amount), 2) : ''],
                    ['Number', $amount >= 0 ? number_format($amount, 2) : ''],
                    ['Number', $balance !== null ? number_format($balance, 2) : ''],
                    ['String', $row->notes ?? ''],
                ];
                echo '<Row>';
                foreach ($cells as [$type, $val]) {
                    $safeVal = htmlspecialchars((string) $val, ENT_XML1);
                    echo "<Cell><Data ss:Type=\"{$type}\">{$safeVal}</Data></Cell>";
                }
                echo '</Row>' . "\n";
            }

            echo '</Table></Worksheet></Workbook>';
        }, $filename . '.xls', ['Content-Type' => 'application/vnd.ms-excel; charset=UTF-8']);
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

        return response()->json(['inProgress' => $inProgress, 'syncNeeded' => $syncNeeded]);
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
}
