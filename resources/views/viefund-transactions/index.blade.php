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
    $coreHeadings = [
        ['label' => 'Cash Txn ID'],
        ['label' => 'Fund Txn ID'],
        ['label' => 'Trust Txn ID'],
        ['label' => 'Relationship'],
        ['label' => 'Source ID'],
        ['label' => 'Customer Name'],
        ['label' => 'Plan Account ID'],
        ['label' => 'Txn Type'],
        ['label' => 'Cash Status'],
        ['label' => 'Trust Status'],
        ['label' => 'Notes'],
        ['label' => 'Created Date', 'sort' => 'created_date'],
        ['label' => 'Trade Date', 'sort' => 'trade_date'],
        ['label' => 'Processing Date', 'sort' => 'processing_date'],
        ['label' => 'Settlement Date', 'sort' => 'settlement_date'],
        ['label' => 'Currency'],
        ['label' => 'Amount', 'sort' => 'amount'],
    ];
    $eftHeadings = [
        ['label' => 'EFT File', 'field' => 'file_name', 'width' => 260],
        ['label' => 'EFT Item ID', 'field' => 'id'],
        ['label' => 'EFT Sequence', 'field' => 'sequence_number'],
        ['label' => 'EFT Created', 'field' => 'created_at', 'width' => 145],
        ['label' => 'EFT Effective', 'field' => 'effective_date', 'width' => 125],
        ['label' => 'EFT Trade', 'field' => 'trade_date', 'width' => 125],
        ['label' => 'EFT Settlement', 'field' => 'settlement_date', 'width' => 125],
        ['label' => 'EFT Type', 'field' => 'type', 'width' => 170],
        ['label' => 'EFT Status', 'field' => 'status_id'],
        ['label' => 'EFT Holder', 'field' => 'holder_name', 'width' => 180],
        ['label' => 'EFT Holder ID', 'field' => 'holder_id', 'width' => 170],
        ['label' => 'EFT Source', 'field' => 'source', 'width' => 160],
        ['label' => 'EFT Amount', 'field' => 'amount', 'width' => 130],
        ['label' => 'EFT Notes', 'field' => 'notes', 'width' => 220],
    ];
    $bankHeadings = [
        ['label' => 'Bank Txn ID', 'field' => 'id'],
        ['label' => 'Bank Value Date', 'field' => 'value_date', 'width' => 130],
        ['label' => 'Bank Direction', 'field' => 'direction'],
        ['label' => 'Bank Amount', 'field' => 'amount', 'width' => 130],
        ['label' => 'Bank Currency', 'field' => 'currency'],
        ['label' => 'Bank Account', 'field' => 'account_number', 'width' => 165],
        ['label' => 'Bank Settlement #', 'field' => 'settlement_number', 'width' => 145],
        ['label' => 'Bank Memo Type', 'field' => 'memo_type', 'width' => 150],
        ['label' => 'Bank Counterparty', 'field' => 'counterparty', 'width' => 180],
        ['label' => 'Bank Wire Ref', 'field' => 'wire_reference', 'width' => 170],
        ['label' => 'Bank Description', 'field' => 'description', 'width' => 260],
        ['label' => 'Bank Source File', 'field' => 'source_file', 'width' => 220],
        ['label' => 'AGRA Bank Match', 'field' => 'reconciliation_status', 'width' => 175],
        ['label' => 'AGRA Bank Variance', 'field' => 'reconciliation_variance', 'width' => 165],
        ['label' => 'AGRA Bank Note', 'field' => 'reconciliation_note', 'width' => 300],
    ];
    $fspHeadings = [
        ['label' => 'AGRA FSP File', 'field' => 'source_file', 'width' => 260],
        ['label' => 'FSP Record ID', 'field' => 'id'],
        ['label' => 'FSP Record #', 'field' => 'record_index'],
        ['label' => 'FSP Created', 'field' => 'create_date', 'width' => 125],
        ['label' => 'FSP Trade', 'field' => 'trade_date', 'width' => 125],
        ['label' => 'FSP Settlement', 'field' => 'settlement_date', 'width' => 125],
        ['label' => 'FSP Side', 'field' => 'side'],
        ['label' => 'FSP Txn Type', 'field' => 'transaction_type', 'width' => 130],
        ['label' => 'FSP Order ID', 'field' => 'order_id', 'width' => 155],
        ['label' => 'FSP Source ID', 'field' => 'source_id', 'width' => 180],
        ['label' => 'FSP Dealer Account', 'field' => 'dealer_account_id', 'width' => 175],
        ['label' => 'FSP Fund Account', 'field' => 'fund_account_id', 'width' => 175],
        ['label' => 'FSP Fund ID', 'field' => 'fund_id'],
        ['label' => 'FSP Currency', 'field' => 'currency'],
        ['label' => 'FSP Gross', 'field' => 'gross_amount', 'width' => 130],
        ['label' => 'FSP Net', 'field' => 'net_amount', 'width' => 130],
        ['label' => 'FSP Settlement Amount', 'field' => 'settlement_amount', 'width' => 165],
    ];
@endphp
<details class="card" style="padding:0;margin-bottom:20px;" open>
    <summary style="display:flex;align-items:center;justify-content:space-between;padding:14px 20px;background:#edf2f7;cursor:pointer;list-style:none;border-radius:6px;font-size:12px;font-weight:800;color:#4a5568;text-transform:uppercase;letter-spacing:.08em;">
        <span>Transaction Filters</span>
        <span style="color:#718096;">Collapse⌄</span>
    </summary>
    <form action="{{ route('viefund-transactions.index') }}" method="GET" style="padding:20px 24px;" data-inception-dates='@json($inceptionDates)'>
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
                <div class="all-transactions-filter-help">FSP matches use AGRA Source ID = VieFund fund transaction SourceID.</div>
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
    .all-transactions-match-option-fsp {
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
    #all-transactions-table td.all-transactions-bank-column.all-transactions-wire-fee-cell {
        background:#fffaf0;
        color:#975a16;
    }
    #all-transactions-table tr.all-transactions-wire-fee-row > td {
        border-top:1px solid #ecc94b;
        border-bottom:1px solid #ecc94b;
    }
    #all-transactions-table tr.all-transactions-wire-fee-row > td:not(.all-transactions-eft-column):not(.all-transactions-bank-column):not(.all-transactions-fsp-column) {
        background:#fffaf0;
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
        vertical-align:middle;
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
    @media (max-width: 1100px) {
        .all-transactions-date-filters { grid-template-columns:repeat(2,minmax(0,1fr)) !important; }
        .all-transactions-secondary-filters { grid-template-columns:1fr; }
    }
    @media (max-width: 700px) {
        .all-transactions-date-filters { grid-template-columns:1fr !important; }
    }
</style>

@if($connectionError)
    <div class="alert alert-error">{{ $connectionError }}</div>
@elseif($transactions)
    <div class="card" style="padding:0;overflow:hidden;">
        <div style="padding:16px 20px;background:linear-gradient(90deg,#ebf8ff,#f0fff4);border-bottom:1px solid #bee3f8;display:flex;align-items:center;justify-content:space-between;gap:16px;">
            <div>
                <div style="font-size:11px;font-weight:700;color:#2c5282;text-transform:uppercase;letter-spacing:.08em;">VieFund Transactions</div>
                <div style="font-size:24px;font-weight:700;color:#1a365d;">All Transactions</div>
            </div>
            <div style="display:flex;align-items:flex-end;gap:18px;flex-wrap:wrap;justify-content:flex-end;">
                <fieldset style="margin:0;padding:0;border:0;">
                    <legend style="margin-bottom:7px;padding:0;color:#4a5568;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;">
                        Display Matched Data
                        <span class="all-transactions-info" tabindex="0" aria-label="Excel export layout information">
                            i
                            <span class="all-transactions-info-popover" role="tooltip">Single Sheet appends the selected EFT, Bank, and FSP (AGRA) details to each transaction row. Split Sheets writes a Transactions sheet plus deduplicated sheets for the matched data selected above.</span>
                        </span>
                    </legend>
                    <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
                        <label for="all-transactions-show-eft" style="display:flex;align-items:center;gap:6px;color:#4a5568;font-size:13px;cursor:pointer;white-space:nowrap;">
                            <input type="checkbox" id="all-transactions-show-eft" style="width:16px;height:16px;">
                            EFT
                        </label>
                        <label for="all-transactions-show-bank" style="display:flex;align-items:center;gap:6px;color:#4a5568;font-size:13px;cursor:pointer;white-space:nowrap;">
                            <input type="checkbox" id="all-transactions-show-bank" style="width:16px;height:16px;">
                            Bank
                        </label>
                        <label for="all-transactions-show-fsp" style="display:flex;align-items:center;gap:6px;color:#4a5568;font-size:13px;cursor:pointer;white-space:nowrap;">
                            <input type="checkbox" id="all-transactions-show-fsp" style="width:16px;height:16px;">
                            FSP (AGRA)
                        </label>
                    </div>
                </fieldset>
                <select id="all-transactions-excel-export" aria-label="Export Excel" class="btn" style="padding:8px 34px 8px 14px;font-size:13px;white-space:nowrap;cursor:pointer;">
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
            @foreach((array) ($filters['trx_type'] ?? []) as $type)
                <input type="hidden" name="filter_trx_type[]" value="{{ $type }}">
            @endforeach
            @foreach($statusIds as $statusId)
                <input type="hidden" name="filter_status[]" value="{{ $statusId }}">
            @endforeach
        </form>
        <div id="all-transactions-export-status" role="status" style="display:none;margin:16px 20px;padding:10px 14px;border:1px solid #99f6e4;border-radius:5px;background:#ecfdf5;color:#115e59;font-size:12px;font-weight:600;"></div>

        <div style="overflow-x:auto;">
            <table id="all-transactions-table" style="width:100%;border-collapse:collapse;min-width:2200px;">
                <thead>
                    <tr style="background:#f7fafc;border-bottom:2px solid #cbd5e0;">
                        @foreach($coreHeadings as $heading)
                            <th style="padding:12px;text-align:{{ $heading['label'] === 'Amount' ? 'right' : 'left' }};font-weight:700;color:#2d3748;white-space:nowrap;">
                                @if(!empty($heading['sort']))
                                    <a href="{{ $sortUrl($heading['sort']) }}" style="color:#2d3748;text-decoration:none;">{{ $heading['label'] }}{{ $sortIndicator($heading['sort']) }}</a>
                                @else
                                    {{ $heading['label'] }}
                                @endif
                            </th>
                        @endforeach
                        @foreach($eftHeadings as $heading)
                            <th class="all-transactions-eft-column {{ $loop->first ? 'all-transactions-eft-group-start' : '' }}" style="display:none;padding:12px;text-align:{{ $heading['field'] === 'amount' ? 'right' : 'left' }};font-weight:700;white-space:nowrap;min-width:{{ $heading['width'] ?? 105 }}px;">{{ $heading['label'] }}</th>
                        @endforeach
                        @foreach($bankHeadings as $heading)
                            <th class="all-transactions-bank-column {{ $loop->first ? 'all-transactions-bank-group-start' : '' }}" style="display:none;padding:12px;text-align:{{ in_array($heading['field'], ['amount','reconciliation_variance'], true) ? 'right' : 'left' }};font-weight:700;white-space:nowrap;min-width:{{ $heading['width'] ?? 110 }}px;">{{ $heading['label'] }}</th>
                        @endforeach
                        @foreach($fspHeadings as $heading)
                            <th class="all-transactions-fsp-column {{ $loop->first ? 'all-transactions-fsp-group-start' : '' }}" style="display:none;padding:12px;text-align:{{ in_array($heading['field'], ['gross_amount','net_amount','settlement_amount'], true) ? 'right' : 'left' }};font-weight:700;white-space:nowrap;min-width:{{ $heading['width'] ?? 110 }}px;">{{ $heading['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse($transactions as $transaction)
                        @php $amount = $transaction->amount !== null ? (float) $transaction->amount : null; @endphp
                        <tr style="border-bottom:1px solid #e2e8f0;">
                            <td style="padding:12px;font-family:monospace;white-space:nowrap;">{{ $transaction->transaction_id }}</td>
                            <td style="padding:12px;font-family:monospace;white-space:nowrap;">{{ $transaction->fund_transaction_id ? 'F-'.$transaction->fund_transaction_id : '–' }}</td>
                            <td style="padding:12px;font-family:monospace;white-space:nowrap;">{{ $transaction->trust_transaction_id ? 'T-'.$transaction->trust_transaction_id : '–' }}</td>
                            <td style="padding:12px;font-family:monospace;white-space:nowrap;">{{ $transaction->ledger_relationship }}</td>
                            <td style="padding:12px;font-family:monospace;">{{ $transaction->source_id ?: '–' }}</td>
                            <td style="padding:12px;font-family:monospace;">{{ $transaction->customer_name ?: '–' }}</td>
                            <td style="padding:12px;font-family:monospace;white-space:nowrap;">{{ $transaction->plan_account_id ?: '–' }}</td>
                            <td style="padding:12px;font-family:monospace;">{{ $transaction->transaction_type ?: '–' }}</td>
                            <td style="padding:12px;font-family:monospace;">{{ $transaction->status ?: '–' }}</td>
                            <td style="padding:12px;font-family:monospace;">{{ $transaction->trust_status ?: '–' }}</td>
                            <td style="padding:12px;font-family:monospace;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{{ $transaction->notes }}">{{ $transaction->notes ?: '–' }}</td>
                            @foreach(['created_date','trade_date','processing_date','settlement_date'] as $dateField)
                                <td style="padding:12px;font-family:monospace;white-space:nowrap;">{{ $transaction->{$dateField} ? date('m/d/Y H:i', strtotime($transaction->{$dateField})) : '–' }}</td>
                            @endforeach
                            <td style="padding:12px;font-family:monospace;white-space:nowrap;">{{ $currencyOptions[$transaction->currency_code] ?? ($transaction->currency_code ?: '–') }}</td>
                            <td style="padding:12px;text-align:right;color:{{ $amount === null || $amount == 0 ? '#718096' : ($amount < 0 ? '#c53030' : '#276749') }};font-family:monospace;font-weight:600;">{{ $amount === null ? '–' : ($amount < 0 ? '($'.number_format(abs($amount), 2).')' : '$'.number_format($amount, 2)) }}</td>
                            @foreach($eftHeadings as $heading)
                                <td class="all-transactions-eft-column all-transactions-eft-cell {{ $loop->first ? 'all-transactions-eft-group-start' : '' }}" data-trust-id="{{ $transaction->trust_transaction_id ?: '' }}" data-record-field="{{ $heading['field'] }}" style="display:none;padding:12px;font-family:monospace;min-width:{{ $heading['width'] ?? 105 }}px;text-align:{{ $heading['field'] === 'amount' ? 'right' : 'left' }};"><span>—</span></td>
                            @endforeach
                            @foreach($bankHeadings as $heading)
                                <td class="all-transactions-bank-column all-transactions-bank-cell {{ $loop->first ? 'all-transactions-bank-group-start' : '' }}" data-trust-id="{{ $transaction->trust_transaction_id ?: '' }}" data-cash-id="{{ $transaction->cash_transaction_id ?: '' }}" data-record-field="{{ $heading['field'] }}" style="display:none;padding:12px;font-family:monospace;min-width:{{ $heading['width'] ?? 110 }}px;text-align:{{ in_array($heading['field'], ['amount','reconciliation_variance'], true) ? 'right' : 'left' }};"><span>—</span></td>
                            @endforeach
                            @foreach($fspHeadings as $heading)
                                <td class="all-transactions-fsp-column all-transactions-fsp-cell {{ $loop->first ? 'all-transactions-fsp-group-start' : '' }}" data-cash-id="{{ $transaction->cash_transaction_id ?: '' }}" data-record-field="{{ $heading['field'] }}" style="display:none;padding:12px;font-family:monospace;min-width:{{ $heading['width'] ?? 110 }}px;text-align:{{ in_array($heading['field'], ['gross_amount','net_amount','settlement_amount'], true) ? 'right' : 'left' }};"><span>—</span></td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($coreHeadings) + count($eftHeadings) + count($bankHeadings) + count($fspHeadings) }}" style="padding:48px;text-align:center;color:#718096;">No VieFund cash-ledger transactions were found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div style="padding:14px 16px;border-top:1px solid #e2e8f0;display:flex;align-items:center;gap:14px;flex-wrap:wrap;font-size:13px;color:#718096;">
            <label for="per-page">Rows per page:</label>
            <select id="per-page" onchange="window.location=this.value" style="border:1px solid #cbd5e0;border-radius:4px;padding:5px 8px;background:#fff;">
                @foreach([50,100,250] as $option)
                    <option value="{{ request()->fullUrlWithQuery(['per_page'=>$option,'page'=>1]) }}" {{ $perPage === $option ? 'selected' : '' }}>{{ $option }}</option>
                @endforeach
            </select>
            <span>
                Showing {{ number_format($transactions->firstItem() ?? 0) }}–{{ number_format($transactions->lastItem() ?? 0) }} transactions
                <span id="all-transactions-total-summary" style="color:#718096;" aria-live="polite"> · Calculating filtered total…</span>
            </span>
            <div style="margin-left:auto;">{{ $transactions->withQueryString()->links() }}</div>
        </div>
    </div>
@endif

<script>
(() => {
    const ACTIVE_RUN_KEY = 'viefundAllTransactionsActiveRunId';
    const EFT_MATCH_VISIBILITY_KEY = 'viefundAllTransactionsShowEftMatches';
    const BANK_MATCH_VISIBILITY_KEY = 'viefundAllTransactionsShowBankMatches';
    const FSP_MATCH_VISIBILITY_KEY = 'viefundAllTransactionsShowAgraFspMatches';
    let pollTimer = null;
    let activeRunId = localStorage.getItem(ACTIVE_RUN_KEY) || null;
    let lastProgress = 0;
    let reconciliationMatchesLoaded = false;
    let reconciliationMatchesLoading = false;
    let fspMatchesLoaded = false;
    let fspMatchesLoading = false;
    let eftBankRecordsByTrust = {};
    let fspBankRecordsByCash = {};

    const filterForm = document.querySelector('form[data-inception-dates]');
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

    const eftToggle = document.getElementById('all-transactions-show-eft');
    const bankToggle = document.getElementById('all-transactions-show-bank');
    const fspToggle = document.getElementById('all-transactions-show-fsp');
    const transactionsTable = document.getElementById('all-transactions-table');
    const eftColumns = Array.from(document.querySelectorAll('.all-transactions-eft-column'));
    const eftCells = Array.from(document.querySelectorAll('.all-transactions-eft-cell'));
    const bankColumns = Array.from(document.querySelectorAll('.all-transactions-bank-column'));
    const bankCells = Array.from(document.querySelectorAll('.all-transactions-bank-cell'));
    const fspColumns = Array.from(document.querySelectorAll('.all-transactions-fsp-column'));
    const fspCells = Array.from(document.querySelectorAll('.all-transactions-fsp-cell'));
    const setColumnVisible = (columns, visible) => {
        columns.forEach((column) => {
            column.style.display = visible ? '' : 'none';
        });
    };
    const updateTableWidth = () => {
        if (!transactionsTable) return;
        const width = 2200 + (eftToggle?.checked ? 2400 : 0) + (bankToggle?.checked ? 3000 : 0) + (fspToggle?.checked ? 2600 : 0);
        transactionsTable.style.minWidth = `${width}px`;
    };
    const formatLinkedDate = (value, includeTime = false) => {
        if (!value) return '—';
        const match = String(value).match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
        if (!match) return String(value);
        const formatted = `${match[2]}/${match[3]}/${match[1]}`;
        return includeTime && match[4] ? `${formatted} ${match[4]}:${match[5]}` : formatted;
    };
    const formatLinkedAmount = (value) => {
        if (value === null || value === undefined || value === '') return '—';
        const amount = Number(value);
        if (!Number.isFinite(amount)) return String(value);
        const formatted = Math.abs(amount).toLocaleString('en-CA', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        return amount < 0 ? `($${formatted})` : `$${formatted}`;
    };
    const linkedRecordValue = (record, type, field) => {
        if (['amount', 'gross_amount', 'net_amount', 'settlement_amount', 'reconciliation_variance'].includes(field)) return formatLinkedAmount(record[field]);
        if (['effective_date', 'create_date', 'trade_date', 'settlement_date', 'value_date', 'booking_date'].includes(field)) {
            return formatLinkedDate(record[field]);
        }
        if (field === 'created_at') return formatLinkedDate(record[field], true);
        if (type === 'eft' && field === 'file_name') {
            return record.file_name || (record.file_id ? `EFT file #${record.file_id}` : `Unprocessed EFT item #${record.id}`);
        }
        if (type === 'bank' && field === 'id') return `Bank txn #${record.id}`;
        if (type === 'fsp' && field === 'id') return `FSP item #${record.id}`;
        const value = record[field];
        return value === null || value === undefined || value === '' ? '—' : String(value);
    };
    const renderLinkedRecords = (cell, records, type) => {
        cell.replaceChildren();
        if (!Array.isArray(records) || records.length === 0) {
            const empty = document.createElement('span');
            empty.textContent = '—';
            empty.title = type === 'eft'
                ? 'No EFT item is linked to this transaction.'
                : (type === 'bank'
                    ? 'No bank transaction matches the linked EFT sequence.'
                    : 'No imported AGRA FSP item has this VieFund fund source ID.');
            empty.style.color = '#718096';
            cell.appendChild(empty);
            return;
        }

        records.forEach((record) => {
            const row = document.createElement('div');
            row.className = 'all-transactions-linked-value';
            const field = cell.dataset.recordField;
            if (['amount', 'gross_amount', 'net_amount', 'settlement_amount', 'reconciliation_variance'].includes(field)) {
                row.style.justifyContent = 'flex-end';
                row.style.textAlign = 'right';
            }
            const value = linkedRecordValue(record, type, field);
            row.title = value === '—' ? '' : value;
            const isLink = (type === 'eft' && field === 'file_name')
                || (type === 'bank' && field === 'id')
                || (type === 'fsp' && field === 'source_file');
            if (isLink && record.url) {
                const link = document.createElement('a');
                link.href = record.url;
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
                link.textContent = value;
                link.title = type === 'eft'
                    ? 'Open the matched EFT item'
                    : (type === 'bank' ? 'Open this bank transaction' : 'Open this AGRA FSP item');
                link.style.color = '#2b6cb0';
                link.style.fontWeight = '600';
                link.style.textDecoration = 'underline';
                row.appendChild(link);
            } else {
                const text = document.createElement('span');
                text.textContent = value;
                if (value === '—') text.style.color = '#718096';
                if (['amount', 'gross_amount', 'net_amount', 'settlement_amount', 'reconciliation_variance'].includes(field) && record[field] !== null && record[field] !== undefined) {
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
            const fspRecords = fspBankRecordsByCash[cell.dataset.cashId] || [];
            const records = [...fspRecords, ...eftRecords].filter((record, index, all) =>
                all.findIndex((candidate) => Number(candidate.id) === Number(record.id)) === index
            );
            cell.classList.toggle(
                'all-transactions-wire-fee-cell',
                fspRecords.some((record) => record.is_possible_wire_fee_match === true)
            );
            cell.closest('tr')?.classList.toggle(
                'all-transactions-wire-fee-row',
                fspRecords.some((record) => record.is_possible_wire_fee_match === true)
            );
            renderLinkedRecords(cell, records, 'bank');
        });
    };
    const loadReconciliationMatches = async () => {
        if (reconciliationMatchesLoaded || reconciliationMatchesLoading || (eftCells.length === 0 && bankCells.length === 0)) return;
        const matchCells = [...eftCells, ...bankCells];
        const trustIds = [...new Set(matchCells.map((cell) => cell.dataset.trustId).filter(Boolean))];
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
            if (!response.ok) throw new Error(data.message || 'EFT and bank matches could not be loaded.');
            eftCells.forEach((cell) => renderLinkedRecords(cell, data.eft_records?.[cell.dataset.trustId] || [], 'eft'));
            eftBankRecordsByTrust = data.bank_records || {};
            renderBankRecords();
            reconciliationMatchesLoaded = true;
        } catch (error) {
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
            renderBankRecords();
        } finally {
            reconciliationMatchesLoading = false;
        }
    };
    const loadFspMatches = async () => {
        if (fspMatchesLoaded || fspMatchesLoading || fspCells.length === 0) return;
        const cashTransactionIds = [...new Set(fspCells.map((cell) => cell.dataset.cashId).filter(Boolean))];
        fspCells.forEach((cell) => {
            const status = document.createElement('span');
            const hasSource = Boolean(cell.dataset.cashId);
            const isGroupStart = cell.classList.contains('all-transactions-fsp-group-start');
            status.className = hasSource && isGroupStart ? 'all-transactions-eft-loading' : '';
            status.textContent = hasSource ? (isGroupStart ? 'Loading…' : '…') : '—';
            status.title = hasSource ? '' : 'This cash transaction has no related fund source ID.';
            cell.replaceChildren(status);
        });
        if (cashTransactionIds.length === 0) {
            fspMatchesLoaded = true;
            return;
        }

        fspMatchesLoading = true;
        try {
            const response = await fetch('{{ route('viefund-transactions.agra-fsp-matches') }}', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: JSON.stringify({cash_transaction_ids: cashTransactionIds.map(Number)}),
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'AGRA FSP matches could not be loaded.');
            fspCells.forEach((cell) => {
                renderLinkedRecords(cell, data.fsp_records?.[cell.dataset.cashId] || [], 'fsp');
            });
            fspBankRecordsByCash = data.bank_records || {};
            renderBankRecords();
            fspMatchesLoaded = true;
        } catch (error) {
            fspCells.forEach((cell) => {
                if (!cell.dataset.cashId) return;
                const message = document.createElement('span');
                message.textContent = 'Unable to load';
                message.title = error.message;
                message.style.color = '#c53030';
                cell.replaceChildren(message);
            });
        } finally {
            fspMatchesLoading = false;
        }
    };
    eftToggle?.addEventListener('change', () => {
        const visible = eftToggle.checked;
        setColumnVisible(eftColumns, visible);
        updateTableWidth();
        try {
            sessionStorage.setItem(EFT_MATCH_VISIBILITY_KEY, visible ? '1' : '0');
        } catch (_) {}
        if (visible) loadReconciliationMatches();
    });
    bankToggle?.addEventListener('change', () => {
        const visible = bankToggle.checked;
        setColumnVisible(bankColumns, visible);
        updateTableWidth();
        try {
            sessionStorage.setItem(BANK_MATCH_VISIBILITY_KEY, visible ? '1' : '0');
        } catch (_) {}
        if (visible) loadReconciliationMatches();
        if (visible) loadFspMatches();
    });
    fspToggle?.addEventListener('change', () => {
        const visible = fspToggle.checked;
        setColumnVisible(fspColumns, visible);
        updateTableWidth();
        try {
            sessionStorage.setItem(FSP_MATCH_VISIBILITY_KEY, visible ? '1' : '0');
        } catch (_) {}
        if (visible) loadFspMatches();
    });
    let showEftMatches = @json($hasEftMatch);
    let showBankMatches = false;
    let showFspMatches = @json($hasAgraFspMatch);
    try {
        showEftMatches = showEftMatches || sessionStorage.getItem(EFT_MATCH_VISIBILITY_KEY) === '1';
        showBankMatches = sessionStorage.getItem(BANK_MATCH_VISIBILITY_KEY) === '1';
        showFspMatches = showFspMatches || sessionStorage.getItem(FSP_MATCH_VISIBILITY_KEY) === '1';
    } catch (_) {}
    if (eftToggle && showEftMatches) {
        eftToggle.checked = true;
        setColumnVisible(eftColumns, true);
    }
    if (bankToggle && showBankMatches) {
        bankToggle.checked = true;
        setColumnVisible(bankColumns, true);
    }
    if (fspToggle && showFspMatches) {
        fspToggle.checked = true;
        setColumnVisible(fspColumns, true);
    }
    updateTableWidth();
    if (showEftMatches || showBankMatches) {
        loadReconciliationMatches();
    }
    if (showFspMatches || showBankMatches) {
        loadFspMatches();
    }

    const totalSummary = document.getElementById('all-transactions-total-summary');
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
            if (data.state === 'complete') {
                const total = Number(data.total || 0).toLocaleString('en-CA');
                const pages = Number(data.total_pages || 1).toLocaleString('en-CA');
                const page = Number(data.page || 1).toLocaleString('en-CA');
                totalSummary.textContent = ` · ${total} total · Page ${page} of ${pages}`;
                totalSummary.title = data.cached ? 'Cached for this filter selection.' : '';
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
            formData.set('include_eft_records', eftToggle?.checked ? '1' : '0');
            formData.set('include_bank_records', bankToggle?.checked ? '1' : '0');
            formData.set('include_fsp_records', fspToggle?.checked ? '1' : '0');
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
