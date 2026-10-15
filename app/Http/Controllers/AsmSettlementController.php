<?php

namespace App\Http\Controllers;

use App\Models\AsmSettlementSummary;
use App\Models\AsmSettlementTransaction;
use App\Models\Import;
use App\Services\Reconciliation\AsmVieFundMatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;

class AsmSettlementController extends Controller
{
    private const LOCK_TTL_SECONDS = 14400;

    public function index(Request $request)
    {
        $sortable = ['settlement_date', 'payment_id', 'payment_type', 'currency', 'settlement_amount', 'create_date'];
        $sort = in_array((string) $request->sort, $sortable, true)
            ? (string) $request->sort
            : 'settlement_date';
        $sortDir = strtolower((string) $request->sort_dir) === 'asc' ? 'asc' : 'desc';

        $transactionsQuery = AsmSettlementTransaction::query();
        $this->applyFilters($transactionsQuery, $request);

        $transactions = $transactionsQuery
            ->orderBy($sort, $sortDir)
            ->orderByDesc('id')
            ->paginate(50)
            ->appends($request->except('page'));

        $totalsQuery = AsmSettlementTransaction::query();
        $this->applyFilters($totalsQuery, $request);
        $recordCount = (clone $totalsQuery)->count();
        $currencyTotals = (clone $totalsQuery)
            ->selectRaw('currency, count(*) as record_count, sum(settlement_amount) as settlement_total')
            ->groupBy('currency')
            ->orderBy('currency')
            ->get();
        $paymentTypeCount = (clone $totalsQuery)
            ->whereNotNull('payment_type')
            ->distinct()
            ->count('payment_type');

        $hasFilters = $request->hasAny([
            'source_file',
            'currency',
            'payment_type',
            'date_from',
            'date_to',
            'search',
        ]);
        if ($hasFilters) {
            $summaryIds = (clone $totalsQuery)
                ->select('asm_settlement_summary_id')
                ->distinct()
                ->pluck('asm_settlement_summary_id');
            $summaryQuery = AsmSettlementSummary::query()->whereIn('id', $summaryIds);
        } else {
            $summaryQuery = AsmSettlementSummary::query();
        }

        $summaryTotals = (clone $summaryQuery)
            ->selectRaw('count(*) as file_count, sum(record_count) as record_count, sum(total_settlement_amount) as settlement_total')
            ->first();
        $summaries = $summaryQuery
            ->orderByDesc('max_settlement_date')
            ->orderByDesc('id')
            ->paginate(25, ['*'], 'summary_page')
            ->appends($request->except('summary_page'));

        $sourceFiles = AsmSettlementSummary::query()
            ->orderByDesc('source_file')
            ->pluck('source_file')
            ->all();
        $paymentTypes = AsmSettlementTransaction::query()
            ->whereNotNull('payment_type')
            ->select(['payment_type', 'payment_type_description'])
            ->distinct()
            ->orderBy('payment_type')
            ->get();

        return view('asm-settlements.index', [
            'transactions' => $transactions,
            'summaries' => $summaries,
            'summaryTotals' => $summaryTotals,
            'recordCount' => $recordCount,
            'currencyTotals' => $currencyTotals,
            'paymentTypeCount' => $paymentTypeCount,
            'sourceFiles' => $sourceFiles,
            'paymentTypes' => $paymentTypes,
            'sort' => $sort,
            'sortDir' => $sortDir,
        ]);
    }

    public function sync(Request $request): RedirectResponse
    {
        $lockFile = storage_path('app/asm-settlements-sync.lock');
        $statusFile = storage_path('app/asm-settlements-sync-status.json');
        $logPath = storage_path('logs/asm-settlements-sync.log');
        $hasLiveLock = file_exists($lockFile) && time() - filemtime($lockFile) < self::LOCK_TTL_SECONDS;

        if ($hasLiveLock) {
            return redirect()
                ->route('asm-settlements.index', $request->except('_token'))
                ->with('import_error', 'An ASM sync is already in progress.');
        }

        $runId = (string) Str::uuid();
        $startedAt = now()->toIso8601String();
        $request->session()->put('asm_sync_run_id', $runId);
        file_put_contents($lockFile, $startedAt);
        file_put_contents($statusFile, json_encode([
            'run_id' => $runId,
            'trigger' => 'Sync button',
            'inProgress' => true,
            'success' => null,
            'dry_run' => (bool) config('sftp.asm.dry_run', false),
            'message' => config('sftp.asm.dry_run', false) ? 'ASM dry run queued...' : 'ASM sync queued...',
            'phase' => 'queued',
            'current_file' => null,
            'processed_files' => 0,
            'total_files' => null,
            'progress_pct' => 0,
            'started_at' => $startedAt,
            'updated_at' => $startedAt,
        ], JSON_PRETTY_PRINT));

        $command = sprintf(
            '%s %s asm:sync-settlements --lock-file=%s --status-file=%s --run-id=%s --trigger=%s%s >> %s 2>&1 &',
            escapeshellarg($this->consolePhpBinary()),
            escapeshellarg(base_path('artisan')),
            escapeshellarg($lockFile),
            escapeshellarg($statusFile),
            escapeshellarg($runId),
            escapeshellarg('Sync button'),
            config('sftp.asm.dry_run', false) ? ' --dry-run' : '',
            escapeshellarg($logPath)
        );

        Log::info('Dispatching ASM workbook sync in background: ' . $command);
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

        return redirect()
            ->route('asm-settlements.index', $request->except('_token'))
            ->with(
                'import_success',
                config('sftp.asm.dry_run', false)
                    ? 'ASM dry run started. No files will be downloaded or imported.'
                    : 'ASM sync started. New SFTP workbooks will be downloaded, imported, and mapped in the background.'
            );
    }

    public function mapToVieFund(Request $request, AsmVieFundMatcher $matcher): RedirectResponse
    {
        try {
            $counts = $matcher->rebuild();
        } catch (\Throwable $e) {
            Log::error('Unable to map ASM settlements to VieFund.', ['exception' => $e]);

            return redirect()
                ->route('asm-settlements.index', $request->except('_token'))
                ->with('import_error', 'ASM mapping failed: ' . $e->getMessage());
        }

        return redirect()
            ->route('asm-settlements.index', $request->except('_token'))
            ->with('import_success', sprintf(
                'Mapped %s ASM rows: %s exact cash matches, %s account-only matches, and %s unresolved accounts.',
                number_format($counts['total']),
                number_format($counts[AsmVieFundMatcher::EXACT]),
                number_format($counts[AsmVieFundMatcher::ACCOUNT_ONLY]),
                number_format($counts[AsmVieFundMatcher::ACCOUNT_UNRESOLVED])
            ));
    }

    public function syncStatus(Request $request): JsonResponse
    {
        $lockFile = storage_path('app/asm-settlements-sync.lock');
        $statusFile = storage_path('app/asm-settlements-sync-status.json');
        $inProgress = file_exists($lockFile) && time() - filemtime($lockFile) < self::LOCK_TTL_SECONDS;
        $payload = [
            'inProgress' => $inProgress,
            'success' => null,
            'message' => $inProgress ? 'ASM sync in progress...' : 'Idle',
            'current_file' => null,
            'processed_files' => null,
            'total_files' => null,
            'progress_pct' => null,
            'started_at' => null,
            'updated_at' => null,
            'completed_at' => null,
        ];
        $parsed = null;

        if (file_exists($statusFile)) {
            $parsed = json_decode(file_get_contents($statusFile) ?: '{}', true);
            if (is_array($parsed)) {
                $payload = array_merge($payload, $parsed);
                $payload['inProgress'] = $inProgress;
            }
        }

        if (!$inProgress && empty($payload['completed_at'])) {
            $latestImport = Import::query()
                ->where('type', 'asm-settlements')
                ->where('status', 'completed')
                ->whereNotNull('import_completed_at')
                ->latest('import_completed_at')
                ->first();

            if ($latestImport) {
                $payload['success'] = true;
                $payload['message'] = sprintf(
                    'Imported %s rows from %s.',
                    number_format((int) $latestImport->imported_count),
                    $latestImport->filename
                );
                $payload['completed_at'] = $latestImport->import_completed_at->toIso8601String();
                $payload['trigger'] = 'ASM workbook import';
            }
        }

        if (!$inProgress && is_array($parsed) && ($parsed['inProgress'] ?? false) && ($parsed['success'] ?? null) === null) {
            $payload['success'] = false;
            $payload['message'] = 'ASM sync stopped before reporting completion. Check the ASM sync log and retry.';
            $payload['completed_at'] = $payload['completed_at'] ?? now()->toIso8601String();
        }
        $payload['initiatedByCurrentSession'] = isset($payload['run_id'])
            && hash_equals((string) $payload['run_id'], (string) $request->session()->get('asm_sync_run_id', ''));

        return response()->json($payload);
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        if ($request->filled('source_file')) {
            $query->where('source_file', (string) $request->source_file);
        }
        if ($request->filled('currency')) {
            $query->where('currency', strtoupper((string) $request->currency));
        }
        if ($request->filled('payment_type')) {
            $query->where('payment_type', (string) $request->payment_type);
        }
        if ($request->filled('date_from')) {
            $query->where('settlement_date', '>=', (string) $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('settlement_date', '<=', (string) $request->date_to);
        }
        if ($request->filled('search')) {
            $search = '%' . trim((string) $request->search) . '%';
            $query->where(function (Builder $nested) use ($search): void {
                $nested->where('payment_id', 'like', $search)
                    ->orWhere('sender_account_number', 'like', $search)
                    ->orWhere('receiver_dealer_account_number', 'like', $search)
                    ->orWhere('receiver_fund_company_account_number', 'like', $search)
                    ->orWhere('sender_company_name', 'like', $search)
                    ->orWhere('receiver_company_name', 'like', $search);
            });
        }
    }

    private function consolePhpBinary(): string
    {
        $configured = trim((string) config('app.php_cli_path', ''));
        $fpmSibling = str_contains(basename(PHP_BINARY), 'php-fpm')
            ? dirname(dirname(PHP_BINARY)) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'php'
            : null;
        $currentBinary = str_contains(basename(PHP_BINARY), 'php-fpm') ? null : PHP_BINARY;
        $candidates = array_filter([
            $configured,
            $fpmSibling,
            (new ExecutableFinder())->find('php'),
            $currentBinary,
            '/usr/local/bin/php',
            '/usr/bin/php',
        ]);

        foreach (array_unique($candidates) as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException('A PHP CLI executable could not be found. Configure PHP_PATH.');
    }
}
