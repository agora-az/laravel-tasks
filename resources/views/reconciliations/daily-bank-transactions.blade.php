@extends('layouts.app')

@section('title', 'Bank Daily Transactions')

@section('content')
@php
    $formattedDate = \Carbon\Carbon::parse($date)->format('F j, Y');
    $isOnlyFundservBank = $onlyFundservBank ?? false;
@endphp
<div style="display: flex; justify-content: space-between; align-items: center; margin: 20px 0; gap: 12px; flex-wrap: wrap;">
    <h2 style="margin: 0;">Bank Daily Transactions</h2>
    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
        <a href="{{ route('reconciliations.daily-totals', ['date_from' => $date, 'date_to' => $date]) }}"
           style="display: inline-flex; align-items: center; gap: 8px; background: #fff; border: 1px solid #90cdf4; color: #2b6cb0; border-radius: 6px; padding: 5px 14px; font-size: 13px; font-weight: 600; cursor: pointer; white-space: nowrap; text-decoration: none;">
            <span>Back to Daily Totals</span>
        </a>
    </div>
</div>

<div class="card" style="margin-bottom: 16px; padding: 22px 24px; background: linear-gradient(135deg, #ebf8ff 0%, #e8f4fd 100%); border: 1px solid #90cdf4; color: #2c5282; box-shadow: 0 4px 14px rgba(49, 130, 206, 0.10);">
    <div style="font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.08em; color: #2b6cb0; margin-bottom: 8px;">Criteria</div>
    <ul style="margin: 0; padding-left: 20px; font-size: 15px; font-weight: 400; line-height: 1.55; color: #2c5282;">
        <li><strong>Transaction Date</strong> is {{ $formattedDate }}</li>
        <li>
            @if($isOnlyFundservBank)
                Calculating only bank transactions where counterparty contains "fundserv"
            @else
                Calculating all bank transactions
            @endif
        </li>
    </ul>
</div>

<div class="card" style="padding: 0; margin-bottom: 16px;">
    <div id="daily-sticky-banner" style="position: sticky; top: 0; z-index: 11; padding: 14px 20px; background: linear-gradient(90deg, #ebf8ff, #f0fff4); border-bottom: 1px solid #bee3f8; display: grid; grid-template-columns: 1fr 1fr 1fr; align-items: center;">
        <div style="display: flex; flex-direction: column; gap: 2px;">
            <span style="font-size: 11px; font-weight: 700; color: #2c5282; text-transform: uppercase; letter-spacing: 0.08em;">Transaction Date</span>
            <span style="font-size: 24px; font-weight: 700; color: #1a365d; line-height: 1.1;">{{ $formattedDate }}</span>
        </div>
        <div style="display: flex; flex-direction: column; gap: 2px; align-items: center;">
            <span style="font-size: 11px; font-weight: 700; color: #2c5282; text-transform: uppercase; letter-spacing: 0.08em;">Total</span>
            <span style="font-size: 24px; font-weight: 700; color: {{ ($summary->net_total ?? 0) < 0 ? '#c53030' : '#276749' }}; line-height: 1.1;">{{ ($summary->net_total ?? 0) < 0 ? '($' . number_format(abs((float) ($summary->net_total ?? 0)), 2) . ')' : '$' . number_format((float) ($summary->net_total ?? 0), 2) }}</span>
        </div>
        <div style="display: flex; gap: 8px; justify-content: flex-end; align-items: center;">
            <div style="position: relative;" id="bank-export-wrap">
                <button id="bank-export-btn" type="button"
                        style="background: #fff; border: 1px solid #90cdf4; color: #2b6cb0; border-radius: 6px; padding: 5px 14px; font-size: 13px; font-weight: 600; cursor: pointer; white-space: nowrap; display: inline-flex; align-items: center; gap: 8px;">
                    <span>↓ Export</span>
                    <span>▾</span>
                </button>
                <div id="bank-export-panel" style="display: none; position: absolute; right: 0; top: calc(100% + 6px); z-index: 30; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 4px 16px rgba(0,0,0,0.12); min-width: 160px; overflow: hidden;">
                    <a href="{{ route('reconciliations.daily-totals.bank-day.export', ['date' => $date, 'only_fundserv_bank' => $isOnlyFundservBank ? 1 : 0, 'format' => 'csv']) }}"
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
                    <a href="{{ route('reconciliations.daily-totals.bank-day.export', ['date' => $date, 'only_fundserv_bank' => $isOnlyFundservBank ? 1 : 0, 'format' => 'excel']) }}"
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

            <div style="position: relative;" id="bank-col-toggle-wrap">
                <button id="bank-col-toggle-btn" type="button"
                        style="background: #fff; border: 1px solid #cbd5e0; color: #4a5568; border-radius: 6px; padding: 5px 14px; font-size: 13px; font-weight: 600; cursor: pointer; white-space: nowrap; display: inline-flex; align-items: center; gap: 8px;">
                    <span>☰</span>
                    <span>Columns</span>
                </button>
                <div id="bank-col-toggle-panel" style="display: none; position: absolute; right: 0; top: calc(100% + 6px); z-index: 30; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 4px 16px rgba(0,0,0,0.12); padding: 12px 16px; min-width: 190px;">
                    <div style="font-size: 11px; font-weight: 700; color: #a0aec0; text-transform: uppercase; letter-spacing: .07em; margin-bottom: 8px;">Toggle Columns</div>
                    <div id="bank-col-toggle-list" style="display: flex; flex-direction: column; gap: 6px;"></div>
                </div>
            </div>
        </div>
    </div>

<div class="card" style="padding: 0; margin-bottom: 16px;">
    @if($transactions->count())
        <div id="table-scroll-wrapper" style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; min-width: 900px;" class="mono-grid">
                <thead>
                    <tr style="background: #f7fafc; border-bottom: 2px solid #e2e8f0;">
                        <th data-col="id" style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748; white-space: nowrap;">ID</th>
                        <th data-col="dir" style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Dir</th>
                        <th data-col="amount" style="padding: 12px; text-align: right; font-weight: 600; color: #2d3748;">Amount</th>
                        <th data-col="memo-type" style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Memo Type</th>
                        <th data-col="counterparty" style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Counterparty</th>
                        <th data-col="settlement-number" style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Settlement #</th>
                        <th data-col="wire-ref" style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Wire Ref</th>
                        <th data-col="description" style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Description</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($transactions as $txn)
                        @php $isCredit = $txn->credit_debit_indicator === 'CRDT'; @endphp
                        <tr style="border-bottom: 1px solid #e2e8f0; background: {{ $loop->even ? 'rgba(56, 161, 105, 0.07)' : 'transparent' }}">
                            <td data-col="id" style="padding: 12px; color: #4a5568; font-family: monospace; font-size: 12px; white-space: nowrap;">{{ $txn->id }}</td>
                            <td data-col="dir" style="padding: 12px; font-family: monospace; font-size: 12px;">
                                <span style="background: {{ $isCredit ? '#c6f6d5' : '#fed7d7' }}; color: {{ $isCredit ? '#22543d' : '#742a2a' }}; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">{{ $txn->credit_debit_indicator }}</span>
                            </td>
                            <td data-col="amount" style="padding: 12px; text-align: right; font-weight: 600; color: {{ $isCredit ? '#2f855a' : '#c53030' }}; font-family: monospace; white-space: nowrap;">{{ '$' . number_format((float) $txn->amount, 2) }}</td>
                            <td data-col="memo-type" style="padding: 12px; color: #4a5568; font-family: monospace; font-size: 12px;">{{ $txn->memo_type ?: '—' }}</td>
                            <td data-col="counterparty" style="padding: 12px; color: #4a5568; font-family: monospace; font-size: 12px;">{{ $txn->counterparty ?: '—' }}</td>
                            <td data-col="settlement-number" style="padding: 12px; color: #4a5568; font-family: monospace; font-size: 12px; white-space: nowrap;">{{ $txn->settlement_number ?: '—' }}</td>
                            <td data-col="wire-ref" style="padding: 12px; color: #4a5568; font-family: monospace; font-size: 12px; white-space: nowrap;">{{ $txn->wire_payment_reference ?: '—' }}</td>
                            <td data-col="description" style="padding: 12px; color: #4a5568; font-family: monospace; font-size: 12px; max-width: 380px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="{{ $txn->additional_info }}">{{ $txn->additional_info ?: '—' }}</td>
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
            $pages = array_unique(array_filter([1, max(1, $cur - 1), $cur, min($last, $cur + 1), $last], fn($p) => $p >= 1 && $p <= $last));
            sort($pages);
        @endphp
        <div style="padding: 14px 16px; border-top: 1px solid #e2e8f0; display: flex; align-items: center; gap: 14px; font-size: 13px; color: #4a5568; flex-wrap: wrap;">
            <div style="display:flex;align-items:center;gap:6px;">
                <label for="per-page-select" style="color:#718096;white-space:nowrap;">Rows per page:</label>
                <select id="per-page-select"
                        onchange="window.location = this.value"
                        style="border:1px solid #cbd5e0;border-radius:4px;padding:4px 8px;font-size:13px;color:#2d3748;background:#fff;cursor:pointer;">
                    @foreach ([50, 100, 250] as $opt)
                        <option value="{{ request()->fullUrlWithQuery(['per_page' => $opt, 'page' => 1]) }}" {{ (int) request('per_page', 100) === $opt ? 'selected' : '' }}>{{ $opt }}</option>
                    @endforeach
                </select>
            </div>

            <span style="color:#718096;white-space:nowrap;">
                Showing {{ number_format($transactions->firstItem()) }}–{{ number_format(min($transactions->lastItem(), $transactions->total())) }} of {{ number_format($transactions->total()) }} transactions
            </span>

            @if ($last > 1)
                <div style="display:flex;align-items:center;gap:4px;margin-left:auto;">
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
                            <a href="{{ $transactions->url($p) }}{{ request('per_page', 100) != 100 ? '&per_page='.request('per_page') : '' }}" style="{{ $pgBtnBase }}">{{ $p }}</a>
                        @endif
                        @php $prevPage = $p; @endphp
                    @endforeach

                    @if ($transactions->hasMorePages())
                        <a href="{{ $transactions->nextPageUrl() }}" style="{{ $pgBtnBase }}">›</a>
                    @else
                        <span style="{{ $pgBtnDisabled }}">›</span>
                    @endif
                </div>
            @endif
        </div>
    @else
        <p style="color: #718096; text-align: center; padding: 40px 0;">No bank transactions found for this date.</p>
    @endif
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const exportWrap = document.getElementById('bank-export-wrap');
    const exportButton = document.getElementById('bank-export-btn');
    const exportPanel = document.getElementById('bank-export-panel');
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

    const colWrap = document.getElementById('bank-col-toggle-wrap');
    const colButton = document.getElementById('bank-col-toggle-btn');
    const colPanel = document.getElementById('bank-col-toggle-panel');
    const colList = document.getElementById('bank-col-toggle-list');
    const table = document.querySelector('#table-scroll-wrapper table');
    if (!colWrap || !colButton || !colPanel || !colList || !table) return;

    const columns = [
        { key: 'id', label: 'ID' },
        { key: 'dir', label: 'Dir' },
        { key: 'amount', label: 'Amount' },
        { key: 'memo-type', label: 'Memo Type' },
        { key: 'counterparty', label: 'Counterparty' },
        { key: 'settlement-number', label: 'Settlement #' },
        { key: 'wire-ref', label: 'Wire Ref' },
        { key: 'description', label: 'Description' },
    ];

    const setColumnVisible = (key, visible) => {
        document.querySelectorAll('[data-col="' + key + '"]').forEach((cell) => {
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
