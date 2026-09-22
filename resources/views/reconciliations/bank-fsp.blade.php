@extends('layouts.app')
@section('title', 'Reconciliation - Bank / FSP')
@section('content')
@include('reconciliations.partials.source-tabs', ['activeReconciliation' => 'fsp-'.$source])

<div style="margin:20px 0;">
    <h2 style="margin:0;">Bank / FSP {{ $sourceLabel }} Reconciliation</h2>
    <div style="color:#718096;font-size:13px;margin-top:4px;">FundServ bank activity compared with {{ $sourceLabel }} FSP settlement files by settlement date and currency</div>
</div>

<div class="card" style="margin-bottom:20px;">
    <form id="bank-fsp-reconciliation-form" method="GET" action="{{ route('reconciliations.bank-fsp.source', ['source'=>$source]) }}" style="display:grid;grid-template-columns:1fr 1fr auto;gap:12px;align-items:end;">
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
            <a href="{{ route('reconciliations.bank-fsp.source', ['source'=>$source]) }}" class="btn" style="background:#718096;padding:8px 18px;text-decoration:none;">Clear</a>
        </div>
        <input type="hidden" name="sort" value="{{ $sortField }}">
        <input type="hidden" name="sort_dir" value="{{ $sortDir }}">
        <input type="hidden" name="per_page" value="{{ $perPage }}">
    </form>
</div>

@include('reconciliations.partials.async-results', [
    'resultsId' => 'bank-fsp-reconciliation-results',
    'formId' => 'bank-fsp-reconciliation-form',
    'loadingMessage' => 'Loading Bank / FSP '.$sourceLabel.' reconciliation data…',
])
@endsection
