@extends('layouts.app')

@section('title', 'Reports')

@section('content')
@php
    $todayDate = now()->toDateString();
    $nowDateTimeLocal = now()->format('Y-m-d\TH:i');
@endphp
<style>
    .reports-page {
        --report-accent: #0f766e;
        --report-accent-dark: #115e59;
        --report-accent-soft: #ecfdf5;
        --report-border: #cbd5e1;
        --report-muted: #64748b;
    }
    .report-card {
        border: 1px solid #dbe5e3;
        border-top: 4px solid var(--report-accent);
        box-shadow: 0 4px 14px rgba(15, 118, 110, 0.08);
    }
    .report-card input:not([type="checkbox"]),
    .report-card select {
        border-color: var(--report-border) !important;
        background: #fff;
        color: #1f2937;
    }
    .report-card input:focus,
    .report-card select:focus {
        outline: 2px solid #99f6e4;
        outline-offset: 1px;
        border-color: var(--report-accent) !important;
    }
    .report-card input[type="checkbox"] {
        accent-color: var(--report-accent);
    }
    .report-options {
        border-color: #dbe5e3 !important;
        background: #f7fbfa !important;
    }
    .report-action-button {
        background: var(--report-accent) !important;
        border-color: var(--report-accent) !important;
        color: #fff !important;
    }
    .report-action-button:hover {
        background: var(--report-accent-dark) !important;
    }
    .report-action-button:disabled {
        cursor: wait !important;
        opacity: 0.65;
    }
    .report-feedback {
        margin: 14px 0;
        padding: 12px 14px;
        border: 1px solid #99f6e4;
        border-radius: 6px;
        background: var(--report-accent-soft);
    }
    .report-feedback .sync-chip {
        margin: 0 !important;
    }
    .report-progress-track {
        background: #dbe5e3 !important;
    }
    .report-progress-bar {
        background: var(--report-accent) !important;
        transition: width 180ms ease;
    }
    .report-local-notice {
        margin: 12px 0;
        padding: 10px 12px;
        border-radius: 6px;
        border: 1px solid #99f6e4;
        background: var(--report-accent-soft);
        color: var(--report-accent-dark);
        font-size: 13px;
    }
    .report-local-notice.is-error {
        border-color: #fecaca;
        background: #fef2f2;
        color: #991b1b;
    }
    .snapshot-monitor-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.4fr) minmax(320px, 0.8fr);
        gap: 18px;
    }
    @media (max-width: 900px) {
        .snapshot-monitor-grid {
            grid-template-columns: minmax(0, 1fr);
        }
    }
</style>
<div class="reports-page">
<div style="display: flex; justify-content: space-between; align-items: center; margin: 20px 0;">
    <div>
        <h2 style="margin: 0;">Reports</h2>
        <div style="color: #718096; font-size: 13px; margin-top: 4px;">Export-ready reporting tools</div>
    </div>
</div>

<div class="card report-card" style="margin-bottom: 20px;">
    <div style="font-size: 16px; font-weight: 700; color: #2d3748; margin-bottom: 6px;">VieFund Daily Net + Running Balance</div>
    <div style="font-size: 13px; color: #4a5568; margin-bottom: 14px;">
        Builds day-by-day net cash transactions and an opening-to-closing running balance from the direct VieFund cash transaction ledger.
    </div>

    @if(session('report_success'))
        <div class="report-local-notice">{{ session('report_success') }}</div>
        <script>window.__reportJustStarted = true;</script>
    @endif
    @if(session('report_error'))
        <div class="report-local-notice is-error">{{ session('report_error') }}</div>
    @endif

    <div id="report-feedback" class="report-feedback" style="display:none;">
        <div id="report-run-status-wrap" class="sync-chip sync-chip-progress" style="display:none; width:max-content; align-items:center; gap:8px;">
            <span id="report-run-status-text"></span>
            <button type="button" id="report-run-status-dismiss" aria-label="Dismiss report status" style="border:none; background:transparent; color:inherit; font-size:14px; font-weight:700; cursor:pointer; line-height:1; padding:0;">×</button>
        </div>
        <div id="report-run-progress-wrap" style="display:none; margin-top:10px; max-width: 760px;">
            <div class="report-progress-track" style="height: 8px; border-radius: 999px; overflow: hidden;">
                <div id="report-run-progress-bar" class="report-progress-bar" style="height: 100%; width: 0%;"></div>
            </div>
            <div id="report-run-progress-meta" style="margin-top: 6px; font-size: 12px; color: #4a5568;"></div>
        </div>
        <div id="report-download-wrap" style="display:none; margin-top: 10px;">
            <a id="report-download-link" href="#" style="display: inline-flex; align-items: center; gap: 8px; color: #0f766e; font-size: 13px; font-weight: 700; text-decoration: none;">
                <span>↓ Download again</span>
            </a>
        </div>
    </div>

    <form method="POST" action="{{ route('reports.viefund-daily-balance.run') }}" id="viefund-report-form" data-inception-dates='@json($inceptionDates)'>
        @csrf
        @php
            $reportSelectedStatuses = array_values(array_filter(
                array_map('intval', (array) old('status', $selectedStatuses ?? [6])),
                fn($id) => array_key_exists($id, $fundStatusOptions ?? [])
            ));
            if (empty($reportSelectedStatuses)) {
                $reportSelectedStatuses = [6];
            }
            $dailyBalanceOpenedBeforeRaw = (string) old('daily_balance_opened_before', $dailyBalanceOpenedBefore ?? '');
            $dailyBalanceOpenedBeforeInput = '';
            if ($dailyBalanceOpenedBeforeRaw !== '') {
                try {
                    $dailyBalanceOpenedBeforeInput = \Carbon\Carbon::parse($dailyBalanceOpenedBeforeRaw)->format('Y-m-d\TH:i');
                } catch (\Throwable) {
                    $dailyBalanceOpenedBeforeInput = '';
                }
            }
        @endphp
        <div style="display: flex; gap: 12px; align-items: flex-start; flex-wrap: wrap;">
            <div style="flex: 1 1 820px; min-width: 0;">
                <div style="display: grid; grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1fr); gap: 12px; align-items: end;">
                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 600; color: #4a5568; margin-bottom: 4px;">Start Date</label>
                        <input type="date" id="report-date-from" name="date_from" value="{{ $dateFrom }}" max="{{ $todayDate }}" required style="padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 4px; width: 100%; font-size: 13px;">
                    </div>

                    <div>
                        <label id="inception-date-note" style="display: block; font-size: 12px; font-weight: 400; color: #718096; margin-bottom: 4px;"></label>
                        <button type="button" id="set-inception-date-btn" style="width: 100%; height: 35px; padding: 0 10px; border: 1px solid #cbd5e0; border-radius: 4px; background: #e2e8f0; color: #2d3748; white-space: nowrap; font-size: 13px; font-weight: 500; line-height: 1; text-align: left; cursor: pointer; box-sizing: border-box;">&laquo; Use Inception Date</button>
                    </div>

                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 600; color: #4a5568; margin-bottom: 4px;">End Date</label>
                        <input type="date" name="date_to" value="{{ $dateTo }}" max="{{ $todayDate }}" required style="padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 4px; width: 100%; font-size: 13px;">
                    </div>

                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 600; color: #4a5568; margin-bottom: 4px;">Date Basis</label>
                        <select id="report-date-basis" name="date_basis" required style="padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 4px; width: 100%; font-size: 13px;">
                            @foreach($dateBasisOptions as $key => $label)
                                <option value="{{ $key }}" @selected($selectedDateBasis === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 600; color: #4a5568; margin-bottom: 4px;">Output Order</label>
                        <select name="output_order" required style="padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 4px; width: 100%; font-size: 13px;">
                            @foreach($outputOrderOptions as $key => $label)
                                <option value="{{ $key }}" @selected($selectedOutputOrder === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 2fr); gap: 12px; align-items: end; margin-top: 10px;">
                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 600; color: #4a5568; margin-bottom: 4px;">Currency Code</label>
                        <select name="daily_balance_currency_code" style="padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 4px; width: 100%; font-size: 13px;">
                            <option value="CAD" @selected(old('daily_balance_currency_code', $dailyBalanceCurrencyLabel ?? 'CAD') === 'CAD')>CAD</option>
                            <option value="USD" @selected(old('daily_balance_currency_code', $dailyBalanceCurrencyLabel ?? 'CAD') === 'USD')>USD</option>
                        </select>
                    </div>

                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 600; color: #4a5568; margin-bottom: 4px;">Simulated Report Generation Time (optional)</label>
                        <input
                            type="datetime-local"
                            name="daily_balance_opened_before"
                            max="{{ $nowDateTimeLocal }}"
                            value="{{ $dailyBalanceOpenedBeforeInput }}"
                            style="padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 4px; width: 100%; font-size: 13px;"
                        >
                    </div>
                </div>

                <div class="report-options" style="margin-top: 10px; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 6px; background: #f7fafc; width: 100%;">
                    <div>
                        <div>
                            <div style="font-size: 12px; font-weight: 700; color: #4a5568; margin-bottom: 6px;">Cash Transaction Status</div>
                            <div style="display: flex; flex-wrap: wrap; gap: 14px; font-size: 12px; color: #2d3748;">
                                @foreach($fundStatusOptions as $value => $label)
                                    <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer;">
                                        <input type="checkbox" name="status[]" value="{{ $value }}" {{ in_array($value, $reportSelectedStatuses, true) ? 'checked' : '' }}>
                                        <span>{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <div style="display: flex; gap: 8px; justify-content: flex-end; flex: 0 0 auto; padding-top: 30px;">
                <input type="hidden" name="format" value="csv" id="report-format-input">
                <div style="position: relative;" id="report-run-wrap">
                    <button type="button" id="report-run-btn" class="report-action-button"
                            style="background: #fff; border: 1px solid #90cdf4; color: #2b6cb0; border-radius: 6px; padding: 5px 14px; font-size: 13px; font-weight: 600; cursor: pointer; white-space: nowrap; display: inline-flex; align-items: center; gap: 8px;">
                        <span>Run Report</span>
                        <span>▾</span>
                    </button>
                    <div id="report-run-panel" style="display: none; position: absolute; right: 0; top: calc(100% + 6px); z-index: 30; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 4px 16px rgba(0,0,0,0.12); min-width: 160px; overflow: hidden;">
                        <button type="button" data-format="csv" class="report-format-option" style="display: block; width: 100%; text-align: left; padding: 10px 16px; font-size: 13px; font-weight: 600; color: #2b6cb0; background: #fff; border: none; border-bottom: 1px solid #f0f4f8; cursor: pointer;"
                                onmouseover="this.style.background='#ebf8ff'" onmouseout="this.style.background=''">
                            <span style="display:inline-flex; align-items:center; gap:8px;">
                                <svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true" focusable="false" style="flex:0 0 auto;">
                                    <path d="M3 1.75h6l3.5 3.5V14.25H3V1.75Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
                                    <path d="M9 1.75V5.25H12.5" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
                                    <path d="M5 9.25h6M5 11.75h6" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                                </svg>
                                <span>CSV</span>
                            </span>
                        </button>
                        <button type="button" data-format="excel" class="report-format-option" style="display: block; width: 100%; text-align: left; padding: 10px 16px; font-size: 13px; font-weight: 600; color: #276749; background: #fff; border: none; cursor: pointer;"
                                onmouseover="this.style.background='#f0fff4'" onmouseout="this.style.background=''">
                            <span style="display:inline-flex; align-items:center; gap:8px;">
                                <svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true" focusable="false" style="flex:0 0 auto;">
                                    <path d="M3 1.75h6l3.5 3.5V14.25H3V1.75Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
                                    <path d="M9 1.75V5.25H12.5" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
                                    <path d="M4.75 9.25h6.5M4.75 11.75h6.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                                    <path d="M6 7.75h4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                                </svg>
                                <span>Excel</span>
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<div class="card report-card" style="margin-bottom:20px; border-left:4px solid #b7791f;">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; margin-bottom:6px;">
        <div style="font-size:16px; font-weight:700; color:#2d3748;">Legacy VieFund Daily Net + Running Balance</div>
        <span style="font-size:11px; font-weight:700; color:#744210; background:#fefcbf; border:1px solid #f6e05e; border-radius:4px; padding:3px 7px;">COMPARISON</span>
    </div>
    <div style="font-size:13px; color:#4a5568; margin-bottom:14px;">
        Reproduces the previous live fund + trust reconstruction. The running balance starts at zero on the selected start date and does not use cash snapshots.
    </div>

    @if(session('legacy_report_error'))
        <div class="report-local-notice is-error">{{ session('legacy_report_error') }}</div>
    @endif
    <div id="legacy-report-feedback" class="report-feedback" style="display:none; margin-bottom:12px;">
        <div id="legacy-report-status-wrap" class="sync-chip sync-chip-progress" style="display:none; width:max-content; align-items:center; gap:8px;">
            <span id="legacy-report-status-text"></span>
            <button type="button" id="legacy-report-status-dismiss" aria-label="Dismiss legacy report status" style="border:none; background:transparent; color:inherit; font-size:14px; font-weight:700; cursor:pointer; line-height:1; padding:0;">×</button>
        </div>
        <div id="legacy-report-progress-wrap" style="display:none; margin-top:10px; max-width:760px;">
            <div class="report-progress-track" style="height:8px; border-radius:999px; overflow:hidden;">
                <div id="legacy-report-progress-bar" class="report-progress-bar" style="height:100%; width:0%;"></div>
            </div>
            <div id="legacy-report-progress-meta" style="margin-top:6px; font-size:12px; color:#4a5568;"></div>
        </div>
        <div id="legacy-report-download-wrap" style="display:none; margin-top:10px;">
            <a id="legacy-report-download-link" href="#" style="display:inline-flex; color:#0f766e; font-size:13px; font-weight:700; text-decoration:none;">↓ Download again</a>
        </div>
    </div>

    <form method="POST" action="{{ route('reports.viefund-daily-balance-legacy.run') }}" id="legacy-viefund-report-form" data-inception-dates='@json($legacyInceptionDates)'>
        @csrf
        @php
            $legacySelectedStatuses = array_values(array_filter(
                array_map('intval', (array) old('legacy_status', [6])),
                fn($id) => array_key_exists($id, $fundStatusOptions ?? [])
            ));
            if (empty($legacySelectedStatuses)) {
                $legacySelectedStatuses = [6];
            }
            $legacySelectedTrustStatuses = array_values(array_intersect(
                $trustStatusOptions ?? [],
                (array) old('legacy_trust_status', ['Settled'])
            ));
        @endphp

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:12px; align-items:end;">
            <div>
                <label style="display:block; font-size:12px; font-weight:600; color:#4a5568; margin-bottom:4px;">Start Date</label>
                <input type="date" id="legacy-report-date-from" name="legacy_date_from" value="{{ old('legacy_date_from', $dateFrom) }}" max="{{ $todayDate }}" required style="padding:8px 10px; border:1px solid #cbd5e0; border-radius:4px; width:100%; font-size:13px;">
            </div>
            <div>
                <label id="legacy-inception-date-note" style="display:block; font-size:12px; font-weight:400; color:#718096; margin-bottom:4px;"></label>
                <button type="button" id="legacy-set-inception-date-btn" style="width:100%; height:35px; padding:0 10px; border:1px solid #cbd5e0; border-radius:4px; background:#e2e8f0; color:#2d3748; white-space:nowrap; font-size:13px; font-weight:500; line-height:1; text-align:left; cursor:pointer; box-sizing:border-box;">&laquo; Use Inception Date</button>
            </div>
            <div>
                <label style="display:block; font-size:12px; font-weight:600; color:#4a5568; margin-bottom:4px;">End Date</label>
                <input type="date" name="legacy_date_to" value="{{ old('legacy_date_to', $dateTo) }}" max="{{ $todayDate }}" required style="padding:8px 10px; border:1px solid #cbd5e0; border-radius:4px; width:100%; font-size:13px;">
            </div>
            <div>
                <label style="display:block; font-size:12px; font-weight:600; color:#4a5568; margin-bottom:4px;">Date Basis</label>
                <select id="legacy-report-date-basis" name="legacy_date_basis" required style="padding:8px 10px; border:1px solid #cbd5e0; border-radius:4px; width:100%; font-size:13px;">
                    @foreach($dateBasisOptions as $key => $label)
                        <option value="{{ $key }}" @selected(old('legacy_date_basis', $selectedDateBasis) === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label style="display:block; font-size:12px; font-weight:600; color:#4a5568; margin-bottom:4px;">Output Order</label>
                <select name="legacy_output_order" required style="padding:8px 10px; border:1px solid #cbd5e0; border-radius:4px; width:100%; font-size:13px;">
                    @foreach($outputOrderOptions as $key => $label)
                        <option value="{{ $key }}" @selected(old('legacy_output_order', $selectedOutputOrder) === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="report-options" style="margin-top:10px; padding:10px 12px; border:1px solid #e2e8f0; border-radius:6px; background:#f7fafc;">
            <div style="display:grid; grid-template-columns:2fr 1fr; gap:16px;">
                <div>
                    <div style="font-size:12px; font-weight:700; color:#4a5568; margin-bottom:6px;">Fund Status</div>
                    <div style="display:flex; flex-wrap:wrap; gap:14px; font-size:12px; color:#2d3748;">
                        @foreach($fundStatusOptions as $value => $label)
                            <label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer;">
                                <input type="checkbox" name="legacy_status[]" value="{{ $value }}" {{ in_array($value, $legacySelectedStatuses, true) ? 'checked' : '' }}>
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
                <div>
                    <div style="font-size:12px; font-weight:700; color:#4a5568; margin-bottom:6px;">Trust Status</div>
                    <div style="display:flex; flex-wrap:wrap; gap:14px; font-size:12px; color:#2d3748;">
                        @foreach($trustStatusOptions as $label)
                            <label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer;">
                                <input type="checkbox" name="legacy_trust_status[]" value="{{ $label }}" {{ in_array($label, $legacySelectedTrustStatuses, true) ? 'checked' : '' }}>
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:12px;">
            <input type="hidden" name="legacy_format" value="csv" id="legacy-report-format-input">
            <button type="button" data-legacy-format="csv" class="legacy-report-run-button report-action-button" style="border:1px solid #90cdf4; border-radius:6px; padding:7px 12px; font-size:13px; font-weight:600; cursor:pointer;">Run CSV</button>
            <button type="button" data-legacy-format="excel" class="legacy-report-run-button report-action-button" style="border:1px solid #9ae6b4; border-radius:6px; padding:7px 12px; font-size:13px; font-weight:600; cursor:pointer;">Run Excel</button>
        </div>
    </form>
</div>

<div class="card report-card" style="margin-bottom: 20px;">
    <div style="font-size: 16px; font-weight: 700; color: #2d3748; margin-bottom: 6px;">VieFund Customer Balances</div>
    <div style="font-size: 13px; color: #4a5568; margin-bottom: 14px;">
        Builds a plan-account cash balance for a selected day from the direct VieFund cash transaction ledger.
    </div>

    @if(session('customer_balance_report_success'))
        <div class="report-local-notice">{{ session('customer_balance_report_success') }}</div>
        <script>window.__customerBalanceReportJustStarted = true;</script>
    @endif
    @if(session('customer_balance_report_error'))
        <div class="report-local-notice is-error">{{ session('customer_balance_report_error') }}</div>
    @endif

    <div id="customer-balance-feedback" class="report-feedback" style="display:none;">
        <div id="customer-balance-run-status-wrap" class="sync-chip sync-chip-progress" style="display:none; width:max-content; align-items:center; gap:8px;">
            <span id="customer-balance-run-status-text"></span>
            <button type="button" id="customer-balance-run-status-dismiss" aria-label="Dismiss customer balance report status" style="border:none; background:transparent; color:inherit; font-size:14px; font-weight:700; cursor:pointer; line-height:1; padding:0;">×</button>
        </div>
        <div id="customer-balance-run-progress-wrap" style="display:none; margin-top:10px; max-width: 760px;">
            <div class="report-progress-track" style="height: 8px; border-radius: 999px; overflow: hidden;">
                <div id="customer-balance-run-progress-bar" class="report-progress-bar" style="height: 100%; width: 0%;"></div>
            </div>
            <div id="customer-balance-run-progress-meta" style="margin-top: 6px; font-size: 12px; color: #4a5568;"></div>
        </div>
        <div id="customer-balance-download-wrap" style="display:none; margin-top: 10px;">
            <a id="customer-balance-download-link" href="#" style="display:inline-flex; color:#0f766e; font-size:13px; font-weight:700; text-decoration:none;">↓ Download again</a>
        </div>
    </div>

    <form method="POST" action="{{ route('reports.viefund-customer-balances.run') }}" id="viefund-customer-balance-form" data-inception-dates='@json($inceptionDates)'>
        @csrf
        @php
            $customerBalanceSelectedStatuses = array_values(array_filter(
                array_map('intval', (array) old('customer_balance_status', $customerBalanceStatuses ?? [6])),
                fn($id) => array_key_exists($id, $fundStatusOptions ?? [])
            ));
            if (empty($customerBalanceSelectedStatuses)) {
                $customerBalanceSelectedStatuses = [6];
            }
            $customerBalanceSelectedTrustStatuses = array_values(array_intersect(
                $trustStatusOptions ?? [],
                (array) old('customer_balance_trust_status', $customerBalanceTrustStatuses ?? ['Settled'])
            ));
            $customerBalanceOpenedBeforeRaw = (string) old('customer_balance_opened_before', $customerBalanceOpenedBefore ?? '');
            $customerBalanceOpenedBeforeInput = '';
            if ($customerBalanceOpenedBeforeRaw !== '') {
                try {
                    $customerBalanceOpenedBeforeInput = \Carbon\Carbon::parse($customerBalanceOpenedBeforeRaw)->format('Y-m-d\TH:i');
                } catch (\Throwable) {
                    $customerBalanceOpenedBeforeInput = '';
                }
            }
        @endphp

        <div style="display: flex; gap: 12px; align-items: flex-start; flex-wrap: wrap;">
            <div style="flex: 1 1 820px; min-width: 0;">
                <div style="display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1fr); gap: 12px; align-items: end;">
                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 600; color: #4a5568; margin-bottom: 4px;">Reporting Date</label>
                        <input type="date" name="customer_balance_date" value="{{ old('customer_balance_date', $customerBalanceDate) }}" max="{{ $todayDate }}" required style="padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 4px; width: 100%; font-size: 13px;">
                    </div>

                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 600; color: #4a5568; margin-bottom: 4px;">Date Basis</label>
                        <select name="customer_balance_date_basis" required style="padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 4px; width: 100%; font-size: 13px;">
                            @foreach($dateBasisOptions as $key => $label)
                                <option value="{{ $key }}" @selected(old('customer_balance_date_basis', $customerBalanceDateBasis) === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 600; color: #4a5568; margin-bottom: 4px;">Currency Code</label>
                        <select name="customer_balance_currency_code" style="padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 4px; width: 100%; font-size: 13px;">
                            <option value="CAD" @selected(old('customer_balance_currency_code', $customerBalanceCurrencyLabel ?? 'CAD') === 'CAD')>CAD</option>
                            <option value="USD" @selected(old('customer_balance_currency_code', $customerBalanceCurrencyLabel ?? 'CAD') === 'USD')>USD</option>
                        </select>
                    </div>

                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 600; color: #4a5568; margin-bottom: 4px;">Simulated Report Generation Time (optional)</label>
                        <input
                            type="datetime-local"
                            name="customer_balance_opened_before"
                            max="{{ $nowDateTimeLocal }}"
                            value="{{ $customerBalanceOpenedBeforeInput }}"
                            style="padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 4px; width: 100%; font-size: 13px;"
                        >
                    </div>
                </div>

                <div class="report-options" style="margin-top: 10px; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 6px; background: #f7fafc; width: 100%;">
                    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 16px;">
                        <div>
                            <div style="font-size: 12px; font-weight: 700; color: #4a5568; margin-bottom: 6px;">Cash Transaction Status</div>
                            <div style="display: flex; flex-wrap: wrap; gap: 14px; font-size: 12px; color: #2d3748;">
                                @foreach($fundStatusOptions as $value => $label)
                                    <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer;">
                                        <input type="checkbox" name="customer_balance_status[]" value="{{ $value }}" {{ in_array($value, $customerBalanceSelectedStatuses, true) ? 'checked' : '' }}>
                                        <span>{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <div style="display: flex; gap: 8px; justify-content: flex-end; flex: 0 0 auto; padding-top: 30px;">
                <input type="hidden" name="format" value="csv" id="customer-balance-format-input">
                <div style="position: relative;" id="customer-balance-run-wrap">
                    <button type="button" id="customer-balance-run-btn" class="report-action-button"
                            style="background: #fff; border: 1px solid #90cdf4; color: #2b6cb0; border-radius: 6px; padding: 5px 14px; font-size: 13px; font-weight: 600; cursor: pointer; white-space: nowrap; display: inline-flex; align-items: center; gap: 8px;">
                        <span>Run Report</span>
                        <span>▾</span>
                    </button>
                    <div id="customer-balance-run-panel" style="display: none; position: absolute; right: 0; top: calc(100% + 6px); z-index: 30; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 4px 16px rgba(0,0,0,0.12); min-width: 160px; overflow: hidden;">
                        <button type="button" data-format="csv" class="customer-balance-format-option" style="display: block; width: 100%; text-align: left; padding: 10px 16px; font-size: 13px; font-weight: 600; color: #2b6cb0; background: #fff; border: none; border-bottom: 1px solid #f0f4f8; cursor: pointer;"
                                onmouseover="this.style.background='#ebf8ff'" onmouseout="this.style.background=''">
                            <span style="display:inline-flex; align-items:center; gap:8px;">
                                <svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true" focusable="false" style="flex:0 0 auto;">
                                    <path d="M3 1.75h6l3.5 3.5V14.25H3V1.75Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
                                    <path d="M9 1.75V5.25H12.5" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
                                    <path d="M5 9.25h6M5 11.75h6" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                                </svg>
                                <span>CSV</span>
                            </span>
                        </button>
                        <button type="button" data-format="excel" class="customer-balance-format-option" style="display: block; width: 100%; text-align: left; padding: 10px 16px; font-size: 13px; font-weight: 600; color: #276749; background: #fff; border: none; cursor: pointer;"
                                onmouseover="this.style.background='#f0fff4'" onmouseout="this.style.background=''">
                            <span style="display:inline-flex; align-items:center; gap:8px;">
                                <svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true" focusable="false" style="flex:0 0 auto;">
                                    <path d="M3 1.75h6l3.5 3.5V14.25H3V1.75Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
                                    <path d="M9 1.75V5.25H12.5" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
                                    <path d="M4.75 9.25h6.5M4.75 11.75h6.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                                    <path d="M6 7.75h4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                                </svg>
                                <span>Excel</span>
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<div class="card report-card" style="margin-bottom: 20px;">
    <div style="display:flex; justify-content:space-between; gap:16px; align-items:flex-start; flex-wrap:wrap; margin-bottom:14px;">
        <div>
            <div style="font-size:16px; font-weight:700; color:#2d3748;">Cash Snapshot Monitoring</div>
            <div style="font-size:13px; color:#4a5568; margin-top:4px;">Daily direct-cash baselines, verification runs, and changed-day audit flags.</div>
        </div>
        @if(session('snapshot_review_success'))
            <div class="report-local-notice" style="margin:0;">{{ session('snapshot_review_success') }}</div>
        @endif
    </div>

    <div class="snapshot-monitor-grid">
        <div style="min-width:0; overflow-x:auto;">
            <div style="font-size:12px; font-weight:700; color:#4a5568; margin-bottom:8px;">Unreviewed Changed Days</div>
            <table style="width:100%; border-collapse:collapse; font-size:12px; white-space:nowrap;">
                <thead>
                    <tr style="text-align:left; border-bottom:1px solid #cbd5e1;">
                        <th style="padding:8px;">Date</th>
                        <th style="padding:8px;">Basis</th>
                        <th style="padding:8px; text-align:right;">Count Δ</th>
                        <th style="padding:8px; text-align:right;">Net Δ</th>
                        <th style="padding:8px;">Detected</th>
                        <th style="padding:8px;"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($cashSnapshotChanges as $change)
                        <tr style="border-bottom:1px solid #edf2f7; background:{{ $loop->even ? '#f0fdf4' : '#f7fbfa' }};">
                            <td style="padding:8px; font-family:monospace;">{{ $change->snapshot->total_date->toDateString() }}</td>
                            <td style="padding:8px;">{{ $dateBasisOptions[$change->snapshot->date_basis] ?? $change->snapshot->date_basis }}</td>
                            <td style="padding:8px; text-align:right; font-family:monospace;">{{ number_format($change->transaction_count_delta) }}</td>
                            <td style="padding:8px; text-align:right; font-family:monospace;">{{ $change->net_total_delta < 0 ? '(' : '' }}${{ number_format(abs((float) $change->net_total_delta), 2) }}{{ $change->net_total_delta < 0 ? ')' : '' }}</td>
                            <td style="padding:8px; font-family:monospace;">{{ $change->detected_at->format('Y-m-d H:i') }}</td>
                            <td style="padding:8px; text-align:right;">
                                <form method="POST" action="{{ route('reports.viefund-cash-snapshots.acknowledge', $change->snapshot) }}">
                                    @csrf
                                    <button type="submit" class="report-action-button" style="border:0; border-radius:4px; padding:5px 9px; font-size:11px; font-weight:700; cursor:pointer;">Acknowledge</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" style="padding:18px 8px; color:#64748b; text-align:center;">No unreviewed snapshot changes.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div style="min-width:0;">
            <div style="font-size:12px; font-weight:700; color:#4a5568; margin-bottom:8px;">Recent Verification Runs</div>
            <div style="display:grid; gap:8px;">
                @forelse($cashSnapshotRuns as $run)
                    <div style="border:1px solid #dbe5e3; border-left:4px solid {{ $run->status === 'completed' ? '#0f766e' : '#b91c1c' }}; padding:9px 10px; border-radius:4px; background:#fff;">
                        <div style="display:flex; justify-content:space-between; gap:8px; font-size:12px; font-weight:700; color:#2d3748;">
                            <span>{{ $dateBasisOptions[$run->date_basis] ?? $run->date_basis }}</span>
                            <span>{{ ucfirst(str_replace('_', ' ', $run->run_type)) }}</span>
                        </div>
                        <div style="font-family:monospace; font-size:11px; color:#64748b; margin-top:4px;">{{ $run->requested_from->toDateString() }} to {{ $run->requested_to->toDateString() }}</div>
                        <div style="font-size:11px; color:#4a5568; margin-top:4px;">{{ number_format($run->days_checked) }} checked · {{ number_format($run->days_changed) }} changed · {{ number_format($run->days_inserted) }} inserted</div>
                    </div>
                @empty
                    <div style="font-size:12px; color:#64748b;">No snapshot runs recorded.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>
</div>

<script>
(function () {
    const form = document.getElementById('viefund-report-form');
    const dateBasis = document.getElementById('report-date-basis');
    const dateFrom = document.getElementById('report-date-from');
    const button = document.getElementById('set-inception-date-btn');
    const note = document.getElementById('inception-date-note');
    const feedback = document.getElementById('report-feedback');
    const runStatusWrap = document.getElementById('report-run-status-wrap');
    const runStatusText = document.getElementById('report-run-status-text');
    const runStatusDismiss = document.getElementById('report-run-status-dismiss');
    const progressWrap = document.getElementById('report-run-progress-wrap');
    const progressBar = document.getElementById('report-run-progress-bar');
    const progressMeta = document.getElementById('report-run-progress-meta');
    const downloadWrap = document.getElementById('report-download-wrap');
    const downloadLink = document.getElementById('report-download-link');
    const runWrap = document.getElementById('report-run-wrap');
    const runBtn = document.getElementById('report-run-btn');
    const runPanel = document.getElementById('report-run-panel');
    if (!form || !dateBasis || !dateFrom || !button || !note || !runWrap || !runBtn || !runPanel) return;

    const inceptionDates = JSON.parse(form.dataset.inceptionDates || '{}');
    const DISMISS_KEY = 'viefundReportRunDismissedMessage';
    let currentRunMessage = '';
    let lastStatus = null;
    let cleanupRequested = false;
    let pollTimer = null;
    let autoDownloadPending = false;
    let downloadedUrl = null;

    const refreshNote = () => {
        const selected = dateBasis.value;
        const inception = inceptionDates[selected] || null;
        note.textContent = inception
            ? `Inception Date: ${inception}`
            : 'Inception Date: N/A';
        button.disabled = !inception;
        button.style.opacity = inception ? '1' : '0.6';
        button.style.cursor = inception ? 'pointer' : 'not-allowed';
    };

    button.addEventListener('click', () => {
        const selected = dateBasis.value;
        const inception = inceptionDates[selected] || null;
        if (!inception) return;
        dateFrom.value = inception;
    });

    const setRunStatusVisible = (visible) => {
        if (!runStatusWrap) return;
        runStatusWrap.style.display = visible ? 'inline-flex' : 'none';
        if (feedback) feedback.style.display = visible ? 'block' : 'none';
    };

    const triggerDownload = (url) => {
        if (!url || downloadedUrl === url) return;
        downloadedUrl = url;
        const frame = document.createElement('iframe');
        frame.hidden = true;
        frame.src = url;
        document.body.appendChild(frame);
        window.setTimeout(() => frame.remove(), 60000);
    };

    const showStartError = (message) => {
        autoDownloadPending = false;
        runBtn.disabled = false;
        runStatusWrap.className = 'sync-chip sync-chip-error';
        setRunMessage(message || 'The report could not be started.');
        if (progressWrap) progressWrap.style.display = 'none';
        if (downloadWrap) downloadWrap.style.display = 'none';
    };

    const startReport = async (format) => {
        if (!form.reportValidity()) return;

        const formatInput = document.getElementById('report-format-input');
        if (formatInput) formatInput.value = format || 'csv';
        runPanel.style.display = 'none';
        runBtn.disabled = true;
        cleanupRequested = false;
        autoDownloadPending = true;
        downloadedUrl = null;
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
        localStorage.removeItem(DISMISS_KEY);
        runStatusWrap.className = 'sync-chip sync-chip-progress';
        setRunMessage(`Starting ${String(format || 'csv').toUpperCase()} report...`);
        if (progressWrap) progressWrap.style.display = 'block';
        if (progressBar) progressBar.style.width = '3%';
        if (progressMeta) progressMeta.textContent = 'Submitting report job...';
        if (downloadWrap) downloadWrap.style.display = 'none';

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const data = await response.json();
            if (!response.ok) {
                const validationMessage = data.errors
                    ? Object.values(data.errors).flat()[0]
                    : null;
                throw new Error(validationMessage || data.message);
            }
            setRunMessage(data.message || 'Report started. Preparing your download...');
            poll();
        } catch (error) {
            showStartError(error.message);
        }
    };

    const setRunMessage = (message) => {
        if (!runStatusText) return;
        currentRunMessage = message;
        runStatusText.textContent = message;
        const dismissed = localStorage.getItem(DISMISS_KEY);
        setRunStatusVisible(dismissed !== message);
    };

    const cleanupLatestReportArtifacts = async () => {
        if (cleanupRequested) return;
        cleanupRequested = true;

        try {
            const tokenMeta = document.querySelector('meta[name="csrf-token"]');
            const csrfToken = tokenMeta ? tokenMeta.getAttribute('content') : '';
            await fetch('{{ route('reports.viefund-daily-balance.dismiss-latest') }}', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken,
                },
            });
        } catch (_) {
            // Keep UI responsive even if cleanup call fails.
        }
    };

    if (runStatusDismiss) {
        runStatusDismiss.addEventListener('click', async () => {
            if (!currentRunMessage) return;

            const isCompleted = lastStatus && lastStatus.inProgress === false && lastStatus.success === true;
            if (isCompleted) {
                try {
                    await cleanupLatestReportArtifacts();
                } catch (_) {
                    // Keep UI responsive even if cleanup call fails.
                }

                if (progressWrap) progressWrap.style.display = 'none';
                if (downloadWrap) downloadWrap.style.display = 'none';
                if (progressBar) progressBar.style.width = '0%';
                if (progressMeta) progressMeta.textContent = '';
                lastStatus = null;
            }

            localStorage.setItem(DISMISS_KEY, currentRunMessage);
            setRunStatusVisible(false);
        });
    }

    const poll = () => {
        fetch('{{ route('reports.viefund-daily-balance.status') }}', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(data => {
                lastStatus = data;
                const pct = Number(data.progress_pct ?? 0);
                const processed = data.processed_days ?? 0;
                const total = data.total_days ?? '?';
                const dismissed = localStorage.getItem(DISMISS_KEY);

                if (data.inProgress) {
                    if (runStatusWrap) runStatusWrap.className = 'sync-chip sync-chip-progress';
                    runBtn.disabled = true;
                    localStorage.removeItem(DISMISS_KEY);
                    setRunMessage(data.message || 'Report in progress...');

                    if (progressWrap) progressWrap.style.display = 'block';
                    if (progressBar) progressBar.style.width = `${Math.max(0, Math.min(100, pct))}%`;
                    if (progressMeta) progressMeta.textContent = `${pct}% complete (${processed}/${total} days)`;

                    if (downloadWrap) downloadWrap.style.display = 'none';

                    if (pollTimer) {
                        clearInterval(pollTimer);
                    }
                    pollTimer = setInterval(poll, 1000);
                    return;
                }

                if (data.success === true) {
                    if (dismissed && dismissed === (data.message || 'Report complete.')) {
                        setRunStatusVisible(false);
                        if (progressWrap) progressWrap.style.display = 'none';
                        if (downloadWrap) downloadWrap.style.display = 'none';
                        if (progressBar) progressBar.style.width = '0%';
                        if (progressMeta) progressMeta.textContent = '';
                        cleanupLatestReportArtifacts();
                        return;
                    }

                    if (runStatusWrap) runStatusWrap.className = 'sync-chip sync-chip-success';
                    runBtn.disabled = false;
                    setRunMessage(data.message || 'Report complete.');

                    if (progressWrap) progressWrap.style.display = 'block';
                    if (progressBar) progressBar.style.width = '100%';
                    if (progressMeta) progressMeta.textContent = `100% complete (${data.total_days ?? processed}/${data.total_days ?? total} days)`;

                    if (data.download_url && downloadWrap && downloadLink) {
                        downloadLink.href = data.download_url;
                        downloadWrap.style.display = 'block';
                        if (autoDownloadPending) {
                            autoDownloadPending = false;
                            triggerDownload(data.download_url);
                        }
                    }

                    if (pollTimer) {
                        clearInterval(pollTimer);
                        pollTimer = null;
                    }
                    return;
                }

                if (data.success === false) {
                    if (runStatusWrap) runStatusWrap.className = 'sync-chip sync-chip-error';
                    runBtn.disabled = false;
                    autoDownloadPending = false;
                    setRunMessage(data.message || 'Report failed.');

                    if (progressWrap) progressWrap.style.display = 'none';
                    if (downloadWrap) downloadWrap.style.display = 'none';

                    if (pollTimer) {
                        clearInterval(pollTimer);
                        pollTimer = null;
                    }
                    return;
                }

                setRunStatusVisible(false);
                if (progressWrap) progressWrap.style.display = 'none';
                if (downloadWrap) downloadWrap.style.display = 'none';

                if (pollTimer) {
                    clearInterval(pollTimer);
                    pollTimer = null;
                }
            })
            .catch(() => {
                // Keep UI stable if polling fails.
            });
    };

    dateBasis.addEventListener('change', refreshNote);
    refreshNote();

    runBtn.addEventListener('click', function (event) {
        event.stopPropagation();
        runPanel.style.display = runPanel.style.display === 'block' ? 'none' : 'block';
    });

    runPanel.querySelectorAll('.report-format-option').forEach((option) => {
        option.addEventListener('click', function () {
            startReport(this.getAttribute('data-format') || 'csv');
        });
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        const formatInput = document.getElementById('report-format-input');
        startReport(formatInput ? formatInput.value : 'csv');
    });

    document.addEventListener('click', function (event) {
        if (!runWrap.contains(event.target)) {
            runPanel.style.display = 'none';
        }
    });

    if (window.__reportJustStarted) {
        if (runStatusWrap) runStatusWrap.className = 'sync-chip sync-chip-progress';
        localStorage.removeItem(DISMISS_KEY);
        setRunMessage('Report is starting...');
        if (progressWrap) progressWrap.style.display = 'block';
        if (progressBar) progressBar.style.width = '5%';
        if (progressMeta) progressMeta.textContent = 'Starting report job...';
    }

    poll();
})();

(function () {
    const form = document.getElementById('legacy-viefund-report-form');
    const dateBasis = document.getElementById('legacy-report-date-basis');
    const dateFrom = document.getElementById('legacy-report-date-from');
    const button = document.getElementById('legacy-set-inception-date-btn');
    const note = document.getElementById('legacy-inception-date-note');
    const formatInput = document.getElementById('legacy-report-format-input');
    const runButtons = Array.from(document.querySelectorAll('.legacy-report-run-button'));
    const feedback = document.getElementById('legacy-report-feedback');
    const statusWrap = document.getElementById('legacy-report-status-wrap');
    const statusText = document.getElementById('legacy-report-status-text');
    const statusDismiss = document.getElementById('legacy-report-status-dismiss');
    const progressWrap = document.getElementById('legacy-report-progress-wrap');
    const progressBar = document.getElementById('legacy-report-progress-bar');
    const progressMeta = document.getElementById('legacy-report-progress-meta');
    const downloadWrap = document.getElementById('legacy-report-download-wrap');
    const downloadLink = document.getElementById('legacy-report-download-link');
    if (!form || !dateBasis || !dateFrom || !button || !note || !formatInput || !statusWrap || !statusText) return;

    const inceptionDates = JSON.parse(form.dataset.inceptionDates || '{}');
    let pollTimer = null;
    let downloadedUrl = null;
    const refreshNote = () => {
        const inception = inceptionDates[dateBasis.value] || null;
        note.textContent = inception
            ? `Inception Date: ${inception}`
            : 'Inception Date: N/A';
        button.disabled = !inception;
        button.style.opacity = inception ? '1' : '0.6';
        button.style.cursor = inception ? 'pointer' : 'not-allowed';
    };

    button.addEventListener('click', () => {
        const inception = inceptionDates[dateBasis.value] || null;
        if (inception) dateFrom.value = inception;
    });
    dateBasis.addEventListener('change', refreshNote);
    refreshNote();

    const setRunning = (running) => {
        runButtons.forEach((runButton) => {
            runButton.disabled = running;
            runButton.style.opacity = running ? '0.6' : '1';
        });
    };
    const showStatus = (message, className = 'sync-chip sync-chip-progress') => {
        if (feedback) feedback.style.display = 'block';
        statusWrap.style.display = 'inline-flex';
        statusWrap.className = className;
        statusText.textContent = message;
    };
    const triggerDownload = (url) => {
        if (!url || downloadedUrl === url) return;
        downloadedUrl = url;
        const frame = document.createElement('iframe');
        frame.hidden = true;
        frame.src = url;
        document.body.appendChild(frame);
        window.setTimeout(() => frame.remove(), 60000);
    };
    const poll = async () => {
        try {
            const response = await fetch('{{ route('reports.viefund-daily-balance-legacy.status') }}', {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await response.json();
            if (data.inProgress) {
                setRunning(true);
                showStatus(data.message || 'Legacy report in progress...');
                if (progressWrap) progressWrap.style.display = 'block';
                if (progressBar) progressBar.style.width = `${Math.max(1, Number(data.progress_pct || 0))}%`;
                if (progressMeta) progressMeta.textContent = `${Number(data.progress_pct || 0)}% complete (${Number(data.processed_days || 0)}/${Number(data.total_days || 0)} days)`;
                return;
            }
            setRunning(false);
            if (data.success === true) {
                if (pollTimer) {
                    window.clearInterval(pollTimer);
                    pollTimer = null;
                }
                showStatus(data.message || 'Legacy report completed.', 'sync-chip sync-chip-success');
                if (progressWrap) progressWrap.style.display = 'none';
                if (downloadWrap) downloadWrap.style.display = data.download_url ? 'block' : 'none';
                if (downloadLink && data.download_url) downloadLink.href = data.download_url;
                triggerDownload(data.download_url);
            } else if (data.success === false) {
                if (pollTimer) {
                    window.clearInterval(pollTimer);
                    pollTimer = null;
                }
                showStatus(data.message || 'Legacy report failed.', 'sync-chip sync-chip-error');
                if (progressWrap) progressWrap.style.display = 'none';
            } else if (pollTimer) {
                window.clearInterval(pollTimer);
                pollTimer = null;
            }
        } catch (_) {
            showStatus('Unable to read legacy report status.', 'sync-chip sync-chip-error');
            setRunning(false);
        }
    };
    const startReport = async (format) => {
        if (!form.reportValidity()) return;
        formatInput.value = format;
        downloadedUrl = null;
        setRunning(true);
        showStatus(`Starting legacy ${format.toUpperCase()} report...`);
        if (progressWrap) progressWrap.style.display = 'block';
        if (progressBar) progressBar.style.width = '2%';
        if (downloadWrap) downloadWrap.style.display = 'none';
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await response.json();
            if (!response.ok) {
                const validationMessage = data.errors ? Object.values(data.errors).flat()[0] : null;
                throw new Error(validationMessage || data.message || 'Legacy report could not start.');
            }
            showStatus(data.message || 'Legacy report started.');
            if (pollTimer) window.clearInterval(pollTimer);
            pollTimer = window.setInterval(poll, 3000);
            poll();
        } catch (error) {
            showStatus(error.message, 'sync-chip sync-chip-error');
            setRunning(false);
        }
    };
    runButtons.forEach((runButton) => runButton.addEventListener('click', () => {
        startReport(runButton.dataset.legacyFormat || 'csv');
    }));
    form.addEventListener('submit', (event) => event.preventDefault());
    if (statusDismiss) statusDismiss.addEventListener('click', async () => {
        statusWrap.style.display = 'none';
        if (feedback) feedback.style.display = 'none';
        if (pollTimer) window.clearInterval(pollTimer);
        await fetch('{{ route('reports.viefund-daily-balance-legacy.dismiss-latest') }}', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
        });
    });
    poll();
})();

(function () {
    const form = document.getElementById('viefund-customer-balance-form');
    const feedback = document.getElementById('customer-balance-feedback');
    const runStatusWrap = document.getElementById('customer-balance-run-status-wrap');
    const runStatusText = document.getElementById('customer-balance-run-status-text');
    const runStatusDismiss = document.getElementById('customer-balance-run-status-dismiss');
    const progressWrap = document.getElementById('customer-balance-run-progress-wrap');
    const progressBar = document.getElementById('customer-balance-run-progress-bar');
    const progressMeta = document.getElementById('customer-balance-run-progress-meta');
    const downloadWrap = document.getElementById('customer-balance-download-wrap');
    const downloadLink = document.getElementById('customer-balance-download-link');
    const runWrap = document.getElementById('customer-balance-run-wrap');
    const runBtn = document.getElementById('customer-balance-run-btn');
    const runPanel = document.getElementById('customer-balance-run-panel');
    const formatInput = document.getElementById('customer-balance-format-input');
    if (!form || !runStatusWrap || !runStatusText || !runWrap || !runBtn || !runPanel || !formatInput) return;

    const DISMISS_KEY = 'viefundCustomerBalanceRunDismissedMessage';
    let currentRunMessage = '';
    let lastStatus = null;
    let cleanupRequested = false;
    let pollTimer = null;
    let autoDownloadPending = false;
    let downloadedUrl = null;

    const setRunStatusVisible = (visible) => {
        runStatusWrap.style.display = visible ? 'inline-flex' : 'none';
        if (feedback) feedback.style.display = visible ? 'block' : 'none';
    };

    const triggerDownload = (url) => {
        if (!url || downloadedUrl === url) return;
        downloadedUrl = url;
        const frame = document.createElement('iframe');
        frame.hidden = true;
        frame.src = url;
        document.body.appendChild(frame);
        window.setTimeout(() => frame.remove(), 60000);
    };

    const showStartError = (message) => {
        autoDownloadPending = false;
        runBtn.disabled = false;
        runStatusWrap.className = 'sync-chip sync-chip-error';
        setRunMessage(message || 'The report could not be started.');
        if (progressWrap) progressWrap.style.display = 'none';
        if (downloadWrap) downloadWrap.style.display = 'none';
    };

    const startReport = async (format) => {
        if (!form.reportValidity()) return;

        formatInput.value = format || 'csv';
        runPanel.style.display = 'none';
        runBtn.disabled = true;
        cleanupRequested = false;
        autoDownloadPending = true;
        downloadedUrl = null;
        if (pollTimer) {
            clearTimeout(pollTimer);
            pollTimer = null;
        }
        localStorage.removeItem(DISMISS_KEY);
        runStatusWrap.className = 'sync-chip sync-chip-progress';
        setRunMessage(`Starting ${String(format || 'csv').toUpperCase()} report...`);
        if (progressWrap) progressWrap.style.display = 'block';
        if (progressBar) progressBar.style.width = '3%';
        if (progressMeta) progressMeta.textContent = 'Submitting report job...';
        if (downloadWrap) downloadWrap.style.display = 'none';

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const data = await response.json();
            if (!response.ok) {
                const validationMessage = data.errors
                    ? Object.values(data.errors).flat()[0]
                    : null;
                throw new Error(validationMessage || data.message);
            }
            setRunMessage(data.message || 'Report started. Preparing your download...');
            poll();
        } catch (error) {
            showStartError(error.message);
        }
    };

    const setRunMessage = (message) => {
        currentRunMessage = message;
        runStatusText.textContent = message;
        const dismissed = localStorage.getItem(DISMISS_KEY);
        setRunStatusVisible(dismissed !== message);
    };

    runBtn.addEventListener('click', function (event) {
        event.stopPropagation();
        runPanel.style.display = runPanel.style.display === 'block' ? 'none' : 'block';
    });

    runPanel.querySelectorAll('.customer-balance-format-option').forEach((option) => {
        option.addEventListener('click', function () {
            startReport(this.getAttribute('data-format') || 'csv');
        });
    });

    document.addEventListener('click', function (event) {
        if (!runWrap.contains(event.target)) {
            runPanel.style.display = 'none';
        }
    });

    const cleanupLatestReportArtifacts = async () => {
        if (cleanupRequested) return;
        cleanupRequested = true;

        try {
            const tokenMeta = document.querySelector('meta[name="csrf-token"]');
            const csrfToken = tokenMeta ? tokenMeta.getAttribute('content') : '';
            await fetch('{{ route('reports.viefund-customer-balances.dismiss-latest') }}', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken,
                },
            });
        } catch (_) {
            // Keep UI responsive even if cleanup call fails.
        }
    };

    if (runStatusDismiss) {
        runStatusDismiss.addEventListener('click', async () => {
            if (!currentRunMessage) return;

            const isCompleted = lastStatus && lastStatus.inProgress === false && lastStatus.success === true;
            if (isCompleted) {
                try {
                    await cleanupLatestReportArtifacts();
                } catch (_) {
                    // Keep UI responsive even if cleanup call fails.
                }

                if (progressWrap) progressWrap.style.display = 'none';
                if (downloadWrap) downloadWrap.style.display = 'none';
                if (progressBar) progressBar.style.width = '0%';
                if (progressMeta) progressMeta.textContent = '';
                lastStatus = null;
            }

            localStorage.setItem(DISMISS_KEY, currentRunMessage);
            setRunStatusVisible(false);
        });
    }

    const poll = () => {
        fetch('{{ route('reports.viefund-customer-balances.status') }}', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(data => {
                lastStatus = data;
                const pct = Number(data.progress_pct ?? 0);
                const processed = data.processed_accounts ?? 0;
                const total = data.total_accounts ?? '?';

                if (data.inProgress) {
                    runStatusWrap.className = 'sync-chip sync-chip-progress';
                    runBtn.disabled = true;
                    localStorage.removeItem(DISMISS_KEY);
                    setRunMessage(data.message || 'Report in progress...');

                    if (progressWrap) progressWrap.style.display = 'block';
                    if (progressBar) progressBar.style.width = `${Math.max(0, Math.min(100, pct))}%`;
                    if (progressMeta) progressMeta.textContent = `${pct}% complete (${processed}/${total} accounts)`;
                    if (downloadWrap) downloadWrap.style.display = 'none';
                } else if (data.success === true) {
                    runStatusWrap.className = 'sync-chip sync-chip-success';
                    runBtn.disabled = false;
                    setRunMessage(data.message || 'Report completed.');
                    if (progressWrap) progressWrap.style.display = 'block';
                    if (progressBar) progressBar.style.width = '100%';
                    if (progressMeta) progressMeta.textContent = `${processed}/${total} accounts written`;
                    if (downloadWrap && downloadLink && data.download_url) {
                        downloadLink.href = data.download_url;
                        downloadWrap.style.display = 'block';
                        if (autoDownloadPending) {
                            autoDownloadPending = false;
                            triggerDownload(data.download_url);
                        }
                    }
                } else if (data.success === false) {
                    runStatusWrap.className = 'sync-chip sync-chip-error';
                    runBtn.disabled = false;
                    autoDownloadPending = false;
                    setRunMessage(data.message || 'Report failed.');
                    if (progressWrap) progressWrap.style.display = 'none';
                    if (downloadWrap) downloadWrap.style.display = 'none';
                } else {
                    if (!window.__customerBalanceReportJustStarted) {
                        setRunStatusVisible(false);
                    }
                }

                const shouldContinue = data.inProgress || data.success === true || data.success === false;
                if (shouldContinue) {
                    pollTimer = window.setTimeout(poll, data.inProgress ? 2000 : 15000);
                }
            })
            .catch(() => {
                pollTimer = window.setTimeout(poll, 5000);
            });
    };

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        startReport(formatInput.value || 'csv');
    });

    poll();
})();
</script>
@endsection
