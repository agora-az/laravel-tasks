@php
    $money = static function ($value) {
        $amount = (float) $value;
        $formatted = '$' . number_format(abs($amount), 2);
        return $amount < 0 ? '(' . $formatted . ')' : $formatted;
    };
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
    $excelExportQuery = array_merge($exportQuery, ['format' => 'excel']);
    $periodNet = collect($rows)->sum('daily_net_transactions');
    $transactionCount = collect($rows)->sum('transaction_count');
@endphp

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
            <span>Cash balance snapshots last verified {{ \Carbon\Carbon::parse($report['snapshot_last_verified_at'])->setTimezone(config('app.display_timezone', 'America/Toronto'))->format('Y-m-d H:i T') }}</span>
        @endif
        @if($report['changed_days'] > 0)
            <span style="color:#b45309;">{{ number_format($report['changed_days']) }} changed {{ $report['changed_days'] === 1 ? 'day' : 'days' }} awaiting review</span>
        @endif
    </div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <a data-no-async href="{{ route('reports.viefund-daily-balance.export', $exportQuery) }}" style="color:#0f766e;font-weight:700;text-decoration:none;">↓ Export CSV</a>
        <a data-no-async href="{{ route('reports.viefund-daily-balance.export', $excelExportQuery) }}" style="display:inline-flex;align-items:center;padding:7px 12px;border-radius:5px;background:#0f766e;color:#fff;font-weight:700;text-decoration:none;">↓ Export Excel</a>
    </div>
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
