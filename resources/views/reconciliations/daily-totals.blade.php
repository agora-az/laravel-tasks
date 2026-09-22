@extends('layouts.app')

@section('title', 'Reconciliation - Bank / EFT')

@section('content')
@include('reconciliations.partials.source-tabs', ['activeReconciliation' => 'eft'])

<div style="margin:20px 0;">
    <h2 style="margin:0;">Bank / EFT Reconciliation</h2>
    <div style="color:#718096;font-size:13px;margin-top:4px;">Bank activity by statement date and account, matched to VieFund EFT files by settlement / sequence number</div>
</div>

<div class="card" style="margin-bottom:20px;">
    <form id="bank-eft-reconciliation-form" method="GET" action="{{ route('reconciliations.daily-totals') }}" style="display:grid;grid-template-columns:1fr 1fr auto;gap:12px;align-items:end;">
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

@include('reconciliations.partials.async-results', [
    'resultsId' => 'bank-eft-reconciliation-results',
    'formId' => 'bank-eft-reconciliation-form',
    'loadingMessage' => 'Loading Bank / EFT reconciliation data…',
])
@endsection
