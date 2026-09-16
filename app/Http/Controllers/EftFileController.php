<?php

namespace App\Http\Controllers;

use App\Exports\EftFilesWorkbookExport;
use App\Models\BankEftFile;
use App\Services\VieFund\Repositories\SqlServerEftRemoteRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EftFileController extends Controller
{
    private const LOCK_TTL_SECONDS = 14400;

    public function __construct(
        private readonly SqlServerEftRemoteRepository $repository
    ) {}

    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $fileSort = $this->sort($request, 'file_sort', [
            'created_at', 'effective_date', 'type', 'status', 'total_amount', 'item_count', 'sequence',
            'bank_count_variance', 'bank_amount_variance',
        ], 'created_at');
        $itemSort = $this->sort($request, 'item_sort', [
            'created_at', 'effective_date', 'type', 'status', 'amount', 'holder', 'file',
        ], 'created_at');
        $fileSortDir = $this->direction($request, 'file_sort_dir');
        $itemSortDir = $this->direction($request, 'item_sort_dir');
        $activeTab = in_array($request->query('tab'), ['files', 'items', 'missing', 'excluded'], true)
            ? (string) $request->query('tab')
            : 'files';
        $drilldownDateLabel = null;
        $drilldownDate = trim((string) $request->query('drilldown_date', ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $drilldownDate)) {
            try {
                $drilldownDateLabel = Carbon::createFromFormat('Y-m-d', $drilldownDate)->format('F j, Y');
            } catch (\Throwable) {
                $drilldownDate = '';
            }
        } else {
            $drilldownDate = '';
        }

        if (in_array($fileSort, ['bank_count_variance', 'bank_amount_variance'], true)) {
            $files = $this->bankSortedFiles($filters, $fileSort, $fileSortDir, $request);
        } else {
            $files = $this->repository->paginateFiles($filters, $fileSort, $fileSortDir);
            $this->attachBankEftReconciliation($files->getCollection());
        }
        $items = match ($activeTab) {
            'missing' => $this->repository->missingCandidates($filters, $drilldownDate ?: null),
            'excluded' => $this->repository->excludedCandidates($filters, $drilldownDate ?: null),
            default => $this->repository->paginateItems($filters, $itemSort, $itemSortDir),
        };
        $totals = $this->repository->totals($filters);
        $totalsByType = $this->repository->totalsByType($filters);
        $types = $this->repository->types();
        $fileStatuses = $this->repository->fileStatuses();
        $itemStatuses = $this->repository->itemStatuses();
        $selectedFile = $filters['file_id'] !== '' ? $files->first() : null;
        return view('eft-files.index', compact(
            'activeTab',
            'fileSort',
            'fileSortDir',
            'itemSort',
            'itemSortDir',
            'filters',
            'files',
            'items',
            'totals',
            'totalsByType',
            'types',
            'fileStatuses',
            'itemStatuses',
            'selectedFile',
            'drilldownDate',
            'drilldownDateLabel'
        ));
    }

    public function export(Request $request): BinaryFileResponse
    {
        $filters = $this->filters($request);
        $fileRows = [[
            'File ID',
            'Created',
            'Effective Date',
            'Type ID',
            'Type',
            'Status ID',
            'Trust Account',
            'Sequence',
            'Total Amount',
            'Item Count',
            'Bank Record Count',
            'Bank Total Amount',
            'Count Variance',
            'Bank Amount Variance',
            'Option ID',
            'File Name',
            'Notes',
        ]];

        $exportFiles = $this->repository->exportFiles($filters);
        $this->attachBankEftReconciliation($exportFiles);
        foreach ($exportFiles as $file) {
            $fileRows[] = [
                (int) $file->id,
                $this->dateTime($file->created_at),
                $this->date($file->effective_date),
                $file->type_id !== null ? (int) $file->type_id : null,
                (string) ($file->type_name ?? ''),
                $file->status_id !== null ? (int) $file->status_id : null,
                match ((int) $file->trust_bank_account_id) { 1 => 'AGRP', 2 => 'AGRA', default => $file->trust_bank_account_id },
                $file->sequence_number !== null ? (int) $file->sequence_number : null,
                $file->total_amount !== null ? (float) $file->total_amount : null,
                (int) ($file->item_count ?? 0),
                $file->bank_record_count,
                $file->bank_total_amount,
                $file->bank_count_variance,
                $file->bank_amount_variance,
                $file->option_id !== null ? (int) $file->option_id : null,
                (string) ($file->file_name ?? ''),
                (string) ($file->notes ?? ''),
            ];
        }

        $itemHeader = [
            'Item ID',
            'File ID',
            'Created',
            'Effective Date',
            'Trade Date',
            'Settlement Date',
            'Type ID',
            'Type',
            'Linked ID',
            'Status ID',
            'Amount',
            'Holder',
            'Holder ID',
            'Source Code',
            'Source',
            'File Name',
            'Notes',
        ];
        $itemRows = [$itemHeader];

        foreach ($this->repository->exportItems($filters) as $item) {
            $itemRows[] = $this->itemExportRow($item);
        }

        $otherSettlementDateRows = [$itemHeader];
        $otherSettlementDateSubtotalRows = [];
        $otherSettlementDateOutlineGroups = [];
        $drilldownDate = $this->validDate((string) $request->query('drilldown_date', ''));
        $candidateGroups = $this->repository->missingCandidates($filters, $drilldownDate)
            ->groupBy(fn($item) => $this->date($item->effective_date));
        foreach ($candidateGroups as $effectiveDate => $groupItems) {
            $groupStartRow = count($otherSettlementDateRows) + 1;
            foreach ($groupItems as $item) {
                $otherSettlementDateRows[] = $this->itemExportRow($item);
            }
            $groupEndRow = count($otherSettlementDateRows);
            $groupNetTotal = $groupItems->sum(fn($item) => (int) $item->type_id === 10
                ? (float) $item->amount
                : -(float) $item->amount);
            $subtotalRow = array_fill(0, count($itemHeader), null);
            $subtotalRow[0] = "{$effectiveDate} Net Total";
            $subtotalRow[10] = $groupNetTotal;
            $otherSettlementDateRows[] = $subtotalRow;
            $subtotalRowNumber = count($otherSettlementDateRows);
            $otherSettlementDateSubtotalRows[] = $subtotalRowNumber;
            $otherSettlementDateOutlineGroups[] = [$groupStartRow, $groupEndRow];
        }

        return Excel::download(
            new EftFilesWorkbookExport(
                $fileRows,
                $itemRows,
                $otherSettlementDateRows,
                $otherSettlementDateSubtotalRows,
                $otherSettlementDateOutlineGroups
            ),
            'eft-files-' . now()->format('Ymd-His') . '.xlsx',
            ExcelWriter::XLSX
        );
    }

    public function sync(Request $request): RedirectResponse
    {
        $lockFile = storage_path('app/bank-eft-sync.lock');
        $statusFile = storage_path('app/bank-eft-sync-status.json');
        if (file_exists($lockFile) && time() - filemtime($lockFile) < self::LOCK_TTL_SECONDS) {
            return redirect()->route('eft-files.index', $request->except('_token'))
                ->with('sync_error', 'A bank EFT file sync is already in progress.');
        }

        $runId = (string) \Illuminate\Support\Str::uuid();
        $startedAt = now()->toIso8601String();
        $request->session()->put('bank_eft_sync_run_id', $runId);
        file_put_contents($lockFile, date('c'));
        file_put_contents($statusFile, json_encode([
            'run_id' => $runId,
            'trigger' => 'Sync button',
            'started_at' => $startedAt,
            'inProgress' => true,
            'success' => null,
            'message' => 'Bank EFT sync queued...',
            'updated_at' => $startedAt,
        ], JSON_PRETTY_PRINT));
        $command = sprintf(
            '%s %s bank:sync-eft-files --lock-file=%s --status-file=%s --run-id=%s >> %s 2>&1 &',
            escapeshellarg(env('PHP_PATH', '/usr/local/bin/php')),
            escapeshellarg(base_path('artisan')),
            escapeshellarg($lockFile),
            escapeshellarg($statusFile),
            escapeshellarg($runId),
            escapeshellarg(storage_path('logs/bank-eft-sync.log'))
        );
        Log::info('Dispatching bank:sync-eft-files in background: ' . $command);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (is_resource($process)) {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
        }

        return redirect()->route('eft-files.index', $request->except('_token'))
            ->with('sync_success', 'Bank EFT sync started. Unprocessed files from /EFT_Files will be downloaded and imported.');
    }

    public function syncStatus(Request $request): JsonResponse
    {
        $lockFile = storage_path('app/bank-eft-sync.lock');
        $statusFile = storage_path('app/bank-eft-sync-status.json');
        $inProgress = file_exists($lockFile) && time() - filemtime($lockFile) < self::LOCK_TTL_SECONDS;
        $payload = ['inProgress' => $inProgress, 'success' => null, 'message' => $inProgress ? 'Bank EFT sync in progress...' : 'Idle'];
        if (file_exists($statusFile)) {
            $stored = json_decode(file_get_contents($statusFile) ?: '{}', true);
            if (is_array($stored)) {
                $payload = array_merge($payload, $stored, ['inProgress' => $inProgress]);
            }
        }
        $payload['initiatedByCurrentSession'] = isset($payload['run_id'])
            && hash_equals((string) $payload['run_id'], (string) $request->session()->get('bank_eft_sync_run_id', ''));

        return response()->json($payload);
    }

    public function bankRecords(Request $request, int $sequence, string $date)
    {
        abort_unless($this->validDate($date), 404);
        $mode = $request->query('mode') === 'variance' ? 'variance' : 'all';
        $bankFiles = BankEftFile::query()
            ->where('sequence_number', $sequence)
            ->whereDate('file_date', $date)
            ->with(['transactions' => fn($query) => $query->orderBy('line_number')->orderBy('segment_number')])
            ->orderBy('source_file')
            ->get();
        abort_if($bankFiles->isEmpty(), 404, 'No imported bank EFT file was found for this sequence and date.');

        $eftItems = $this->repository->itemsForSequenceDate($sequence, $date);
        $eftMatchCounts = $eftItems->countBy(fn($item) => $this->bankMatchKey($item->holder_id, $item->amount));
        $bankRecords = $bankFiles->flatMap->transactions->map(function ($record) use ($eftMatchCounts) {
            $key = $this->bankMatchKey($record->holder_id, $record->amount);
            $record->matches_eft = (int) $eftMatchCounts->get($key, 0) > 0;
            if ($record->matches_eft) {
                $eftMatchCounts->put($key, (int) $eftMatchCounts->get($key) - 1);
            }

            return $record;
        })->values();
        $unmatchedBankCount = $bankRecords->where('matches_eft', false)->count();
        $unmatchedEftCount = $eftMatchCounts->sum();
        $displayRecords = $mode === 'variance'
            ? $bankRecords->where('matches_eft', false)->values()
            : $bankRecords;
        $bankTotal = $bankRecords->sum(fn($record) => (float) $record->amount);
        $eftTotal = $eftItems->sum(fn($item) => (float) $item->amount);

        return view('eft-files.bank-records', compact(
            'sequence',
            'date',
            'mode',
            'bankFiles',
            'displayRecords',
            'bankTotal',
            'eftTotal',
            'eftItems',
            'unmatchedBankCount',
            'unmatchedEftCount'
        ));
    }

    private function attachBankEftReconciliation($files): void
    {
        $sequences = $files->pluck('sequence_number')->filter()->unique()->values();
        if ($sequences->isEmpty()) {
            return;
        }
        $bankFiles = BankEftFile::query()
            ->whereIn('sequence_number', $sequences)
            ->selectRaw('sequence_number, file_date, sum(parsed_transaction_count) bank_record_count, sum(parsed_total_amount) bank_total_amount')
            ->groupBy('sequence_number', 'file_date')
            ->get()
            ->keyBy(fn(BankEftFile $file) => $file->sequence_number . '|' . $file->file_date->toDateString());

        foreach ($files as $file) {
            $effectiveDate = $this->date($file->effective_date);
            $bank = $bankFiles->get((int) $file->sequence_number . '|' . $effectiveDate);
            $file->bank_record_count = $bank ? (int) $bank->bank_record_count : null;
            $file->bank_total_amount = $bank ? (float) $bank->bank_total_amount : null;
            $file->bank_count_variance = $bank ? (int) $file->item_count - (int) $bank->bank_record_count : null;
            $file->bank_amount_variance = $bank ? round((float) $file->item_amount - (float) $bank->bank_total_amount, 2) : null;
        }
    }

    private function bankSortedFiles(
        array $filters,
        string $sort,
        string $direction,
        Request $request
    ): LengthAwarePaginator {
        $allFiles = $this->repository->exportFiles($filters);
        $this->attachBankEftReconciliation($allFiles);

        $sorted = $allFiles
            ->filter(fn($file) => $file->bank_record_count !== null)
            ->sortBy($sort, SORT_REGULAR, $direction === 'desc')
            ->values()
            ->concat($allFiles->filter(fn($file) => $file->bank_record_count === null)->values());
        $perPage = 50;
        $page = LengthAwarePaginator::resolveCurrentPage('file_page');

        return (new LengthAwarePaginator(
            $sorted->forPage($page, $perPage)->values(),
            $sorted->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'pageName' => 'file_page']
        ))->appends($request->query());
    }

    private function bankMatchKey(mixed $holderId, mixed $amount): string
    {
        return mb_strtoupper(trim((string) $holderId), 'UTF-8') . '|' . (int) round((float) $amount * 100);
    }

    private function filters(Request $request): array
    {
        return [
            'file_id' => (string) $request->query('file_id', ''),
            'date_basis' => $request->query('date_basis') === 'effective' ? 'effective' : 'created',
            'date_from' => (string) $request->query('date_from', ''),
            'date_to' => (string) $request->query('date_to', ''),
            'type' => (string) $request->query('type', ''),
            'file_status' => (string) $request->query('file_status', ''),
            'item_status' => (string) $request->query('item_status', ''),
            'sequences' => collect(preg_split('/[,\s]+/', (string) $request->query('sequences', ''), -1, PREG_SPLIT_NO_EMPTY))
                ->map(fn($sequence) => trim((string) $sequence))
                ->filter(fn($sequence) => ctype_digit($sequence))
                ->map(fn($sequence) => (string) (int) $sequence)
                ->unique()
                ->take(500)
                ->implode(','),
            'file_search' => trim((string) $request->query('file_search', '')),
            'item_search' => trim((string) $request->query('item_search', '')),
        ];
    }

    private function sort(Request $request, string $key, array $allowed, string $default): string
    {
        $sort = (string) $request->query($key, $default);

        return in_array($sort, $allowed, true) ? $sort : $default;
    }

    private function direction(Request $request, string $key): string
    {
        return strtolower((string) $request->query($key, 'desc')) === 'asc' ? 'asc' : 'desc';
    }

    private function dateTime(mixed $value): string
    {
        return $value ? date('Y-m-d H:i:s', strtotime((string) $value)) : '';
    }

    private function date(mixed $value): string
    {
        return $value ? date('Y-m-d', strtotime((string) $value)) : '';
    }

    private function validDate(string $value): ?string
    {
        $value = trim($value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            Carbon::createFromFormat('Y-m-d', $value);

            return $value;
        } catch (\Throwable) {
            return null;
        }
    }

    private function itemExportRow(object $item): array
    {
        return [
            (int) $item->id,
            (int) $item->processing_id,
            $this->dateTime($item->created_at),
            $this->date($item->effective_date),
            $this->dateTime($item->trade_date),
            $this->dateTime($item->settlement_date),
            $item->type_id !== null ? (int) $item->type_id : null,
            (string) ($item->type_name ?? ''),
            $item->linked_id !== null ? (int) $item->linked_id : null,
            $item->status_id !== null ? (int) $item->status_id : null,
            $item->amount !== null ? (float) $item->amount : null,
            (string) ($item->holder_name ?? ''),
            (string) ($item->holder_id ?? ''),
            trim((string) ($item->source_code ?? '')),
            (string) ($item->source_name ?? ''),
            (string) ($item->file_name ?? ''),
            (string) ($item->notes ?? ''),
        ];
    }
}
