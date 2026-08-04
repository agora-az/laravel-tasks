@extends('layouts.app')

@section('title', 'VieFund Daily Transactions')

@section('content')
@php
    $formattedDate = \Carbon\Carbon::parse($date)->format('F j, Y');
@endphp
<div style="display: flex; justify-content: space-between; align-items: center; margin: 20px 0; gap: 12px; flex-wrap: wrap;">
    <h2 style="margin: 0;">VieFund Daily Transactions</h2>
    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
        <a href="{{ route('reconciliations.daily-totals', ['date_from' => $date, 'date_to' => $date]) }}"
           style="display: inline-flex; align-items: center; gap: 8px; background: #fff; border: 1px solid #90cdf4; color: #2b6cb0; border-radius: 6px; padding: 5px 14px; font-size: 13px; font-weight: 600; cursor: pointer; white-space: nowrap; text-decoration: none;">
            <span>Back to Daily Totals</span>
        </a>
    </div>
</div>

<div class="card" style="margin-bottom: 16px; padding: 22px 24px; background: linear-gradient(135deg, #edfdf9 0%, #f0fff4 100%); border: 1px solid #9ae6b4; color: #22543d; box-shadow: 0 4px 14px rgba(72, 187, 120, 0.12);">
    <div style="font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.08em; color: #2f855a; margin-bottom: 8px;">Criteria</div>
    <ul style="margin: 0; padding-left: 20px; font-size: 15px; font-weight: 400; line-height: 1.55; color: #22543d;">
        <li><strong>{{ $basisLabel ?? 'Settlement date' }}</strong> is {{ $formattedDate }}</li>
        <li>Audited CAD direct cash transactions with status: <strong>{{ $fundCriteria ?? 'Confirmed' }}</strong></li>
        <li style="list-style: none; margin-left: -20px; margin-top: 6px;">
            <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-weight: 400;">
                <input type="checkbox" id="hide-zero-toggle" {{ ($hideZero ?? false) ? 'checked' : '' }}>
                <strong>Exclude $0 transactions</strong>
            </label>
        </li>
    </ul>
</div>

@if(isset($liveSummary) && ((int) $liveSummary->transaction_count !== (int) $summary->transaction_count || abs((float) $liveSummary->net_total - (float) $summary->net_total) >= 0.005))
    <div style="margin-bottom:16px;padding:10px 14px;border:1px solid #f6e05e;border-radius:6px;background:#fffaf0;color:#744210;font-size:13px;">
        The audited snapshot is {{ number_format((int) $summary->transaction_count) }} transactions / ${{ number_format((float) $summary->net_total, 2) }}, while the current matching VieFund rows are {{ number_format((int) $liveSummary->transaction_count) }} / ${{ number_format((float) $liveSummary->net_total, 2) }}. Resync Daily Totals to audit the change.
    </div>
@endif

<script>
document.addEventListener('DOMContentLoaded', function () {
    const exportWrap = document.getElementById('daily-export-wrap');
    const exportButton = document.getElementById('daily-export-btn');
    const exportPanel = document.getElementById('daily-export-panel');
    if (exportWrap && exportButton && exportPanel) {
        const closeExportPanel = () => { exportPanel.style.display = 'none'; };

        exportButton.addEventListener('click', function (event) {
            event.stopPropagation();
            exportPanel.style.display = exportPanel.style.display === 'block' ? 'none' : 'block';
        });

        document.addEventListener('click', function (event) {
            if (!exportWrap.contains(event.target)) closeExportPanel();
        });
    }

    const colWrap = document.getElementById('daily-col-toggle-wrap');
    const colButton = document.getElementById('daily-col-toggle-btn');
    const colPanel = document.getElementById('daily-col-toggle-panel');
    const colList = document.getElementById('daily-col-toggle-list');
    const table = document.querySelector('#table-scroll-wrapper table');
    if (!colWrap || !colButton || !colPanel || !colList || !table) return;

    const columns = [
        { key: 'trx-id', label: 'Txn ID', checked: true },
        { key: 'source', label: 'Source', checked: true },
        { key: 'customer-name', label: 'Customer Name', checked: true },
        { key: 'trx-type', label: 'Txn Type', checked: true },
        { key: 'order-status', label: 'Order Status', checked: true },
        { key: 'notes', label: 'Notes', checked: true },
        { key: 'amount', label: 'Amount', checked: true },
        { key: 'created-date', label: 'Created Date', checked: true },
        { key: 'trade-date', label: 'Trade Date', checked: true },
        { key: 'processing-date', label: 'Processing Date', checked: true },
        { key: 'settlement-date', label: 'Settlement Date', checked: true },
    ];

    const setColumnVisible = (key, visible) => {
        table.querySelectorAll('[data-col="' + key + '"]').forEach((cell) => {
            cell.style.display = visible ? '' : 'none';
        });
    };

    columns.forEach((column) => {
        const row = document.createElement('label');
        row.style.cssText = 'display:flex;align-items:center;gap:8px;font-size:13px;color:#2d3748;cursor:pointer;';
        row.innerHTML = '<input type="checkbox" checked style="accent-color:#2b6cb0;"> <span>' + column.label + '</span>';
        const checkbox = row.querySelector('input');

        checkbox.addEventListener('change', function () {
            setColumnVisible(column.key, this.checked);
        });

        colList.appendChild(row);
        setColumnVisible(column.key, true);
    });

    colButton.addEventListener('click', function (event) {
        event.stopPropagation();
        colPanel.style.display = colPanel.style.display === 'block' ? 'none' : 'block';
    });

    document.addEventListener('click', function (event) {
        if (!colWrap.contains(event.target)) {
            colPanel.style.display = 'none';
        }
    });
});
</script>

<div class="card" style="padding: 0; margin-bottom: 16px;">
        <div id="daily-sticky-banner" style="position: sticky; top: 0; z-index: 11; padding: 14px 20px; background: linear-gradient(90deg, #ebf8ff, #f0fff4); border-bottom: 1px solid #bee3f8; display: grid; grid-template-columns: 1fr 1fr 1fr; align-items: center;">
        <div style="display: flex; flex-direction: column; gap: 2px;">
            <span style="font-size: 11px; font-weight: 700; color: #2c5282; text-transform: uppercase; letter-spacing: 0.08em;">{{ \Illuminate\Support\Str::title($basisLabel ?? 'Settlement date') }}</span>
            <span style="font-size: 24px; font-weight: 700; color: #1a365d; line-height: 1.1;">{{ $formattedDate }}</span>
        </div>
        <div style="display: flex; flex-direction: column; gap: 2px; align-items: center;">
            <span style="font-size: 11px; font-weight: 700; color: #2c5282; text-transform: uppercase; letter-spacing: 0.08em;">Total</span>
            <span style="font-size: 24px; font-weight: 700; color: {{ ($summary->net_total ?? 0) < 0 ? '#c53030' : '#276749' }}; line-height: 1.1;">{{ ($summary->net_total ?? 0) < 0 ? '($' . number_format(abs((float) ($summary->net_total ?? 0)), 2) . ')' : '$' . number_format((float) ($summary->net_total ?? 0), 2) }}</span>
        </div>
        <div style="display: flex; gap: 8px; justify-content: flex-end; align-items: center;">
            <div style="position: relative;" id="daily-export-wrap">
                <button id="daily-export-btn" type="button"
                        style="background: #fff; border: 1px solid #90cdf4; color: #2b6cb0; border-radius: 6px; padding: 5px 14px; font-size: 13px; font-weight: 600; cursor: pointer; white-space: nowrap; display: inline-flex; align-items: center; gap: 8px;">
                    <span>↓ Export</span>
                    <span>▾</span>
                </button>
                <div id="daily-export-panel" style="display: none; position: absolute; right: 0; top: calc(100% + 6px); z-index: 30; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 4px 16px rgba(0,0,0,0.12); min-width: 160px; overflow: hidden;">
                    <a href="{{ route('reconciliations.daily-totals.viefund-day.export', ['date' => $date, 'variant' => $criteriaKey ?? request('variant'), 'hide_zero' => ($hideZero ?? false) ? 1 : null, 'format' => 'csv']) }}"
                       style="display: block; padding: 10px 16px; font-size: 13px; font-weight: 600; color: #2b6cb0; text-decoration: none; border-bottom: 1px solid #f0f4f8;"
                       onmouseover="this.style.background='#ebf8ff'" onmouseout="this.style.background=''">
                        <span style="display:inline-flex; align-items:center; gap:8px;">
                            <svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true" focusable="false" style="flex:0 0 auto;">
                                <path d="M3 1.75h6l3.5 3.5V14.25H3V1.75Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
                                <path d="M9 1.75V5.25H12.5" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
                                <path d="M5 9.25h6M5 11.75h6" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                            </svg>
                            <span>CSV</span>
                        </span>
                    </a>
                    <a href="{{ route('reconciliations.daily-totals.viefund-day.export', ['date' => $date, 'variant' => $criteriaKey ?? request('variant'), 'hide_zero' => ($hideZero ?? false) ? 1 : null, 'format' => 'excel']) }}"
                       style="display: block; padding: 10px 16px; font-size: 13px; font-weight: 600; color: #276749; text-decoration: none;"
                       onmouseover="this.style.background='#f0fff4'" onmouseout="this.style.background=''">
                        <span style="display:inline-flex; align-items:center; gap:8px;">
                            <svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true" focusable="false" style="flex:0 0 auto;">
                                <path d="M3 1.75h6l3.5 3.5V14.25H3V1.75Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
                                <path d="M9 1.75V5.25H12.5" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
                                <path d="M4.75 9.25h6.5M4.75 11.75h6.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                                <path d="M6 7.75h4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                            </svg>
                            <span>Excel</span>
                        </span>
                    </a>
                </div>
            </div>
            <div style="position: relative;" id="daily-col-toggle-wrap">
                <button id="daily-col-toggle-btn" type="button"
                        style="background: #fff; border: 1px solid #cbd5e0; color: #4a5568; border-radius: 6px; padding: 5px 14px; font-size: 13px; font-weight: 600; cursor: pointer; white-space: nowrap; display: inline-flex; align-items: center; gap: 8px;">
                    <span>☰</span>
                    <span>Columns</span>
                </button>
                <div id="daily-col-toggle-panel" style="display: none; position: absolute; right: 0; top: calc(100% + 6px); z-index: 30; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 4px 16px rgba(0,0,0,0.12); padding: 12px 16px; min-width: 190px;">
                    <div style="font-size: 11px; font-weight: 700; color: #a0aec0; text-transform: uppercase; letter-spacing: .07em; margin-bottom: 8px;">Toggle Columns</div>
                    <div id="daily-col-toggle-list" style="display: flex; flex-direction: column; gap: 6px;"></div>
                </div>
            </div>
        </div>
        </div>
    @if($transactions->count() > 0)
        @php
            $toTwoLineDate = function ($value) {
                if (!$value) {
                    return ['—', '--:--'];
                }

                $parts = preg_split('/\s+/', trim((string) $value));
                return [
                    $parts[0] ?? '—',
                    $parts[1] ?? '--:--',
                ];
            };
        @endphp

        <div id="table-scroll-wrapper" style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; min-width: 900px;">
                <thead>
                    <tr style="background: #f7fafc; border-bottom: 2px solid #e2e8f0;">
                        <th data-col="trx-id" style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748; white-space: nowrap;">Txn ID</th>
                        <th data-col="source" style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Source</th>
                        <th data-col="customer-name" style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Customer Name</th>
                        <th data-col="trx-type" style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Txn Type</th>
                        <th data-col="order-status" style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Order Status</th>
                        <th data-col="notes" style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Notes</th>
                        <th data-col="amount" style="padding: 12px; text-align: right; font-weight: 600; color: #2d3748;">Amount</th>
                        <th data-col="created-date" style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Created Date</th>
                        <th data-col="trade-date" style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Trade Date</th>
                        <th data-col="processing-date" style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Processing Date</th>
                        <th data-col="settlement-date" style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Settlement Date</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($transactions as $txn)
                        @php
                            [$createdDate, $createdTime] = $toTwoLineDate($txn->created_date);
                            [$tradeDate, $tradeTime] = $toTwoLineDate($txn->trade_date);
                            [$processingDate, $processingTime] = $toTwoLineDate($txn->processing_date);
                            [$settlementDate, $settlementTime] = $toTwoLineDate($txn->settlement_date);
                            $rowSource = data_get($txn, 'row_source', 'fund');
                        @endphp
                        <tr style="border-bottom: 1px solid #e2e8f0;">
                            <td data-col="trx-id" style="padding: 12px; color: #4a5568; font-family: monospace; font-size: 12px; white-space: nowrap;">{{ $txn->trx_id }}</td>
                            <td data-col="source" style="padding: 12px; color: #4a5568; font-family: monospace; font-size: 12px;">
                                <span style="display:inline-block; padding:1px 7px; border-radius:10px; font-size:11px; font-weight:700; {{ $rowSource === 'trust' ? 'background:#e9d8fd; color:#553c9a;' : 'background:#c6f6d5; color:#22543d;' }}">{{ ucfirst($rowSource) }}</span>
                            </td>
                            <td data-col="customer-name" style="padding: 12px; color: #2d3748; font-family: monospace;">{{ $txn->client_name ?: '—' }}</td>
                            <td data-col="trx-type" style="padding: 12px; color: #4a5568; font-family: monospace; font-size: 12px; white-space: nowrap;" title="{{ $txn->trx_type }}">{{ $txn->trx_type ?: '—' }}</td>
                            <td data-col="order-status" style="padding: 12px; color: #4a5568; font-family: monospace; font-size: 12px;">{{ data_get($txn, 'status', data_get($txn, 'order_status', '—')) }}</td>
                            <td data-col="notes" style="padding: 12px; color: #4a5568; font-family: monospace; font-size: 12px; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="{{ $txn->notes }}">{{ $txn->notes ?: '—' }}</td>
                            <td data-col="amount" style="padding: 12px; text-align: right; color: {{ (float) $txn->amount < 0 ? '#e53e3e' : '#276749' }}; font-weight: 500; font-family: monospace; white-space: nowrap;">
                                {{ (float) $txn->amount < 0 ? '($' . number_format(abs((float) $txn->amount), 2) . ')' : '$' . number_format((float) $txn->amount, 2) }}
                            </td>
                            <td data-col="created-date" style="padding: 12px; color: #4a5568; font-family: monospace; white-space: nowrap; line-height: 1.2;">
                                <span style="display: block;">{{ $createdDate }}</span>
                                <span style="display: block; opacity: 0.9;">{{ $createdTime }}</span>
                            </td>
                            <td data-col="trade-date" style="padding: 12px; color: #4a5568; font-family: monospace; white-space: nowrap; line-height: 1.2;">
                                <span style="display: block;">{{ $tradeDate }}</span>
                                <span style="display: block; opacity: 0.9;">{{ $tradeTime }}</span>
                            </td>
                            <td data-col="processing-date" style="padding: 12px; color: #4a5568; font-family: monospace; white-space: nowrap; line-height: 1.2;">
                                <span style="display: block;">{{ $processingDate }}</span>
                                <span style="display: block; opacity: 0.9;">{{ $processingTime }}</span>
                            </td>
                            <td data-col="settlement-date" style="padding: 12px; color: #4a5568; font-family: monospace; white-space: nowrap; line-height: 1.2;">
                                <span style="display: block;">{{ $settlementDate }}</span>
                                <span style="display: block; opacity: 0.9;">{{ $settlementTime }}</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @php
            $cur  = $transactions->currentPage();
            $last = $transactions->lastPage();
            $pgBtnBase = 'display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:30px;padding:0 8px;border-radius:4px;font-size:13px;text-decoration:none;border:1px solid #cbd5e0;color:#4a5568;';
            $pgBtnActive = 'display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:30px;padding:0 8px;border-radius:4px;font-size:13px;font-weight:700;border:2px solid #4299e1;color:#2b6cb0;background:#ebf8ff;';
            $pgBtnDisabled = 'display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:30px;padding:0 8px;border-radius:4px;font-size:13px;border:1px solid #e2e8f0;color:#cbd5e0;cursor:default;';
            $pages = array_unique(array_filter([1, max(1,$cur-1), $cur, min($last,$cur+1), $last], fn($p) => $p >= 1 && $p <= $last));
            sort($pages);
        @endphp
        <div style="padding: 14px 16px; border-top: 1px solid #e2e8f0; display: flex; align-items: center; gap: 14px; font-size: 13px; color: #4a5568; flex-wrap: wrap;">

            {{-- Per-page dropdown --}}
            <div style="display:flex;align-items:center;gap:6px;">
                <label for="per-page-select" style="color:#718096;white-space:nowrap;">Rows per page:</label>
                <select id="per-page-select"
                        onchange="window.location = this.value"
                        style="border:1px solid #cbd5e0;border-radius:4px;padding:4px 8px;font-size:13px;color:#2d3748;background:#fff;cursor:pointer;">
                    @foreach ([50, 100, 250] as $opt)
                        <option value="{{ request()->fullUrlWithQuery(['per_page' => $opt, 'page' => 1]) }}"
                                {{ (int) request('per_page', 250) === $opt ? 'selected' : '' }}>{{ $opt }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Summary --}}
            <span style="color:#718096;white-space:nowrap;">
                Showing {{ number_format($transactions->firstItem()) }}–{{ number_format(min($transactions->lastItem(), $transactions->total())) }} of {{ number_format($transactions->total()) }} transactions
            </span>

            {{-- Page buttons --}}
            @if ($last > 1)
                <div style="display:flex;align-items:center;gap:4px;margin-left:auto;">
                    {{-- Prev --}}
                    @if ($transactions->onFirstPage())
                        <span style="{{ $pgBtnDisabled }}">‹</span>
                    @else
                        <a href="{{ $transactions->previousPageUrl() }}" style="{{ $pgBtnBase }}">‹</a>
                    @endif

                    @php $prevPage = null; @endphp
                    @foreach ($pages as $p)
                        @if ($prevPage !== null && $p > $prevPage + 1)
                            <span style="{{ $pgBtnDisabled }}">…</span>
                        @endif
                        @if ($p === $cur)
                            <span style="{{ $pgBtnActive }}">{{ $p }}</span>
                        @else
                            <a href="{{ $transactions->url($p) }}{{ request('per_page', 250) != 250 ? '&per_page='.request('per_page') : '' }}" style="{{ $pgBtnBase }}">{{ $p }}</a>
                        @endif
                        @php $prevPage = $p; @endphp
                    @endforeach

                    {{-- Next --}}
                    @if ($transactions->hasMorePages())
                        <a href="{{ $transactions->nextPageUrl() }}" style="{{ $pgBtnBase }}">›</a>
                    @else
                        <span style="{{ $pgBtnDisabled }}">›</span>
                    @endif
                </div>
            @endif
        </div>
    @else
        <p style="color: #718096; text-align: center; padding: 40px 0;">No VieFund transactions found for this date.</p>
    @endif
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const toggle = document.getElementById('hide-zero-toggle');
    if (!toggle) return;
    toggle.addEventListener('change', function () {
        const u = new URL(window.location.href);
        if (this.checked) {
            u.searchParams.set('hide_zero', '1');
        } else {
            u.searchParams.delete('hide_zero');
        }
        u.searchParams.delete('viefund_page'); // back to page 1
        window.location.assign(u.toString());
    });
});
</script>

<script>
(function initStickyHeader() {
    const scrollWrapper = document.getElementById('table-scroll-wrapper');
    if (!scrollWrapper) return;

    const realTr = scrollWrapper.querySelector('thead tr');
    if (!realTr) return;

    const stickyWrap = document.createElement('div');
    stickyWrap.id = 'daily-sticky-thead-wrap';
    stickyWrap.style.cssText = 'position:sticky;top:0;z-index:10;overflow:hidden;' +
        'display:none;background:#f7fafc;border-bottom:2px solid #e2e8f0;';

    const stickyTable = document.createElement('table');
    stickyTable.style.cssText = 'border-collapse:collapse;table-layout:fixed;';

    const clonedTr = realTr.cloneNode(true);
    const clonedThead = document.createElement('thead');
    clonedThead.appendChild(clonedTr);
    stickyTable.appendChild(clonedThead);
    stickyWrap.appendChild(stickyTable);

    scrollWrapper.parentNode.insertBefore(stickyWrap, scrollWrapper);

    function syncWidths() {
        const origThs = realTr.querySelectorAll('th');
        const cloneThs = clonedTr.querySelectorAll('th');
        let total = 0;

        origThs.forEach((th, index) => {
            const width = th.getBoundingClientRect().width;
            total += width;
            if (cloneThs[index]) cloneThs[index].style.width = width + 'px';
        });

        stickyTable.style.width = total + 'px';
    }

    function onScroll() {
        const rect = scrollWrapper.getBoundingClientRect();
        const banner = document.getElementById('daily-sticky-banner');
        const bannerHeight = (banner && banner.offsetHeight > 0) ? banner.offsetHeight : 0;
        const shouldShow = rect.top < bannerHeight && rect.bottom > bannerHeight;

        if (shouldShow) {
            if (stickyWrap.style.display === 'none') {
                syncWidths();
                stickyWrap.scrollLeft = scrollWrapper.scrollLeft;
            }

            stickyWrap.style.top = bannerHeight + 'px';
            stickyWrap.style.display = 'block';
        } else {
            stickyWrap.style.display = 'none';
        }
    }

    window.addEventListener('scroll', onScroll, { passive: true });
    scrollWrapper.addEventListener('scroll', () => {
        if (stickyWrap.style.display !== 'none') {
            stickyWrap.scrollLeft = scrollWrapper.scrollLeft;
        }
    });
    window.addEventListener('resize', syncWidths);

    requestAnimationFrame(() => requestAnimationFrame(() => {
        syncWidths();
        onScroll();
    }));
})();
</script>
@endsection
