@extends('layouts.app')

@section('title', 'Bank EFT Records')

@section('content')
@php
    $formatAmount = static function ($amount): string {
        $value = (float) $amount;
        $formatted = '$' . number_format(abs($value), 2);
        return $value < 0 ? '(' . $formatted . ')' : $formatted;
    };
    $dateLabel = \Illuminate\Support\Carbon::parse($date)->format('F j, Y');
    $countVariance = $eftItems->count() - $bankFiles->sum('parsed_transaction_count');
    $amountVariance = $eftTotal - $bankTotal;
@endphp

<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin:20px 0;flex-wrap:wrap;">
    <div>
        <div style="font-size:12px;font-weight:800;color:#2c5282;text-transform:uppercase;letter-spacing:.07em;">Bank EFT Records</div>
        <h2 style="margin:4px 0 0;">Sequence {{ $sequence }} · {{ $dateLabel }}</h2>
        <div style="color:#718096;font-size:13px;margin-top:4px;">{{ $bankFiles->pluck('source_file')->join(', ') }}</div>
    </div>
    <a href="{{ route('eft-files.index', ['tab' => 'files', 'sequences' => $sequence]) }}" class="sync-action-pill sync-action-pill-secondary" style="text-decoration:none;">← File Summaries</a>
</div>

<div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:20px;">
    @foreach([
        ['Bank Records', number_format($bankFiles->sum('parsed_transaction_count')), '#345262'],
        ['Bank Total', $formatAmount($bankTotal), '#2d6a6a'],
        ['Count Variance', number_format($countVariance), $countVariance === 0 ? '#2f855a' : '#c53030'],
        ['Bank Variance', $formatAmount($amountVariance), abs($amountVariance) < .005 ? '#2f855a' : '#c53030'],
    ] as [$label, $value, $color])
        <div class="card" style="text-align:center;border-top:4px solid {{ $color }};">
            <div style="font-size:22px;font-weight:800;color:{{ $color }};white-space:nowrap;">{{ $value }}</div>
            <div style="font-size:12px;font-weight:700;color:#718096;margin-top:5px;">{{ $label }}</div>
        </div>
    @endforeach
</div>

<div class="card" style="padding-top:0;">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 14px;border-bottom:1px solid #e2e8f0;background:#f8fafc;">
        <div style="font-size:13px;color:#4a5568;">
            @if($mode === 'variance')
                Showing {{ number_format($displayRecords->count()) }} unmatched bank record(s). {{ number_format($unmatchedEftCount) }} unmatched VieFund item(s).
            @else
                Showing all {{ number_format($displayRecords->count()) }} bank records. {{ number_format($unmatchedBankCount) }} unmatched.
            @endif
        </div>
        <div style="display:flex;gap:8px;">
            <a href="{{ route('eft-files.bank-records', ['sequence' => $sequence, 'date' => $date]) }}" style="text-decoration:none;padding:6px 10px;border-radius:4px;font-size:12px;font-weight:700;{{ $mode === 'all' ? 'background:#2b6cb0;color:#fff;' : 'background:#e2e8f0;color:#2d3748;' }}">All Bank Records</a>
            <a href="{{ route('eft-files.bank-records', ['sequence' => $sequence, 'date' => $date, 'mode' => 'variance']) }}" style="text-decoration:none;padding:6px 10px;border-radius:4px;font-size:12px;font-weight:700;{{ $mode === 'variance' ? 'background:#2b6cb0;color:#fff;' : 'background:#e2e8f0;color:#2d3748;' }}">Variance Records</a>
        </div>
    </div>

    @if($displayRecords->isNotEmpty())
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;min-width:1100px;" class="mono-grid">
                <thead><tr style="background:#e2e8f0;border-bottom:2px solid #cbd5e0;white-space:nowrap;">
                    <th style="text-align:left;">Record</th>
                    <th style="text-align:left;">Effective</th>
                    <th style="text-align:left;">Direction</th>
                    <th style="text-align:left;">Code</th>
                    <th style="text-align:left;">Holder</th>
                    <th style="text-align:left;">Holder ID</th>
                    <th style="text-align:right;">Amount</th>
                    <th style="text-align:left;">EFT Match</th>
                </tr></thead>
                <tbody>
                @foreach($displayRecords as $record)
                    <tr style="border-bottom:1px solid #d9e2ec;background:{{ $loop->even ? 'rgba(56,161,105,.07)' : 'transparent' }};">
                        <td style="white-space:nowrap;">Line {{ $record->line_number }}.{{ $record->segment_number }}</td>
                        <td style="white-space:nowrap;">{{ $record->effective_date?->format('Y-m-d') ?? '—' }}</td>
                        <td>{{ $record->record_type === 'C' ? 'Credit' : ($record->record_type === 'D' ? 'Debit' : '—') }}</td>
                        <td>{{ $record->transaction_code ?? '—' }}</td>
                        <td>{{ $record->holder_name ?? '—' }}</td>
                        <td style="white-space:nowrap;color:#4a5568;">{{ $record->holder_id ?? '—' }}</td>
                        <td style="text-align:right;white-space:nowrap;font-weight:700;">{{ $formatAmount($record->amount) }}</td>
                        <td><span style="padding:3px 7px;border-radius:4px;font-size:11px;font-weight:700;background:{{ $record->matches_eft ? '#f0fff4' : '#fff5f5' }};color:{{ $record->matches_eft ? '#276749' : '#c53030' }};">{{ $record->matches_eft ? 'Matched' : 'Unmatched' }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @else
        <p style="color:#718096;text-align:center;padding:36px 16px;">
            @if($unmatchedEftCount > 0)
                No unmatched bank records. The count variance comes from {{ number_format($unmatchedEftCount) }} VieFund item(s) without a bank counterpart.
            @else
                No variance records were found; every bank record matched a VieFund EFT item.
            @endif
        </p>
    @endif
</div>
@endsection
