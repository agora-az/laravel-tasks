@php
    $money = static function ($value) {
        $amount = (float) $value;
        $formatted = '$' . number_format(abs($amount), 2);
        return $amount < 0 ? '(' . $formatted . ')' : $formatted;
    };
    $sortUrl = static function (string $field) use ($filters): string {
        $baseQuery = request()->except(['sort', 'sort_dir', 'page', '_results']);

        return route('reconciliations.customer-balances', array_merge($baseQuery, [
            'sort' => $field,
            'sort_dir' => $filters['sort'] === $field && $filters['sort_dir'] === 'asc' ? 'desc' : 'asc',
        ]));
    };
    $sortArrow = static fn(string $field): string => $filters['sort'] === $field
        ? ($filters['sort_dir'] === 'asc' ? ' ↑' : ' ↓')
        : ' ⇅';
@endphp

<div class="customer-balance-summary" style="display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:12px;margin-bottom:14px;">
    <div class="card" style="padding:14px 16px;">
        <div style="font-size:11px;font-weight:700;color:#718096;text-transform:uppercase;letter-spacing:.04em;">Total Cash Balance</div>
        <div style="font-size:20px;font-weight:700;color:#2d3748;margin-top:4px;">{{ $money($summary->total_balance ?? 0) }} <span style="font-size:12px;color:#718096;">{{ $filters['currency'] }}</span></div>
    </div>
    <div class="card" style="padding:14px 16px;">
        <div style="font-size:11px;font-weight:700;color:#718096;text-transform:uppercase;letter-spacing:.04em;">Plan Accounts</div>
        <div style="font-size:20px;font-weight:700;color:#2d3748;margin-top:4px;">{{ number_format((int) ($summary->plan_accounts ?? 0)) }}</div>
    </div>
    <div class="card" style="padding:14px 16px;">
        <div style="font-size:11px;font-weight:700;color:#718096;text-transform:uppercase;letter-spacing:.04em;">Reported Account Rows</div>
        <div style="font-size:20px;font-weight:700;color:#2d3748;margin-top:4px;">{{ number_format((int) ($summary->account_rows ?? 0)) }}</div>
    </div>
    <div class="card" style="padding:14px 16px;">
        <div style="font-size:11px;font-weight:700;color:#718096;text-transform:uppercase;letter-spacing:.04em;">Future Settlement Cash</div>
        <div style="font-size:20px;font-weight:700;color:{{ (float) ($summary->future_settlement_cash ?? 0) > 0 ? '#b45309' : '#2d3748' }};margin-top:4px;">{{ $money($summary->future_settlement_cash ?? 0) }}</div>
    </div>
</div>

<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:10px;font-size:12px;color:#4a5568;">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <strong>Balance source:</strong>
        <span style="display:inline-flex;align-items:center;gap:6px;padding:5px 9px;border-radius:999px;background:#fef3c7;color:#92400e;font-weight:700;">
            <span style="width:7px;height:7px;border-radius:50%;background:currentColor;"></span>Direct Cash Ledger (VieFund database)
        </span>
        @if(!empty($filters['opened_before']))
            <span>Simulated as of {{ $filters['opened_before'] }} Eastern</span>
        @endif
    </div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:flex-end;">
        <span>Balances include cash activity through {{ $filters['report_date'] }}.</span>
        <button type="button" id="customer-balance-excel-export" style="display:inline-flex;align-items:center;padding:7px 12px;border:0;border-radius:5px;background:#0f766e;color:#fff;font-size:12px;font-weight:700;cursor:pointer;">↓ Export Excel</button>
    </div>
</div>

<form id="customer-balance-excel-form" action="{{ route('reports.viefund-customer-balances.run') }}" method="POST" style="display:none;">
    @csrf
    <input type="hidden" name="customer_balance_date" value="{{ $filters['report_date'] }}">
    <input type="hidden" name="customer_balance_date_basis" value="{{ $filters['date_basis'] }}">
    <input type="hidden" name="customer_balance_currency_code" value="{{ $filters['currency'] }}">
    <input type="hidden" name="customer_balance_opened_before" value="{{ $filters['opened_before'] ?? '' }}">
    <input type="hidden" name="customer_balance_search" value="{{ $filters['search'] ?? '' }}">
    <input type="hidden" name="customer_balance_sort" value="{{ $filters['sort'] }}">
    <input type="hidden" name="customer_balance_sort_dir" value="{{ $filters['sort_dir'] }}">
    @foreach($filters['status'] as $status)
        <input type="hidden" name="customer_balance_status[]" value="{{ $status }}">
    @endforeach
    <input type="hidden" name="format" value="excel">
</form>
<div id="customer-balance-export-status" role="status" style="display:none;margin:0 0 10px;padding:9px 12px;border:1px solid #99f6e4;border-radius:5px;background:#ecfdf5;color:#115e59;font-size:12px;font-weight:600;"></div>

<div class="card" style="padding:0;overflow:hidden;">
    <div style="max-height:calc(100vh - 210px);overflow:auto;">
        <table style="border-collapse:collapse;width:100%;min-width:1900px;font-size:12px;">
            <thead>
                <tr style="border-bottom:2px solid #94a3b8;">
                    @foreach([
                        ['Client Name', 'left', 'client_name'],
                        ['Rep Code', 'left', null],
                        ['Plan Account ID', 'left', null],
                        ['Cash Account ID', 'left', null],
                        ['Account Status', 'left', 'account_status'],
                        ['Cash Transactions', 'right', 'cash_transaction_count'],
                        ['Cash Balance (' . $filters['currency'] . ')', 'right', 'total_balance'],
                        ['Future Settlement Transactions', 'right', 'future_settlement_transaction_count'],
                        ['Future Settlement Cash', 'right', 'future_settlement_cash'],
                        ['Next Settlement Date', 'left', 'next_settlement_date'],
                        ['Clarification Note', 'left', null],
                    ] as [$heading, $alignment, $columnSort])
                        <th style="position:sticky;top:0;z-index:2;padding:11px 12px;text-align:{{ $alignment }};background:#dbeafe;color:#1e3a8a;white-space:nowrap;">
                            @if($columnSort)
                                <a href="{{ $sortUrl($columnSort) }}" style="color:inherit;text-decoration:none;">{{ $heading }}<span aria-hidden="true">{{ $sortArrow($columnSort) }}</span></a>
                            @else
                                {{ $heading }}
                            @endif
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse($balances as $balance)
                    @php
                        $futureCount = (int) ($balance->future_settlement_transaction_count ?? 0);
                        $nextSettlementDate = !empty($balance->next_settlement_date)
                            ? \Carbon\Carbon::parse($balance->next_settlement_date)->toDateString()
                            : '—';
                    @endphp
                    <tr style="border-bottom:1px solid #e2e8f0;vertical-align:top;">
                        <td style="padding:10px 12px;">{{ trim((string) ($balance->client_name ?? '')) ?: '—' }}</td>
                        <td style="padding:10px 12px;font-family:monospace;">{{ $balance->rep_code ?: '—' }}</td>
                        <td style="padding:10px 12px;font-family:monospace;">{{ $balance->plan_account_id ?: '—' }}</td>
                        <td style="padding:10px 12px;font-family:monospace;">{{ $balance->account_id ?: '—' }}</td>
                        <td style="padding:10px 12px;">{{ $balance->account_status ?: '—' }}</td>
                        <td style="padding:10px 12px;text-align:right;font-variant-numeric:tabular-nums;">{{ number_format((int) ($balance->cash_transaction_count ?? 0)) }}</td>
                        <td style="padding:10px 12px;text-align:right;font-family:monospace;font-weight:700;">{{ $money($balance->total_balance ?? 0) }}</td>
                        <td style="padding:10px 12px;text-align:right;font-variant-numeric:tabular-nums;">{{ number_format($futureCount) }}</td>
                        <td style="padding:10px 12px;text-align:right;font-family:monospace;color:{{ (float) ($balance->future_settlement_cash ?? 0) > 0 ? '#b45309' : '#2d3748' }};">{{ $money($balance->future_settlement_cash ?? 0) }}</td>
                        <td style="padding:10px 12px;white-space:nowrap;">{{ $nextSettlementDate }}</td>
                        <td style="padding:10px 12px;color:{{ $futureCount > 0 ? '#92400e' : '#a0aec0' }};max-width:300px;">{{ $futureCount > 0 ? 'Positive confirmed cash linked to unsettled trust; review required.' : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="11" style="padding:48px;text-align:center;color:#718096;">No customer balance rows match the selected criteria.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div style="padding:14px 16px;border-top:1px solid #e2e8f0;display:flex;align-items:center;gap:14px;flex-wrap:wrap;font-size:13px;color:#718096;">
        <label for="customer-balance-per-page">Rows per page:</label>
        <select id="customer-balance-per-page" data-async-results-select style="border:1px solid #cbd5e0;border-radius:4px;padding:5px 8px;background:#fff;">
            @foreach([50,100,250] as $option)
                <option value="{{ request()->fullUrlWithQuery(['per_page'=>$option,'page'=>1,'_results'=>null]) }}" @selected($perPage === $option)>{{ $option }}</option>
            @endforeach
        </select>
        <span>Showing {{ number_format($balances->firstItem() ?? 0) }}–{{ number_format($balances->lastItem() ?? 0) }} of {{ number_format($balances->total()) }} account rows</span>
        @php
            $currentPage = $balances->currentPage();
            $lastPage = $balances->lastPage();
            $pageNumbers = array_values(array_unique(array_filter([
                1,
                $currentPage - 2,
                $currentPage - 1,
                $currentPage,
                $currentPage + 1,
                $currentPage + 2,
                $lastPage,
            ], fn($pageNumber) => $pageNumber >= 1 && $pageNumber <= $lastPage)));
            sort($pageNumbers);
            $pageButton = 'display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:34px;padding:0 9px;border:1px solid #cbd5e0;border-radius:5px;text-decoration:none;color:#2d3748;background:#fff;box-sizing:border-box;';
        @endphp
        @if($lastPage > 1)
            <nav aria-label="Customer balance pages" style="margin-left:auto;display:flex;align-items:center;justify-content:flex-end;gap:4px;max-width:100%;flex-wrap:wrap;">
                @if($balances->onFirstPage())
                    <span style="{{ $pageButton }}color:#a0aec0;">‹</span>
                @else
                    <a href="{{ $balances->previousPageUrl() }}" style="{{ $pageButton }}">‹</a>
                @endif
                @php $previousNumber = null; @endphp
                @foreach($pageNumbers as $pageNumber)
                    @if($previousNumber !== null && $pageNumber > $previousNumber + 1)
                        <span style="padding:0 4px;">…</span>
                    @endif
                    @if($pageNumber === $currentPage)
                        <span aria-current="page" style="{{ $pageButton }}background:#2c5364;color:#fff;border-color:#2c5364;font-weight:700;">{{ $pageNumber }}</span>
                    @else
                        <a href="{{ $balances->url($pageNumber) }}" style="{{ $pageButton }}">{{ $pageNumber }}</a>
                    @endif
                    @php $previousNumber = $pageNumber; @endphp
                @endforeach
                @if($balances->hasMorePages())
                    <a href="{{ $balances->nextPageUrl() }}" style="{{ $pageButton }}">›</a>
                @else
                    <span style="{{ $pageButton }}color:#a0aec0;">›</span>
                @endif
            </nav>
        @endif
    </div>
</div>
