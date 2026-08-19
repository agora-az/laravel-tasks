@extends('layouts.app')

@section('title', 'Bank / FSP '.$sourceLabel.' Comparison')

@section('content')
@php
    $formatMoney = static function ($amount): string {
        $value = (float) $amount;
        $formatted = '$'.number_format(abs($value), 2);
        return $value < 0 ? '('.$formatted.')' : $formatted;
    };
    $isMismatch = abs($variance) >= .01;
    $sortUrl = static function (string $column) use ($sort, $direction): string {
        $query = request()->except(['page', 'sort', 'direction']);
        $query['sort'] = $column;
        $query['direction'] = $sort === $column && $direction === 'asc' ? 'desc' : 'asc';
        return url()->current().'?'.http_build_query($query);
    };
    $sortIndicator = static function (string $column) use ($sort, $direction): string {
        return $sort === $column ? ($direction === 'asc' ? '↑' : '↓') : '⇅';
    };
@endphp

<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:20px;">
    <div>
        <h2 style="margin:0;">Bank / FSP {{ $sourceLabel }} Comparison</h2>
        <div style="color:#718096;font-size:13px;margin-top:4px;">{{ $date }} · {{ $currency }}</div>
    </div>
    <a href="{{ route('reconciliations.bank-fsp.source', ['source'=>$source]) }}" class="btn" style="text-decoration:none;padding:8px 16px;">Back to Bank / FSP {{ $sourceLabel }}</a>
</div>

<div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-bottom:24px;">
    <div class="card" style="background:linear-gradient(135deg,#345262 0%,#5a7585 100%);color:#fff;text-align:center;">
        <div style="font-size:24px;font-weight:bold;">{{ $formatMoney($bankNet) }}</div>
        <div style="font-size:13px;opacity:.9;margin-top:4px;">Bank FundServ Net</div>
    </div>
    <div class="card" style="background:linear-gradient(135deg,#3182ce 0%,#2c5aa0 100%);color:#fff;text-align:center;">
        <div style="font-size:24px;font-weight:bold;">{{ $formatMoney($fspNet) }}</div>
        <div style="font-size:13px;opacity:.9;margin-top:4px;">{{ $sourceLabel }} FSP Net · {{ number_format($fspItemCount) }} Items</div>
    </div>
    <div class="card" style="background:{{ $isMismatch ? 'linear-gradient(135deg,#e53e3e 0%,#c53030 100%)' : 'linear-gradient(135deg,#38a169 0%,#2f855a 100%)' }};color:#fff;text-align:center;">
        <div style="font-size:24px;font-weight:bold;">{{ $formatMoney($variance) }}</div>
        <div style="font-size:13px;opacity:.9;margin-top:4px;">Variance</div>
    </div>
</div>

<div style="display:grid;grid-template-columns:minmax(360px,.8fr) minmax(640px,1.7fr);gap:20px;align-items:start;">
    <div class="card">
        <h3 style="margin:0 0 14px;color:#2d3748;">Bank Transaction</h3>
        <div style="overflow-x:auto;">
            <table class="mono-grid" style="width:100%;border-collapse:collapse;">
                <thead><tr style="background:#f7fafc;border-bottom:2px solid #e2e8f0;"><th style="text-align:left;">Field</th><th style="text-align:left;">Value</th></tr></thead>
                <tbody>
                    @if($bankTransaction)
                        <tr><td>ID</td><td>{{ $bankTransaction->id }}</td></tr>
                        <tr><td>Account</td><td><a href="{{ route('reconciliations.daily-totals.bank-day', ['date'=>$date,'account'=>$bankTransaction->account_number]) }}" target="_blank" rel="noopener noreferrer">{{ $bankTransaction->account_number }}</a></td></tr>
                        <tr><td>Direction</td><td>{{ $bankTransaction->credit_debit_indicator }}</td></tr>
                        <tr><td>Amount</td><td style="font-weight:600;color:{{ $bankNet < 0 ? '#e53e3e' : '#276749' }};">{{ $formatMoney($bankNet) }}</td></tr>
                        <tr><td>Memo Type</td><td>{{ $bankTransaction->memo_type ?: '—' }}</td></tr>
                        <tr><td>Counterparty</td><td>{{ $bankTransaction->counterparty ?: '—' }}</td></tr>
                        <tr><td>Wire Ref</td><td>{{ $bankTransaction->wire_payment_reference ?: '—' }}</td></tr>
                        <tr><td>Description</td><td style="white-space:normal;overflow-wrap:anywhere;">{{ $bankTransaction->additional_info ?: '—' }}</td></tr>
                    @else
                        <tr><td colspan="2" style="text-align:center;color:#718096;padding:30px 10px;">No matching bank transaction.</td></tr>
                    @endif
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:14px;">
            <h3 style="margin:0;color:#2d3748;">{{ $sourceLabel }} FSP Transactions</h3>
            <div style="font-size:13px;color:#718096;">{{ number_format($fspItemCount) }} item(s)</div>
        </div>
        <form method="GET" action="{{ url()->current() }}" style="display:flex;align-items:end;gap:10px;margin-bottom:14px;flex-wrap:wrap;">
            <input type="hidden" name="currency" value="{{ $currency }}">
            @if(request('entry_id'))<input type="hidden" name="entry_id" value="{{ request('entry_id') }}">@endif
            @if($sort)<input type="hidden" name="sort" value="{{ $sort }}"><input type="hidden" name="direction" value="{{ $direction }}">@endif
            <div style="flex:1;min-width:220px;">
                <label for="fsp-amount-search" style="display:block;font-weight:600;color:#4a5568;font-size:12px;margin-bottom:5px;">Search amount</label>
                <input id="fsp-amount-search" name="amount" value="{{ $amountSearchInput }}" inputmode="decimal" placeholder="e.g. 100.00" style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #cbd5e0;border-radius:5px;font-family:inherit;font-size:13px;line-height:1.4;color:#2d3748;">
            </div>
            <button type="submit" class="btn" style="padding:8px 13px;font-size:13px;line-height:1.4;">Search</button>
            @if($amountSearchInput !== '')
                <a href="{{ url()->current().'?'.http_build_query(request()->except(['amount', 'page'])) }}" style="padding:8px 4px;font-size:13px;line-height:1.4;color:#2c5282;">Clear</a>
            @endif
            <div style="width:100%;font-size:11px;color:#718096;">Matches anywhere in the amount; BUY/SELL and positive/negative signs are ignored.</div>
        </form>
        <div style="overflow-x:auto;">
            <table class="mono-grid" style="width:100%;border-collapse:collapse;">
                <thead><tr style="background:#f7fafc;border-bottom:2px solid #e2e8f0;white-space:nowrap;">
                    <th style="text-align:left;"><a href="{{ $sortUrl('side') }}" style="color:inherit;text-decoration:none;">Side {{ $sortIndicator('side') }}</a></th>
                    <th style="text-align:right;"><a href="{{ $sortUrl('amount') }}" style="color:inherit;text-decoration:none;">Amount {{ $sortIndicator('amount') }}</a></th>
                    <th style="text-align:left;">Order ID</th><th style="text-align:left;">Source ID</th><th style="text-align:left;">Fund Account</th><th style="text-align:left;">Fund ID</th>
                </tr></thead>
                <tbody>
                    @forelse($fspTransactions as $item)
                        @php($signedAmount = $item->side === 'BUY' ? -(float)$item->settlement_amount : ($item->side === 'SELL' ? (float)$item->settlement_amount : 0))
                        <tr style="border-bottom:1px solid #e2e8f0;background:{{ $loop->even ? 'rgba(56,161,105,.07)' : 'transparent' }};">
                            <td>{{ $item->side ?: '—' }}</td>
                            <td style="text-align:right;color:{{ $signedAmount < 0 ? '#e53e3e' : '#276749' }};white-space:nowrap;">{{ $formatMoney($signedAmount) }}</td>
                            <td>{{ $item->order_id ?: '—' }}</td><td>{{ $item->source_id ?: '—' }}</td><td>{{ $item->fund_account_id ?: '—' }}</td><td>{{ $item->fund_id ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" style="text-align:center;color:#718096;padding:30px 10px;">No FSP transactions found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($fspTransactions->hasPages())
            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:14px;flex-wrap:wrap;">
                <div style="font-size:12px;color:#718096;">Showing {{ $fspTransactions->firstItem() }} to {{ $fspTransactions->lastItem() }} of {{ $fspTransactions->total() }} item(s)</div>
                {{ $fspTransactions->onEachSide(1)->links() }}
            </div>
        @endif
        @if($amountSearchInput !== '' && !$fspTransactions->hasPages())
            <div style="font-size:12px;color:#718096;margin-top:14px;">{{ number_format($fspTransactions->total()) }} matching item(s)</div>
        @endif
    </div>
</div>
@endsection
