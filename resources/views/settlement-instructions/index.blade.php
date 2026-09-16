@extends('layouts.app')

@section('title', 'FSP Files')

@section('content')
@php
    $showSyncButtons = filter_var(env('SHOW_SYNC_BUTTONS', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    $settlementDateFrom = request('date_from');
    $settlementDateTo = request('date_to');
    $settlementDateLabel = match (true) {
        $settlementDateFrom && $settlementDateTo && $settlementDateFrom === $settlementDateTo => \Carbon\Carbon::parse($settlementDateFrom)->format('F j, Y'),
        $settlementDateFrom && $settlementDateTo => \Carbon\Carbon::parse($settlementDateFrom)->format('F j, Y') . ' through ' . \Carbon\Carbon::parse($settlementDateTo)->format('F j, Y'),
        $settlementDateFrom => \Carbon\Carbon::parse($settlementDateFrom)->format('F j, Y') . ' onward',
        $settlementDateTo => 'Through ' . \Carbon\Carbon::parse($settlementDateTo)->format('F j, Y'),
        default => 'All settlement dates',
    };
    $sourceViewUrl = static fn(string $sourceType): string => route(
        'settlement-instructions.index',
        array_merge(request()->except(['source_type', 'source_file', 'agra_page', 'ltm_page', 'agra_summary_page', 'ltm_summary_page']), ['source_type' => $sourceType])
    );
@endphp
<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin:20px 0;flex-wrap:wrap;">
    <div>
        <h2 style="margin: 0;">FSP Files</h2>
        <div style="color: #718096; font-size: 13px; margin-top: 4px;">Source files: AGRA and 7960 feeds</div>
        <div id="settlement-sync-status-wrap" class="sync-chip sync-chip-progress" style="display:none; margin-top:8px; width:max-content; align-items:center; gap:8px;">
            <span id="settlement-sync-status"></span>
            <button type="button" id="settlement-sync-status-dismiss" aria-label="Dismiss FSP sync status" style="border:none; background:transparent; color:inherit; font-size:14px; font-weight:700; cursor:pointer; line-height:1; padding:0;">×</button>
        </div>
    </div>
    <div id="settlement-last-sync" style="display:flex;flex-direction:column;align-items:flex-end;gap:4px;color:#4a5568;font-size:12px;text-align:right;line-height:1.4;">
        <span><strong>Last sync:</strong> <span id="settlement-last-sync-time">checking…</span></span>
        <span id="settlement-last-sync-detail">&nbsp;</span>
    </div>
</div>

@if(session('sync_success'))
    <div style="margin-bottom:16px;padding:10px 14px;border-radius:6px;background:#c6f6d5;color:#22543d;border:1px solid #9ae6b4;font-size:13px;">
        {{ session('sync_success') }}
    </div>
@endif
@if(session('sync_error'))
    <div style="margin-bottom:16px;padding:10px 14px;border-radius:6px;background:#fff5f5;color:#742a2a;border:1px solid #feb2b2;font-size:13px;">
        {{ session('sync_error') }}
    </div>
@endif

<script>
(function () {
    const wrap = document.getElementById('settlement-sync-status-wrap');
    const text = document.getElementById('settlement-sync-status');
    const dismiss = document.getElementById('settlement-sync-status-dismiss');
    const lastSyncTime = document.getElementById('settlement-last-sync-time');
    const lastSyncDetail = document.getElementById('settlement-last-sync-detail');
    if (!wrap || !text) return;

    const setVisible = (visible) => { wrap.style.display = visible ? 'inline-flex' : 'none'; };
    const setMessage = (message) => { text.textContent = message || ''; };
    const setBusy = (busy) => {
        const button = document.getElementById('settlement-sync-btn');
        if (!button) return;
        button.disabled = busy;
        button.style.opacity = busy ? '0.65' : '';
        button.style.cursor = busy ? 'not-allowed' : '';
    };

    if (dismiss) {
        dismiss.addEventListener('click', () => setVisible(false));
    }

    const poll = () => {
        fetch('{{ route('settlement-instructions.sync-status') }}', {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
        })
            .then((response) => response.json())
            .then((data) => {
                if (lastSyncTime && lastSyncDetail) {
                    if (data.completed_at) {
                        const completed = new Date(data.completed_at);
                        const when = Number.isNaN(completed.getTime()) ? data.completed_at : completed.toLocaleString([], {
                            year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZoneName: 'short',
                        });
                        const result = data.success === true ? (data.message || 'Completed successfully') : (data.success === false ? 'Failed' : 'Completed');
                        const trigger = data.trigger ? `Started via ${data.trigger}` : '';
                        lastSyncTime.textContent = when;
                        lastSyncDetail.textContent = trigger ? `${result} · ${trigger}` : result;
                    } else if (data.inProgress && data.started_at) {
                        lastSyncTime.textContent = 'currently running';
                        lastSyncDetail.textContent = data.message || 'FSP sync in progress…';
                    } else {
                        lastSyncTime.textContent = 'no completed sync recorded';
                        lastSyncDetail.innerHTML = '&nbsp;';
                    }
                }
                if (data.inProgress) {
                    wrap.className = 'sync-chip sync-chip-progress';
                    setVisible(true);
                    setMessage(data.message || 'FSP sync in progress...');
                    setBusy(true);
                    return;
                }

                setBusy(false);
                if (data.success === true && data.initiatedByCurrentSession) {
                    wrap.className = 'sync-chip sync-chip-success';
                    setVisible(true);
                    setMessage(data.message || 'FSP sync completed.');
                } else if (data.success === false && data.initiatedByCurrentSession) {
                    wrap.className = 'sync-chip sync-chip-error';
                    setVisible(true);
                    setMessage(data.message || 'FSP sync failed.');
                } else {
                    setVisible(false);
                }
            })
            .catch(() => {});
    };

    poll();
    setInterval(poll, 5000);
})();
</script>

@php
    $activeSourceTab = $activeSourceType ?? 'fundserv_agra';
    $currentSort = $sort ?? 'settlement_date';
    $currentSortDir = $sortDir ?? 'desc';
    $sourceLabels = [
        'fundserv_agra' => 'AGRA',
        'ltm' => '7960',
    ];
    $formatAccountingAmount = static function ($amount, ?string $side): string {
        if ($amount === null) {
            return '—';
        }

        $formatted = '$' . number_format(abs((float) $amount), 2);

        return $side === 'BUY' ? '(' . $formatted . ')' : $formatted;
    };
    $formatAccountingBalance = static function ($amount, ?string $currency = null): string {
        if ($amount === null) {
            return '—';
        }

        $value = (float) $amount;
        $formatted = ($currency ? $currency . ' $' : '$') . number_format(abs($value), 2);

        return $value < 0 ? '(' . $formatted . ')' : $formatted;
    };
@endphp

@foreach(['fundserv_agra', 'ltm'] as $sourceType)
    @php
        $sourceTotals = $totalsBySource[$sourceType] ?? null;
        $sourceEntries = $entriesBySource[$sourceType] ?? null;
        $currencyTotals = $sourceTotals->currency_totals ?? collect();
    @endphp
    <div data-settlement-source-pane="{{ $sourceType }}" style="display: {{ $activeSourceTab === $sourceType ? '' : 'none' }};">
        <div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 16px; margin-bottom: 24px;">
            <div class="card" style="background: linear-gradient(135deg, #345262 0%, #5a7585 100%); color: white; text-align: center;">
                <div class="summary-card-value">{{ number_format((int) ($sourceTotals->total_count ?? 0)) }}</div>
                <div class="summary-card-label">Records</div>
            </div>
            <div class="card" style="background: linear-gradient(135deg, #e53e3e 0%, #c53030 100%); color: white; text-align: center;">
                @forelse($currencyTotals as $currencyTotal)
                    <div class="summary-card-value" style="font-size: 16px; line-height: 1.4; white-space: nowrap;">{{ $formatAccountingBalance(-(float) $currencyTotal->buy_settlement_total, $currencyTotal->currency) }}</div>
                @empty
                    <div class="summary-card-value">—</div>
                @endforelse
                <div class="summary-card-label">Buy Settlements</div>
            </div>
            <div class="card" style="background: linear-gradient(135deg, #38a169 0%, #2f855a 100%); color: white; text-align: center;">
                @forelse($currencyTotals as $currencyTotal)
                    <div class="summary-card-value" style="font-size: 16px; line-height: 1.4; white-space: nowrap;">{{ $formatAccountingBalance($currencyTotal->sell_settlement_total, $currencyTotal->currency) }}</div>
                @empty
                    <div class="summary-card-value">—</div>
                @endforelse
                <div class="summary-card-label">Sell Settlements</div>
            </div>
            <div class="card" style="background: linear-gradient(135deg, #2d6a6a 0%, #234e52 100%); color: white; text-align: center;">
                @forelse($currencyTotals as $currencyTotal)
                    <div class="summary-card-value" style="font-size: 16px; line-height: 1.4; white-space: nowrap;">{{ $formatAccountingBalance($currencyTotal->settlement_total, $currencyTotal->currency) }}</div>
                @empty
                    <div class="summary-card-value">—</div>
                @endforelse
                <div class="summary-card-label">Net Settlement</div>
            </div>
            <div class="card" style="background: linear-gradient(135deg, #3182ce 0%, #2c5aa0 100%); color: white; text-align: center;">
                <div class="summary-card-value">{{ $sourceEntries?->currentPage() ?? 1 }} / {{ max(1, (int) ($sourceEntries?->lastPage() ?? 1)) }}</div>
                <div class="summary-card-label">Page</div>
            </div>
        </div>
    </div>
@endforeach

<div class="card" style="margin-bottom: 20px;">
    <form action="{{ route('settlement-instructions.index') }}" method="GET">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; align-items: end;">
            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; color: #4a5568; margin-bottom: 4px;">Side</label>
                <select name="side" style="padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 4px; width: 100%; font-size: 13px;">
                    <option value="">All</option>
                    <option value="BUY" @selected(request('side') === 'BUY')>Buy</option>
                    <option value="SELL" @selected(request('side') === 'SELL')>Sell</option>
                </select>
            </div>
            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; color: #4a5568; margin-bottom: 4px;">Currency</label>
                <select name="currency" style="padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 4px; width: 100%; font-size: 13px;">
                    <option value="">All</option>
                    <option value="CAD" @selected(request('currency') === 'CAD')>CAD</option>
                    <option value="USD" @selected(request('currency') === 'USD')>USD</option>
                </select>
            </div>
            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; color: #4a5568; margin-bottom: 4px;">Settlement Date From</label>
                <input type="date" name="date_from" value="{{ request('date_from') }}" style="padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 4px; width: 100%; font-size: 13px;">
            </div>
            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; color: #4a5568; margin-bottom: 4px;">Settlement Date To</label>
                <input type="date" name="date_to" value="{{ request('date_to') }}" style="padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 4px; width: 100%; font-size: 13px;">
            </div>
            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; color: #4a5568; margin-bottom: 4px;">Source File</label>
                <select id="settlement-source-file" name="source_file" style="padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 4px; width: 100%; font-size: 13px;">
                    <option value="">All files</option>
                    @foreach($sourceFiles as $file)
                        <option value="{{ $file }}" @selected(request('source_file') === $file)>{{ $file }}</option>
                    @endforeach
                </select>
            </div>
            <div style="display: flex; gap: 8px;">
                <button type="submit" class="btn" style="padding: 8px 20px; white-space: nowrap;">Filter</button>
                @if(request()->hasAny(['source_type','side','currency','date_from','date_to','source_file','search']))
                    <a href="{{ route('settlement-instructions.index') }}" class="btn" style="background: #718096; padding: 8px 14px; text-decoration: none;">Clear</a>
                @endif
            </div>
        </div>
        <div style="margin-top: 12px;">
            <label style="display: block; font-size: 12px; font-weight: 600; color: #4a5568; margin-bottom: 4px;">Search (order ID, source ID, account IDs)</label>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Search..." style="padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 4px; width: 100%; font-size: 13px;">
        </div>
        <input type="hidden" name="sort" value="{{ $currentSort }}">
        <input type="hidden" name="sort_dir" value="{{ $currentSortDir }}">
        <input id="settlement-source-type" type="hidden" name="source_type" value="{{ $activeSourceTab }}">
    </form>
</div>

<div class="card" style="padding-top: 0;">
    <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; border-bottom: 1px solid #e2e8f0; padding: 12px 14px; background: #f8fafc; flex-wrap: wrap;">
        <div>
            <div style="font-size:12px;font-weight:800;color:#2c5282;text-transform:uppercase;letter-spacing:.07em;">Settlement Date</div>
            <div style="font-size:20px;font-weight:800;color:#1a365d;line-height:1.15;margin-top:4px;">{{ $settlementDateLabel }}</div>
        </div>
        <div style="display:flex;align-items:flex-end;justify-content:flex-end;gap:10px;flex-wrap:wrap;margin-left:auto;">
            <label style="display:flex;flex-direction:column;gap:4px;color:#4a5568;font-size:11px;font-weight:700;">
                <span>View</span>
                <select aria-label="FSP data source" onchange="window.location.href=this.value" style="min-width:205px;padding:7px 30px 7px 10px;border:1px solid #cbd5e0;border-radius:5px;background:#fff;color:#2d3748;font-size:12px;font-weight:600;">
                    <option value="{{ $sourceViewUrl('fundserv_agra') }}" @selected($activeSourceTab === 'fundserv_agra')>AGRA Files</option>
                    <option value="{{ $sourceViewUrl('ltm') }}" @selected($activeSourceTab === 'ltm')>7960 Files</option>
                </select>
            </label>
            <a href="{{ route('settlement-instructions.export', request()->query()) }}" class="sync-action-pill sync-action-pill-secondary" style="display:inline-flex;align-items:center;gap:8px;text-decoration:none;">
                <span>↓ Export Excel</span>
            </a>
            @if($showSyncButtons)
                <form method="POST" action="{{ route('settlement-instructions.sync') }}" style="margin:0;">
                    @csrf
                    @foreach(request()->query() as $key => $value)
                        @if(is_array($value))
                            @foreach($value as $item)
                                <input type="hidden" name="{{ $key }}[]" value="{{ $item }}">
                            @endforeach
                        @else
                            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                        @endif
                    @endforeach
                    <button type="submit" id="settlement-sync-btn" class="sync-action-pill sync-action-pill-primary">↻ Sync FSP Files</button>
                </form>
            @endif
        </div>
    </div>

    @foreach(['fundserv_agra', 'ltm'] as $sourceType)
        @php
            $sourceTotals = $totalsBySource[$sourceType] ?? null;
            $sourceEntries = $entriesBySource[$sourceType];
            $sourceSummaries = $summariesBySource[$sourceType];
            $sourceSummaryTotals = $summaryTotalsBySource[$sourceType];
            $detailSortBaseParams = array_merge(
                request()->except(['agra_page', 'ltm_page', 'agra_summary_page', 'ltm_summary_page', 'source_type']),
                ['source_type' => $sourceType]
            );
            $sortUrl = function (string $column) use ($detailSortBaseParams, $currentSort, $currentSortDir): string {
                $nextDir = ($currentSort === $column && $currentSortDir === 'asc') ? 'desc' : 'asc';

                return route('settlement-instructions.index', array_merge($detailSortBaseParams, [
                    'sort' => $column,
                    'sort_dir' => $nextDir,
                ]));
            };
            $sortArrow = function (string $column) use ($currentSort, $currentSortDir): string {
                if ($currentSort !== $column) {
                    return ' ⇅';
                }

                return $currentSortDir === 'asc' ? ' ↑' : ' ↓';
            };
        @endphp
        <div data-settlement-source-pane="{{ $sourceType }}" style="display: {{ $activeSourceTab === $sourceType ? '' : 'none' }};">
            @if(($sourceSummaryTotals->summary_count ?? 0) > 0)
                <div style="margin: 16px 0;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; gap: 12px; flex-wrap: wrap; padding: 0 14px;">
                        <div>
                            <div style="font-size: 12px; font-weight: 800; color: #4a5568; text-transform: uppercase; letter-spacing: 0.07em;">FSP Files</div>
                            <div style="font-size: 13px; color: #4a5568; margin-top: 4px;">
                                {{ $sourceLabels[$sourceType] }}: showing {{ number_format($sourceSummaries->count()) }} of {{ number_format((int) ($sourceSummaryTotals->summary_count ?? 0)) }} summary groups for the active filters.
                            </div>
                        </div>
                    </div>

                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse; min-width: 1020px;" class="mono-grid">
                            <thead>
                                <tr style="background: #e2e8f0; border-bottom: 2px solid #cbd5e0; white-space: nowrap;">
                                    <th style="text-align: left; font-weight: 700; color: #2d3748;">Source File</th>
                                    <th style="text-align: right; font-weight: 700; color: #2d3748;">Records</th>
                                    <th style="text-align: left; font-weight: 700; color: #2d3748;">Currency</th>
                                    <th style="text-align: left; font-weight: 700; color: #2d3748;">Create Date Range</th>
                                    <th style="text-align: left; font-weight: 700; color: #2d3748;">Trade Date Range</th>
                                    <th style="text-align: left; font-weight: 700; color: #2d3748;">Settlement Date Range</th>
                                    <th style="text-align: right; font-weight: 700; color: #2d3748;">Buy Amount</th>
                                    <th style="text-align: right; font-weight: 700; color: #2d3748;">Sell Amount</th>
                                    @if($sourceType === 'fundserv_agra')
                                        <th style="text-align: right; font-weight: 700; color: #2d3748;">Switch Amount</th>
                                    @endif
                                    <th style="text-align: right; font-weight: 700; color: #2d3748;">Net Transactions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($sourceSummaries as $summary)
                                    @php $summaryCurrencyTotals = $summary->currency_totals ?? collect(); @endphp
                                    <tr style="border-bottom: 1px solid #d9e2ec; background: {{ $loop->even ? 'rgba(56, 161, 105, 0.07)' : 'transparent' }};">
                                        <td style="white-space: nowrap; color: #4a5568;">{{ $summary->source_file }}</td>
                                        <td style="text-align: right; white-space: nowrap; color: #2d3748;">{{ number_format((int) $summary->record_count) }}</td>
                                        <td style="white-space: nowrap; color: #2d3748;">{!! $summaryCurrencyTotals->pluck('currency')->filter()->map(fn ($currency) => e($currency))->implode('<br>') ?: '—' !!}</td>
                                        <td style="white-space: nowrap; color: #4a5568;">{{ $summary->min_create_date?->format('Y-m-d') ?? '—' }} to {{ $summary->max_create_date?->format('Y-m-d') ?? '—' }}</td>
                                        <td style="white-space: nowrap; color: #4a5568;">{{ $summary->min_trade_date?->format('Y-m-d') ?? '—' }} to {{ $summary->max_trade_date?->format('Y-m-d') ?? '—' }}</td>
                                        <td style="white-space: nowrap; color: #2d3748; font-weight: 700;">{{ $summary->min_settlement_date?->format('Y-m-d') ?? '—' }} to {{ $summary->max_settlement_date?->format('Y-m-d') ?? '—' }}</td>
                                        <td style="text-align: right; white-space: nowrap; font-weight: 500;">@foreach($summaryCurrencyTotals as $total)<span style="display:block; color: #e53e3e;">{{ $formatAccountingBalance(-(float) $total->buy_amount, $total->currency) }}</span>@endforeach</td>
                                        <td style="text-align: right; white-space: nowrap; font-weight: 500;">@foreach($summaryCurrencyTotals as $total)<span style="display:block; color: #276749;">{{ $formatAccountingBalance($total->sell_amount, $total->currency) }}</span>@endforeach</td>
                                        @if($sourceType === 'fundserv_agra')
                                            <td style="text-align: right; white-space: nowrap; font-weight: 500;">@foreach($summaryCurrencyTotals as $total)<span style="display:block; color: #2d3748;">{{ $formatAccountingBalance($total->switch_amount, $total->currency) }}</span>@endforeach</td>
                                        @endif
                                        <td style="text-align: right; white-space: nowrap; font-weight: 700;">@foreach($summaryCurrencyTotals as $total)<span style="display:block; color: {{ (float) $total->net_transactions < 0 ? '#e53e3e' : '#276749' }};">{{ $formatAccountingBalance($total->net_transactions, $total->currency) }}</span>@endforeach</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div data-preserve-scroll-links="true" style="margin-top: 16px; display: flex; justify-content: center;">
                        {{ $sourceSummaries->onEachSide(1)->links() }}
                    </div>
                </div>
            @endif

            <div style="font-size: 12px; font-weight: 800; color: #4a5568; text-transform: uppercase; letter-spacing: 0.07em; padding: 0 14px 10px 14px;">
                Details
            </div>

            @if($sourceEntries->count())
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse;" class="mono-grid">
                        <thead>
                            <tr style="background: #f7fafc; border-bottom: 2px solid #e2e8f0; white-space: nowrap;">
                                <th style="text-align:left; font-weight:600; color:#2d3748;"><a href="{{ $sortUrl('create_date') }}" data-preserve-scroll="true" style="color:inherit; text-decoration:none;">Create Date{{ $sortArrow('create_date') }}</a></th>
                                <th style="text-align:left; font-weight:600; color:#2d3748;"><a href="{{ $sortUrl('trade_date') }}" data-preserve-scroll="true" style="color:inherit; text-decoration:none;">Trade Date{{ $sortArrow('trade_date') }}</a></th>
                                <th style="text-align:left; font-weight:600; color:#2d3748;"><a href="{{ $sortUrl('settlement_date') }}" data-preserve-scroll="true" style="color:inherit; text-decoration:none;">Settle Date{{ $sortArrow('settlement_date') }}</a></th>
                                <th style="text-align:left; font-weight:600; color:#2d3748;"><a href="{{ $sortUrl('side') }}" data-preserve-scroll="true" style="color:inherit; text-decoration:none;">Side{{ $sortArrow('side') }}</a></th>
                                <th style="text-align:left; font-weight:600; color:#2d3748;">Order ID</th>
                                <th style="text-align:left; font-weight:600; color:#2d3748;">Source ID</th>
                                <th style="text-align:left; font-weight:600; color:#2d3748;">Fund Acct</th>
                                <th style="text-align:left; font-weight:600; color:#2d3748;">Fund ID</th>
                                <th style="text-align:left; font-weight:600; color:#2d3748;">Currency</th>
                                <th style="text-align:right; font-weight:600; color:#2d3748;"><a href="{{ $sortUrl('gross_amount') }}" data-preserve-scroll="true" style="color:inherit; text-decoration:none;">Gross{{ $sortArrow('gross_amount') }}</a></th>
                                <th style="text-align:right; font-weight:600; color:#2d3748;"><a href="{{ $sortUrl('net_amount') }}" data-preserve-scroll="true" style="color:inherit; text-decoration:none;">Net{{ $sortArrow('net_amount') }}</a></th>
                                <th style="text-align:right; font-weight:600; color:#2d3748;"><a href="{{ $sortUrl('settlement_amount') }}" data-preserve-scroll="true" style="color:inherit; text-decoration:none;">Settle Amt{{ $sortArrow('settlement_amount') }}</a></th>
                                <th style="text-align:left; font-weight:600; color:#2d3748;">File</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($sourceEntries as $entry)
                                @php
                                    $isBuy = $entry->side === 'BUY';
                                    $isSell = $entry->side === 'SELL';
                                    $amountColor = $isBuy ? '#e53e3e' : ($isSell ? '#276749' : '#2d3748');
                                @endphp
                                <tr style="border-bottom: 1px solid #e2e8f0; background: {{ $loop->even ? 'rgba(56, 161, 105, 0.07)' : 'transparent' }};">
                                    <td style="white-space: nowrap; color:#4a5568;">{{ $entry->create_date?->format('Y-m-d') ?? '—' }}</td>
                                    <td style="white-space: nowrap; color:#4a5568;">{{ $entry->trade_date?->format('Y-m-d') ?? '—' }}</td>
                                    <td style="white-space: nowrap; color:#2d3748;">{{ $entry->settlement_date?->format('Y-m-d') ?? '—' }}</td>
                                    <td style="white-space: nowrap;">
                                        @if($entry->side)
                                            <span style="background: {{ $isBuy ? '#fed7d7' : ($isSell ? '#c6f6d5' : '#e2e8f0') }}; color: {{ $isBuy ? '#742a2a' : ($isSell ? '#22543d' : '#2d3748') }}; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; font-family: monospace;">{{ $entry->side }}</span>
                                        @else
                                            <span style="color: #a0aec0;">—</span>
                                        @endif
                                    </td>
                                    <td style="white-space: nowrap; color:#2d3748;">{{ $entry->order_id ?? '—' }}</td>
                                    <td style="white-space: nowrap; color:#2d3748;">{{ $entry->source_id ?? '—' }}</td>
                                    <td style="white-space: nowrap; color:#2d3748;">{{ $entry->fund_account_id ?? '—' }}</td>
                                    <td style="white-space: nowrap; color:#2d3748;">
                                        @if($entry->side === 'SWITCH' && ($entry->switch_from_fund_id || $entry->switch_to_fund_id))
                                            {{ $entry->switch_from_fund_id ?? '—' }} &gt; {{ $entry->switch_to_fund_id ?? '—' }}
                                        @else
                                            {{ $entry->fund_id ?? '—' }}
                                        @endif
                                    </td>
                                    <td style="white-space: nowrap; color:#2d3748; font-weight:600;">{{ $entry->currency ?? '—' }}</td>
                                    <td style="text-align:right; white-space: nowrap; color:{{ $amountColor }}; font-weight:500;">{{ $formatAccountingAmount($entry->gross_amount, $entry->side) }}</td>
                                    <td style="text-align:right; white-space: nowrap; color:{{ $amountColor }}; font-weight:500;">{{ $formatAccountingAmount($entry->net_amount, $entry->side) }}</td>
                                    <td style="text-align:right; white-space: nowrap; color:{{ $amountColor }}; font-weight:700;">{{ $formatAccountingAmount($entry->settlement_amount, $entry->side) }}</td>
                                    <td style="white-space: nowrap; color:#4a5568;">{{ $entry->source_file }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div data-preserve-scroll-links="true" style="margin-top: 20px; display: flex; justify-content: center;">
                    {{ $sourceEntries->onEachSide(1)->links() }}
                </div>
            @else
                <p style="color: #718096; text-align: center; padding: 40px 0;">No {{ $sourceLabels[$sourceType] }} settlement instructions found for the active filters.</p>
            @endif
        </div>
    @endforeach
</div>

<script>
(function () {
    const key = 'settlementDetailsScrollY';

    const restore = sessionStorage.getItem(key);
    if (restore !== null) {
        sessionStorage.removeItem(key);
        const y = parseInt(restore, 10);
        if (!Number.isNaN(y)) {
            window.scrollTo(0, y);
        }
    }

    const links = Array.from(document.querySelectorAll(
        'a[data-preserve-scroll="true"], [data-preserve-scroll-links="true"] a'
    ));
    links.forEach((link) => {
        link.addEventListener('click', () => {
            sessionStorage.setItem(key, String(window.scrollY || window.pageYOffset || 0));
        });
    });
})();
</script>
@endsection
