@extends('layouts.app')

@section('title', 'Reconciliation - Bank / EFT')

@section('content')
@php
    $formatMoney = static function ($amount): string {
        $value = (float) $amount;
        $formatted = '$' . number_format(abs($value), 2);
        return $value < 0 ? '(' . $formatted . ')' : $formatted;
    };
@endphp

@include('reconciliations.partials.source-tabs', ['activeReconciliation' => 'eft'])

<div style="margin:20px 0;">
    <h2 style="margin:0;">Bank / EFT Reconciliation</h2>
    <div style="color:#718096;font-size:13px;margin-top:4px;">Bank activity by statement date and account, matched to VieFund EFT files by settlement / sequence number</div>
</div>

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
        <div style="font-size:24px;font-weight:bold;">{{ $formatMoney($summary['bank_total']) }}</div>
        <div style="font-size:13px;opacity:.9;margin-top:4px;">Bank Total Net</div>
    </div>
    <div class="card" style="background:linear-gradient(135deg,#3182ce 0%,#2c5aa0 100%);color:#fff;text-align:center;">
        <div style="font-size:24px;font-weight:bold;">{{ $formatMoney($summary['settlement_total']) }}</div>
        <div style="font-size:13px;opacity:.9;margin-top:4px;">Bank Settlement Net</div>
    </div>
</div>

<div class="card" style="margin-bottom:20px;">
    <form method="GET" action="{{ route('reconciliations.daily-totals') }}" style="display:grid;grid-template-columns:1fr 1fr auto;gap:12px;align-items:end;">
        <div>
            <label style="display:block;font-size:12px;font-weight:600;color:#4a5568;margin-bottom:4px;">Date From</label>
            <input type="date" name="date_from" value="{{ $dateFrom }}" style="padding:8px 10px;border:1px solid #cbd5e0;border-radius:4px;width:100%;">
        </div>
        <div>
            <label style="display:block;font-size:12px;font-weight:600;color:#4a5568;margin-bottom:4px;">Date To</label>
            <input type="date" name="date_to" value="{{ $dateTo }}" style="padding:8px 10px;border:1px solid #cbd5e0;border-radius:4px;width:100%;">
        </div>
        <div style="display:flex;gap:8px;">
            <button type="submit" class="btn" style="padding:8px 18px;">Filter</button>
            <a href="{{ route('reconciliations.daily-totals') }}" class="btn" style="background:#718096;padding:8px 18px;text-decoration:none;">Clear</a>
        </div>
        <div style="display:flex;align-items:center;gap:8px;min-height:38px;">
            <input type="hidden" name="include_3000_sequences" value="0">
            <input type="checkbox" id="include_3000_sequences" name="include_3000_sequences" value="1" @checked($include3000Sequences)>
            <label for="include_3000_sequences" style="font-size:13px;color:#4a5568;cursor:pointer;">Include 3000-level sequences</label>
        </div>
        <div></div>
        <input type="hidden" name="sort" value="{{ $sortField }}">
        <input type="hidden" name="sort_dir" value="{{ $sortDir }}">
        <input type="hidden" name="per_page" value="{{ $perPage }}">
    </form>
</div>

<div class="card">
    <div style="margin-bottom:12px;display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
        <div style="color:#4a5568;font-size:13px;">{{ number_format($summary['rows']) }} date/account/currency row(s).</div>
        <details style="max-width:760px;text-align:right;">
            <summary style="cursor:pointer;color:#2c5282;font-size:13px;font-weight:600;text-decoration:underline;">Matching Criteria</summary>
            <div style="margin-top:8px;color:#4a5568;font-size:13px;line-height:1.5;text-align:left;">
                <ul style="margin:0;padding-left:20px;">
                    <li>Every bank transaction is grouped by value date, statement account, and currency.</li>
                    <li>Only date/account/currency rows containing at least one eligible Bank EFT sequence are displayed.</li>
                    <li>Bank EFT Seq Count is the number of distinct bank settlement numbers. Bank EFT Net includes every bank record with a settlement number.</li>
                    <li>Each bank settlement number is matched to the VieFund EFT file sequence number; EFT dates and amount equality are not matching criteria.</li>
                    <li>VieFund EFT Seq Items is the number of EFT items contained in the sequence-matched files, and VieFund EFT Net is their actual signed total.</li>
                    <li>Variance is Bank EFT Net less VieFund EFT Net.</li>
                    <li>When 3000-level sequences are excluded, sequences 3000 through 3999 are omitted from all Bank EFT and VieFund EFT calculations.</li>
                    <li>Sequence-level bank and EFT differences are available in the settlement drilldown.</li>
                </ul>
            </div>
        </details>
    </div>

    @if($rows->count())
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;" class="mono-grid">
                <thead>
                    <tr style="background:#f7fafc;border-bottom:2px solid #e2e8f0;white-space:nowrap;">
                        @php
                            $baseQuery = request()->except(['sort', 'sort_dir', 'page']);
                            $sortUrl = fn(string $field) => route('reconciliations.daily-totals', array_merge($baseQuery, [
                                'sort' => $field,
                                'sort_dir' => ($sortField === $field && $sortDir === 'asc') ? 'desc' : 'asc',
                            ]));
                            $sortArrow = fn(string $field) => $sortField === $field ? ($sortDir === 'asc' ? ' ↑' : ' ↓') : ' ⇅';
                        @endphp
                        <th style="text-align:left;font-weight:600;color:#2d3748;"><a href="{{ $sortUrl('total_date') }}" style="color:inherit;text-decoration:none;">Date{{ $sortArrow('total_date') }}</a></th>
                        <th style="text-align:left;font-weight:600;color:#2d3748;line-height:1.25;"><a href="{{ $sortUrl('account_number') }}" style="color:inherit;text-decoration:none;"><span style="display:block;">Bank</span><span style="display:block;">Account{{ $sortArrow('account_number') }}</span></a></th>
                        <th style="text-align:left;font-weight:600;color:#2d3748;"><a href="{{ $sortUrl('currency') }}" style="color:inherit;text-decoration:none;">Currency{{ $sortArrow('currency') }}</a></th>
                        <th style="text-align:right;font-weight:600;color:#2d3748;line-height:1.25;"><span style="display:block;">Bank EFT</span><span style="display:block;">Seq Count</span></th>
                        <th style="text-align:right;font-weight:600;color:#2d3748;line-height:1.25;">
                            <a href="{{ $sortUrl('settlement_net_total') }}" style="color:inherit;text-decoration:none;"><span style="display:block;">Bank EFT</span><span style="display:block;">Net{{ $sortArrow('settlement_net_total') }}</span></a>
                        </th>
                        <th style="text-align:right;font-weight:600;color:#2d3748;line-height:1.25;"><span style="display:block;">VieFund EFT</span><span style="display:block;">Seq Items</span></th>
                        <th style="text-align:right;font-weight:600;color:#2d3748;line-height:1.25;"><span style="display:block;">VieFund EFT</span><span style="display:block;">Net</span></th>
                        <th style="text-align:right;font-weight:600;color:#2d3748;">Variance</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $row)
                        <tr style="border-bottom:1px solid #e2e8f0;background:{{ abs($row['variance']) >= .01 ? '#fff5f5' : ($loop->even ? 'rgba(56,161,105,.07)' : 'transparent') }};">
                            <td style="color:#4a5568;white-space:nowrap;">{{ $row['total_date'] }}</td>
                            <td style="color:#4a5568;white-space:nowrap;">@if($row['account_number'])<a href="{{ route('reconciliations.daily-totals.bank-day', ['date' => $row['total_date'], 'account' => $row['account_number']]) }}" target="_blank" rel="noopener noreferrer" style="color:inherit;text-decoration:underline;">{{ $row['account_number'] }}</a>@else—@endif</td>
                            <td style="color:#4a5568;white-space:nowrap;">{{ $row['currency'] }}</td>
                            <td style="text-align:right;color:#4a5568;" title="{{ $row['settlement_sequences']->implode(', ') }}">
                                @if($row['settlement_transaction_count'] > 0)
                                    <a href="{{ route('reconciliations.daily-totals.bank-day', ['date' => $row['total_date'], 'settlement_numbers' => $row['settlement_sequences']->implode(','), 'all_dates' => 1]) }}" target="_blank" rel="noopener noreferrer" style="color:inherit;text-decoration:underline;">{{ number_format($row['settlement_sequences']->count()) }}</a>
                                @else
                                    0
                                @endif
                            </td>
                            <td style="text-align:right;font-weight:500;color:{{ $row['settlement_net_total'] < 0 ? '#e53e3e' : '#276749' }};white-space:nowrap;">
                                {{ $formatMoney($row['settlement_transaction_count'] > 0 ? $row['settlement_net_total'] : 0) }}
                            </td>
                            <td style="text-align:right;color:#4a5568;" title="Matched {{ $row['matched_sequence_count'] }} of {{ $row['settlement_sequences']->count() }} distinct sequence(s)">
                                @if($row['settlement_transaction_count'] > 0)
                                    <a href="{{ route('eft-files.index', ['tab' => 'items', 'sequences' => $row['matched_sequences']->implode(',')]) }}" target="_blank" rel="noopener noreferrer" style="color:inherit;text-decoration:underline;">{{ number_format($row['eft_transaction_count']) }}</a>
                                @else
                                    0
                                @endif
                            </td>
                            <td style="text-align:right;font-weight:500;color:{{ $row['eft_net_total'] < 0 ? '#e53e3e' : '#276749' }};white-space:nowrap;">
                                {{ $formatMoney($row['settlement_transaction_count'] > 0 ? $row['eft_net_total'] : 0) }}
                            </td>
                            <td style="text-align:right;font-weight:600;color:{{ abs($row['variance']) >= .01 ? '#e53e3e' : '#276749' }};white-space:nowrap;">
                                <a href="{{ route('reconciliations.daily-totals.settlement-sequences', ['date' => $row['total_date'], 'account' => $row['account_number'], 'include_3000_sequences' => $include3000Sequences ? 1 : 0]) }}" target="_blank" rel="noopener noreferrer" style="color:inherit;text-decoration:underline;">{{ $formatMoney($row['variance']) }}</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($rows->hasPages())
            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:14px;flex-wrap:wrap;">
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                    <div style="font-size:12px;color:#718096;">Showing {{ $rows->firstItem() }} to {{ $rows->lastItem() }} of {{ $rows->total() }} row(s)</div>
                    <form method="GET" action="{{ route('reconciliations.daily-totals') }}" style="display:flex;align-items:center;gap:6px;margin:0;">
                        @foreach(request()->except(['per_page', 'page']) as $key => $value)
                            @if(!is_array($value))
                                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                            @endif
                        @endforeach
                        <label for="per_page" style="font-size:12px;color:#718096;">Rows</label>
                        <select id="per_page" name="per_page" onchange="this.form.submit()" style="padding:5px 8px;border:1px solid #cbd5e0;border-radius:4px;font-size:12px;">
                            @foreach([25, 50, 100, 250] as $option)
                                <option value="{{ $option }}" @selected($perPage === $option)>{{ $option }}</option>
                            @endforeach
                        </select>
                    </form>
                </div>
                {{ $rows->onEachSide(1)->links() }}
            </div>
        @endif
    @else
        <p style="color:#718096;text-align:center;padding:40px 0;">No bank activity found in the selected range.</p>
    @endif
</div>
@endsection
