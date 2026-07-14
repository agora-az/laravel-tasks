@extends('layouts.app')

@section('title', 'VieFund Daily Transactions')

@section('content')
<div style="display: flex; justify-content: space-between; align-items: center; margin: 20px 0; gap: 12px; flex-wrap: wrap;">
    <h2 style="margin: 0;">VieFund Daily Transactions</h2>
    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
        <div style="display: flex; align-items: baseline; gap: 12px; padding: 8px 14px; border-radius: 10px; background: linear-gradient(135deg, #e6fffa 0%, #c6f6d5 100%); border: 1px solid #9ae6b4; color: #22543d;">
            <div style="font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em;">Total</div>
            <div style="font-size: 20px; font-weight: 800;">{{ '$' . number_format((float) ($summary->net_total ?? 0), 2) }}</div>
            <div style="font-size: 13px; font-weight: 700; opacity: 0.85;">{{ number_format((int) ($summary->transaction_count ?? 0)) }} txn(s)</div>
        </div>
        <a href="{{ route('reconciliations.daily-totals', ['date_from' => $date, 'date_to' => $date]) }}" class="btn" style="text-decoration: none;">Back to Daily Totals</a>
    </div>
</div>

<div class="card" style="margin-bottom: 16px; padding: 22px 24px; background: linear-gradient(135deg, #edfdf9 0%, #f0fff4 100%); border: 1px solid #9ae6b4; color: #22543d; box-shadow: 0 4px 14px rgba(72, 187, 120, 0.12);">
    <div style="font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.08em; color: #2f855a; margin-bottom: 8px;">Criteria</div>
    <div style="font-size: 18px; font-weight: 700; line-height: 1.5;">
        Settlement date is {{ $date }}, transaction types are either purchase or redemption and transaction status is confirmed.
    </div>
</div>

<div class="card">
    @if($transactions->count())
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
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; min-width: 1280px;" class="mono-grid">
                <thead>
                    <tr style="background: #f7fafc; border-bottom: 2px solid #e2e8f0; white-space: nowrap;">
                        <th style="text-align: left; font-weight: 600; color: #2d3748;">Txn ID</th>
                        <th style="text-align: left; font-weight: 600; color: #2d3748;">Customer Name</th>
                        <th style="text-align: left; font-weight: 600; color: #2d3748;">Txn Type</th>
                        <th style="text-align: left; font-weight: 600; color: #2d3748;">Order Status</th>
                        <th style="text-align: left; font-weight: 600; color: #2d3748;">Notes</th>
                        <th style="text-align: right; font-weight: 600; color: #2d3748;">Amount</th>
                        <th style="text-align: left; font-weight: 600; color: #2d3748;">Created Date</th>
                        <th style="text-align: left; font-weight: 600; color: #2d3748;">Trade Date</th>
                        <th style="text-align: left; font-weight: 600; color: #2d3748;">Processing Date</th>
                        <th style="text-align: left; font-weight: 600; color: #2d3748;">Settlement Date</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($transactions as $txn)
                        @php
                            [$createdDate, $createdTime] = $toTwoLineDate($txn->created_date);
                            [$tradeDate, $tradeTime] = $toTwoLineDate($txn->trade_date);
                            [$processingDate, $processingTime] = $toTwoLineDate($txn->processing_date);
                            [$settlementDate, $settlementTime] = $toTwoLineDate($txn->settlement_date);
                        @endphp
                        <tr style="border-bottom: 1px solid #e2e8f0; background: {{ $loop->even ? 'rgba(56, 161, 105, 0.07)' : 'transparent' }}">
                            <td style="color: #4a5568;">{{ $txn->trx_id }}</td>
                            <td style="color: #2d3748;">{{ $txn->client_name ?: '—' }}</td>
                            <td style="color: #4a5568;white-space: nowrap;" title="{{ $txn->trx_type }}">{{ $txn->trx_type ?: '—' }}</td>
                            <td style="color: #4a5568;">{{ $txn->order_status ?: '—' }}</td>
                            <td style="color: #4a5568; max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="{{ $txn->notes }}">{{ $txn->notes ?: '—' }}</td>
                            <td style="text-align: right;font-weight: 500; color: {{ (float) $txn->amount < 0 ? '#e53e3e' : '#276749' }}; white-space: nowrap;">
                                {{ '$' . number_format((float) $txn->amount, 2) }}
                            </td>
                            <td style="color: #4a5568; white-space: nowrap; line-height: 1.2;">
                                <span style="display: block;">{{ $createdDate }}</span>
                                <span style="display: block; opacity: 0.9;">{{ $createdTime }}</span>
                            </td>
                            <td style="color: #4a5568; white-space: nowrap; line-height: 1.2;">
                                <span style="display: block;">{{ $tradeDate }}</span>
                                <span style="display: block; opacity: 0.9;">{{ $tradeTime }}</span>
                            </td>
                            <td style="color: #4a5568; white-space: nowrap; line-height: 1.2;">
                                <span style="display: block;">{{ $processingDate }}</span>
                                <span style="display: block; opacity: 0.9;">{{ $processingTime }}</span>
                            </td>
                            <td style="color: #4a5568; white-space: nowrap; line-height: 1.2;">
                                <span style="display: block;">{{ $settlementDate }}</span>
                                <span style="display: block; opacity: 0.9;">{{ $settlementTime }}</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div style="margin-top: 16px; display: flex; justify-content: center;">
            {{ $transactions->links() }}
        </div>
    @else
        <p style="color: #718096; text-align: center; padding: 40px 0;">No VieFund transactions found for this date.</p>
    @endif
</div>
@endsection
