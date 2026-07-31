@extends('layouts.app')

@section('title', 'VieFund Daily Transactions')

@section('content')
@php
    $formattedDate = \Carbon\Carbon::parse($date)->format('F j, Y');
@endphp
<div style="display: flex; justify-content: space-between; align-items: center; margin: 20px 0; gap: 12px; flex-wrap: wrap;">
    <h2 style="margin: 0;">VieFund Daily Transactions</h2>
    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
        <div style="display: flex; align-items: baseline; gap: 12px; padding: 8px 14px; border-radius: 10px; background: linear-gradient(135deg, #e6fffa 0%, #c6f6d5 100%); border: 1px solid #9ae6b4; color: #22543d;">
            <div style="font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em;">Total</div>
            <div style="font-size: 20px; font-weight: 800;">{{ '$' . number_format((float) ($summary->net_total ?? 0), 2) }}</div>
            <div style="font-size: 13px; font-weight: 700; opacity: 0.85;">{{ number_format((int) ($summary->transaction_count ?? 0)) }} txn(s)</div>
        </div>
        <a href="{{ route('reconciliations.daily-totals.viefund-day.export', ['date' => $date, 'variant' => request('variant'), 'hide_zero' => ($hideZero ?? false) ? 1 : null]) }}" class="btn" style="text-decoration: none; background: #2f855a;">Export CSV</a>
        <a href="{{ route('reconciliations.daily-totals', ['date_from' => $date, 'date_to' => $date]) }}" class="btn" style="text-decoration: none;">Back to Daily Totals</a>
    </div>
</div>

<div class="card" style="margin-bottom: 16px; padding: 22px 24px; background: linear-gradient(135deg, #edfdf9 0%, #f0fff4 100%); border: 1px solid #9ae6b4; color: #22543d; box-shadow: 0 4px 14px rgba(72, 187, 120, 0.12);">
    <div style="font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.08em; color: #2f855a; margin-bottom: 8px;">Criteria</div>
    <ul style="margin: 0; padding-left: 20px; font-size: 15px; font-weight: 400; line-height: 1.55; color: #22543d;">
        <li><strong>{{ $basisLabel ?? 'Settlement date' }}</strong> is {{ $formattedDate }}</li>
        <li>Purchase or redemption fund transactions with status: <strong>{{ $fundCriteria ?? 'Confirmed' }}</strong></li>
        <li>Trust transactions: <strong>{{ $trustCriteria ?? 'excluded' }}</strong></li>
        <li style="list-style: none; margin-left: -20px; margin-top: 6px;">
            <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-weight: 400;">
                <input type="checkbox" id="hide-zero-toggle" {{ ($hideZero ?? false) ? 'checked' : '' }}>
                <strong>Exclude $0 transactions</strong>
            </label>
        </li>
    </ul>
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
                        <th style="text-align: left; font-weight: 600; color: #2d3748;">Source</th>
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
                            <td style="color: #4a5568;">
                                @php $rowSource = data_get($txn, 'row_source', 'fund'); @endphp
                                <span style="display:inline-block; padding:1px 7px; border-radius:10px; font-size:11px; font-weight:700; {{ $rowSource === 'trust' ? 'background:#e9d8fd; color:#553c9a;' : 'background:#c6f6d5; color:#22543d;' }}">{{ ucfirst($rowSource) }}</span>
                            </td>
                            <td style="color: #2d3748;">{{ $txn->client_name ?: '—' }}</td>
                            <td style="color: #4a5568;white-space: nowrap;" title="{{ $txn->trx_type }}">{{ $txn->trx_type ?: '—' }}</td>
                            <td style="color: #4a5568;">{{ data_get($txn, 'status', data_get($txn, 'order_status', '—')) }}</td>
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

<script>
document.addEventListener('DOMContentLoaded', function () {
    const toggle = document.getElementById('hide-zero-toggle');
    if (!toggle) return;
    toggle.addEventListener('change', function () {
        const u = new URL(window.location.href);
        if (this.checked) {
            u.searchParams.set('hide_zero', '1');
        } else {
            u.searchParams.delete('hide_zero');
        }
        u.searchParams.delete('viefund_page'); // back to page 1
        window.location.assign(u.toString());
    });
});
</script>
@endsection
