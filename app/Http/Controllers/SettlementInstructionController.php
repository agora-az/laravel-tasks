<?php

namespace App\Http\Controllers;

use App\Exports\SettlementInstructionsWorkbookExport;
use App\Models\SettlementInstruction;
use App\Models\SettlementInstructionSummary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SettlementInstructionController extends Controller
{
    private const LOCK_TTL_SECONDS = 14400;

    public function index(Request $request)
    {
        $validSources = ['fundserv_agra', 'ltm'];
        $sortableColumns = ['trade_date', 'settlement_date', 'create_date', 'side', 'gross_amount', 'net_amount', 'settlement_amount'];
        $activeSourceType = in_array((string) $request->source_type, $validSources, true)
            ? (string) $request->source_type
            : 'fundserv_agra';
        $sort = in_array((string) $request->sort, $sortableColumns, true)
            ? (string) $request->sort
            : 'settlement_date';
        $sortDir = strtolower((string) $request->sort_dir) === 'asc' ? 'asc' : 'desc';

        $entriesBySource = [];
        $totalsBySource = [];
        $summariesBySource = [];
        $summaryTotalsBySource = [];
        $sourceFilesBySource = [];

        foreach ($validSources as $sourceType) {
            $pageParam = $sourceType === 'fundserv_agra' ? 'agra_page' : 'ltm_page';
            $summaryPageParam = $sourceType === 'fundserv_agra' ? 'agra_summary_page' : 'ltm_summary_page';

            $entriesQuery = SettlementInstruction::query();
            $this->applyFilters($entriesQuery, $request, $sourceType);

            if (in_array($sort, ['gross_amount', 'net_amount', 'settlement_amount'], true)) {
                $entriesQuery->orderByRaw(
                    "case when side = 'BUY' then -{$sort} when side = 'SELL' then {$sort} else 0 end {$sortDir}"
                );
            } else {
                $entriesQuery->orderBy($sort, $sortDir);
            }

            $entriesBySource[$sourceType] = $entriesQuery
                ->orderByDesc('id')
                ->paginate(50, ['*'], $pageParam)
                ->appends($request->except([$pageParam]));

            $totalsQuery = SettlementInstruction::query();
            $this->applyFilters($totalsQuery, $request, $sourceType);
            $totalCount = (clone $totalsQuery)->count();

            $currencyTotals = $totalsQuery
                ->whereNotNull('currency')
                ->selectRaw(
                    "currency,
                     count(*) as total_count,
                     sum(case when side = 'BUY' then coalesce(settlement_amount, 0) else 0 end) as buy_settlement_total,
                     sum(case when side = 'SELL' then coalesce(settlement_amount, 0) else 0 end) as sell_settlement_total,
                     sum(case when side = 'SELL' then coalesce(settlement_amount, 0) when side = 'BUY' then -coalesce(settlement_amount, 0) else 0 end) as settlement_total,
                     sum(case when side = 'SELL' then coalesce(gross_amount, 0) when side = 'BUY' then -coalesce(gross_amount, 0) else 0 end) as gross_total"
                )
                ->groupBy('currency')
                ->orderBy('currency')
                ->get();

            $totalsBySource[$sourceType] = (object) [
                'total_count' => $totalCount,
                'currency_totals' => $currencyTotals,
            ];

            $summaryIdQuery = SettlementInstruction::query();
            $this->applyFilters($summaryIdQuery, $request, $sourceType);

            $summaryIds = $summaryIdQuery
                ->whereNotNull('settlement_instruction_summary_id')
                ->select('settlement_instruction_summary_id')
                ->distinct()
                ->pluck('settlement_instruction_summary_id');

            $summaryTotalsBySource[$sourceType] = SettlementInstructionSummary::query()
                ->whereIn('id', $summaryIds)
                ->selectRaw('count(*) as summary_count, sum(record_count) as total_summary_records, sum(total_settlement_amount) as total_summary_settlement')
                ->first();

            $summariesBySource[$sourceType] = SettlementInstructionSummary::query()
                ->whereIn('id', $summaryIds)
                ->orderByDesc('max_settlement_date')
                ->orderByDesc('id')
                ->paginate(25, ['*'], $summaryPageParam)
                ->appends($request->except([$summaryPageParam]));

            $summaryPageIds = $summariesBySource[$sourceType]->getCollection()->pluck('id');
            $summaryAmountsQuery = SettlementInstruction::query();
            $this->applyFilters($summaryAmountsQuery, $request, $sourceType);

            $summaryAmounts = $summaryAmountsQuery
                ->whereIn('settlement_instruction_summary_id', $summaryPageIds)
                ->whereNotNull('currency')
                ->selectRaw(
                    "settlement_instruction_summary_id,
                     currency,
                     count(*) as record_count,
                     sum(case when side = 'BUY' then coalesce(gross_amount, 0) else 0 end) as buy_amount,
                     sum(case when side = 'SELL' then coalesce(gross_amount, 0) else 0 end) as sell_amount,
                     sum(case when side = 'SWITCH' then coalesce(gross_amount, 0) else 0 end) as switch_amount,
                     sum(case when side = 'SELL' then coalesce(gross_amount, 0) when side = 'BUY' then -coalesce(gross_amount, 0) else 0 end) as net_transactions"
                )
                ->groupBy('settlement_instruction_summary_id', 'currency')
                ->orderBy('currency')
                ->get()
                ->groupBy('settlement_instruction_summary_id');

            $summariesBySource[$sourceType]->getCollection()->each(function ($summary) use ($summaryAmounts): void {
                $summary->setAttribute('currency_totals', $summaryAmounts->get($summary->id, collect()));
            });

            $sourceFilesBySource[$sourceType] = SettlementInstruction::query()
                ->where('source_type', $sourceType)
                ->select('source_file')
                ->distinct()
                ->orderByDesc('source_file')
                ->pluck('source_file')
                ->values()
                ->all();
        }

        $entries = $entriesBySource[$activeSourceType];
        $totals = $totalsBySource[$activeSourceType];
        $summaries = $summariesBySource[$activeSourceType];
        $summaryTotals = $summaryTotalsBySource[$activeSourceType];
        $sourceFiles = collect($sourceFilesBySource[$activeSourceType]);

        return view('settlement-instructions.index', compact(
            'activeSourceType',
            'sort',
            'sortDir',
            'entries',
            'totals',
            'summaries',
            'summaryTotals',
            'sourceFiles',
            'entriesBySource',
            'totalsBySource',
            'summariesBySource',
            'summaryTotalsBySource',
            'sourceFilesBySource'
        ));
    }

    public function export(Request $request): BinaryFileResponse
    {
        $rowsBySource = [];
        $summaryRowsBySource = [];

        foreach (['fundserv_agra', 'ltm'] as $sourceType) {
            $entriesQuery = SettlementInstruction::query();
            $this->applyFilters($entriesQuery, $request, $sourceType);

            $entries = $entriesQuery
                ->orderBy('settlement_date')
                ->orderBy('id')
                ->get();

            $rows = [[
                'Create Date',
                'Trade Date',
                'Settle Date',
                'Side',
                'Order ID',
                'Source ID',
                'Fund Account',
                'Fund ID',
                'Currency',
                'Gross',
                'Net',
                'Settle Amount',
                'Source File',
            ]];

            foreach ($entries as $entry) {
                $amountSign = $entry->side === 'BUY' ? -1 : 1;
                $fundId = $entry->side === 'SWITCH'
                    ? trim(($entry->switch_from_fund_id ?? '') . ' > ' . ($entry->switch_to_fund_id ?? ''))
                    : (string) ($entry->fund_id ?? '');

                $rows[] = [
                    $entry->create_date?->format('Y-m-d') ?? '',
                    $entry->trade_date?->format('Y-m-d') ?? '',
                    $entry->settlement_date?->format('Y-m-d') ?? '',
                    (string) ($entry->side ?? ''),
                    (string) ($entry->order_id ?? ''),
                    (string) ($entry->source_id ?? ''),
                    (string) ($entry->fund_account_id ?? ''),
                    $fundId,
                    (string) ($entry->currency ?? ''),
                    $entry->gross_amount !== null ? (float) $entry->gross_amount * $amountSign : null,
                    $entry->net_amount !== null ? (float) $entry->net_amount * $amountSign : null,
                    $entry->settlement_amount !== null ? (float) $entry->settlement_amount * $amountSign : null,
                    (string) $entry->source_file,
                ];
            }

            $rowsBySource[$sourceType] = $rows;

            $summaryQuery = SettlementInstruction::query();
            $this->applyFilters($summaryQuery, $request, $sourceType);

            $summaries = $summaryQuery
                ->selectRaw(
                    "source_file,
                     currency,
                     count(*) as record_count,
                     min(create_date) as min_create_date,
                     max(create_date) as max_create_date,
                     min(trade_date) as min_trade_date,
                     max(trade_date) as max_trade_date,
                     min(settlement_date) as min_settlement_date,
                     max(settlement_date) as max_settlement_date,
                     sum(case when side = 'BUY' then coalesce(gross_amount, 0) else 0 end) as buy_amount,
                     sum(case when side = 'SELL' then coalesce(gross_amount, 0) else 0 end) as sell_amount,
                     sum(case when side = 'SWITCH' then coalesce(gross_amount, 0) else 0 end) as switch_amount,
                     sum(case when side = 'SELL' then coalesce(gross_amount, 0) when side = 'BUY' then -coalesce(gross_amount, 0) else 0 end) as net_transactions"
                )
                ->groupBy('source_file', 'currency')
                ->orderBy('source_file')
                ->orderBy('currency')
                ->get();

            $summaryRows = [[
                'Source File',
                'Records',
                'Create Date From',
                'Create Date To',
                'Trade Date From',
                'Trade Date To',
                'Settle Date From',
                'Settle Date To',
                'Currency',
                'Buy Amount',
                'Sell Amount',
            ]];

            if ($sourceType === 'fundserv_agra') {
                $summaryRows[0][] = 'Switch Amount';
            }

            $summaryRows[0][] = 'Net Transactions';

            foreach ($summaries as $summary) {
                $summaryRow = [
                    (string) $summary->source_file,
                    (int) $summary->record_count,
                    (string) ($summary->min_create_date ?? ''),
                    (string) ($summary->max_create_date ?? ''),
                    (string) ($summary->min_trade_date ?? ''),
                    (string) ($summary->max_trade_date ?? ''),
                    (string) ($summary->min_settlement_date ?? ''),
                    (string) ($summary->max_settlement_date ?? ''),
                    (string) ($summary->currency ?? ''),
                    (float) $summary->buy_amount * -1,
                    (float) $summary->sell_amount,
                ];

                if ($sourceType === 'fundserv_agra') {
                    $summaryRow[] = (float) $summary->switch_amount;
                }

                $summaryRow[] = (float) $summary->net_transactions;
                $summaryRows[] = $summaryRow;
            }

            $summaryRowsBySource[$sourceType] = $summaryRows;
        }

        return Excel::download(
            new SettlementInstructionsWorkbookExport(
                $rowsBySource['fundserv_agra'],
                $summaryRowsBySource['fundserv_agra'],
                $rowsBySource['ltm'],
                $summaryRowsBySource['ltm']
            ),
            'settlement_instructions_' . now()->format('Ymd_His') . '.xlsx',
            ExcelWriter::XLSX
        );
    }

    public function sync(Request $request): RedirectResponse
    {
        $artisanPath = base_path('artisan');
        $lockFile = storage_path('app/settlement-instructions-sync.lock');
        $statusFile = storage_path('app/settlement-instructions-sync-status.json');
        $logPath = storage_path('logs/settlement-instructions-sync.log');
        $phpPath = env('PHP_PATH', '/usr/local/bin/php');
        $dryRun = filter_var(env('SETTLEMENT_SFTP_DRY_RUN', false), FILTER_VALIDATE_BOOLEAN);

        $hasLiveLock = file_exists($lockFile) && (time() - filemtime($lockFile)) < self::LOCK_TTL_SECONDS;
        if ($hasLiveLock) {
            return redirect()
                ->route('settlement-instructions.index', $request->except('_token'))
                ->with('sync_error', 'An FSP sync is already in progress.');
        }

        $runId = (string) \Illuminate\Support\Str::uuid();
        $startedAt = now()->toIso8601String();
        $request->session()->put('fsp_sync_run_id', $runId);
        file_put_contents($lockFile, date('c'));
        file_put_contents($statusFile, json_encode([
            'run_id' => $runId,
            'trigger' => 'Sync button',
            'inProgress' => true,
            'success' => null,
            'dry_run' => $dryRun,
            'message' => $dryRun ? 'FSP dry run queued...' : 'FSP sync queued...',
            'processed_files' => 0,
            'total_files' => null,
            'progress_pct' => 0,
            'started_at' => $startedAt,
            'updated_at' => $startedAt,
        ], JSON_PRETTY_PRINT));

        $command = sprintf(
            '%s %s settlement:sync-instructions --lock-file=%s --status-file=%s --run-id=%s --trigger=%s%s >> %s 2>&1 &',
            escapeshellarg($phpPath),
            escapeshellarg($artisanPath),
            escapeshellarg($lockFile),
            escapeshellarg($statusFile),
            escapeshellarg($runId),
            escapeshellarg('Sync button'),
            $dryRun ? ' --dry-run' : '',
            escapeshellarg($logPath)
        );

        Log::info('Dispatching settlement:sync-instructions in background: ' . $command);

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
            ->route('settlement-instructions.index', $request->except('_token'))
            ->with('sync_success', $dryRun
                ? 'FSP dry run started. No files will be downloaded or imported.'
                : 'FSP sync started. Settlement instruction files will be downloaded and imported in the background.');
    }

    public function syncStatus(Request $request): JsonResponse
    {
        $lockFile = storage_path('app/settlement-instructions-sync.lock');
        $statusFile = storage_path('app/settlement-instructions-sync-status.json');
        $inProgress = file_exists($lockFile) && (time() - filemtime($lockFile)) < self::LOCK_TTL_SECONDS;

        $payload = [
            'inProgress' => $inProgress,
            'success' => null,
            'message' => $inProgress ? 'FSP sync in progress...' : 'Idle',
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

        if (!$inProgress && is_array($parsed) && ($parsed['inProgress'] ?? false) && ($parsed['success'] ?? null) === null) {
            $payload['success'] = false;
            $payload['message'] = 'FSP sync stopped before reporting completion. Check the settlement sync log and retry.';
            $payload['completed_at'] = $payload['completed_at'] ?? now()->toIso8601String();
        }
        $payload['initiatedByCurrentSession'] = isset($payload['run_id'])
            && hash_equals((string) $payload['run_id'], (string) $request->session()->get('fsp_sync_run_id', ''));

        return response()->json($payload);
    }

    private function applyFilters(Builder $query, Request $request, string $activeSourceType): void
    {
        $query->where('source_type', $activeSourceType);

        if ($request->filled('source_file')) {
            $query->where('source_file', (string) $request->source_file);
        }
        if ($request->filled('side')) {
            $query->where('side', strtoupper((string) $request->side));
        }
        if ($request->filled('currency')) {
            $currency = strtoupper((string) $request->currency);
            if (in_array($currency, ['CAD', 'USD'], true)) {
                $query->where('currency', $currency);
            }
        }
        if ($request->filled('date_from')) {
            $query->where('settlement_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('settlement_date', '<=', $request->date_to);
        }
        if ($request->filled('search')) {
            $term = '%' . (string) $request->search . '%';
            $query->where(function (Builder $nested) use ($term) {
                $nested->where('order_id', 'like', $term)
                    ->orWhere('source_id', 'like', $term)
                    ->orWhere('fund_account_id', 'like', $term)
                    ->orWhere('dealer_account_id', 'like', $term)
                    ->orWhere('intermediary_account_id', 'like', $term);
            });
        }
    }
}
