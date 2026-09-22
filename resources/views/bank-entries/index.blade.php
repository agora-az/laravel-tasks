@extends('layouts.app')

@section('title', 'Bank Statements')

@section('content')
@php
    $showSyncButtons = filter_var(env('SHOW_SYNC_BUTTONS', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    $showSyncButtons = $showSyncButtons ?? true;
    $activeTab = request('view') === 'transactions' ? 'transactions' : 'summaries';
    $currencyTotals = $totals->currency_totals ?? collect();
    $formatCurrencyTotal = static function ($amount, ?string $currency, bool $accounting = false): string {
        $value = (float) $amount;
        $formatted = ($currency ?: 'Unknown') . ' $' . number_format(abs($value), 2);
        return $accounting && $value < 0 ? '(' . $formatted . ')' : $formatted;
    };
    $dateFromLabel = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) request('date_from'))
        ? \Illuminate\Support\Carbon::parse(request('date_from'))->format('F j, Y')
        : null;
    $dateToLabel = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) request('date_to'))
        ? \Illuminate\Support\Carbon::parse(request('date_to'))->format('F j, Y')
        : null;
    $selectedStatementDate = $selectedStatement
        ? ($selectedStatement->closing_balance_date ?? $selectedStatement->opening_balance_date)
        : null;
    $dateScopeLabel = match (true) {
        $selectedStatementDate !== null => $selectedStatementDate->format('F j, Y'),
        $dateFromLabel && $dateToLabel && $dateFromLabel === $dateToLabel => $dateFromLabel,
        $dateFromLabel && $dateToLabel => $dateFromLabel . ' through ' . $dateToLabel,
        $dateFromLabel => $dateFromLabel . ' onward',
        $dateToLabel => 'Through ' . $dateToLabel,
        default => null,
    };
    $hasStatementScope = $selectedStatement !== null || $dateFromLabel !== null || $dateToLabel !== null;
    $bankViewUrl = static fn(string $view): string => route(
        'bank-entries.index',
        array_merge(request()->except(['view', 'page', 'summary_page', 'statement_summary_id']), ['view' => $view])
    );
@endphp
<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin:20px 0;flex-wrap:wrap;">
    <div>
        <h2 style="margin: 0;">Bank Statements</h2>
        <div style="color: #718096; font-size: 13px; margin-top: 4px;">Source: CIBC CAMT.053 · Parser v2</div>
        <div id="bank-sync-status-wrap" class="sync-chip sync-chip-progress" style="display:none; margin-top:8px; width:max-content; align-items:center; gap:8px;">
            <span id="bank-sync-status"></span>
            <button type="button" id="bank-sync-status-dismiss" aria-label="Dismiss bank sync status" style="border:none; background:transparent; color:inherit; font-size:14px; font-weight:700; cursor:pointer; line-height:1; padding:0;">×</button>
        </div>
    </div>
    <div id="bank-last-sync" style="display:flex;flex-direction:column;align-items:flex-end;gap:4px;color:#4a5568;font-size:12px;text-align:right;line-height:1.4;">
        <span><strong>Last bank statement sync:</strong> <span id="bank-last-sync-time">checking…</span></span>
        <span id="bank-last-sync-detail">&nbsp;</span>
    </div>
</div>

@if(session('sync_success'))
    <div style="margin-bottom: 16px; padding: 10px 14px; border-radius: 6px; background: #c6f6d5; color: #22543d; border: 1px solid #9ae6b4; font-size: 13px;">
        {{ session('sync_success') }}
    </div>
@endif
@if(session('sync_error'))
    <div style="margin-bottom: 16px; padding: 10px 14px; border-radius: 6px; background: #fff5f5; color: #742a2a; border: 1px solid #feb2b2; font-size: 13px;">
        {{ session('sync_error') }}
    </div>
@endif

<script>
(function () {
    const wrap = document.getElementById('bank-sync-status-wrap');
    const text = document.getElementById('bank-sync-status');
    const dismiss = document.getElementById('bank-sync-status-dismiss');
    const lastSyncTime = document.getElementById('bank-last-sync-time');
    const lastSyncDetail = document.getElementById('bank-last-sync-detail');
    if (!wrap || !text) return;

    const DISMISS_KEY = 'bankEntriesSyncDismissedMessage';
    let currentMessage = '';

    const setVisible = (visible) => {
        wrap.style.display = visible ? 'inline-flex' : 'none';
    };

    const setBusy = (busy) => {
        document.querySelectorAll('[data-bank-sync-button]').forEach((btn) => {
            btn.disabled = busy;
            btn.style.opacity = busy ? '0.65' : '';
            btn.style.cursor = busy ? 'not-allowed' : '';
        });
    };

    const setMessage = (msg) => {
        currentMessage = msg;
        text.textContent = msg;
        const dismissed = localStorage.getItem(DISMISS_KEY);
        setVisible(dismissed !== msg);
    };

    if (dismiss) {
        dismiss.addEventListener('click', () => {
            if (!currentMessage) return;
            localStorage.setItem(DISMISS_KEY, currentMessage);
            setVisible(false);
        });
    }

    const poll = () => {
        fetch('{{ route('bank-entries.sync-status') }}', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(data => {
                if (lastSyncTime && lastSyncDetail) {
                    if (data.completed_at) {
                        const when = window.formatOperationalDateTime(data.completed_at);
                        const result = data.success === true ? (data.message || 'Completed successfully') : (data.success === false ? 'Failed' : 'Completed');
                        const trigger = data.trigger ? `Started via ${data.trigger}` : '';
                        lastSyncTime.textContent = when;
                        lastSyncDetail.textContent = trigger ? `${result} · ${trigger}` : result;
                    } else if (data.inProgress && data.started_at) {
                        lastSyncTime.textContent = 'currently running';
                        lastSyncDetail.textContent = data.message || 'Bank statement sync in progress…';
                    } else {
                        lastSyncTime.textContent = 'no completed sync recorded';
                        lastSyncDetail.innerHTML = '&nbsp;';
                    }
                }
                if (data.inProgress) {
                    const processed = data.processed_files ?? 0;
                    const total = data.total_files ?? '?';
                    const pct = data.progress_pct ?? 0;
                    wrap.className = 'sync-chip sync-chip-progress';
                    localStorage.removeItem(DISMISS_KEY);
                    setMessage(`Bank sync in progress: ${pct}% (${processed}/${total})`);
                    setBusy(true);
                    return;
                }

                setBusy(false);
                if (data.success === true && data.initiatedByCurrentSession) {
                    wrap.className = 'sync-chip sync-chip-success';
                    setMessage(data.message || 'Bank sync completed.');
                } else if (data.success === false && data.initiatedByCurrentSession) {
                    wrap.className = 'sync-chip sync-chip-error';
                    setMessage(data.message || 'Bank sync failed.');
                } else {
                    setVisible(false);
                }
            })
            .catch(() => {
                // Keep UI stable if polling fails.
            });
    };

    poll();
    setInterval(poll, 5000);
})();
</script>

{{-- Summary Cards --}}
<div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 16px; margin-bottom: 24px;">
    <div class="card" style="background: linear-gradient(135deg, #345262 0%, #5a7585 100%); color: white; text-align: center;">
        <div class="summary-card-value">{{ number_format($totals->total_count) }}</div>
        <div class="summary-card-label">Matching Entries</div>
    </div>
    <div class="card" style="background: linear-gradient(135deg, #e53e3e 0%, #c53030 100%); color: white; text-align: center;">
        @forelse($currencyTotals as $currencyTotal)
            <div class="summary-card-value" style="font-size: 16px; line-height: 1.4; white-space: nowrap;">{{ $formatCurrencyTotal($currencyTotal->total_debits, $currencyTotal->currency) }}</div>
        @empty
            <div class="summary-card-value">—</div>
        @endforelse
        <div class="summary-card-label">Total Debits</div>
    </div>
    <div class="card" style="background: linear-gradient(135deg, #38a169 0%, #2f855a 100%); color: white; text-align: center;">
        @forelse($currencyTotals as $currencyTotal)
            <div class="summary-card-value" style="font-size: 16px; line-height: 1.4; white-space: nowrap;">{{ $formatCurrencyTotal($currencyTotal->total_credits, $currencyTotal->currency) }}</div>
        @empty
            <div class="summary-card-value">—</div>
        @endforelse
        <div class="summary-card-label">Total Credits</div>
    </div>
    <div class="card" style="background: linear-gradient(135deg, #2d6a6a 0%, #234e52 100%); color: white; text-align: center;">
        @forelse($currencyTotals as $currencyTotal)
            @php $netAmount = (float) $currencyTotal->total_credits - (float) $currencyTotal->total_debits; @endphp
            <div class="summary-card-value" style="font-size: 16px; line-height: 1.4; white-space: nowrap;">{{ $formatCurrencyTotal($netAmount, $currencyTotal->currency, true) }}</div>
        @empty
            <div class="summary-card-value">—</div>
        @endforelse
        <div class="summary-card-label">Net Amount</div>
    </div>
    <div class="card" style="background: linear-gradient(135deg, #3182ce 0%, #2c5aa0 100%); color: white; text-align: center;">
        <div class="summary-card-value">{{ $entries->currentPage() }} / {{ $entries->lastPage() }}</div>
        <div class="summary-card-label">Page</div>
    </div>
</div>

{{-- Filters --}}
<div class="card" style="margin-bottom: 20px;">
    <form action="{{ route('bank-entries.index') }}" method="GET">
        @if($selectedStatement)
            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;background:#ebf8ff;border:1px solid #bee3f8;color:#2c5282;border-radius:4px;padding:9px 12px;margin-bottom:12px;font-size:12px;font-weight:600;">
                <span>Viewing transactions for {{ $selectedStatement->account_number }} · {{ ($selectedStatement->closing_balance_date ?? $selectedStatement->opening_balance_date)?->format('Y-m-d') ?? 'statement' }}</span>
                <a href="{{ route('bank-entries.index', ['view' => 'transactions']) }}" style="color:#2b6cb0;white-space:nowrap;">Clear statement</a>
            </div>
            <input type="hidden" name="statement_summary_id" value="{{ $selectedStatement->id }}">
        @endif
        <div style="display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)) auto; gap: 12px; align-items: end;">
            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; color: #4a5568; margin-bottom: 4px;">Date From</label>
                <input type="date" name="date_from" value="{{ request('date_from') }}"
                    style="padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 4px; width: 100%; font-size: 13px;">
            </div>
            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; color: #4a5568; margin-bottom: 4px;">Date To</label>
                <input type="date" name="date_to" value="{{ request('date_to') }}"
                    style="padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 4px; width: 100%; font-size: 13px;">
            </div>
            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; color: #4a5568; margin-bottom: 4px;">Channel</label>
                @php
                    $selectedChannels = array_values(array_filter((array) request('channel', [])));
                    $channelSummary = empty($selectedChannels)
                        ? 'All channels'
                        : (count($selectedChannels) <= 2
                            ? collect($selectedChannels)->map(fn($channel) => ucfirst($channel))->implode(', ')
                            : count($selectedChannels) . ' channels selected');
                @endphp
                <details id="bank-channel-filter" style="position: relative;">
                    <summary style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 10px;border:1px solid #cbd5e0;border-radius:4px;width:100%;font-size:13px;background:#fff;cursor:pointer;box-sizing:border-box;list-style:none;">
                        <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $channelSummary }}</span>
                        <span aria-hidden="true" style="flex:0 0 auto;color:#4a5568;">▾</span>
                    </summary>
                    <div style="position:absolute;left:0;right:0;top:calc(100% + 4px);z-index:40;max-height:280px;overflow-y:auto;padding:6px;background:#fff;border:1px solid #cbd5e0;border-radius:4px;box-shadow:0 8px 20px rgba(0,0,0,.14);">
                        @foreach($channels as $ch)
                            <label style="display:flex;align-items:center;gap:8px;padding:7px 8px;border-radius:3px;cursor:pointer;font-size:13px;color:#2d3748;">
                                <input type="checkbox" name="channel[]" value="{{ $ch }}" @checked(in_array($ch, $selectedChannels, true)) style="accent-color:#2b6cb0;">
                                <span>{{ ucfirst($ch) }}</span>
                            </label>
                        @endforeach
                    </div>
                </details>
            </div>
            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; color: #4a5568; margin-bottom: 4px;">Direction</label>
                <select name="direction" style="padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 4px; width: 100%; font-size: 13px;">
                    <option value="">All</option>
                    <option value="DBIT" @selected(request('direction') === 'DBIT')>Debit</option>
                    <option value="CRDT" @selected(request('direction') === 'CRDT')>Credit</option>
                </select>
            </div>
            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; color: #4a5568; margin-bottom: 4px;">Currency</label>
                <select name="currency" style="padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 4px; width: 100%; font-size: 13px;">
                    <option value="">All currencies</option>
                    @foreach($currencies as $currency)
                        <option value="{{ $currency }}" @selected(request('currency') === $currency)>{{ $currency }}</option>
                    @endforeach
                </select>
            </div>
            <div style="display: flex; gap: 8px;">
                <button type="submit" class="btn" style="padding: 8px 20px; white-space: nowrap;">Filter</button>
                @if(request()->hasAny(['statement_summary_id','date_from','date_to','channel','direction','currency','memo_type','search','sort','sort_dir']))
                    <a href="{{ route('bank-entries.index') }}" class="btn" style="background: #718096; padding: 8px 14px; text-decoration: none;">Clear</a>
                @endif
            </div>
        </div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 12px;">
            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; color: #4a5568; margin-bottom: 4px;">Memo Type</label>
                @php
                    $selectedMemoTypes = array_values(array_filter((array) request('memo_type', [])));
                    $selectedMemoLabels = collect($memoTypes)
                        ->whereIn('value', $selectedMemoTypes)
                        ->pluck('label');
                    $memoTypeSummary = $selectedMemoLabels->isEmpty()
                        ? 'All memo types'
                        : ($selectedMemoLabels->count() <= 2
                            ? $selectedMemoLabels->implode(', ')
                            : $selectedMemoLabels->count() . ' memo types selected');
                @endphp
                <details id="bank-memo-type-filter" style="position: relative;">
                    <summary style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 10px;border:1px solid #cbd5e0;border-radius:4px;width:100%;font-size:13px;background:#fff;cursor:pointer;box-sizing:border-box;list-style:none;">
                        <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $memoTypeSummary }}</span>
                        <span aria-hidden="true" style="flex:0 0 auto;color:#4a5568;">▾</span>
                    </summary>
                    <div style="position:absolute;left:0;right:0;top:calc(100% + 4px);z-index:40;max-height:280px;overflow-y:auto;padding:6px;background:#fff;border:1px solid #cbd5e0;border-radius:4px;box-shadow:0 8px 20px rgba(0,0,0,.14);">
                        @foreach($memoTypes as $memoOption)
                            <label style="display:flex;align-items:center;gap:8px;padding:7px 8px;border-radius:3px;cursor:pointer;font-size:13px;color:#2d3748;">
                                <input type="checkbox" name="memo_type[]" value="{{ $memoOption['value'] }}" @checked(in_array($memoOption['value'], $selectedMemoTypes, true)) style="accent-color:#2b6cb0;">
                                <span style="flex:1;">{{ $memoOption['label'] }}</span>
                                <span style="color:#718096;font-family:monospace;">{{ number_format($memoOption['count']) }}</span>
                            </label>
                        @endforeach
                    </div>
                </details>
            </div>
            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; color: #4a5568; margin-bottom: 4px;">Search (description / counterparty / reference)</label>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Search..."
                    style="padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 4px; width: 100%; font-size: 13px;">
            </div>
        </div>

        {{-- Preserve sort state across filter submissions --}}
        <input type="hidden" name="sort" value="{{ request('sort', 'value_date') }}">
        <input type="hidden" name="sort_dir" value="{{ request('sort_dir', 'desc') }}">
        <input type="hidden" name="view" value="{{ $activeTab }}">
    </form>
</div>

{{-- Results --}}
<div id="bank-results-card" class="card" style="padding-top: 0;">
    <div id="bank-tab-pane-summaries" style="{{ $activeTab === 'summaries' ? '' : 'display:none;' }}">
        @if(($statementSummaryTotals->statement_count ?? 0) > 0)
            @php
                $statementCount = (int) ($statementSummaryTotals->statement_count ?? 0);
                $shownCount = count($statementSummaries);
            @endphp
            <div style="display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap;padding:14px 18px;border-bottom:1px solid #e2e8f0;background:#f8fafc;">
                <div>
                    <div style="font-size:11px;font-weight:800;color:#2c5282;text-transform:uppercase;letter-spacing:.07em;">Statement Date</div>
                    <div style="font-size:20px;font-weight:800;color:#1a365d;line-height:1.15;margin-top:3px;">{{ $dateScopeLabel ?? 'All Statements' }}</div>
                </div>
                @if($hasStatementScope)
                    <div style="text-align:center;min-width:190px;">
                        <div style="font-size:11px;font-weight:800;color:#2c5282;text-transform:uppercase;letter-spacing:.07em;">Net Transaction Total</div>
                        @forelse($currencyTotals as $currencyTotal)
                            @php $headerNet = (float) $currencyTotal->total_credits - (float) $currencyTotal->total_debits; @endphp
                            <div style="font-size:18px;font-weight:800;color:{{ $headerNet < 0 ? '#c53030' : '#2f855a' }};line-height:1.2;margin-top:3px;white-space:nowrap;">{{ $formatCurrencyTotal($headerNet, $currencyTotal->currency, true) }}</div>
                        @empty
                            <div style="font-size:18px;font-weight:800;color:#718096;">—</div>
                        @endforelse
                    </div>
                @else
                    <div></div>
                @endif
                <div style="margin-left:auto;">@include('bank-entries._table-controls')</div>
            </div>

            <div style="padding:16px 18px 12px 18px;">
                <div style="font-size:12px;font-weight:800;color:#4a5568;text-transform:uppercase;letter-spacing:.07em;">Statement Summaries</div>
                <div style="font-size:13px;color:#4a5568;margin-top:4px;">
                    Showing {{ number_format($shownCount) }} of {{ number_format($statementCount) }} matching statements across {{ number_format((int) ($statementSummaryTotals->account_count ?? 0)) }} accounts.
                </div>
                <div style="font-size:12px;color:#718096;margin-top:12px;">
                    Statement values come from CAMT file-level metadata and are shown per matching statement; they are not trimmed to specific transaction subsets inside a statement.
                </div>
            </div>

            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; min-width: 980px;" class="mono-grid">
                    <thead>
                        <tr style="background: #e2e8f0; border-bottom: 2px solid #cbd5e0; white-space: nowrap;">
                            <th style="text-align: left; font-weight: 700; color: #2d3748;"><span style="display:block;">Statement</span><span style="display:block;">Date</span></th>
                            <th style="text-align: left; font-weight: 700; color: #2d3748;">Account</th>
                            <th style="text-align: left; font-weight: 700; color: #2d3748;">CURR</th>
                            <th style="text-align: right; font-weight: 700; color: #2d3748;"><span style="display:block;">Opening</span><span style="display:block;">Balance</span></th>
                            <th style="text-align: right; font-weight: 700; color: #2d3748;"><span style="display:block;">Closing</span><span style="display:block;">Balance</span></th>
                            <th style="text-align: right; font-weight: 700; color: #2d3748;"><span style="display:block;">Credit</span><span style="display:block;">Txns</span></th>
                            <th style="text-align: right; font-weight: 700; color: #2d3748;"><span style="display:block;">Credit</span><span style="display:block;">Summary</span></th>
                            <th style="text-align: right; font-weight: 700; color: #2d3748;"><span style="display:block;">Debit</span><span style="display:block;">Txns</span></th>
                            <th style="text-align: right; font-weight: 700; color: #2d3748;"><span style="display:block;">Debit</span><span style="display:block;">Summary</span></th>
                            <th style="text-align: left; font-weight: 700; color: #2d3748;"><span style="display:block;">Source</span><span style="display:block;">File</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($statementSummaries as $summary)
                            @php
                                $createdAt = $summary->statement_created_at ?? $summary->group_created_at;
                                $statementDate = $summary->closing_balance_date ?? $summary->opening_balance_date;
                                $currency = $summary->closing_balance_currency ?: ($summary->opening_balance_currency ?: 'CAD');
                                $openingAmount = $summary->opening_balance_signed_amount ?? $summary->opening_balance_amount;
                                $closingAmount = $summary->closing_balance_signed_amount ?? $summary->closing_balance_amount;
                                $formatStatementAmount = static function ($amount): string {
                                    $value = (float) $amount;
                                    $formatted = '$' . number_format(abs($value), 2);
                                    return $value < 0 ? '(' . $formatted . ')' : $formatted;
                                };
                                $statementTransactionsUrl = route('bank-entries.index', [
                                    'view' => 'transactions',
                                    'statement_summary_id' => $summary->id,
                                ]);
                            @endphp
                            <tr role="link" tabindex="0" title="View transactions from this statement" onclick="window.location.href='{{ $statementTransactionsUrl }}'" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();window.location.href='{{ $statementTransactionsUrl }}';}" style="cursor:pointer;border-bottom: 1px solid #d9e2ec; background: {{ $loop->even ? 'rgba(56, 161, 105, 0.07)' : 'transparent' }};">
                                <td style="white-space: nowrap; color: #2d3748;">
                                    {{ $statementDate?->format('Y-m-d') ?? ($createdAt?->format('Y-m-d') ?? '—') }}
                                </td>
                                <td style="white-space: nowrap; color: #2d3748;">{{ $summary->account_number ?: '—' }}</td>
                                <td style="white-space: nowrap; color: #2d3748; font-weight:700;">{{ $currency }}</td>
                                <td style="text-align: right; white-space: nowrap; color: #4a5568;">
                                    @if($openingAmount !== null)
                                        {{ $formatStatementAmount($openingAmount) }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td style="text-align: right; white-space: nowrap; color: #4a5568;">
                                    @if($closingAmount !== null)
                                        {{ $formatStatementAmount($closingAmount) }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td style="text-align: right; white-space: nowrap; color: #276749;">
                                    {{ number_format((int) ($summary->total_credit_entries ?? 0)) }}
                                </td>
                                <td style="text-align: right; white-space: nowrap; color: #276749; font-weight:700;">
                                    {{ $formatStatementAmount($summary->total_credit_sum ?? 0) }}
                                </td>
                                <td style="text-align: right; white-space: nowrap; color: #c53030;">
                                    {{ number_format((int) ($summary->total_debit_entries ?? 0)) }}
                                </td>
                                <td style="text-align: right; white-space: nowrap; color: #c53030; font-weight:700;">
                                    {{ $formatStatementAmount(-abs((float) ($summary->total_debit_sum ?? 0))) }}
                                </td>
                                <td style="white-space: nowrap; color: #4a5568;">{{ $summary->source_file }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div style="margin-top: 20px; display: flex; justify-content: center;">
                {{ $statementSummaries->onEachSide(1)->links() }}
            </div>
        @else
            <p style="color: #718096; text-align: center; padding: 26px 0;">No statement summaries match the current filters.</p>
        @endif
    </div>

    <div id="bank-tab-pane-transactions" style="{{ $activeTab === 'transactions' ? '' : 'display:none;' }}">
        @if($entries->count())
            <div style="display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap;padding:14px 18px;border-bottom:1px solid #e2e8f0;background:#f8fafc;">
                <div>
                    <div style="font-size:11px;font-weight:800;color:#2c5282;text-transform:uppercase;letter-spacing:.07em;">Transaction Date</div>
                    <div style="font-size:20px;font-weight:800;color:#1a365d;line-height:1.15;margin-top:3px;">{{ $dateScopeLabel ?? 'All Transaction Dates' }}</div>
                </div>
                <div style="text-align:center;min-width:190px;">
                    <div style="font-size:11px;font-weight:800;color:#2c5282;text-transform:uppercase;letter-spacing:.07em;">Net Transaction Total</div>
                    @forelse($currencyTotals as $currencyTotal)
                        @php $headerNet = (float) $currencyTotal->total_credits - (float) $currencyTotal->total_debits; @endphp
                        <div style="font-size:18px;font-weight:800;color:{{ $headerNet < 0 ? '#c53030' : '#2f855a' }};line-height:1.2;margin-top:3px;white-space:nowrap;">{{ $formatCurrencyTotal($headerNet, $currencyTotal->currency, true) }}</div>
                    @empty
                        <div style="font-size:18px;font-weight:800;color:#718096;">—</div>
                    @endforelse
                </div>
                <div style="margin-left:auto;">@include('bank-entries._table-controls')</div>
            </div>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;" class="mono-grid">
                    <thead>
                        <tr style="background: #f7fafc; border-bottom: 2px solid #e2e8f0; white-space: nowrap;">
                            @php
                                $sort = request('sort', 'value_date');
                                $dir = request('sort_dir', 'desc');
                                $flip = fn($col) => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
                                $arrow = fn($col) => $sort === $col ? ($dir === 'asc' ? ' ↑' : ' ↓') : ' ⇅';
                                $sortUrl = fn($col) => route('bank-entries.index', array_merge(request()->except(['sort','sort_dir','page']), ['sort' => $col, 'sort_dir' => $flip($col)]));
                            @endphp
                            <th style="text-align: left; font-weight: 600; color: #2d3748;">
                                <a href="{{ $sortUrl('value_date') }}" style="color: inherit; text-decoration: none;">Date{{ $arrow('value_date') }}</a>
                            </th>
                            <th style="text-align: left; font-weight: 600; color: #2d3748;">
                                <a href="{{ $sortUrl('account_number') }}" style="color: inherit; text-decoration: none;">Account{{ $arrow('account_number') }}</a>
                            </th>
                            <th style="text-align: left; font-weight: 600; color: #2d3748;">
                                <a href="{{ $sortUrl('inferred_channel') }}" style="color: inherit; text-decoration: none;">Channel{{ $arrow('inferred_channel') }}</a>
                            </th>
                            <th style="text-align: left; font-weight: 600; color: #2d3748;">Dir</th>
                            <th style="text-align: right; font-weight: 600; color: #2d3748;">
                                <a href="{{ $sortUrl('amount') }}" style="color: inherit; text-decoration: none;">Amount{{ $arrow('amount') }}</a>
                            </th>
                            <th style="text-align: left; font-weight: 600; color: #2d3748;">CURR</th>
                            <th style="text-align: left; font-weight: 600; color: #2d3748;">
                                <a href="{{ $sortUrl('memo_type') }}" style="color: inherit; text-decoration: none;"><span style="display:block;">Memo</span><span style="display:block;">Type{{ $arrow('memo_type') }}</span></a>
                            </th>
                            <th style="text-align: left; font-weight: 600; color: #2d3748;">
                                <a href="{{ $sortUrl('counterparty') }}" style="color: inherit; text-decoration: none;">Counterparty{{ $arrow('counterparty') }}</a>
                            </th>
                            <th style="text-align: left; font-weight: 600; color: #2d3748;"><a href="{{ $sortUrl('settlement_number') }}" style="color:inherit;text-decoration:none;">Sequence{{ $arrow('settlement_number') }}</a></th>
                            <th style="text-align: left; font-weight: 600; color: #2d3748;"><span style="display:block;">Wire</span><span style="display:block;">Ref</span></th>
                            <th style="text-align: left; font-weight: 600; color: #2d3748;"><span style="display:block;">Source</span><span style="display:block;">File</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($entries as $entry)
                        @php
                            $isCredit = $entry->credit_debit_indicator === 'CRDT';
                            $channelColors = [
                                'wire' => ['bg' => '#ebf4ff', 'text' => '#2b6cb0'],
                                'transfer' => ['bg' => '#e9d8fd', 'text' => '#553c9a'],
                                'memo' => ['bg' => '#feebc8', 'text' => '#744210'],
                                'deposit' => ['bg' => '#c6f6d5', 'text' => '#22543d'],
                                'eft' => ['bg' => '#fed7e2', 'text' => '#702459'],
                                'payment' => ['bg' => '#e2e8f0', 'text' => '#2d3748'],
                                'cheque' => ['bg' => '#fefcbf', 'text' => '#744210'],
                                'fee' => ['bg' => '#fff5f5', 'text' => '#742a2a'],
                                'interest' => ['bg' => '#e6fffa', 'text' => '#234e52'],
                            ];
                            $ch = $entry->inferred_channel ?? 'other';
                            $chStyle = $channelColors[$ch] ?? ['bg' => '#f7fafc', 'text' => '#4a5568'];
                        @endphp
                        <tr style="border-bottom: 1px solid #e2e8f0; background: {{ $loop->even ? 'rgba(56, 161, 105, 0.07)' : 'transparent' }}">
                            <td style="color: #4a5568; white-space: nowrap;">{{ $entry->value_date?->format('Y-m-d') ?? '—' }}</td>
                            <td style="color: #4a5568; white-space: nowrap;">{{ $entry->account_number ?: '—' }}</td>
                            <td style="">
                                @if($entry->inferred_channel)
                                    <span style="background: {{ $chStyle['bg'] }}; color: {{ $chStyle['text'] }}; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; white-space: nowrap; font-family: monospace;">
                                        {{ strtoupper($entry->inferred_channel) }}
                                    </span>
                                @else
                                    <span style="color: #a0aec0;">—</span>
                                @endif
                            </td>
                            <td style="">
                                <span style="background: {{ $isCredit ? '#c6f6d5' : '#fed7d7' }}; color: {{ $isCredit ? '#22543d' : '#742a2a' }}; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; font-family: monospace;">
                                    {{ $entry->credit_debit_indicator }}
                                </span>
                            </td>
                            <td style="text-align: right;font-weight: 500; color: {{ $isCredit ? '#276749' : '#e53e3e' }}; white-space: nowrap;">
                                @if($isCredit)
                                    ${{ number_format($entry->amount, 2) }}
                                @else
                                    (${{ number_format($entry->amount, 2) }})
                                @endif
                            </td>
                            <td style="color: #2d3748; font-weight: 600; white-space: nowrap;">{{ $entry->currency ?? '—' }}</td>
                            <td style="color: #4a5568; max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="{{ $entry->memo_type }}">{{ $entry->memo_type ?? '—' }}</td>
                            <td style="color: #4a5568; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="{{ $entry->counterparty }}">{{ $entry->counterparty ?? '—' }}</td>
                            <td style="color: #4a5568; white-space: nowrap;">{{ $entry->settlement_number ?? '—' }}</td>
                            <td style="color: #4a5568; white-space: nowrap;">{{ $entry->wire_payment_reference ?? '—' }}</td>
                            <td style="color: #4a5568; white-space: nowrap;">{{ $entry->source_file }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div style="margin-top: 20px; display: flex; justify-content: center;">
                {{ $entries->onEachSide(1)->links() }}
            </div>
        @else
            <p style="color: #718096; text-align: center; padding: 40px 0;">No entries match the current filters.</p>
        @endif
    </div>
</div>
@endsection
