@extends('layouts.app')

@section('title', 'Bank / EFT Sequence Comparison')

@section('content')
@php
    $formatMoney = static function ($amount): string {
        $value = (float) $amount;
        $formatted = '$'.number_format(abs($value), 2);
        return $value < 0 ? '('.$formatted.')' : $formatted;
    };
    $isMismatch = abs($variance) >= .01;
    $amountSortUrl = url()->current().'?'.http_build_query(array_merge(request()->except(['eft_page', 'sort', 'amount_direction']), [
        'sort' => 'amount',
        'amount_direction' => $sortByAmount && $amountSortDirection === 'asc' ? 'desc' : 'asc',
    ]));
    $amountSortIndicator = $sortByAmount ? ($amountSortDirection === 'asc' ? '↑' : '↓') : '⇅';
@endphp

<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin:20px 0;flex-wrap:wrap;">
    <div>
        <h2 style="margin:0;">Bank / EFT Sequence Comparison</h2>
        <div style="color:#718096;font-size:13px;margin-top:4px;">Sequence {{ $sequence }} · reconciled across all bank dates and accounts</div>
    </div>
    <a href="{{ route('reconciliations.daily-totals.settlement-sequences', ['date'=>$date,'account'=>$account,'include_3000_sequences'=>$include3000Sequences ? 1 : 0]) }}" class="btn" style="text-decoration:none;padding:8px 16px;">Back to Settlement Sequences</a>
</div>

<div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-bottom:24px;">
    <div class="card" style="background:linear-gradient(135deg,#345262 0%,#5a7585 100%);color:#fff;text-align:center;">
        <div style="font-size:24px;font-weight:bold;">{{ $formatMoney($bankNet) }}</div>
        <div style="font-size:13px;opacity:.9;margin-top:4px;">Bank Net · {{ number_format($bankTransactions->count()) }} Entries</div>
    </div>
    <div class="card" style="background:linear-gradient(135deg,#3182ce 0%,#2c5aa0 100%);color:#fff;text-align:center;">
        <div style="font-size:24px;font-weight:bold;">{{ $formatMoney($eftNet) }}</div>
        <div style="font-size:13px;opacity:.9;margin-top:4px;">VieFund EFT Net · {{ number_format($eftItemCount) }} Items</div>
    </div>
    <div class="card" style="background:{{ $isMismatch ? 'linear-gradient(135deg,#e53e3e 0%,#c53030 100%)' : 'linear-gradient(135deg,#38a169 0%,#2f855a 100%)' }};color:#fff;text-align:center;">
        <div style="font-size:24px;font-weight:bold;">{{ $formatMoney($variance) }}</div>
        <div style="font-size:13px;opacity:.9;margin-top:4px;">Variance</div>
    </div>
</div>

<div style="display:grid;grid-template-columns:minmax(460px,1fr) minmax(680px,1.6fr);gap:20px;align-items:start;">
    <div class="card">
        <h3 style="margin:0 0 14px;color:#2d3748;">Bank {{ $bankTransactions->count() === 1 ? 'Transaction' : 'Transactions' }}</h3>
        @forelse($bankTransactions as $item)
            @php
                $signedAmount = $item->credit_debit_indicator === 'DBIT' ? -(float)$item->amount : (float)$item->amount;
                $bankDate = \Carbon\Carbon::parse($item->value_date)->toDateString();
            @endphp
            @if($bankTransactions->count() > 1)
                <div style="font-size:12px;font-weight:700;color:#4a5568;margin:{{ $loop->first ? '0' : '20px' }} 0 8px;">Transaction {{ $loop->iteration }}</div>
            @endif
            <div style="overflow-x:auto;">
                <table class="mono-grid" style="width:100%;border-collapse:collapse;">
                    <thead><tr style="background:#f7fafc;border-bottom:2px solid #e2e8f0;"><th style="text-align:left;">Field</th><th style="text-align:left;">Value</th></tr></thead>
                    <tbody>
                        <tr><td>ID</td><td>{{ $item->id }}</td></tr>
                        <tr><td>Date</td><td>{{ $bankDate }}</td></tr>
                        <tr><td>Account</td><td><a href="{{ route('reconciliations.daily-totals.bank-day', ['date'=>$bankDate,'account'=>$item->account_number,'entry_id'=>$item->id]) }}" target="_blank" rel="noopener noreferrer">{{ $item->account_number }}</a></td></tr>
                        <tr><td>Direction</td><td>{{ $item->credit_debit_indicator }}</td></tr>
                        <tr><td>Amount</td><td style="font-weight:600;color:{{ $signedAmount < 0 ? '#e53e3e' : '#276749' }};white-space:nowrap;">{{ $formatMoney($signedAmount) }}</td></tr>
                        <tr><td>Memo Type</td><td>{{ $item->memo_type ?: '—' }}</td></tr>
                        <tr><td>Counterparty</td><td>{{ $item->counterparty ?: '—' }}</td></tr>
                        <tr><td>Settlement #</td><td>{{ $item->settlement_number ?: '—' }}</td></tr>
                        <tr><td>Wire Ref</td><td>{{ $item->wire_payment_reference ?: '—' }}</td></tr>
                        <tr><td>Description</td><td style="white-space:normal;overflow-wrap:anywhere;">{{ $item->additional_info ?: '—' }}</td></tr>
                    </tbody>
                </table>
            </div>
        @empty
            <div style="text-align:center;color:#718096;padding:30px 10px;">No bank transactions found.</div>
        @endforelse
    </div>

    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:14px;">
            <h3 style="margin:0;color:#2d3748;">VieFund EFT Items</h3>
            <div style="font-size:13px;color:#718096;">{{ number_format($eftItemCount) }} item(s)</div>
        </div>
        <form method="GET" action="{{ url()->current() }}" style="display:flex;align-items:end;gap:10px;margin-bottom:14px;flex-wrap:wrap;">
            @if($account !== '')<input type="hidden" name="account" value="{{ $account }}">@endif
            <input type="hidden" name="include_3000_sequences" value="{{ $include3000Sequences ? 1 : 0 }}">
            @if($sortByAmount)<input type="hidden" name="sort" value="amount"><input type="hidden" name="amount_direction" value="{{ $amountSortDirection }}">@endif
            <div style="flex:1;min-width:220px;">
                <label for="eft-amount-search" style="display:block;font-weight:600;color:#4a5568;font-size:12px;margin-bottom:5px;">Search amount</label>
                <input id="eft-amount-search" name="amount" value="{{ $amountSearchInput }}" inputmode="decimal" placeholder="e.g. 100.00" style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #cbd5e0;border-radius:5px;font-family:inherit;font-size:13px;line-height:1.4;color:#2d3748;">
            </div>
            <button type="submit" class="btn" style="padding:8px 13px;font-size:13px;line-height:1.4;">Search</button>
            @if($amountSearchInput !== '')
                <a href="{{ url()->current().'?'.http_build_query(request()->except(['amount', 'eft_page'])) }}" style="padding:8px 4px;font-size:13px;line-height:1.4;color:#2c5282;">Clear</a>
            @endif
            <div style="width:100%;font-size:11px;color:#718096;">Matches anywhere in the amount; payment/deposit and positive/negative signs are ignored.</div>
        </form>
        <div style="overflow-x:auto;">
            <table class="mono-grid" style="width:100%;border-collapse:collapse;">
                <thead><tr style="background:#f7fafc;border-bottom:2px solid #e2e8f0;white-space:nowrap;">
                    <th style="text-align:left;">Created</th><th style="text-align:left;">Type</th><th style="text-align:left;">Holder</th><th style="text-align:right;"><a href="{{ $amountSortUrl }}" style="color:inherit;text-decoration:none;">Amount {{ $amountSortIndicator }}</a></th>
                </tr></thead>
                <tbody>
                    @forelse($eftItems as $item)
                        @php($signedAmount = (int)$item->type_id === 10 ? (float)$item->amount : -(float)$item->amount)
                        <tr style="border-bottom:1px solid #e2e8f0;background:{{ $loop->even ? 'rgba(56,161,105,.07)' : 'transparent' }};">
                            <td style="white-space:nowrap;">{{ $item->created_at ? date('Y-m-d H:i:s', strtotime((string)$item->created_at)) : '—' }}</td>
                            <td style="white-space:nowrap;">{{ $item->type_name ?? 'Type '.$item->type_id }}</td>
                            <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{{ $item->holder_name }}">{{ $item->holder_name ?: '—' }}</td>
                            <td style="text-align:right;color:{{ $signedAmount < 0 ? '#e53e3e' : '#276749' }};white-space:nowrap;">{{ $formatMoney($signedAmount) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" style="text-align:center;color:#718096;padding:30px 10px;">No VieFund EFT items found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($eftItems->hasPages())
            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:14px;flex-wrap:wrap;">
                <div style="font-size:12px;color:#718096;">Showing {{ $eftItems->firstItem() }} to {{ $eftItems->lastItem() }} of {{ $eftItems->total() }} item(s)</div>
                {{ $eftItems->onEachSide(1)->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
