@extends('layouts.app')

@section('title', 'VieFund Transactions')

@section('content')
<div style="margin:20px 0;">
    <div style="font-size:12px;font-weight:800;color:#2c5282;text-transform:uppercase;letter-spacing:.07em;">VieFund Transactions</div>
    <h2 style="margin:4px 0 0;">All Transactions</h2>
    <div style="color:#718096;font-size:13px;margin-top:4px;">One row per VieFund cash-ledger transaction, with related fund and trust identifiers for tracing.</div>
    <div style="color:#718096;font-size:12px;margin-top:3px;">Trust activity without a related cash-ledger entry is excluded from this reconciliation view and its totals.</div>
</div>

@php
    $hasFilters = $search !== ''
        || !empty($filters['customer_name'])
        || !empty($filters['plan_account_id'])
        || !empty($filters['trx_id'])
        || !empty($filters['source_id'])
        || !empty($filters['date_from'])
        || !empty($filters['date_to'])
        || !empty($filters['trx_type'])
        || $dateBasis !== 'settlement_date'
        || $hasEftMatch
        || $hasAgraFspMatch
        || $has7960FspMatch
        || !empty($matchStatuses)
        || $currencyCode !== '00'
        || $statusIds !== [6];
    $dateBasisLabel = $dateBasisOptions[$dateBasis] ?? 'Settlement date';
    $currencyLabel = $currencyOptions[$currencyCode] ?? $currencyCode;
    $sortLabel = $sortOptions[$sort] ?? 'Settlement Date';
    $sortUrl = function (string $column) use ($sort, $sortDirection): string {
        $nextDirection = $sort === $column && $sortDirection === 'desc' ? 'asc' : 'desc';

        return request()->fullUrlWithQuery([
            'sort' => $column,
            'sort_dir' => $nextDirection,
            'page' => 1,
        ]);
    };
    $sortIndicator = fn(string $column): string => $sort === $column
        ? ($sortDirection === 'asc' ? ' ↑' : ' ↓')
        : ' ⇅';
    $coreHeadings = [];
    foreach($visibleTransactionColumns as $key => $definition) {
        $coreHeadings[] = array_merge($definition, ['key' => $key]);
    }
    $eftHeadings = array_values($visibleEftColumns);
    $bankSummaryHeadings = array_values($visibleBankSummaryColumns);
    $bankHeadings = array_values($visibleBankDetailColumns);
    $fspHeadings = array_values($visibleFspColumns);
@endphp
<details class="card" style="padding:0;margin-bottom:20px;" open>
    <summary style="display:flex;align-items:center;justify-content:space-between;padding:14px 20px;background:#edf2f7;cursor:pointer;list-style:none;border-radius:6px;font-size:12px;font-weight:800;color:#4a5568;text-transform:uppercase;letter-spacing:.08em;">
        <span>Transaction Filters</span>
        <span style="color:#718096;">Collapse⌄</span>
    </summary>
    <form action="{{ route('viefund-transactions.filters') }}" method="POST" style="padding:20px 24px;" data-inception-dates='@json($inceptionDates)'>
        @csrf
        <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px 24px;margin-bottom:16px;">
            <div>
                <label for="all-customer-search" style="display:block;font-size:12px;font-weight:800;color:#4a5568;margin-bottom:6px;">Customer</label>
                <input id="all-customer-search" name="filter_customer_name" value="{{ $filters['customer_name'] ?? '' }}" placeholder="Search by name…" autocomplete="off"
                       style="width:100%;height:40px;padding:8px 12px;border:1px solid #cbd5e0;border-radius:4px;box-sizing:border-box;font-size:13px;">
            </div>
            <div>
                <label for="all-plan-account" style="display:block;font-size:12px;font-weight:800;color:#4a5568;margin-bottom:6px;">Plan Account ID</label>
                <input id="all-plan-account" name="filter_plan_account_id" value="{{ $filters['plan_account_id'] ?? '' }}" placeholder="Search by account ID…"
                       style="width:100%;height:40px;padding:8px 12px;border:1px solid #cbd5e0;border-radius:4px;box-sizing:border-box;font-size:13px;">
            </div>
        </div>

        <div class="all-transactions-date-filters" style="display:grid;grid-template-columns:minmax(150px,1fr) auto minmax(150px,1fr) minmax(165px,1fr);gap:12px;align-items:end;margin-bottom:16px;">
            <div>
                <label for="all-date-from" style="display:block;font-size:12px;font-weight:800;color:#4a5568;margin-bottom:6px;">Start Date</label>
                <input type="date" id="all-date-from" name="filter_date_from" value="{{ $filters['date_from'] ?? '' }}" max="{{ now()->toDateString() }}" required
                       style="width:100%;height:40px;padding:8px 12px;border:1px solid #cbd5e0;border-radius:4px;box-sizing:border-box;font-size:13px;">
            </div>
            <div>
                <label id="all-inception-date-note" style="display:block;font-size:11px;color:#718096;margin-bottom:6px;white-space:nowrap;"></label>
                <button type="button" id="all-set-inception-date" style="height:40px;padding:0 12px;border:1px solid #cbd5e0;border-radius:4px;background:#e2e8f0;color:#2d3748;white-space:nowrap;cursor:pointer;">« Use Inception Date</button>
            </div>
            <div>
                <label for="all-date-to" style="display:block;font-size:12px;font-weight:800;color:#4a5568;margin-bottom:6px;">End Date</label>
                <input type="date" id="all-date-to" name="filter_date_to" value="{{ $filters['date_to'] ?? '' }}" max="{{ now()->toDateString() }}" required
                       style="width:100%;height:40px;padding:8px 12px;border:1px solid #cbd5e0;border-radius:4px;box-sizing:border-box;font-size:13px;">
            </div>
            <div>
                <label for="all-date-basis" style="display:block;font-size:12px;font-weight:800;color:#4a5568;margin-bottom:6px;">Date Basis</label>
                <select id="all-date-basis" name="filter_date_basis" style="width:100%;height:40px;padding:8px 12px;border:1px solid #cbd5e0;border-radius:4px;background:#fff;font-size:13px;">
                    @foreach($dateBasisOptions as $value => $label)
                        <option value="{{ $value }}" {{ $dateBasis === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:16px;">
            <div>
                <label for="all-currency-code" style="display:block;font-size:12px;font-weight:800;color:#4a5568;margin-bottom:6px;">Currency Code</label>
                <select id="all-currency-code" name="filter_currency_code" style="width:100%;height:40px;padding:8px 12px;border:1px solid #cbd5e0;border-radius:4px;background:#fff;font-size:13px;">
                    @foreach($currencyOptions as $value => $label)
                        <option value="{{ $value }}" {{ $currencyCode === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="all-trx-id" style="display:block;font-size:12px;font-weight:800;color:#4a5568;margin-bottom:6px;">Transaction ID</label>
                <input id="all-trx-id" name="filter_trx_id" value="{{ $filters['trx_id'] ?? '' }}" placeholder="Cash C-, fund F-, or trust T- ID"
                       style="width:100%;height:40px;padding:8px 12px;border:1px solid #cbd5e0;border-radius:4px;box-sizing:border-box;font-size:13px;font-family:monospace;">
            </div>
            <div>
                <label for="all-source-id" style="display:block;font-size:12px;font-weight:800;color:#4a5568;margin-bottom:6px;">Source ID</label>
                <input id="all-source-id" name="filter_source_id" value="{{ $filters['source_id'] ?? '' }}" placeholder="e.g. L20200…"
                       style="width:100%;height:40px;padding:8px 12px;border:1px solid #cbd5e0;border-radius:4px;box-sizing:border-box;font-size:13px;font-family:monospace;">
            </div>
        </div>

        <div class="all-transactions-secondary-filters">
            <fieldset class="all-transactions-filter-group">
                <legend>Transaction Type</legend>
                <select id="all-trx-types" name="filter_trx_type[]" multiple size="4"
                        style="width:100%;padding:7px 10px;border:1px solid #cbd5e0;border-radius:4px;background:#fff;font-size:13px;">
                    @foreach($availableTrxTypes as $type)
                        <option value="{{ $type }}" {{ in_array($type, (array)($filters['trx_type'] ?? []), true) ? 'selected' : '' }}>{{ $type }}</option>
                    @endforeach
                </select>
                <div class="all-transactions-filter-help">No selection means all transaction types.</div>
            </fieldset>

            <fieldset class="all-transactions-filter-group all-transactions-status-filter">
                <legend>Cash Transaction Status</legend>
                <select id="all-cash-statuses" name="filter_status[]" multiple size="4"
                        style="width:100%;padding:7px 10px;border:1px solid #cbd5e0;border-radius:4px;background:#fff;font-size:13px;">
                    @foreach($statusOptions as $value => $label)
                        <option value="{{ $value }}" {{ in_array($value, $statusIds, true) ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
                <div class="all-transactions-filter-help">If none are selected, Confirmed is used. This always applies to the cash-ledger status.</div>
            </fieldset>

            <fieldset class="all-transactions-filter-group all-transactions-match-filter">
                <legend>Match Filter</legend>
                <label class="all-transactions-match-option all-transactions-match-option-eft">
                    <input type="checkbox" name="filter_has_eft_match" value="1" {{ $hasEftMatch ? 'checked' : '' }}>
                    EFT matches only
                </label>
                <label class="all-transactions-match-option all-transactions-match-option-fsp">
                    <input type="checkbox" name="filter_has_agra_fsp_match" value="1" {{ $hasAgraFspMatch ? 'checked' : '' }}>
                    FSP (AGRA) matches only
                </label>
                <label class="all-transactions-match-option all-transactions-match-option-fsp-7960">
                    <input type="checkbox" name="filter_has_7960_fsp_match" value="1" {{ $has7960FspMatch ? 'checked' : '' }}>
                    FSP (7960) matches only
                </label>
                <div class="all-transactions-filter-help">FSP matches use the file Source ID = VieFund fund transaction SourceID.</div>
            </fieldset>
        </div>

        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px;padding-top:14px;border-top:1px solid #e2e8f0;">
            @if($hasFilters)
                <a href="{{ route('viefund-transactions.index') }}" class="btn" style="background:#718096;text-decoration:none;padding:8px 20px;font-size:13px;">Clear All</a>
            @endif
            <button type="submit" class="btn" style="padding:8px 24px;font-size:13px;">Apply Filters</button>
        </div>
    </form>
</details>

<style>
    .all-transactions-secondary-filters {
        display:grid;
        grid-template-columns:repeat(3,minmax(0,1fr));
        gap:12px;
        align-items:stretch;
    }
    .all-transactions-filter-group {
        min-width:0;
        margin:0;
        padding:10px 12px 12px;
        border:1px solid #d6e4e1;
        border-radius:6px;
        background:#f7fbfa;
    }
    .all-transactions-filter-group > legend {
        padding:0 6px;
        font-size:12px;
        font-weight:800;
        color:#4a5568;
    }
    .all-transactions-match-option {
        display:flex;
        align-items:center;
        gap:6px;
        min-width:0;
        color:#2d3748;
        font-size:13px;
        cursor:pointer;
    }
    .all-transactions-match-option input {
        flex:0 0 auto;
        width:16px;
        height:16px;
    }
    .all-transactions-match-filter {
        display:flex;
        flex-direction:column;
        gap:8px;
    }
    .all-transactions-match-option {
        padding:9px 10px;
        border:1px solid #d6e4e1;
        border-radius:5px;
    }
    .all-transactions-match-option-eft {
        background:#f0f9fd;
        border-color:#bee3f8;
    }
    .all-transactions-match-option-fsp,
    .all-transactions-match-option-fsp-7960 {
        background:#faf6fd;
        border-color:#e4d7ef;
    }
    .all-transactions-filter-help {
        margin-top:auto;
        padding-top:5px;
        color:#718096;
        font-size:11px;
        line-height:1.35;
    }
    .all-transactions-working-set-status {
        display:inline-flex;
        align-items:center;
        min-height:26px;
        padding:4px 9px;
        border:1px solid #f59e0b;
        border-radius:5px;
        background:#fffbeb;
        color:#92400e;
        font-size:11px;
        font-weight:700;
        line-height:1.35;
    }
    .all-transactions-working-set-status--ready {
        border-color:#10b981;
        background:#ecfdf5;
        color:#065f46;
    }
    .all-transactions-working-set-status--failed {
        border-color:#ef4444;
        background:#fef2f2;
        color:#991b1b;
    }
    .all-transactions-info {
        position:relative;
        display:inline-flex;
        align-items:center;
        justify-content:center;
        width:17px;
        height:17px;
        margin-left:5px;
        border:1px solid #718096;
        border-radius:50%;
        color:#4a5568;
        font-size:11px;
        font-weight:800;
        line-height:1;
        cursor:help;
        text-transform:none;
        letter-spacing:normal;
    }
    .all-transactions-info-popover {
        position:absolute;
        z-index:20;
        top:calc(100% + 8px);
        right:-8px;
        display:none;
        width:360px;
        max-width:80vw;
        padding:11px 13px;
        border:1px solid #cbd5e0;
        border-radius:6px;
        background:#fff;
        box-shadow:0 8px 20px rgba(45,55,72,.18);
        color:#4a5568;
        font-size:12px;
        font-weight:500;
        line-height:1.5;
        text-align:left;
        text-transform:none;
        letter-spacing:normal;
    }
    .all-transactions-info:hover .all-transactions-info-popover,
    .all-transactions-info:focus .all-transactions-info-popover,
    .all-transactions-info:focus-within .all-transactions-info-popover {
        display:block;
    }
    @keyframes all-transactions-eft-spin { to { transform:rotate(360deg); } }
    .all-transactions-eft-loading {
        display:inline-flex;
        align-items:center;
        gap:7px;
        color:#718096;
        white-space:nowrap;
    }
    .all-transactions-eft-loading::before {
        content:'';
        width:12px;
        height:12px;
        border:2px solid #cbd5e0;
        border-top-color:#2b6cb0;
        border-radius:50%;
        animation:all-transactions-eft-spin .75s linear infinite;
    }
    #all-transactions-table th.all-transactions-eft-column {
        background:#d9f0fb;
        color:#1a5276;
    }
    #all-transactions-table td.all-transactions-eft-column {
        background:#f0f9fd;
    }
    #all-transactions-table th.all-transactions-bank-column {
        background:#dff3e8;
        color:#22543d;
    }
    #all-transactions-table td.all-transactions-bank-column {
        background:#f1faf5;
    }
    #all-transactions-table th.all-transactions-reconciliation-column {
        background:#fff;
        color:#2d3748;
    }
    #all-transactions-table .all-transactions-reconciliation-start {
        border-left:3px solid #4a5568;
    }
    #all-transactions-table .all-transactions-reconciliation-end {
        border-right:3px solid #4a5568;
    }
    #all-transactions-table .all-transactions-reconciliation-column.all-transactions-bank-group-start {
        border-left:0;
    }
    #all-transactions-table th.all-transactions-fsp-column {
        background:#eadcf4;
        color:#553c7b;
    }
    #all-transactions-table td.all-transactions-fsp-column {
        background:#faf6fd;
    }
    #all-transactions-table .all-transactions-eft-group-start {
        border-left:3px solid #4299e1;
    }
    #all-transactions-table .all-transactions-bank-group-start {
        border-left:3px solid #38a169;
    }
    #all-transactions-table .all-transactions-fsp-group-start {
        border-left:3px solid #805ad5;
    }
    #all-transactions-table tbody td {
        vertical-align:top;
    }
    #all-transactions-table tbody td.all-transactions-reconciliation-column {
        border-bottom:1px solid rgba(74, 85, 104, .18);
    }
    #all-transactions-table td.all-transactions-reconciliation-column[data-match-status="complete"] {
        background:#c6f6d5 !important;
        color:#22543d !important;
    }
    #all-transactions-table .all-transactions-match-status-cell[data-match-status="complete"] {
        box-shadow:inset 5px 0 #38a169;
    }
    #all-transactions-table td.all-transactions-reconciliation-column[data-match-status="possible"] {
        background:#bee3f8 !important;
        color:#2a4365 !important;
    }
    #all-transactions-table .all-transactions-match-status-cell[data-match-status="possible"] {
        box-shadow:inset 5px 0 #4299e1;
    }
    #all-transactions-table td.all-transactions-reconciliation-column[data-match-status="verify"] {
        background:#fefcbf !important;
        color:#975a16 !important;
    }
    #all-transactions-table .all-transactions-match-status-cell[data-match-status="verify"] {
        box-shadow:inset 5px 0 #d69e2e;
    }
    #all-transactions-table td.all-transactions-reconciliation-column[data-match-status="unknown"],
    #all-transactions-table td.all-transactions-reconciliation-column[data-match-status="loading"] {
        background:#edf2f7 !important;
        color:#4a5568 !important;
    }
    #all-transactions-table .all-transactions-match-status-cell[data-match-status="unknown"],
    #all-transactions-table .all-transactions-match-status-cell[data-match-status="loading"] {
        box-shadow:inset 5px 0 #a0aec0;
    }
    #all-transactions-table td.all-transactions-reconciliation-column[data-match-status="error"] {
        background:#fed7d7 !important;
        color:#9b2c2c !important;
    }
    #all-transactions-table .all-transactions-match-status-cell[data-match-status="error"] {
        box-shadow:inset 5px 0 #e53e3e;
    }
    #all-transactions-table tbody tr {
        cursor:pointer;
    }
    #all-transactions-table tbody tr:hover > td {
        background:#e6fffa !important;
    }
    #all-transactions-table tbody tr:hover > td.all-transactions-reconciliation-column {
        background:#e6fffa !important;
        color:#234e52 !important;
    }
    #all-transactions-table tbody tr.all-transactions-row-selected > td,
    #all-transactions-table tbody tr.all-transactions-row-selected:hover > td {
        background:#fff5b8 !important;
        border-top-color:#d69e2e;
        border-bottom-color:#d69e2e;
    }
    #all-transactions-table tbody tr.all-transactions-row-selected > td.all-transactions-reconciliation-column,
    #all-transactions-table tbody tr.all-transactions-row-selected:hover > td.all-transactions-reconciliation-column {
        background:#fff5b8 !important;
        color:#744210 !important;
    }
    #all-transactions-table thead th {
        position:sticky;
        top:0;
        z-index:4;
        background:#f7fafc;
        box-shadow:inset 0 -2px 0 #cbd5e0;
    }
    .all-transactions-table-card {
        position:relative;
    }
    .all-transactions-table-toolbar {
        position:sticky;
        top:0;
        z-index:8;
        display:grid;
        grid-template-columns:minmax(190px,.75fr) minmax(510px,1.5fr) minmax(190px,.55fr);
        align-items:center;
        gap:24px;
        padding:16px 20px;
        border-radius:6px 6px 0 0;
        border-bottom:1px solid #bee3f8;
        background:linear-gradient(90deg,#ebf8ff,#f0fff4);
        box-shadow:0 2px 5px rgba(45,55,72,.12);
    }
    .all-transactions-toolbar-title-kicker,
    .all-transactions-toolbar-section-label {
        color:#2c5282;
        font-size:11px;
        font-weight:800;
        text-transform:uppercase;
        letter-spacing:.08em;
    }
    .all-transactions-toolbar-title {
        margin-top:3px;
        color:#1a365d;
        font-size:24px;
        font-weight:700;
    }
    .all-transactions-match-controls {
        min-width:0;
        margin:0;
        padding:0;
        border:0;
    }
    .all-transactions-match-controls legend {
        margin-bottom:8px;
        padding:0;
        color:#4a5568;
        font-size:11px;
        font-weight:800;
        text-transform:uppercase;
        letter-spacing:.06em;
    }
    .all-transactions-match-controls-row {
        display:flex;
        align-items:center;
        gap:12px;
        flex-wrap:wrap;
    }
    .all-transactions-match-controls-row > span,
    .all-transactions-match-controls-row label {
        color:#4a5568;
        font-size:13px;
        white-space:nowrap;
    }
    .all-transactions-match-controls-row > span {
        font-weight:700;
    }
    .all-transactions-match-controls-row label {
        display:flex;
        align-items:center;
        gap:5px;
        cursor:pointer;
    }
    .all-transactions-match-controls-row input {
        width:15px;
        height:15px;
    }
    #all-transactions-apply-match-filter {
        min-height:34px;
        margin-left:4px;
        padding:6px 14px;
        border:1px solid #2b6cb0;
        border-radius:4px;
        background:#2b6cb0;
        color:#fff;
        font-size:12px;
        font-weight:800;
        cursor:pointer;
    }
    #all-transactions-apply-match-filter:hover,
    #all-transactions-apply-match-filter:focus-visible {
        background:#2c5282;
    }
    #all-transactions-clear-match-filter {
        min-height:34px;
        padding:6px 12px;
        border:1px solid #a0aec0;
        border-radius:4px;
        background:#fff;
        color:#4a5568;
        font-size:12px;
        font-weight:800;
        cursor:pointer;
    }
    #all-transactions-clear-match-filter:hover,
    #all-transactions-clear-match-filter:focus-visible {
        border-color:#718096;
        background:#edf2f7;
        color:#2d3748;
    }
    .all-transactions-export-zone {
        display:flex;
        min-height:64px;
        padding-left:22px;
        border-left:1px solid #9ae6b4;
        flex-direction:column;
        justify-content:center;
        gap:7px;
    }
    #all-transactions-excel-export {
        width:100%;
        min-width:175px;
        padding:8px 30px 8px 14px;
        font-size:13px;
        white-space:nowrap;
        cursor:pointer;
    }
    .all-transactions-pagination {
        display:flex;
        align-items:center;
        justify-content:flex-end;
        gap:10px;
        margin-left:auto;
        flex-wrap:wrap;
    }
    .all-transactions-page-links {
        display:flex;
        align-items:center;
        gap:4px;
    }
    .all-transactions-page-link,
    .all-transactions-page-current {
        display:inline-flex;
        min-width:34px;
        height:34px;
        padding:0 9px;
        align-items:center;
        justify-content:center;
        border:1px solid #cbd5e0;
        border-radius:4px;
        background:#fff;
        color:#2b6cb0;
        font-size:12px;
        font-weight:700;
        line-height:1;
        text-decoration:none;
    }
    .all-transactions-page-link:hover,
    .all-transactions-page-link:focus-visible {
        border-color:#4299e1;
        background:#ebf8ff;
    }
    .all-transactions-page-current {
        border-color:#2b6cb0;
        background:#2b6cb0;
        color:#fff;
    }
    .all-transactions-page-ellipsis {
        padding:0 3px;
        color:#718096;
    }
    .all-transactions-page-jump {
        display:none;
        align-items:center;
        gap:6px;
        white-space:nowrap;
    }
    .all-transactions-page-jump input {
        width:72px;
        height:34px;
        padding:5px 8px;
        border:1px solid #cbd5e0;
        border-radius:4px;
        font-size:12px;
    }
    .all-transactions-page-jump button {
        height:34px;
        padding:0 11px;
        border:1px solid #2b6cb0;
        border-radius:4px;
        background:#fff;
        color:#2b6cb0;
        font-size:12px;
        font-weight:700;
        cursor:pointer;
    }
    .all-transactions-linked-value {
        display:flex;
        align-items:center;
        height:32px;
        min-height:32px;
        max-width:100%;
        overflow:hidden;
        text-overflow:ellipsis;
        white-space:nowrap;
    }
    .all-transactions-linked-value > a,
    .all-transactions-linked-value > span {
        display:block;
        max-width:100%;
        overflow:hidden;
        text-overflow:ellipsis;
        white-space:nowrap;
    }
    .all-transactions-summary-grid {
        display:grid;
        grid-template-columns:repeat(5,minmax(150px,1fr));
        gap:12px;
    }
    .all-transactions-summary-card {
        padding:14px 16px;
        min-height:78px;
    }
    .all-transactions-summary-label {
        font-size:11px;
        font-weight:700;
        color:#718096;
        text-transform:uppercase;
        letter-spacing:.04em;
    }
    .all-transactions-summary-value {
        margin-top:4px;
        color:#2d3748;
        font-size:20px;
        font-weight:700;
        font-variant-numeric:tabular-nums;
    }
    @media (max-width: 1100px) {
        .all-transactions-date-filters { grid-template-columns:repeat(2,minmax(0,1fr)) !important; }
        .all-transactions-secondary-filters { grid-template-columns:1fr; }
        .all-transactions-summary-grid { grid-template-columns:repeat(2,minmax(150px,1fr)); }
        .all-transactions-table-toolbar { grid-template-columns:1fr; gap:16px; }
        .all-transactions-export-zone { min-height:0; padding:14px 0 0; border-top:1px solid #9ae6b4; border-left:0; }
        #all-transactions-excel-export { width:min(100%,320px); }
    }
    @media (max-width: 700px) {
        .all-transactions-date-filters { grid-template-columns:1fr !important; }
        .all-transactions-summary-grid { grid-template-columns:1fr; }
    }
</style>

@if($connectionError)
    <div class="alert alert-error">{{ $connectionError }}</div>
@elseif($transactions)
    <section id="all-transactions-summary" aria-labelledby="all-transactions-summary-heading" aria-busy="true" style="margin-bottom:16px;">
        <div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:10px;">
            <div>
                <h3 id="all-transactions-summary-heading" style="margin:0;color:#2d3748;">Unfiltered Period Summary</h3>
                <div style="margin-top:3px;color:#718096;font-size:12px;">Cash-ledger totals for the selected period, date basis, currency, and statuses. Table search and detail filters do not affect these values.</div>
            </div>
            <div id="all-transactions-summary-source" style="display:none;align-items:center;gap:7px;color:#4a5568;font-size:12px;">
                <strong>Balance source:</strong>
                <span id="all-transactions-summary-source-badge" style="display:inline-flex;align-items:center;gap:6px;padding:5px 9px;border-radius:999px;font-weight:700;"></span>
            </div>
        </div>
        <div class="all-transactions-summary-grid">
            <div class="card all-transactions-summary-card">
                <div class="all-transactions-summary-label">Summary Period</div>
                <div id="all-transactions-summary-period" class="all-transactions-summary-value">Loading…</div>
            </div>
            <div class="card all-transactions-summary-card">
                <div class="all-transactions-summary-label">Opening Balance</div>
                <div id="all-transactions-summary-opening" class="all-transactions-summary-value">Loading…</div>
            </div>
            <div class="card all-transactions-summary-card">
                <div class="all-transactions-summary-label">Period Net</div>
                <div id="all-transactions-summary-net" class="all-transactions-summary-value">Loading…</div>
            </div>
            <div class="card all-transactions-summary-card">
                <div class="all-transactions-summary-label">Closing Balance</div>
                <div id="all-transactions-summary-closing" class="all-transactions-summary-value">Loading…</div>
            </div>
            <div class="card all-transactions-summary-card">
                <div class="all-transactions-summary-label">Cash Transactions</div>
                <div id="all-transactions-summary-count" class="all-transactions-summary-value">Loading…</div>
            </div>
        </div>
        <div id="all-transactions-summary-error" role="status" style="display:none;margin-top:8px;color:#b91c1c;font-size:12px;"></div>
    </section>

    <div class="card all-transactions-table-card" style="padding:0;overflow:visible;">
        <div class="all-transactions-table-toolbar">
            <div class="all-transactions-toolbar-title-block">
                @if(!empty($workingSetStatus['id']))
                    <div id="all-transactions-working-set-status"
                         class="all-transactions-working-set-status {{ !empty($workingSetStatus['ready']) ? 'all-transactions-working-set-status--ready' : (($workingSetStatus['state'] ?? null) === 'failed' ? 'all-transactions-working-set-status--failed' : '') }}"
                         role="status"
                         aria-live="polite"
                         data-status-url="{{ route('viefund-transactions.working-set.status', $workingSetStatus['id']) }}"
                         data-ready="{{ !empty($workingSetStatus['ready']) ? '1' : '0' }}"
                         data-queryable="{{ !empty($workingSetStatus['queryable']) ? '1' : '0' }}">
                        @if(!empty($workingSetStatus['ready']))
                            Period cache ready
                        @elseif(!empty($workingSetStatus['queryable']))
                            Partial period cache: {{ number_format($workingSetStatus['rows_cached'] ?? 0) }} rows available and growing
                        @else
                            Preparing first cache chunk
                        @endif
                    </div>
                @else
                    <div class="all-transactions-toolbar-title-kicker">VieFund Transactions</div>
                @endif
                <div class="all-transactions-toolbar-title">All Transactions</div>
            </div>
            <fieldset class="all-transactions-match-controls">
                <legend>{{ !empty($workingSetStatus['queryable']) ? 'Period Match Filter' : 'Filter Current Page' }}</legend>
                <div class="all-transactions-match-controls-row">
                    <span>Match</span>
                    @foreach(['Complete', 'Verify', 'Possible', 'Unknown'] as $label)
                        <label>
                            <input class="all-transactions-match-filter" type="checkbox" value="{{ $label }}" {{ in_array($label, $matchStatuses, true) ? 'checked' : '' }}>
                            {{ $label }}
                        </label>
                    @endforeach
                    <button id="all-transactions-apply-match-filter" type="button">Apply Filter</button>
                    <button id="all-transactions-clear-match-filter" type="button">Clear</button>
                </div>
            </fieldset>
            <div class="all-transactions-export-zone">
                <label class="all-transactions-toolbar-section-label" for="all-transactions-excel-export">Export</label>
                <select id="all-transactions-excel-export" aria-label="Export Excel" class="btn">
                    <option value="">↓ Export Excel</option>
                    <option value="single">Single Sheet</option>
                    <option value="split">Split Sheets (Trx, EFT, Bank, FSP)</option>
                </select>
            </div>
        </div>

        <form id="all-transactions-excel-form" action="{{ route('viefund-transactions.export.start') }}" method="POST" style="display:none;">
            @csrf
            <input type="hidden" name="search" value="{{ $search }}">
            <input type="hidden" name="filter_customer_name" value="{{ $filters['customer_name'] ?? '' }}">
            <input type="hidden" name="filter_plan_account_id" value="{{ $filters['plan_account_id'] ?? '' }}">
            <input type="hidden" name="filter_trx_id" value="{{ $filters['trx_id'] ?? '' }}">
            <input type="hidden" name="filter_source_id" value="{{ $filters['source_id'] ?? '' }}">
            <input type="hidden" name="filter_date_from" value="{{ $filters['date_from'] ?? '' }}">
            <input type="hidden" name="filter_date_to" value="{{ $filters['date_to'] ?? '' }}">
            <input type="hidden" name="filter_date_basis" value="{{ $dateBasis }}">
            <input type="hidden" name="filter_output_order" value="desc">
            <input type="hidden" name="filter_currency_code" value="{{ $currencyCode }}">
            @if($hasEftMatch)
                <input type="hidden" name="filter_has_eft_match" value="1">
            @endif
            @if($hasAgraFspMatch)
                <input type="hidden" name="filter_has_agra_fsp_match" value="1">
            @endif
            @if($has7960FspMatch)
                <input type="hidden" name="filter_has_7960_fsp_match" value="1">
            @endif
            @foreach((array) ($filters['trx_type'] ?? []) as $type)
                <input type="hidden" name="filter_trx_type[]" value="{{ $type }}">
            @endforeach
            @foreach($matchStatuses as $matchStatus)
                <input type="hidden" name="filter_match_status[]" value="{{ $matchStatus }}">
            @endforeach
            @foreach($statusIds as $statusId)
                <input type="hidden" name="filter_status[]" value="{{ $statusId }}">
            @endforeach
        </form>
        <div id="all-transactions-export-status" role="status" style="display:none;margin:16px 20px;padding:10px 14px;border:1px solid #99f6e4;border-radius:5px;background:#ecfdf5;color:#115e59;font-size:12px;font-weight:600;"></div>

        <div id="all-transactions-table-scroll" style="overflow:auto;max-height:72vh;position:relative;">
            <table id="all-transactions-table" style="width:max-content;max-width:none;border-collapse:collapse;table-layout:auto;">
                <thead>
                    <tr style="background:#f7fafc;border-bottom:2px solid #cbd5e0;">
                        @foreach($coreHeadings as $heading)
                            <th class="{{ $heading['key'] === 'matched_to_bank' ? 'all-transactions-reconciliation-column all-transactions-reconciliation-start' : '' }}" style="padding:12px;text-align:{{ $heading['label'] === 'Amount' ? 'right' : 'left' }};font-weight:700;color:{{ $heading['key'] === 'matched_to_bank' ? '#22543d' : '#2d3748' }};white-space:nowrap;">
                                @if(!empty($heading['sort']))
                                    <a href="{{ $sortUrl($heading['sort']) }}" style="color:#2d3748;text-decoration:none;">{{ $heading['label'] }}{{ $sortIndicator($heading['sort']) }}</a>
                                @else
                                    {{ $heading['label'] }}
                                @endif
                            </th>
                            @if($heading['key'] === 'matched_to_bank')
                                @foreach($bankSummaryHeadings as $bankSummaryHeading)
                                    <th class="all-transactions-bank-column all-transactions-reconciliation-column {{ $loop->first ? 'all-transactions-bank-group-start' : '' }} {{ $loop->last ? 'all-transactions-reconciliation-end' : '' }}" style="padding:12px;text-align:{{ $bankSummaryHeading['field'] === 'reconciliation_variance' ? 'right' : 'left' }};font-weight:700;white-space:nowrap;max-width:{{ $bankSummaryHeading['width'] ?? 220 }}px;">{{ $bankSummaryHeading['label'] }}</th>
                                @endforeach
                            @endif
                        @endforeach
                        @foreach($eftHeadings as $heading)
                            <th class="all-transactions-eft-column {{ $loop->first ? 'all-transactions-eft-group-start' : '' }}" style="padding:12px;text-align:{{ in_array($heading['field'], ['amount','file_total'], true) ? 'right' : 'left' }};font-weight:700;white-space:nowrap;max-width:{{ $heading['width'] ?? 220 }}px;">{{ $heading['label'] }}</th>
                        @endforeach
                        @foreach($fspHeadings as $heading)
                            <th class="all-transactions-fsp-column {{ $loop->first ? 'all-transactions-fsp-group-start' : '' }}" style="padding:12px;text-align:{{ in_array($heading['field'], ['items_total','gross_amount','net_amount','settlement_amount'], true) ? 'right' : 'left' }};font-weight:700;white-space:nowrap;max-width:{{ $heading['width'] ?? 220 }}px;">{{ $heading['label'] }}</th>
                        @endforeach
                        @foreach($bankHeadings as $heading)
                            <th class="all-transactions-bank-column {{ $loop->first ? 'all-transactions-bank-group-start' : '' }}" style="padding:12px;text-align:{{ in_array($heading['field'], ['amount','transaction_total'], true) ? 'right' : 'left' }};font-weight:700;white-space:nowrap;max-width:{{ $heading['width'] ?? 220 }}px;">{{ $heading['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody id="all-transactions-table-body">
                    @include('viefund-transactions.partials.rows')
                </tbody>
            </table>
        </div>

        <div style="padding:14px 16px;border-top:1px solid #e2e8f0;display:flex;align-items:center;gap:14px;flex-wrap:wrap;font-size:13px;color:#718096;">
            <label for="per-page">Rows per page:</label>
            <select id="per-page" style="border:1px solid #cbd5e0;border-radius:4px;padding:5px 8px;background:#fff;">
                @foreach([50,100,250] as $option)
                    <option value="{{ request()->fullUrlWithQuery(['per_page'=>$option,'page'=>1]) }}" {{ $perPage === $option ? 'selected' : '' }}>{{ $option }}</option>
                @endforeach
            </select>
            <span>
                <span id="all-transactions-range-summary">Showing {{ number_format($transactions->firstItem() ?? 0) }}–{{ number_format($transactions->lastItem() ?? 0) }} transactions</span>
                <span id="all-transactions-total-summary" style="color:#718096;" aria-live="polite"> · Calculating filtered total…</span>
            </span>
            <nav id="all-transactions-pagination" class="all-transactions-pagination" aria-label="Transaction pages">
                <div id="all-transactions-simple-pagination">{{ $transactions->withQueryString()->links() }}</div>
                <div id="all-transactions-numbered-pagination" class="all-transactions-page-links" hidden></div>
                <form id="all-transactions-page-jump" class="all-transactions-page-jump">
                    <label for="all-transactions-page-number">Go to page</label>
                    <input id="all-transactions-page-number" type="number" min="1" step="1" inputmode="numeric" aria-label="Page number">
                    <button type="submit">Go</button>
                </form>
            </nav>
        </div>
    </div>
@endif

<script>
(() => {
    const ACTIVE_RUN_KEY = 'viefundAllTransactionsActiveRunId';
    let pollTimer = null;
    let activeRunId = localStorage.getItem(ACTIVE_RUN_KEY) || null;
    let lastProgress = 0;
    let reconciliationMatchesLoaded = false;
    let reconciliationMatchesLoading = false;
    let tablePageGeneration = 0;
    const fspMatchesLoaded = {agra: false, '7960': false};
    const fspMatchesLoading = {agra: false, '7960': false};
    const statusLoadFailed = {eft: false, agra: false, '7960': false};
    let eftBankRecordsByTrust = {};
    let eftMatchStatusesByTrust = {};
    const fspRecordsBySource = {agra: {}, '7960': {}};
    const fspBankRecordsBySource = {agra: {}, '7960': {}};
    const fspMatchStatusesBySource = {agra: {}, '7960': {}};

    const filterForm = document.querySelector('form[data-inception-dates]');
    const transactionTypeSelect = document.getElementById('all-trx-types');
    filterForm?.addEventListener('submit', () => {
        const options = Array.from(transactionTypeSelect?.options || []);
        if (options.length > 0 && options.every((option) => option.selected)) {
            options.forEach((option) => {
                option.selected = false;
            });
        }
        filterForm.querySelectorAll('[data-match-status-filter]').forEach((input) => input.remove());
        matchStatusInputs.filter((input) => input.checked).forEach((input) => {
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'filter_match_status[]';
            hidden.value = input.value;
            hidden.dataset.matchStatusFilter = '1';
            filterForm.appendChild(hidden);
        });
    });
    const dateBasis = document.getElementById('all-date-basis');
    const dateFrom = document.getElementById('all-date-from');
    const inceptionButton = document.getElementById('all-set-inception-date');
    const inceptionNote = document.getElementById('all-inception-date-note');
    const inceptionDates = filterForm ? JSON.parse(filterForm.dataset.inceptionDates || '{}') : {};
    const updateInceptionControl = () => {
        const inceptionDate = inceptionDates[dateBasis?.value] || '';
        if (inceptionNote) inceptionNote.textContent = inceptionDate ? `Inception: ${inceptionDate}` : 'Inception: unavailable';
        if (inceptionButton) inceptionButton.disabled = !inceptionDate;
    };
    dateBasis?.addEventListener('change', updateInceptionControl);
    inceptionButton?.addEventListener('click', () => {
        const inceptionDate = inceptionDates[dateBasis?.value] || '';
        if (inceptionDate && dateFrom) dateFrom.value = inceptionDate;
    });
    updateInceptionControl();

    const matchStatusInputs = Array.from(document.querySelectorAll('.all-transactions-match-filter'));
    const applyMatchFilterButton = document.getElementById('all-transactions-apply-match-filter');
    const clearMatchFilterButton = document.getElementById('all-transactions-clear-match-filter');
    const workingSetStatus = document.getElementById('all-transactions-working-set-status');
    const setWorkingSetStatus = (state, message) => {
        if (!workingSetStatus) return;
        workingSetStatus.classList.toggle('all-transactions-working-set-status--ready', state === 'ready');
        workingSetStatus.classList.toggle('all-transactions-working-set-status--failed', state === 'failed');
        workingSetStatus.textContent = message;
    };
    let eftColumns = Array.from(document.querySelectorAll('.all-transactions-eft-column'));
    let eftCells = Array.from(document.querySelectorAll('.all-transactions-eft-cell'));
    let bankColumns = Array.from(document.querySelectorAll('.all-transactions-bank-column'));
    let bankCells = Array.from(document.querySelectorAll('.all-transactions-bank-cell'));
    let fspColumns = Array.from(document.querySelectorAll('.all-transactions-fsp-column'));
    let fspCells = Array.from(document.querySelectorAll('.all-transactions-fsp-cell'));
    let transactionStatusCells = Array.from(document.querySelectorAll('.all-transactions-match-status-cell'));
    const setColumnVisible = (columns, visible) => {
        columns.forEach((column) => {
            column.style.display = visible ? '' : 'none';
        });
    };
    const formatLinkedDate = (value) => {
        if (!value) return '—';
        const match = String(value).match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/);
        if (!match) return String(value);
        const formatted = `${match[2]}/${match[3]}/${match[1]}`;
        const hasActualTime = match[4] && `${match[4]}:${match[5]}:${match[6] || '00'}` !== '00:00:00';
        return hasActualTime ? `${formatted} ${match[4]}:${match[5]}:${match[6] || '00'}` : formatted;
    };
    const formatLinkedAmount = (value) => {
        if (value === null || value === undefined || value === '') return '—';
        const amount = Number(value);
        if (!Number.isFinite(amount)) return String(value);
        const formatted = Math.abs(amount).toLocaleString('en-CA', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        return amount < 0 ? `($${formatted})` : `$${formatted}`;
    };
    const linkedRecordValue = (record, type, field) => {
        if (['amount', 'transaction_total', 'file_total', 'items_total', 'gross_amount', 'net_amount', 'settlement_amount', 'reconciliation_variance'].includes(field)) return formatLinkedAmount(record[field]);
        if (['effective_date', 'create_date', 'trade_date', 'settlement_date', 'value_date', 'booking_date'].includes(field)) {
            return formatLinkedDate(record[field]);
        }
        if (field === 'created_at') return formatLinkedDate(record[field]);
        if (type === 'eft' && field === 'file_name') {
            return record.file_name || (record.file_id ? `EFT file #${record.file_id}` : `Unprocessed EFT item #${record.id}`);
        }
        if (type === 'bank' && field === 'id') return `Bank txn #${record.id}`;
        if (type === 'fsp' && field === 'id') return `FSP item #${record.id}`;
        const value = record[field];
        return value === null || value === undefined || value === '' ? '—' : String(value);
    };
    const bankTransactionGroupUrl = (records) => {
        const transactionIds = [...new Set(records
            .map((record) => Number(record.id))
            .filter((id) => Number.isInteger(id) && id > 0))];
        const firstLinkedRecord = records.find((record) => record.url);
        if (transactionIds.length === 0 || !firstLinkedRecord?.url) return null;

        const url = new URL(firstLinkedRecord.url, window.location.origin);
        url.searchParams.delete('entry_id');
        url.searchParams.delete('settlement_numbers');
        url.searchParams.delete('all_dates');
        url.searchParams.set('entry_ids', transactionIds.join(','));
        return url.toString();
    };
    const renderLinkedRecords = (cell, records, type) => {
        cell.replaceChildren();
        if (!Array.isArray(records) || records.length === 0) {
            const empty = document.createElement('span');
            empty.textContent = type === 'eft' && cell.dataset.recordField === 'bank_match_status' ? 'Unknown' : '—';
            empty.title = type === 'eft'
                ? 'No EFT item is linked to this transaction.'
                : (type === 'bank'
                    ? 'No bank transaction matches the linked EFT sequence.'
                    : 'No imported selected FSP item has this VieFund fund source ID.');
            empty.style.color = '#718096';
            cell.appendChild(empty);
            return;
        }

        const displayedRecords = type === 'bank' && cell.dataset.recordField === 'transaction_total'
            ? [{
                transaction_total: records.reduce((total, record) => total + Number(record.amount || 0), 0),
                url: bankTransactionGroupUrl(records),
            }]
            : records;
        displayedRecords.forEach((record) => {
            const row = document.createElement('div');
            row.className = 'all-transactions-linked-value';
            const field = cell.dataset.recordField;
            if (['amount', 'transaction_total', 'file_total', 'items_total', 'gross_amount', 'net_amount', 'settlement_amount', 'reconciliation_variance'].includes(field)) {
                row.style.justifyContent = 'flex-end';
                row.style.textAlign = 'right';
            }
            const value = linkedRecordValue(record, type, field);
            row.title = value === '—' ? '' : value;
            const isFspFileLink = type === 'fsp' && field === 'source_file';
            const isFspItemLink = type === 'fsp' && field === 'settlement_amount';
            const isBankFileLink = type === 'bank' && field === 'source_file';
            const isBankAccountLink = type === 'bank' && field === 'account_number';
            const isLink = (type === 'eft' && ['file_name', 'holder_name'].includes(field))
                || (type === 'bank' && ['id', 'amount', 'transaction_total'].includes(field))
                || isBankFileLink
                || isBankAccountLink
                || isFspFileLink
                || isFspItemLink;
            const linkUrl = type === 'eft' && field === 'holder_name'
                ? record.linked_item_url
                : (isBankAccountLink
                    ? record.account_url
                    : ((isBankFileLink || isFspFileLink) ? record.file_url : (isFspItemLink ? record.item_url : record.url)));
            if (isLink && linkUrl) {
                const link = document.createElement('a');
                link.href = linkUrl;
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
                link.textContent = value;
                link.title = type === 'eft'
                    ? (field === 'file_name' ? 'Open the complete EFT file' : 'Open the linked EFT item')
                    : (type === 'bank'
                        ? (isBankAccountLink
                            ? 'Open this bank account statement'
                            : (isBankFileLink
                            ? 'Open statements from this bank source file'
                            : (field === 'transaction_total' ? 'Open all bank transactions included in this total' : 'Open this bank transaction')))
                        : (isFspFileLink ? 'Open this FSP file' : 'Open this FSP item'));
                link.style.color = '#2b6cb0';
                link.style.fontWeight = '600';
                link.style.textDecoration = 'underline';
                row.appendChild(link);
            } else {
                const text = document.createElement('span');
                text.textContent = value;
                if (value === '—') text.style.color = '#718096';
                if (type === 'eft' && field === 'bank_match_status') {
                    text.style.color = value === 'Complete'
                        ? '#276749'
                        : (value === 'Verify'
                            ? '#b7791f'
                            : (value === 'Possible' ? '#2b6cb0' : '#718096'));
                    text.style.fontWeight = '700';
                }
                if (['amount', 'transaction_total', 'file_total', 'items_total', 'gross_amount', 'net_amount', 'settlement_amount', 'reconciliation_variance'].includes(field) && record[field] !== null && record[field] !== undefined) {
                    const amount = Number(record[field]);
                    text.style.color = field === 'reconciliation_variance' && record.is_possible_wire_fee_match
                        ? '#b7791f'
                        : (amount < 0 ? '#c53030' : (amount > 0 ? '#276749' : '#718096'));
                    text.style.fontWeight = '600';
                }
                row.appendChild(text);
            }
            cell.appendChild(row);
        });
    };
    const renderBankRecords = () => {
        bankCells.forEach((cell) => {
            const eftRecords = eftBankRecordsByTrust[cell.dataset.trustId] || [];
            const fspRecords = ['agra', '7960'].flatMap(
                (source) => fspBankRecordsBySource[source]?.[cell.dataset.cashId] || []
            );
            const records = [...fspRecords, ...eftRecords].filter((record, index, all) =>
                all.findIndex((candidate) => Number(candidate.id) === Number(record.id)) === index
            );
            renderLinkedRecords(cell, records, 'bank');
        });
    };
    const renderFspRecords = () => {
        fspCells.forEach((cell) => {
            const records = ['agra', '7960'].flatMap(
                (source) => fspRecordsBySource[source]?.[cell.dataset.cashId] || []
            );
            renderLinkedRecords(cell, records, 'fsp');
        });
    };
    const summarizeMatchStatuses = (statuses) => {
        const values = statuses.filter(Boolean);
        if (values.length === 0) return null;
        if (values.every((status) => status === 'Complete')) return 'Complete';
        if (values.includes('Complete') || values.includes('Verify')) return 'Verify';
        if (values.includes('Possible')) return 'Possible';
        return 'Unknown';
    };
    const applyLocalMatchFilters = () => {
        const selected = matchStatusInputs
            .filter((input) => input.checked)
            .map((input) => input.value.toLowerCase());
        document.querySelectorAll('#all-transactions-table-body tr[data-transaction-row]').forEach((row) => {
            const status = row.querySelector('.all-transactions-match-status-cell')?.dataset.matchStatus || 'loading';
            row.hidden = selected.length > 0 && !selected.includes(status);
        });
    };
    const setTransactionMatchStatusAppearance = (cell, status) => {
        const normalized = String(status || '').toLowerCase();
        const appearance = normalized === 'complete'
            ? 'complete'
            : (normalized === 'possible'
                ? 'possible'
                : (normalized === 'verify'
                    ? 'verify'
                    : (normalized === 'unable to load' ? 'error' : (normalized === 'loading…' ? 'loading' : 'unknown'))));
        cell.closest('tr')?.querySelectorAll('.all-transactions-reconciliation-column').forEach((column) => {
            column.dataset.matchStatus = appearance;
        });
    };
    const renderTransactionMatchStatuses = () => {
        const ready = reconciliationMatchesLoaded && fspMatchesLoaded.agra && fspMatchesLoaded['7960'];
        transactionStatusCells.forEach((cell) => {
            if (!ready) {
                cell.textContent = 'Loading…';
                setTransactionMatchStatusAppearance(cell, 'Loading…');
                return;
            }
            if (statusLoadFailed.eft || statusLoadFailed.agra || statusLoadFailed['7960']) {
                cell.textContent = 'Unable to load';
                setTransactionMatchStatusAppearance(cell, 'Unable to load');
                return;
            }

            const trustId = cell.dataset.trustId;
            const cashId = cell.dataset.cashId;
            const hasEft = trustId !== '' && Object.prototype.hasOwnProperty.call(eftMatchStatusesByTrust, trustId);
            const fspStatuses = ['agra', '7960']
                .filter((source) => cashId !== '' && Object.prototype.hasOwnProperty.call(fspMatchStatusesBySource[source], cashId))
                .map((source) => fspMatchStatusesBySource[source][cashId]);
            const hasFsp = fspStatuses.length > 0;
            const status = hasEft && hasFsp
                ? 'Verify'
                : (hasEft
                    ? eftMatchStatusesByTrust[trustId]
                    : (summarizeMatchStatuses(fspStatuses) || 'Unknown'));

            cell.textContent = status;
            cell.title = hasEft && hasFsp
                ? 'This transaction has both EFT and FSP supporting records and should be reviewed.'
                : '';
            setTransactionMatchStatusAppearance(cell, status);
        });
        applyLocalMatchFilters();
    };
    const loadReconciliationMatches = async () => {
        if (reconciliationMatchesLoaded || reconciliationMatchesLoading || (eftCells.length === 0 && bankCells.length === 0 && transactionStatusCells.length === 0)) return;
        const generation = tablePageGeneration;
        const matchCells = [...eftCells, ...bankCells];
        const trustIds = [...new Set([...matchCells, ...transactionStatusCells].map((cell) => cell.dataset.trustId).filter(Boolean))];
        matchCells.forEach((cell) => {
            const status = cell.querySelector('span') || document.createElement('span');
            const isGroupStart = cell.classList.contains('all-transactions-eft-group-start')
                || cell.classList.contains('all-transactions-bank-group-start');
            status.className = cell.dataset.trustId && isGroupStart ? 'all-transactions-eft-loading' : '';
            status.textContent = cell.dataset.trustId ? (isGroupStart ? 'Loading…' : '…') : '—';
            status.title = cell.dataset.trustId ? '' : 'This cash transaction has no related trust transaction.';
            cell.replaceChildren(status);
        });
        if (trustIds.length === 0) {
            reconciliationMatchesLoaded = true;
            renderTransactionMatchStatuses();
            return;
        }

        reconciliationMatchesLoading = true;
        try {
            const response = await fetch('{{ route('viefund-transactions.eft-matches') }}', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: JSON.stringify({trust_ids: trustIds.map(Number)}),
            });
            const data = await response.json();
            if (generation !== tablePageGeneration) return;
            if (!response.ok) throw new Error(data.message || 'EFT and bank matches could not be loaded.');
            eftCells.forEach((cell) => renderLinkedRecords(cell, data.eft_records?.[cell.dataset.trustId] || [], 'eft'));
            eftBankRecordsByTrust = data.bank_records || {};
            eftMatchStatusesByTrust = data.match_statuses || {};
            renderBankRecords();
            reconciliationMatchesLoaded = true;
            renderTransactionMatchStatuses();
        } catch (error) {
            if (generation !== tablePageGeneration) return;
            eftCells.forEach((cell) => {
                if (!cell.dataset.trustId) return;
                cell.replaceChildren();
                const message = document.createElement('span');
                message.textContent = 'Unable to load';
                message.title = error.message;
                message.style.color = '#c53030';
                cell.appendChild(message);
            });
            eftBankRecordsByTrust = {};
            eftMatchStatusesByTrust = {};
            statusLoadFailed.eft = true;
            reconciliationMatchesLoaded = true;
            renderBankRecords();
            renderTransactionMatchStatuses();
        } finally {
            if (generation === tablePageGeneration) reconciliationMatchesLoading = false;
        }
    };
    const loadFspMatches = async (source) => {
        if (fspMatchesLoaded[source] || fspMatchesLoading[source] || (fspCells.length === 0 && transactionStatusCells.length === 0)) return;
        const generation = tablePageGeneration;
        const cashTransactionIds = [...new Set([...fspCells, ...transactionStatusCells].map((cell) => cell.dataset.cashId).filter(Boolean))];
        if (!fspMatchesLoaded.agra && !fspMatchesLoaded['7960']) {
            fspCells.forEach((cell) => {
                const status = document.createElement('span');
                const hasSource = Boolean(cell.dataset.cashId);
                const isGroupStart = cell.classList.contains('all-transactions-fsp-group-start');
                status.className = hasSource && isGroupStart ? 'all-transactions-eft-loading' : '';
                status.textContent = hasSource ? (isGroupStart ? 'Loading…' : '…') : '—';
                status.title = hasSource ? '' : 'This cash transaction has no related fund source ID.';
                cell.replaceChildren(status);
            });
        }
        if (cashTransactionIds.length === 0) {
            fspMatchesLoaded[source] = true;
            renderTransactionMatchStatuses();
            return;
        }

        fspMatchesLoading[source] = true;
        try {
            const response = await fetch('{{ route('viefund-transactions.fsp-matches') }}', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: JSON.stringify({
                    cash_transaction_ids: cashTransactionIds.map(Number),
                    sources: [source],
                }),
            });
            const data = await response.json();
            if (generation !== tablePageGeneration) return;
            if (!response.ok) throw new Error(data.message || `${source.toUpperCase()} FSP matches could not be loaded.`);
            fspRecordsBySource[source] = data.fsp_records || {};
            fspBankRecordsBySource[source] = data.bank_records || {};
            fspMatchStatusesBySource[source] = data.match_statuses || {};
            renderFspRecords();
            renderBankRecords();
            fspMatchesLoaded[source] = true;
            renderTransactionMatchStatuses();
        } catch (error) {
            if (generation !== tablePageGeneration) return;
            fspCells.forEach((cell) => {
                if (!cell.dataset.cashId) return;
                const message = document.createElement('span');
                message.textContent = 'Unable to load';
                message.title = error.message;
                message.style.color = '#c53030';
                cell.replaceChildren(message);
            });
            fspMatchStatusesBySource[source] = {};
            statusLoadFailed[source] = true;
            fspMatchesLoaded[source] = true;
            renderTransactionMatchStatuses();
        } finally {
            if (generation === tablePageGeneration) fspMatchesLoading[source] = false;
        }
    };
    const initializeLinkedRecordsForCurrentPage = () => {
        tablePageGeneration += 1;
        eftColumns = Array.from(document.querySelectorAll('.all-transactions-eft-column'));
        eftCells = Array.from(document.querySelectorAll('.all-transactions-eft-cell'));
        bankColumns = Array.from(document.querySelectorAll('.all-transactions-bank-column'));
        bankCells = Array.from(document.querySelectorAll('.all-transactions-bank-cell'));
        fspColumns = Array.from(document.querySelectorAll('.all-transactions-fsp-column'));
        fspCells = Array.from(document.querySelectorAll('.all-transactions-fsp-cell'));
        transactionStatusCells = Array.from(document.querySelectorAll('.all-transactions-match-status-cell'));

        reconciliationMatchesLoaded = false;
        reconciliationMatchesLoading = false;
        fspMatchesLoaded.agra = false;
        fspMatchesLoaded['7960'] = false;
        fspMatchesLoading.agra = false;
        fspMatchesLoading['7960'] = false;
        statusLoadFailed.eft = false;
        statusLoadFailed.agra = false;
        statusLoadFailed['7960'] = false;
        eftBankRecordsByTrust = {};
        eftMatchStatusesByTrust = {};
        fspRecordsBySource.agra = {};
        fspRecordsBySource['7960'] = {};
        fspBankRecordsBySource.agra = {};
        fspBankRecordsBySource['7960'] = {};
        fspMatchStatusesBySource.agra = {};
        fspMatchStatusesBySource['7960'] = {};

        setColumnVisible(eftColumns, true);
        setColumnVisible(bankColumns, true);
        setColumnVisible(fspColumns, true);
        loadReconciliationMatches();
        loadFspMatches('agra');
        loadFspMatches('7960');
    };
    const applyMatchFilters = () => {
        const url = new URL(window.location.href);
        url.searchParams.delete('filter_match_status[]');
        matchStatusInputs.filter((input) => input.checked).forEach((input) => {
            url.searchParams.append('filter_match_status[]', input.value);
        });
        url.searchParams.set('page', '1');
        if (workingSetStatus?.dataset.queryable === '1') {
            window.location.assign(url.toString());
            return;
        }
        window.history.replaceState({}, '', url.toString());
        applyLocalMatchFilters();
    };
    applyMatchFilterButton?.addEventListener('click', applyMatchFilters);
    clearMatchFilterButton?.addEventListener('click', () => {
        matchStatusInputs.forEach((input) => {
            input.checked = false;
        });
        applyMatchFilters();
    });
    initializeLinkedRecordsForCurrentPage();

    if (workingSetStatus?.dataset.ready === '0' && workingSetStatus.dataset.statusUrl) {
        const pollWorkingSet = async () => {
            try {
                const response = await fetch(workingSetStatus.dataset.statusUrl, {headers: {'Accept': 'application/json'}});
                if (!response.ok) return;
                const status = await response.json();
                if (status.queryable && workingSetStatus.dataset.queryable !== '1') {
                    window.location.reload();
                    return;
                }
                workingSetStatus.dataset.queryable = status.queryable ? '1' : '0';
                workingSetStatus.dataset.ready = status.ready ? '1' : '0';
                if (status.ready) {
                    setWorkingSetStatus('ready', `Period cache ready · ${Number(status.total_rows || status.rows_cached || 0).toLocaleString()} rows`);
                    return;
                }
                if (status.state === 'failed') {
                    setWorkingSetStatus('failed', 'Period cache unavailable; current-page filtering remains active');
                    return;
                }
                setWorkingSetStatus(
                    'warming',
                    status.queryable
                        ? `Partial period cache: ${Number(status.rows_cached || 0).toLocaleString()} rows available and growing`
                        : 'Preparing first cache chunk'
                );
                window.setTimeout(pollWorkingSet, 2500);
            } catch (error) {
                window.setTimeout(pollWorkingSet, 5000);
            }
        };
        window.setTimeout(pollWorkingSet, 1200);
    }

    const totalSummary = document.getElementById('all-transactions-total-summary');
    const simplePagination = document.getElementById('all-transactions-simple-pagination');
    const numberedPagination = document.getElementById('all-transactions-numbered-pagination');
    const pageJumpForm = document.getElementById('all-transactions-page-jump');
    const pageNumberInput = document.getElementById('all-transactions-page-number');
    const perPageSelect = document.getElementById('per-page');
    const rangeSummary = document.getElementById('all-transactions-range-summary');
    const transactionTable = document.getElementById('all-transactions-table');
    const transactionTableBody = document.getElementById('all-transactions-table-body');
    const transactionTableScroll = document.getElementById('all-transactions-table-scroll');
    transactionTableBody?.addEventListener('click', (event) => {
        if (event.target.closest('a, button, input, select, textarea, label, form')) return;
        const row = event.target.closest('tr[data-transaction-row]');
        if (!row) return;
        const selected = row.classList.toggle('all-transactions-row-selected');
        row.setAttribute('aria-selected', selected ? 'true' : 'false');
    });
    let knownTotalPages = null;
    let knownTotalRecords = null;
    let pageRequestController = null;
    const pageUrl = (page) => {
        const url = new URL(window.location.href);
        url.searchParams.set('page', String(page));
        return url.toString();
    };
    const renderNumberedPagination = (currentPage, totalPages) => {
        if (!numberedPagination || !Number.isFinite(totalPages) || totalPages < 1) return;

        const current = Math.max(1, Math.min(currentPage, totalPages));
        const pages = new Set([1, totalPages]);
        if (totalPages <= 9) {
            for (let page = 1; page <= totalPages; page += 1) pages.add(page);
        } else {
            for (let page = Math.max(1, current - 2); page <= Math.min(totalPages, current + 2); page += 1) {
                pages.add(page);
            }
        }
        const orderedPages = Array.from(pages).sort((left, right) => left - right);
        numberedPagination.replaceChildren();

        const addLink = (label, page, active = false, title = '') => {
            const element = document.createElement(active ? 'span' : 'a');
            element.className = active ? 'all-transactions-page-current' : 'all-transactions-page-link';
            element.textContent = label;
            if (title) element.title = title;
            if (active) {
                element.setAttribute('aria-current', 'page');
            } else {
                element.href = pageUrl(page);
            }
            numberedPagination.appendChild(element);
        };

        if (current > 1) addLink('‹', current - 1, false, 'Previous page');
        let previousPage = null;
        orderedPages.forEach((page) => {
            if (previousPage !== null && page - previousPage > 1) {
                const ellipsis = document.createElement('span');
                ellipsis.className = 'all-transactions-page-ellipsis';
                ellipsis.textContent = '…';
                numberedPagination.appendChild(ellipsis);
            }
            addLink(String(page), page, page === current, `Page ${page}`);
            previousPage = page;
        });
        if (current < totalPages) addLink('›', current + 1, false, 'Next page');

        simplePagination?.setAttribute('hidden', 'hidden');
        numberedPagination.hidden = false;
        knownTotalPages = totalPages;
        if (pageNumberInput) {
            pageNumberInput.max = String(totalPages);
            pageNumberInput.value = String(current);
        }
        if (pageJumpForm) pageJumpForm.style.display = totalPages > 1 ? 'flex' : 'none';
    };
    const renderSimplePagination = (currentPage, hasMorePages) => {
        if (!simplePagination) return;
        simplePagination.replaceChildren();
        const links = document.createElement('div');
        links.className = 'all-transactions-page-links';
        [
            ['‹ Previous', currentPage > 1 ? pageUrl(currentPage - 1) : null],
            ['Next ›', hasMorePages ? pageUrl(currentPage + 1) : null],
        ].forEach(([label, url]) => {
            if (url === null) return;
            const link = document.createElement('a');
            link.className = 'all-transactions-page-link';
            link.href = url;
            link.textContent = label;
            links.appendChild(link);
        });
        simplePagination.appendChild(links);
        simplePagination.hidden = false;
    };
    const loadTransactionPage = async (targetUrl, addToHistory = true) => {
        if (!transactionTableBody || !transactionTable) {
            window.location.assign(targetUrl);
            return;
        }

        pageRequestController?.abort();
        const controller = new AbortController();
        pageRequestController = controller;
        const requestUrl = new URL(targetUrl, window.location.href);
        requestUrl.searchParams.set('_table_page', '1');
        transactionTable.setAttribute('aria-busy', 'true');
        transactionTableBody.style.opacity = '.45';

        try {
            const response = await fetch(requestUrl.toString(), {
                credentials: 'same-origin',
                headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                cache: 'no-store',
                signal: controller.signal,
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'The transaction page could not be loaded.');

            transactionTableBody.innerHTML = data.rows_html || '';
            if (rangeSummary) {
                const from = Number(data.from || 0).toLocaleString('en-CA');
                const to = Number(data.to || 0).toLocaleString('en-CA');
                rangeSummary.textContent = `Showing ${from}–${to} transactions`;
            }
            const cleanUrl = new URL(targetUrl, window.location.href);
            cleanUrl.searchParams.delete('_table_page');
            if (addToHistory) history.pushState({transactionPage: true}, '', cleanUrl.toString());

            if (perPageSelect) {
                const selectedPerPage = String(data.per_page || 100);
                Array.from(perPageSelect.options).forEach((option) => {
                    option.selected = new URL(option.value, window.location.href).searchParams.get('per_page') === selectedPerPage;
                });
            }
            if (knownTotalRecords !== null) {
                renderNumberedPagination(
                    Number(data.page || 1),
                    Math.max(1, Math.ceil(knownTotalRecords / Number(data.per_page || 100)))
                );
            } else {
                renderSimplePagination(Number(data.page || 1), Boolean(data.has_more_pages));
            }

            if (transactionTableScroll) transactionTableScroll.scrollTop = 0;
            initializeLinkedRecordsForCurrentPage();
        } catch (error) {
            if (error.name === 'AbortError') return;
            window.location.assign(targetUrl);
        } finally {
            if (pageRequestController === controller) {
                transactionTable.removeAttribute('aria-busy');
                transactionTableBody.style.opacity = '1';
            }
        }
    };
    document.getElementById('all-transactions-pagination')?.addEventListener('click', (event) => {
        const link = event.target.closest('a[href]');
        if (!link) return;
        event.preventDefault();
        loadTransactionPage(link.href);
    });
    perPageSelect?.addEventListener('change', () => loadTransactionPage(perPageSelect.value));
    pageJumpForm?.addEventListener('submit', (event) => {
        event.preventDefault();
        const page = Number.parseInt(pageNumberInput?.value || '', 10);
        if (!Number.isFinite(page) || page < 1 || (knownTotalPages !== null && page > knownTotalPages)) {
            pageNumberInput?.focus();
            return;
        }
        loadTransactionPage(pageUrl(page));
    });
    window.addEventListener('popstate', () => loadTransactionPage(window.location.href, false));
    let totalPollAttempts = 0;
    const updateFilteredTotal = async () => {
        if (!totalSummary || totalPollAttempts >= 120) return;
        ++totalPollAttempts;
        try {
            const countUrl = new URL('{{ route('viefund-transactions.count') }}', window.location.origin);
            countUrl.search = window.location.search;
            const response = await fetch(countUrl.toString(), {
                headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                cache: 'no-store',
            });
            const data = await response.json();
            if (data.state === 'complete' || data.state === 'partial') {
                const currentUrl = new URL(window.location.href);
                const currentPerPage = Number(currentUrl.searchParams.get('per_page') || {{ $perPage }});
                const currentPage = Math.max(1, Number(currentUrl.searchParams.get('page') || 1));
                knownTotalRecords = Number(data.total || 0);
                const totalPageCount = Math.max(1, Math.ceil(knownTotalRecords / currentPerPage));
                const total = knownTotalRecords.toLocaleString('en-CA');
                totalSummary.textContent = data.state === 'partial'
                    ? ` · ${total} cached so far`
                    : ` · ${total} total`;
                totalSummary.title = data.state === 'partial'
                    ? 'This filtered total and the available page count will grow as more period rows are cached.'
                    : (data.cached ? 'Cached for this filter selection.' : '');
                renderNumberedPagination(currentPage, totalPageCount);
                if (data.state === 'partial') {
                    totalPollAttempts = 0;
                    window.setTimeout(updateFilteredTotal, 2500);
                }
                return;
            }
            if (data.state === 'failed') {
                totalSummary.textContent = ' · Total unavailable';
                totalSummary.title = data.message || 'The filtered total could not be calculated.';
                return;
            }
            totalSummary.textContent = ' · Calculating filtered total…';
        } catch (_) {
            totalSummary.textContent = ' · Waiting for filtered total…';
        }
        window.setTimeout(updateFilteredTotal, 5000);
    };
    if (totalSummary) {
        window.setTimeout(updateFilteredTotal, 1000);
    }

    const periodSummary = document.getElementById('all-transactions-summary');
    const loadPeriodSummary = async () => {
        if (!periodSummary) return;

        const opening = document.getElementById('all-transactions-summary-opening');
        const period = document.getElementById('all-transactions-summary-period');
        const periodNet = document.getElementById('all-transactions-summary-net');
        const closing = document.getElementById('all-transactions-summary-closing');
        const transactionCount = document.getElementById('all-transactions-summary-count');
        const source = document.getElementById('all-transactions-summary-source');
        const sourceBadge = document.getElementById('all-transactions-summary-source-badge');
        const error = document.getElementById('all-transactions-summary-error');
        const formatMoney = (value) => {
            const amount = Number(value || 0);
            const formatted = `$${Math.abs(amount).toLocaleString('en-CA', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
            return amount < 0 ? `(${formatted})` : formatted;
        };

        try {
            const summaryUrl = new URL('{{ route('viefund-transactions.summary') }}', window.location.origin);
            summaryUrl.search = window.location.search;
            const response = await fetch(summaryUrl.toString(), {
                headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                cache: 'no-store',
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'The period summary could not be loaded.');

            opening.textContent = formatMoney(data.opening_balance);
            period.textContent = data.period_label || 'Selected period';
            periodNet.textContent = formatMoney(data.period_net);
            periodNet.style.color = Number(data.period_net) < 0 ? '#b91c1c' : '#166534';
            closing.textContent = formatMoney(data.closing_balance);
            transactionCount.textContent = Number(data.transaction_count || 0).toLocaleString('en-CA');
            sourceBadge.innerHTML = '<span style="width:7px;height:7px;border-radius:50%;background:currentColor;"></span>';
            sourceBadge.append(document.createTextNode(data.balance_source || 'VieFund cash ledger'));
            sourceBadge.style.background = data.uses_snapshots ? '#dcfce7' : '#fef3c7';
            sourceBadge.style.color = data.uses_snapshots ? '#166534' : '#92400e';
            source.style.display = 'flex';
        } catch (summaryError) {
            [period, opening, periodNet, closing, transactionCount].forEach((element) => {
                if (element) element.textContent = 'Unavailable';
            });
            error.textContent = summaryError.message || 'The period summary could not be loaded.';
            error.style.display = 'block';
        } finally {
            periodSummary.removeAttribute('aria-busy');
        }
    };
    loadPeriodSummary();

    const showStatus = (element, message, isError = false) => {
        element.textContent = message;
        element.style.display = 'block';
        element.style.borderColor = isError ? '#fecaca' : '#99f6e4';
        element.style.background = isError ? '#fef2f2' : '#ecfdf5';
        element.style.color = isError ? '#991b1b' : '#115e59';
    };

    const setBusy = (button, busy) => {
        button.disabled = busy;
        button.style.opacity = busy ? '.65' : '1';
        button.style.cursor = busy ? 'wait' : 'pointer';
    };

    const download = (url) => {
        const frame = document.createElement('iframe');
        frame.hidden = true;
        frame.src = url;
        document.body.appendChild(frame);
        window.setTimeout(() => frame.remove(), 60000);
    };

    const poll = async (button, status) => {
        if (!activeRunId) return;

        try {
            const statusUrl = new URL('{{ route('viefund-transactions.export.status') }}', window.location.origin);
            statusUrl.searchParams.set('run_id', activeRunId);
            const response = await fetch(statusUrl.toString(), {
                headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                cache: 'no-store',
            });
            const data = await response.json();
            if (data.run_id !== activeRunId) return;

            if (data.inProgress) {
                const reportedProgress = Number(data.progress_pct || 0);
                lastProgress = Math.max(lastProgress, reportedProgress);
                const progress = data.progress_pct === null ? '' : ` (${lastProgress}% complete)`;
                showStatus(status, `${data.message || 'Generating Excel export...'}${progress}`);
                pollTimer = window.setTimeout(() => poll(button, status), 3000);
                return;
            }

            window.clearTimeout(pollTimer);
            pollTimer = null;
            setBusy(button, false);
            if (data.success === true && data.download_url) {
                showStatus(status, data.message || 'Excel export completed. Downloading...');
                download(data.download_url);
                localStorage.removeItem(ACTIVE_RUN_KEY);
            } else {
                showStatus(status, data.message || 'The Excel export could not be generated.', true);
                localStorage.removeItem(ACTIVE_RUN_KEY);
            }
        } catch (_) {
            pollTimer = window.setTimeout(() => poll(button, status), 5000);
        }
    };

    document.addEventListener('change', async (event) => {
        const button = event.target.closest('#all-transactions-excel-export');
        if (!button || !button.value) return;

        const form = document.getElementById('all-transactions-excel-form');
        const status = document.getElementById('all-transactions-export-status');
        if (!form || !status) return;

        const exportMode = button.value;
        setBusy(button, true);
        showStatus(status, 'Starting the All Transactions Excel export...');
        try {
            const formData = new FormData(form);
            formData.set('linked_record_layout', exportMode);
            formData.set('include_eft_records', '1');
            formData.set('include_bank_records', '1');
            formData.set('include_fsp_records', '1');
            formData.set('include_7960_fsp_records', '1');
            button.value = '';
            const response = await fetch(form.action, {
                method: 'POST',
                body: formData,
                headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            });
            const data = await response.json();
            if (!response.ok) {
                const validationMessage = data.errors ? Object.values(data.errors).flat()[0] : null;
                throw new Error(validationMessage || data.message || 'The Excel export could not start.');
            }

            showStatus(status, data.message || 'Excel export started.');
            activeRunId = data.run_id || null;
            lastProgress = 0;
            if (!activeRunId) throw new Error('The export started without a tracking ID.');
            localStorage.setItem(ACTIVE_RUN_KEY, activeRunId);
            if (pollTimer) window.clearTimeout(pollTimer);
            poll(button, status);
        } catch (error) {
            setBusy(button, false);
            button.value = '';
            showStatus(status, error.message, true);
        }
    });

    if (activeRunId) {
        const button = document.getElementById('all-transactions-excel-export');
        const status = document.getElementById('all-transactions-export-status');
        if (button && status) {
            setBusy(button, true);
            showStatus(status, 'Resuming export progress...');
            poll(button, status);
        }
    }
})();
</script>
@endsection
