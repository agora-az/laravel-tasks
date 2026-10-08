@php
    $matchHeadings = array_values(array_filter($coreHeadings, fn($heading) => $heading['key'] === 'matched_to_bank'));
    $viefundHeadings = array_values(array_filter($coreHeadings, fn($heading) => $heading['key'] !== 'matched_to_bank'));
@endphp
@forelse($transactions as $transaction)
    @php $amount = isset($transaction->amount) ? (float) $transaction->amount : null; @endphp
    <tr data-transaction-row aria-selected="false" style="border-bottom:1px solid #e2e8f0;">
        @foreach($columnGroupOrder as $columnGroup)
            @if($columnGroup === 'match')
                @foreach($matchHeadings as $heading)
                    @include('viefund-transactions.partials.core-cell')
                @endforeach
                @foreach($bankSummaryHeadings as $bankSummaryHeading)
                    <td class="all-transactions-bank-column all-transactions-bank-cell all-transactions-reconciliation-column {{ empty($matchHeadings) && $loop->first ? 'all-transactions-reconciliation-start' : '' }} {{ $loop->last ? 'all-transactions-reconciliation-end' : '' }}" data-match-status="loading" data-trust-id="{{ $transaction->trust_transaction_id ?: '' }}" data-cash-id="{{ $transaction->cash_transaction_id ?: '' }}" data-record-field="{{ $bankSummaryHeading['field'] }}" style="padding:12px;font-family:monospace;max-width:{{ $bankSummaryHeading['width'] ?? 220 }}px;overflow:hidden;text-align:{{ $bankSummaryHeading['field'] === 'reconciliation_variance' ? 'right' : 'left' }};"><span>—</span></td>
                @endforeach
            @elseif($columnGroup === 'viefund')
                @foreach($viefundHeadings as $heading)
                    @include('viefund-transactions.partials.core-cell')
                @endforeach
            @elseif($columnGroup === 'eft')
                @foreach($eftHeadings as $heading)
                    <td class="all-transactions-eft-column all-transactions-eft-cell {{ $loop->first ? 'all-transactions-eft-group-start' : '' }}" data-trust-id="{{ $transaction->trust_transaction_id ?: '' }}" data-record-field="{{ $heading['field'] }}" style="padding:12px;font-family:monospace;max-width:{{ $heading['width'] ?? 220 }}px;overflow:hidden;text-align:{{ in_array($heading['field'], ['amount','file_total'], true) ? 'right' : 'left' }};"><span>—</span></td>
                @endforeach
            @elseif($columnGroup === 'fsp')
                @foreach($fspHeadings as $heading)
                    <td class="all-transactions-fsp-column all-transactions-fsp-cell {{ $loop->first ? 'all-transactions-fsp-group-start' : '' }}" data-cash-id="{{ $transaction->cash_transaction_id ?: '' }}" data-record-field="{{ $heading['field'] }}" style="padding:12px;font-family:monospace;max-width:{{ $heading['width'] ?? 220 }}px;overflow:hidden;text-align:{{ in_array($heading['field'], ['items_total','gross_amount','net_amount','settlement_amount'], true) ? 'right' : 'left' }};"><span>—</span></td>
                @endforeach
            @elseif($columnGroup === 'bank')
                @foreach($bankHeadings as $heading)
                    <td class="all-transactions-bank-column all-transactions-bank-cell {{ $loop->first ? 'all-transactions-bank-group-start' : '' }}" data-trust-id="{{ $transaction->trust_transaction_id ?: '' }}" data-cash-id="{{ $transaction->cash_transaction_id ?: '' }}" data-record-field="{{ $heading['field'] }}" style="padding:12px;font-family:monospace;max-width:{{ $heading['width'] ?? 220 }}px;overflow:hidden;text-align:{{ in_array($heading['field'], ['amount','transaction_total'], true) ? 'right' : 'left' }};"><span>—</span></td>
                @endforeach
            @endif
        @endforeach
    </tr>
@empty
    <tr><td colspan="{{ count($coreHeadings) + count($eftHeadings) + count($bankSummaryHeadings) + count($bankHeadings) + count($fspHeadings) }}" style="padding:48px;text-align:center;color:#718096;">No VieFund cash-ledger transactions were found.</td></tr>
@endforelse
