<?php

namespace App\Http\Controllers;

use App\Exports\EftFilesWorkbookExport;
use App\Services\VieFund\Repositories\SqlServerEftRemoteRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EftFileController extends Controller
{
    public function __construct(
        private readonly SqlServerEftRemoteRepository $repository
    ) {}

    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $fileSort = $this->sort($request, 'file_sort', [
            'created_at', 'effective_date', 'type', 'status', 'total_amount', 'item_count', 'sequence',
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

        $files = $this->repository->paginateFiles($filters, $fileSort, $fileSortDir);
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
            'Trust Bank Account ID',
            'Sequence',
            'Total Amount',
            'Item Count',
            'Option ID',
            'File Name',
            'Notes',
        ]];

        foreach ($this->repository->exportFiles($filters) as $file) {
            $fileRows[] = [
                (int) $file->id,
                $this->dateTime($file->created_at),
                $this->date($file->effective_date),
                $file->type_id !== null ? (int) $file->type_id : null,
                (string) ($file->type_name ?? ''),
                $file->status_id !== null ? (int) $file->status_id : null,
                $file->trust_bank_account_id !== null ? (int) $file->trust_bank_account_id : null,
                $file->sequence_number !== null ? (int) $file->sequence_number : null,
                $file->total_amount !== null ? (float) $file->total_amount : null,
                (int) ($file->item_count ?? 0),
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
