@extends('layouts.app')

@section('title', 'VieFund Transactions')

@section('content')
<div style="margin:20px 0;">
    <div style="font-size:12px;font-weight:800;color:#2c5282;text-transform:uppercase;letter-spacing:.07em;">VieFund Transactions</div>
    <h2 style="margin:4px 0 0;">All Transactions</h2>
    <div style="color:#718096;font-size:13px;margin-top:4px;">All fund and standalone trust transactions using the selected date basis, currency, and cash transaction statuses.</div>
</div>

@php
    $hasFilters = $search !== ''
        || !empty($filters['customer_name'])
        || !empty($filters['plan_account_id'])
        || !empty($filters['trx_id'])
        || !empty($filters['source_id'])
        || !empty($filters['date_from'])
        || !empty($filters['date_to'])
        || !empty($filters['trx_type'])
        || $dateBasis !== 'settlement_date'
        || $outputOrder !== 'desc'
        || $currencyCode !== '00'
        || $statusIds !== [6];
    $dateBasisLabel = $dateBasisOptions[$dateBasis] ?? 'Settlement date';
    $currencyLabel = $currencyOptions[$currencyCode] ?? $currencyCode;
@endphp
<details class="card" style="padding:0;margin-bottom:20px;" open>
    <summary style="display:flex;align-items:center;justify-content:space-between;padding:14px 20px;background:#edf2f7;cursor:pointer;list-style:none;border-radius:6px;font-size:12px;font-weight:800;color:#4a5568;text-transform:uppercase;letter-spacing:.08em;">
        <span>Transaction Filters</span>
        <span style="color:#718096;">Collapse⌄</span>
    </summary>
    <form action="{{ route('viefund-transactions.index') }}" method="GET" style="padding:20px 24px;" data-inception-dates='@json($inceptionDates)'>
        <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px 24px;margin-bottom:16px;">
            <div>
                <label for="all-customer-search" style="display:block;font-size:12px;font-weight:800;color:#4a5568;margin-bottom:6px;">Customer</label>
                <input id="all-customer-search" name="filter_customer_name" value="{{ $filters['customer_name'] ?? '' }}" placeholder="Search by name…" autocomplete="off"
                       style="width:100%;height:40px;padding:8px 12px;border:1px solid #cbd5e0;border-radius:4px;box-sizing:border-box;font-size:13px;">
            </div>
            <div>
                <label for="all-plan-account" style="display:block;font-size:12px;font-weight:800;color:#4a5568;margin-bottom:6px;">Plan Account ID</label>
                <input id="all-plan-account" name="filter_plan_account_id" value="{{ $filters['plan_account_id'] ?? '' }}" placeholder="Search by account ID…"
                       style="width:100%;height:40px;padding:8px 12px;border:1px solid #cbd5e0;border-radius:4px;box-sizing:border-box;font-size:13px;">
            </div>
        </div>

        <div class="all-transactions-date-filters" style="display:grid;grid-template-columns:minmax(150px,1fr) auto minmax(150px,1fr) minmax(165px,1fr) minmax(150px,1fr);gap:12px;align-items:end;margin-bottom:16px;">
            <div>
                <label for="all-date-from" style="display:block;font-size:12px;font-weight:800;color:#4a5568;margin-bottom:6px;">Start Date</label>
                <input type="date" id="all-date-from" name="filter_date_from" value="{{ $filters['date_from'] ?? '' }}" max="{{ now()->toDateString() }}" required
                       style="width:100%;height:40px;padding:8px 12px;border:1px solid #cbd5e0;border-radius:4px;box-sizing:border-box;font-size:13px;">
            </div>
            <div>
                <label id="all-inception-date-note" style="display:block;font-size:11px;color:#718096;margin-bottom:6px;white-space:nowrap;"></label>
                <button type="button" id="all-set-inception-date" style="height:40px;padding:0 12px;border:1px solid #cbd5e0;border-radius:4px;background:#e2e8f0;color:#2d3748;white-space:nowrap;cursor:pointer;">« Use Inception Date</button>
            </div>
            <div>
                <label for="all-date-to" style="display:block;font-size:12px;font-weight:800;color:#4a5568;margin-bottom:6px;">End Date</label>
                <input type="date" id="all-date-to" name="filter_date_to" value="{{ $filters['date_to'] ?? '' }}" max="{{ now()->toDateString() }}" required
                       style="width:100%;height:40px;padding:8px 12px;border:1px solid #cbd5e0;border-radius:4px;box-sizing:border-box;font-size:13px;">
            </div>
            <div>
                <label for="all-date-basis" style="display:block;font-size:12px;font-weight:800;color:#4a5568;margin-bottom:6px;">Date Basis</label>
                <select id="all-date-basis" name="filter_date_basis" style="width:100%;height:40px;padding:8px 12px;border:1px solid #cbd5e0;border-radius:4px;background:#fff;font-size:13px;">
                    @foreach($dateBasisOptions as $value => $label)
                        <option value="{{ $value }}" {{ $dateBasis === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="all-output-order" style="display:block;font-size:12px;font-weight:800;color:#4a5568;margin-bottom:6px;">Output Order</label>
                <select id="all-output-order" name="filter_output_order" style="width:100%;height:40px;padding:8px 12px;border:1px solid #cbd5e0;border-radius:4px;background:#fff;font-size:13px;">
                    @foreach($outputOrderOptions as $value => $label)
                        <option value="{{ $value }}" {{ $outputOrder === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:16px;">
            <div>
                <label for="all-currency-code" style="display:block;font-size:12px;font-weight:800;color:#4a5568;margin-bottom:6px;">Currency Code</label>
                <select id="all-currency-code" name="filter_currency_code" style="width:100%;height:40px;padding:8px 12px;border:1px solid #cbd5e0;border-radius:4px;background:#fff;font-size:13px;">
                    @foreach($currencyOptions as $value => $label)
                        <option value="{{ $value }}" {{ $currencyCode === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="all-trx-id" style="display:block;font-size:12px;font-weight:800;color:#4a5568;margin-bottom:6px;">Txn ID</label>
                <input id="all-trx-id" name="filter_trx_id" value="{{ $filters['trx_id'] ?? '' }}" placeholder="e.g. C-939"
                       style="width:100%;height:40px;padding:8px 12px;border:1px solid #cbd5e0;border-radius:4px;box-sizing:border-box;font-size:13px;font-family:monospace;">
            </div>
            <div>
                <label for="all-source-id" style="display:block;font-size:12px;font-weight:800;color:#4a5568;margin-bottom:6px;">Source ID</label>
                <input id="all-source-id" name="filter_source_id" value="{{ $filters['source_id'] ?? '' }}" placeholder="e.g. L20200…"
                       style="width:100%;height:40px;padding:8px 12px;border:1px solid #cbd5e0;border-radius:4px;box-sizing:border-box;font-size:13px;font-family:monospace;">
            </div>
        </div>

        <div>
            <div>
                <label for="all-trx-types" style="display:block;font-size:12px;font-weight:800;color:#4a5568;margin-bottom:6px;">Txn Type</label>
                <select id="all-trx-types" name="filter_trx_type[]" multiple size="3"
                        style="width:100%;padding:7px 10px;border:1px solid #cbd5e0;border-radius:4px;background:#fff;font-size:13px;">
                    @foreach($availableTrxTypes as $type)
                        <option value="{{ $type }}" {{ in_array($type, (array)($filters['trx_type'] ?? []), true) ? 'selected' : '' }}>{{ $type }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <fieldset style="margin:16px 0 0;padding:12px 14px;border:1px solid #d6e4e1;border-radius:6px;background:#f7fbfa;">
            <legend style="padding:0 6px;font-size:12px;font-weight:800;color:#4a5568;">Cash Transaction Status</legend>
            <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;">
                @foreach($statusOptions as $value => $label)
                    <label style="display:flex;align-items:center;gap:6px;color:#2d3748;font-size:13px;cursor:pointer;">
                        <input type="checkbox" name="filter_status[]" value="{{ $value }}" {{ in_array($value, $statusIds, true) ? 'checked' : '' }} style="width:16px;height:16px;">
                        {{ $label }}
                    </label>
                @endforeach
                <span style="margin-left:auto;color:#718096;font-size:12px;">If none are selected, Confirmed is used. Standalone trust rows use the equivalent Deleted, Unsettled, or Settled status.</span>
            </div>
        </fieldset>

        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px;padding-top:14px;border-top:1px solid #e2e8f0;">
            @if($hasFilters)
                <a href="{{ route('viefund-transactions.index') }}" class="btn" style="background:#718096;text-decoration:none;padding:8px 20px;font-size:13px;">Clear All</a>
            @endif
            <button type="submit" class="btn" style="padding:8px 24px;font-size:13px;">Apply Filters</button>
        </div>
    </form>
</details>

<style>
    @media (max-width: 1100px) {
        .all-transactions-date-filters { grid-template-columns:repeat(2,minmax(0,1fr)) !important; }
    }
    @media (max-width: 700px) {
        .all-transactions-date-filters { grid-template-columns:1fr !important; }
    }
</style>

@if($connectionError)
    <div class="alert alert-error">{{ $connectionError }}</div>
@elseif($transactions)
    <div class="card" style="padding:0;overflow:hidden;">
        <div style="padding:16px 20px;background:linear-gradient(90deg,#ebf8ff,#f0fff4);border-bottom:1px solid #bee3f8;display:flex;align-items:center;justify-content:space-between;gap:16px;">
            <div>
                <div style="font-size:11px;font-weight:700;color:#2c5282;text-transform:uppercase;letter-spacing:.08em;">VieFund Transactions</div>
                <div style="font-size:24px;font-weight:700;color:#1a365d;">All Transactions</div>
            </div>
            <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;justify-content:flex-end;">
                <div style="color:#718096;font-size:13px;">{{ $dateBasisLabel }} · {{ strtolower($outputOrderOptions[$outputOrder] ?? 'Latest first') }} · {{ $currencyLabel }}</div>
                <label for="all-transactions-split-sheets" style="display:flex;align-items:center;gap:6px;color:#4a5568;font-size:13px;cursor:pointer;white-space:nowrap;">
                    <input type="checkbox" id="all-transactions-split-sheets" style="width:16px;height:16px;">
                    Split into smaller sheets
                </label>
                <button type="button" id="all-transactions-excel-export" class="btn" style="padding:8px 14px;font-size:13px;white-space:nowrap;">↓ Export Excel</button>
            </div>
        </div>

        <form id="all-transactions-excel-form" action="{{ route('viefund-transactions.export.start') }}" method="POST" style="display:none;">
            @csrf
            <input type="hidden" name="search" value="{{ $search }}">
            <input type="hidden" name="filter_customer_name" value="{{ $filters['customer_name'] ?? '' }}">
            <input type="hidden" name="filter_plan_account_id" value="{{ $filters['plan_account_id'] ?? '' }}">
            <input type="hidden" name="filter_trx_id" value="{{ $filters['trx_id'] ?? '' }}">
            <input type="hidden" name="filter_source_id" value="{{ $filters['source_id'] ?? '' }}">
            <input type="hidden" name="filter_date_from" value="{{ $filters['date_from'] ?? '' }}">
            <input type="hidden" name="filter_date_to" value="{{ $filters['date_to'] ?? '' }}">
            <input type="hidden" name="filter_date_basis" value="{{ $dateBasis }}">
            <input type="hidden" name="filter_output_order" value="{{ $outputOrder }}">
            <input type="hidden" name="filter_currency_code" value="{{ $currencyCode }}">
            @foreach((array) ($filters['trx_type'] ?? []) as $type)
                <input type="hidden" name="filter_trx_type[]" value="{{ $type }}">
            @endforeach
            @foreach($statusIds as $statusId)
                <input type="hidden" name="filter_status[]" value="{{ $statusId }}">
            @endforeach
        </form>
        <div id="all-transactions-export-status" role="status" style="display:none;margin:12px 20px 0;padding:9px 12px;border:1px solid #99f6e4;border-radius:5px;background:#ecfdf5;color:#115e59;font-size:12px;font-weight:600;"></div>
        <div style="padding:8px 20px 0;color:#718096;font-size:11px;text-align:right;">By default, transactions remain on one date-named sheet. The optional split keeps days together and targets approximately 65,000 rows per sheet.</div>

        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;min-width:1540px;">
                <thead>
                    <tr style="background:#f7fafc;border-bottom:2px solid #cbd5e0;">
                        @foreach(['Txn ID','Source ID','Customer Name','Plan Account ID','Txn Type','Status','Notes','Created Date','Trade Date','Processing Date','Settlement Date','Currency','Amount'] as $heading)
                            <th style="padding:12px;text-align:{{ $heading === 'Amount' ? 'right' : 'left' }};font-weight:700;color:#2d3748;white-space:nowrap;">{{ $heading }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse($transactions as $transaction)
                        @php $amount = $transaction->amount !== null ? (float) $transaction->amount : null; @endphp
                        <tr style="border-bottom:1px solid #e2e8f0;">
                            <td style="padding:12px;font-family:monospace;white-space:nowrap;">{{ $transaction->transaction_id }}</td>
                            <td style="padding:12px;font-family:monospace;">{{ $transaction->source_id ?: '–' }}</td>
                            <td style="padding:12px;font-family:monospace;">{{ $transaction->customer_name ?: '–' }}</td>
                            <td style="padding:12px;font-family:monospace;white-space:nowrap;">{{ $transaction->plan_account_id ?: '–' }}</td>
                            <td style="padding:12px;font-family:monospace;">{{ $transaction->transaction_type ?: '–' }}</td>
                            <td style="padding:12px;font-family:monospace;">{{ $transaction->status ?: '–' }}</td>
                            <td style="padding:12px;font-family:monospace;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{{ $transaction->notes }}">{{ $transaction->notes ?: '–' }}</td>
                            @foreach(['created_date','trade_date','processing_date','settlement_date'] as $dateField)
                                <td style="padding:12px;font-family:monospace;white-space:nowrap;">{{ $transaction->{$dateField} ? date('m/d/Y H:i', strtotime($transaction->{$dateField})) : '–' }}</td>
                            @endforeach
                            <td style="padding:12px;font-family:monospace;white-space:nowrap;">{{ $currencyOptions[$transaction->currency_code] ?? ($transaction->currency_code ?: '–') }}</td>
                            <td style="padding:12px;text-align:right;color:{{ $amount === null || $amount == 0 ? '#718096' : ($amount < 0 ? '#c53030' : '#276749') }};font-family:monospace;font-weight:600;">{{ $amount === null ? '–' : ($amount < 0 ? '($'.number_format(abs($amount), 2).')' : '$'.number_format($amount, 2)) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="13" style="padding:48px;text-align:center;color:#718096;">No VieFund transactions were found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div style="padding:14px 16px;border-top:1px solid #e2e8f0;display:flex;align-items:center;gap:14px;flex-wrap:wrap;font-size:13px;color:#718096;">
            <label for="per-page">Rows per page:</label>
            <select id="per-page" onchange="window.location=this.value" style="border:1px solid #cbd5e0;border-radius:4px;padding:5px 8px;background:#fff;">
                @foreach([50,100,250] as $option)
                    <option value="{{ request()->fullUrlWithQuery(['per_page'=>$option,'page'=>1]) }}" {{ $perPage === $option ? 'selected' : '' }}>{{ $option }}</option>
                @endforeach
            </select>
            <span>Showing {{ number_format($transactions->firstItem() ?? 0) }}–{{ number_format($transactions->lastItem() ?? 0) }} transactions</span>
            <div style="margin-left:auto;">{{ $transactions->withQueryString()->links() }}</div>
        </div>
    </div>
@endif

<script>
(() => {
    const ACTIVE_RUN_KEY = 'viefundAllTransactionsActiveRunId';
    let pollTimer = null;
    let activeRunId = localStorage.getItem(ACTIVE_RUN_KEY) || null;
    let lastProgress = 0;

    const filterForm = document.querySelector('form[data-inception-dates]');
    const dateBasis = document.getElementById('all-date-basis');
    const dateFrom = document.getElementById('all-date-from');
    const inceptionButton = document.getElementById('all-set-inception-date');
    const inceptionNote = document.getElementById('all-inception-date-note');
    const inceptionDates = filterForm ? JSON.parse(filterForm.dataset.inceptionDates || '{}') : {};
    const updateInceptionControl = () => {
        const inceptionDate = inceptionDates[dateBasis?.value] || '';
        if (inceptionNote) inceptionNote.textContent = inceptionDate ? `Inception: ${inceptionDate}` : 'Inception: unavailable';
        if (inceptionButton) inceptionButton.disabled = !inceptionDate;
    };
    dateBasis?.addEventListener('change', updateInceptionControl);
    inceptionButton?.addEventListener('click', () => {
        const inceptionDate = inceptionDates[dateBasis?.value] || '';
        if (inceptionDate && dateFrom) dateFrom.value = inceptionDate;
    });
    updateInceptionControl();

    const showStatus = (element, message, isError = false) => {
        element.textContent = message;
        element.style.display = 'block';
        element.style.borderColor = isError ? '#fecaca' : '#99f6e4';
        element.style.background = isError ? '#fef2f2' : '#ecfdf5';
        element.style.color = isError ? '#991b1b' : '#115e59';
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
        if (!activeRunId) return;

        try {
            const statusUrl = new URL('{{ route('viefund-transactions.export.status') }}', window.location.origin);
            statusUrl.searchParams.set('run_id', activeRunId);
            const response = await fetch(statusUrl.toString(), {
                headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                cache: 'no-store',
            });
            const data = await response.json();
            if (data.run_id !== activeRunId) return;

            if (data.inProgress) {
                const reportedProgress = Number(data.progress_pct || 0);
                lastProgress = Math.max(lastProgress, reportedProgress);
                const progress = data.progress_pct === null ? '' : ` (${lastProgress}% complete)`;
                showStatus(status, `${data.message || 'Generating Excel export...'}${progress}`);
                pollTimer = window.setTimeout(() => poll(button, status), 3000);
                return;
            }

            window.clearTimeout(pollTimer);
            pollTimer = null;
            setBusy(button, false);
            if (data.success === true && data.download_url) {
                showStatus(status, data.message || 'Excel export completed. Downloading...');
                download(data.download_url);
                localStorage.removeItem(ACTIVE_RUN_KEY);
            } else {
                showStatus(status, data.message || 'The Excel export could not be generated.', true);
                localStorage.removeItem(ACTIVE_RUN_KEY);
            }
        } catch (_) {
            pollTimer = window.setTimeout(() => poll(button, status), 5000);
        }
    };

    document.addEventListener('click', async (event) => {
        const button = event.target.closest('#all-transactions-excel-export');
        if (!button) return;

        const form = document.getElementById('all-transactions-excel-form');
        const status = document.getElementById('all-transactions-export-status');
        if (!form || !status) return;

        setBusy(button, true);
        showStatus(status, 'Starting the All Transactions Excel export...');
        try {
            const formData = new FormData(form);
            if (document.getElementById('all-transactions-split-sheets')?.checked) {
                formData.set('split_sheets', '1');
            }
            const response = await fetch(form.action, {
                method: 'POST',
                body: formData,
                headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            });
            const data = await response.json();
            if (!response.ok) {
                const validationMessage = data.errors ? Object.values(data.errors).flat()[0] : null;
                throw new Error(validationMessage || data.message || 'The Excel export could not start.');
            }

            showStatus(status, data.message || 'Excel export started.');
            activeRunId = data.run_id || null;
            lastProgress = 0;
            if (!activeRunId) throw new Error('The export started without a tracking ID.');
            localStorage.setItem(ACTIVE_RUN_KEY, activeRunId);
            if (pollTimer) window.clearTimeout(pollTimer);
            poll(button, status);
        } catch (error) {
            setBusy(button, false);
            showStatus(status, error.message, true);
        }
    });

    if (activeRunId) {
        const button = document.getElementById('all-transactions-excel-export');
        const status = document.getElementById('all-transactions-export-status');
        if (button && status) {
            setBusy(button, true);
            showStatus(status, 'Resuming export progress...');
            poll(button, status);
        }
    }
})();
</script>
@endsection
