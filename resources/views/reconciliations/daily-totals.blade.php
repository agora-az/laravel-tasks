@extends('layouts.app')

@section('title', 'Daily Totals Comparison')

@section('content')
<div style="display: flex; justify-content: space-between; align-items: center; margin: 20px 0;">
    <div>
        <h2 style="margin: 0;">Daily Totals Comparison</h2>
        <div style="color: #718096; font-size: 13px; margin-top: 4px;">Bank net total vs. cached VieFund daily net total</div>
    </div>
    <a href="{{ route('reconciliations.matches') }}" class="btn" style="background: #718096; padding: 10px 20px; text-decoration: none;">← Back to Reconciliation</a>
</div>

<div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px;">
    <div class="card" style="background: linear-gradient(135deg, #345262 0%, #5a7585 100%); color: white; text-align: center;">
        <div style="font-size: 28px; font-weight: bold;">{{ number_format($summary['days']) }}</div>
        <div style="font-size: 13px; opacity: 0.9; margin-top: 4px;">Days Compared</div>
    </div>
    <div class="card" style="background: linear-gradient(135deg, #38a169 0%, #2f855a 100%); color: white; text-align: center;">
        <div style="font-size: 24px; font-weight: bold;">{{ '$' . number_format($summary['bank_total'], 2) }}</div>
        <div style="font-size: 13px; opacity: 0.9; margin-top: 4px;">Bank Total</div>
    </div>
    <div class="card" style="background: linear-gradient(135deg, #3182ce 0%, #2c5aa0 100%); color: white; text-align: center;">
        <div style="font-size: 24px; font-weight: bold;">{{ '$' . number_format($summary['viefund_total'], 2) }}</div>
        <div style="font-size: 13px; opacity: 0.9; margin-top: 4px;">VieFund Total</div>
    </div>
    <div class="card" style="background: linear-gradient(135deg, #d53f8c 0%, #97266d 100%); color: white; text-align: center;">
        <div style="font-size: 24px; font-weight: bold;">{{ '$' . number_format($summary['variance_total'], 2) }}</div>
        <div style="font-size: 13px; opacity: 0.9; margin-top: 4px;">Variance</div>
    </div>
</div>

<div class="card" style="margin-bottom: 20px;">
    <form method="GET" action="{{ route('reconciliations.daily-totals') }}" style="display: grid; grid-template-columns: 1fr 1fr auto; gap: 12px; align-items: end;">
        <div>
            <label style="display: block; font-size: 12px; font-weight: 600; color: #4a5568; margin-bottom: 4px;">Date From</label>
            <input type="date" name="date_from" value="{{ $dateFrom }}" style="padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 4px; width: 100%;">
        </div>
        <div>
            <label style="display: block; font-size: 12px; font-weight: 600; color: #4a5568; margin-bottom: 4px;">Date To</label>
            <input type="date" name="date_to" value="{{ $dateTo }}" style="padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 4px; width: 100%;">
        </div>
        <div style="display: flex; gap: 8px;">
            <button type="submit" class="btn" style="padding: 8px 18px;">Filter</button>
            <a href="{{ route('reconciliations.daily-totals') }}" class="btn" style="background: #718096; padding: 8px 18px; text-decoration: none;">Clear</a>
        </div>

        <div style="grid-column: 1 / -1; margin-top: 4px; display: flex; align-items: center; gap: 8px;">
            <input type="checkbox" id="show_zero_days" name="show_zero_days" value="1" {{ $showZeroDays ? 'checked' : '' }}>
            <label for="show_zero_days" style="font-size: 13px; color: #4a5568; cursor: pointer;">
                Include bank holidays and $0 transaction days
            </label>
        </div>

        <div style="grid-column: 1 / -1; margin-top: 2px; display: flex; align-items: center; gap: 8px;">
            <input type="checkbox" id="only_fundserv_bank" name="only_fundserv_bank" value="1" {{ $onlyFundservBank ? 'checked' : '' }}>
            <label for="only_fundserv_bank" style="font-size: 13px; color: #4a5568; cursor: pointer;">
                Only include Fundserv bank transactions (counterparty contains "fundserv")
            </label>
        </div>

        <input type="hidden" name="sort" value="{{ $sortField }}">
        <input type="hidden" name="sort_dir" value="{{ $sortDir }}">
    </form>
</div>

<div class="card">
    <div style="margin-bottom: 12px; display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap;">
        <div style="color: #4a5568; font-size: 13px;">
            {{ $summary['mismatch_days'] }} day(s) with a non-zero variance.
        </div>
        <div style="margin-left: auto; max-width: 760px; width: 100%; display: flex; flex-direction: column; align-items: flex-end; text-align: right;">
        <details style="display: block; width: 100%; text-align: right;">
            <summary title="Calculating VieFund purchase or redemption cash transactions that are confirmed. {{ $onlyFundservBank ? 'Calculating only bank transactions where counterparty contains fundserv.' : 'Calculating all bank transactions.' }}" style="cursor: pointer; color: #2c5282; font-size: 13px; font-weight: 600; text-decoration: underline;">
                Transaction Criteria
            </summary>
            <div style="margin-top: 8px; color: #4a5568; font-size: 13px; line-height: 1.5; text-align: left; display: inline-block;">
                <ul style="margin: 0; padding-left: 20px;">
                    <li>Calculating VieFund purchase or redemption cash transactions that are confirmed.</li>
                    <li>
                        @if($onlyFundservBank)
                            Calculating only bank transactions where counterparty contains "fundserv".
                        @else
                            Calculating all bank transactions.
                        @endif
                    </li>
                </ul>
            </div>
        </details>
        </div>
    </div>

    @if($rows->count())
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse;" class="mono-grid">
                <thead>
                    <tr style="background: #f7fafc; border-bottom: 2px solid #e2e8f0; white-space: nowrap;">
                        @php
                            $baseQuery = request()->except(['sort', 'sort_dir']);
                            $sortUrl = fn(string $field) => route('reconciliations.daily-totals', array_merge($baseQuery, [
                                'sort' => $field,
                                'sort_dir' => ($sortField === $field && $sortDir === 'desc') ? 'asc' : 'desc',
                            ]));
                            $sortArrow = fn(string $field) => $sortField === $field ? ($sortDir === 'asc' ? ' ↑' : ' ↓') : '';
                        @endphp
                        <th style="text-align: left; font-weight: 600; color: #2d3748;">
                            <a href="{{ $sortUrl('total_date') }}" style="color: inherit; text-decoration: none;">Date{{ $sortArrow('total_date') }}</a>
                        </th>
                        <th style="text-align: right; font-weight: 600; color: #2d3748;">Bank Count</th>
                        <th style="text-align: right; font-weight: 600; color: #2d3748;">
                            <a href="{{ $sortUrl('bank_net_total') }}" style="color: inherit; text-decoration: none;">Bank Net{{ $sortArrow('bank_net_total') }}</a>
                        </th>
                        <th style="text-align: right; font-weight: 600; color: #2d3748;">VieFund Count</th>
                        <th style="text-align: right; font-weight: 600; color: #2d3748;">
                            <a href="{{ $sortUrl('viefund_net_total') }}" style="color: inherit; text-decoration: none;">VieFund Net{{ $sortArrow('viefund_net_total') }}</a>
                        </th>
                        <th style="text-align: right; font-weight: 600; color: #2d3748;">
                            <a href="{{ $sortUrl('variance') }}" style="color: inherit; text-decoration: none;">Variance{{ $sortArrow('variance') }}</a>
                        </th>
                        <th style="text-align: right; font-weight: 600; color: #2d3748;">
                            <a href="{{ $sortUrl('discrepancy_pct') }}" style="color: inherit; text-decoration: none;">Discrepancy %{{ $sortArrow('discrepancy_pct') }}</a>
                        </th>
                        <th style="text-align: left; font-weight: 600; color: #2d3748;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $row)
                        @php
                            $statusStyles = [
                                'match' => ['bg' => '#c6f6d5', 'text' => '#22543d', 'label' => 'Match'],
                                'bank-higher' => ['bg' => '#fed7d7', 'text' => '#742a2a', 'label' => 'Bank higher'],
                                'viefund-higher' => ['bg' => '#bee3f8', 'text' => '#2c5282', 'label' => 'VieFund higher'],
                            ];
                            $style = $statusStyles[$row['status']] ?? $statusStyles['match'];
                        @endphp
                        <tr style="border-bottom: 1px solid #e2e8f0; background: {{ $loop->even ? 'rgba(56, 161, 105, 0.07)' : 'transparent' }}">
                            <td style="color: #4a5568; white-space: nowrap; line-height: 1.2;">
                                <span style="display: block;">{{ $row['total_date'] }}</span>
                                <span style="display: block; opacity: 0.9;">00:00</span>
                            </td>
                            <td style="text-align: right;color: #4a5568;">{{ number_format($row['bank_transaction_count']) }}</td>
                            <td style="text-align: right;font-weight: 500; color: {{ $row['bank_net_total'] < 0 ? '#e53e3e' : '#276749' }};">
                                <a href="{{ route('reconciliations.daily-totals.bank-day', ['date' => $row['total_date'], 'only_fundserv_bank' => $onlyFundservBank ? 1 : 0]) }}" target="_blank" rel="noopener noreferrer" style="color: inherit; text-decoration: underline;">
                                    {{ $row['bank_net_total'] < 0 ? '($'.number_format(abs($row['bank_net_total']),2).')' : '$'.number_format($row['bank_net_total'],2) }}
                                </a>
                            </td>
                            <td style="text-align: right;color: #4a5568;">{{ number_format($row['viefund_transaction_count']) }}</td>
                            <td style="text-align: right;font-weight: 500; color: {{ $row['viefund_net_total'] < 0 ? '#e53e3e' : '#276749' }};">
                                <a href="{{ route('reconciliations.daily-totals.viefund-day', ['date' => $row['total_date']]) }}" target="_blank" rel="noopener noreferrer" style="color: inherit; text-decoration: underline;">
                                    {{ $row['viefund_net_total'] < 0 ? '($'.number_format(abs($row['viefund_net_total']),2).')' : '$'.number_format($row['viefund_net_total'],2) }}
                                </a>
                            </td>
                            <td style="text-align: right;font-weight: 500; color: {{ abs($row['variance']) < 0.01 ? '#276749' : '#e53e3e' }};">
                                <a href="{{ route('reconciliations.daily-totals.variance-day', ['date' => $row['total_date'], 'only_fundserv_bank' => $onlyFundservBank ? 1 : 0]) }}" target="_blank" rel="noopener noreferrer" style="color: inherit; text-decoration: underline;">
                                    {{ $row['variance'] < 0 ? '($'.number_format(abs($row['variance']),2).')' : '$'.number_format($row['variance'],2) }}
                                </a>
                            </td>
                            <td style="text-align: right;color: {{ $row['discrepancy_pct'] === null ? '#718096' : '#4a5568' }};">
                                {{ $row['discrepancy_pct'] === null ? 'N/A' : number_format($row['discrepancy_pct'], 2) . '%' }}
                            </td>
                            <td style="">
                                <span style="background: {{ $style['bg'] }}; color: {{ $style['text'] }}; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; white-space: nowrap;">{{ $style['label'] }}</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <p style="color: #718096; text-align: center; padding: 40px 0;">No daily totals found in the selected range.</p>
    @endif
</div>
@endsection
