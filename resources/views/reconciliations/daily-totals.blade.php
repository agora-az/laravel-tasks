@extends('layouts.app')

@section('title', 'Daily Totals Comparison')

@php
    $showSyncButtons = filter_var(env('SHOW_SYNC_BUTTONS', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    $showSyncButtons = $showSyncButtons ?? true;
@endphp

@section('content')
<div style="margin: 20px 0;">
    <h2 style="margin: 0;">Daily Totals Comparison</h2>
    <div style="color: #718096; font-size: 13px; margin-top: 4px;">Bank net total vs. cached VieFund daily net total</div>
    <div id="daily-sync-status-wrap" class="sync-chip sync-chip-progress" style="display:none; margin-top:8px; width:max-content; align-items:center; gap:8px;">
        <span id="daily-sync-status"></span>
        <button type="button" id="daily-sync-status-dismiss" aria-label="Dismiss sync status" style="border:none; background:transparent; color:inherit; font-size:14px; font-weight:700; cursor:pointer; line-height:1; padding:0;">×</button>
    </div>
</div>

@if(session('sync_error'))
    <div style="margin-bottom: 16px; padding: 10px 14px; border-radius: 6px; background: #fff5f5; color: #742a2a; border: 1px solid #feb2b2; font-size: 13px;">
        {{ session('sync_error') }}
    </div>
@endif

<script>
document.addEventListener('DOMContentLoaded', function () {
    const badge = document.getElementById('daily-sync-status');
    const badgeWrap = document.getElementById('daily-sync-status-wrap');
    const dismissBtn = document.getElementById('daily-sync-status-dismiss');
    const applyBtn = document.getElementById('daily-apply-btn');
    const resyncBtn = document.getElementById('daily-resync-btn');
    if (!badge || !badgeWrap) return;

    const DISMISS_KEY = 'dailyTotalsSyncDismissedMessage';
    let currentMessage = '';

    const formatEta = (seconds) => {
        if (seconds === null || seconds === undefined || isNaN(seconds)) return 'ETA: calculating...';
        const s = Math.max(0, Number(seconds));
        const h = Math.floor(s / 3600);
        const m = Math.floor((s % 3600) / 60);
        const sec = Math.floor(s % 60);
        if (h > 0) return `ETA: ${h}h ${m}m`;
        if (m > 0) return `ETA: ${m}m ${sec}s`;
        return `ETA: ${sec}s`;
    };

    const formatDateTime = (iso) => {
        if (!iso) return null;
        const dt = new Date(iso);
        if (Number.isNaN(dt.getTime())) return null;
        return dt.toLocaleString([], {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: false,
        });
    };

    const setButtonsBusy = (busy) => {
        [applyBtn, resyncBtn].forEach(btn => {
            if (!btn) return;
            btn.disabled = busy;
            btn.style.opacity = busy ? '0.7' : '1';
            btn.style.cursor = busy ? 'not-allowed' : 'pointer';
        });
    };

    const setBadgeVisible = (visible) => {
        badgeWrap.style.display = visible ? 'inline-flex' : 'none';
    };

    const setMessage = (message) => {
        currentMessage = message;
        badge.textContent = message;
        const dismissed = localStorage.getItem(DISMISS_KEY);
        setBadgeVisible(dismissed !== message);
    };

    if (dismissBtn) {
        dismissBtn.addEventListener('click', () => {
            if (!currentMessage) return;
            localStorage.setItem(DISMISS_KEY, currentMessage);
            setBadgeVisible(false);
        });
    }

    let wasInProgress = false;

    const poll = () => {
        fetch('{{ route('reconciliations.daily-totals.sync-status') }}', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(data => {
                if (data.inProgress) {
                    wasInProgress = true;
                    const pct = data.progress_pct ?? 0;
                    const processed = data.processed_days ?? 0;
                    const total = data.total_days ?? '?';
                    const startedAt = formatDateTime(data.started_at);
                    const updatedAt = formatDateTime(data.updated_at);
                    const extras = [
                        startedAt ? `Started: ${startedAt}` : null,
                        updatedAt ? `Updated: ${updatedAt}` : null,
                    ].filter(Boolean).join(' • ');
                    setBadgeVisible(true);
                    localStorage.removeItem(DISMISS_KEY);
                    badgeWrap.className = 'sync-chip sync-chip-progress';
                    setMessage(`Sync in progress: ${pct}% (${processed}/${total}) • ${formatEta(data.eta_seconds)}${extras ? ` • ${extras}` : ''}`);
                    setButtonsBusy(true);
                } else {
                    setButtonsBusy(false);
                    if (data.success === true && data.completed_at) {
                        const completedAt = formatDateTime(data.completed_at);
                        const updatedAt = formatDateTime(data.updated_at);
                        const suffix = [
                            completedAt ? `Completed: ${completedAt}` : null,
                            updatedAt ? `Updated: ${updatedAt}` : null,
                        ].filter(Boolean).join(' • ');
                        setBadgeVisible(true);
                        badgeWrap.className = 'sync-chip sync-chip-success';
                        setMessage(`${data.message || 'Last sync completed.'}${suffix ? ` • ${suffix}` : ''}`);
                        // A sync we were tracking just finished — reload so the freshly
                        // built variant displays.
                        if (wasInProgress) {
                            wasInProgress = false;
                            setTimeout(() => window.location.reload(), 700);
                        }
                    } else if (data.success === false) {
                        const updatedAt = formatDateTime(data.updated_at);
                        setBadgeVisible(true);
                        badgeWrap.className = 'sync-chip sync-chip-error';
                        setMessage(`${data.message || 'Last sync failed.'}${updatedAt ? ` • Updated: ${updatedAt}` : ''}`);
                    } else {
                        setBadgeVisible(false);
                    }
                }
            })
            .catch(() => {
                // Keep UI stable if polling fails.
            });
    };

    poll();
    setInterval(poll, 5000);
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const cfgEl = document.getElementById('daily-variant-config');
    const form = document.getElementById('daily-variant-form');
    if (!cfgEl || !form) return;

    const cfg = JSON.parse(cfgEl.textContent);
    const applyBtn = document.getElementById('daily-apply-btn');
    const resyncBtn = document.getElementById('daily-resync-btn');

    const sig = (basis, statuses, trust) =>
        JSON.stringify([basis, statuses.map(Number).sort((a, b) => a - b), trust.slice().sort()]);

    const loadedSig = sig(cfg.loadedBasis, cfg.loadedStatuses, cfg.loadedTrust);

    const currentSelection = () => ({
        basis: form.querySelector('[name="date_basis"]').value,
        statuses: Array.from(form.querySelectorAll('input[name="statuses[]"]:checked')).map(el => Number(el.value)),
        trust: Array.from(form.querySelectorAll('input[name="trust_statuses[]"]:checked')).map(el => el.value),
    });

    const refreshButtons = () => {
        // While a sync is running / auto-starting, suppress both actions.
        if (cfg.syncInProgress || cfg.autoSync) {
            if (applyBtn) applyBtn.style.display = 'none';
            if (resyncBtn) resyncBtn.style.display = 'none';
            return;
        }
        const sel = currentSelection();
        const changed = sig(sel.basis, sel.statuses, sel.trust) !== loadedSig;
        // Changed selection → Apply (loads/auto-builds it). Unchanged + cached → Resync.
        if (applyBtn) applyBtn.style.display = (changed || !cfg.cached) ? '' : 'none';
        if (resyncBtn) resyncBtn.style.display = (!changed && cfg.cached) ? '' : 'none';
    };

    form.addEventListener('change', refreshButtons);
    refreshButtons();

    // Never-built combo → start the sync automatically; the poller reloads on completion.
    if (cfg.autoSync && resyncBtn) {
        resyncBtn.click();
    }
});
</script>

@if($showSyncButtons)
    {{-- Apply (GET) loads the selected variant from cache (auto-syncing if it has
         never been built). When the form matches the loaded variant, Apply morphs
         into Resync (POST) to refresh it on demand. --}}
    <form method="GET" action="{{ route('reconciliations.daily-totals') }}" id="daily-variant-form" style="display:flex; gap:16px; align-items:center; justify-content:space-between; flex-wrap:wrap; width:100%; margin:0 0 16px 0; padding:12px 16px; border:1px solid #e2e8f0; border-radius:8px; background:#f7fafc;">
        @csrf
        <input type="hidden" name="date_from" value="{{ $dateFrom }}">
        <input type="hidden" name="date_to" value="{{ $dateTo }}">
        <div style="display:flex; gap:16px; align-items:center; flex-wrap:wrap;">
            <fieldset style="border:1px solid #e2e8f0; border-radius:8px; padding:8px 12px; margin:0;">
                <legend style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; color:#4a5568; padding:0 4px;">Date basis</legend>
                <select name="date_basis" style="font-size:12px; padding:4px 6px; border:1px solid #cbd5e0; border-radius:4px;">
                    @foreach($dateBasisOptions as $key => $label)
                        <option value="{{ $key }}" @selected($selectedBasis === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </fieldset>
            <fieldset style="border:1px solid #e2e8f0; border-radius:8px; padding:8px 12px; margin:0;">
                <legend style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; color:#4a5568; padding:0 4px;">Fund status</legend>
                <div style="display:flex; gap:12px; flex-wrap:wrap; font-size:12px; color:#2d3748;">
                    @foreach($fundStatusOptions as $id => $label)
                        <label style="display:flex; align-items:center; gap:4px; white-space:nowrap; cursor:pointer;">
                            <input type="checkbox" name="statuses[]" value="{{ $id }}" @checked(in_array($id, $selectedStatuses, true))>
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <fieldset style="border:1px solid #e2e8f0; border-radius:8px; padding:8px 12px; margin:0;">
                <legend style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; color:#4a5568; padding:0 4px;">Trust status <span style="font-weight:400; text-transform:none; letter-spacing:0; color:#718096;">(none = excluded)</span></legend>
                <div style="display:flex; gap:12px; flex-wrap:wrap; font-size:12px; color:#2d3748;">
                    @foreach($trustStatusOptions as $name)
                        <label style="display:flex; align-items:center; gap:4px; white-space:nowrap; cursor:pointer;">
                            <input type="checkbox" name="trust_statuses[]" value="{{ $name }}" @checked(in_array($name, $selectedTrustStatuses, true))>
                            {{ $name }}
                        </label>
                    @endforeach
                </div>
            </fieldset>
        </div>
        <div style="display:flex; gap:12px; align-items:center;">
            <div id="daily-last-updated" style="font-size:11px; color:#718096; white-space:nowrap; text-align:right;">
                @if($viefundLastSynced)
                    Last updated:<br>{{ \Carbon\Carbon::parse($viefundLastSynced)->format('M j, Y g:i A') }}
                @elseif($syncInProgress || $autoSync)
                    Syncing…
                @else
                    Not synced
                @endif
            </div>
            <button type="submit" id="daily-apply-btn" class="btn" style="padding:8px 16px;">Apply</button>
            <button type="submit" id="daily-resync-btn" name="resync" value="1"
                    formmethod="post" formaction="{{ route('reconciliations.daily-totals.sync') }}"
                    class="sync-action-pill sync-action-pill-secondary" style="display:none;">↻ Resync data</button>
        </div>
    </form>

    @php
        $dailyVariantConfig = [
            'loadedBasis' => $selectedBasis,
            'loadedStatuses' => array_values($selectedStatuses),
            'loadedTrust' => array_values($selectedTrustStatuses),
            'cached' => (bool) $viefundVariantSynced,
            'autoSync' => (bool) $autoSync,
            'syncInProgress' => (bool) $syncInProgress,
        ];
    @endphp
    <script type="application/json" id="daily-variant-config">{!! json_encode($dailyVariantConfig) !!}</script>
@endif

@if(!$viefundVariantSynced && ($syncInProgress || $autoSync))
    <div style="margin-bottom: 16px; padding: 10px 14px; border-radius: 6px; background: #ebf8ff; color: #2c5282; border: 1px solid #90cdf4; font-size: 13px;">
        Building VieFund totals for this basis / status combination… the page will refresh automatically when it finishes.
    </div>
@elseif(!$viefundVariantSynced)
    <div style="margin-bottom: 16px; padding: 10px 14px; border-radius: 6px; background: #fffaf0; color: #744210; border: 1px solid #f6e05e; font-size: 13px;">
        No VieFund totals are cached for this basis / status combination.
        @if($showSyncButtons) Use <strong>Resync data</strong> to build it. @endif
    </div>
@endif

<div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px;">
    <div class="card" style="background: linear-gradient(135deg, #345262 0%, #5a7585 100%); color: white; text-align: center;">
        <div style="font-size: 28px; font-weight: bold;">{{ number_format($summary['days']) }}</div>
        <div style="font-size: 13px; opacity: 0.9; margin-top: 4px;">Days Compared</div>
    </div>
    <div class="card" style="background: linear-gradient(135deg, #38a169 0%, #2f855a 100%); color: white; text-align: center;">
        <div style="font-size: 24px; font-weight: bold;">{{ '$' . number_format($summary['bank_total'], 2) }}</div>
        <div style="font-size: 13px; opacity: 0.9; margin-top: 4px;">Bank Total</div>
    </div>
    <div class="card" style="background: linear-gradient(135deg, #3182ce 0%, #2c5aa0 100%); color: white; text-align: center;">
        <div style="font-size: 24px; font-weight: bold;">{{ '$' . number_format($summary['viefund_total'], 2) }}</div>
        <div style="font-size: 13px; opacity: 0.9; margin-top: 4px;">VieFund Total</div>
    </div>
    <div class="card" style="background: linear-gradient(135deg, #d53f8c 0%, #97266d 100%); color: white; text-align: center;">
        <div style="font-size: 24px; font-weight: bold;">{{ '$' . number_format($summary['variance_total'], 2) }}</div>
        <div style="font-size: 13px; opacity: 0.9; margin-top: 4px;">Variance</div>
    </div>
</div>

<div class="card" style="margin-bottom: 20px;">
    <form method="GET" action="{{ route('reconciliations.daily-totals') }}" style="display: grid; grid-template-columns: 1fr 1fr auto; gap: 12px; align-items: end;">
        {{-- Preserve the active variant selection when filtering by date. --}}
        <input type="hidden" name="date_basis" value="{{ $selectedBasis }}">
        @foreach($selectedStatuses as $sid)
            <input type="hidden" name="statuses[]" value="{{ $sid }}">
        @endforeach
        @foreach($selectedTrustStatuses as $tname)
            <input type="hidden" name="trust_statuses[]" value="{{ $tname }}">
        @endforeach
        <div>
            <label style="display: block; font-size: 12px; font-weight: 600; color: #4a5568; margin-bottom: 4px;">Date From</label>
            <input type="date" name="date_from" value="{{ $dateFrom }}" style="padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 4px; width: 100%;">
        </div>
        <div>
            <label style="display: block; font-size: 12px; font-weight: 600; color: #4a5568; margin-bottom: 4px;">Date To</label>
            <input type="date" name="date_to" value="{{ $dateTo }}" style="padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 4px; width: 100%;">
        </div>
        <div style="display: flex; gap: 8px;">
            <button type="submit" class="btn" style="padding: 8px 18px;">Filter</button>
            <a href="{{ route('reconciliations.daily-totals') }}" class="btn" style="background: #718096; padding: 8px 18px; text-decoration: none;">Clear</a>
        </div>

        <div style="grid-column: 1 / -1; margin-top: 4px; display: flex; align-items: center; gap: 8px;">
            <input type="checkbox" id="show_zero_days" name="show_zero_days" value="1" {{ $showZeroDays ? 'checked' : '' }}>
            <label for="show_zero_days" style="font-size: 13px; color: #4a5568; cursor: pointer;">
                Include bank holidays and $0 transaction days
            </label>
        </div>

        <div style="grid-column: 1 / -1; margin-top: 2px; display: flex; align-items: center; gap: 8px;">
            <input type="checkbox" id="only_fundserv_bank" name="only_fundserv_bank" value="1" {{ $onlyFundservBank ? 'checked' : '' }}>
            <label for="only_fundserv_bank" style="font-size: 13px; color: #4a5568; cursor: pointer;">
                Only include Fundserv bank transactions (counterparty contains "fundserv")
            </label>
        </div>

        <div style="grid-column: 1 / -1; margin-top: 2px; display: flex; align-items: center; gap: 8px;">
            <input type="checkbox" id="include_incomplete" name="include_incomplete" value="1" {{ $includeIncomplete ? 'checked' : '' }}>
            <label for="include_incomplete" style="font-size: 13px; color: #4a5568; cursor: pointer;">
                Include incomplete datasets (missing bank or VieFund)
            </label>
        </div>

        <input type="hidden" name="sort" value="{{ $sortField }}">
        <input type="hidden" name="sort_dir" value="{{ $sortDir }}">
        <input type="hidden" name="per_page" value="{{ $perPage }}">
    </form>
</div>

<div class="card">
    <div style="margin-bottom: 12px; display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap;">
        <div style="color: #4a5568; font-size: 13px;">
            {{ $summary['mismatch_days'] }} day(s) with a non-zero variance.
        </div>
        <div style="margin-left: auto; max-width: 760px; width: 100%; display: flex; flex-direction: column; align-items: flex-end; text-align: right;">
        <details style="display: block; width: 100%; text-align: right;">
            <summary title="Calculating VieFund purchase or redemption cash transactions that are confirmed. {{ $onlyFundservBank ? 'Calculating only bank transactions where counterparty contains fundserv.' : 'Calculating all bank transactions.' }}" style="cursor: pointer; color: #2c5282; font-size: 13px; font-weight: 600; text-decoration: underline;">
                Transaction Criteria
            </summary>
            <div style="margin-top: 8px; color: #4a5568; font-size: 13px; line-height: 1.5; text-align: left; display: inline-block;">
                <ul style="margin: 0; padding-left: 20px;">
                    <li>Calculating VieFund purchase or redemption cash transactions that are confirmed.</li>
                    <li>
                        @if($onlyFundservBank)
                            Calculating only bank transactions where counterparty contains "fundserv".
                        @else
                            Calculating all bank transactions.
                        @endif
                    </li>
                </ul>
            </div>
        </details>
        </div>
    </div>

    @if($rows->count())
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse;" class="mono-grid">
                <thead>
                    <tr style="background: #f7fafc; border-bottom: 2px solid #e2e8f0; white-space: nowrap;">
                        @php
                            $baseQuery = request()->except(['sort', 'sort_dir', 'page']);
                            $sortUrl = fn(string $field) => route('reconciliations.daily-totals', array_merge($baseQuery, [
                                'sort' => $field,
                                'sort_dir' => ($sortField === $field && $sortDir === 'desc') ? 'asc' : 'desc',
                            ]));
                            $sortArrow = fn(string $field) => $sortField === $field ? ($sortDir === 'asc' ? ' ↑' : ' ↓') : '';
                        @endphp
                        <th style="text-align: left; font-weight: 600; color: #2d3748;">
                            <a href="{{ $sortUrl('total_date') }}" style="color: inherit; text-decoration: none;">Date{{ $sortArrow('total_date') }}</a>
                        </th>
                        <th style="text-align: right; font-weight: 600; color: #2d3748;">Bank Count</th>
                        <th style="text-align: right; font-weight: 600; color: #2d3748;">
                            <a href="{{ $sortUrl('bank_net_total') }}" style="color: inherit; text-decoration: none;">Bank Net{{ $sortArrow('bank_net_total') }}</a>
                        </th>
                        <th style="text-align: right; font-weight: 600; color: #2d3748;">VieFund Count</th>
                        <th style="text-align: right; font-weight: 600; color: #2d3748;">
                            <a href="{{ $sortUrl('viefund_net_total') }}" style="color: inherit; text-decoration: none;">VieFund Net{{ $sortArrow('viefund_net_total') }}</a>
                        </th>
                        <th style="text-align: right; font-weight: 600; color: #2d3748;">
                            <a href="{{ $sortUrl('variance') }}" style="color: inherit; text-decoration: none;">Variance{{ $sortArrow('variance') }}</a>
                        </th>
                        <th style="text-align: right; font-weight: 600; color: #2d3748;">
                            <a href="{{ $sortUrl('discrepancy_pct') }}" style="color: inherit; text-decoration: none;">Discrepancy %{{ $sortArrow('discrepancy_pct') }}</a>
                        </th>
                        <th style="text-align: left; font-weight: 600; color: #2d3748;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $row)
                        @php
                            $statusStyles = [
                                'match' => ['bg' => '#c6f6d5', 'text' => '#22543d', 'label' => 'Match'],
                                'bank-higher' => ['bg' => '#fed7d7', 'text' => '#742a2a', 'label' => 'Bank higher'],
                                'viefund-higher' => ['bg' => '#bee3f8', 'text' => '#2c5282', 'label' => 'VieFund higher'],
                                'missing-bank' => ['bg' => '#fef3c7', 'text' => '#92400e', 'label' => 'Missing bank'],
                                'missing-viefund' => ['bg' => '#fde68a', 'text' => '#78350f', 'label' => 'Missing VieFund'],
                            ];
                            $style = $statusStyles[$row['status']] ?? $statusStyles['match'];
                        @endphp
                        <tr style="border-bottom: 1px solid #e2e8f0; background: {{ $loop->even ? 'rgba(56, 161, 105, 0.07)' : 'transparent' }}">
                            <td style="color: #4a5568; white-space: nowrap; line-height: 1.2;">
                                <span style="display: block;">{{ $row['total_date'] }}</span>
                                <span style="display: block; opacity: 0.9;">00:00</span>
                            </td>
                            <td style="text-align: right;color: #4a5568;">{{ number_format($row['bank_transaction_count']) }}</td>
                            <td style="text-align: right;font-weight: 500; color: {{ $row['bank_net_total'] < 0 ? '#e53e3e' : '#276749' }};">
                                <a href="{{ route('reconciliations.daily-totals.bank-day', ['date' => $row['total_date'], 'only_fundserv_bank' => $onlyFundservBank ? 1 : 0]) }}" target="_blank" rel="noopener noreferrer" style="color: inherit; text-decoration: underline;">
                                    {{ $row['bank_net_total'] < 0 ? '($'.number_format(abs($row['bank_net_total']),2).')' : '$'.number_format($row['bank_net_total'],2) }}
                                </a>
                            </td>
                            <td style="text-align: right;color: #4a5568;">{{ number_format($row['viefund_transaction_count']) }}</td>
                            <td style="text-align: right;font-weight: 500; color: {{ $row['viefund_net_total'] < 0 ? '#e53e3e' : '#276749' }};">
                                <a href="{{ route('reconciliations.daily-totals.viefund-day', ['date' => $row['total_date'], 'variant' => $variantKey]) }}" target="_blank" rel="noopener noreferrer" style="color: inherit; text-decoration: underline;">
                                    {{ $row['viefund_net_total'] < 0 ? '($'.number_format(abs($row['viefund_net_total']),2).')' : '$'.number_format($row['viefund_net_total'],2) }}
                                </a>
                            </td>
                            <td style="text-align: right;font-weight: 500; color: {{ abs($row['variance']) < 0.01 ? '#276749' : '#e53e3e' }};">
                                <a href="{{ route('reconciliations.daily-totals.variance-day', ['date' => $row['total_date'], 'only_fundserv_bank' => $onlyFundservBank ? 1 : 0]) }}" target="_blank" rel="noopener noreferrer" style="color: inherit; text-decoration: underline;">
                                    {{ $row['variance'] < 0 ? '($'.number_format(abs($row['variance']),2).')' : '$'.number_format($row['variance'],2) }}
                                </a>
                            </td>
                            <td style="text-align: right;color: {{ $row['discrepancy_pct'] === null ? '#718096' : '#4a5568' }};">
                                {{ $row['discrepancy_pct'] === null ? 'N/A' : number_format($row['discrepancy_pct'], 2) . '%' }}
                            </td>
                            <td style="">
                                <span style="background: {{ $style['bg'] }}; color: {{ $style['text'] }}; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; white-space: nowrap;">{{ $style['label'] }}</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($rows->hasPages())
            <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; margin-top:14px; flex-wrap:wrap;">
                <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                    <div style="font-size:12px; color:#718096;">
                        Showing {{ $rows->firstItem() }} to {{ $rows->lastItem() }} of {{ $rows->total() }} day(s)
                    </div>
                    <form method="GET" action="{{ route('reconciliations.daily-totals') }}" style="display:flex; align-items:center; gap:6px; margin:0;">
                        @foreach(request()->except(['per_page', 'page']) as $key => $value)
                            @if(is_array($value))
                                @foreach($value as $item)
                                    <input type="hidden" name="{{ $key }}[]" value="{{ $item }}">
                                @endforeach
                            @else
                                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                            @endif
                        @endforeach
                        <label for="footer_per_page" style="font-size:12px; color:#4a5568;">Rows per page</label>
                        <select id="footer_per_page" name="per_page" onchange="this.form.submit()" style="padding:4px 8px; border:1px solid #cbd5e0; border-radius:4px; font-size:12px; color:#2d3748; background:#fff;">
                            @foreach([25, 50, 100, 250] as $size)
                                <option value="{{ $size }}" {{ (int) $perPage === $size ? 'selected' : '' }}>{{ $size }}</option>
                            @endforeach
                        </select>
                    </form>
                </div>
                <div style="display:flex; gap:8px; align-items:center;">
                    @php
                        $current = $rows->currentPage();
                        $last = $rows->lastPage();
                        $window = 2;
                        $startPage = max(1, $current - $window);
                        $endPage = min($last, $current + $window);
                    @endphp

                    @if($rows->onFirstPage())
                        <span style="padding:6px 12px; border:1px solid #e2e8f0; border-radius:4px; color:#a0aec0; background:#f7fafc;">First</span>
                    @else
                        <a href="{{ $rows->url(1) }}" style="padding:6px 12px; border:1px solid #cbd5e0; border-radius:4px; color:#2d3748; text-decoration:none; background:#fff;">First</a>
                    @endif

                    @if($rows->onFirstPage())
                        <span style="padding:6px 12px; border:1px solid #e2e8f0; border-radius:4px; color:#a0aec0; background:#f7fafc;">Previous</span>
                    @else
                        <a href="{{ $rows->previousPageUrl() }}" style="padding:6px 12px; border:1px solid #cbd5e0; border-radius:4px; color:#2d3748; text-decoration:none; background:#fff;">Previous</a>
                    @endif

                    @if($startPage > 1)
                        <span style="padding:6px 8px; color:#718096;">...</span>
                    @endif

                    @for($page = $startPage; $page <= $endPage; $page++)
                        @if($page === $current)
                            <span style="padding:6px 10px; border:1px solid #2d3748; border-radius:4px; color:#fff; background:#2d3748; font-weight:600; min-width:36px; text-align:center;">{{ $page }}</span>
                        @else
                            <a href="{{ $rows->url($page) }}" style="padding:6px 10px; border:1px solid #cbd5e0; border-radius:4px; color:#2d3748; text-decoration:none; background:#fff; min-width:36px; text-align:center;">{{ $page }}</a>
                        @endif
                    @endfor

                    @if($endPage < $last)
                        <span style="padding:6px 8px; color:#718096;">...</span>
                    @endif

                    @if($rows->hasMorePages())
                        <a href="{{ $rows->nextPageUrl() }}" style="padding:6px 12px; border:1px solid #cbd5e0; border-radius:4px; color:#2d3748; text-decoration:none; background:#fff;">Next</a>
                    @else
                        <span style="padding:6px 12px; border:1px solid #e2e8f0; border-radius:4px; color:#a0aec0; background:#f7fafc;">Next</span>
                    @endif

                    @if($rows->currentPage() === $rows->lastPage())
                        <span style="padding:6px 12px; border:1px solid #e2e8f0; border-radius:4px; color:#a0aec0; background:#f7fafc;">Last</span>
                    @else
                        <a href="{{ $rows->url($rows->lastPage()) }}" style="padding:6px 12px; border:1px solid #cbd5e0; border-radius:4px; color:#2d3748; text-decoration:none; background:#fff;">Last</a>
                    @endif
                </div>
            </div>
        @endif
    @else
        <p style="color: #718096; text-align: center; padding: 40px 0;">No daily totals found in the selected range.</p>
    @endif
</div>
@endsection
