@switch($heading['key'])
    @case('matched_to_bank')
        @php $cachedMatchStatus = $transaction->cached_match_status ?? null; @endphp
        <td class="all-transactions-match-status-cell all-transactions-reconciliation-column all-transactions-reconciliation-start" data-match-status="{{ strtolower($cachedMatchStatus ?? 'loading') }}" data-trust-id="{{ $transaction->trust_transaction_id ?? '' }}" data-cash-id="{{ $transaction->cash_transaction_id ?? '' }}" style="padding:12px;font-family:monospace;font-weight:700;white-space:nowrap;color:#718096;">{{ $cachedMatchStatus ?? 'Loading…' }}</td>
        @break
    @case('cash_transaction_id')
        <td style="padding:12px;font-family:monospace;white-space:nowrap;">{{ $transaction->transaction_id ?? '–' }}</td>
        @break
    @case('fund_transaction_id')
        <td style="padding:12px;font-family:monospace;white-space:nowrap;">{{ !empty($transaction->fund_transaction_id) ? 'F-'.$transaction->fund_transaction_id : '–' }}</td>
        @break
    @case('trust_transaction_id')
        <td style="padding:12px;font-family:monospace;white-space:nowrap;">{{ !empty($transaction->trust_transaction_id) ? 'T-'.$transaction->trust_transaction_id : '–' }}</td>
        @break
    @case('notes')
        <td style="padding:12px;font-family:monospace;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{{ $transaction->notes ?? '' }}">{{ $transaction->notes ?? '–' }}</td>
        @break
    @case('created_date')
    @case('trade_date')
    @case('processing_date')
    @case('settlement_date')
        @php
            $dateValue = $transaction->{$heading['key']} ?? null;
            $dateTimestamp = $dateValue ? strtotime($dateValue) : false;
            $hasActualTime = $dateTimestamp !== false && date('H:i:s', $dateTimestamp) !== '00:00:00';
        @endphp
        <td data-date-time="{{ $dateTimestamp !== false ? date('Y-m-d H:i:s', $dateTimestamp) : '' }}" style="padding:12px;font-family:monospace;white-space:nowrap;">{{ $dateTimestamp === false ? '–' : date($hasActualTime ? 'm/d/Y H:i:s' : 'm/d/Y', $dateTimestamp) }}</td>
        @break
    @case('currency_code')
        <td style="padding:12px;font-family:monospace;white-space:nowrap;">{{ $currencyOptions[$transaction->currency_code ?? ''] ?? ($transaction->currency_code ?? '–') }}</td>
        @break
    @case('amount')
        <td style="padding:12px;text-align:right;color:{{ $amount === null || $amount == 0 ? '#718096' : ($amount < 0 ? '#c53030' : '#276749') }};font-family:monospace;font-weight:600;">{{ $amount === null ? '–' : ($amount < 0 ? '($'.number_format(abs($amount), 2).')' : '$'.number_format($amount, 2)) }}</td>
        @break
    @default
        @php $field = $heading['field']; $value = $field ? ($transaction->{$field} ?? null) : null; @endphp
        <td style="padding:12px;font-family:monospace;{{ in_array($heading['key'], ['ledger_relationship','plan_account_id'], true) ? 'white-space:nowrap;' : '' }}">{{ $value !== null && $value !== '' ? $value : '–' }}</td>
@endswitch
