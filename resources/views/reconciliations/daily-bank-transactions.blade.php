@extends('layouts.app')

@section('title', 'Bank Daily Transactions')

@section('content')
<div style="display: flex; justify-content: space-between; align-items: center; margin: 20px 0; gap: 12px; flex-wrap: wrap;">
    <h2 style="margin: 0;">Bank Daily Transactions</h2>
    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
        <div style="display: flex; align-items: baseline; gap: 12px; padding: 12px 16px; border-radius: 12px; background: linear-gradient(135deg, #ebf8ff 0%, #bee3f8 100%); border: 1px solid #90cdf4; color: #2c5282; box-shadow: 0 4px 14px rgba(49, 130, 206, 0.10);">
            <div style="font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.08em;">Total</div>
            <div style="font-size: 22px; font-weight: 800;">{{ '$' . number_format((float) ($summary->net_total ?? 0), 2) }}</div>
            <div style="font-size: 14px; font-weight: 700; opacity: 0.85;">{{ number_format((int) ($summary->transaction_count ?? 0)) }} txn(s)</div>
        </div>
        <a href="{{ route('reconciliations.daily-totals', ['date_from' => $date, 'date_to' => $date]) }}" class="btn" style="text-decoration: none;">Back to Daily Totals</a>
    </div>
</div>

<div class="card" style="margin-bottom: 16px; padding: 22px 24px; background: linear-gradient(135deg, #ebf8ff 0%, #e8f4fd 100%); border: 1px solid #90cdf4; color: #2c5282; box-shadow: 0 4px 14px rgba(49, 130, 206, 0.10);">
    <div style="font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.08em; color: #2b6cb0; margin-bottom: 8px;">Criteria</div>
    <div style="font-size: 18px; font-weight: 700; line-height: 1.5;">
        Transaction date is {{ $date }}, all bank transactions are included.
    </div>
</div>

<div class="card">
    @if($transactions->count())
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; min-width: 980px;" class="mono-grid">
                <thead>
                    <tr style="background: #f7fafc; border-bottom: 2px solid #e2e8f0; white-space: nowrap;">
                        <th style="text-align: left; font-weight: 600; color: #2d3748;">ID</th>
                        <th style="text-align: left; font-weight: 600; color: #2d3748;">Dir</th>
                        <th style="text-align: right; font-weight: 600; color: #2d3748;">Amount</th>
                        <th style="text-align: left; font-weight: 600; color: #2d3748;">Memo Type</th>
                        <th style="text-align: left; font-weight: 600; color: #2d3748;">Counterparty</th>
                        <th style="text-align: left; font-weight: 600; color: #2d3748;">Settlement #</th>
                        <th style="text-align: left; font-weight: 600; color: #2d3748;">Wire Ref</th>
                        <th style="text-align: left; font-weight: 600; color: #2d3748;">Description</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($transactions as $txn)
                        @php $isCredit = $txn->credit_debit_indicator === 'CRDT'; @endphp
                        <tr style="border-bottom: 1px solid #e2e8f0; background: {{ $loop->even ? 'rgba(56, 161, 105, 0.07)' : 'transparent' }}">
                            <td style="color: #4a5568;">{{ $txn->id }}</td>
                            <td style="">
                                <span style="background: {{ $isCredit ? '#c6f6d5' : '#fed7d7' }}; color: {{ $isCredit ? '#22543d' : '#742a2a' }}; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">
                                    {{ $txn->credit_debit_indicator }}
                                </span>
                            </td>
                            <td style="text-align: right;font-weight: 600; color: {{ $isCredit ? '#2f855a' : '#c53030' }}; white-space: nowrap;">
                                {{ '$' . number_format((float) $txn->amount, 2) }}
                            </td>
                            <td style="color: #4a5568;">{{ $txn->memo_type ?: '—' }}</td>
                            <td style="color: #4a5568;">{{ $txn->counterparty ?: '—' }}</td>
                            <td style="color: #4a5568; white-space: nowrap;">{{ $txn->settlement_number ?: '—' }}</td>
                            <td style="color: #4a5568; white-space: nowrap;">{{ $txn->wire_payment_reference ?: '—' }}</td>
                            <td style="color: #4a5568;max-width: 380px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="{{ $txn->additional_info }}">{{ $txn->additional_info ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div style="margin-top: 16px; display: flex; justify-content: center;">
            {{ $transactions->links() }}
        </div>
    @else
        <p style="color: #718096; text-align: center; padding: 40px 0;">No bank transactions found for this date.</p>
    @endif
</div>
@endsection
