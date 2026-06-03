@extends('layouts.app')

@section('title', 'Remote VieFund Transactions')

<style>
/* ── Multi-select dropdown widget ──────────────────────────────────── */
.ms-wrap { position: relative; }
.ms-trigger {
    display: flex; align-items: center; flex-wrap: wrap; gap: 4px;
    min-height: 36px; padding: 4px 36px 4px 8px; position: relative;
    border: 1px solid #cbd5e0; border-radius: 4px;
    background: #fff; cursor: pointer; user-select: none;
}
.ms-wrap.open .ms-trigger, .ms-trigger:hover { border-color: #4299e1; }
.ms-tags { display: flex; flex-wrap: wrap; gap: 4px; flex: 1; min-width: 0; }
.ms-tag {
    display: inline-flex; align-items: center; gap: 2px;
    background: #4299e1; color: #fff; font-size: 12px; font-weight: 500;
    padding: 2px 4px 2px 7px; border-radius: 3px; max-width: 120px; white-space: nowrap;
}
.ms-tag-label { overflow: hidden; text-overflow: ellipsis; }
.ms-tag-x { cursor: pointer; font-size: 15px; line-height: 1; opacity: 0.75; padding: 0 2px; flex-shrink: 0; }
.ms-tag-x:hover { opacity: 1; }
.ms-placeholder { color: #a0aec0; font-size: 13px; flex: 1; }
.ms-caret {
    position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
    color: #718096; font-size: 14px; transition: transform 0.15s;
    pointer-events: none;
}
.ms-wrap.open .ms-caret { transform: translateY(-50%) rotate(180deg); }
.ms-panel {
    display: none; position: absolute;
    top: calc(100% + 3px); left: 0; right: 0; min-width: 100%;
    background: #fff; border: 1px solid #cbd5e0; border-radius: 4px;
    box-shadow: 0 6px 16px rgba(0,0,0,.12); z-index: 1000;
    max-height: 260px; overflow-y: auto;
}
.ms-wrap.open .ms-panel { display: block; }
.ms-item {
    display: flex; align-items: center; gap: 11px;
    padding: 9px 14px; cursor: pointer;
    border-bottom: 1px solid #f0f0f0;
    font-size: 13px; color: #2d3748;
}
.ms-item:last-child { border-bottom: none; }
.ms-item:hover { background: #ebf8ff; }
.ms-select-all { font-weight: 600; border-bottom: 2px solid #e2e8f0 !important; }
.ms-checkbox {
    width: 18px; height: 18px; flex-shrink: 0;
    border: 2px solid #cbd5e0; border-radius: 3px;
    display: flex; align-items: center; justify-content: center;
    background: #fff; box-sizing: border-box;
    transition: background 0.1s, border-color 0.1s;
}
.ms-item.checked .ms-checkbox { background: #4299e1; border-color: #4299e1; }
.ms-item.checked .ms-checkbox::after {
    content: ''; display: block;
    width: 5px; height: 9px;
    border: 2px solid #fff; border-top: none; border-left: none;
    transform: rotate(45deg) translate(-1px, -1px);
}
.ms-item input[type="checkbox"] { display: none; }
/* ──────────────────────────────────────────────────────────────────── */
</style>

@section('content')
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 style="margin: 0;">📡 Remote VieFund Transactions</h2>
        <div style="display: flex; gap: 10px; align-items: center;">
            <div id="sync-status-badge">
                @if($syncInProgress)
                    <span style="background: #ebf8ff; color: #2b6cb0; border-radius: 20px; padding: 4px 14px; font-size: 13px; font-weight: 600;">
                        ⟳ Transaction Sync In Progress…
                    </span>
                @elseif($syncNeeded)
                    <form method="POST" action="{{ route('remote-viefund.sync') }}" style="margin:0;">
                        @csrf
                        @foreach(request()->query() as $key => $val)
                            @if(is_array($val))
                                @foreach($val as $v)
                                    <input type="hidden" name="{{ $key }}[]" value="{{ $v }}">
                                @endforeach
                            @else
                                <input type="hidden" name="{{ $key }}" value="{{ $val }}">
                            @endif
                        @endforeach
                        <button type="submit" style="background: #fefcbf; color: #744210; border: 1px solid #f6e05e; border-radius: 20px; padding: 4px 14px; font-size: 13px; font-weight: 600; cursor: pointer;">
                            ↻ Sync New Transactions
                        </button>
                    </form>
                @else
                    <span style="background: #c6f6d5; color: #276749; border-radius: 20px; padding: 4px 14px; font-size: 13px; font-weight: 600;">
                        ✓ Transactions Synced
                    </span>
                @endif
            </div>
            <span style="background: #c6f6d5; color: #276749; border-radius: 20px; padding: 4px 14px; font-size: 13px; font-weight: 600;">
                🔴 Live Source DB
            </span>
        </div>
    </div>

    @if($connectionError)
        <div class="alert" style="background: #fff5f5; border-left: 4px solid #e53e3e; color: #742a2a; padding: 16px 20px; border-radius: 6px; margin-bottom: 20px;">
            <strong>Connection Error</strong><br>
            {{ $connectionError }}
        </div>
    @else
        <!-- Summary Card -->
        <div class="card" style="margin-bottom: 20px; background: linear-gradient(135deg, #38a169 0%, #234e52 100%); color: white;">
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; text-align: center;">
                <div>
                    <div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;">{{ number_format($totalRecords) }}</div>
                    <div style="font-size: 14px; opacity: 0.9;">Total Transactions</div>
                </div>
                <div>
                    <div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;">{{ number_format($transactions->total()) }}</div>
                    <div style="font-size: 14px; opacity: 0.9;">
                        @if($search || !empty($filters))
                            Filtered Results
                        @else
                            Unfiltered Results
                        @endif
                    </div>
                </div>
                <div>
                    <div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;">{{ $transactions->currentPage() }}/{{ $transactions->lastPage() }}</div>
                    <div style="font-size: 14px; opacity: 0.9;">Current Page</div>
                </div>
            </div>
        </div>

        <!-- Combined Search & Filters -->
        @php
            $summaryParts = [];
            if (!empty($filters['customer_name'])) $summaryParts[] = ['label' => 'Customer', 'value' => $filters['customer_name']];
            if (!empty($filters['account_id']))   $summaryParts[] = ['label' => 'Account',  'value' => $filters['account_id']];
            if (!empty($filters['trx_id']))        $summaryParts[] = ['label' => 'Txn ID',   'value' => $filters['trx_id']];
            if (!empty($filters['source_id']))     $summaryParts[] = ['label' => 'Source',   'value' => $filters['source_id']];
            if (!empty($filters['created_from']))  $summaryParts[] = ['label' => 'From',     'value' => $filters['created_from']];
            if (!empty($filters['created_to']))    $summaryParts[] = ['label' => 'To',       'value' => $filters['created_to']];
            if (!empty($filters['trx_type']))      $summaryParts[] = ['label' => 'Type',     'value' => implode(', ', (array) $filters['trx_type'])];
            if (!empty($filters['direction']))     $summaryParts[] = ['label' => 'Direction','value' => implode(', ', (array) $filters['direction'])];
            $hasActiveFilters = !empty($summaryParts);
        @endphp
        <div class="card" style="margin-bottom: 20px; padding: 0;">

            <!-- Card header / toggle bar -->
            <div id="filter-card-header" style="display: flex; align-items: center; gap: 10px; padding: 10px 20px; background: #edf2f7; border-bottom: 1px solid #e2e8f0; cursor: pointer; user-select: none; border-radius: 6px 6px 0 0;">
                <span style="font-size: 11px; font-weight: 700; color: #4a5568; text-transform: uppercase; letter-spacing: 0.08em; white-space: nowrap; flex-shrink: 0;">Transaction Filters</span>
                <!-- Active filter summary pills (visible when collapsed) -->
                <div id="filter-summary" style="display: flex; flex-wrap: wrap; gap: 5px; flex: 1; min-width: 0;">
                    @foreach($summaryParts as $part)
                        <span style="display: inline-flex; align-items: center; gap: 3px; background: #fff; border: 1px solid #cbd5e0; color: #2d3748; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 10px; white-space: nowrap;">
                            <span style="color: #718096; font-weight: 500;">{{ $part['label'] }}:</span>
                            {{ $part['value'] }}
                        </span>
                    @endforeach
                </div>
                <!-- Expand/Collapse toggle (always right-justified) -->
                <div style="display: flex; align-items: center; gap: 5px; margin-left: auto; flex-shrink: 0;">
                    <span id="filter-toggle-label" style="font-size: 11px; font-weight: 600; color: #718096; text-transform: uppercase; letter-spacing: 0.06em;">Expand</span>
                    <svg id="filter-chevron" xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#718096" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="transition: transform 0.2s;"><polyline points="6 9 12 15 18 9"></polyline></svg>
                </div>
            </div>

            <!-- Collapsible filter body -->
            <div id="filter-body" style="padding: 20px 24px 20px;">
            <form action="{{ route('remote-viefund.index') }}" method="GET" id="viefund-filter-form">
                <input type="hidden" name="filter_customer_id" id="filter-customer-id" value="{{ $filters['customer_id'] ?? '' }}">
                <input type="hidden" name="filter_customer_name" id="filter-customer-name" value="{{ $filters['customer_name'] ?? '' }}">
                <input type="hidden" name="filter_account_id" id="filter-account-id" value="{{ $filters['account_id'] ?? '' }}">

                <!-- Customer / Plan Account row -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 16px;">

                    <!-- Customer -->
                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 800; color: #4a5568; margin-bottom: 6px;">Customer</label>
                        <div id="customer-trigger" style="display: flex; align-items: center; flex-wrap: wrap; gap: 4px; min-height: 36px; padding: 4px 8px; border: 1px solid #cbd5e0; border-radius: 4px; background: #fff; cursor: text; position: relative; box-sizing: border-box;">
                            <div id="customer-pill" style="display: {{ !empty($filters['customer_id']) ? 'inline-flex' : 'none' }}; align-items: center; gap: 2px; background: #4299e1; color: #fff; font-size: 12px; font-weight: 500; padding: 2px 4px 2px 8px; border-radius: 3px; white-space: nowrap;">
                                <span id="customer-pill-label">{{ $filters['customer_name'] ?? '' }}</span>
                                <span id="customer-pill-x" style="cursor: pointer; font-size: 14px; line-height: 1; opacity: 0.75; padding: 0 2px;">×</span>
                            </div>
                            <input type="text"
                                   id="customer-search-input"
                                   placeholder="Search by name…"
                                   autocomplete="off"
                                   style="display: {{ !empty($filters['customer_id']) ? 'none' : '' }}; border: none; outline: none; flex: 1; min-width: 120px; font-size: 13px; padding: 2px 0; background: transparent;">
                            <div id="customer-dropdown"
                                 style="display: none; position: absolute; top: calc(100% + 2px); left: 0; right: 0; background: #fff; border: 1px solid #cbd5e0; border-radius: 4px; z-index: 200; max-height: 260px; overflow-y: auto; box-shadow: 0 4px 10px rgba(0,0,0,0.10);"></div>
                        </div>
                    </div>

                    <!-- Plan Account ID -->
                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 800; color: #4a5568; margin-bottom: 6px;">Plan Account ID</label>
                        <div id="account-trigger" style="display: flex; align-items: center; flex-wrap: wrap; gap: 4px; min-height: 36px; padding: 4px 8px; border: 1px solid #cbd5e0; border-radius: 4px; background: #fff; cursor: text; position: relative; box-sizing: border-box;">
                            <div id="account-pill" style="display: {{ !empty($filters['account_id']) ? 'inline-flex' : 'none' }}; align-items: center; gap: 2px; background: #4299e1; color: #fff; font-size: 12px; font-weight: 500; padding: 2px 4px 2px 8px; border-radius: 3px; white-space: nowrap;">
                                <span id="account-pill-label">{{ $filters['account_id'] ?? '' }}</span>
                                <span id="account-pill-x" style="cursor: pointer; font-size: 14px; line-height: 1; opacity: 0.75; padding: 0 2px;">×</span>
                            </div>
                            <input type="text"
                                   id="plan-account-search-input"
                                   placeholder="Search by account ID…"
                                   autocomplete="off"
                                   style="display: {{ !empty($filters['account_id']) ? 'none' : '' }}; border: none; outline: none; flex: 1; min-width: 120px; font-size: 13px; padding: 2px 0; background: transparent;">
                            <div id="plan-account-dropdown"
                                 style="display: none; position: absolute; top: calc(100% + 2px); left: 0; right: 0; background: #fff; border: 1px solid #cbd5e0; border-radius: 4px; z-index: 200; max-height: 260px; overflow-y: auto; box-shadow: 0 4px 10px rgba(0,0,0,0.10);"></div>
                        </div>
                    </div>

                </div>

                <div style="border-top: 0; padding-top: 0;">
                    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px;">
                        @php
                            $trxIdValues    = array_values(array_filter(array_map('trim', explode(',', $filters['trx_id']    ?? ''))));
                            $sourceIdValues = array_values(array_filter(array_map('trim', explode(',', $filters['source_id'] ?? ''))));
                        @endphp
                        <div>
                            <label style="display: block; font-size: 12px; font-weight: 800; color: #4a5568; margin-bottom: 6px;">Txn ID</label>
                            <div id="trx-id-tags" style="display: flex; align-items: center; flex-wrap: wrap; gap: 4px; min-height: 36px; padding: 4px 8px; border: 1px solid #cbd5e0; border-radius: 4px; background: #fff; cursor: text; box-sizing: border-box;">
                                @foreach($trxIdValues as $tv)
                                <div class="tag-pill" data-value="{{ $tv }}" style="display: inline-flex; align-items: center; gap: 2px; background: #4299e1; color: #fff; font-size: 12px; font-weight: 500; padding: 2px 4px 2px 8px; border-radius: 3px; white-space: nowrap; font-family: monospace;">
                                    <span>{{ $tv }}</span><span class="tag-x" style="cursor: pointer; font-size: 14px; line-height: 1; opacity: 0.75; padding: 0 2px;">×</span>
                                </div>
                                @endforeach
                                <input type="text" id="trx-id-input"
                                       data-default-placeholder="e.g. 939"
                                       placeholder="{{ empty($trxIdValues) ? 'e.g. 939' : '' }}"
                                       style="border: none; outline: none; flex: 1; min-width: 60px; font-size: 13px; padding: 2px 0; background: transparent; font-family: monospace;">
                            </div>
                            <input type="hidden" name="filter_trx_id" id="filter-trx-id" value="{{ $filters['trx_id'] ?? '' }}">
                        </div>
                        <div>
                            <label style="display: block; font-size: 12px; font-weight: 800; color: #4a5568; margin-bottom: 6px;">Source ID</label>
                            <div id="source-id-tags" style="display: flex; align-items: center; flex-wrap: wrap; gap: 4px; min-height: 36px; padding: 4px 8px; border: 1px solid #cbd5e0; border-radius: 4px; background: #fff; cursor: text; box-sizing: border-box;">
                                @foreach($sourceIdValues as $sv)
                                <div class="tag-pill" data-value="{{ $sv }}" style="display: inline-flex; align-items: center; gap: 2px; background: #4299e1; color: #fff; font-size: 12px; font-weight: 500; padding: 2px 4px 2px 8px; border-radius: 3px; white-space: nowrap; font-family: monospace;">
                                    <span>{{ $sv }}</span><span class="tag-x" style="cursor: pointer; font-size: 14px; line-height: 1; opacity: 0.75; padding: 0 2px;">×</span>
                                </div>
                                @endforeach
                                <input type="text" id="source-id-input"
                                       data-default-placeholder="e.g. L20200..."
                                       placeholder="{{ empty($sourceIdValues) ? 'e.g. L20200...' : '' }}"
                                       style="border: none; outline: none; flex: 1; min-width: 80px; font-size: 13px; padding: 2px 0; background: transparent; font-family: monospace;">
                            </div>
                            <input type="hidden" name="filter_source_id" id="filter-source-id" value="{{ $filters['source_id'] ?? '' }}">
                        </div>
                        <div>
                            <label style="display: block; font-size: 12px; font-weight: 800; color: #4a5568; margin-bottom: 6px;">Created From</label>
                            <div id="created-from-wrap" style="display: flex; align-items: center; min-height: 36px; padding: 4px 8px; border: 1px solid #cbd5e0; border-radius: 4px; background: #fff; box-sizing: border-box;">
                                <div id="created-from-pill" style="display: {{ !empty($filters['created_from']) ? 'inline-flex' : 'none' }}; align-items: center; gap: 2px; background: #4299e1; color: #fff; font-size: 12px; font-weight: 500; padding: 2px 4px 2px 8px; border-radius: 3px; white-space: nowrap; font-family: monospace;">
                                    <span id="created-from-pill-label">{{ $filters['created_from'] ?? '' }}</span>
                                    <span id="created-from-pill-x" style="cursor: pointer; font-size: 14px; line-height: 1; opacity: 0.75; padding: 0 2px;">×</span>
                                </div>
                                <input type="date" id="created-from-picker"
                                       style="display: {{ !empty($filters['created_from']) ? 'none' : '' }}; border: none; outline: none; flex: 1; font-size: 13px; padding: 2px 0; background: transparent; width: 100%;">
                            </div>
                            <input type="hidden" name="filter_created_from" id="filter-created-from" value="{{ $filters['created_from'] ?? '' }}">
                        </div>
                        <div>
                            <label style="display: block; font-size: 12px; font-weight: 800; color: #4a5568; margin-bottom: 6px;">Created To</label>
                            <div id="created-to-wrap" style="display: flex; align-items: center; min-height: 36px; padding: 4px 8px; border: 1px solid #cbd5e0; border-radius: 4px; background: #fff; box-sizing: border-box;">
                                <div id="created-to-pill" style="display: {{ !empty($filters['created_to']) ? 'inline-flex' : 'none' }}; align-items: center; gap: 2px; background: #4299e1; color: #fff; font-size: 12px; font-weight: 500; padding: 2px 4px 2px 8px; border-radius: 3px; white-space: nowrap; font-family: monospace;">
                                    <span id="created-to-pill-label">{{ $filters['created_to'] ?? '' }}</span>
                                    <span id="created-to-pill-x" style="cursor: pointer; font-size: 14px; line-height: 1; opacity: 0.75; padding: 0 2px;">×</span>
                                </div>
                                <input type="date" id="created-to-picker"
                                       style="display: {{ !empty($filters['created_to']) ? 'none' : '' }}; border: none; outline: none; flex: 1; font-size: 13px; padding: 2px 0; background: transparent; width: 100%;">
                            </div>
                            <input type="hidden" name="filter_created_to" id="filter-created-to" value="{{ $filters['created_to'] ?? '' }}">
                        </div>
                    </div>
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-top: 12px;">
                    @if(!empty($availableTrxTypes))
                        <div>
                            <label style="display: block; font-size: 12px; font-weight: 800; color: #4a5568; margin-bottom: 6px;">Txn Type</label>
                            <div class="ms-wrap">
                                <div class="ms-trigger">
                                    <div class="ms-tags"></div>
                                    <span class="ms-placeholder">All types</span>
                                    <span class="ms-caret">&#9662;</span>
                                </div>
                                <div class="ms-panel">
                                    <div class="ms-item ms-select-all">
                                        <span class="ms-checkbox"></span>
                                        <span>Select All</span>
                                    </div>
                                    @foreach($availableTrxTypes as $type)
                                    <div class="ms-item" data-value="{{ $type }}">
                                        <span class="ms-checkbox"></span>
                                        <input type="checkbox" name="filter_trx_type[]" value="{{ $type }}"
                                               {{ in_array($type, (array) ($filters['trx_type'] ?? [])) ? 'checked' : '' }}>
                                        <span>{{ $type }}</span>
                                    </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @else
                        <div></div>
                    @endif
                        <div>
                            <label style="display: block; font-size: 12px; font-weight: 800; color: #4a5568; margin-bottom: 6px;">Direction</label>
                            <div class="ms-wrap">
                                <div class="ms-trigger">
                                    <div class="ms-tags"></div>
                                    <span class="ms-placeholder">All directions</span>
                                    <span class="ms-caret">&#9662;</span>
                                </div>
                                <div class="ms-panel">
                                    <div class="ms-item ms-select-all">
                                        <span class="ms-checkbox"></span>
                                        <span>Select All</span>
                                    </div>
                                    @foreach(['debit' => 'Debit', 'credit' => 'Credit'] as $dirVal => $dirLabel)
                                    <div class="ms-item" data-value="{{ $dirVal }}">
                                        <span class="ms-checkbox"></span>
                                        <input type="checkbox" name="filter_direction[]" value="{{ $dirVal }}"
                                               {{ in_array($dirVal, (array) ($filters['direction'] ?? [])) ? 'checked' : '' }}>
                                        <span>{{ $dirLabel }}</span>
                                    </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>{{-- end 2-col grid --}}
                    <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 14px; padding-top: 14px; border-top: 1px solid #e2e8f0;">
                        @if(!empty($filters))
                            <a href="{{ route('remote-viefund.index') }}" class="btn" style="background: #718096; padding: 8px 20px; text-decoration: none; font-size: 13px;">Clear All</a>
                        @endif
                        <button type="submit" class="btn" style="padding: 8px 24px; font-size: 13px;">Apply Filters</button>
                    </div>
                </div>
            </form>
            </div>{{-- #filter-body --}}
        </div>{{-- .card --}}

        <!-- Transactions Table -->
        <div class="card" style="padding: 0;">
            @if($transactions->count() > 0)

                {{-- Always-present sticky banner: populated when filtered by customer/account, zero-height otherwise --}}
                <div id="client-banner" style="position: sticky; top: 0; z-index: 11; {{ $bannerName !== null ? 'padding: 14px 20px; background: linear-gradient(90deg, #ebf8ff, #f0fff4); border-bottom: 1px solid #bee3f8;' : '' }} display: flex; align-items: flex-start; justify-content: space-between;">
                    @if($bannerName !== null)
                        <div style="display: flex; flex-direction: column; gap: 2px;">
                            <span style="font-size: 11px; font-weight: 700; color: #2c5282; text-transform: uppercase; letter-spacing: 0.08em;">Client</span>
                            <span style="font-size: 24px; font-weight: 700; color: #1a365d; line-height: 1.1;">{{ $bannerName }}</span>
                        </div>
                        @if($currentBalance !== null)
                        <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 2px;">
                            <span style="font-size: 11px; font-weight: 700; color: #2c5282; text-transform: uppercase; letter-spacing: 0.08em;">Current Balance</span>
                            <span style="font-size: 24px; font-weight: 700; color: {{ $currentBalance >= 0 ? '#276749' : '#c53030' }}; line-height: 1.1;">{{ ($currentBalance >= 0 ? '' : '-') . '$' . number_format(abs($currentBalance), 2) }}</span>
                        </div>
                        @endif
                    @endif
                </div>

                <div style="overflow-x: clip;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f7fafc; border-bottom: 2px solid #e2e8f0; position: sticky; top: 0; z-index: 10;">
                                <th data-col="trx-id" style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Txn ID</th>
                                <th data-col="source-id" style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Source ID</th>
                                @if($bannerName === null)
                                    <th data-col="client-name" style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Client Name</th>
                                @endif
                                <th data-col="rep-code" style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Rep Code</th>
                                <th data-col="plan-account-id" style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Plan Account ID</th>
                                <th data-col="trx-type" style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Txn Type</th>
                                <th data-col="trx-type-detail" style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Txn Type Detail</th>
                                <th data-col="created-date" style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Created Date</th>
                                <th data-col="trade-date" style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Trade Date</th>
                                <th data-col="debit" style="padding: 12px; text-align: right; font-weight: 600; color: #2d3748;">Debit</th>
                                <th data-col="credit" style="padding: 12px; text-align: right; font-weight: 600; color: #2d3748;">Credit</th>
                                <th data-col="running-balance" style="padding: 12px; text-align: right; font-weight: 600; color: #2d3748;" title="True historical running balance from local sync. Shows ~approximate if sync has not run.">Running Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($transactions as $txn)
                                <tr class="remote-viefund-row" style="border-bottom: 1px solid #e2e8f0; cursor: pointer;"
                                    data-trx-id="{{ $txn->trx_id }}"
                                    data-source-id="{{ $txn->source_id }}"
                                    data-cash-trx-id="{{ $txn->cash_trx_id }}"
                                    data-client-name="{{ $txn->client_name }}"
                                    data-rep-code="{{ $txn->rep_code }}"
                                    data-plan-dealer-account-id="{{ $txn->plan_dealer_account_id }}"
                                    data-trx-type="{{ $txn->trx_type }}"
                                    data-cash-trx-type="{{ $txn->cash_trx_type }}"
                                    data-fund-wo-number="{{ $txn->fund_wo_number }}"
                                    data-created-date="{{ $txn->created_date }}"
                                    data-trade-date="{{ $txn->trade_date }}"
                                    data-amount="{{ $txn->amount }}"
                                    data-balance="{{ $txn->balance }}"
                                    data-running-balance="{{ $localBalances[$txn->cash_trx_id] ?? '' }}">
                                    <td data-col="trx-id" style="padding: 12px; color: #4a5568; font-family: monospace; font-size: 12px;">{{ $txn->trx_id ?? '-' }}</td>
                                    <td data-col="source-id" style="padding: 12px; color: #4a5568; font-family: monospace; font-size: 12px;">{{ $txn->source_id ?? '-' }}</td>
                                    @if($bannerName === null)
                                        <td data-col="client-name" style="padding: 12px; color: #2d3748; font-family: monospace;">{{ $txn->client_name ?: '-' }}</td>
                                    @endif
                                    <td data-col="rep-code" style="padding: 12px; color: #4a5568; font-family: monospace;">{{ $txn->rep_code ?? '-' }}</td>
                                    <td data-col="plan-account-id" style="padding: 12px; color: #4a5568; font-family: monospace;">{{ $txn->plan_dealer_account_id ?? '-' }}</td>
                                    <td data-col="trx-type" style="padding: 12px; color: #4a5568; font-family: monospace;">{{ $txn->trx_type ?? '-' }}</td>
                                    <td data-col="trx-type-detail" style="padding: 12px; color: #4a5568; font-family: monospace;">{{ $txn->cash_trx_type ?? '-' }}</td>
                                    <td data-col="created-date" style="padding: 12px; color: #4a5568; font-family: monospace;">{{ $txn->created_date ?? '-' }}</td>
                                    <td data-col="trade-date" style="padding: 12px; color: #4a5568; font-family: monospace;">{{ $txn->trade_date ?? '-' }}</td>
                                    <td data-col="debit" style="padding: 12px; text-align: right; color: #e53e3e; font-weight: 500; font-family: monospace;">
                                        @if($txn->amount !== null && (float) $txn->amount < 0)
                                            ${{ number_format(abs((float) $txn->amount), 2) }}
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td data-col="credit" style="padding: 12px; text-align: right; color: #276749; font-weight: 500; font-family: monospace;">
                                        @if($txn->amount !== null && (float) $txn->amount >= 0)
                                            ${{ number_format((float) $txn->amount, 2) }}
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td data-col="running-balance" class="calc-balance-cell" style="padding: 12px; text-align: right; font-weight: 500; font-family: monospace; color: #2d3748;">-</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                @php
                    $cur  = $transactions->currentPage();
                    $last = $transactions->lastPage();
                    $pgBtnBase = 'display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:30px;padding:0 8px;border-radius:4px;font-size:13px;text-decoration:none;border:1px solid #cbd5e0;color:#4a5568;';
                    $pgBtnActive = 'display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:30px;padding:0 8px;border-radius:4px;font-size:13px;font-weight:700;border:2px solid #4299e1;color:#2b6cb0;background:#ebf8ff;';
                    $pgBtnDisabled = 'display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:30px;padding:0 8px;border-radius:4px;font-size:13px;border:1px solid #e2e8f0;color:#cbd5e0;cursor:default;';
                    $pages = array_unique(array_filter([1, max(1,$cur-1), $cur, min($last,$cur+1), $last], fn($p) => $p >= 1 && $p <= $last));
                    sort($pages);
                @endphp
                <div style="padding: 14px 16px; border-top: 1px solid #e2e8f0; display: flex; align-items: center; gap: 14px; font-size: 13px; color: #4a5568; flex-wrap: wrap;">

                    {{-- Per-page dropdown --}}
                    <div style="display:flex;align-items:center;gap:6px;">
                        <label for="per-page-select" style="color:#718096;white-space:nowrap;">Rows per page:</label>
                        <select id="per-page-select"
                                onchange="window.location = this.value"
                                style="border:1px solid #cbd5e0;border-radius:4px;padding:4px 8px;font-size:13px;color:#2d3748;background:#fff;cursor:pointer;">
                            @foreach ([50, 100, 250] as $opt)
                                <option value="{{ request()->fullUrlWithQuery(['per_page' => $opt, 'page' => 1]) }}"
                                        {{ (int) request('per_page', 50) === $opt ? 'selected' : '' }}>{{ $opt }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Summary --}}
                    <span style="color:#718096;white-space:nowrap;">
                        Showing {{ number_format($transactions->firstItem()) }}–{{ number_format($transactions->lastItem()) }} of {{ number_format($transactions->total()) }} transactions
                    </span>

                    {{-- Page buttons --}}
                    @if ($last > 1)
                        <div style="display:flex;align-items:center;gap:4px;margin-left:auto;">
                            {{-- Prev --}}
                            @if ($transactions->onFirstPage())
                                <span style="{{ $pgBtnDisabled }}">‹</span>
                            @else
                                <a href="{{ $transactions->previousPageUrl() }}" style="{{ $pgBtnBase }}">‹</a>
                            @endif

                            @php $prevPage = null; @endphp
                            @foreach ($pages as $p)
                                @if ($prevPage !== null && $p > $prevPage + 1)
                                    <span style="{{ $pgBtnDisabled }}">…</span>
                                @endif
                                @if ($p === $cur)
                                    <span style="{{ $pgBtnActive }}">{{ $p }}</span>
                                @else
                                    <a href="{{ $transactions->url($p) }}{{ request('per_page', 50) != 50 ? '&per_page='.request('per_page') : '' }}" style="{{ $pgBtnBase }}">{{ $p }}</a>
                                @endif
                                @php $prevPage = $p; @endphp
                            @endforeach

                            {{-- Next --}}
                            @if ($transactions->hasMorePages())
                                <a href="{{ $transactions->nextPageUrl() }}" style="{{ $pgBtnBase }}">›</a>
                            @else
                                <span style="{{ $pgBtnDisabled }}">›</span>
                            @endif
                        </div>
                    @endif

                </div>
            @else
                <div style="text-align: center; padding: 40px; color: #718096;">
                    <p style="font-size: 18px; margin-bottom: 10px;">📂 No transactions found</p>
                    <p>{{ $search ? 'Try a different search term.' : 'No data returned from the remote database.' }}</p>
                </div>
            @endif
        </div>

        <!-- Transaction Details Modal -->
        <div id="remote-viefund-modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); align-items: center; justify-content: center; z-index: 50;">
            <div style="background: #fff; width: 90%; max-width: 700px; border-radius: 10px; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.2);">
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; border-bottom: 1px solid #e2e8f0;">
                    <h3 style="margin: 0; color: #2d3748;">Remote VieFund Transaction Details</h3>
                    <button type="button" id="remote-modal-close" class="btn" style="background: #718096; padding: 6px 12px;">Close</button>
                </div>
                <div style="padding: 20px; max-height: 70vh; overflow: auto;">
                    <div style="display: grid; grid-template-columns: 180px 1fr; row-gap: 12px; column-gap: 16px; align-items: baseline;">
                        <div style="font-weight: 600; color: #2d3748; font-size: 15px;">Fund Txn ID</div><div id="m-trx-id" style="font-family: monospace; font-size: 15px;"></div>
                        <div style="font-weight: 600; color: #2d3748; font-size: 15px;">Source ID</div><div id="m-source-id" style="font-family: monospace; font-size: 15px;"></div>
                        <div style="font-weight: 600; color: #2d3748; font-size: 15px;">Cash Txn ID</div><div id="m-cash-trx-id" style="font-family: monospace; font-size: 15px;"></div>
                        <div style="font-weight: 600; color: #2d3748; font-size: 15px;">Client Name</div><div id="m-client-name" style="font-family: monospace; font-size: 15px;"></div>
                        <div style="font-weight: 600; color: #2d3748; font-size: 15px;">Rep Code</div><div id="m-rep-code" style="font-family: monospace; font-size: 15px;"></div>
                        <div style="font-weight: 600; color: #2d3748; font-size: 15px;">Plan Account ID</div><div id="m-plan-dealer-account-id" style="font-family: monospace; font-size: 15px;"></div>
                        <div style="font-weight: 600; color: #2d3748; font-size: 15px;">Txn Type</div><div id="m-trx-type" style="font-family: monospace; font-size: 15px;"></div>
                        <div style="font-weight: 600; color: #2d3748; font-size: 15px;">Txn Type Detail</div><div id="m-cash-trx-type" style="font-family: monospace; font-size: 15px;"></div>
                        <div style="font-weight: 600; color: #2d3748; font-size: 15px;">Fund WO#</div><div id="m-fund-wo-number" style="font-family: monospace; font-size: 15px;"></div>
                        <div style="font-weight: 600; color: #2d3748; font-size: 15px;">Created Date</div><div id="m-created-date" style="font-family: monospace; font-size: 15px;"></div>
                        <div style="font-weight: 600; color: #2d3748; font-size: 15px;">Trade Date</div><div id="m-trade-date" style="font-family: monospace; font-size: 15px;"></div>
                        <div style="font-weight: 600; color: #2d3748; font-size: 15px;">Amount</div><div id="m-amount" style="font-family: monospace; font-size: 15px;"></div>
                        <div style="font-weight: 600; color: #2d3748; font-size: 15px;">VieFund Balance</div><div id="m-balance" style="font-family: monospace; font-size: 15px;"></div>
                    </div>
                </div>
            </div>
        </div>

        <script>
        (function () {
            var header      = document.getElementById('filter-card-header');
            var body        = document.getElementById('filter-body');
            var chevron     = document.getElementById('filter-chevron');
            var summary     = document.getElementById('filter-summary');
            var toggleLabel = document.getElementById('filter-toggle-label');
            if (!header || !body) return;

            var hasFilters = {{ $hasActiveFilters ? 'true' : 'false' }};
            var collapsed  = true; // always start collapsed on page load

            function applyState() {
                if (collapsed) {
                    body.style.display      = 'none';
                    summary.style.display   = 'flex';
                    chevron.style.transform = 'rotate(-90deg)';
                    if (toggleLabel) toggleLabel.textContent = 'Expand';
                } else {
                    body.style.display      = '';
                    summary.style.display   = 'none';
                    chevron.style.transform = 'rotate(0deg)';
                    if (toggleLabel) toggleLabel.textContent = 'Collapse';
                }
            }

            header.addEventListener('click', function () {
                collapsed = !collapsed;
                applyState();
            });

            applyState();
        })();
        </script>

        <script>
        (function () {
            var banner  = document.getElementById('client-banner');
            var theadTr = document.querySelector('tr[style*="sticky"]');
            if (banner && theadTr) {
                theadTr.style.top = banner.offsetHeight + 'px';
            }
        })();
        </script>

        <script>
            (function () {
                const modal = document.getElementById('remote-viefund-modal');
                const closeBtn = document.getElementById('remote-modal-close');

                if (!modal || !closeBtn) return;

                const fill = (id, value) => {
                    const el = document.getElementById(id);
                    if (el) el.textContent = value && String(value).trim() !== '' ? value : '-';
                };

                const openModal = (row) => {
                    fill('m-trx-id',               row.dataset.trxId);
                    fill('m-source-id',             row.dataset.sourceId);
                    fill('m-cash-trx-id',           row.dataset.cashTrxId);
                    fill('m-client-name',           row.dataset.clientName);
                    fill('m-rep-code',              row.dataset.repCode);
                    fill('m-plan-dealer-account-id',row.dataset.planDealerAccountId);
                    fill('m-trx-type',              row.dataset.trxType);
                    fill('m-cash-trx-type',         row.dataset.cashTrxType);
                    fill('m-fund-wo-number',        row.dataset.fundWoNumber);
                    fill('m-created-date',          row.dataset.createdDate);
                    fill('m-trade-date',            row.dataset.tradeDate);
                    fill('m-amount',  row.dataset.amount  ? '$' + parseFloat(row.dataset.amount).toFixed(2)  : '-');
                    fill('m-balance', row.dataset.balance ? '$' + parseFloat(row.dataset.balance).toFixed(2) : '-');
                    modal.style.display = 'flex';
                };

                // ── Step 1: Re-order rows that share the same TXN ID by balance chain ──
                (function reorderByBalanceChain() {
                    const allRows = Array.from(document.querySelectorAll('.remote-viefund-row'));
                    if (allRows.length === 0) return;

                    const groups = {};
                    allRows.forEach(row => {
                        const id = row.dataset.trxId || '__no_id__';
                        if (!groups[id]) groups[id] = [];
                        groups[id].push(row);
                    });

                    Object.values(groups).forEach(group => {
                        if (group.length <= 1) return;

                        const byBalance = {};
                        group.forEach(row => {
                            const bal = parseFloat(row.dataset.balance);
                            if (!isNaN(bal)) byBalance[bal.toFixed(2)] = row;
                        });

                        let startRow = byBalance['0.00'];
                        if (!startRow) {
                            let minAbs = Infinity;
                            group.forEach(row => {
                                const abs = Math.abs(parseFloat(row.dataset.balance));
                                if (!isNaN(abs) && abs < minAbs) { minAbs = abs; startRow = row; }
                            });
                        }
                        if (!startRow) return;

                        const sorted = [];
                        const visited = new Set();
                        let current = startRow;
                        while (current && !visited.has(current)) {
                            sorted.push(current);
                            visited.add(current);
                            const bal = parseFloat(current.dataset.balance) || 0;
                            const amt = parseFloat(current.dataset.amount) || 0;
                            current = byBalance[(bal - amt).toFixed(2)] ?? null;
                        }
                        group.forEach(row => { if (!visited.has(row)) sorted.push(row); });

                        const parent = group[0].parentNode;
                        const anchor = group[group.length - 1].nextSibling;
                        group.forEach(row => parent.removeChild(row));
                        sorted.forEach(row => parent.insertBefore(row, anchor));
                    });
                })();

                // ── Step 2: Group rows with the same TXN ID into expandable sets ──
                (function groupByTrxId() {
                    const tbody = document.querySelector('tbody');
                    if (!tbody) return;

                    // Collect consecutive runs of rows sharing a trx_id
                    const runs = [];
                    Array.from(tbody.querySelectorAll('.remote-viefund-row')).forEach(row => {
                        const tid = row.dataset.trxId;
                        const last = runs[runs.length - 1];
                        if (last && last.id === tid) { last.rows.push(row); }
                        else { runs.push({ id: tid, rows: [row] }); }
                    });

                    runs.forEach(({ rows }) => {
                        if (rows.length <= 1) return;

                        const [parentRow, ...origChildren] = rows;
                        const count = rows.length;

                        // Clone parentRow BEFORE modifying it so its original transaction
                        // data is preserved and shown as the first child when expanded.
                        const parentDetailRow = parentRow.cloneNode(true);
                        parentRow.insertAdjacentElement('afterend', parentDetailRow);
                        const children = [parentDetailRow, ...origChildren];

                        // Running balance from the child with the highest cash_trx_id
                        const lastChild = rows.reduce((best, r) =>
                            (parseInt(r.dataset.cashTrxId) || 0) > (parseInt(best.dataset.cashTrxId) || 0) ? r : best
                        , rows[0]);
                        parentRow.dataset.runningBalance = lastChild.dataset.runningBalance;

                        // Aggregate debit / credit totals across all sub-rows
                        let debitCents = 0, creditCents = 0;
                        rows.forEach(r => {
                            const amt = parseFloat(r.dataset.amount) || 0;
                            if (amt < 0) debitCents  += Math.round(Math.abs(amt) * 10000);
                            else         creditCents += Math.round(amt * 10000);
                        });

                        // ── Modify parent: add toggle button + count badge ──
                        const tdTrxId = parentRow.querySelector('[data-col="trx-id"]');
                        const trxIdText = tdTrxId.textContent.trim();
                        tdTrxId.innerHTML =
                            `<div style="display:flex;align-items:center;gap:6px;">` +
                            `<button class="trx-toggle-btn" style="background:none;border:none;` +
                            `cursor:pointer;padding:2px 5px;color:#38a169;font-size:13px;` +
                            `line-height:1;flex-shrink:0;" title="Expand/collapse sub-transactions">▶</button>` +
                            `<span style="font-family:monospace;font-size:12px;">${trxIdText}</span>` +
                            `<span style="background:#38a169;color:#fff;border-radius:10px;padding:1px 8px;` +
                            `font-size:11px;font-weight:700;flex-shrink:0;" title="${count} sub-transactions">×${count}</span>` +
                            `</div>`;

                        // Replace debit / credit cells with totals
                        if (debitCents > 0) {
                            parentRow.querySelector('[data-col="debit"]').innerHTML =
                                `<span style="color:#e53e3e;font-weight:500;font-family:monospace;">` +
                                `$${(debitCents / 10000).toFixed(2)}</span>`;
                        }
                        if (creditCents > 0) {
                            parentRow.querySelector('[data-col="credit"]').innerHTML =
                                `<span style="color:#276749;font-weight:500;font-family:monospace;">` +
                                `$${(creditCents / 10000).toFixed(2)}</span>`;
                        }

                        // Toggle expand / collapse
                        const btn = tdTrxId.querySelector('.trx-toggle-btn');

                        // Clear txn type detail on parent — it belongs to individual rows
                        const tdTypeDetail = parentRow.querySelector('[data-col="trx-type-detail"]');
                        if (tdTypeDetail) tdTypeDetail.innerHTML = '<span style="color:#cbd5e0;">—</span>';

                        let expanded = false;
                        const toggle = (e) => {
                            e.stopPropagation();
                            expanded = !expanded;
                            btn.textContent = expanded ? '▼' : '▶';
                            children.forEach(r => { r.style.display = expanded ? '' : 'none'; });
                        };
                        btn.addEventListener('click', toggle);
                        parentRow.addEventListener('click', toggle);

                        // ── Style children and dim redundant fields ──
                        const dimCell = (cell) => {
                            cell.innerHTML = '<span style="color:#cbd5e0;">—</span>';
                        };
const sharedCols = [
                                    ['source-id',       'sourceId'],
                                    ['client-name',    'clientName'],
                                    ['rep-code',        'repCode'],
                                    ['plan-account-id', 'planDealerAccountId'],
                                    ['trx-type',        'trxType'],
                                    ['created-date',    'createdDate'],
                                    ['trade-date',      'tradeDate'],
                                ];

                        children.forEach(child => {
                            child.classList.add('viefund-detail-row');
                            child.style.display = 'none';
                            child.style.borderLeft = '3px solid #c6f6d5';
                            child.style.backgroundColor = '#f7fff9';

                            // Indent indicator in place of the Txn ID
                            child.querySelector('[data-col="trx-id"]').innerHTML =
                                '<span style="padding-left:24px;color:#68d391;font-size:13px;">↳</span>';

                            // Dim fields that match parent (works regardless of column visibility)
                            sharedCols.forEach(([colName, dataKey]) => {
                                const cell = child.querySelector(`[data-col="${colName}"]`);
                                if (cell && child.dataset[dataKey] === parentRow.dataset[dataKey]) {
                                    dimCell(cell);
                                }
                            });

                            // Detail rows open the modal on click
                            child.style.cursor = 'pointer';
                            child.addEventListener('click', (e) => {
                                e.stopPropagation();
                                openModal(child);
                            });
                        });
                    });
                })();

                // ── Step 3: Alternate row colours on parent / ungrouped rows only ──
                const topRows = Array.from(document.querySelectorAll(
                    '.remote-viefund-row:not(.viefund-detail-row)'
                ));
                if (topRows.length === 0) return;
                topRows.forEach((row, i) => {
                    if (i % 2 !== 0) row.style.backgroundColor = 'rgba(56, 161, 105, 0.07)';
                });

                // ── Step 4: Running Balance ──
                (function renderRunningBalance() {
                    document.querySelectorAll('.remote-viefund-row').forEach(row => {
                        const cell = row.querySelector('.calc-balance-cell');
                        if (!cell) return;

                        const synced = row.dataset.runningBalance;
                        if (synced !== undefined && synced !== '') {
                            const val = parseFloat(synced);
                            cell.textContent = '$' + val.toFixed(2);
                            cell.style.color  = val < 0 ? '#e53e3e' : (val > 0 ? '#276749' : '#718096');
                            cell.title = 'Synced running balance';
                        } else {
                            cell.innerHTML = '<span style="color:#cbd5e0;">—</span>';
                            cell.title = '';
                        }
                    });
                })();

                // ── Step 5: Click-to-open modal for ungrouped / single rows ──
                topRows.forEach(row => {
                    if (row.querySelector('.trx-toggle-btn')) return; // grouped parent — toggle handles click
                    row.style.cursor = 'pointer';
                    row.addEventListener('click', () => openModal(row));
                });

                closeBtn.addEventListener('click', () => { modal.style.display = 'none'; });
                modal.addEventListener('click', (e) => { if (e.target === modal) modal.style.display = 'none'; });
            })();
        </script>

        <script>
            // ── Plan Account ID autocomplete ──
            (function () {
                const input    = document.getElementById('plan-account-search-input');
                const dropdown = document.getElementById('plan-account-dropdown');
                const hidden   = document.getElementById('filter-account-id');

                if (!input || !dropdown || !hidden) return;

                const pill      = document.getElementById('account-pill');
                const pillLabel = document.getElementById('account-pill-label');
                const pillX     = document.getElementById('account-pill-x');

                let debounceTimer = null;
                const closeDropdown = () => { dropdown.style.display = 'none'; dropdown.innerHTML = ''; };

                const selectAccount = (accountId) => {
                    hidden.value = accountId;
                    if (pillLabel) pillLabel.textContent = accountId;
                    if (pill)      pill.style.display    = 'inline-flex';
                    input.style.display = 'none';
                    closeDropdown();
                };

                if (pillX) {
                    pillX.addEventListener('click', (e) => {
                        e.stopPropagation();
                        hidden.value        = '';
                        if (pill) pill.style.display = 'none';
                        input.style.display = '';
                        input.value         = '';
                        input.focus();
                    });
                }

                input.addEventListener('input', () => {
                    clearTimeout(debounceTimer);
                    const q = input.value.trim();
                    if (q.length < 2) { closeDropdown(); return; }
                    debounceTimer = setTimeout(() => {
                        fetch('/remote-viefund/plan-accounts?search=' + encodeURIComponent(q))
                            .then(r => r.json())
                            .then(results => {
                                closeDropdown();
                                if (!results.length) return;
                                results.forEach(r => {
                                    const item = document.createElement('div');
                                    item.style.cssText = 'padding: 9px 14px; cursor: pointer; font-size: 14px; border-bottom: 1px solid #f0f0f0;';
                                    item.innerHTML = `<span style="font-family:monospace;font-weight:600;">${r.account_id}</span>` +
                                        (r.customer_name ? `<span style="color:#718096;"> — ${r.customer_name}</span>` : '');
                                    item.addEventListener('mouseenter', () => { item.style.background = '#ebf8ff'; });
                                    item.addEventListener('mouseleave', () => { item.style.background = ''; });
                                    item.addEventListener('mousedown', (e) => { e.preventDefault(); selectAccount(r.account_id); });
                                    dropdown.appendChild(item);
                                });
                                dropdown.style.display = 'block';
                            })
                            .catch(() => closeDropdown());
                    }, 250);
                });

                input.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeDropdown(); });
                input.addEventListener('blur', () => { setTimeout(closeDropdown, 150); });
            })();

            // ── Customer autocomplete ──
            (function () {
                const input      = document.getElementById('customer-search-input');
                const dropdown   = document.getElementById('customer-dropdown');
                const hiddenId   = document.getElementById('filter-customer-id');
                const hiddenName = document.getElementById('filter-customer-name');

                if (!input || !dropdown || !hiddenId || !hiddenName) return;

                const pill      = document.getElementById('customer-pill');
                const pillLabel = document.getElementById('customer-pill-label');
                const pillX     = document.getElementById('customer-pill-x');

                let debounceTimer = null;

                const closeDropdown = () => { dropdown.style.display = 'none'; dropdown.innerHTML = ''; };

                const selectCustomer = (id, name) => {
                    hiddenId.value   = id;
                    hiddenName.value = name;
                    if (pillLabel) pillLabel.textContent = name;
                    if (pill)      pill.style.display    = 'inline-flex';
                    input.style.display = 'none';
                    closeDropdown();
                };

                if (pillX) {
                    pillX.addEventListener('click', (e) => {
                        e.stopPropagation();
                        hiddenId.value       = '';
                        hiddenName.value     = '';
                        if (pill) pill.style.display = 'none';
                        input.style.display  = '';
                        input.value          = '';
                        input.focus();
                    });
                }

                input.addEventListener('input', () => {
                    clearTimeout(debounceTimer);
                    const q = input.value.trim();
                    if (q.length < 2) { closeDropdown(); return; }
                    debounceTimer = setTimeout(() => {
                        fetch('/remote-viefund/customers?search=' + encodeURIComponent(q))
                            .then(r => r.json())
                            .then(customers => {
                                closeDropdown();
                                if (!customers.length) return;
                                customers.forEach(c => {
                                    const item = document.createElement('div');
                                    item.textContent = c.name;
                                    item.style.cssText = 'padding: 9px 14px; cursor: pointer; font-size: 14px; border-bottom: 1px solid #f0f0f0;';
                                    item.addEventListener('mouseenter', () => { item.style.background = '#ebf8ff'; });
                                    item.addEventListener('mouseleave', () => { item.style.background = ''; });
                                    item.addEventListener('mousedown', (e) => { e.preventDefault(); selectCustomer(c.id, c.name); });
                                    dropdown.appendChild(item);
                                });
                                dropdown.style.display = 'block';
                            })
                            .catch(() => closeDropdown());
                    }, 250);
                });

                // Clear the hidden id if the user manually clears the text field
                input.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape') { closeDropdown(); }
                    if (e.key === 'Enter' && hiddenId.value) { closeDropdown(); }
                });
                input.addEventListener('blur', () => { setTimeout(closeDropdown, 150); });
            })();
        </script>

        <script>
        // ── Tag inputs (Txn ID and Source ID) ─────────────────────────────
        (function () {
            function initTagInput(containerId, inputId, hiddenId) {
                const container = document.getElementById(containerId);
                const input     = document.getElementById(inputId);
                const hidden    = document.getElementById(hiddenId);
                if (!container || !input || !hidden) return;

                function getTags() {
                    return Array.from(container.querySelectorAll('.tag-pill')).map(p => p.dataset.value);
                }

                function syncHidden() {
                    const tags = getTags();
                    hidden.value       = tags.join(', ');
                    input.placeholder  = tags.length === 0 ? (input.dataset.defaultPlaceholder || '') : '';
                }

                function addTag(val) {
                    const v = val.trim();
                    if (!v || getTags().includes(v)) return;
                    const pill  = document.createElement('div');
                    pill.className    = 'tag-pill';
                    pill.dataset.value = v;
                    pill.style.cssText = 'display: inline-flex; align-items: center; gap: 2px; background: #4299e1; color: #fff; font-size: 12px; font-weight: 500; padding: 2px 4px 2px 8px; border-radius: 3px; white-space: nowrap; font-family: monospace;';
                    const label = document.createElement('span');
                    label.textContent = v;
                    const x = document.createElement('span');
                    x.className   = 'tag-x';
                    x.textContent = '×';
                    x.style.cssText = 'cursor: pointer; font-size: 14px; line-height: 1; opacity: 0.75; padding: 0 2px;';
                    x.addEventListener('click', (e) => { e.stopPropagation(); pill.remove(); syncHidden(); });
                    pill.appendChild(label);
                    pill.appendChild(x);
                    container.insertBefore(pill, input);
                    syncHidden();
                }

                // Wire server-side-rendered pills
                container.querySelectorAll('.tag-pill .tag-x').forEach(x => {
                    x.addEventListener('click', (e) => { e.stopPropagation(); x.closest('.tag-pill').remove(); syncHidden(); });
                });

                // Click anywhere in container focuses the input
                container.addEventListener('click', () => input.focus());

                input.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        input.value.split(',').forEach(v => addTag(v));
                        input.value = '';
                    } else if (e.key === 'Backspace' && input.value === '') {
                        const pills = container.querySelectorAll('.tag-pill');
                        if (pills.length) { pills[pills.length - 1].remove(); syncHidden(); }
                    }
                });

                // Typing a comma immediately finalizes the current tag
                input.addEventListener('input', () => {
                    if (input.value.endsWith(',')) {
                        const val = input.value.slice(0, -1);
                        if (val.trim()) addTag(val);
                        input.value = '';
                    }
                });

                syncHidden();
            }

            initTagInput('trx-id-tags',    'trx-id-input',    'filter-trx-id');
            initTagInput('source-id-tags', 'source-id-input', 'filter-source-id');

            // ── Date pill inputs ──────────────────────────────────────────
            function initDatePill(pickerId, pillId, pillLabelId, pillXId, hiddenId) {
                const picker    = document.getElementById(pickerId);
                const pill      = document.getElementById(pillId);
                const pillLabel = document.getElementById(pillLabelId);
                const pillX     = document.getElementById(pillXId);
                const hidden    = document.getElementById(hiddenId);
                if (!picker || !pill || !pillLabel || !pillX || !hidden) return;

                picker.addEventListener('change', () => {
                    const val = picker.value;
                    if (!val) return;
                    hidden.value          = val;
                    pillLabel.textContent = val;
                    pill.style.display    = 'inline-flex';
                    picker.style.display  = 'none';
                });

                pillX.addEventListener('click', (e) => {
                    e.stopPropagation();
                    hidden.value         = '';
                    picker.value         = '';
                    pill.style.display   = 'none';
                    picker.style.display = '';
                });
            }

            initDatePill('created-from-picker', 'created-from-pill', 'created-from-pill-label', 'created-from-pill-x', 'filter-created-from');
            initDatePill('created-to-picker',   'created-to-pill',   'created-to-pill-label',   'created-to-pill-x',   'filter-created-to');
        })();
        </script>

        <script>
        // ── Multi-select widget init ──────────────────────────────────────
        (function () {
            function initMs(wrap) {
                const trigger     = wrap.querySelector('.ms-trigger');
                const panel       = wrap.querySelector('.ms-panel');
                const tagsEl      = wrap.querySelector('.ms-tags');
                const placeholder = wrap.querySelector('.ms-placeholder');
                const allItem     = panel.querySelector('.ms-select-all');
                const items       = Array.from(panel.querySelectorAll('.ms-item:not(.ms-select-all)'));

                // Sync initial checked state from hidden checkboxes
                items.forEach(item => {
                    const cb = item.querySelector('input[type="checkbox"]');
                    if (cb && cb.checked) item.classList.add('checked');
                });

                function refreshTags() {
                    tagsEl.innerHTML = '';
                    const checked = items.filter(i => i.classList.contains('checked'));
                    placeholder.style.display = checked.length ? 'none' : '';
                    checked.forEach(item => {
                        const val = item.dataset.value;
                        const tag = document.createElement('span');
                        tag.className = 'ms-tag';
                        const lbl = document.createElement('span');
                        lbl.className = 'ms-tag-label';
                        lbl.title = val;
                        lbl.textContent = val;
                        const x = document.createElement('span');
                        x.className = 'ms-tag-x';
                        x.innerHTML = '&times;';
                        x.addEventListener('mousedown', e => {
                            e.preventDefault(); e.stopPropagation();
                            item.classList.remove('checked');
                            const cb = item.querySelector('input[type="checkbox"]');
                            if (cb) cb.checked = false;
                            refreshTags(); refreshSelectAll();
                        });
                        tag.appendChild(lbl); tag.appendChild(x);
                        tagsEl.appendChild(tag);
                    });
                }

                function refreshSelectAll() {
                    if (!allItem) return;
                    const allChecked = items.length > 0 && items.every(i => i.classList.contains('checked'));
                    allItem.classList.toggle('checked', allChecked);
                }

                trigger.addEventListener('click', e => {
                    e.stopPropagation();
                    wrap.classList.toggle('open');
                });

                if (allItem) {
                    allItem.addEventListener('click', () => {
                        const toCheck = !items.every(i => i.classList.contains('checked'));
                        items.forEach(item => {
                            item.classList.toggle('checked', toCheck);
                            const cb = item.querySelector('input[type="checkbox"]');
                            if (cb) cb.checked = toCheck;
                        });
                        refreshTags(); refreshSelectAll();
                    });
                }

                items.forEach(item => {
                    item.addEventListener('click', () => {
                        item.classList.toggle('checked');
                        const cb = item.querySelector('input[type="checkbox"]');
                        if (cb) cb.checked = item.classList.contains('checked');
                        refreshTags(); refreshSelectAll();
                    });
                });

                document.addEventListener('click', e => {
                    if (!wrap.contains(e.target)) wrap.classList.remove('open');
                });

                refreshTags();
                refreshSelectAll();
            }

            document.querySelectorAll('.ms-wrap').forEach(initMs);
        })();

        // ── Sync status polling ───────────────────────────────────────────────
        // When a sync is in progress, poll every 5 s and update the badge live.
        (function () {
            var inProgress = {{ $syncInProgress ? 'true' : 'false' }};
            if (!inProgress) return;

            var badge = document.getElementById('sync-status-badge');
            var timer = setInterval(function () {
                fetch('{{ route('remote-viefund.sync-status') }}', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.inProgress) return; // still running — keep polling

                        clearInterval(timer);
                        if (data.syncNeeded) {
                            // Finished but still behind — show sync button (reload for CSRF token)
                            location.reload();
                        } else {
                            // All caught up
                            badge.innerHTML = '<span style="background:#c6f6d5;color:#276749;border-radius:20px;padding:4px 14px;font-size:13px;font-weight:600;">✓ Transactions Synced</span>';
                        }
                    })
                    .catch(function () { /* network blip — keep polling */ });
            }, 5000);
        })();
        </script>
    @endif
@endsection
