@php
    $formatMoney = static function ($amount): string {
        $value = (float) $amount;
        $formatted = '$' . number_format(abs($value), 2);
        return $value < 0 ? '(' . $formatted . ')' : $formatted;
    };
    $sortUrl = fn(string $field) => route('reconciliations.bank-fsp.source', array_merge(
        ['source' => $source],
        request()->except(['sort', 'sort_dir', 'page', '_results']),
        ['sort' => $field, 'sort_dir' => $sortField === $field && $sortDir === 'asc' ? 'desc' : 'asc']
    ));
    $sortArrow = fn(string $field) => $sortField === $field ? ($sortDir === 'asc' ? ' ↑' : ' ↓') : ' ⇅';
@endphp

<div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:24px;">
    <div class="card" style="background:linear-gradient(135deg,#345262 0%,#5a7585 100%);color:#fff;text-align:center;">
        <div style="font-size:28px;font-weight:bold;">{{ number_format($summary['days']) }}</div>
        <div style="font-size:13px;opacity:.9;margin-top:4px;">Bank Days</div>
    </div>
    <div class="card" style="background:linear-gradient(135deg,#4a5568 0%,#2d3748 100%);color:#fff;text-align:center;">
        <div style="font-size:28px;font-weight:bold;">{{ number_format($summary['accounts']) }}</div>
        <div style="font-size:13px;opacity:.9;margin-top:4px;">Bank Accounts</div>
    </div>
    <div class="card" style="background:linear-gradient(135deg,#38a169 0%,#2f855a 100%);color:#fff;text-align:center;">
        <div style="font-size:24px;font-weight:bold;">{{ $formatMoney($summary['bank_net_total']) }}</div>
        <div style="font-size:13px;opacity:.9;margin-top:4px;">Bank FundServ Net</div>
    </div>
    <div class="card" style="background:linear-gradient(135deg,#3182ce 0%,#2c5aa0 100%);color:#fff;text-align:center;">
        <div style="font-size:24px;font-weight:bold;">{{ $formatMoney($summary['fsp_net_total']) }}</div>
        <div style="font-size:13px;opacity:.9;margin-top:4px;">{{ $sourceLabel }} FSP Net</div>
    </div>
</div>

<div class="card">
    <div style="margin-bottom:12px;display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
        <div style="color:#4a5568;font-size:13px;">{{ number_format($rows->total()) }} date/currency row(s).</div>
        <details style="max-width:760px;text-align:right;">
            <summary style="cursor:pointer;color:#2c5282;font-size:13px;font-weight:600;text-decoration:underline;">Matching Criteria</summary>
            <div style="margin-top:8px;color:#4a5568;font-size:13px;line-height:1.5;text-align:left;">
                <ul style="margin:0;padding-left:20px;">
                    <li>{{ $sourceLabel }} FSP items are grouped by settlement date and currency.</li>
                    <li>Eligible bank records use parser v2 entries whose counterparty contains “FundServ”.</li>
                    <li>Each FSP date/currency total is matched to the closest signed eligible bank transaction on that date.</li>
                    <li>FSP settlement amounts are signed by side: SELL is positive and BUY is negative.</li>
                    <li>Variance is the matched Bank FundServ Net less {{ $sourceLabel }} FSP Net.</li>
                </ul>
            </div>
        </details>
    </div>
    @if($rows->count())
        <div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;" class="mono-grid">
            <thead><tr style="background:#f7fafc;border-bottom:2px solid #e2e8f0;white-space:nowrap;">
                @foreach(['total_date'=>['Date',''],'account_number'=>['Account',''],'currency'=>['Currency',''],'bank_transaction_count'=>['Bank FundServ','Txn Count'],'bank_net_total'=>['Bank FundServ','Net'],'fsp_item_count'=>[$sourceLabel.' FSP','Items'],'fsp_net_total'=>[$sourceLabel.' FSP','Net'],'variance'=>['Variance','']] as $field => [$line1,$line2])
                    <th style="text-align:{{ in_array($field, ['total_date','account_number','currency'], true) ? 'left' : 'right' }};font-weight:600;color:#2d3748;line-height:1.25;"><a href="{{ $sortUrl($field) }}" style="color:inherit;text-decoration:none;"><span style="display:block;">{{ $line1 }}</span>@if($line2)<span style="display:block;">{{ $line2 }}{{ $sortArrow($field) }}</span>@else{{ $sortArrow($field) }}@endif</a></th>
                @endforeach
            </tr></thead>
            <tbody>
                @foreach($rows as $row)
                    @php($isMismatch = abs($row['variance']) >= .01)
                    <tr style="border-bottom:1px solid #e2e8f0;background:{{ $isMismatch ? '#fff5f5' : ($loop->even ? 'rgba(56,161,105,.07)' : 'transparent') }};">
                        <td style="color:#4a5568;white-space:nowrap;">{{ $row['total_date'] }}</td>
                        <td style="color:#4a5568;white-space:nowrap;">@if($row['account_number'])<a href="{{ route('reconciliations.daily-totals.bank-day', ['date'=>$row['total_date'],'account'=>$row['account_number']]) }}" target="_blank" rel="noopener noreferrer" style="color:inherit;text-decoration:underline;">{{ $row['account_number'] }}</a>@else—@endif</td>
                        <td style="color:#4a5568;white-space:nowrap;">{{ $row['currency'] ?: '—' }}</td>
                        <td style="text-align:right;color:#4a5568;">@if($row['bank_entry_id'])<a href="{{ route('reconciliations.daily-totals.bank-day', ['date'=>$row['total_date'],'account'=>$row['account_number'],'entry_id'=>$row['bank_entry_id']]) }}" target="_blank" rel="noopener noreferrer" style="color:inherit;text-decoration:underline;">{{ number_format($row['bank_transaction_count']) }}</a>@else{{ number_format($row['bank_transaction_count']) }}@endif</td>
                        <td style="text-align:right;font-weight:500;color:{{ $row['bank_net_total'] < 0 ? '#e53e3e' : '#276749' }};white-space:nowrap;">{{ $formatMoney($row['bank_net_total']) }}</td>
                        <td style="text-align:right;color:#4a5568;"><a href="{{ route('settlement-instructions.index', ['source_type'=>$source === '7960' ? 'ltm' : 'fundserv_agra','date_from'=>$row['total_date'],'date_to'=>$row['total_date'],'currency'=>$row['currency']]) }}" target="_blank" rel="noopener noreferrer" style="color:inherit;text-decoration:underline;">{{ number_format($row['fsp_item_count']) }}</a></td>
                        <td style="text-align:right;font-weight:500;color:{{ $row['fsp_net_total'] < 0 ? '#e53e3e' : '#276749' }};white-space:nowrap;">{{ $formatMoney($row['fsp_net_total']) }}</td>
                        <td style="text-align:right;font-weight:600;color:{{ $isMismatch ? '#e53e3e' : '#276749' }};white-space:nowrap;"><a href="{{ route('reconciliations.bank-fsp.compare', ['source'=>$source,'date'=>$row['total_date'],'currency'=>$row['currency'],'entry_id'=>$row['bank_entry_id']]) }}" target="_blank" rel="noopener noreferrer" style="color:inherit;text-decoration:underline;">{{ $formatMoney($row['variance']) }}</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table></div>
        @if($rows->hasPages())
            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:14px;flex-wrap:wrap;">
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                    <div style="font-size:12px;color:#718096;">Showing {{ $rows->firstItem() }} to {{ $rows->lastItem() }} of {{ $rows->total() }} row(s)</div>
                    <form method="GET" action="{{ route('reconciliations.bank-fsp.source', ['source'=>$source]) }}" style="display:flex;align-items:center;gap:6px;margin:0;">
                        @foreach(request()->except(['per_page','page','_results']) as $key => $value) @if(!is_array($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif @endforeach
                        <label for="fsp_per_page" style="font-size:12px;color:#718096;">Rows</label>
                        <select id="fsp_per_page" name="per_page" data-async-results-select style="padding:5px 8px;border:1px solid #cbd5e0;border-radius:4px;font-size:12px;">@foreach([25,50,100,250] as $option)<option value="{{ $option }}" @selected($perPage === $option)>{{ $option }}</option>@endforeach</select>
                    </form>
                </div>
                {{ $rows->onEachSide(1)->links() }}
            </div>
        @endif
    @else
        <p style="color:#718096;text-align:center;padding:40px 0;">No FundServ bank transactions were found in the selected range.</p>
    @endif
</div>
