@extends('layouts.app')

@section('title', 'VieFund Customer Balances')

@section('content')
@include('reconciliations.partials.source-tabs', ['activeReconciliation' => 'customer-balances'])

@php
    $openedBeforeInput = '';
    if (!empty($filters['opened_before'])) {
        try {
            $openedBeforeInput = \Carbon\Carbon::parse($filters['opened_before'])->format('Y-m-d\TH:i');
        } catch (\Throwable) {
            $openedBeforeInput = '';
        }
    }
    $easternNowInput = \Carbon\Carbon::now(config('viefund.simulated_report_timezone', 'America/Toronto'))->format('Y-m-d\TH:i');
@endphp

<div style="margin-bottom:18px;">
    <h2 style="margin:0;">VieFund Customer Balances</h2>
    <div style="color:#718096;font-size:13px;margin-top:4px;">Plan-account cash balances for a selected day from the direct VieFund cash transaction ledger.</div>
</div>

<div class="card" style="margin-bottom:18px;padding:18px 20px;border-top:4px solid #0f766e;">
    @if(isset($errors) && $errors->any())
        <div role="alert" style="margin-bottom:12px;padding:10px 12px;border:1px solid #f6ad55;background:#fffaf0;color:#9c4221;border-radius:5px;font-size:13px;">
            {{ $errors->first() }}
        </div>
    @endif
    <form method="GET" action="{{ route('reconciliations.customer-balances') }}" id="customer-balance-table-form">
        <input type="hidden" name="per_page" value="{{ $perPage }}">
        <input type="hidden" name="sort" value="{{ $filters['sort'] }}">
        <input type="hidden" name="sort_dir" value="{{ $filters['sort_dir'] }}">
        <div class="customer-balance-primary-filters" style="display:grid;grid-template-columns:minmax(160px,1fr) minmax(170px,1fr) minmax(140px,.75fr) minmax(320px,1.7fr) auto;gap:12px;align-items:end;">
            <div>
                <label for="report-date" style="display:block;font-size:12px;font-weight:700;color:#4a5568;margin-bottom:5px;">Reporting Date</label>
                <input type="date" id="report-date" name="report_date" value="{{ $filters['report_date'] }}" max="{{ now()->toDateString() }}" required style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #cbd5e0;border-radius:4px;">
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
            <button class="btn" type="submit" style="padding:8px 18px;white-space:nowrap;">View Table</button>
        </div>

        <div style="margin-top:12px;max-width:620px;">
            <label for="customer-balance-search" style="display:block;font-size:12px;font-weight:700;color:#4a5568;margin-bottom:5px;">Search Customers and Accounts</label>
            <input type="search" id="customer-balance-search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Client name, plan account ID, or cash account ID" maxlength="120" style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #cbd5e0;border-radius:4px;">
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

@include('reconciliations.partials.async-results', [
    'resultsId' => 'customer-balance-results',
    'formId' => 'customer-balance-table-form',
    'loadingMessage' => 'Loading customer balances from the VieFund database…',
])

<style>
    @media (max-width: 1100px) {
        .customer-balance-primary-filters { grid-template-columns:repeat(2,minmax(0,1fr)) !important; }
    }
    @media (max-width: 700px) {
        .customer-balance-primary-filters,
        .customer-balance-summary { grid-template-columns:1fr !important; }
    }
</style>
<script>
(() => {
    let pollTimer = null;

    const showStatus = (status, message, isError = false) => {
        status.textContent = message;
        status.style.display = 'block';
        status.style.borderColor = isError ? '#fecaca' : '#99f6e4';
        status.style.background = isError ? '#fef2f2' : '#ecfdf5';
        status.style.color = isError ? '#991b1b' : '#115e59';
    };

    const setBusy = (button, busy) => {
        button.disabled = busy;
        button.style.opacity = busy ? '.65' : '1';
        button.style.cursor = busy ? 'wait' : 'pointer';
    };

    const download = (url) => {
        const frame = document.createElement('iframe');
        frame.hidden = true;
        frame.src = url;
        document.body.appendChild(frame);
        window.setTimeout(() => frame.remove(), 60000);
    };

    const poll = async (button, status) => {
        try {
            const response = await fetch('{{ route('reports.viefund-customer-balances.status') }}', {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                cache: 'no-store',
            });
            const data = await response.json();
            if (data.inProgress) {
                const progress = Number(data.progress_pct || 0);
                showStatus(status, `${data.message || 'Generating Excel report...'} (${progress}% complete)`);
                return;
            }

            window.clearInterval(pollTimer);
            pollTimer = null;
            setBusy(button, false);
            if (data.success === true && data.download_url) {
                showStatus(status, data.message || 'Excel report completed. Downloading...');
                download(data.download_url);
            } else {
                showStatus(status, data.message || 'Excel report could not be generated.', true);
            }
        } catch (_) {
            if (pollTimer) window.clearInterval(pollTimer);
            pollTimer = null;
            setBusy(button, false);
            showStatus(status, 'Unable to read the Excel report status.', true);
        }
    };

    document.addEventListener('click', async (event) => {
        const button = event.target.closest('#customer-balance-excel-export');
        if (!button) return;

        const form = document.getElementById('customer-balance-excel-form');
        const status = document.getElementById('customer-balance-export-status');
        if (!form || !status) return;

        setBusy(button, true);
        showStatus(status, 'Starting VieFund Customer Balances Excel report...');
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await response.json();
            if (!response.ok) {
                const validationMessage = data.errors ? Object.values(data.errors).flat()[0] : null;
                throw new Error(validationMessage || data.message || 'Excel report could not start.');
            }
            showStatus(status, data.message || 'Excel report started.');
            if (pollTimer) window.clearInterval(pollTimer);
            pollTimer = window.setInterval(() => poll(button, status), 3000);
            poll(button, status);
        } catch (error) {
            setBusy(button, false);
            showStatus(status, error.message, true);
        }
    });
})();
</script>
@endsection
