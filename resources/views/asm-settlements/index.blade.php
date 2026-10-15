@extends('layouts.app')

@section('title', 'ASM Files')

@section('content')
@php
    $formatMoney = static function ($amount, ?string $currency = null): string {
        $value = (float) $amount;
        $formatted = ($currency ? $currency . ' $' : '$') . number_format(abs($value), 2);
        return $value < 0 ? '(' . $formatted . ')' : $formatted;
    };
    $sortUrl = static function (string $column) use ($sort, $sortDir): string {
        return route('asm-settlements.index', array_merge(
            request()->except(['sort', 'sort_dir', 'page']),
            [
                'sort' => $column,
                'sort_dir' => $sort === $column && $sortDir === 'asc' ? 'desc' : 'asc',
            ]
        ));
    };
@endphp

<div class="sync-page-header">
    <div class="sync-page-header-main">
        <h2 style="margin:0;">ASM Files</h2>
        <div style="color:#718096;font-size:13px;margin-top:4px;">Source: Fundserv Adhoc Money Transfer settlement workbooks</div>
        <div id="asm-import-status-wrap" class="sync-chip sync-chip-progress sync-page-notification" style="display:none;">
            <span id="asm-import-status"></span>
            <button type="button" id="asm-import-status-dismiss" aria-label="Dismiss ASM sync status" style="border:none;background:transparent;color:inherit;font-size:14px;font-weight:700;cursor:pointer;line-height:1;padding:0;">×</button>
        </div>
    </div>
    <div class="sync-page-header-meta">
        <div id="asm-last-import" style="display:flex;flex-direction:column;align-items:flex-end;gap:4px;color:#4a5568;font-size:12px;text-align:right;line-height:1.4;">
            <span><strong>Last ASM file sync:</strong> <span id="asm-last-import-time">checking…</span></span>
            <span id="asm-last-import-detail">&nbsp;</span>
        </div>
    </div>
</div>

@if(session('import_success'))
    <div style="margin-bottom:16px;padding:10px 14px;border-radius:6px;background:#c6f6d5;color:#22543d;border:1px solid #9ae6b4;font-size:13px;">
        {{ session('import_success') }}
    </div>
@endif
@if(session('import_error'))
    <div style="margin-bottom:16px;padding:10px 14px;border-radius:6px;background:#fff5f5;color:#742a2a;border:1px solid #feb2b2;font-size:13px;">
        {{ session('import_error') }}
    </div>
@endif

<div style="display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:16px;margin-bottom:24px;">
    <div class="card" style="background:linear-gradient(135deg,#345262 0%,#5a7585 100%);color:white;text-align:center;">
        <div class="summary-card-value">{{ number_format($recordCount) }}</div>
        <div class="summary-card-label">Transactions</div>
    </div>
    <div class="card" style="background:linear-gradient(135deg,#2d6a6a 0%,#234e52 100%);color:white;text-align:center;">
        @forelse($currencyTotals as $total)
            <div class="summary-card-value" style="font-size:14px;line-height:1.4;overflow-wrap:anywhere;">{{ $formatMoney($total->settlement_total, $total->currency) }}</div>
        @empty
            <div class="summary-card-value">—</div>
        @endforelse
        <div class="summary-card-label">Settlement Total</div>
    </div>
    <div class="card" style="background:linear-gradient(135deg,#6b46c1 0%,#553c9a 100%);color:white;text-align:center;">
        <div class="summary-card-value">{{ number_format($paymentTypeCount) }}</div>
        <div class="summary-card-label">Payment Types</div>
    </div>
    <div class="card" style="background:linear-gradient(135deg,#3182ce 0%,#2c5aa0 100%);color:white;text-align:center;">
        <div class="summary-card-value">{{ number_format((int) ($summaryTotals->file_count ?? 0)) }}</div>
        <div class="summary-card-label">Files</div>
    </div>
    <div class="card" style="background:linear-gradient(135deg,#38a169 0%,#2f855a 100%);color:white;text-align:center;">
        <div class="summary-card-value">{{ $transactions->currentPage() }} / {{ max(1, $transactions->lastPage()) }}</div>
        <div class="summary-card-label">Page</div>
    </div>
</div>

<div class="card" style="margin-bottom:20px;">
    <form method="GET" action="{{ route('asm-settlements.index') }}">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;align-items:end;">
            <div>
                <label style="display:block;font-size:12px;font-weight:600;color:#4a5568;margin-bottom:4px;">Currency</label>
                <select name="currency" style="padding:8px 10px;border:1px solid #cbd5e0;border-radius:4px;width:100%;font-size:13px;">
                    <option value="">All</option>
                    <option value="CAD" @selected(request('currency') === 'CAD')>CAD</option>
                    <option value="USD" @selected(request('currency') === 'USD')>USD</option>
                </select>
            </div>
            <div>
                <label style="display:block;font-size:12px;font-weight:600;color:#4a5568;margin-bottom:4px;">Payment Type</label>
                <select name="payment_type" style="padding:8px 10px;border:1px solid #cbd5e0;border-radius:4px;width:100%;font-size:13px;">
                    <option value="">All</option>
                    @foreach($paymentTypes as $type)
                        <option value="{{ $type->payment_type }}" @selected(request('payment_type') === $type->payment_type)>
                            {{ $type->payment_type }} — {{ $type->payment_type_description }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label style="display:block;font-size:12px;font-weight:600;color:#4a5568;margin-bottom:4px;">Settlement From</label>
                <input type="date" name="date_from" value="{{ request('date_from') }}" style="padding:8px 10px;border:1px solid #cbd5e0;border-radius:4px;width:100%;font-size:13px;">
            </div>
            <div>
                <label style="display:block;font-size:12px;font-weight:600;color:#4a5568;margin-bottom:4px;">Settlement To</label>
                <input type="date" name="date_to" value="{{ request('date_to') }}" style="padding:8px 10px;border:1px solid #cbd5e0;border-radius:4px;width:100%;font-size:13px;">
            </div>
            <div>
                <label style="display:block;font-size:12px;font-weight:600;color:#4a5568;margin-bottom:4px;">Source File</label>
                <select name="source_file" style="padding:8px 10px;border:1px solid #cbd5e0;border-radius:4px;width:100%;font-size:13px;">
                    <option value="">All files</option>
                    @foreach($sourceFiles as $file)
                        <option value="{{ $file }}" @selected(request('source_file') === $file)>{{ $file }}</option>
                    @endforeach
                </select>
            </div>
            <div style="display:flex;gap:8px;">
                <button type="submit" class="btn" style="padding:8px 20px;">Filter</button>
                @if(request()->hasAny(['currency','payment_type','date_from','date_to','source_file','search']))
                    <a href="{{ route('asm-settlements.index') }}" class="btn" style="background:#718096;padding:8px 14px;text-decoration:none;">Clear</a>
                @endif
            </div>
        </div>
        <div style="margin-top:12px;">
            <label style="display:block;font-size:12px;font-weight:600;color:#4a5568;margin-bottom:4px;">Search payment, company, or account</label>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Payment ID, company, or account number..." style="padding:8px 10px;border:1px solid #cbd5e0;border-radius:4px;width:100%;font-size:13px;">
        </div>
    </form>
</div>

<div class="card" style="padding:0;margin-bottom:20px;overflow:hidden;">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;padding:14px;border-bottom:1px solid #e2e8f0;background:#f7fafc;">
        <div>
            <div style="font-size:12px;font-weight:800;color:#4a5568;text-transform:uppercase;letter-spacing:.07em;">ASM Files</div>
            <div style="font-size:13px;color:#718096;margin-top:3px;">
                Showing {{ number_format($summaries->count()) }} of {{ number_format((int) ($summaryTotals->file_count ?? 0)) }} imported files.
            </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;">
            <form method="POST" action="{{ route('asm-settlements.map-viefund') }}" style="margin:0;">
                @csrf
                <button type="submit" class="sync-action-pill">Map to VieFund</button>
            </form>
            <form method="POST" action="{{ route('asm-settlements.sync') }}" style="margin:0;">
                @csrf
                <button id="asm-import-button" type="submit" class="sync-action-pill sync-action-pill-primary">↻ Sync ASM Files</button>
            </form>
        </div>
    </div>
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:13px;min-width:980px;">
            <thead>
                <tr style="background:#edf2f7;border-bottom:2px solid #cbd5e0;white-space:nowrap;">
                    <th style="padding:10px 12px;text-align:left;">Source File</th>
                    <th style="padding:10px 12px;text-align:left;">Settlement Range</th>
                    <th style="padding:10px 12px;text-align:right;">Rows</th>
                    <th style="padding:10px 12px;text-align:right;">Duplicates</th>
                    <th style="padding:10px 12px;text-align:left;">Currency Totals</th>
                    <th style="padding:10px 12px;text-align:left;">Payment Types</th>
                    <th style="padding:10px 12px;text-align:left;">Imported</th>
                </tr>
            </thead>
            <tbody>
                @forelse($summaries as $summary)
                    <tr style="border-bottom:1px solid #e2e8f0;">
                        <td style="padding:10px 12px;font-weight:700;">{{ $summary->source_file }}</td>
                        <td style="padding:10px 12px;white-space:nowrap;">
                            {{ $summary->min_settlement_date?->format('Y-m-d') ?? '—' }}
                            @if($summary->max_settlement_date && !$summary->max_settlement_date->equalTo($summary->min_settlement_date))
                                – {{ $summary->max_settlement_date->format('Y-m-d') }}
                            @endif
                        </td>
                        <td style="padding:10px 12px;text-align:right;">{{ number_format($summary->record_count) }}</td>
                        <td style="padding:10px 12px;text-align:right;">{{ number_format($summary->duplicate_count) }}</td>
                        <td style="padding:10px 12px;white-space:nowrap;">
                            @forelse($summary->currency_totals ?? [] as $currency => $total)
                                <div>{{ $formatMoney($total['settlement_total'] ?? 0, $currency) }}</div>
                            @empty
                                —
                            @endforelse
                        </td>
                        <td style="padding:10px 12px;">
                            {{ collect($summary->payment_type_totals ?? [])->keys()->filter()->implode(', ') ?: '—' }}
                        </td>
                        <td style="padding:10px 12px;white-space:nowrap;">{{ $summary->created_at?->format('Y-m-d H:i') ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" style="padding:24px;text-align:center;color:#718096;">No ASM workbooks have been imported.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($summaries->hasPages())
        <div style="padding:14px;">{{ $summaries->links() }}</div>
    @endif
</div>

<div class="card" style="padding:0;overflow:hidden;">
    <div style="padding:14px;border-bottom:1px solid #e2e8f0;background:#f7fafc;">
        <div style="font-size:12px;font-weight:800;color:#4a5568;text-transform:uppercase;letter-spacing:.07em;">ASM Transactions</div>
        <div style="font-size:13px;color:#718096;margin-top:3px;">{{ number_format($transactions->total()) }} rows match the active filters.</div>
    </div>
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:12px;min-width:1500px;">
            <thead>
                <tr style="background:#edf2f7;border-bottom:2px solid #cbd5e0;white-space:nowrap;">
                    @foreach([
                        'settlement_date' => 'Settlement Date',
                        'payment_id' => 'Payment ID',
                        'payment_type' => 'Payment Type',
                        'currency' => 'Currency',
                        'settlement_amount' => 'Settlement Amount',
                    ] as $column => $label)
                        <th style="padding:10px 12px;text-align:{{ $column === 'settlement_amount' ? 'right' : 'left' }};">
                            <a href="{{ $sortUrl($column) }}" style="color:inherit;text-decoration:none;">{{ $label }}@if($sort === $column) {{ $sortDir === 'asc' ? '↑' : '↓' }}@endif</a>
                        </th>
                    @endforeach
                    <th style="padding:10px 12px;text-align:left;">Sender</th>
                    <th style="padding:10px 12px;text-align:left;">Receiver</th>
                    <th style="padding:10px 12px;text-align:left;">Sender Account</th>
                    <th style="padding:10px 12px;text-align:left;">Receiver Account</th>
                    <th style="padding:10px 12px;text-align:left;">Payment Streams</th>
                    <th style="padding:10px 12px;text-align:left;">Source File</th>
                </tr>
            </thead>
            <tbody>
                @forelse($transactions as $transaction)
                    <tr style="border-bottom:1px solid #e2e8f0;">
                        <td style="padding:10px 12px;white-space:nowrap;">{{ $transaction->settlement_date?->format('Y-m-d') ?? '—' }}</td>
                        <td style="padding:10px 12px;font-weight:700;">{{ $transaction->payment_id ?? '—' }}</td>
                        <td style="padding:10px 12px;">{{ $transaction->payment_type }} — {{ $transaction->payment_type_description }}</td>
                        <td style="padding:10px 12px;">{{ $transaction->currency ?? '—' }}</td>
                        <td style="padding:10px 12px;text-align:right;font-weight:700;color:{{ (float) $transaction->settlement_amount < 0 ? '#c53030' : '#2f855a' }};white-space:nowrap;">{{ $formatMoney($transaction->settlement_amount) }}</td>
                        <td style="padding:10px 12px;">{{ $transaction->sender_company_code }} — {{ $transaction->sender_company_name }}</td>
                        <td style="padding:10px 12px;">{{ $transaction->receiver_company_code }} — {{ $transaction->receiver_company_name }}</td>
                        <td style="padding:10px 12px;">{{ $transaction->sender_account_number ?? '—' }}</td>
                        <td style="padding:10px 12px;">{{ $transaction->receiver_dealer_account_number ?: ($transaction->receiver_fund_company_account_number ?: ($transaction->receiver_intermediary_account_number ?: '—')) }}</td>
                        <td style="padding:10px 12px;white-space:nowrap;">{{ $transaction->sender_payment_stream ?? '—' }} → {{ $transaction->receiver_payment_stream ?? '—' }}</td>
                        <td style="padding:10px 12px;white-space:nowrap;">{{ $transaction->source_file }}</td>
                    </tr>
                @empty
                    <tr><td colspan="11" style="padding:24px;text-align:center;color:#718096;">No ASM transactions match the active filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($transactions->hasPages())
        <div style="padding:14px;">{{ $transactions->links() }}</div>
    @endif
</div>

<script>
(function () {
    const wrap = document.getElementById('asm-import-status-wrap');
    const text = document.getElementById('asm-import-status');
    const dismiss = document.getElementById('asm-import-status-dismiss');
    const button = document.getElementById('asm-import-button');
    const lastTime = document.getElementById('asm-last-import-time');
    const lastDetail = document.getElementById('asm-last-import-detail');
    if (!wrap || !text) return;

    let observedInProgress = false;
    let dismissed = false;
    let completionRunId = null;

    const setVisible = (visible) => { wrap.style.display = visible ? 'inline-flex' : 'none'; };
    const setBusy = (busy) => {
        if (!button) return;
        button.disabled = busy;
        button.style.opacity = busy ? '.65' : '';
        button.style.cursor = busy ? 'not-allowed' : '';
    };

    dismiss?.addEventListener('click', () => {
        dismissed = true;
        setVisible(false);
    });

    const poll = () => {
        fetch('{{ route('asm-settlements.sync-status') }}', {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
        })
            .then(response => response.json())
            .then(data => {
                if (data.completed_at) {
                    lastTime.textContent = window.formatOperationalDateTime(data.completed_at);
                    lastDetail.textContent = data.message || (data.success ? 'Completed' : 'Failed');
                } else if (data.inProgress) {
                    lastTime.textContent = 'currently running';
                    lastDetail.textContent = data.current_file || data.message || 'Syncing ASM files';
                } else {
                    lastTime.textContent = 'no completed import recorded';
                    lastDetail.innerHTML = '&nbsp;';
                }

                if (data.inProgress) {
                    observedInProgress = true;
                    dismissed = false;
                    wrap.className = 'sync-chip sync-chip-progress sync-page-notification';
                    const total = data.total_files ?? '?';
                    text.textContent = `Syncing ${data.current_file || 'ASM files'} · ${data.processed_files ?? 0}/${total} · ${data.progress_pct ?? 0}%`;
                    setVisible(true);
                    setBusy(true);
                    return;
                }

                setBusy(false);
                if ((observedInProgress || data.initiatedByCurrentSession) && data.run_id !== completionRunId) {
                    completionRunId = data.run_id;
                    observedInProgress = false;
                    dismissed = false;
                }

                if (!dismissed && data.initiatedByCurrentSession && data.success !== null) {
                    wrap.className = `sync-chip ${data.success ? 'sync-chip-success' : 'sync-chip-error'} sync-page-notification`;
                    text.textContent = data.message || (data.success ? 'ASM sync completed.' : 'ASM sync failed.');
                    setVisible(true);
                } else if (!data.inProgress) {
                    setVisible(false);
                }
            })
            .catch(() => {});
    };

    poll();
    setInterval(poll, 2000);
})();
</script>
@endsection
