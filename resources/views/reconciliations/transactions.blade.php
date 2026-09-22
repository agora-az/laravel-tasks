@extends('layouts.app')

@section('title', 'VieFund Daily Transactions')

@section('content')
@include('reconciliations.partials.source-tabs', ['activeReconciliation' => 'transactions'])

@php
    $money = static function ($value) {
        $amount = (float) $value;
        $formatted = '$' . number_format(abs($amount), 2);
        return $amount < 0 ? '(' . $formatted . ')' : $formatted;
    };
    $openedBeforeInput = '';
    if (!empty($filters['opened_before'])) {
        try {
            $openedBeforeInput = \Carbon\Carbon::parse($filters['opened_before'])->format('Y-m-d\TH:i');
        } catch (\Throwable) {
            $openedBeforeInput = '';
        }
    }
    $exportQuery = [
        'date_from' => $filters['date_from'],
        'date_to' => $filters['date_to'],
        'date_basis' => $filters['date_basis'],
        'output_order' => $filters['output_order'],
        'daily_balance_currency_code' => $filters['currency'],
        'daily_balance_opened_before' => $filters['opened_before'],
        'status' => $filters['status'],
        'format' => 'csv',
    ];
    $periodNet = collect($rows)->sum('daily_net_transactions');
    $transactionCount = collect($rows)->sum('transaction_count');
    $easternNowInput = \Carbon\Carbon::now(config('viefund.simulated_report_timezone', 'America/Toronto'))->format('Y-m-d\TH:i');
@endphp

<div style="margin-bottom:18px;">
    <h2 style="margin:0;">VieFund Daily Net + Running Balance</h2>
    <div style="color:#718096;font-size:13px;margin-top:4px;">Day-by-day direct cash-ledger activity with opening-to-closing running balances.</div>
</div>

<div class="card" style="margin-bottom:18px;padding:18px 20px;border-top:4px solid #0f766e;">
    @if(isset($errors) && $errors->any())
        <div role="alert" style="margin-bottom:12px;padding:10px 12px;border:1px solid #f6ad55;background:#fffaf0;color:#9c4221;border-radius:5px;font-size:13px;">
            {{ $errors->first() }}
        </div>
    @endif
    <form method="GET" action="{{ route('reconciliations.transactions') }}" id="daily-balance-table-form" data-inception-dates='@json($inceptionDates)'>
        <div class="daily-balance-primary-filters" style="display:grid;grid-template-columns:minmax(150px,1fr) auto minmax(150px,1fr) minmax(165px,1fr) minmax(150px,1fr) auto;gap:12px;align-items:end;">
            <div>
                <label for="date-from" style="display:block;font-size:12px;font-weight:700;color:#4a5568;margin-bottom:5px;">Start Date</label>
                <input type="date" id="date-from" name="date_from" value="{{ $filters['date_from'] }}" max="{{ now()->toDateString() }}" required style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #cbd5e0;border-radius:4px;">
            </div>
            <div>
                <label id="inception-date-note" style="display:block;font-size:11px;color:#718096;margin-bottom:5px;white-space:nowrap;"></label>
                <button type="button" id="set-inception-date" style="height:35px;padding:0 12px;border:1px solid #cbd5e0;border-radius:4px;background:#e2e8f0;color:#2d3748;white-space:nowrap;cursor:pointer;">« Use Inception Date</button>
            </div>
            <div>
                <label for="date-to" style="display:block;font-size:12px;font-weight:700;color:#4a5568;margin-bottom:5px;">End Date</label>
                <input type="date" id="date-to" name="date_to" value="{{ $filters['date_to'] }}" max="{{ now()->toDateString() }}" required style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #cbd5e0;border-radius:4px;">
            </div>
            <div>
                <label for="date-basis" style="display:block;font-size:12px;font-weight:700;color:#4a5568;margin-bottom:5px;">Date Basis</label>
                <select id="date-basis" name="date_basis" style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #cbd5e0;border-radius:4px;background:#fff;">
                    @foreach($dateBasisOptions as $value => $label)
                        <option value="{{ $value }}" @selected($filters['date_basis'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="output-order" style="display:block;font-size:12px;font-weight:700;color:#4a5568;margin-bottom:5px;">Output Order</label>
                <select id="output-order" name="output_order" style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #cbd5e0;border-radius:4px;background:#fff;">
                    @foreach($outputOrderOptions as $value => $label)
                        <option value="{{ $value }}" @selected($filters['output_order'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <button class="btn" type="submit" style="padding:8px 18px;white-space:nowrap;">View Table</button>
        </div>

        <div class="daily-balance-secondary-filters" style="display:grid;grid-template-columns:minmax(180px,1fr) minmax(360px,2fr);gap:12px;align-items:end;margin-top:12px;">
            <div>
                <label for="currency" style="display:block;font-size:12px;font-weight:700;color:#4a5568;margin-bottom:5px;">Currency Code</label>
                <select id="currency" name="currency" style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #cbd5e0;border-radius:4px;background:#fff;">
                    <option value="CAD" @selected($filters['currency'] === 'CAD')>CAD</option>
                    <option value="USD" @selected($filters['currency'] === 'USD')>USD</option>
                </select>
            </div>
            <div>
                <label for="opened-before" style="display:block;font-size:12px;font-weight:700;color:#4a5568;margin-bottom:5px;">Simulated Report Generation Time — Eastern (EST/EDT) (optional)</label>
                <input type="datetime-local" id="opened-before" name="opened_before" value="{{ $openedBeforeInput }}" max="{{ $easternNowInput }}" style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #cbd5e0;border-radius:4px;">
            </div>
        </div>

        <fieldset style="margin:12px 0 0;padding:11px 12px;border:1px solid #d8e4e2;border-radius:6px;background:#f5faf9;">
            <legend style="padding:0 4px;font-size:12px;font-weight:700;color:#4a5568;">Cash Transaction Status</legend>
            <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;font-size:12px;color:#2d3748;">
                @foreach($statusOptions as $value => $label)
                    <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;">
                        <input type="checkbox" name="status[]" value="{{ $value }}" @checked(in_array($value, $filters['status'], true))>
                        <span>{{ $label }}</span>
                    </label>
                @endforeach
                <span style="margin-left:auto;color:#718096;">If none are selected, Confirmed is used.</span>
            </div>
        </fieldset>
    </form>
</div>

<div class="daily-balance-summary" style="display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:12px;margin-bottom:14px;">
    <div class="card" style="padding:14px 16px;">
        <div style="font-size:11px;font-weight:700;color:#718096;text-transform:uppercase;letter-spacing:.04em;">Opening Balance</div>
        <div style="font-size:20px;font-weight:700;color:#2d3748;margin-top:4px;">{{ $money($report['opening_balance']) }}</div>
    </div>
    <div class="card" style="padding:14px 16px;">
        <div style="font-size:11px;font-weight:700;color:#718096;text-transform:uppercase;letter-spacing:.04em;">Period Net</div>
        <div style="font-size:20px;font-weight:700;color:{{ $periodNet < 0 ? '#b91c1c' : '#166534' }};margin-top:4px;">{{ $money($periodNet) }}</div>
    </div>
    <div class="card" style="padding:14px 16px;">
        <div style="font-size:11px;font-weight:700;color:#718096;text-transform:uppercase;letter-spacing:.04em;">Closing Balance</div>
        <div style="font-size:20px;font-weight:700;color:#2d3748;margin-top:4px;">{{ $money($report['final_balance']) }}</div>
    </div>
    <div class="card" style="padding:14px 16px;">
        <div style="font-size:11px;font-weight:700;color:#718096;text-transform:uppercase;letter-spacing:.04em;">Cash Transactions</div>
        <div style="font-size:20px;font-weight:700;color:#2d3748;margin-top:4px;">{{ number_format($transactionCount) }}</div>
    </div>
</div>

<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:10px;font-size:12px;color:#4a5568;">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <strong>Balance source:</strong>
        <span style="display:inline-flex;align-items:center;gap:6px;padding:5px 9px;border-radius:999px;background:{{ $report['uses_snapshots'] ? '#dcfce7' : '#fef3c7' }};color:{{ $report['uses_snapshots'] ? '#166534' : '#92400e' }};font-weight:700;">
            <span style="width:7px;height:7px;border-radius:50%;background:currentColor;"></span>{{ $report['balance_source'] }}
        </span>
        @if($report['snapshot_last_verified_at'])
            <span>Last verified {{ \Carbon\Carbon::parse($report['snapshot_last_verified_at'])->format('Y-m-d H:i') }}</span>
        @endif
        @if($report['changed_days'] > 0)
            <span style="color:#b45309;">{{ number_format($report['changed_days']) }} changed {{ $report['changed_days'] === 1 ? 'day' : 'days' }} awaiting review</span>
        @endif
    </div>
    <a href="{{ route('reports.viefund-daily-balance.export', $exportQuery) }}" style="color:#0f766e;font-weight:700;text-decoration:none;">↓ Download this view as CSV</a>
</div>

<div class="card" style="padding:0;overflow:hidden;">
    <div style="max-height:calc(100vh - 210px);overflow:auto;">
        <table style="border-collapse:collapse;width:100%;min-width:760px;font-size:13px;">
            <thead>
                <tr style="border-bottom:2px solid #94a3b8;">
                    <th style="position:sticky;top:0;z-index:2;padding:12px 16px;text-align:left;background:#dbeafe;color:#1e3a8a;">Report Date</th>
                    <th style="position:sticky;top:0;z-index:2;padding:12px 16px;text-align:right;background:#dbeafe;color:#1e3a8a;">Cash Transactions</th>
                    <th style="position:sticky;top:0;z-index:2;padding:12px 16px;text-align:right;background:#dbeafe;color:#1e3a8a;">Daily Net Transactions</th>
                    <th style="position:sticky;top:0;z-index:2;padding:12px 16px;text-align:right;background:#dbeafe;color:#1e3a8a;">Running Daily Balance</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $row)
                    <tr style="border-bottom:1px solid #e2e8f0;">
                        <td style="padding:11px 16px;font-family:monospace;color:#2d3748;">{{ $row['report_date'] }}</td>
                        <td style="padding:11px 16px;text-align:right;font-variant-numeric:tabular-nums;">{{ number_format($row['transaction_count']) }}</td>
                        <td style="padding:11px 16px;text-align:right;font-family:monospace;font-weight:600;color:{{ $row['daily_net_transactions'] < 0 ? '#b91c1c' : ($row['daily_net_transactions'] > 0 ? '#166534' : '#718096') }};">{{ $money($row['daily_net_transactions']) }}</td>
                        <td style="padding:11px 16px;text-align:right;font-family:monospace;font-weight:700;color:#2d3748;">{{ $money($row['running_daily_balance']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" style="padding:48px;text-align:center;color:#718096;">No dates match the selected filters.</td></tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr style="border-top:2px solid #94a3b8;background:#f8fafc;font-weight:700;">
                    <td style="padding:12px 16px;">Selected period</td>
                    <td style="padding:12px 16px;text-align:right;">{{ number_format($transactionCount) }}</td>
                    <td style="padding:12px 16px;text-align:right;font-family:monospace;">{{ $money($periodNet) }}</td>
                    <td style="padding:12px 16px;text-align:right;font-family:monospace;">{{ $money($report['final_balance']) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<style>
    @media (max-width: 1100px) {
        .daily-balance-primary-filters { grid-template-columns:repeat(2,minmax(0,1fr)) !important; }
    }
    @media (max-width: 700px) {
        .daily-balance-primary-filters,
        .daily-balance-secondary-filters,
        .daily-balance-summary { grid-template-columns:1fr !important; }
    }
</style>
<script>
    (function () {
        const form = document.getElementById('daily-balance-table-form');
        const basis = document.getElementById('date-basis');
        const dateFrom = document.getElementById('date-from');
        const button = document.getElementById('set-inception-date');
        const note = document.getElementById('inception-date-note');
        const inceptionDates = JSON.parse(form.dataset.inceptionDates || '{}');

        function refreshInceptionDate() {
            const date = inceptionDates[basis.value];
            note.textContent = date ? 'Inception: ' + date : 'Inception unavailable';
            button.disabled = !date;
            button.style.opacity = date ? '1' : '.55';
            button.style.cursor = date ? 'pointer' : 'not-allowed';
        }

        basis.addEventListener('change', refreshInceptionDate);
        button.addEventListener('click', function () {
            const date = inceptionDates[basis.value];
            if (date) dateFrom.value = date;
        });
        refreshInceptionDate();
    })();
</script>
@endsection
