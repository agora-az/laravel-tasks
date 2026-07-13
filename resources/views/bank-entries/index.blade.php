@extends('layouts.app')

@section('title', 'Bank Statement Entries')

@section('content')
<div style="display: flex; justify-content: space-between; align-items: center; margin: 20px 0;">
    <h2 style="margin: 0;">🏦 Bank Statement Entries</h2>
    <span style="color: #718096; font-size: 13px;">Source: CIBC CAMT.053 · Parser v2</span>
</div>

{{-- Summary Cards --}}
<div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px;">
    <div class="card" style="background: linear-gradient(135deg, #345262 0%, #5a7585 100%); color: white; text-align: center;">
        <div style="font-size: 28px; font-weight: bold;">{{ number_format($totals->total_count) }}</div>
        <div style="font-size: 13px; opacity: 0.9; margin-top: 4px;">Matching Entries</div>
    </div>
    <div class="card" style="background: linear-gradient(135deg, #e53e3e 0%, #c53030 100%); color: white; text-align: center;">
        <div style="font-size: 22px; font-weight: bold;">{{ '$' . number_format($totals->total_debits, 2) }}</div>
        <div style="font-size: 13px; opacity: 0.9; margin-top: 4px;">Total Debits</div>
    </div>
    <div class="card" style="background: linear-gradient(135deg, #38a169 0%, #2f855a 100%); color: white; text-align: center;">
        <div style="font-size: 22px; font-weight: bold;">{{ '$' . number_format($totals->total_credits, 2) }}</div>
        <div style="font-size: 13px; opacity: 0.9; margin-top: 4px;">Total Credits</div>
    </div>
    <div class="card" style="background: linear-gradient(135deg, #3182ce 0%, #2c5aa0 100%); color: white; text-align: center;">
        <div style="font-size: 28px; font-weight: bold;">{{ $entries->currentPage() }} / {{ $entries->lastPage() }}</div>
        <div style="font-size: 13px; opacity: 0.9; margin-top: 4px;">Page</div>
    </div>
</div>

{{-- Filters --}}
<div class="card" style="margin-bottom: 20px;">
    <form action="{{ route('bank-entries.index') }}" method="GET">
        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr auto; gap: 12px; align-items: end;">
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
                <select name="channel" style="padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 4px; width: 100%; font-size: 13px;">
                    <option value="">All channels</option>
                    @foreach($channels as $ch)
                        <option value="{{ $ch }}" @selected(request('channel') === $ch)>{{ ucfirst($ch) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; color: #4a5568; margin-bottom: 4px;">Direction</label>
                <select name="direction" style="padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 4px; width: 100%; font-size: 13px;">
                    <option value="">All</option>
                    <option value="DBIT" @selected(request('direction') === 'DBIT')>Debit</option>
                    <option value="CRDT" @selected(request('direction') === 'CRDT')>Credit</option>
                </select>
            </div>
            <div style="display: flex; gap: 8px;">
                <button type="submit" class="btn" style="padding: 8px 20px; white-space: nowrap;">Filter</button>
                @if(request()->hasAny(['date_from','date_to','channel','direction','memo_type','search','sort','sort_dir']))
                    <a href="{{ route('bank-entries.index') }}" class="btn" style="background: #718096; padding: 8px 14px; text-decoration: none;">Clear</a>
                @endif
            </div>
        </div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 12px;">
            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; color: #4a5568; margin-bottom: 4px;">Memo Type</label>
                <select name="memo_type" style="padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 4px; width: 100%; font-size: 13px;">
                    <option value="">All memo types</option>
                    @foreach($memoTypes as $mt)
                        <option value="{{ $mt }}" @selected(request('memo_type') === $mt)>{{ $mt }}</option>
                    @endforeach
                </select>
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
    </form>
</div>

{{-- Table --}}
<div class="card">
@if($entries->count())
    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
            <thead>
                <tr style="background: #f7fafc; border-bottom: 2px solid #e2e8f0; white-space: nowrap;">
                    @php
                        $sort = request('sort', 'value_date');
                        $dir = request('sort_dir', 'desc');
                        $flip = fn($col) => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
                        $arrow = fn($col) => $sort === $col ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
                        $sortUrl = fn($col) => route('bank-entries.index', array_merge(request()->except(['sort','sort_dir','page']), ['sort' => $col, 'sort_dir' => $flip($col)]));
                    @endphp
                    <th style="padding: 10px 12px; text-align: left; color: #2d3748; font-weight: 600;">
                        <a href="{{ $sortUrl('value_date') }}" style="color: inherit; text-decoration: none;">Date{{ $arrow('value_date') }}</a>
                    </th>
                    <th style="padding: 10px 12px; text-align: left; color: #2d3748; font-weight: 600;">
                        <a href="{{ $sortUrl('inferred_channel') }}" style="color: inherit; text-decoration: none;">Channel{{ $arrow('inferred_channel') }}</a>
                    </th>
                    <th style="padding: 10px 12px; text-align: left; color: #2d3748; font-weight: 600;">Dir</th>
                    <th style="padding: 10px 12px; text-align: right; color: #2d3748; font-weight: 600;">
                        <a href="{{ $sortUrl('amount') }}" style="color: inherit; text-decoration: none;">Amount{{ $arrow('amount') }}</a>
                    </th>
                    <th style="padding: 10px 12px; text-align: left; color: #2d3748; font-weight: 600;">
                        <a href="{{ $sortUrl('memo_type') }}" style="color: inherit; text-decoration: none;">Memo Type{{ $arrow('memo_type') }}</a>
                    </th>
                    <th style="padding: 10px 12px; text-align: left; color: #2d3748; font-weight: 600;">
                        <a href="{{ $sortUrl('counterparty') }}" style="color: inherit; text-decoration: none;">Counterparty{{ $arrow('counterparty') }}</a>
                    </th>
                    <th style="padding: 10px 12px; text-align: left; color: #2d3748; font-weight: 600;">Settlement #</th>
                    <th style="padding: 10px 12px; text-align: left; color: #2d3748; font-weight: 600;">Wire Ref</th>
                    <th style="padding: 10px 12px; text-align: left; color: #2d3748; font-weight: 600;">Source File</th>
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
                <tr style="border-bottom: 1px solid #e2e8f0;">
                    <td style="padding: 10px 12px; font-family: monospace; white-space: nowrap; color: #2d3748;">
                        {{ $entry->value_date }}
                    </td>
                    <td style="padding: 10px 12px;">
                        @if($entry->inferred_channel)
                            <span style="background: {{ $chStyle['bg'] }}; color: {{ $chStyle['text'] }}; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; white-space: nowrap;">
                                {{ strtoupper($entry->inferred_channel) }}
                            </span>
                        @else
                            <span style="color: #a0aec0;">—</span>
                        @endif
                    </td>
                    <td style="padding: 10px 12px;">
                        <span style="background: {{ $isCredit ? '#c6f6d5' : '#fed7d7' }}; color: {{ $isCredit ? '#22543d' : '#742a2a' }}; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">
                            {{ $entry->credit_debit_indicator }}
                        </span>
                    </td>
                    <td style="padding: 10px 12px; text-align: right; font-family: monospace; font-weight: 600; color: {{ $isCredit ? '#2f855a' : '#c53030' }}; white-space: nowrap;">
                        ${{ number_format($entry->amount, 2) }}
                    </td>
                    <td style="padding: 10px 12px; color: #4a5568; max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"
                        title="{{ $entry->memo_type }}">
                        {{ $entry->memo_type ?? '—' }}
                    </td>
                    <td style="padding: 10px 12px; color: #4a5568; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"
                        title="{{ $entry->counterparty }}">
                        {{ $entry->counterparty ?? '—' }}
                    </td>
                    <td style="padding: 10px 12px; font-family: monospace; color: #2d3748; white-space: nowrap;">
                        {{ $entry->settlement_number ?? '—' }}
                    </td>
                    <td style="padding: 10px 12px; font-family: monospace; color: #2d3748; white-space: nowrap;">
                        {{ $entry->wire_payment_reference ?? '—' }}
                    </td>
                    <td style="padding: 10px 12px; color: #718096; font-size: 11px; white-space: nowrap;">
                        {{ $entry->source_file }}
                    </td>
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
@endsection
