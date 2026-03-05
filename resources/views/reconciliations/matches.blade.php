@extends('layouts.app')

@section('title', 'Reconciliation Review')

@section('content')
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 style="margin: 0;">Reconciliation Review</h2>
        <div style="display: flex; gap: 10px; align-items: center;">
            <form method="POST" action="{{ route('reconciliations.matches.find') }}" class="match-action-form" data-action="find">
                @csrf
                <button type="submit" class="btn find-matches-btn" style="background: #2f855a; padding: 10px 20px;">Find Matches</button>
            </form>
            <form method="POST" action="{{ route('reconciliations.matches.delete') }}" class="match-action-form" data-action="delete" onsubmit="return confirm('Delete all reconciliation matches?');">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn delete-matches-btn" style="background: #c53030; padding: 10px 20px;">Delete Matches</button>
            </form>
        </div>
    </div>

    <!-- Matching Progress Section -->
    <div id="matching-progress-section" style="display: none; margin-bottom: 20px;" class="card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
            <div>
                <h3 style="margin: 0; font-size: 16px; font-weight: 600;">Matching in Progress</h3>
                <div id="progress-current-pass" style="font-size: 12px; color: #718096; margin-top: 4px;">Initializing...</div>
            </div>
            <span id="progress-percentage" style="font-weight: bold; color: #2f855a; font-size: 20px;">0%</span>
        </div>
        <div style="background: #e2e8f0; height: 28px; border-radius: 4px; overflow: hidden; margin-bottom: 12px; position: relative;">
            <div id="progress-bar" style="background: linear-gradient(90deg, #2f855a 0%, #38a169 100%); height: 100%; width: 0%; transition: width 0.3s ease; display: flex; align-items: center; justify-content: center;">
                <span id="progress-text" style="color: white; font-size: 12px; font-weight: bold; text-shadow: 0 1px 2px rgba(0,0,0,0.3); white-space: nowrap;">0%</span>
            </div>
        </div>
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; font-size: 14px;">
            <div style="background: #f7fafc; padding: 8px; border-radius: 4px;">
                <div style="color: #718096; font-size: 12px;">Processed</div>
                <div style="font-weight: bold; color: #2d3748;"><span id="progress-processed">0</span> / <span id="progress-total">0</span></div>
            </div>
            <div style="background: #f7fafc; padding: 8px; border-radius: 4px;">
                <div style="color: #718096; font-size: 12px;">Matches Created</div>
                <div style="font-weight: bold; color: #2d3748;"><span id="progress-matched">0</span></div>
            </div>
            <div style="background: #f7fafc; padding: 8px; border-radius: 4px;">
                <div style="color: #718096; font-size: 12px;">Session</div>
                <div style="font-weight: bold; color: #2d3748;"><span id="progress-session">-</span></div>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="card" style="background: #f0fff4; color: #22543d; margin-bottom: 20px; padding: 12px 16px; border: 1px solid #c6f6d5;">
            {{ session('success') }}
        </div>
    @endif

    @if(session('info'))
        <div class="card" style="background: #ebf8ff; color: #2c5282; margin-bottom: 20px; padding: 12px 16px; border: 1px solid #90cdf4;">
            {{ session('info') }}
        </div>
    @endif

    <!-- Summary Cards -->
    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 20px;">
        <div class="card" style="background: linear-gradient(135deg, #345262 0%, #5a7585 100%); color: white; text-align: center;">
            <div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;">{{ number_format($totalMatches) }}</div>
            <div style="font-size: 14px; opacity: 0.9;">Total Matches</div>
        </div>
        <div class="card" style="background: linear-gradient(135deg, #3182ce 0%, #2c5aa0 100%); color: white; text-align: center;">
            <div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;">
                {{ $fundservTotal > 0 ? number_format(($fundservMatched / $fundservTotal) * 100, 1) : '0.0' }}%
            </div>
            <div style="font-size: 14px; opacity: 0.9;">Fundserv Matched ({{ number_format($fundservMatched) }}/{{ number_format($fundservTotal) }})</div>
        </div>
        <div class="card" style="background: linear-gradient(135deg, #38a169 0%, #2f855a 100%); color: white; text-align: center;">
            <div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;">
                {{ $viefundTotal > 0 ? number_format(($viefundMatched / $viefundTotal) * 100, 1) : '0.0' }}%
            </div>
            <div style="font-size: 14px; opacity: 0.9;">VieFund Matched ({{ number_format($viefundMatched) }}/{{ number_format($viefundTotal) }})</div>
        </div>
        <div class="card" style="background: linear-gradient(135deg, #805ad5 0%, #6b46c1 100%); color: white; text-align: center;">
            <div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;">
                {{ $bankTotal > 0 ? number_format(($bankMatched / $bankTotal) * 100, 1) : '0.0' }}%
            </div>
            <div style="font-size: 14px; opacity: 0.9;">Bank Matched ({{ number_format($bankMatched) }}/{{ number_format($bankTotal) }})</div>
        </div>
    </div>

    <div class="card">
        <!-- Filters Row -->
        <div style="padding: 16px; border-bottom: 1px solid #e2e8f0; background: #f9fafb;">
            <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 24px;">
                <!-- Reconciliation Filter (1/3 width) -->
                <div>
                    <div style="font-weight: 600; color: #2d3748; margin-bottom: 12px; font-size: 14px;">Filter by Reconciliation:</div>
                    <div style="display: flex; gap: 12px; align-items: flex-start;">
                        <div style="display: flex; flex-direction: column; gap: 4px; width: 100%;">
                            <label style="font-size: 12px; color: #4a5568; font-weight: 500;">Status</label>
                            <select id="reconciliation-filter-select" onchange="
                                updateReconciliationFilterColor(this);
                                const params = new URLSearchParams(window.location.search);
                                if (this.value === 'hide_reconciled') {
                                    params.set('reconciliation_filter', 'hide_reconciled');
                                } else if (this.value === 'show_reconciled') {
                                    params.set('reconciliation_filter', 'show_reconciled');
                                } else {
                                    params.set('reconciliation_filter', 'show_all');
                                }
                                window.location.search = params.toString();
                            " style="padding: 6px 8px; border: 1px solid #cbd5e0; border-radius: 4px; background: white; color: #2d3748; font-size: 13px; cursor: pointer; width: 100%;">
                                <option value="hide_reconciled" {{ $reconciliationFilter === 'hide_reconciled' ? 'selected' : '' }}>Hide Reconciled</option>
                                <option value="show_reconciled" {{ $reconciliationFilter === 'show_reconciled' ? 'selected' : '' }}>Show Reconciled</option>
                                <option value="show_all" {{ $reconciliationFilter === 'show_all' ? 'selected' : '' }}>Show All</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Criteria Filters (2/3 width) -->
                <div>
                    <div style="font-weight: 600; color: #2d3748; margin-bottom: 12px; font-size: 14px;">Filter by Matched Criteria:</div>
                    <div style="display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-start;">
                        @php
                            $criteriaLabels = [
                                'order_id' => 'Order ID',
                                'settlement_date' => 'Settlement Date',
                                'transaction_type' => 'Transaction Type',
                                'amount' => 'Amount',
                                'fund_code' => 'Fund Code',
                                'source_id' => 'Source Identifier',
                            ];
                        @endphp
                        @foreach($availableCriteria as $criterion)
                            @php
                                $currentValue = $criteriaFilters[$criterion] ?? 'off';
                                $queryParams = request()->query();
                            @endphp
                            <div style="display: flex; flex-direction: column; gap: 4px;">
                                <label style="font-size: 12px; color: #4a5568; font-weight: 500;">{{ $criteriaLabels[$criterion] ?? $criterion }}</label>
                                <select class="criteria-filter-select" data-criterion="{{ $criterion }}" onchange="
                                    updateFilterSelectColor(this);
                                    const params = new URLSearchParams(window.location.search);
                                    if (this.value === 'off') {
                                        params.delete('criteria_{{ $criterion }}');
                                    } else {
                                        params.set('criteria_{{ $criterion }}', this.value);
                                    }
                                    window.location.search = params.toString();
                                " style="padding: 6px 8px; border: 1px solid #cbd5e0; border-radius: 4px; background: white; color: #2d3748; font-size: 13px; cursor: pointer;">
                                    <option value="off" {{ $currentValue === 'off' ? 'selected' : '' }}>Off</option>
                                    <option value="matched" {{ $currentValue === 'matched' ? 'selected' : '' }}>Matched</option>
                                    <option value="unmatched" {{ $currentValue === 'unmatched' ? 'selected' : '' }}>Unmatched</option>
                                </select>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <!-- Results Summary Row -->
        <div style="padding: 12px 16px; border-bottom: 1px solid #e2e8f0; background: #ffffff; font-size: 13px; color: #4a5568; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <strong style="color: #2d3748;">{{ $matchesPaginator->total() }}</strong> results
                @if($matchesPaginator->total() > 0)
                    showing <strong style="color: #2d3748;">{{ $matchesPaginator->perPage() }}</strong> per page
                    on page <strong style="color: #2d3748;">{{ $matchesPaginator->currentPage() }}</strong> of <strong style="color: #2d3748;">{{ $matchesPaginator->lastPage() }}</strong>
                @endif
            </div>
        </div>

        @if($matchesPaginator->count() > 0)
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                    @php
                        $nextDirection = $direction === 'asc' ? 'desc' : 'asc';
                        $sortParams = function ($column) use ($sort, $direction, $nextDirection) {
                            $dir = $sort === $column ? $nextDirection : 'asc';
                            return array_merge(request()->query(), ['sort' => $column, 'direction' => $dir]);
                        };
                        $sortIndicator = function ($column) use ($sort, $direction) {
                            if ($sort !== $column) {
                                return ' ↕';
                            }
                            return $direction === 'asc' ? ' ▲' : ' ▼';
                        };
                    @endphp
                    <thead>
                        <tr style="background: #f7fafc; border-bottom: 2px solid #e2e8f0; white-space: nowrap;">
                            <th style="padding: 12px; text-align: center; font-weight: 600; color: #2d3748; width: 110px;">Rule</th>
                            <th style="padding: 12px; text-align: right; font-weight: 600; color: #2d3748;">
                                <a href="{{ route('reconciliations.matches', $sortParams('viefund')) }}" style="color: inherit; text-decoration: none;">VieFund{{ $sortIndicator('viefund') }}</a>
                            </th>
                            <th style="padding: 12px; text-align: right; font-weight: 600; color: #2d3748;">
                                <a href="{{ route('reconciliations.matches', $sortParams('fundserv')) }}" style="color: inherit; text-decoration: none;">Fundserv{{ $sortIndicator('fundserv') }}</a>
                            </th>
                            <th style="padding: 12px; text-align: right; font-weight: 600; color: #2d3748;">
                                <a href="{{ route('reconciliations.matches', $sortParams('bank')) }}" style="color: inherit; text-decoration: none;">Bank{{ $sortIndicator('bank') }}</a>
                            </th>
                            <th style="padding: 12px; text-align: right; font-weight: 600; color: #2d3748;">
                                <a href="{{ route('reconciliations.matches', $sortParams('difference')) }}" style="color: inherit; text-decoration: none;">Diff{{ $sortIndicator('difference') }}</a>
                            </th>
                            <th style="padding: 12px; text-align: center; font-weight: 600; color: #2d3748;">
                                <a href="{{ route('reconciliations.matches', $sortParams('confidence')) }}" style="color: inherit; text-decoration: none;">Confidence{{ $sortIndicator('confidence') }}</a>
                            </th>
                            <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Details</th>
                            <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Matched At</th>
                            <th style="padding: 12px; text-align: center; font-weight: 600; color: #2d3748;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($pagedGroups as $group)
                            @php
                                $groupId = 'group-' . substr(md5($group['key']), 0, 8);
                                $first = $group['first'];
                                $hasDiff = $group['diff'] !== null && abs((float) $group['diff']) > 0.005;
                                $columnBackground = $hasDiff ? '#fef3c7' : 'transparent';
                            @endphp

                            <tr class="group-toggle" data-match-id="{{ $first->id }}" data-group="{{ $groupId }}" data-expandable="{{ $group['is_multi'] ? 'true' : 'false' }}" style="border-bottom: 1px solid #e2e8f0; transition: background-color 0.2s ease;" onmouseover="this.style.backgroundColor='#f0f4f8'" onmouseout="this.style.backgroundColor='transparent'">
                                <td style="padding: 12px; color: #2d3748; font-size: 12px; max-width: 110px; white-space: normal; text-align: center;">
                                    @php
                                        $ruleLabel = match($first->match_rule) {                                            'viefund_to_fundserv_criteria_based' => 'VieFund<br>to<br>Fundserv',                                            'viefund_to_fundserv_criteria_based' => 'VieFund<br>to<br>Fundserv',
                                            'fundserv_order_id_to_viefund_fund_wo_number' => 'Fundserv<br>to<br>VieFund',
                                            'bank_to_fundserv_amount_date' => !empty($first->metadata_array['viefund_id'] ?? null)
                                                ? 'Bank<br>to<br>Fundserv<br>&amp;<br>VieFund'
                                                : 'Bank<br>to<br>Fundserv',
                                            'bank_to_viefund_amount_date' => 'Bank<br>to<br>VieFund',
                                            default => str_replace('_', ' ', $first->match_rule),
                                        };
                                    @endphp
                                    {!! $ruleLabel !!}
                                    @if($group['is_multi'])
                                        <span style="margin-left: 6px; background: #805ad5; color: #fff; padding: 2px 6px; border-radius: 10px; font-size: 10px;">Multi</span>
                                    @endif
                                </td>
                                <td style="padding: 12px; text-align: right; color: #2d3748; font-family: monospace;">
                                    {{ $group['viefund_total'] !== null ? number_format($group['viefund_total'], 2) : '-' }}
                                </td>
                                <td style="padding: 12px; text-align: right; color: #2d3748; font-family: monospace;">
                                    {{ $group['fundserv_amount'] !== null ? number_format($group['fundserv_amount'], 2) : '-' }}
                                </td>
                                <td style="padding: 12px; text-align: right; color: #2d3748; font-family: monospace;">
                                    -
                                </td>
                                <td style="padding: 12px; text-align: right; color: #2d3748; font-family: monospace; background: {{ $columnBackground }};">
                                    {{ $group['diff'] !== null ? number_format($group['diff'], 2) : '-' }}
                                </td>
                                <td style="padding: 12px; text-align: center;">
                                    @php
                                        // Use aggregated confidence for multi-match groups, individual confidence for single matches
                                        $confidence = (float) ($group['is_multi'] ? $group['aggregated_confidence'] : $group['first']->confidence ?? 0);
                                        $pctText = number_format($confidence * 100, 1) . '%';
                                        $barWidth = $confidence * 100;
                                    @endphp
                                    <div style="display: flex; flex-direction: column; align-items: center; gap: 4px;">
                                        <div style="font-family: monospace; font-size: 12px; font-weight: 600; color: #2d3748;">
                                            {{ $pctText }}
                                        </div>
                                        <div style="width: 96px; height: 16px; background: #f7fafc; border: 2px solid #cbd5e0; border-radius: 4px; overflow: hidden; position: relative;">
                                            <div style="height: 100%; width: {{ $barWidth }}%; background: #48bb78; transition: width 0.3s ease;"></div>
                                        </div>
                                    </div>
                                </td>
                                <td style="padding: 12px; color: #4a5568; font-size: 12px;">
                                    @if($first->reconcile_type)
                                        <!-- Reconciliation Status -->
                                        <div style="background: #f0fff4; padding: 8px; border-radius: 4px; border-left: 3px solid #22863a;">
                                            <div><strong style="color: #22863a;">✓ Reconciled</strong></div>
                                            <div style="font-size: 11px; color: #2d3748; margin-top: 4px;">
                                                <div><strong>Type:</strong> {{ ucfirst($first->reconcile_type) }}</div>
                                                <div><strong>Date:</strong> {{ \Carbon\Carbon::parse($first->reconcile_date)->format('M d, Y H:i') ?? '-' }}</div>
                                                @if($first->reconcile_notes)
                                                    <div><strong>Notes:</strong> {{ substr($first->reconcile_notes, 0, 50) }}{{ strlen($first->reconcile_notes) > 50 ? '...' : '' }}</div>
                                                @endif
                                            </div>
                                        </div>
                                    @elseif($first->match_rule === 'viefund_to_fundserv_criteria_based')
                                        <div><strong>VieFund Fund WO:</strong> {{ $first->viefund_fund_wo_number ?? '-' }}</div>
                                        <div><strong>Fundserv Order ID:</strong> {{ $first->fundserv_order_id ?? '-' }}</div>
                                        <div><strong>Fundserv Settlement Date:</strong> {{ $first->fundserv_settlement_date ?? '-' }}</div>
                                        @if($group['count'] > 1)
                                            <div><strong>Transactions:</strong> {{ $group['count'] }}</div>
                                        @endif
                                        @php
                                            // Use aggregated criteria for multi-match groups, individual criteria for single matches
                                            $displayCriteria = $group['is_multi'] ? $group['aggregated_criteria'] : $first->criteria_array;
                                        @endphp
                                        @if(!empty($displayCriteria))
                                            <div style="margin-top: 8px; font-size: 11px;">
                                                <strong>Criteria Met:</strong>
                                                @foreach($displayCriteria as $criterion)
                                                    <div style="margin-left: 8px; color: {{ $criterion['matched'] ? '#22863a' : '#cb2431' }};">
                                                        {{ $criterion['matched'] ? '✓' : '✗' }} {{ str_replace('_', ' ', $criterion['rule']) }}
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif
                                    @elseif($first->match_rule === 'fundserv_order_id_to_viefund_fund_wo_number')
                                        <div><strong>Order ID:</strong> {{ $first->fundserv_order_id ?? '-' }}</div>
                                        <div><strong>Fund WO#:</strong> {{ $first->viefund_fund_wo_number ?? '-' }}</div>
                                        <div><strong>Transactions:</strong> {{ $group['count'] }}</div>
                                    @else
                                        <div style="font-family: monospace; white-space: pre-wrap;">{{ $first->metadata ?? '-' }}</div>
                                    @endif
                                </td>
                                <td style="padding: 12px; color: #4a5568; font-family: monospace; font-size: 12px; white-space: nowrap;">
                                    {!! \Carbon\Carbon::parse($first->created_at)->format('Y-M-d') . '<br>' . \Carbon\Carbon::parse($first->created_at)->format('H:i:s') !!}
                                </td>
                                <td style="padding: 12px; color: #4a5568; font-family: monospace; font-size: 12px; white-space: nowrap; text-align: center;">
                                    <div style="display: flex; gap: 8px; justify-content: center; align-items: center;">
                                        <!-- View Button -->
                                        @if($group['is_multi'])
                                            <button class="view-match-btn" data-match-id="{{ $first->id }}" data-is-parent="true" data-group-data="{{ base64_encode(json_encode(['aggregated_confidence' => $group['aggregated_confidence'], 'aggregated_criteria' => $group['aggregated_criteria'], 'count' => $group['count'], 'fundserv_order_id' => $first->fundserv_order_id, 'viefund_fund_wo_number' => $first->viefund_fund_wo_number, 'reconcile_type' => $first->reconcile_type, 'reconcile_date' => $first->reconcile_date, 'reconcile_notes' => $first->reconcile_notes])) }}" style="background: #3182ce; color: white; border: none; padding: 6px 12px; border-radius: 4px; font-size: 12px; cursor: pointer; transition: background 0.2s;" onmouseover="this.style.backgroundColor='#2c5aa0'" onmouseout="this.style.backgroundColor='#3182ce'">View</button>
                                        @else
                                            <button class="view-match-btn" data-match-id="{{ $first->id }}" data-is-parent="false" style="background: #3182ce; color: white; border: none; padding: 6px 12px; border-radius: 4px; font-size: 12px; cursor: pointer; transition: background 0.2s;" onmouseover="this.style.backgroundColor='#2c5aa0'" onmouseout="this.style.backgroundColor='#3182ce'">View</button>
                                        @endif
                                        @if($group['is_multi'])
                                            <!-- Expand Button (only for multi-match rows) -->
                                            <button class="expand-match-btn" data-group="{{ $groupId }}" style="background: #805ad5; color: white; border: none; padding: 6px 12px; border-radius: 4px; font-size: 12px; cursor: pointer; transition: background 0.2s;" onmouseover="this.style.backgroundColor='#6b46c1'" onmouseout="this.style.backgroundColor='#805ad5'">Expand</button>
                                        @endif
                                    </div>
                                </td>
                            </tr>

                            @foreach($group['items'] as $match)
                                <tr class="group-row {{ $groupId }}" data-match-id="{{ $match->id }}" style="border-bottom: 1px solid #e2e8f0; border-top: 1px solid #cbd5e0; border-left: 3px solid #3182ce; display: none; background: #e8eef7; transition: background-color 0.2s ease; box-shadow: inset 0 1px 2px rgba(0,0,0,0.05);" onmouseover="this.style.backgroundColor='#d1ddf0'" onmouseout="this.style.backgroundColor='#e8eef7'">
                                    <td style="padding: 12px; color: #2d3748; font-size: 12px; max-width: 110px; white-space: normal; text-align: center;">
                                        @php
                                            $ruleLabel = match($match->match_rule) {
                                                'viefund_to_fundserv_criteria_based' => 'VieFund to Fundserv',
                                                'fundserv_order_id_to_viefund_fund_wo_number' => 'Fundserv to VieFund',
                                                'bank_to_fundserv_amount_date' => !empty($match->metadata_array['viefund_id'] ?? null)
                                                    ? 'Bank to Fundserv & VieFund'
                                                    : 'Bank to Fundserv',
                                                'bank_to_viefund_amount_date' => 'Bank to VieFund',
                                                default => str_replace('_', ' ', ucwords($match->match_rule, '_')),
                                            };
                                        @endphp
                                        {{ $ruleLabel }}
                                    </td>
                                    <td style="padding: 12px; text-align: right; color: #2d3748; font-family: monospace;">
                                        {{ $match->viefund_amount !== null ? number_format($match->viefund_amount, 2) : '-' }}
                                    </td>
                                    <td style="padding: 12px; text-align: right; color: #2d3748; font-family: monospace;">
                                        {{ $match->fundserv_actual_amount !== null ? number_format($match->fundserv_actual_amount, 2) : ($match->fundserv_amount !== null ? number_format($match->fundserv_amount, 2) : '-') }}
                                    </td>
                                    <td style="padding: 12px; text-align: right; color: #2d3748; font-family: monospace;">
                                        -
                                    </td>
                                    <td style="padding: 12px; text-align: right; color: #2d3748; font-family: monospace;">
                                        -
                                    </td>
                                    <td style="padding: 12px; text-align: center;">
                                        @php
                                            $confidence = (float) ($match->confidence ?? 0);
                                            if ($confidence >= 0.9) {
                                                $barBg = '#c6f6d5';
                                                $barFill = '#2f855a';
                                            } elseif ($confidence >= 0.75) {
                                                $barBg = '#9ae6b4';
                                                $barFill = '#2f855a';
                                            } elseif ($confidence >= 0.6) {
                                                $barBg = '#68d391';
                                                $barFill = '#276749';
                                            } elseif ($confidence >= 0.4) {
                                                $barBg = '#48bb78';
                                                $barFill = '#22543d';
                                            } else {
                                                $barBg = '#38a169';
                                                $barFill = '#1c4532';
                                            }
                                            $pctText = number_format($confidence * 100, 1) . '%';
                                        @endphp
                                        <div style="font-family: monospace; font-size: 12px; font-weight: 600; color: #2d3748; margin-bottom: 6px;">
                                            {{ $pctText }}
                                        </div>
                                        <div style="height: 8px; width: 96px; margin: 0 auto; background: {{ $barBg }}; border-radius: 999px; overflow: hidden;">
                                            <div style="height: 100%; width: {{ $pctText }}; background: {{ $barFill }};"></div>
                                        </div>
                                    </td>
                                    <td style="padding: 12px; color: #4a5568; font-size: 12px;">
                                        @if($match->reconcile_type)
                                            <!-- Reconciliation Status -->
                                            <div style="background: #f0fff4; padding: 8px; border-radius: 4px; border-left: 3px solid #22863a;">
                                                <div><strong style="color: #22863a;">✓ Reconciled</strong></div>
                                                <div style="font-size: 11px; color: #2d3748; margin-top: 4px;">
                                                    <div><strong>Type:</strong> {{ ucfirst($match->reconcile_type) }}</div>
                                                    <div><strong>Date:</strong> {{ \Carbon\Carbon::parse($match->reconcile_date)->format('M d, Y H:i') ?? '-' }}</div>
                                                    @if($match->reconcile_notes)
                                                        <div><strong>Notes:</strong> {{ substr($match->reconcile_notes, 0, 50) }}{{ strlen($match->reconcile_notes) > 50 ? '...' : '' }}</div>
                                                    @endif
                                                </div>
                                            </div>
                                        @else
                                            <!-- Match Criteria -->
                                            @if($match->match_rule === 'viefund_to_fundserv_criteria_based')
                                                <div><strong>VieFund Fund WO:</strong> {{ $match->viefund_fund_wo_number ?? '-' }}</div>
                                                <div><strong>Fundserv Order ID:</strong> {{ $match->fundserv_order_id ?? '-' }}</div>
                                                <div><strong>Fundserv Settlement Date:</strong> {{ $match->fundserv_settlement_date ?? '-' }}</div>
                                                @if(!empty($match->criteria_array))
                                                    <div style="margin-top: 8px; font-size: 11px;">
                                                        <strong>Criteria Met:</strong>
                                                        @foreach($match->criteria_array as $criterion)
                                                            <div style="margin-left: 8px; color: {{ $criterion['matched'] ? '#22863a' : '#cb2431' }};">
                                                                {{ $criterion['matched'] ? '✓' : '✗' }} {{ str_replace('_', ' ', $criterion['rule']) }}
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                @endif
                                            @elseif($match->match_rule === 'fundserv_order_id_to_viefund_fund_wo_number')
                                                <div><strong>Order ID:</strong> {{ $match->fundserv_order_id ?? '-' }}</div>
                                                <div><strong>Fund WO#:</strong> {{ $match->viefund_fund_wo_number ?? '-' }}</div>
                                            @else
                                                <div style="font-family: monospace; white-space: pre-wrap;">{{ $match->metadata ?? '-' }}</div>
                                            @endif
                                        @endif
                                    </td>
                                    <td style="padding: 12px; color: #4a5568; font-family: monospace; font-size: 12px; white-space: nowrap;">
                                        {!! \Carbon\Carbon::parse($match->created_at)->format('Y-M-d') . '<br>' . \Carbon\Carbon::parse($match->created_at)->format('H:i:s') !!}
                                    </td>
                                    <td style="padding: 12px; color: #4a5568; font-family: monospace; font-size: 12px; white-space: nowrap; text-align: center;">
                                        <button class="view-match-btn" data-match-id="{{ $match->id }}" data-is-parent="false" style="background: #3182ce; color: white; border: none; padding: 6px 12px; border-radius: 4px; font-size: 12px; cursor: pointer; transition: background 0.2s;" onmouseover="this.style.backgroundColor='#2c5aa0'" onmouseout="this.style.backgroundColor='#3182ce'">View</button>
                                    </td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div style="margin-top: 20px; display: flex; justify-content: center;">
                {{ $matchesPaginator->onEachSide(1)->links() }}
            </div>
        @else
            <div style="text-align: center; padding: 40px; color: #718096;">
                <p style="font-size: 18px; margin-bottom: 10px;">No reconciliation matches found.</p>
            </div>
        @endif
    </div>

    <!-- Match Detail Modal -->
    <div id="match-detail-modal" style="display: none; position: fixed; inset: 0; background: rgba(0, 0, 0, 0.5); z-index: 1000; align-items: center; justify-content: center; overflow-y: auto;">
        <div class="modal-overlay" style="position: absolute; inset: 0; cursor: pointer;"></div>
        <div class="modal-content" style="position: relative; background: white; border-radius: 12px; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3); max-width: 1000px; width: 90%; max-height: 90vh; overflow-y: auto; margin: 20px auto;">
            <div style="position: sticky; top: 0; background: white; padding: 20px; border-bottom: 2px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; z-index: 10;">
                <h3 style="margin: 0; font-size: 20px; font-weight: 700; color: #2d3748;">Transaction Comparison</h3>
                <button class="modal-close" style="background: none; border: none; font-size: 28px; cursor: pointer; color: #718096; padding: 0; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; border-radius: 4px; transition: all 0.2s;" onmouseover="this.style.backgroundColor='#e2e8f0'" onmouseout="this.style.backgroundColor='transparent';">×</button>
            </div>
            <div class="modal-content-wrapper" style="padding: 24px;">
                <!-- Content will be populated by JavaScript -->
            </div>
        </div>
    </div>

    <script>
        let currentSessionId = null;
        let pollingInterval = null;

        const processingModal = document.createElement('div');
        processingModal.id = 'processing-modal';
        processingModal.style.cssText = 'display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); align-items:center; justify-content:center; z-index:60;';
        processingModal.innerHTML = `
            <div style="background:#fff; padding:24px 28px; border-radius:10px; min-width:320px; text-align:center; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
                <div style="width:40px; height:40px; margin:0 auto 12px; border:4px solid #cbd5e0; border-top-color:#2f855a; border-radius:50%; animation:spin 0.9s linear infinite;"></div>
                <div style="font-weight:600; color:#2d3748; margin-bottom:6px;">Processing</div>
                <div style="font-size:13px; color:#718096;">Please wait while we process matches.</div>
            </div>
        `;
        document.body.appendChild(processingModal);

        const styleEl = document.createElement('style');
        styleEl.textContent = '@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }';
        document.head.appendChild(styleEl);

        function disableButtons(disabled) {
            document.querySelector('.find-matches-btn').disabled = disabled;
            document.querySelector('.delete-matches-btn').disabled = disabled;
            if (disabled) {
                document.querySelector('.find-matches-btn').style.opacity = '0.5';
                document.querySelector('.delete-matches-btn').style.opacity = '0.5';
            } else {
                document.querySelector('.find-matches-btn').style.opacity = '1';
                document.querySelector('.delete-matches-btn').style.opacity = '1';
            }
        }

        function updateProgress(data) {
            const section = document.getElementById('matching-progress-section');
            const percentage = data.progress_percentage;
            
            document.getElementById('progress-percentage').textContent = percentage + '%';
            document.getElementById('progress-bar').style.width = percentage + '%';
            
            // Update progress bar text with pass number and percentage
            const passNumber = data.current_pass_number || 1;
            const progressText = `Pass ${passNumber} of 5 - ${percentage}%`;
            document.getElementById('progress-text').textContent = progressText;
            
            document.getElementById('progress-processed').textContent = data.processed_records;
            document.getElementById('progress-total').textContent = data.total_records;
            document.getElementById('progress-matched').textContent = data.matched_count;
            document.getElementById('progress-session').textContent = 'ID: ' + data.id;
            
            // Update current pass
            if (data.current_pass) {
                document.getElementById('progress-current-pass').textContent = data.current_pass;
            }
        }

        function pollMatchingStatus() {
            if (!currentSessionId) return;

            fetch('/reconciliations/matching-status/' + currentSessionId)
                .then(response => response.json())
                .then(data => {
                    updateProgress(data);

                    if (data.status === 'completed') {
                        clearInterval(pollingInterval);
                        disableButtons(false);
                        document.getElementById('matching-progress-section').style.display = 'none';
                        
                        // Reload the page to show new matches
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else if (data.status === 'failed') {
                        clearInterval(pollingInterval);
                        disableButtons(false);
                        alert('Matching failed: ' + data.error_message);
                        document.getElementById('matching-progress-section').style.display = 'none';
                    }
                })
                .catch(error => {
                    console.error('Error polling status:', error);
                });
        }

        // Handle form submissions
        document.querySelectorAll('.match-action-form').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                if (form.getAttribute('data-action') === 'find') {
                    e.preventDefault();
                    
                    // Disable buttons
                    disableButtons(true);
                    
                    // Show progress section
                    document.getElementById('matching-progress-section').style.display = 'block';
                    
                    // Submit the form via AJAX
                    fetch(form.action, {
                        method: 'POST',
                        body: new FormData(form)
                    })
                    .then(response => response.text())
                    .then(html => {
                        // Extract session ID from response headers or body
                        // The server will set a session ID on form submission
                        // We need to check the database for the latest session
                        
                        // Poll for active sessions every 500ms
                        let checkForSession = setInterval(function() {
                            fetch('/api/matching-sessions/active')
                                .then(r => r.json())
                                .then(data => {
                                    if (data.id) {
                                        clearInterval(checkForSession);
                                        currentSessionId = data.id;
                                        updateProgress(data);
                                        
                                        // Start polling every 5 seconds (backend updates every 30 seconds)
                                        pollingInterval = setInterval(pollMatchingStatus, 5000);
                                    }
                                })
                                .catch(() => {}); // Ignore errors
                        }, 500);
                        
                        // Stop checking after 30 seconds
                        setTimeout(() => clearInterval(checkForSession), 30000);
                    })
                    .catch(error => {
                        console.error('Error submitting form:', error);
                        disableButtons(false);
                    });
                }
            });
        });

        // Check if there's an active session on page load
        window.addEventListener('load', function() {
            fetch('/api/matching-sessions/active')
                .then(r => r.json())
                .then(data => {
                    if (data.id && (data.status === 'processing' || data.status === 'pending')) {
                        currentSessionId = data.id;
                        document.getElementById('matching-progress-section').style.display = 'block';
                        disableButtons(true);
                        updateProgress(data);
                        pollingInterval = setInterval(pollMatchingStatus, 5000);
                    }
                })
                .catch(() => {}); // No active session
        });

        // Modal functionality - Handle match detail modal
        const detailModal = document.getElementById('match-detail-modal');
        const modalClose = detailModal.querySelector('.modal-close');
        const modalOverlay = detailModal.querySelector('.modal-overlay');

        // Close modal handlers
        modalClose.addEventListener('click', () => {
            detailModal.style.display = 'none';
        });

        modalOverlay.addEventListener('click', () => {
            detailModal.style.display = 'none';
        });

        // Prevent closing modal when clicking on modal content
        detailModal.querySelector('.modal-content').addEventListener('click', (e) => {
            e.stopPropagation();
        });

        function openParentGroupDetailModal(groupData, matchId) {
            const modal = document.getElementById('match-detail-modal');
            const content = modal.querySelector('.modal-content-wrapper');
            
            // Map criteria rules to display names
            const criteriaLabels = {
                'order_id': 'Order ID',
                'settlement_date': 'Settlement Date',
                'transaction_type': 'Transaction Type',
                'amount': 'Amount',
                'fund_code': 'Fund Code',
                'source_id': 'Source ID'
            };
            
            // Build aggregated criteria display
            let criteriaHtml = `
                <div style="margin-bottom: 24px;">
                    <h3 style="margin: 0 0 12px 0; font-size: 18px; font-weight: 600; color: #2d3748;">Match Criteria Summary</h3>
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f7fafc; border-bottom: 2px solid #cbd5e0;">
                                <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Criteria</th>
                                <th style="padding: 12px; text-align: center; font-weight: 600; color: #2d3748;">Matched</th>
                            </tr>
                        </thead>
                        <tbody>
            `;
            
            if (groupData.aggregated_criteria && Array.isArray(groupData.aggregated_criteria)) {
                groupData.aggregated_criteria.forEach(criterion => {
                    const label = criteriaLabels[criterion.rule] || criterion.rule;
                    const isMatched = criterion.matched;
                    const bgColor = isMatched ? '#f0fff4' : '#fef3c7';
                    const textColor = isMatched ? '#22863a' : '#b8860b';
                    const icon = isMatched ? '✓' : '✗';
                    
                    criteriaHtml += `
                        <tr style="border-bottom: 1px solid #e2e8f0;">
                            <td style="padding: 12px; color: #2d3748; font-weight: 500;">${label}</td>
                            <td style="padding: 12px; text-align: center; background: ${bgColor}; color: ${textColor}; font-weight: 600; border-radius: 4px;">
                                ${icon} All transactions matched
                            </td>
                        </tr>
                    `;
                });
            }
            
            criteriaHtml += `
                        </tbody>
                    </table>
                </div>
            `;
            
            // Build summary section and reconciliation form in a single row
            const confidencePercent = (groupData.aggregated_confidence * 100).toFixed(1);
            const bottomRowHtml = `
                <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 24px; margin-top: 24px; padding-top: 20px; border-top: 1px solid #e2e8f0;">
                    <div style="background: #f7fafc; padding: 16px; border-radius: 6px; border-left: 4px solid #3182ce;">
                        <div style="display: grid; grid-template-columns: 1fr; gap: 16px;">
                            <div>
                                <div style="font-size: 12px; color: #718096; font-weight: 500; margin-bottom: 4px;">Total Transactions</div>
                                <div style="font-size: 24px; font-weight: 700; color: #2d3748;">${groupData.count}</div>
                            </div>
                            <div>
                                <div style="font-size: 12px; color: #718096; font-weight: 500; margin-bottom: 4px;">Overall Confidence</div>
                                <div style="font-size: 24px; font-weight: 700; color: #3182ce;">${confidencePercent}%</div>
                            </div>
                        </div>
                    </div>
                    <div>
                        ${groupData.reconcile_type ? `
                            <!-- Already reconciled - show status -->
                            <div style="background: #f0fff4; padding: 12px; border-radius: 4px; border-left: 3px solid #22863a; margin-bottom: 12px;">
                                <div style="font-size: 12px; color: #22863a; font-weight: 600; margin-bottom: 4px;">✓ Status: ${groupData.reconcile_type === 'auto' ? 'Auto Reconciled' : 'Manually Reconciled'}</div>
                                <div style="font-size: 11px; color: #2d3748;"><strong>Date:</strong> ${new Date(groupData.reconcile_date).toLocaleString()}</div>
                                ${groupData.reconcile_notes ? `<div style="font-size: 11px; color: #2d3748; margin-top: 4px;"><strong>Notes:</strong> ${groupData.reconcile_notes}</div>` : ''}
                            </div>
                            <button onclick="clearReconciliation(event, ${matchId})" style="background: #e53e3e; color: white; border: none; padding: 10px 16px; border-radius: 4px; font-weight: 600; cursor: pointer; transition: background 0.2s; width: 100%;" onmouseover="this.style.backgroundColor='#c53030'" onmouseout="this.style.backgroundColor='#e53e3e'">
                                Clear Reconciliation
                            </button>
                        ` : `
                            <!-- Not reconciled - show form -->
                            <div>
                                <div style="margin-bottom: 12px;">
                                    <label style="display: block; font-size: 12px; color: #718096; font-weight: 500; margin-bottom: 8px;">Reconciliation Notes (Optional)</label>
                                    <textarea id="reconcile-notes" placeholder="Enter reconciliation notes..." style="width: 100%; padding: 10px; border: 1px solid #cbd5e0; border-radius: 4px; font-family: monospace; font-size: 12px; resize: vertical; min-height: 80px; box-sizing: border-box;"></textarea>
                                </div>
                                <button id="reconcile-btn" onclick="reconcileMatch(event, 'parent', ${matchId})" style="background: #38a169; color: white; border: none; padding: 10px 16px; border-radius: 4px; font-weight: 600; cursor: pointer; transition: background 0.2s;" onmouseover="this.style.backgroundColor='#2f855a'" onmouseout="this.style.backgroundColor='#38a169'">
                                    Reconcile
                                </button>
                            </div>
                        `}
                    </div>
                </div>
            `;
            
            const html = criteriaHtml + bottomRowHtml;
            
            content.innerHTML = html;
            
            modal.style.display = 'flex';
        }

        async function openMatchDetailModal(matchId, isParentOverride = null) {
            try {
                const response = await fetch(`/api/matches/${matchId}/details`);
                const data = await response.json();

                if (data.error) {
                    alert('Match details not found');
                    return;
                }

                // Use provided isParent value if given, otherwise use API response
                const isParent = isParentOverride !== null ? isParentOverride : data.is_parent;
                const criteriaMap = {};
                if (data.criteria && Array.isArray(data.criteria)) {
                    data.criteria.forEach(criterion => {
                        // Map criteria rules to field names
                        if (criterion.rule === 'order_id') {
                            criteriaMap['Fund WO Number'] = criterion.matched;
                            criteriaMap['Order ID'] = criterion.matched;
                        } else if (criterion.rule === 'settlement_date') {
                            criteriaMap['Settlement Date'] = criterion.matched;
                        } else if (criterion.rule === 'transaction_type') {
                            criteriaMap['Fund Trx Type'] = criterion.matched;
                            criteriaMap['Tx Type'] = criterion.matched;
                        } else if (criterion.rule === 'amount') {
                            criteriaMap['Fund Trx Amount'] = criterion.matched;
                            criteriaMap['Actual Amount'] = criterion.matched;
                        } else if (criterion.rule === 'fund_code') {
                            criteriaMap['Fund Code'] = criterion.matched;
                            criteriaMap['Code'] = criterion.matched;
                            criteriaMap['Fund ID'] = criterion.matched;
                        } else if (criterion.rule === 'source_id') {
                            criteriaMap['Fund Source ID'] = criterion.matched;
                            criteriaMap['Source Identifier'] = criterion.matched;
                        }
                    });
                }

                // Helper function to format large numbers and prevent scientific notation
                const formatValue = (value) => {
                    if (value === null || value === undefined || value === '-') {
                        return '-';
                    }
                    // Check if it's a number that might be in scientific notation
                    if (!isNaN(value) && String(value).includes('E')) {
                        // Convert to regular number without decimal places
                        return Math.round(parseFloat(value)).toString();
                    }
                    return String(value);
                };

                // Helper function to get cell background color
                const getCellBackground = (fieldName) => {
                    if (fieldName in criteriaMap) {
                        return criteriaMap[fieldName] ? '#f0fff4' : '#fef3c7';
                    }
                    return 'transparent';
                };

                // Populate modal with data
                const modal = document.getElementById('match-detail-modal');
                const content = modal.querySelector('.modal-content-wrapper');

                // Check if transaction is reconciled
                if (data.match.reconcile_type) {
                    // Show reconciliation status instead of criteria
                    const reconcileDate = new Date(data.match.reconcile_date).toLocaleString();
                    let html = `
                        <div style="margin-bottom: 20px;">
                            <h3 style="margin: 0 0 12px 0; font-size: 18px; font-weight: 600; color: #2d3748;">Reconciliation Status</h3>
                            <div style="background: #f0fff4; padding: 16px; border-radius: 6px; border-left: 4px solid #22863a;">
                                <table style="width: 100%; font-size: 13px;">
                                    <tbody>
                                        <tr style="border-bottom: 1px solid #e2e8f0;">
                                            <td style="padding: 8px 0; font-weight: 500; color: #2d3748; width: 30%;">Status:</td>
                                            <td style="padding: 8px 0; color: #22863a; font-weight: 600;">${data.match.reconcile_type === 'auto' ? 'Auto Reconciled' : 'Manually Reconciled'}</td>
                                        </tr>
                                        <tr style="border-bottom: 1px solid #e2e8f0;">
                                            <td style="padding: 8px 0; font-weight: 500; color: #2d3748; width: 30%;">Reconciliation Date:</td>
                                            <td style="padding: 8px 0; color: #2d3748;">${reconcileDate}</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 8px 0; font-weight: 500; color: #2d3748; width: 30%; vertical-align: top;">Notes:</td>
                                            <td style="padding: 8px 0; color: #2d3748; font-family: monospace; white-space: pre-wrap;">${data.match.reconcile_notes || 'None'}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    `;
                    content.innerHTML = html;
                } else {
                    // Show transaction comparison criteria (existing logic)
                    // Create comparison table
                    let html = `
                        <div style="margin-bottom: 20px;">
                            <h3 style="margin: 0 0 12px 0; font-size: 18px; font-weight: 600; color: #2d3748;">Match Details</h3>
                            <p style="margin: 0 0 16px 0; font-size: 12px; color: #718096;"><span style="background: #f0fff4; padding: 2px 6px; border-radius: 3px; margin-right: 8px;">Green = Matched Field</span><span style="background: #fef3c7; padding: 2px 6px; border-radius: 3px;">Yellow = No Match</span></p>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
                    `;

                    // VieFund side
                    html += `<div>
                        <h4 style="margin: 0 0 12px 0; font-size: 14px; font-weight: 600; color: #2f855a; padding-bottom: 8px; border-bottom: 2px solid #c6f6d5;">VieFund Transaction</h4>
                        <table style="width: 100%; font-size: 13px;">
                    `;

                    // Criterion fields in order, then non-criterion
                    const viefundFields = [
                        // Criterion fields (in matching order)
                        ['Fund WO Number', data.viefund.fund_wo_number],
                        ['Settlement Date', data.viefund.settlement_date],
                        ['Fund Trx Type', data.viefund.fund_trx_type],
                        ['Fund Trx Amount', data.viefund.fund_trx_amount],
                    ];

                    viefundFields.forEach(([label, value, special]) => {
                        const bgColor = getCellBackground(label);
                        html += `<tr style="border-bottom: 1px solid #e2e8f0;">
                            <td style="padding: 8px 0; font-weight: 500; color: #4a5568; width: 45%;">${label}:</td>
                            <td style="padding: 8px 6px; color: #2d3748; font-family: monospace; word-break: break-all; background: ${bgColor}; border-radius: 4px;">${value || '-'}</td>
                        </tr>`;
                    });

                    // Fund Code - shown twice on VieFund side to align with Code + Fund ID on Fundserv
                    const viefundFundCodeBgColor = getCellBackground('Fund Code');
                    const fundCode = data.viefund.fund_code || '';
                    html += `<tr style="border-bottom: 1px solid #e2e8f0;">
                        <td style="padding: 8px 0; font-weight: 500; color: #4a5568; width: 45%;">Fund Code:</td>
                        <td style="padding: 8px 6px; color: #2d3748; font-family: monospace; word-break: break-all; background: ${viefundFundCodeBgColor}; border-radius: 4px;"><strong>${fundCode.substring(0, 3) || '-'}</strong>${fundCode.substring(3) || ''}</td>
                    </tr>`;
                    html += `<tr style="border-bottom: 1px solid #e2e8f0;">
                        <td style="padding: 8px 0; font-weight: 500; color: #4a5568; width: 45%;"></td>
                        <td style="padding: 8px 6px; color: #2d3748; font-family: monospace; word-break: break-all; background: ${viefundFundCodeBgColor}; border-radius: 4px;">${fundCode.substring(0, 3) || ''}<strong>${fundCode.substring(3) || '-'}</strong></td>
                    </tr>`;

                    // Continue with remaining fields
                    const viefundRemainingFields = [
                        ['Fund Source ID', formatValue(data.viefund.fund_source_id)],
                        // Non-criterion fields
                        ['Trade Date', data.viefund.trade_date],
                        ['Account ID', data.viefund.account_id],
                        ['Client Name', data.viefund.client_name],
                    ];

                viefundRemainingFields.forEach(([label, value]) => {
                    const bgColor = getCellBackground(label);
                    html += `<tr style="border-bottom: 1px solid #e2e8f0;">
                        <td style="padding: 8px 0; font-weight: 500; color: #4a5568; width: 45%;">${label}:</td>
                        <td style="padding: 8px 6px; color: #2d3748; font-family: monospace; word-break: break-all; background: ${bgColor}; border-radius: 4px;">${value || '-'}</td>
                    </tr>`;
                });

                html += `</table></div>`;

                // Fundserv side
                html += `<div>
                    <h4 style="margin: 0 0 12px 0; font-size: 14px; font-weight: 600; color: #3182ce; padding-bottom: 8px; border-bottom: 2px solid #90cdf4;">Fundserv Transaction</h4>
                    <table style="width: 100%; font-size: 13px;">
                `;

                // Build fundserv table with aligned criterion fields
                const fundservCriteriaRows = [
                    ['Order ID', data.fundserv.order_id],
                    ['Settlement Date', data.fundserv.settlement_date],
                    ['Tx Type', data.fundserv.tx_type],
                    ['Actual Amount', formatValue(data.fundserv.actual_amount)],
                ];

                fundservCriteriaRows.forEach(([label, value]) => {
                    const bgColor = getCellBackground(label);
                    html += `<tr style="border-bottom: 1px solid #e2e8f0;">
                        <td style="padding: 8px 0; font-weight: 500; color: #4a5568; width: 45%;">${label}:</td>
                        <td style="padding: 8px 6px; color: #2d3748; font-family: monospace; word-break: break-all; background: ${bgColor}; border-radius: 4px;">${value || '-'}</td>
                    </tr>`;
                });

                // Fund Code criterion - Fund Code maps to Code + Fund ID (2 rows)
                const fundCodeBgColor = getCellBackground('Code');

                html += `<tr style="border-bottom: 1px solid #e2e8f0;">
                    <td style="padding: 8px 0; font-weight: 500; color: #4a5568; width: 45%;">Code:</td>
                    <td style="padding: 8px 6px; color: #2d3748; font-family: monospace; word-break: break-all; background: ${fundCodeBgColor}; border-radius: 4px;">${data.fundserv.code || '-'}</td>
                </tr>`;
                html += `<tr style="border-bottom: 1px solid #e2e8f0;">
                    <td style="padding: 8px 0; font-weight: 500; color: #4a5568; width: 45%;">Fund ID:</td>
                    <td style="padding: 8px 6px; color: #2d3748; font-family: monospace; word-break: break-all; background: ${fundCodeBgColor}; border-radius: 4px;">${data.fundserv.fund_id || '-'}</td>
                </tr>`;

                // Source ID criterion
                const sourceIdBgColor = getCellBackground('Source Identifier');
                html += `<tr style="border-bottom: 1px solid #e2e8f0;">
                    <td style="padding: 8px 0; font-weight: 500; color: #4a5568; width: 45%;">Source Identifier:</td>
                    <td style="padding: 8px 6px; color: #2d3748; font-family: monospace; word-break: break-all; background: ${sourceIdBgColor}; border-radius: 4px;">${formatValue(data.fundserv.source_identifier) || '-'}</td>
                </tr>`;

                // Non-criterion fields
                const fundservNonCriteriaFields = [
                    ['Trade Date', data.fundserv.trade_date],
                    ['Dealer Account ID', data.fundserv.dealer_account_id],
                    ['Company', data.fundserv.company],
                ];

                fundservNonCriteriaFields.forEach(([label, value]) => {
                    html += `<tr style="border-bottom: 1px solid #e2e8f0;">
                        <td style="padding: 8px 0; font-weight: 500; color: #4a5568; width: 45%;">${label}:</td>
                        <td style="padding: 8px 6px; color: #2d3748; font-family: monospace; word-break: break-all; background: transparent; border-radius: 4px;">${value || '-'}</td>
                    </tr>`;
                    });

                    html += `</table></div></div></div>`;

                    content.innerHTML = html;
                }
                
                // Add reconciliation section only for non-reconciled transactions
                if (!data.match.reconcile_type) {
                    const reconcileSection = document.createElement('div');
                    reconcileSection.style.cssText = 'margin-top: 24px; padding-top: 20px; border-top: 1px solid #e2e8f0;';
                    
                    const confidencePercent = (data.confidence * 100).toFixed(1);
                    
                    if (isParent) {
                        // Parent transaction - show confidence and reconciliation form side by side
                        reconcileSection.innerHTML = `
                            <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 24px;">
                                <div style="background: #f7fafc; padding: 16px; border-radius: 6px; border-left: 4px solid #3182ce;">
                                    <div>
                                        <div style="font-size: 12px; color: #718096; font-weight: 500; margin-bottom: 4px;">Confidence</div>
                                        <div style="font-size: 24px; font-weight: 700; color: #3182ce;">${confidencePercent}%</div>
                                    </div>
                                </div>
                                <div>
                                    <div style="margin-bottom: 12px;">
                                        <label style="display: block; font-size: 12px; color: #718096; font-weight: 500; margin-bottom: 8px;">Reconciliation Notes (Optional)</label>
                                        <textarea id="reconcile-notes" placeholder="Enter reconciliation notes..." style="width: 100%; padding: 10px; border: 1px solid #cbd5e0; border-radius: 4px; font-family: monospace; font-size: 12px; resize: vertical; min-height: 80px; box-sizing: border-box;"></textarea>
                                    </div>
                                    <button id="reconcile-btn" onclick="reconcileMatch(event, 'individual', ${matchId})" style="background: #38a169; color: white; border: none; padding: 10px 16px; border-radius: 4px; font-weight: 600; cursor: pointer; transition: background 0.2s;" onmouseover="this.style.backgroundColor='#2f855a'" onmouseout="this.style.backgroundColor='#38a169'">
                                        Reconcile
                                    </button>
                                </div>
                            </div>
                        `;
                    } else {
                        // Child transaction - show confidence only, full width
                        reconcileSection.innerHTML = `
                            <div style="background: #f7fafc; padding: 16px; border-radius: 6px; border-left: 4px solid #3182ce;">
                                <div>
                                    <div style="font-size: 12px; color: #718096; font-weight: 500; margin-bottom: 4px;">Confidence</div>
                                    <div style="font-size: 24px; font-weight: 700; color: #3182ce;">${confidencePercent}%</div>
                                </div>
                            </div>
                        `;
                    }
                    
                    content.appendChild(reconcileSection);
                } else {
                    // Transaction is already reconciled
                    // Show reconciliation status and controls based on parent/child
                    console.log('Reconciled record:', { is_parent: isParent, match_id: data.match.match_id, reconcile_type: data.match.reconcile_type });
                    
                    // Always show reconciliation status first
                    const reconcileDate = new Date(data.match.reconcile_date).toLocaleString();
                    let reconcileHtml = `
                        <div style="background: #f0fff4; padding: 16px; border-radius: 6px; border-left: 4px solid #22863a; margin-bottom: 16px;">
                            <div style="margin-bottom: 12px;">
                                <div style="font-size: 12px; color: #22863a; font-weight: 600; margin-bottom: 2px;">✓ Status: ${data.match.reconcile_type === 'auto' ? 'Auto Reconciled' : 'Manually Reconciled'}</div>
                                <div style="font-size: 12px; color: #2d3748; margin-bottom: 4px;"><strong>Date:</strong> ${reconcileDate}</div>
                                ${data.match.reconcile_notes ? `<div style="font-size: 12px; color: #2d3748;"><strong>Notes:</strong> ${data.match.reconcile_notes}</div>` : ''}
                            </div>
                        </div>
                    `;
                    
                    // Only show clear button for parent records
                    if (isParent) {
                        reconcileHtml += `
                            <button onclick="clearReconciliation(event, ${matchId})" style="background: #e53e3e; color: white; border: none; padding: 10px 16px; border-radius: 4px; font-weight: 600; cursor: pointer; transition: background 0.2s;" onmouseover="this.style.backgroundColor='#c53030'" onmouseout="this.style.backgroundColor='#e53e3e'">
                                Clear Reconciliation
                            </button>
                        `;
                    } else {
                        reconcileHtml += `
                            <div style="font-size: 12px; color: #2d3748; padding: 8px; background: #e8f7f0; border-radius: 3px;">
                                This record is part of a reconciled group. The reconciliation is managed through the parent record.
                            </div>
                        `;
                    }
                    
                    const reconcileSection = document.createElement('div');
                    reconcileSection.style.cssText = 'margin-top: 24px; padding-top: 20px; border-top: 1px solid #e2e8f0;';
                    reconcileSection.innerHTML = reconcileHtml;
                    content.appendChild(reconcileSection);
                }
                
                modal.style.display = 'flex';

            } catch (error) {
                console.error('Error fetching match details:', error);
                alert('Error loading match details');
            }
        }

        async function reconcileMatch(event, type, matchId = null) {
            event.preventDefault();
            
            const notesField = document.getElementById('reconcile-notes');
            const notes = notesField.value.trim();
            
            // Show confirmation dialog
            if (!confirm('Are you sure you want to reconcile this match?')) {
                return;
            }
            
            try {
                const endpoint = type === 'parent' 
                    ? `/api/matches/${matchId}/reconcile-group`
                    : `/api/matches/${matchId}/reconcile`;
                
                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({
                        reconcile_type: 'manual',
                        reconcile_notes: notes || null,
                    }),
                });
                
                const data = await response.json();
                
                if (data.success) {
                    alert('Match reconciled successfully!');
                    // Close the modal
                    document.getElementById('match-detail-modal').style.display = 'none';
                    // Reload the page to reflect changes
                    setTimeout(() => location.reload(), 500);
                } else {
                    alert('Error reconciling match: ' + (data.message || 'Unknown error'));
                }
            } catch (error) {
                console.error('Error reconciling match:', error);
                alert('Error reconciling match');
            }
        }

        async function clearReconciliation(event, matchId) {
            event.preventDefault();
            
            // Show confirmation dialog
            if (!confirm('Are you sure you want to clear the reconciliation for this match? This action cannot be undone.')) {
                return;
            }
            
            try {
                const response = await fetch(`/api/matches/${matchId}/clear-reconciliation`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                });
                
                const data = await response.json();
                
                if (data.success) {
                    alert('Reconciliation cleared successfully!');
                    // Close the modal
                    document.getElementById('match-detail-modal').style.display = 'none';
                    // Reload the page to reflect changes
                    setTimeout(() => location.reload(), 500);
                } else {
                    alert('Error clearing reconciliation: ' + (data.message || 'Unknown error'));
                }
            } catch (error) {
                console.error('Error clearing reconciliation:', error);
                alert('Error clearing reconciliation');
            }
        }

        // Color code filter selects based on their values
        function updateFilterSelectColor(selectElement) {
            const value = selectElement.value;
            let bgColor = 'white';
            let textColor = '#2d3748';

            if (value === 'matched') {
                bgColor = '#dcfce7'; // Light green
                textColor = '#166534'; // Dark green
            } else if (value === 'unmatched') {
                bgColor = '#fee2e2'; // Light red
                textColor = '#991b1b'; // Dark red
            }

            selectElement.style.backgroundColor = bgColor;
            selectElement.style.color = textColor;
        }

        // Color code reconciliation filter based on selection
        function updateReconciliationFilterColor(selectElement) {
            const value = selectElement.value;
            let bgColor = 'white';
            let textColor = '#2d3748';

            if (value === 'hide_reconciled') {
                bgColor = '#fef3c7'; // Light yellow/amber
                textColor = '#92400e'; // Dark amber
            } else if (value === 'show_reconciled') {
                bgColor = '#dcfce7'; // Light green
                textColor = '#166534'; // Dark green
            }

            selectElement.style.backgroundColor = bgColor;
            selectElement.style.color = textColor;
        }

        // Initialize all filter selects on page load
        document.addEventListener('DOMContentLoaded', function() {
            const criteriaSelects = document.querySelectorAll('.criteria-filter-select');
            criteriaSelects.forEach(select => {
                updateFilterSelectColor(select);
            });

            const reconciliationSelect = document.getElementById('reconciliation-filter-select');
            if (reconciliationSelect) {
                updateReconciliationFilterColor(reconciliationSelect);
                // Also update on change
                reconciliationSelect.addEventListener('change', function() {
                    updateReconciliationFilterColor(this);
                });
            }

            // Handle View button clicks
            document.addEventListener('click', function(e) {
                if (e.target.classList.contains('view-match-btn')) {
                    e.stopPropagation();
                    const matchId = e.target.getAttribute('data-match-id');
                    const isParent = e.target.getAttribute('data-is-parent') === 'true';
                    if (isParent) {
                        const groupDataEncoded = e.target.getAttribute('data-group-data');
                        const groupData = JSON.parse(atob(groupDataEncoded));
                        openParentGroupDetailModal(groupData, matchId);
                    } else {
                        openMatchDetailModal(matchId, false);
                    }
                }
            });

            // Handle Expand button clicks
            document.addEventListener('click', function(e) {
                if (e.target.classList.contains('expand-match-btn')) {
                    e.stopPropagation();
                    const groupId = e.target.getAttribute('data-group');
                    // Toggle visibility of detail rows for this group
                    const detailRows = document.querySelectorAll('tr.' + groupId);
                    detailRows.forEach(row => {
                        row.style.display = row.style.display === 'none' ? 'table-row' : 'none';
                    });
                    // Toggle button text to indicate state
                    const allDetailRowsVisible = Array.from(detailRows).every(row => row.style.display !== 'none');
                    e.target.textContent = allDetailRowsVisible ? 'Collapse' : 'Expand';
                    
                    // Toggle purple border on parent row
                    const parentRow = document.querySelector('tr.group-toggle[data-group="' + groupId + '"]');
                    if (parentRow) {
                        if (allDetailRowsVisible) {
                            parentRow.style.borderLeft = '4px solid #805ad5';
                        } else {
                            parentRow.style.borderLeft = '0px solid #805ad5';
                        }
                    }
                }
            });
        });
    </script>
@endsection
