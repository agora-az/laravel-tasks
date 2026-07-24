<?php

namespace App\Http\Controllers;

use App\Models\BankStatementEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BankStatementEntryController extends Controller
{
    private const PARSER_VERSION = 'v2';
    private const LOCK_TTL_SECONDS = 14400;

    public function index(Request $request)
    {
        $query = BankStatementEntry::query()
            ->leftJoin(
                'bank_statement_entry_analyses as a',
                function ($join) {
                    $join->on('a.bank_statement_entry_id', '=', 'bank_statement_entries.id')
                        ->where('a.parser_version', self::PARSER_VERSION);
                }
            )
            ->select([
                'bank_statement_entries.id',
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

        // Filters
        if ($request->filled('date_from')) {
            $query->where('bank_statement_entries.value_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('bank_statement_entries.value_date', '<=', $request->date_to);
        }
        if ($request->filled('channel')) {
            $query->where('a.inferred_channel', $request->channel);
        }
        if ($request->filled('direction')) {
            $query->where('bank_statement_entries.credit_debit_indicator', $request->direction);
        }
        if ($request->filled('memo_type')) {
            $query->where('a.memo_type', 'like', '%' . $request->memo_type . '%');
        }
        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('bank_statement_entries.additional_info', 'like', '%' . $term . '%')
                    ->orWhere('a.counterparty', 'like', '%' . $term . '%')
                    ->orWhere('a.settlement_number', 'like', '%' . $term . '%')
                    ->orWhere('a.wire_payment_reference', 'like', '%' . $term . '%');
            });
        }

        // Sorting
        $sortField = in_array($request->sort, ['value_date', 'amount', 'inferred_channel', 'memo_type', 'counterparty'])
            ? $request->sort
            : 'value_date';
        $sortDir = $request->sort_dir === 'asc' ? 'asc' : 'desc';

        $columnMap = [
            'value_date' => 'bank_statement_entries.value_date',
            'amount' => 'bank_statement_entries.amount',
            'inferred_channel' => 'a.inferred_channel',
            'memo_type' => 'a.memo_type',
            'counterparty' => 'a.counterparty',
        ];
        $query->orderBy($columnMap[$sortField], $sortDir)->orderBy('bank_statement_entries.id');

        $entries = $query->paginate(50)->withQueryString();

        // Summary totals (respecting filters but not pagination)
        $totalsQuery = BankStatementEntry::query()
            ->leftJoin(
                'bank_statement_entry_analyses as a',
                function ($join) {
                    $join->on('a.bank_statement_entry_id', '=', 'bank_statement_entries.id')
                        ->where('a.parser_version', self::PARSER_VERSION);
                }
            );

        if ($request->filled('date_from')) {
            $totalsQuery->where('bank_statement_entries.value_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $totalsQuery->where('bank_statement_entries.value_date', '<=', $request->date_to);
        }
        if ($request->filled('channel')) {
            $totalsQuery->where('a.inferred_channel', $request->channel);
        }
        if ($request->filled('direction')) {
            $totalsQuery->where('bank_statement_entries.credit_debit_indicator', $request->direction);
        }
        if ($request->filled('memo_type')) {
            $totalsQuery->where('a.memo_type', 'like', '%' . $request->memo_type . '%');
        }
        if ($request->filled('search')) {
            $term = $request->search;
            $totalsQuery->where(function ($q) use ($term) {
                $q->where('bank_statement_entries.additional_info', 'like', '%' . $term . '%')
                    ->orWhere('a.counterparty', 'like', '%' . $term . '%')
                    ->orWhere('a.settlement_number', 'like', '%' . $term . '%')
                    ->orWhere('a.wire_payment_reference', 'like', '%' . $term . '%');
            });
        }

        $totals = $totalsQuery->selectRaw(
            'count(*) as total_count,
             sum(case when bank_statement_entries.credit_debit_indicator = "CRDT" then bank_statement_entries.amount else 0 end) as total_credits,
             sum(case when bank_statement_entries.credit_debit_indicator = "DBIT" then bank_statement_entries.amount else 0 end) as total_debits'
        )->first();

        // Filter options
        $channels = DB::table('bank_statement_entry_analyses')
            ->where('parser_version', self::PARSER_VERSION)
            ->whereNotNull('inferred_channel')
            ->distinct()
            ->orderBy('inferred_channel')
            ->pluck('inferred_channel');

        $memoTypes = DB::table('bank_statement_entry_analyses')
            ->where('parser_version', self::PARSER_VERSION)
            ->whereNotNull('memo_type')
            ->selectRaw('memo_type, count(*) as c')
            ->groupBy('memo_type')
            ->orderByDesc('c')
            ->pluck('memo_type');

        return view('bank-entries.index', compact(
            'entries',
            'totals',
            'channels',
            'memoTypes',
        ));
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
}
