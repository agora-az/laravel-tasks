@extends('layouts.app')

@section('title', 'VieFund Customer Balances')

@section('content')
@include('reconciliations.partials.source-tabs', ['activeReconciliation' => 'customer-balances'])

@php
    $money = static function ($value) {
        $amount = (float) $value;
        $formatted = '$' . number_format(abs($amount), 2);
        return $amount < 0 ? '(' . $formatted . ')' : $formatted;
    };
    $openedBeforeInput = '';
    if (!empty($filters['opened_before'])) {
        try {
            $openedBeforeInput = \Carbon\Carbon::parse($filters['opened_before'])->format('Y-m-d\TH:i');
        } catch (\Throwable) {
            $openedBeforeInput = '';
        }
    }
    $easternNowInput = \Carbon\Carbon::now(config('viefund.simulated_report_timezone', 'America/Toronto'))->format('Y-m-d\TH:i');
@endphp

<div style="margin-bottom:18px;">
    <h2 style="margin:0;">VieFund Customer Balances</h2>
    <div style="color:#718096;font-size:13px;margin-top:4px;">Plan-account cash balances for a selected day from the direct VieFund cash transaction ledger.</div>
</div>

<div class="card" style="margin-bottom:18px;padding:18px 20px;border-top:4px solid #0f766e;">
    @if(isset($errors) && $errors->any())
        <div role="alert" style="margin-bottom:12px;padding:10px 12px;border:1px solid #f6ad55;background:#fffaf0;color:#9c4221;border-radius:5px;font-size:13px;">
            {{ $errors->first() }}
        </div>
    @endif
    <form method="GET" action="{{ route('reconciliations.customer-balances') }}">
        <input type="hidden" name="per_page" value="{{ $perPage }}">
        <div class="customer-balance-primary-filters" style="display:grid;grid-template-columns:minmax(160px,1fr) minmax(170px,1fr) minmax(140px,.75fr) minmax(320px,1.7fr) auto;gap:12px;align-items:end;">
            <div>
                <label for="report-date" style="display:block;font-size:12px;font-weight:700;color:#4a5568;margin-bottom:5px;">Reporting Date</label>
                <input type="date" id="report-date" name="report_date" value="{{ $filters['report_date'] }}" max="{{ now()->toDateString() }}" required style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #cbd5e0;border-radius:4px;">
            </div>
            <div>
                <label for="date-basis" style="display:block;font-size:12px;font-weight:700;color:#4a5568;margin-bottom:5px;">Date Basis</label>
                <select id="date-basis" name="date_basis" style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #cbd5e0;border-radius:4px;background:#fff;">
                    @foreach($dateBasisOptions as $value => $label)
                        <option value="{{ $value }}" @selected($filters['date_basis'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="currency" style="display:block;font-size:12px;font-weight:700;color:#4a5568;margin-bottom:5px;">Currency Code</label>
                <select id="currency" name="currency" style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #cbd5e0;border-radius:4px;background:#fff;">
                    <option value="CAD" @selected($filters['currency'] === 'CAD')>CAD</option>
                    <option value="USD" @selected($filters['currency'] === 'USD')>USD</option>
                </select>
            </div>
            <div>
                <label for="opened-before" style="display:block;font-size:12px;font-weight:700;color:#4a5568;margin-bottom:5px;">Simulated Report Generation Time — Eastern (EST/EDT) (optional)</label>
                <input type="datetime-local" id="opened-before" name="opened_before" value="{{ $openedBeforeInput }}" max="{{ $easternNowInput }}" style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #cbd5e0;border-radius:4px;">
            </div>
            <button class="btn" type="submit" style="padding:8px 18px;white-space:nowrap;">View Table</button>
        </div>

        <fieldset style="margin:12px 0 0;padding:11px 12px;border:1px solid #d8e4e2;border-radius:6px;background:#f5faf9;">
            <legend style="padding:0 4px;font-size:12px;font-weight:700;color:#4a5568;">Cash Transaction Status</legend>
            <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;font-size:12px;color:#2d3748;">
                @foreach($statusOptions as $value => $label)
                    <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;">
                        <input type="checkbox" name="status[]" value="{{ $value }}" @checked(in_array($value, $filters['status'], true))>
                        <span>{{ $label }}</span>
                    </label>
                @endforeach
                <span style="margin-left:auto;color:#718096;">If none are selected, Confirmed is used.</span>
            </div>
        </fieldset>
    </form>
</div>

<div class="customer-balance-summary" style="display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:12px;margin-bottom:14px;">
    <div class="card" style="padding:14px 16px;">
        <div style="font-size:11px;font-weight:700;color:#718096;text-transform:uppercase;letter-spacing:.04em;">Total Settled Balance</div>
        <div style="font-size:20px;font-weight:700;color:#2d3748;margin-top:4px;">{{ $money($summary->total_balance ?? 0) }} <span style="font-size:12px;color:#718096;">{{ $filters['currency'] }}</span></div>
    </div>
    <div class="card" style="padding:14px 16px;">
        <div style="font-size:11px;font-weight:700;color:#718096;text-transform:uppercase;letter-spacing:.04em;">Plan Accounts</div>
        <div style="font-size:20px;font-weight:700;color:#2d3748;margin-top:4px;">{{ number_format((int) ($summary->plan_accounts ?? 0)) }}</div>
    </div>
    <div class="card" style="padding:14px 16px;">
        <div style="font-size:11px;font-weight:700;color:#718096;text-transform:uppercase;letter-spacing:.04em;">Reported Account Rows</div>
        <div style="font-size:20px;font-weight:700;color:#2d3748;margin-top:4px;">{{ number_format((int) ($summary->account_rows ?? 0)) }}</div>
    </div>
    <div class="card" style="padding:14px 16px;">
        <div style="font-size:11px;font-weight:700;color:#718096;text-transform:uppercase;letter-spacing:.04em;">Future Settlement Cash</div>
        <div style="font-size:20px;font-weight:700;color:{{ (float) ($summary->future_settlement_cash ?? 0) > 0 ? '#b45309' : '#2d3748' }};margin-top:4px;">{{ $money($summary->future_settlement_cash ?? 0) }}</div>
    </div>
</div>

<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:10px;font-size:12px;color:#4a5568;">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <strong>Balance source:</strong>
        <span style="display:inline-flex;align-items:center;gap:6px;padding:5px 9px;border-radius:999px;background:#fef3c7;color:#92400e;font-weight:700;">
            <span style="width:7px;height:7px;border-radius:50%;background:currentColor;"></span>Direct Cash Ledger (Live)
        </span>
        @if(!empty($filters['opened_before']))
            <span>Simulated as of {{ $filters['opened_before'] }} Eastern</span>
        @endif
    </div>
    <span>Balances include cash activity through {{ $filters['report_date'] }}.</span>
</div>

<div class="card" style="padding:0;overflow:hidden;">
    <div style="max-height:calc(100vh - 210px);overflow:auto;">
        <table style="border-collapse:collapse;width:100%;min-width:1900px;font-size:12px;">
            <thead>
                <tr style="border-bottom:2px solid #94a3b8;">
                    @foreach([
                        ['Client Name', 'left'],
                        ['Rep Code', 'left'],
                        ['Plan Account ID', 'left'],
                        ['Account ID', 'left'],
                        ['Account Status', 'left'],
                        ['Cash Transactions', 'right'],
                        ['Settled Balance (' . $filters['currency'] . ')', 'right'],
                        ['Future Settlement Transactions', 'right'],
                        ['Future Settlement Cash', 'right'],
                        ['Next Settlement Date', 'left'],
                        ['Clarification Note', 'left'],
                    ] as [$heading, $alignment])
                        <th style="position:sticky;top:0;z-index:2;padding:11px 12px;text-align:{{ $alignment }};background:#dbeafe;color:#1e3a8a;white-space:nowrap;">{{ $heading }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse($balances as $balance)
                    @php
                        $futureCount = (int) ($balance->future_settlement_transaction_count ?? 0);
                        $nextSettlementDate = !empty($balance->next_settlement_date)
                            ? \Carbon\Carbon::parse($balance->next_settlement_date)->toDateString()
                            : '—';
                    @endphp
                    <tr style="border-bottom:1px solid #e2e8f0;vertical-align:top;">
                        <td style="padding:10px 12px;">{{ trim((string) ($balance->client_name ?? '')) ?: '—' }}</td>
                        <td style="padding:10px 12px;font-family:monospace;">{{ $balance->rep_code ?: '—' }}</td>
                        <td style="padding:10px 12px;font-family:monospace;">{{ $balance->plan_account_id ?: '—' }}</td>
                        <td style="padding:10px 12px;font-family:monospace;">{{ $balance->account_id ?: '—' }}</td>
                        <td style="padding:10px 12px;">{{ $balance->account_status ?: '—' }}</td>
                        <td style="padding:10px 12px;text-align:right;font-variant-numeric:tabular-nums;">{{ number_format((int) ($balance->cash_transaction_count ?? 0)) }}</td>
                        <td style="padding:10px 12px;text-align:right;font-family:monospace;font-weight:700;">{{ $money($balance->total_balance ?? 0) }}</td>
                        <td style="padding:10px 12px;text-align:right;font-variant-numeric:tabular-nums;">{{ number_format($futureCount) }}</td>
                        <td style="padding:10px 12px;text-align:right;font-family:monospace;color:{{ (float) ($balance->future_settlement_cash ?? 0) > 0 ? '#b45309' : '#2d3748' }};">{{ $money($balance->future_settlement_cash ?? 0) }}</td>
                        <td style="padding:10px 12px;white-space:nowrap;">{{ $nextSettlementDate }}</td>
                        <td style="padding:10px 12px;color:{{ $futureCount > 0 ? '#92400e' : '#a0aec0' }};max-width:300px;">{{ $futureCount > 0 ? 'Positive confirmed cash linked to unsettled trust; review required.' : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="11" style="padding:48px;text-align:center;color:#718096;">No customer balance rows match the selected criteria.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div style="padding:14px 16px;border-top:1px solid #e2e8f0;display:flex;align-items:center;gap:14px;flex-wrap:wrap;font-size:13px;color:#718096;">
        <label for="customer-balance-per-page">Rows per page:</label>
        <select id="customer-balance-per-page" onchange="window.location=this.value" style="border:1px solid #cbd5e0;border-radius:4px;padding:5px 8px;background:#fff;">
            @foreach([50,100,250] as $option)
                <option value="{{ request()->fullUrlWithQuery(['per_page'=>$option,'page'=>1]) }}" @selected($perPage === $option)>{{ $option }}</option>
            @endforeach
        </select>
        <span>Showing {{ number_format($balances->firstItem() ?? 0) }}–{{ number_format($balances->lastItem() ?? 0) }} of {{ number_format($balances->total()) }} account rows</span>
        @php
            $currentPage = $balances->currentPage();
            $lastPage = $balances->lastPage();
            $pageNumbers = array_values(array_unique(array_filter([
                1,
                $currentPage - 2,
                $currentPage - 1,
                $currentPage,
                $currentPage + 1,
                $currentPage + 2,
                $lastPage,
            ], fn($pageNumber) => $pageNumber >= 1 && $pageNumber <= $lastPage)));
            sort($pageNumbers);
            $pageButton = 'display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:34px;padding:0 9px;border:1px solid #cbd5e0;border-radius:5px;text-decoration:none;color:#2d3748;background:#fff;box-sizing:border-box;';
        @endphp
        @if($lastPage > 1)
            <nav aria-label="Customer balance pages" style="margin-left:auto;display:flex;align-items:center;justify-content:flex-end;gap:4px;max-width:100%;flex-wrap:wrap;">
                @if($balances->onFirstPage())
                    <span style="{{ $pageButton }}color:#a0aec0;">‹</span>
                @else
                    <a href="{{ $balances->previousPageUrl() }}" style="{{ $pageButton }}">‹</a>
                @endif
                @php $previousNumber = null; @endphp
                @foreach($pageNumbers as $pageNumber)
                    @if($previousNumber !== null && $pageNumber > $previousNumber + 1)
                        <span style="padding:0 4px;">…</span>
                    @endif
                    @if($pageNumber === $currentPage)
                        <span aria-current="page" style="{{ $pageButton }}background:#2c5364;color:#fff;border-color:#2c5364;font-weight:700;">{{ $pageNumber }}</span>
                    @else
                        <a href="{{ $balances->url($pageNumber) }}" style="{{ $pageButton }}">{{ $pageNumber }}</a>
                    @endif
                    @php $previousNumber = $pageNumber; @endphp
                @endforeach
                @if($balances->hasMorePages())
                    <a href="{{ $balances->nextPageUrl() }}" style="{{ $pageButton }}">›</a>
                @else
                    <span style="{{ $pageButton }}color:#a0aec0;">›</span>
                @endif
            </nav>
        @endif
    </div>
</div>

<style>
    @media (max-width: 1100px) {
        .customer-balance-primary-filters { grid-template-columns:repeat(2,minmax(0,1fr)) !important; }
    }
    @media (max-width: 700px) {
        .customer-balance-primary-filters,
        .customer-balance-summary { grid-template-columns:1fr !important; }
    }
</style>
@endsection
