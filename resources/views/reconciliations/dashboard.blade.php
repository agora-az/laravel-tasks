@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <h2 style="margin-bottom: 30px;">Dashboard</h2>

    <!-- Summary Cards -->
    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 40px;">
        <div class="card" style="background: linear-gradient(135deg, #4a5568 0%, #2d3748 100%); color: white; padding: 25px; text-align: center; border-radius: 8px;">
            <div style="font-size: 28px; font-weight: bold; margin-bottom: 8px;">{{ number_format($totalTransactions) }}</div>
            <div style="font-size: 14px; opacity: 0.9;">Total Transactions</div>
        </div>
        <div class="card" style="background: linear-gradient(135deg, #3182ce 0%, #2c5aa0 100%); color: white; padding: 25px; text-align: center; border-radius: 8px;">
            <div style="font-size: 28px; font-weight: bold; margin-bottom: 8px;">{{ number_format($fundservTotal) }}</div>
            <div style="font-size: 14px; opacity: 0.9;">Fundserv Transactions</div>
        </div>
        <div class="card" style="background: linear-gradient(135deg, #38a169 0%, #2f855a 100%); color: white; padding: 25px; text-align: center; border-radius: 8px;">
            <div style="font-size: 28px; font-weight: bold; margin-bottom: 8px;">{{ number_format($viefundTotal) }}</div>
            <div style="font-size: 14px; opacity: 0.9;">VieFund Transactions</div>
        </div>
        <div class="card" style="background: linear-gradient(135deg, #dd6b20 0%, #c05621 100%); color: white; padding: 25px; text-align: center; border-radius: 8px;">
            <div style="font-size: 28px; font-weight: bold; margin-bottom: 8px;">{{ number_format($bankTotal) }}</div>
            <div style="font-size: 14px; opacity: 0.9;">Bank Transactions</div>
        </div>
    </div>

    <!-- Matching Statistics -->
    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 40px;">
        <div class="card" style="background: #f7fafc; padding: 25px; border-radius: 8px; border-left: 4px solid #3182ce;">
            <div style="font-size: 24px; font-weight: bold; color: #3182ce; margin-bottom: 5px;">{{ $fundservMatchPercentage }}%</div>
            <div style="font-size: 13px; color: #4a5568; margin-bottom: 8px;">Fundserv Matched</div>
            <div style="font-size: 12px; color: #718096;">{{ number_format($fundservMatched) }} of {{ number_format($fundservTotal) }}</div>
        </div>
        <div class="card" style="background: #f7fafc; padding: 25px; border-radius: 8px; border-left: 4px solid #38a169;">
            <div style="font-size: 24px; font-weight: bold; color: #38a169; margin-bottom: 5px;">{{ $viefundMatchPercentage }}%</div>
            <div style="font-size: 13px; color: #4a5568; margin-bottom: 8px;">VieFund Matched</div>
            <div style="font-size: 12px; color: #718096;">{{ number_format($viefundMatched) }} of {{ number_format($viefundTotal) }}</div>
        </div>
        <div class="card" style="background: #f7fafc; padding: 25px; border-radius: 8px; border-left: 4px solid #dd6b20;">
            <div style="font-size: 24px; font-weight: bold; color: #dd6b20; margin-bottom: 5px;">{{ $bankMatchPercentage }}%</div>
            <div style="font-size: 13px; color: #4a5568; margin-bottom: 8px;">Bank Matched</div>
            <div style="font-size: 12px; color: #718096;">{{ number_format($bankMatched) }} of {{ number_format($bankTotal) }}</div>
        </div>
        <div class="card" style="background: #f7fafc; padding: 25px; border-radius: 8px; border-left: 4px solid #9f7aea;">
            <div style="font-size: 24px; font-weight: bold; color: #9f7aea; margin-bottom: 5px;">{{ number_format($totalMatches) }}</div>
            <div style="font-size: 13px; color: #4a5568; margin-bottom: 8px;">Total Matches</div>
            <div style="font-size: 12px; color: #718096;">All matched records</div>
        </div>
    </div>

    <!-- Charts -->
    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 30px; margin-bottom: 40px;">
        <!-- Confidence Distribution Chart -->
        <div class="card" style="padding: 25px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
            <h3 style="margin: 0 0 20px 0; color: #2d3748; font-size: 16px;">Match Confidence Distribution</h3>
            <div style="height: 150px;">
                <canvas id="confidenceChart"></canvas>
            </div>
        </div>

        <!-- Transaction Type Distribution -->
        <div class="card" style="padding: 25px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
            <h3 style="margin: 0 0 20px 0; color: #2d3748; font-size: 16px;">Transaction Types</h3>
            <div style="height: 150px;">
                <canvas id="transactionChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Match Rules Chart -->
    <div class="card" style="padding: 25px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 40px;">
        <h3 style="margin: 0 0 20px 0; color: #2d3748; font-size: 16px;">Matches by Rule</h3>
        <div style="height: 120px;">
            <canvas id="rulesChart"></canvas>
        </div>
    </div>

    <!-- Recent Matches -->
    <div class="card" style="padding: 25px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
        <h3 style="margin: 0 0 20px 0; color: #2d3748; font-size: 16px;">Recent Matches</h3>
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f7fafc; border-bottom: 2px solid #e2e8f0;">
                        <th style="padding: 12px; text-align: left; color: #4a5568; font-weight: 600; font-size: 13px;">Rule</th>
                        <th style="padding: 12px; text-align: left; color: #4a5568; font-weight: 600; font-size: 13px;">Left Type</th>
                        <th style="padding: 12px; text-align: left; color: #4a5568; font-weight: 600; font-size: 13px;">Right Type</th>
                        <th style="padding: 12px; text-align: left; color: #4a5568; font-weight: 600; font-size: 13px;">Amount</th>
                        <th style="padding: 12px; text-align: left; color: #4a5568; font-weight: 600; font-size: 13px;">Confidence</th>
                        <th style="padding: 12px; text-align: left; color: #4a5568; font-weight: 600; font-size: 13px;">Matched At</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recentMatches as $match)
                        <tr style="border-bottom: 1px solid #e2e8f0;">
                            <td style="padding: 12px; color: #2d3748; font-size: 13px;">{{ $match->display_label }}</td>
                            <td style="padding: 12px; color: #4a5568; font-size: 13px;">{{ ucfirst($match->left_type) }}</td>
                            <td style="padding: 12px; color: #4a5568; font-size: 13px;">{{ ucfirst($match->right_type) }}</td>
                            <td style="padding: 12px; color: #2d3748; font-size: 13px; font-weight: 500;">${{ number_format($match->matched_amount, 2) }}</td>
                            <td style="padding: 12px; color: #2d3748; font-size: 13px;">{{ round($match->confidence * 100, 1) }}%</td>
                            <td style="padding: 12px; color: #718096; font-size: 13px;">{{ \Carbon\Carbon::parse($match->created_at)->format('M d, Y H:i') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" style="padding: 20px; text-align: center; color: #718096;">No recent matches found</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Action Buttons -->
    <div style="display: flex; gap: 10px; margin-top: 30px;">
        <a href="{{ route('reconciliations.matches') }}" class="btn" style="background: #3182ce; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px;">
            View Matches
        </a>
        <a href="{{ route('reconciliations.index') }}" class="btn" style="background: #718096; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px;">
            Reports
        </a>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.js"></script>
    <script>
        // Confidence Distribution Chart
        const confidenceCtx = document.getElementById('confidenceChart').getContext('2d');
        const confidenceData = @json($confidenceData);
        
        const confidenceLabels = confidenceData.map(item => item.confidence_range + '%');
        const confidenceCounts = confidenceData.map(item => item.count);
        
        new Chart(confidenceCtx, {
            type: 'bar',
            data: {
                labels: confidenceLabels,
                datasets: [{
                    label: 'Number of Matches',
                    data: confidenceCounts,
                    backgroundColor: '#3182ce',
                    borderColor: '#2c5aa0',
                    borderWidth: 1,
                    borderRadius: 4,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: Math.max(5, Math.ceil(Math.max(...confidenceCounts) / 3)),
                            font: { size: 10 }
                        }
                    },
                    x: {
                        ticks: {
                            font: { size: 9 }
                        }
                    }
                }
            }
        });

        // Transaction Type Distribution Chart
        const transactionCtx = document.getElementById('transactionChart').getContext('2d');
        new Chart(transactionCtx, {
            type: 'doughnut',
            data: {
                labels: ['Fundserv', 'VieFund', 'Bank'],
                datasets: [{
                    data: [{{ $fundservTotal }}, {{ $viefundTotal }}, {{ $bankTotal }}],
                    backgroundColor: [
                        '#3182ce',
                        '#38a169',
                        '#dd6b20'
                    ],
                    borderColor: 'white',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            font: { size: 11 },
                            padding: 8
                        }
                    }
                }
            }
        });

        // Matches by Rule Chart
        @if($matchesByRule->count() > 0)
            const rulesCtx = document.getElementById('rulesChart').getContext('2d');
            const rulesData = @json($matchesByRule);
            
            const ruleLabels = rulesData.map(item => item.display_label);
            const ruleCounts = rulesData.map(item => item.count);
            
            new Chart(rulesCtx, {
                type: 'bar',
                data: {
                    labels: ruleLabels,
                    datasets: [{
                        label: 'Number of Matches',
                        data: ruleCounts,
                        backgroundColor: [
                            '#38a169',
                            '#3182ce',
                            '#dd6b20',
                            '#9f7aea',
                            '#d69e2e'
                        ],
                        borderRadius: 4,
                        borderSkipped: false
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: 'y',
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: Math.max(5, Math.ceil(Math.max(...ruleCounts) / 3)),
                                font: { size: 9 }
                            }
                        },
                        y: {
                            ticks: {
                                font: { size: 10 }
                            }
                        }
                    }
                }
            });
        @endif
    </script>
@endsection
