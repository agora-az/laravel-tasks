@extends('layouts.app')

@section('title', 'VieFund Transactions')

@section('content')
<div style="margin:20px 0;">
    <div style="font-size:12px;font-weight:800;color:#2c5282;text-transform:uppercase;letter-spacing:.07em;">VieFund Transactions</div>
    <h2 style="margin:4px 0 0;">All Transactions</h2>
    <div style="color:#718096;font-size:13px;margin-top:4px;">All fund and standalone trust transactions, newest created first.</div>
</div>

@php $hasFilters = $search !== '' || !empty($filters); @endphp
<details class="card" style="padding:0;margin-bottom:20px;" open>
    <summary style="display:flex;align-items:center;justify-content:space-between;padding:14px 20px;background:#edf2f7;cursor:pointer;list-style:none;border-radius:6px;font-size:12px;font-weight:800;color:#4a5568;text-transform:uppercase;letter-spacing:.08em;">
        <span>Transaction Filters</span>
        <span style="color:#718096;">Collapse⌄</span>
    </summary>
    <form action="{{ route('viefund-transactions.index') }}" method="GET" style="padding:20px 24px;">
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

        <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px;">
            <div>
                <label for="all-trx-id" style="display:block;font-size:12px;font-weight:800;color:#4a5568;margin-bottom:6px;">Txn ID</label>
                <input id="all-trx-id" name="filter_trx_id" value="{{ $filters['trx_id'] ?? '' }}" placeholder="e.g. C-939"
                       style="width:100%;height:40px;padding:8px 12px;border:1px solid #cbd5e0;border-radius:4px;box-sizing:border-box;font-size:13px;font-family:monospace;">
            </div>
            <div>
                <label for="all-source-id" style="display:block;font-size:12px;font-weight:800;color:#4a5568;margin-bottom:6px;">Source ID</label>
                <input id="all-source-id" name="filter_source_id" value="{{ $filters['source_id'] ?? '' }}" placeholder="e.g. L20200…"
                       style="width:100%;height:40px;padding:8px 12px;border:1px solid #cbd5e0;border-radius:4px;box-sizing:border-box;font-size:13px;font-family:monospace;">
            </div>
            <div>
                <label for="all-created-from" style="display:block;font-size:12px;font-weight:800;color:#4a5568;margin-bottom:6px;">Created From</label>
                <input type="date" id="all-created-from" name="filter_created_from" value="{{ $filters['created_from'] ?? '' }}"
                       style="width:100%;height:40px;padding:8px 12px;border:1px solid #cbd5e0;border-radius:4px;box-sizing:border-box;font-size:13px;">
            </div>
            <div>
                <label for="all-created-to" style="display:block;font-size:12px;font-weight:800;color:#4a5568;margin-bottom:6px;">Created To</label>
                <input type="date" id="all-created-to" name="filter_created_to" value="{{ $filters['created_to'] ?? '' }}"
                       style="width:100%;height:40px;padding:8px 12px;border:1px solid #cbd5e0;border-radius:4px;box-sizing:border-box;font-size:13px;">
            </div>
        </div>

        <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;">
            <div>
                <label for="all-trx-types" style="display:block;font-size:12px;font-weight:800;color:#4a5568;margin-bottom:6px;">Txn Type</label>
                <select id="all-trx-types" name="filter_trx_type[]" multiple size="3"
                        style="width:100%;padding:7px 10px;border:1px solid #cbd5e0;border-radius:4px;background:#fff;font-size:13px;">
                    @foreach($availableTrxTypes as $type)
                        <option value="{{ $type }}" {{ in_array($type, (array)($filters['trx_type'] ?? []), true) ? 'selected' : '' }}>{{ $type }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="all-status-groups" style="display:block;font-size:12px;font-weight:800;color:#4a5568;margin-bottom:6px;">Status Group</label>
                <select id="all-status-groups" name="filter_status_group[]" multiple size="3"
                        style="width:100%;padding:7px 10px;border:1px solid #cbd5e0;border-radius:4px;background:#fff;font-size:13px;">
                    @foreach(['completed'=>'Completed','open'=>'Open','not_completed'=>'Not Completed'] as $value => $label)
                        <option value="{{ $value }}" {{ in_array($value, (array)($filters['status_group'] ?? []), true) ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px;padding-top:14px;border-top:1px solid #e2e8f0;">
            @if($hasFilters)
                <a href="{{ route('viefund-transactions.index') }}" class="btn" style="background:#718096;text-decoration:none;padding:8px 20px;font-size:13px;">Clear All</a>
            @endif
            <button type="submit" class="btn" style="padding:8px 24px;font-size:13px;">Apply Filters</button>
        </div>
    </form>
</details>

@if($connectionError)
    <div class="alert alert-error">{{ $connectionError }}</div>
@elseif($transactions)
    <div class="card" style="padding:0;overflow:hidden;">
        <div style="padding:16px 20px;background:linear-gradient(90deg,#ebf8ff,#f0fff4);border-bottom:1px solid #bee3f8;display:flex;align-items:center;justify-content:space-between;gap:16px;">
            <div>
                <div style="font-size:11px;font-weight:700;color:#2c5282;text-transform:uppercase;letter-spacing:.08em;">VieFund Transactions</div>
                <div style="font-size:24px;font-weight:700;color:#1a365d;">All Transactions</div>
            </div>
            <div style="color:#718096;font-size:13px;">Created date descending</div>
        </div>

        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;min-width:1450px;">
                <thead>
                    <tr style="background:#f7fafc;border-bottom:2px solid #cbd5e0;">
                        @foreach(['Txn ID','Source ID','Customer Name','Plan Account ID','Txn Type','Status','Notes','Created Date','Trade Date','Processing Date','Settlement Date','Amount −','Amount +'] as $heading)
                            <th style="padding:12px;text-align:{{ str_starts_with($heading, 'Amount') ? 'right' : 'left' }};font-weight:700;color:#2d3748;white-space:nowrap;">{{ $heading }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse($transactions as $transaction)
                        @php $amount = $transaction->amount !== null ? (float) $transaction->amount : null; @endphp
                        <tr style="border-bottom:1px solid #e2e8f0;">
                            <td style="padding:12px;font-family:monospace;white-space:nowrap;">{{ $transaction->transaction_id }}</td>
                            <td style="padding:12px;font-family:monospace;">{{ $transaction->source_id ?: '–' }}</td>
                            <td style="padding:12px;font-family:monospace;">{{ $transaction->customer_name ?: '–' }}</td>
                            <td style="padding:12px;font-family:monospace;white-space:nowrap;">{{ $transaction->plan_account_id ?: '–' }}</td>
                            <td style="padding:12px;font-family:monospace;">{{ $transaction->transaction_type ?: '–' }}</td>
                            <td style="padding:12px;font-family:monospace;">{{ $transaction->status ?: '–' }}</td>
                            <td style="padding:12px;font-family:monospace;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{{ $transaction->notes }}">{{ $transaction->notes ?: '–' }}</td>
                            @foreach(['created_date','trade_date','processing_date','settlement_date'] as $dateField)
                                <td style="padding:12px;font-family:monospace;white-space:nowrap;">{{ $transaction->{$dateField} ? date('m/d/Y H:i', strtotime($transaction->{$dateField})) : '–' }}</td>
                            @endforeach
                            <td style="padding:12px;text-align:right;color:#c53030;font-family:monospace;font-weight:600;">{{ $amount !== null && $amount < 0 ? '($'.number_format(abs($amount), 2).')' : '–' }}</td>
                            <td style="padding:12px;text-align:right;color:#276749;font-family:monospace;font-weight:600;">{{ $amount !== null && $amount >= 0 ? '$'.number_format($amount, 2) : '–' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="13" style="padding:48px;text-align:center;color:#718096;">No VieFund transactions were found.</td></tr>
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
            <span>Showing {{ number_format($transactions->firstItem() ?? 0) }}–{{ number_format($transactions->lastItem() ?? 0) }} transactions</span>
            <div style="margin-left:auto;">{{ $transactions->withQueryString()->links() }}</div>
        </div>
    </div>
@endif
@endsection
