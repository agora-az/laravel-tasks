@extends('layouts.app')

@section('title', 'Variance Comparison - ' . $date)

@section('content')
<div style="display: flex; justify-content: space-between; align-items: center; margin: 20px 0; gap: 12px; flex-wrap: wrap;">
    <h2 style="margin: 0;">Variance Drilldown: {{ $date }}</h2>
    <a href="{{ route('reconciliations.daily-totals', ['date_from' => $date, 'date_to' => $date]) }}" class="btn" style="text-decoration: none;">Back to Daily Totals</a>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; align-items: start;">

    {{-- ── Bank pane ───────────────────────────────────────────────── --}}
    <div class="card" style="min-width: 0;">
        <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 12px; margin-bottom: 14px; flex-wrap: wrap;">
            <h3 style="margin: 0; font-size: 15px; font-weight: 700; color: #2d3748;">Bank Transactions</h3>
            <div style="display: flex; align-items: baseline; gap: 10px; padding: 6px 12px; border-radius: 8px; background: linear-gradient(135deg, #ebf8ff 0%, #bee3f8 100%); border: 1px solid #90cdf4; color: #2c5282;">
                <span style="font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em;">Total</span>
                <span style="font-size: 16px; font-weight: 800;">{{ '$' . number_format((float) ($bankSummary->net_total ?? 0), 2) }}</span>
                <span style="font-size: 11px; font-weight: 700; opacity: 0.85;">{{ number_format((int) ($bankSummary->transaction_count ?? 0)) }} txn(s)</span>
            </div>
        </div>
        @if($bankTransactions->count())
            <div style="overflow-x: auto; max-height: 70vh;">
                <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
                    <thead>
                        <tr style="background: #f7fafc; border-bottom: 2px solid #e2e8f0; white-space: nowrap; position: sticky; top: 0;">
                            <th style="padding: 8px 10px; text-align: left; font-weight: 600; color: #2d3748;">ID</th>
                            <th style="padding: 8px 10px; text-align: left; font-weight: 600; color: #2d3748;">Dir</th>
                            <th style="padding: 8px 10px; text-align: right; font-weight: 600; color: #2d3748;">Amount</th>
                            <th style="padding: 8px 10px; text-align: left; font-weight: 600; color: #2d3748;">Memo Type</th>
                            <th style="padding: 8px 10px; text-align: left; font-weight: 600; color: #2d3748;">Counterparty</th>
                            <th style="padding: 8px 10px; text-align: left; font-weight: 600; color: #2d3748;">Settlement #</th>
                            <th style="padding: 8px 10px; text-align: left; font-weight: 600; color: #2d3748;">Wire Ref</th>
                            <th style="padding: 8px 10px; text-align: left; font-weight: 600; color: #2d3748;">Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($bankTransactions as $txn)
                            @php $isCredit = $txn->credit_debit_indicator === 'CRDT'; @endphp
                            <tr style="border-bottom: 1px solid #e2e8f0;">
                                <td style="padding: 8px 10px; font-family: monospace; color: #2d3748;">{{ $txn->id }}</td>
                                <td style="padding: 8px 10px;">
                                    <span style="background: {{ $isCredit ? '#c6f6d5' : '#fed7d7' }}; color: {{ $isCredit ? '#22543d' : '#742a2a' }}; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: 600;">
                                        {{ $txn->credit_debit_indicator }}
                                    </span>
                                </td>
                                <td style="padding: 8px 10px; text-align: right; font-family: monospace; font-weight: 600; color: {{ $isCredit ? '#2f855a' : '#c53030' }}; white-space: nowrap;">
                                    {{ '$' . number_format((float) $txn->amount, 2) }}
                                </td>
                                <td style="padding: 8px 10px; color: #2d3748;">{{ $txn->memo_type ?: '—' }}</td>
                                <td style="padding: 8px 10px; color: #2d3748; max-width: 140px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="{{ $txn->counterparty }}">{{ $txn->counterparty ?: '—' }}</td>
                                <td style="padding: 8px 10px; font-family: monospace; color: #2d3748; white-space: nowrap;">{{ $txn->settlement_number ?: '—' }}</td>
                                <td style="padding: 8px 10px; font-family: monospace; color: #2d3748; white-space: nowrap;">{{ $txn->wire_payment_reference ?: '—' }}</td>
                                <td style="padding: 8px 10px; color: #4a5568; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="{{ $txn->additional_info }}">{{ $txn->additional_info ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div style="margin-top: 12px; display: flex; justify-content: center;">
                {{ $bankTransactions->appends(['viefund_page' => $viefundTransactions->currentPage()])->links() }}
            </div>
        @else
            <p style="color: #718096; text-align: center; padding: 30px 0;">No bank transactions for this date.</p>
        @endif
    </div>

    {{-- ── VieFund pane ────────────────────────────────────────────── --}}
    <div class="card" style="min-width: 0;">
        <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 12px; margin-bottom: 14px; flex-wrap: wrap;">
            <h3 style="margin: 0; font-size: 15px; font-weight: 700; color: #2d3748;">VieFund Transactions</h3>
            <div style="display: flex; align-items: baseline; gap: 10px; padding: 6px 12px; border-radius: 8px; background: linear-gradient(135deg, #e6fffa 0%, #c6f6d5 100%); border: 1px solid #9ae6b4; color: #22543d;">
                <span style="font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em;">Total</span>
                <span style="font-size: 16px; font-weight: 800;">{{ '$' . number_format((float) ($viefundSummary->net_total ?? 0), 2) }}</span>
                <span style="font-size: 11px; font-weight: 700; opacity: 0.85;">{{ number_format((int) ($viefundSummary->transaction_count ?? 0)) }} txn(s)</span>
            </div>
        </div>
        @if($viefundTransactions->count())
            <div style="overflow-x: auto; max-height: 70vh;">
                <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
                    <thead>
                        <tr style="background: #f7fafc; border-bottom: 2px solid #e2e8f0; white-space: nowrap; position: sticky; top: 0;">
                            <th style="padding: 8px 10px; text-align: left; font-weight: 600; color: #2d3748;">Txn ID</th>
                            <th style="padding: 8px 10px; text-align: left; font-weight: 600; color: #2d3748;">Customer</th>
                            <th style="padding: 8px 10px; text-align: left; font-weight: 600; color: #2d3748;">Txn Type</th>
                            <th style="padding: 8px 10px; text-align: left; font-weight: 600; color: #2d3748;">Order Status</th>
                            <th style="padding: 8px 10px; text-align: left; font-weight: 600; color: #2d3748;">Notes</th>
                            <th style="padding: 8px 10px; text-align: right; font-weight: 600; color: #2d3748;">Amount</th>
                            <th style="padding: 8px 10px; text-align: left; font-weight: 600; color: #2d3748;">Created Date</th>
                            <th style="padding: 8px 10px; text-align: left; font-weight: 600; color: #2d3748;">Trade Date</th>
                            <th style="padding: 8px 10px; text-align: left; font-weight: 600; color: #2d3748;">Processing Date</th>
                            <th style="padding: 8px 10px; text-align: left; font-weight: 600; color: #2d3748;">Settlement Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($viefundTransactions as $txn)
                            <tr style="border-bottom: 1px solid #e2e8f0;">
                                <td style="padding: 8px 10px; font-family: monospace; color: #2d3748;">{{ $txn->trx_id }}</td>
                                <td style="padding: 8px 10px; color: #2d3748; max-width: 130px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="{{ $txn->client_name }}">{{ $txn->client_name ?: '—' }}</td>
                                <td style="padding: 8px 10px; color: #2d3748; white-space: nowrap;">{{ $txn->trx_type ?: '—' }}</td>
                                <td style="padding: 8px 10px; color: #2d3748;">{{ $txn->order_status ?: '—' }}</td>
                                <td style="padding: 8px 10px; color: #4a5568; max-width: 160px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="{{ $txn->notes }}">{{ $txn->notes ?: '—' }}</td>
                                <td style="padding: 8px 10px; text-align: right; font-family: monospace; font-weight: 600; color: {{ (float) $txn->amount < 0 ? '#c53030' : '#2f855a' }}; white-space: nowrap;">
                                    {{ '$' . number_format((float) $txn->amount, 2) }}
                                </td>
                                <td style="padding: 8px 10px; font-family: monospace; color: #2d3748; white-space: nowrap;">{{ $txn->created_date ?: '—' }}</td>
                                <td style="padding: 8px 10px; font-family: monospace; color: #2d3748; white-space: nowrap;">{{ $txn->trade_date ?: '—' }}</td>
                                <td style="padding: 8px 10px; font-family: monospace; color: #2d3748; white-space: nowrap;">{{ $txn->processing_date ?: '—' }}</td>
                                <td style="padding: 8px 10px; font-family: monospace; color: #2d3748; white-space: nowrap;">{{ $txn->settlement_date ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div style="margin-top: 12px; display: flex; justify-content: center;">
                {{ $viefundTransactions->appends(['bank_page' => $bankTransactions->currentPage()])->links() }}
            </div>
        @else
            <p style="color: #718096; text-align: center; padding: 30px 0;">No VieFund transactions for this date.</p>
        @endif
    </div>

</div>
@endsection
