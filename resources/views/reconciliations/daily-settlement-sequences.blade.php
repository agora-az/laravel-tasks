@extends('layouts.app')

@section('title', 'Settlement Sequence Reconciliation')

@section('content')
@php
    $formatMoney = static function ($amount): string {
        $value = (float) $amount;
        $formatted = '$' . number_format(abs($value), 2);
        return $value < 0 ? '(' . $formatted . ')' : $formatted;
    };
@endphp

<div style="display:flex;justify-content:space-between;align-items:center;margin:20px 0;gap:12px;flex-wrap:wrap;">
    <div>
        <h2 style="margin:0;">Settlement Sequence Reconciliation</h2>
        <div style="color:#718096;font-size:13px;margin-top:4px;">
            Sequences present on {{ $date }}{{ $account !== '' ? ' for account ' . $account : '' }}, reconciled across all bank dates
        </div>
    </div>
    <a href="{{ route('reconciliations.daily-totals', ['date_from' => $date, 'date_to' => $date]) }}" class="btn" style="text-decoration:none;">Back to Daily Totals</a>
</div>

<div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-bottom:20px;">
    <div class="card" style="background:linear-gradient(135deg,#345262 0%,#5a7585 100%);color:#fff;text-align:center;">
        <div class="summary-card-value" style="font-size:22px;white-space:nowrap;">{{ $formatMoney($summary['bank_net_total']) }}</div>
        <div class="summary-card-label">Bank Net</div>
    </div>
    <div class="card" style="background:linear-gradient(135deg,#3182ce 0%,#2c5aa0 100%);color:#fff;text-align:center;">
        <div class="summary-card-value" style="font-size:22px;white-space:nowrap;">{{ $formatMoney($summary['eft_net_total']) }}</div>
        <div class="summary-card-label">VieFund EFT Net</div>
    </div>
    <div class="card" style="background:linear-gradient(135deg,{{ abs($summary['variance']) < .005 ? '#38a169,#276749' : '#e53e3e,#c53030' }});color:#fff;text-align:center;">
        <div class="summary-card-value" style="font-size:22px;white-space:nowrap;">{{ $formatMoney($summary['variance']) }}</div>
        <div class="summary-card-label">Total Variance</div>
    </div>
</div>

<div class="card">
    <div style="color:#4a5568;font-size:13px;margin-bottom:12px;">
        Bank totals include every record sharing the sequence, even when settlement is split across multiple dates or accounts.
    </div>

    @if($rows->isNotEmpty())
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;" class="mono-grid">
                <thead>
                    <tr style="background:#f7fafc;border-bottom:2px solid #e2e8f0;white-space:nowrap;">
                        <th style="text-align:left;font-weight:600;color:#2d3748;">Sequence</th>
                        <th style="text-align:left;font-weight:600;color:#2d3748;">Bank Date(s)</th>
                        <th style="text-align:left;font-weight:600;color:#2d3748;">Bank Account(s)</th>
                        <th style="text-align:right;font-weight:600;color:#2d3748;">Bank Count</th>
                        <th style="text-align:right;font-weight:600;color:#2d3748;">Bank Net</th>
                        <th style="text-align:right;font-weight:600;color:#2d3748;">EFT Files</th>
                        <th style="text-align:right;font-weight:600;color:#2d3748;">EFT Items</th>
                        <th style="text-align:right;font-weight:600;color:#2d3748;">EFT Net</th>
                        <th style="text-align:right;font-weight:600;color:#2d3748;">Variance</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $row)
                        @php $isMatch = abs($row['variance']) < 0.01; @endphp
                        <tr style="border-bottom:1px solid #e2e8f0;background:{{ !$isMatch ? 'rgba(229,62,62,.08)' : ($loop->even ? 'rgba(56,161,105,.07)' : 'transparent') }};">
                            <td style="white-space:nowrap;color:{{ $isMatch ? '#4a5568' : '#e53e3e' }};font-weight:{{ $isMatch ? '500' : '700' }};">{{ $row['sequence'] }}</td>
                            <td style="color:#4a5568;white-space:nowrap;">
                                {{ $row['first_bank_date'] }}
                                @if($row['last_bank_date'] && $row['last_bank_date'] !== $row['first_bank_date'])
                                    through {{ $row['last_bank_date'] }}
                                @endif
                            </td>
                            <td style="text-align:left;color:#4a5568;white-space:nowrap;">{{ $row['bank_accounts'] ?: '—' }}</td>
                            <td style="text-align:right;color:#4a5568;"><a href="{{ route('reconciliations.daily-totals.bank-day', ['date' => $date, 'settlement_numbers' => $row['sequence'], 'all_dates' => 1]) }}" target="_blank" rel="noopener noreferrer" style="color:inherit;text-decoration:underline;">{{ number_format($row['bank_transaction_count']) }}</a></td>
                            <td style="text-align:right;font-weight:500;color:{{ $row['bank_net_total'] < 0 ? '#e53e3e' : '#276749' }};white-space:nowrap;">{{ $formatMoney($row['bank_net_total']) }}</td>
                            <td style="text-align:right;color:#4a5568;">{{ number_format($row['eft_file_count']) }}</td>
                            <td style="text-align:right;color:#4a5568;">@if($row['eft_transaction_count'] > 0)<a href="{{ route('eft-files.index', ['tab' => 'items', 'sequences' => $row['sequence'], 'drilldown_date' => $date]) }}" target="_blank" rel="noopener noreferrer" style="color:inherit;text-decoration:underline;">{{ number_format($row['eft_transaction_count']) }}</a>@else 0 @endif</td>
                            <td style="text-align:right;font-weight:500;color:{{ $row['eft_net_total'] < 0 ? '#e53e3e' : '#276749' }};white-space:nowrap;">{{ $formatMoney($row['eft_net_total']) }}</td>
                            <td style="text-align:right;font-weight:600;color:{{ $isMatch ? '#276749' : '#e53e3e' }};white-space:nowrap;"><a href="{{ route('reconciliations.daily-totals.eft-sequence-compare', ['date' => $date, 'sequence' => $row['sequence'], 'account' => $account, 'include_3000_sequences' => $include3000Sequences ? 1 : 0]) }}" target="_blank" rel="noopener noreferrer" style="color:inherit;text-decoration:underline;">{{ $formatMoney($row['variance']) }}</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <p style="color:#718096;text-align:center;padding:40px 0;">No settlement sequences were found for this date/account row.</p>
    @endif
</div>
@endsection
