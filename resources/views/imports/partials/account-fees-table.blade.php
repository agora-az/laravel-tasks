@if(session('success'))
    <div class="alert alert-success">
        {{ session('success') }}
    </div>
@endif

<!-- Summary Card -->
<div class="card" style="margin-bottom: 20px; background: linear-gradient(135deg, #805ad5 0%, #553c9a 100%); color: white;">
    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; text-align: center;">
        <div>
            <div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;">{{ number_format($totalRecords) }}</div>
            <div style="font-size: 14px; opacity: 0.9;">Total Records</div>
        </div>
        <div>
            <div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;">{{ number_format($transactions->total()) }}</div>
            <div style="font-size: 14px; opacity: 0.9;">
                @if(request('search'))
                    Search Results
                @else
                    Total Transactions
                @endif
            </div>
        </div>
        <div>
            <div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;">{{ $transactions->currentPage() }}/{{ $transactions->lastPage() }}</div>
            <div style="font-size: 14px; opacity: 0.9;">Current Page</div>
        </div>
    </div>
</div>

<!-- Search Bar -->
<div class="card" style="margin-bottom: 20px;">
    <form action="{{ route('imports.transactions', ['type' => 'account-fees']) }}" method="GET">
        <div style="display: flex; gap: 10px;">
            <input type="text" name="search" placeholder="Search by client name, rep code, or transaction type..." 
                   value="{{ request('search') }}"
                   style="flex: 1; padding: 10px; border: 1px solid #cbd5e0; border-radius: 4px;">
            <button type="submit" class="btn" style="padding: 10px 30px;">Search</button>
            @if(request('search'))
                <a href="{{ route('imports.transactions', ['type' => 'account-fees']) }}" class="btn" style="background: #718096; padding: 10px 20px; text-decoration: none;">Clear</a>
            @endif
        </div>
    </form>
</div>

<!-- Transactions Table -->
<div class="card">
    @if($transactions->count() > 0)
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f7fafc; border-bottom: 2px solid #e2e8f0;">
                        <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Rep Code</th>
                        <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Client Name</th>
                        <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Plan Description</th>
                        <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Account Description</th>
                        <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Transaction Type</th>
                        <th style="padding: 12px; text-align: right; font-weight: 600; color: #2d3748;">Amount</th>
                        <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Trade Date</th>
                        <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Settlement Date</th>
                        <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Trust Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($transactions as $transaction)
                        <tr style="border-bottom: 1px solid #e2e8f0; hover: background: #f7fafc;">
                            <td style="padding: 12px; color: #2d3748; font-family: monospace;">{{ $transaction->rep_code ?? '-' }}</td>
                            <td style="padding: 12px; color: #2d3748; font-family: monospace;">{{ $transaction->client_name }}</td>
                            <td style="padding: 12px; color: #2d3748; font-family: monospace;">{{ $transaction->plan_description ?? '-' }}</td>
                            <td style="padding: 12px; color: #2d3748; font-family: monospace;">{{ $transaction->account_description ?? '-' }}</td>
                            <td style="padding: 12px; color: #2d3748; font-family: monospace;">{{ $transaction->transaction_type }}</td>
                            <td style="padding: 12px; text-align: right; color: #2d3748; font-weight: 500; font-family: monospace;">
                                ${{ number_format($transaction->amount, 2) }}
                            </td>
                            <td style="padding: 12px; color: #2d3748; font-family: monospace;">
                                {{ $transaction->trade_date ? \Carbon\Carbon::parse($transaction->trade_date)->format('M d, Y') : '-' }}
                            </td>
                            <td style="padding: 12px; color: #2d3748; font-family: monospace;">
                                {{ $transaction->settlement_date ? \Carbon\Carbon::parse($transaction->settlement_date)->format('M d, Y') : '-' }}
                            </td>
                            <td style="padding: 12px; color: #2d3748; font-family: monospace;">
                                @if($transaction->trust_status)
                                    <span style="display: inline-block; padding: 4px 8px; background: #bee3f8; color: #2c5aa0; border-radius: 4px; font-size: 12px;">
                                        {{ $transaction->trust_status }}
                                    </span>
                                @else
                                    <span style="color: #a0aec0;">-</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div style="margin-top: 20px; display: flex; justify-content: center;">
            {{ $transactions->appends(request()->query())->links() }}
        </div>
    @else
        <div style="padding: 40px; text-align: center;">
            <p style="font-size: 18px; color: #4a5568;">No account fee transactions found.</p>
        </div>
    @endif
</div>
