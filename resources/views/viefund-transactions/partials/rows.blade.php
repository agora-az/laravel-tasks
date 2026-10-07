@forelse($transactions as $transaction)
    @php $amount = isset($transaction->amount) ? (float) $transaction->amount : null; @endphp
    <tr data-transaction-row aria-selected="false" style="border-bottom:1px solid #e2e8f0;">
        @foreach($coreHeadings as $heading)
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
            @if($heading['key'] === 'matched_to_bank')
                @foreach($bankSummaryHeadings as $bankSummaryHeading)
                    <td class="all-transactions-bank-column all-transactions-bank-cell all-transactions-reconciliation-column {{ $loop->first ? 'all-transactions-bank-group-start' : '' }} {{ $loop->last ? 'all-transactions-reconciliation-end' : '' }}" data-match-status="loading" data-trust-id="{{ $transaction->trust_transaction_id ?: '' }}" data-cash-id="{{ $transaction->cash_transaction_id ?: '' }}" data-record-field="{{ $bankSummaryHeading['field'] }}" style="padding:12px;font-family:monospace;max-width:{{ $bankSummaryHeading['width'] ?? 220 }}px;overflow:hidden;text-align:{{ $bankSummaryHeading['field'] === 'reconciliation_variance' ? 'right' : 'left' }};"><span>—</span></td>
                @endforeach
            @endif
        @endforeach
        @foreach($eftHeadings as $heading)
            <td class="all-transactions-eft-column all-transactions-eft-cell {{ $loop->first ? 'all-transactions-eft-group-start' : '' }}" data-trust-id="{{ $transaction->trust_transaction_id ?: '' }}" data-record-field="{{ $heading['field'] }}" style="padding:12px;font-family:monospace;max-width:{{ $heading['width'] ?? 220 }}px;overflow:hidden;text-align:{{ in_array($heading['field'], ['amount','file_total'], true) ? 'right' : 'left' }};"><span>—</span></td>
        @endforeach
        @foreach($fspHeadings as $heading)
            <td class="all-transactions-fsp-column all-transactions-fsp-cell {{ $loop->first ? 'all-transactions-fsp-group-start' : '' }}" data-cash-id="{{ $transaction->cash_transaction_id ?: '' }}" data-record-field="{{ $heading['field'] }}" style="padding:12px;font-family:monospace;max-width:{{ $heading['width'] ?? 220 }}px;overflow:hidden;text-align:{{ in_array($heading['field'], ['items_total','gross_amount','net_amount','settlement_amount'], true) ? 'right' : 'left' }};"><span>—</span></td>
        @endforeach
        @foreach($bankHeadings as $heading)
            <td class="all-transactions-bank-column all-transactions-bank-cell {{ $loop->first ? 'all-transactions-bank-group-start' : '' }}" data-trust-id="{{ $transaction->trust_transaction_id ?: '' }}" data-cash-id="{{ $transaction->cash_transaction_id ?: '' }}" data-record-field="{{ $heading['field'] }}" style="padding:12px;font-family:monospace;max-width:{{ $heading['width'] ?? 220 }}px;overflow:hidden;text-align:{{ in_array($heading['field'], ['amount','transaction_total'], true) ? 'right' : 'left' }};"><span>—</span></td>
        @endforeach
    </tr>
@empty
    <tr><td colspan="{{ count($coreHeadings) + count($eftHeadings) + count($bankSummaryHeadings) + count($bankHeadings) + count($fspHeadings) }}" style="padding:48px;text-align:center;color:#718096;">No VieFund cash-ledger transactions were found.</td></tr>
@endforelse
