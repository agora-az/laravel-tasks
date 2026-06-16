@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <h2 style="margin-bottom: 30px;">Dashboard</h2>

    @if($connectionError)
        <div style="background:#fff5f5;border:1px solid #fc8181;border-radius:8px;padding:20px;color:#c53030;margin-bottom:30px;">
            Unable to connect to remote VieFund database: {{ $connectionError }}
        </div>
    @elseif($stats)

    {{-- Summary Cards --}}
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:20px;margin-bottom:40px;">
        <div style="background:linear-gradient(135deg,#4a5568 0%,#2d3748 100%);color:white;padding:25px;text-align:center;border-radius:8px;">
            <div style="font-size:28px;font-weight:bold;margin-bottom:8px;">{{ number_format($stats['total_count']) }}</div>
            <div style="font-size:14px;opacity:0.9;">Total Transactions</div>
        </div>
        <div style="background:linear-gradient(135deg,#38a169 0%,#234e52 100%);color:white;padding:25px;text-align:center;border-radius:8px;">
            <div style="font-size:28px;font-weight:bold;margin-bottom:8px;">{{ number_format($stats['fund_count']) }}</div>
            <div style="font-size:14px;opacity:0.9;">Fund Transactions</div>
        </div>
        <div style="background:linear-gradient(135deg,#3182ce 0%,#2c5282 100%);color:white;padding:25px;text-align:center;border-radius:8px;">
            <div style="font-size:28px;font-weight:bold;margin-bottom:8px;">{{ number_format($stats['trust_count']) }}</div>
            <div style="font-size:14px;opacity:0.9;">Trust Transactions</div>
        </div>
        <div style="background:linear-gradient(135deg,#805ad5 0%,#553c9a 100%);color:white;padding:25px;text-align:center;border-radius:8px;">
            <div style="font-size:28px;font-weight:bold;margin-bottom:8px;">{{ number_format($stats['customer_count']) }}</div>
            <div style="font-size:14px;opacity:0.9;">Customers</div>
        </div>
    </div>

    {{-- Plan accounts + chart row --}}
    <div style="display:grid;grid-template-columns:200px 1fr;gap:20px;margin-bottom:40px;">
        <div style="background:linear-gradient(135deg,#d69e2e 0%,#7d6608 100%);color:white;padding:25px;text-align:center;border-radius:8px;align-self:start;">
            <div style="font-size:28px;font-weight:bold;margin-bottom:8px;">{{ number_format($stats['plan_count']) }}</div>
            <div style="font-size:14px;opacity:0.9;">Plan Accounts</div>
        </div>
        <div style="background:white;padding:25px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.08);">
            <h3 style="margin:0 0 16px;color:#2d3748;font-size:15px;">Top Transaction Types</h3>
            <div style="height:160px;">
                <canvas id="typesChart"></canvas>
            </div>
        </div>
    </div>

    {{-- Recent Transactions --}}
    <div style="background:white;padding:25px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.08);margin-bottom:30px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <h3 style="margin:0;color:#2d3748;font-size:15px;">Recent Transactions</h3>
            <a href="{{ route('remote-viefund.index') }}" style="font-size:13px;color:#3182ce;text-decoration:none;">View all →</a>
        </div>
        <table style="width:100%;border-collapse:collapse;">
            <thead>
                <tr style="background:#f7fafc;border-bottom:2px solid #e2e8f0;">
                    <th style="padding:10px 12px;text-align:left;color:#4a5568;font-size:12px;font-weight:600;">TXN ID</th>
                    <th style="padding:10px 12px;text-align:left;color:#4a5568;font-size:12px;font-weight:600;">Client</th>
                    <th style="padding:10px 12px;text-align:left;color:#4a5568;font-size:12px;font-weight:600;">Plan Account</th>
                    <th style="padding:10px 12px;text-align:left;color:#4a5568;font-size:12px;font-weight:600;">Type</th>
                    <th style="padding:10px 12px;text-align:left;color:#4a5568;font-size:12px;font-weight:600;">Created</th>
                    <th style="padding:10px 12px;text-align:right;color:#4a5568;font-size:12px;font-weight:600;">Amount</th>
                </tr>
            </thead>
            <tbody>
                @forelse($stats['recent'] as $txn)
                    <tr style="border-bottom:1px solid #e2e8f0;">
                        <td style="padding:10px 12px;font-family:monospace;font-size:12px;color:#4a5568;">{{ $txn->trx_id ?? '-' }}</td>
                        <td style="padding:10px 12px;font-size:13px;color:#2d3748;">{{ $txn->client_name ?? '-' }}</td>
                        <td style="padding:10px 12px;font-family:monospace;font-size:12px;color:#4a5568;">{{ $txn->plan_dealer_account_id ?? '-' }}</td>
                        <td style="padding:10px 12px;font-size:13px;color:#4a5568;">{{ $txn->trx_type ?? '-' }}</td>
                        <td style="padding:10px 12px;font-size:12px;color:#718096;">{{ $txn->created_date ? \Carbon\Carbon::parse($txn->created_date)->format('M d, Y') : '-' }}</td>
                        <td style="padding:10px 12px;font-family:monospace;font-size:12px;text-align:right;color:{{ ($txn->amount ?? 0) < 0 ? '#e53e3e' : '#276749' }};">
                            @if(isset($txn->amount) && $txn->amount !== null)
                                {{ $txn->amount < 0 ? '($'.number_format(abs($txn->amount),2).')' : '$'.number_format($txn->amount,2) }}
                            @else
                                &mdash;
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="padding:20px;text-align:center;color:#718096;">No recent transactions</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.js"></script>
    <script>
        const typesCtx = document.getElementById('typesChart').getContext('2d');
        const topTypes = @json($stats['top_types']);
        new Chart(typesCtx, {
            type: 'bar',
            data: {
                labels: topTypes.map(t => t.label),
                datasets: [{
                    data: topTypes.map(t => t.count),
                    backgroundColor: '#38a169',
                    borderRadius: 4,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { font: { size: 10 } } },
                    x: { ticks: { font: { size: 10 } } }
                }
            }
        });
    </script>

    @endif
@endsection
