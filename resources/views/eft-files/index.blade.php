@extends('layouts.app')

@section('title', 'EFT Files')

@section('content')
@php
    $formatAmount = static function ($amount): string {
        if ($amount === null) {
            return '—';
        }

        $value = (float) $amount;
        $formatted = '$' . number_format(abs($value), 2);

        return $value < 0 ? '(' . $formatted . ')' : $formatted;
    };
    $formatDate = static fn($value): string => $value ? date('Y-m-d', strtotime((string) $value)) : '—';
    $formatTime = static fn($value): string => $value ? date('H:i:s', strtotime((string) $value)) : '--:--:--';
    $typeColors = [
        0 => ['bg' => '#ebf8ff', 'text' => '#2c5282'],
        1 => ['bg' => '#fff5f5', 'text' => '#9b2c2c'],
        2 => ['bg' => '#fffaf0', 'text' => '#975a16'],
        10 => ['bg' => '#f0fff4', 'text' => '#276749'],
    ];
    $typeLabels = [
        0 => 'Rep payment',
        10 => 'Client deposit',
        1 => 'Client payment',
        2 => 'Supplier payment',
    ];
    $statusColors = [
        0 => ['bg' => '#edf2f7', 'text' => '#4a5568'],
        1 => ['bg' => '#c6f6d5', 'text' => '#22543d'],
        2 => ['bg' => '#bee3f8', 'text' => '#2a4365'],
    ];
    $difference = (float) $totals->total_amount - (float) $totals->item_amount;
    $filterKeys = ['file_id', 'sequences', 'date_basis', 'date_from', 'date_to', 'type', 'file_status', 'item_status', 'file_search', 'item_search'];
    $dateBasisLabel = $filters['date_basis'] === 'effective' ? 'Effective' : 'Created';
    $tabUrl = static fn(string $tab): string => route('eft-files.index', array_merge(request()->except(['file_page', 'item_page']), ['tab' => $tab]));
    $fileSortUrl = static function (string $column) use ($fileSort, $fileSortDir): string {
        $direction = $fileSort === $column && $fileSortDir === 'asc' ? 'desc' : 'asc';

        return route('eft-files.index', array_merge(request()->except(['file_sort', 'file_sort_dir', 'file_page']), [
            'tab' => 'files',
            'file_sort' => $column,
            'file_sort_dir' => $direction,
        ]));
    };
    $itemSortUrl = static function (string $column) use ($itemSort, $itemSortDir): string {
        $direction = $itemSort === $column && $itemSortDir === 'asc' ? 'desc' : 'asc';

        return route('eft-files.index', array_merge(request()->except(['item_sort', 'item_sort_dir', 'item_page']), [
            'tab' => 'items',
            'item_sort' => $column,
            'item_sort_dir' => $direction,
        ]));
    };
    $fileSortArrow = static fn(string $column): string => $fileSort === $column ? ($fileSortDir === 'asc' ? ' ↑' : ' ↓') : ' ⇅';
    $itemSortArrow = static fn(string $column): string => $itemSort === $column ? ($itemSortDir === 'asc' ? ' ↑' : ' ↓') : ' ⇅';
    $isCandidateTab = in_array($activeTab, ['missing', 'excluded'], true);
    $candidatesNetTotal = $isCandidateTab
        ? $items->sum(fn($item) => (int) $item->type_id === 10
            ? (float) $item->amount
            : -(float) $item->amount)
        : null;
@endphp

<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin:20px 0;flex-wrap:wrap;">
    <div>
        <h2 style="margin:0;">EFT Files</h2>
        <div style="color:#718096;font-size:13px;margin-top:4px;">Live, read-only VieFund data from UB_EFTFile and UB_EFTItem</div>
    </div>
    <a href="{{ route('eft-files.export', request()->query()) }}" class="sync-action-pill sync-action-pill-secondary" style="display:inline-flex;align-items:center;gap:8px;text-decoration:none;">
        <span>↓ Export Excel</span>
    </a>
</div>

<div style="display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:16px;margin-bottom:24px;">
    <div class="card" style="background:linear-gradient(135deg,#345262 0%,#5a7585 100%);color:#fff;text-align:center;">
        <div class="summary-card-value">{{ number_format($totals->file_count) }}</div>
        <div class="summary-card-label">EFT Files</div>
    </div>
    <div class="card" style="background:linear-gradient(135deg,#2d6a6a 0%,#234e52 100%);color:#fff;text-align:center;">
        <div class="summary-card-value">{{ number_format($totals->item_count) }}</div>
        <div class="summary-card-label">EFT Items</div>
    </div>
    <div class="card" style="background:linear-gradient(135deg,#3182ce 0%,#2c5aa0 100%);color:#fff;text-align:center;">
        <div class="summary-card-value" style="font-size:18px;white-space:nowrap;">{{ $formatAmount($totals->total_amount) }}</div>
        <div class="summary-card-label">File Net</div>
    </div>
    <div class="card" style="background:linear-gradient(135deg,#38a169 0%,#2f855a 100%);color:#fff;text-align:center;">
        <div class="summary-card-value" style="font-size:18px;white-space:nowrap;">{{ $formatAmount($totals->item_amount) }}</div>
        <div class="summary-card-label">Item Net</div>
    </div>
    <div class="card" style="background:linear-gradient(135deg,{{ abs($difference) < 0.005 ? '#4a5568,#2d3748' : '#e53e3e,#c53030' }});color:#fff;text-align:center;">
        @if($filters['date_from'] !== '' || $filters['date_to'] !== '')
            <div class="summary-card-value" style="font-size:18px;white-space:nowrap;">N/A</div>
            <div class="summary-card-label">Different Date Populations</div>
        @else
            <div class="summary-card-value" style="font-size:18px;white-space:nowrap;">{{ $formatAmount($difference) }}</div>
            <div class="summary-card-label">File / Item Net Variance</div>
        @endif
    </div>
</div>

@if($totalsByType->isNotEmpty())
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin:-8px 0 20px;">
        @foreach($totalsByType as $typeTotal)
            @php $colors = $typeColors[(int) $typeTotal->type_id] ?? ['bg' => '#edf2f7', 'text' => '#2d3748']; @endphp
            <span style="background:{{ $colors['bg'] }};color:{{ $colors['text'] }};border:1px solid #cbd5e0;border-radius:4px;padding:6px 9px;font:600 12px monospace;">
                {{ $typeLabels[(int) $typeTotal->type_id] ?? $typeTotal->type_name }}: {{ number_format((int) $typeTotal->file_count) }} / {{ $formatAmount($typeTotal->total_amount) }}
            </span>
        @endforeach
    </div>
@endif

<div class="card" style="margin-bottom:20px;">
    <form action="{{ route('eft-files.index') }}" method="GET">
        @if($selectedFile)
            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;background:#ebf8ff;border:1px solid #bee3f8;color:#2c5282;border-radius:4px;padding:9px 12px;margin-bottom:12px;font:600 12px monospace;">
                <span>Viewing file #{{ $selectedFile->id }}: {{ $selectedFile->file_name }}</span>
                <a href="{{ route('eft-files.index', ['tab' => 'items']) }}" style="color:#2b6cb0;white-space:nowrap;">Clear file</a>
            </div>
        @endif
        @if($filters['sequences'] !== '')
            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;background:#ebf8ff;border:1px solid #bee3f8;color:#2c5282;border-radius:4px;padding:9px 12px;margin-bottom:12px;font:600 12px monospace;">
                <span>Viewing sequence(s): {{ $filters['sequences'] }}</span>
                <a href="{{ route('eft-files.index', ['tab' => 'items']) }}" style="color:#2b6cb0;white-space:nowrap;">Clear sequences</a>
            </div>
            <input type="hidden" name="sequences" value="{{ $filters['sequences'] }}">
        @endif
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:12px;align-items:end;">
            <div>
                <label style="display:block;font-size:12px;font-weight:600;color:#4a5568;margin-bottom:4px;">Date Basis</label>
                <select name="date_basis" style="padding:8px 10px;border:1px solid #cbd5e0;border-radius:4px;width:100%;font-size:13px;">
                    <option value="created" @selected($filters['date_basis'] === 'created')>Created</option>
                    <option value="effective" @selected($filters['date_basis'] === 'effective')>Effective</option>
                </select>
            </div>
            <div>
                <label style="display:block;font-size:12px;font-weight:600;color:#4a5568;margin-bottom:4px;">{{ $dateBasisLabel }} From</label>
                <input type="date" name="date_from" value="{{ $filters['date_from'] }}" style="padding:8px 10px;border:1px solid #cbd5e0;border-radius:4px;width:100%;font-size:13px;">
            </div>
            <div>
                <label style="display:block;font-size:12px;font-weight:600;color:#4a5568;margin-bottom:4px;">{{ $dateBasisLabel }} To</label>
                <input type="date" name="date_to" value="{{ $filters['date_to'] }}" style="padding:8px 10px;border:1px solid #cbd5e0;border-radius:4px;width:100%;font-size:13px;">
            </div>
            <div>
                <label style="display:block;font-size:12px;font-weight:600;color:#4a5568;margin-bottom:4px;">EFT Type</label>
                <select name="type" style="padding:8px 10px;border:1px solid #cbd5e0;border-radius:4px;width:100%;font-size:13px;">
                    <option value="">All types</option>
                    @foreach($types as $type)
                        <option value="{{ $type->id }}" @selected($filters['type'] !== '' && (int) $filters['type'] === (int) $type->id)>{{ $typeLabels[(int) $type->id] ?? $type->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label style="display:block;font-size:12px;font-weight:600;color:#4a5568;margin-bottom:4px;">File Status</label>
                <select name="file_status" style="padding:8px 10px;border:1px solid #cbd5e0;border-radius:4px;width:100%;font-size:13px;">
                    <option value="">All statuses</option>
                    @foreach($fileStatuses as $status)
                        <option value="{{ $status->id }}" @selected($filters['file_status'] !== '' && (int) $filters['file_status'] === (int) $status->id)>Status {{ $status->id }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label style="display:block;font-size:12px;font-weight:600;color:#4a5568;margin-bottom:4px;">Item Status</label>
                <select name="item_status" style="padding:8px 10px;border:1px solid #cbd5e0;border-radius:4px;width:100%;font-size:13px;">
                    <option value="">All statuses</option>
                    @foreach($itemStatuses as $status)
                        <option value="{{ $status->id }}" @selected($filters['item_status'] !== '' && (int) $filters['item_status'] === (int) $status->id)>Status {{ $status->id }}</option>
                    @endforeach
                </select>
            </div>
            <div style="display:flex;gap:8px;">
                <button type="submit" class="btn" style="padding:8px 20px;white-space:nowrap;">Filter</button>
                @if(request()->hasAny($filterKeys))
                    <a href="{{ route('eft-files.index') }}" class="btn" style="background:#718096;padding:8px 14px;text-decoration:none;">Reset</a>
                @endif
            </div>
        </div>
        <div style="display:grid;grid-template-columns:repeat(2,minmax(240px,1fr));gap:12px;margin-top:12px;">
            <div>
                <label style="display:block;font-size:12px;font-weight:600;color:#4a5568;margin-bottom:4px;">File Search</label>
                <input type="text" name="file_search" value="{{ $filters['file_search'] }}" placeholder="File name, notes, file ID, sequence" style="padding:8px 10px;border:1px solid #cbd5e0;border-radius:4px;width:100%;font-size:13px;">
            </div>
            <div>
                <label style="display:block;font-size:12px;font-weight:600;color:#4a5568;margin-bottom:4px;">Item Search</label>
                <input type="text" name="item_search" value="{{ $filters['item_search'] }}" placeholder="Holder, holder ID, notes, file name" style="padding:8px 10px;border:1px solid #cbd5e0;border-radius:4px;width:100%;font-size:13px;">
            </div>
        </div>
        @if($filters['file_id'] !== '')
            <input type="hidden" name="file_id" value="{{ $filters['file_id'] }}">
        @endif
        <input type="hidden" name="tab" value="{{ $activeTab }}">
        <input type="hidden" name="file_sort" value="{{ $fileSort }}">
        <input type="hidden" name="file_sort_dir" value="{{ $fileSortDir }}">
        <input type="hidden" name="item_sort" value="{{ $itemSort }}">
        <input type="hidden" name="item_sort_dir" value="{{ $itemSortDir }}">
        @if($drilldownDate !== '')
            <input type="hidden" name="drilldown_date" value="{{ $drilldownDate }}">
        @endif
    </form>
</div>

<div class="card" style="padding-top:0;">
    <div style="display:grid;grid-template-columns:minmax(0,1fr) auto minmax(0,1fr);align-items:center;gap:18px;border-bottom:1px solid #e2e8f0;padding:12px 14px;background:#f8fafc;">
        <div>
            @if($drilldownDateLabel)
                <div style="font-size:12px;font-weight:800;color:#2c5282;text-transform:uppercase;letter-spacing:.07em;">Settlement Date</div>
                <div style="font-size:20px;font-weight:800;color:#1a365d;line-height:1.15;margin-top:4px;">{{ $drilldownDateLabel }}</div>
            @else
                <div style="font-size:12px;font-weight:800;color:#4a5568;text-transform:uppercase;letter-spacing:0.07em;">EFT Remote Records</div>
                <div style="font-size:12px;color:#718096;margin-top:3px;">{{ $dateBasisLabel }} {{ $filters['date_from'] ?: 'all history' }} through {{ $filters['date_to'] ?: 'present' }}</div>
            @endif
        </div>
        @if($isCandidateTab)
            <div style="text-align:center;min-width:180px;">
                <div style="font-size:12px;font-weight:800;color:#2c5282;text-transform:uppercase;letter-spacing:.07em;">Net Total</div>
                <div style="font-size:20px;font-weight:800;color:{{ $candidatesNetTotal < 0 ? '#c53030' : '#2f855a' }};line-height:1.15;margin-top:4px;white-space:nowrap;">{{ $formatAmount($candidatesNetTotal) }}</div>
            </div>
        @else
            <div></div>
        @endif
        <div style="display:flex;gap:8px;justify-self:end;">
            <a href="{{ $tabUrl('files') }}" style="text-decoration:none;padding:6px 12px;border-radius:4px;font-size:12px;font-weight:700;{{ $activeTab === 'files' ? 'background:#2b6cb0;color:#fff;' : 'background:#e2e8f0;color:#2d3748;' }}">File Summaries</a>
            <a href="{{ $tabUrl('items') }}" style="text-decoration:none;padding:6px 12px;border-radius:4px;font-size:12px;font-weight:700;{{ $activeTab === 'items' ? 'background:#2b6cb0;color:#fff;' : 'background:#e2e8f0;color:#2d3748;' }}">EFT Items</a>
            <a href="{{ $tabUrl('missing') }}" style="text-decoration:none;padding:6px 12px;border-radius:4px;font-size:12px;font-weight:700;{{ $activeTab === 'missing' ? 'background:#2b6cb0;color:#fff;' : 'background:#e2e8f0;color:#2d3748;' }}">Other Settlement Dates</a>
            <a href="{{ $tabUrl('excluded') }}" style="text-decoration:none;padding:6px 12px;border-radius:4px;font-size:12px;font-weight:700;{{ $activeTab === 'excluded' ? 'background:#2b6cb0;color:#fff;' : 'background:#e2e8f0;color:#2d3748;' }}">Txns from Other Files</a>
        </div>
    </div>

    @if($activeTab === 'files')
        @if($files->count())
            <div style="padding:12px 14px;color:#4a5568;font-size:13px;">Showing {{ number_format($files->count()) }} of {{ number_format($files->total()) }} matching files.</div>
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;min-width:1280px;" class="mono-grid">
                    <thead><tr style="background:#e2e8f0;border-bottom:2px solid #cbd5e0;white-space:nowrap;">
                        <th style="text-align:left;"><a href="{{ $fileSortUrl('created_at') }}" style="color:inherit;text-decoration:none;">Created{{ $fileSortArrow('created_at') }}</a></th>
                        <th style="text-align:left;"><a href="{{ $fileSortUrl('effective_date') }}" style="color:inherit;text-decoration:none;">Effective{{ $fileSortArrow('effective_date') }}</a></th>
                        <th style="text-align:left;"><a href="{{ $fileSortUrl('type') }}" style="color:inherit;text-decoration:none;">Type{{ $fileSortArrow('type') }}</a></th>
                        <th style="text-align:right;"><a href="{{ $fileSortUrl('sequence') }}" style="color:inherit;text-decoration:none;">Sequence{{ $fileSortArrow('sequence') }}</a></th>
                        <th style="text-align:right;"><a href="{{ $fileSortUrl('item_count') }}" style="color:inherit;text-decoration:none;">Items{{ $fileSortArrow('item_count') }}</a></th>
                        <th style="text-align:right;"><a href="{{ $fileSortUrl('total_amount') }}" style="color:inherit;text-decoration:none;">File Total{{ $fileSortArrow('total_amount') }}</a></th>
                        <th style="text-align:right;">Item Total</th>
                        <th style="text-align:right;">Variance</th>
                        <th style="text-align:left;">Trust Account</th>
                    </tr></thead>
                    <tbody>
                    @foreach($files as $file)
                        @php
                            $colors = $typeColors[(int) $file->type_id] ?? ['bg' => '#edf2f7', 'text' => '#2d3748'];
                            $amountColor = (int) $file->type_id === 10 ? '#2f855a' : '#c53030';
                            $fileVariance = (float) $file->total_amount - (float) ($file->item_amount ?? 0);
                        @endphp
                        <tr style="border-bottom:1px solid #d9e2ec;background:{{ $loop->even ? 'rgba(56,161,105,0.07)' : 'transparent' }};">
                            <td style="white-space:nowrap;line-height:1.2;"><span style="display:block;">{{ $formatDate($file->created_at) }}</span><span style="display:block;opacity:.85;">{{ $formatTime($file->created_at) }}</span></td>
                            <td style="white-space:nowrap;">{{ $formatDate($file->effective_date) }}</td>
                            <td><span style="background:{{ $colors['bg'] }};color:{{ $colors['text'] }};padding:3px 7px;border-radius:4px;font-size:11px;font-weight:700;white-space:nowrap;">{{ $typeLabels[(int) $file->type_id] ?? ($file->type_name ?? 'Type ' . $file->type_id) }}</span></td>
                            <td style="text-align:right;white-space:nowrap;">{{ $file->sequence_number ?? '—' }}</td>
                            <td style="text-align:right;">
                                <a href="{{ route('eft-files.index', ['tab' => 'items', 'file_id' => $file->id]) }}" style="color:#2b6cb0;font-weight:700;text-decoration:underline;">{{ number_format((int) $file->item_count) }}</a>
                            </td>
                            <td style="text-align:right;white-space:nowrap;font-weight:700;color:{{ $amountColor }};">{{ $formatAmount($file->total_amount) }}</td>
                            <td style="text-align:right;white-space:nowrap;font-weight:700;color:{{ $amountColor }};">{{ $formatAmount($file->item_amount) }}</td>
                            <td style="text-align:right;white-space:nowrap;color:{{ abs($fileVariance) < .005 ? '#4a5568' : '#c53030' }};font-weight:{{ abs($fileVariance) < .005 ? '400' : '700' }};">{{ $formatAmount($fileVariance) }}</td>
                            <td style="white-space:nowrap;">{{ $file->trust_bank_account_id ?? '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div style="margin-top:20px;display:flex;justify-content:center;">{{ $files->onEachSide(1)->links() }}</div>
        @else
            <p style="color:#718096;text-align:center;padding:36px 0;">No EFT files match the current filters.</p>
        @endif
    @else
        @if($items->count())
            <div style="padding:12px 14px;color:#4a5568;font-size:13px;">
                @if($isCandidateTab)
                    Showing all {{ number_format($items->count()) }} candidates, grouped by effective date.
                @else
                    Showing {{ number_format($items->count()) }} of {{ number_format($items->total()) }} matching items.
                @endif
            </div>
            <div style="overflow-x:auto;">
                <table style="width:max-content;border-collapse:collapse;min-width:1120px;" class="mono-grid eft-items-grid">
                    <thead><tr style="background:#e2e8f0;border-bottom:2px solid #cbd5e0;white-space:nowrap;">
                        <th style="text-align:left;">@if($isCandidateTab) Created @else <a href="{{ $itemSortUrl('created_at') }}" style="color:inherit;text-decoration:none;">Created{{ $itemSortArrow('created_at') }}</a> @endif</th>
                        <th style="text-align:left;">@if($isCandidateTab) Effective ↓ @else <a href="{{ $itemSortUrl('effective_date') }}" style="color:inherit;text-decoration:none;">Effective{{ $itemSortArrow('effective_date') }}</a> @endif</th>
                        <th style="text-align:left;">Trade</th>
                        <th style="text-align:left;">Settlement</th>
                        <th style="text-align:left;"><a href="{{ $itemSortUrl('type') }}" style="color:inherit;text-decoration:none;">Type{{ $itemSortArrow('type') }}</a></th>
                        <th style="text-align:left;"><a href="{{ $itemSortUrl('holder') }}" style="color:inherit;text-decoration:none;">Holder{{ $itemSortArrow('holder') }}</a></th>
                        <th style="text-align:left;">Holder ID</th>
                        <th style="text-align:left;">Source</th>
                        <th style="text-align:right;"><a href="{{ $itemSortUrl('amount') }}" style="color:inherit;text-decoration:none;">Amount{{ $itemSortArrow('amount') }}</a></th>
                    </tr></thead>
                    <tbody>
                    @php
                        $itemGroups = $isCandidateTab
                            ? $items->groupBy(fn($item) => $formatDate($item->effective_date))
                            : collect(['' => $items]);
                    @endphp
                    @foreach($itemGroups as $effectiveDate => $groupItems)
                    @foreach($groupItems as $item)
                        @php
                            $colors = $typeColors[(int) $item->type_id] ?? ['bg' => '#edf2f7', 'text' => '#2d3748'];
                            $amountColor = (int) $item->type_id === 10 ? '#2f855a' : '#c53030';
                            $planAccountId = null;
                            if (preg_match('/PL(.+)$/i', (string) $item->holder_id, $holderIdMatch)) {
                                $planAccountId = $holderIdMatch[1];
                            }
                            $customerTransactionUrl = $planAccountId && $item->linked_id
                                ? route('remote-viefund.index', [
                                    'filter_account_id' => $planAccountId,
                                    'highlight_trust_trx_id' => $item->linked_id,
                                ]) . '#transaction-T-' . $item->linked_id
                                : null;
                        @endphp
                        <tr style="border-bottom:1px solid #d9e2ec;background:{{ $loop->even ? 'rgba(56,161,105,0.07)' : 'transparent' }};">
                            <td style="white-space:nowrap;line-height:1.2;"><span style="display:block;">{{ $formatDate($item->created_at) }}</span><span style="display:block;opacity:.85;">{{ $formatTime($item->created_at) }}</span></td>
                            <td style="white-space:nowrap;">{{ $formatDate($item->effective_date) }}</td>
                            <td style="white-space:nowrap;">{{ $formatDate($item->trade_date) }}</td>
                            <td style="white-space:nowrap;">{{ $formatDate($item->settlement_date) }}</td>
                            <td><span style="background:{{ $colors['bg'] }};color:{{ $colors['text'] }};padding:3px 7px;border-radius:4px;font-size:11px;font-weight:700;white-space:nowrap;">{{ $typeLabels[(int) $item->type_id] ?? ($item->type_name ?? 'Type ' . $item->type_id) }}</span></td>
                            <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{{ $customerTransactionUrl ? 'Open customer transaction' : $item->holder_name }}">
                                @if($customerTransactionUrl)
                                    <a href="{{ $customerTransactionUrl }}" target="_blank" rel="noopener noreferrer" style="color:#2b6cb0;text-decoration:underline;font-weight:600;">{{ $item->holder_name ?? '—' }}</a>
                                @else
                                    {{ $item->holder_name ?? '—' }}
                                @endif
                            </td>
                            <td style="white-space:nowrap;color:#4a5568;">{{ $item->holder_id ?? '—' }}</td>
                            <td style="white-space:nowrap;" title="Code {{ trim((string) $item->source_code) }}">{{ $item->source_name ?? (trim((string) $item->source_code) ?: '—') }}</td>
                            <td style="text-align:right;white-space:nowrap;font-weight:700;color:{{ $amountColor }};">{{ $formatAmount($item->amount) }}</td>
                        </tr>
                    @endforeach
                    @if($isCandidateTab)
                        @php
                            $groupNetTotal = $groupItems->sum(
                                fn($groupItem) => (int) $groupItem->type_id === 10
                                    ? (float) $groupItem->amount
                                    : -(float) $groupItem->amount
                            );
                        @endphp
                        <tr style="border-top:2px solid #cbd5e0;border-bottom:2px solid #a0aec0;background:#edf2f7;">
                            <td colspan="8" style="text-align:right;font-weight:800;color:#2d3748;white-space:nowrap;">{{ $effectiveDate }} Net Total</td>
                            <td style="text-align:right;font-weight:800;color:{{ $groupNetTotal < 0 ? '#c53030' : '#2f855a' }};white-space:nowrap;">{{ $formatAmount($groupNetTotal) }}</td>
                        </tr>
                    @endif
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if(!$isCandidateTab)
                <div style="margin-top:20px;display:flex;justify-content:center;">{{ $items->onEachSide(1)->links() }}</div>
            @endif
        @else
            <p style="color:#718096;text-align:center;padding:36px 0;">
                @if($isCandidateTab && !$drilldownDateLabel && $filters['date_from'] === '' && $filters['date_to'] === '')
                    Select a date or date range to find candidates.
                @elseif($activeTab === 'missing')
                    No missing candidates match the current filters.
                @elseif($activeTab === 'excluded')
                    No excluded candidates match the selected date and filters.
                @else
                    No EFT items match the current filters.
                @endif
            </p>
        @endif
    @endif
</div>
@endsection
