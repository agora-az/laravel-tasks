@extends('layouts.app')

@section('title', 'Transaction Reconciliation')

@section('content')
@include('reconciliations.partials.source-tabs', ['activeReconciliation' => 'transactions'])

<div style="margin-bottom:18px;">
    <h2 style="margin:0;">Transaction Reconciliation</h2>
    <div style="color:#718096;font-size:13px;margin-top:4px;">VieFund transactions with related EFT items and bank statement transactions.</div>
</div>

<div class="card" style="margin-bottom:18px;padding:16px 20px;">
    @if(session('reconciliation_notice'))
        <div style="margin-bottom:12px;padding:10px 12px;border:1px solid #f6ad55;background:#fffaf0;color:#9c4221;border-radius:5px;font-size:13px;">{{ session('reconciliation_notice') }}</div>
    @endif
    <form method="GET" action="{{ route('reconciliations.transactions') }}" style="display:grid;grid-template-columns:1.2fr .8fr 1fr 1fr auto;gap:12px;align-items:end;">
        <input type="hidden" name="per_page" value="{{ $perPage }}">
        <div>
            <label for="transaction-id" style="display:block;font-size:12px;font-weight:700;color:#4a5568;margin-bottom:5px;">VieFund Transaction ID</label>
            <input id="transaction-id" name="transaction_id" value="{{ $filters['trx_id'] ?? '' }}" placeholder="C-123, T-456, or unprefixed ID" style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #cbd5e0;border-radius:4px;">
        </div>
        <div>
            <label for="date-basis" style="display:block;font-size:12px;font-weight:700;color:#4a5568;margin-bottom:5px;">Date Basis</label>
            <select id="date-basis" name="date_basis" style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #cbd5e0;border-radius:4px;background:#fff;">
                <option value="created" @selected(($filters['date_basis'] ?? 'created') === 'created')>Created</option>
                <option value="trade" @selected(($filters['date_basis'] ?? '') === 'trade')>Trade</option>
                <option value="processing" @selected(($filters['date_basis'] ?? '') === 'processing')>Processing</option>
                <option value="settlement" @selected(($filters['date_basis'] ?? '') === 'settlement')>Settlement</option>
            </select>
        </div>
        <div>
            <label for="date-from" style="display:block;font-size:12px;font-weight:700;color:#4a5568;margin-bottom:5px;">Date From</label>
            <input type="date" id="date-from" name="date_from" value="{{ $filters['created_from'] ?? '' }}" style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #cbd5e0;border-radius:4px;">
        </div>
        <div>
            <label for="date-to" style="display:block;font-size:12px;font-weight:700;color:#4a5568;margin-bottom:5px;">Date To</label>
            <input type="date" id="date-to" name="date_to" value="{{ $filters['created_to'] ?? '' }}" style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #cbd5e0;border-radius:4px;">
        </div>
        <div style="display:flex;gap:8px;">
            <button class="btn" type="submit" style="padding:8px 18px;">Filter</button>
            <a class="btn" href="{{ route('reconciliations.transactions') }}" style="background:#718096;padding:8px 18px;text-decoration:none;">Clear</a>
        </div>
        <div style="grid-column:1 / 2;">
            <label for="transaction-status" style="display:block;font-size:12px;font-weight:700;color:#4a5568;margin-bottom:5px;">Transaction Status</label>
            <select id="transaction-status" name="transaction_status[]" multiple size="4" style="width:100%;box-sizing:border-box;padding:6px 10px;border:1px solid #cbd5e0;border-radius:4px;background:#fff;">
                @foreach($transactionStatusOptions as $status)
                    <option value="{{ $status }}" @selected(in_array($status, (array) ($filters['transaction_status'] ?? []), true))>{{ $status }}</option>
                @endforeach
            </select>
            <div style="margin-top:4px;color:#718096;font-size:11px;">Leave blank for all statuses.</div>
        </div>
        <label for="has-reconciliation-match" style="grid-column:2 / 3;display:inline-flex;align-items:center;align-self:start;gap:8px;width:max-content;color:#4a5568;font-size:13px;font-weight:600;cursor:pointer;margin-top:29px;">
            <input type="checkbox" id="has-reconciliation-match" name="has_reconciliation_match" value="1" @checked(!empty($filters['has_reconciliation_match'])) style="width:16px;height:16px;">
            Display matched
        </label>
    </form>
</div>

<div style="display:flex;align-items:center;justify-content:space-between;gap:18px;flex-wrap:wrap;margin:0 0 12px;font-size:12px;color:#4a5568;">
    <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;">
        <strong style="color:#2d3748;">Data source:</strong>
        <span style="display:inline-flex;align-items:center;gap:6px;"><span style="width:14px;height:14px;border-radius:3px;background:#dbeafe;border:1px solid #93c5fd;"></span>VieFund</span>
        <span style="display:inline-flex;align-items:center;gap:6px;"><span style="width:14px;height:14px;border-radius:3px;background:#dcfce7;border:1px solid #86efac;"></span>EFT</span>
        <span style="display:inline-flex;align-items:center;gap:6px;"><span style="width:14px;height:14px;border-radius:3px;background:#f3e8ff;border:1px solid #d8b4fe;"></span>Bank statement</span>
        <span style="color:#718096;">A dash means no related record or no value.</span>
    </div>
    <div style="display:flex;gap:8px;margin-left:auto;">
        <a class="btn" href="{{ route('reconciliations.transactions', ['jump' => 'latest_eft', 'per_page' => $perPage]) }}" style="background:#2b6cb0;padding:8px 16px;text-decoration:none;white-space:nowrap;">Latest EFT Match</a>
        <a class="btn" href="{{ route('reconciliations.transactions', ['jump' => 'latest_bank', 'per_page' => $perPage]) }}" style="background:#6b46c1;padding:8px 16px;text-decoration:none;white-space:nowrap;">Latest Bank Match</a>
    </div>
</div>

@php
    $dateValue = static fn($value) => $value ? date('Y-m-d H:i:s', strtotime((string) $value)) : '—';
    $money = static function ($value, $direction = null) {
        if ($value === null || $value === '') return '—';
        $amount = (float) $value;
        if ($direction === 'DBIT') $amount = -abs($amount);
        return $amount < 0 ? '($' . number_format(abs($amount), 2) . ')' : '$' . number_format($amount, 2);
    };
    $multi = static function ($items, $callback) {
        if ($items->isEmpty()) return '<span style="color:#a0aec0;">—</span>';
        return $items->map(fn($item) => '<div style="padding:2px 0;white-space:nowrap;">' . e($callback($item)) . '</div>')->implode('');
    };
    $eftStatus = static fn($value) => match ((int) $value) {
        0 => 'Unprocessed',
        1 => 'Processed',
        default => $value === null ? '—' : (string) $value,
    };
@endphp

<div class="card" style="padding:0;overflow:hidden;">
    <style>
        #transaction-reconciliation-scroll {
            max-height: calc(100vh - 190px);
            overflow: auto;
        }
        #transaction-reconciliation-table thead .source-group-row th {
            position: sticky;
            top: 0;
            z-index: 6;
            height: 36px;
            box-sizing: border-box;
        }
        #transaction-reconciliation-table thead .column-heading-row th {
            position: sticky;
            top: 36px;
            z-index: 5;
        }
        #transaction-reconciliation-table tr:target td {
            box-shadow: inset 0 3px #d69e2e, inset 0 -3px #d69e2e;
        }
        #transaction-reconciliation-table tbody tr { scroll-margin-top: 88px; }
        #transaction-reconciliation-table tr:target td:first-child { box-shadow: inset 4px 0 #d69e2e, inset 0 3px #d69e2e, inset 0 -3px #d69e2e; }
    </style>
    <div id="transaction-reconciliation-scroll">
        <table id="transaction-reconciliation-table" style="border-collapse:collapse;min-width:3300px;width:100%;font-size:12px;">
            <thead>
                <tr class="source-group-row">
                    <th colspan="11" style="padding:10px 12px;text-align:left;background:#bfdbfe;color:#1e3a8a;border-right:4px solid #fff;">VIEFUND TRANSACTION</th>
                    <th colspan="11" style="padding:10px 12px;text-align:left;background:#bbf7d0;color:#14532d;border-right:4px solid #fff;">RELATED EFT TRANSACTION</th>
                    <th colspan="9" style="padding:10px 12px;text-align:left;background:#e9d5ff;color:#581c87;">RELATED BANK TRANSACTION</th>
                </tr>
                <tr class="column-heading-row" style="border-bottom:2px solid #94a3b8;">
                    @foreach(['Txn ID','Fund ID','Customer','Plan Account','Type','Status','Created','Trade','Processing','Settlement','Amount'] as $heading)
                        <th style="padding:10px;text-align:{{ $heading === 'Amount' ? 'right' : 'left' }};background:#dbeafe;color:#1e3a8a;white-space:nowrap;">{{ $heading }}</th>
                    @endforeach
                    @foreach(['Matches','Item ID','Sequence','File','Holder ID','Source','Type','Status','Created','Effective','Amount'] as $heading)
                        <th style="padding:10px;{{ $heading === 'File' ? 'padding-right:28px;min-width:300px;' : '' }}text-align:{{ $heading === 'Amount' ? 'right' : 'left' }};background:#dcfce7;color:#14532d;white-space:nowrap;">{{ $heading }}</th>
                    @endforeach
                    @foreach(['Matches','Entry ID','Sequence','Account','Direction','Created','Value Date','Memo','Amount'] as $heading)
                        <th style="padding:10px;text-align:{{ $heading === 'Amount' ? 'right' : 'left' }};background:#f3e8ff;color:#581c87;white-space:nowrap;">{{ $heading }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse($transactions as $transaction)
                    @php
                        $eftMatches = $transaction->eft_matches;
                        $bankMatches = $transaction->bank_matches;
                    @endphp
                    <tr id="transaction-{{ $transaction->transaction_id }}" style="border-bottom:1px solid #cbd5e0;vertical-align:top;">
                        <td style="padding:10px;background:#eff6ff;font-family:monospace;white-space:nowrap;">{{ $transaction->transaction_id }}</td>
                        <td style="padding:10px;background:#eff6ff;font-family:monospace;">{{ $transaction->fund_transaction_id ?: '—' }}</td>
                        <td style="padding:10px;background:#eff6ff;">{{ $transaction->customer_name ?: '—' }}</td>
                        <td style="padding:10px;background:#eff6ff;font-family:monospace;">{{ $transaction->plan_account_id ?: '—' }}</td>
                        <td style="padding:10px;background:#eff6ff;">{{ $transaction->transaction_type ?: '—' }}</td>
                        <td style="padding:10px;background:#eff6ff;">{{ $transaction->status ?: '—' }}</td>
                        <td style="padding:10px;background:#eff6ff;white-space:nowrap;">{{ $dateValue($transaction->created_date) }}</td>
                        <td style="padding:10px;background:#eff6ff;white-space:nowrap;">{{ $dateValue($transaction->trade_date) }}</td>
                        <td style="padding:10px;background:#eff6ff;white-space:nowrap;">{{ $dateValue($transaction->processing_date) }}</td>
                        <td style="padding:10px;background:#eff6ff;white-space:nowrap;">{{ $dateValue($transaction->settlement_date) }}</td>
                        <td style="padding:10px;background:#eff6ff;text-align:right;font-family:monospace;white-space:nowrap;">{{ $money($transaction->amount) }}</td>

                        <td style="padding:10px;background:#f0fdf4;text-align:center;font-weight:700;">{{ $eftMatches->count() ?: '—' }}</td>
                        <td style="padding:10px;background:#f0fdf4;font-family:monospace;">{!! $multi($eftMatches, fn($row) => $row->id) !!}</td>
                        <td style="padding:10px;background:#f0fdf4;font-family:monospace;">{!! $multi($eftMatches, fn($row) => $row->sequence_number ?: '—') !!}</td>
                        <td style="padding:10px 28px 10px 10px;background:#f0fdf4;min-width:300px;white-space:nowrap;">{!! $multi($eftMatches, fn($row) => $row->file_name ?: '—') !!}</td>
                        <td style="padding:10px;background:#f0fdf4;font-family:monospace;">{!! $multi($eftMatches, fn($row) => $row->holder_id ?: '—') !!}</td>
                        <td style="padding:10px;background:#f0fdf4;" title="Raw EFT source code">{!! $multi($eftMatches, fn($row) => $row->source_name ?: ($row->source_code ?: '—')) !!}</td>
                        <td style="padding:10px;background:#f0fdf4;">{!! $multi($eftMatches, fn($row) => $row->type_name ?: '—') !!}</td>
                        <td style="padding:10px;background:#f0fdf4;">{!! $multi($eftMatches, fn($row) => $eftStatus($row->status_id)) !!}</td>
                        <td style="padding:10px;background:#f0fdf4;">{!! $multi($eftMatches, fn($row) => $dateValue($row->created_at)) !!}</td>
                        <td style="padding:10px;background:#f0fdf4;">{!! $multi($eftMatches, fn($row) => $dateValue($row->effective_date)) !!}</td>
                        <td style="padding:10px;background:#f0fdf4;text-align:right;font-family:monospace;">{!! $multi($eftMatches, fn($row) => $money($row->amount)) !!}</td>

                        <td style="padding:10px;background:#faf5ff;text-align:center;font-weight:700;">{{ $bankMatches->count() ?: '—' }}</td>
                        <td style="padding:10px;background:#faf5ff;font-family:monospace;">{!! $multi($bankMatches, fn($row) => $row->id) !!}</td>
                        <td style="padding:10px;background:#faf5ff;font-family:monospace;">{!! $multi($bankMatches, fn($row) => $row->settlement_number ?: '—') !!}</td>
                        <td style="padding:10px;background:#faf5ff;font-family:monospace;">{!! $multi($bankMatches, fn($row) => $row->account_number ?: '—') !!}</td>
                        <td style="padding:10px;background:#faf5ff;">{!! $multi($bankMatches, fn($row) => $row->credit_debit_indicator ?: '—') !!}</td>
                        <td style="padding:10px;background:#faf5ff;">{!! $multi($bankMatches, fn($row) => $dateValue($row->created_at)) !!}</td>
                        <td style="padding:10px;background:#faf5ff;">{!! $multi($bankMatches, fn($row) => $dateValue($row->value_date)) !!}</td>
                        <td style="padding:10px;background:#faf5ff;max-width:220px;">{!! $multi($bankMatches, fn($row) => $row->memo_type ?: ($row->additional_info ?: '—')) !!}</td>
                        <td style="padding:10px;background:#faf5ff;text-align:right;font-family:monospace;">{!! $multi($bankMatches, fn($row) => $money($row->amount, $row->credit_debit_indicator)) !!}</td>
                    </tr>
                @empty
                    <tr><td colspan="31" style="padding:48px;text-align:center;color:#718096;">No VieFund transactions match the selected filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div style="padding:14px 16px;border-top:1px solid #e2e8f0;display:flex;align-items:center;gap:14px;flex-wrap:wrap;font-size:13px;color:#718096;">
        <label for="reconciliation-per-page">Rows per page:</label>
        <select id="reconciliation-per-page" onchange="window.location=this.value" style="border:1px solid #cbd5e0;border-radius:4px;padding:5px 8px;background:#fff;">
            @foreach([50,100,250] as $option)
                <option value="{{ request()->fullUrlWithQuery(['per_page'=>$option,'page'=>1]) }}" @selected($perPage === $option)>{{ $option }}</option>
            @endforeach
        </select>
        <span>Showing {{ number_format($transactions->firstItem() ?? 0) }}–{{ number_format($transactions->lastItem() ?? 0) }} of {{ number_format($transactions->total()) }} VieFund transactions</span>
        @php
            $currentPage = $transactions->currentPage();
            $lastPage = $transactions->lastPage();
            $pageNumbers = array_values(array_unique(array_filter([
                1,
                $currentPage - 2,
                $currentPage - 1,
                $currentPage,
                $currentPage + 1,
                $currentPage + 2,
                $lastPage,
            ], fn($page) => $page >= 1 && $page <= $lastPage)));
            sort($pageNumbers);
            $pageButton = 'display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:34px;padding:0 9px;border:1px solid #cbd5e0;border-radius:5px;text-decoration:none;color:#2d3748;background:#fff;box-sizing:border-box;';
        @endphp
        @if($lastPage > 1)
            <nav aria-label="Transaction reconciliation pages" style="margin-left:auto;display:flex;align-items:center;justify-content:flex-end;gap:4px;max-width:100%;flex-wrap:wrap;">
                @if($transactions->onFirstPage())
                    <span style="{{ $pageButton }}color:#a0aec0;">‹</span>
                @else
                    <a href="{{ $transactions->previousPageUrl() }}" style="{{ $pageButton }}">‹</a>
                @endif
                @php $previousNumber = null; @endphp
                @foreach($pageNumbers as $pageNumber)
                    @if($previousNumber !== null && $pageNumber > $previousNumber + 1)
                        <span style="padding:0 4px;">…</span>
                    @endif
                    @if($pageNumber === $currentPage)
                        <span aria-current="page" style="{{ $pageButton }}background:#2c5364;color:#fff;border-color:#2c5364;font-weight:700;">{{ $pageNumber }}</span>
                    @else
                        <a href="{{ $transactions->url($pageNumber) }}" style="{{ $pageButton }}">{{ $pageNumber }}</a>
                    @endif
                    @php $previousNumber = $pageNumber; @endphp
                @endforeach
                @if($transactions->hasMorePages())
                    <a href="{{ $transactions->nextPageUrl() }}" style="{{ $pageButton }}">›</a>
                @else
                    <span style="{{ $pageButton }}color:#a0aec0;">›</span>
                @endif
            </nav>
        @endif
    </div>
</div>

@if(request()->has('page'))
<script>
    window.addEventListener('load', function () {
        if (!window.location.hash) return;

        const target = document.getElementById(decodeURIComponent(window.location.hash.slice(1)));
        if (target && target.closest('#transaction-reconciliation-table')) {
            requestAnimationFrame(function () {
                target.scrollIntoView({ behavior: 'auto', block: 'center', inline: 'nearest' });
            });
        }
    });
</script>
@endif
@endsection
