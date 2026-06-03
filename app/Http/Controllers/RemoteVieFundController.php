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
            (array) $request->query('filter_direction', []), ['debit', 'credit']
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
        $connectionError = null;
        $transactions    = null;
        $totalRecords    = 0;
        $syncNeeded      = false;
        $localBalances   = collect();
        $currentBalance  = null;
        $bannerName      = null;
        $availableTrxTypes = [];

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
            // Watermark check: compare local max cash_trx_id against remote max
            try {
                $schema    = env('VIEFUND_DB_SCHEMA', 'dbo');
                $localMax  = (int) (RemoteVieFundCustomerTransaction::max('cash_trx_id') ?? 0);
                $remoteMax = (int) DB::connection('viefund_sqlsrv')
                    ->table("{$schema}.UB_FundTrxCash as fc")
                    ->join("{$schema}.UB_CashTrx as ct", 'ct.ID', '=', 'fc.iCashTrxID')
                    ->max('ct.ID');
                $syncNeeded = $remoteMax > $localMax;
            } catch (Exception) {
                // Suppress silently — main query will surface any real connection error
            }
        }

        try {
            $totalRecords = $this->remoteService->countTransactions();
            $transactions = $this->remoteService->fetchTransactions($search ?: null, $filters);
            $availableTrxTypes = $this->remoteService->fetchDistinctTrxTypes($filters);

            // Batch-lookup pre-computed running balances from the local slim table.
            // One extra local query per page — O(1) per row via primary key.
            if ($transactions && $transactions->count() > 0) {
                $cashTrxIds = $transactions->getCollection()
                    ->pluck('cash_trx_id')
                    ->filter()
                    ->values();

                if ($cashTrxIds->isNotEmpty()) {
                    $localBalances = RemoteVieFundCustomerTransaction::whereIn('cash_trx_id', $cashTrxIds)
                        ->pluck('running_balance', 'cash_trx_id');
                }
            }

            // Banner name + current balance for focused (customer or plan account) mode
            if (!empty($filters['customer_id'])) {
                $bannerName     = $filters['customer_name'] ?? null;
                $currentBalance = RemoteVieFundCustomerTransaction::where('viefund_customer_id', $filters['customer_id'])
                    ->orderBy('cash_trx_id', 'desc')
                    ->value('running_balance');
            } elseif (!empty($filters['account_id'])) {
                $customer = $this->remoteService->getCustomerForPlanAccount($filters['account_id']);
                if ($customer) {
                    $bannerName     = $customer['customer_name'];
                    $currentBalance = RemoteVieFundCustomerTransaction::where('viefund_customer_id', $customer['customer_id'])
                        ->orderBy('cash_trx_id', 'desc')
                        ->value('running_balance');
                }
            }
        } catch (Exception $e) {
            $connectionError = 'Could not connect to the remote VieFund database: ' . $e->getMessage();
        }

        return view('remote-viefund.index', compact(
            'transactions', 'totalRecords', 'search', 'filters', 'connectionError',
            'localBalances', 'currentBalance', 'bannerName', 'availableTrxTypes',
            'syncNeeded', 'syncInProgress'
        ));
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
}
