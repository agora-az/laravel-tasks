@extends('layouts.app')

@section('title', 'View Import - ' . $import->filename)

@section('content')
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 style="margin: 0;">Import Details</h2>
        <a href="{{ route('imports.history') }}" class="btn" style="background: #718096; padding: 10px 20px; text-decoration: none;">
            ← Back to History
        </a>
    </div>

    <!-- Import Summary Card -->
    <div class="card" style="margin-bottom: 20px; background: linear-gradient(135deg, #345262 0%, #5a7585 100%); color: white;">
        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 20px;">
            <div>
                <div style="font-size: 14px; opacity: 0.9; margin-bottom: 5px;">Filename</div>
                <div style="font-size: 18px; font-weight: bold;">{{ $import->filename }}</div>
            </div>
            <div>
                <div style="font-size: 14px; opacity: 0.9; margin-bottom: 5px;">Import Date</div>
                <div style="font-size: 18px; font-weight: bold;">{{ $import->created_at->format('Y-m-d H:i:s') }}</div>
            </div>
        </div>
        <div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 20px; text-align: center; padding-top: 20px; border-top: 1px solid rgba(255,255,255,0.2);">
            <div>
                <div style="font-size: 24px; font-weight: bold; margin-bottom: 5px;">{{ number_format($import->imported_count) }}</div>
                <div style="font-size: 12px; opacity: 0.9;">Imported</div>
            </div>
            <div>
                <div style="font-size: 24px; font-weight: bold; margin-bottom: 5px;">{{ number_format($import->duplicate_count) }}</div>
                <div style="font-size: 12px; opacity: 0.9;">Duplicates</div>
            </div>
            <div>
                <div style="font-size: 24px; font-weight: bold; margin-bottom: 5px; color: {{ $import->error_count > 0 ? '#fed7d7' : 'white' }}">{{ number_format($import->error_count) }}</div>
                <div style="font-size: 12px; opacity: 0.9;">Errors</div>
            </div>
            <div>
                <div style="font-size: 24px; font-weight: bold; margin-bottom: 5px;">{{ $import->file_size_mb }} MB</div>
                <div style="font-size: 12px; opacity: 0.9;">File Size</div>
            </div>
            <div>
                <div style="font-size: 24px; font-weight: bold; margin-bottom: 5px;">{{ $import->duration ?? '-' }}s</div>
                <div style="font-size: 12px; opacity: 0.9;">Duration</div>
            </div>
        </div>
    </div>

    <!-- Transactions Table -->
    <div class="card">
        <h3 style="margin-bottom: 15px; color: #2d3748;">
            {{ ucfirst($import->type) }} Transactions ({{ number_format($transactions->total()) }})
        </h3>
        
        @if($transactions->count() > 0)
            <div style="overflow-x: auto;">
                @if($import->type == 'viefund')
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f7fafc; border-bottom: 2px solid #e2e8f0; white-space: nowrap;">
                                <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Client Name</th>
                                <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Rep Code</th>
                                <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Plan</th>
                                <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Institution</th>
                                <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Account ID</th>
                                <th style="padding: 12px; text-align: right; font-weight: 600; color: #2d3748;">Available CAD</th>
                                <th style="padding: 12px; text-align: right; font-weight: 600; color: #2d3748;">Balance CAD</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($transactions as $transaction)
                                <tr style="border-bottom: 1px solid #e2e8f0;">
                                    <td style="padding: 12px; color: #2d3748; font-family: monospace;">{{ $transaction->client_name }}</td>
                                    <td style="padding: 12px; color: #4a5568; font-family: monospace;">{{ $transaction->rep_code }}</td>
                                    <td style="padding: 12px; color: #4a5568; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-family: monospace;" title="{{ $transaction->plan_description }}">
                                        {{ $transaction->plan_description }}
                                    </td>
                                    <td style="padding: 12px; color: #4a5568; font-family: monospace;">{{ $transaction->institution }}</td>
                                    <td style="padding: 12px; color: #4a5568; font-family: monospace;">{{ $transaction->account_id }}</td>
                                    <td style="padding: 12px; text-align: right; color: #2d3748; font-weight: 500; font-family: monospace;">
                                        ${{ number_format($transaction->available_cad, 2) }}
                                    </td>
                                    <td style="padding: 12px; text-align: right; color: #2d3748; font-weight: 500; font-family: monospace;">
                                        ${{ number_format($transaction->balance_cad, 2) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @elseif($import->type == 'fundserv')
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f7fafc; border-bottom: 2px solid #e2e8f0;">
                                <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Company</th>
                                <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Settlement Date</th>
                                <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Trade Date</th>
                                <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Fund ID</th>
                                <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Dealer Account</th>
                                <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Order ID</th>
                                <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Type</th>
                                <th style="padding: 12px; text-align: right; font-weight: 600; color: #2d3748;">Settlement Amt</th>
                                <th style="padding: 12px; text-align: right; font-weight: 600; color: #2d3748;">Actual Amt</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($transactions as $transaction)
                                <tr style="border-bottom: 1px solid #e2e8f0;">
                                    <td style="padding: 12px; color: #2d3748; font-weight: 500;">{{ $transaction->company }}</td>
                                    <td style="padding: 12px; color: #4a5568;">{{ $transaction->settlement_date }}</td>
                                    <td style="padding: 12px; color: #4a5568;">{{ $transaction->trade_date }}</td>
                                    <td style="padding: 12px; color: #4a5568; font-family: monospace;">{{ $transaction->fund_id }}</td>
                                    <td style="padding: 12px; color: #4a5568; font-family: monospace;">{{ $transaction->dealer_account_id }}</td>
                                    <td style="padding: 12px; color: #4a5568; font-family: monospace;">{{ $transaction->order_id }}</td>
                                    <td style="padding: 12px;">
                                        <span style="background: {{ $transaction->tx_type == 'Buy' ? '#c6f6d5' : '#fed7d7' }}; 
                                                     color: {{ $transaction->tx_type == 'Buy' ? '#22543d' : '#742a2a' }}; 
                                                     padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500;">
                                            {{ $transaction->tx_type }}
                                        </span>
                                    </td>
                                    <td style="padding: 12px; text-align: right; color: #2d3748; font-weight: 500;">
                                        ${{ number_format($transaction->settlement_amt, 2) }}
                                    </td>
                                    <td style="padding: 12px; text-align: right; color: #2d3748; font-weight: 500;">
                                        {{ $transaction->actual_amount !== null ? '$' . number_format($transaction->actual_amount, 2) : '-' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f7fafc; border-bottom: 2px solid #e2e8f0;">
                                <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Date</th>
                                <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Description</th>
                                <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Type</th>
                                <th style="padding: 12px; text-align: right; font-weight: 600; color: #2d3748;">Amount</th>
                                <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Account #</th>
                                <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Currency</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($transactions as $transaction)
                                <tr style="border-bottom: 1px solid #e2e8f0;">
                                    <td style="padding: 12px; color: #2d3748; font-family: monospace;">{{ $transaction->txn_date }}</td>
                                    <td style="padding: 12px; color: #4a5568; font-family: monospace;">{{ $transaction->description }}</td>
                                    <td style="padding: 12px; font-family: monospace;">
                                        <span style="background: {{ $transaction->type === 'deposit' ? '#c6f6d5' : '#fed7d7' }}; 
                                                     color: {{ $transaction->type === 'deposit' ? '#22543d' : '#742a2a' }}; 
                                                     padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500;">
                                            {{ ucfirst($transaction->type) }}
                                        </span>
                                    </td>
                                    <td style="padding: 12px; text-align: right; color: {{ $transaction->type === 'deposit' ? '#38a169' : '#e53e3e' }}; font-weight: 500; font-family: monospace;">
                                        ${{ number_format($transaction->amount ?? 0, 2) }}
                                    </td>
                                    <td style="padding: 12px; color: #4a5568; font-family: monospace;">{{ $transaction->account_number ?? '-' }}</td>
                                    <td style="padding: 12px; color: #4a5568; font-family: monospace;">{{ $transaction->currency ?? '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            <!-- Pagination -->
            <div style="margin-top: 20px; display: flex; justify-content: center;">
                {{ $transactions->onEachSide(1)->links() }}
            </div>
        @else
            <div style="text-align: center; padding: 40px; color: #718096;">
                <p style="font-size: 18px; margin-bottom: 10px;">📂 No transactions found for this import</p>
            </div>
        @endif
    </div>
@endsection
