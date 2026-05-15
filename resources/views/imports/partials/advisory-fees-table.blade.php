@if(session('success'))
    <div class="alert alert-success">
        {{ session('success') }}
    </div>
@endif

<!-- Transaction Type Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 20px;">
    <!-- VieFund Card -->
    <div class="card" style="background: linear-gradient(135deg, #38a169 0%, #234e52 100%); color: white; padding: 20px;">
        <div style="text-align: center;">
            <div style="font-size: 28px; margin-bottom: 8px;">📊</div>
            <div style="font-size: 24px; font-weight: bold; margin-bottom: 5px;">{{ number_format($transactionCounts['viefund'] ?? 0) }}</div>
            <div style="font-size: 13px; opacity: 0.9;">VieFund</div>
        </div>
    </div>

    <!-- Fundserv Card -->
    <div class="card" style="background: linear-gradient(135deg, #3182ce 0%, #2c5282 100%); color: white; padding: 20px;">
        <div style="text-align: center;">
            <div style="font-size: 28px; margin-bottom: 8px;">📈</div>
            <div style="font-size: 24px; font-weight: bold; margin-bottom: 5px;">{{ number_format($transactionCounts['fundserv'] ?? 0) }}</div>
            <div style="font-size: 13px; opacity: 0.9;">Fundserv</div>
        </div>
    </div>

    <!-- Bank Card -->
    <div class="card" style="background: linear-gradient(135deg, #d97706 0%, #7c2d12 100%); color: white; padding: 20px;">
        <div style="text-align: center;">
            <div style="font-size: 28px; margin-bottom: 8px;">🏦</div>
            <div style="font-size: 24px; font-weight: bold; margin-bottom: 5px;">{{ number_format($transactionCounts['bank'] ?? 0) }}</div>
            <div style="font-size: 13px; opacity: 0.9;">Bank</div>
        </div>
    </div>

    <!-- Account Fees Card -->
    <div class="card" style="background: linear-gradient(135deg, #805ad5 0%, #553c9a 100%); color: white; padding: 20px;">
        <div style="text-align: center;">
            <div style="font-size: 28px; margin-bottom: 8px;">💰</div>
            <div style="font-size: 24px; font-weight: bold; margin-bottom: 5px;">{{ number_format($transactionCounts['account-fees'] ?? 0) }}</div>
            <div style="font-size: 13px; opacity: 0.9;">Account Fees</div>
        </div>
    </div>

    <!-- Advisory Fees Card -->
    <div class="card" style="background: linear-gradient(135deg, #d69e2e 0%, #7d6608 100%); color: white; padding: 20px;">
        <div style="text-align: center;">
            <div style="font-size: 28px; margin-bottom: 8px;">📋</div>
            <div style="font-size: 24px; font-weight: bold; margin-bottom: 5px;">{{ number_format($transactionCounts['advisory-fees'] ?? 0) }}</div>
            <div style="font-size: 13px; opacity: 0.9;">Advisory Fees</div>
        </div>
    </div>
</div>

<!-- Trigger Matching Button -->
<div style="margin-bottom: 20px; display: flex; justify-content: flex-end;">
    <form method="POST" action="{{ route('reconciliations.matches.find') }}" style="display: inline;">
        @csrf
        <button type="submit" class="btn" style="padding: 12px 24px; background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: 600; font-size: 14px; transition: all 0.2s;">
            ⚡ Run Matching Process
        </button>
    </form>
</div>

<!-- Summary Card -->
<div class="card" style="margin-bottom: 20px; background: linear-gradient(135deg, #d69e2e 0%, #7d6608 100%); color: white;">
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
    <form action="{{ route('imports.transactions', ['type' => 'advisory-fees']) }}" method="GET">
        <div style="display: flex; gap: 10px;">
            <input type="text" name="search" placeholder="Search by client name, plan info, or transaction type..." 
                   value="{{ request('search') }}"
                   style="flex: 1; padding: 10px; border: 1px solid #cbd5e0; border-radius: 4px;">
            <button type="submit" class="btn" style="padding: 10px 30px;">Search</button>
            @if(request('search'))
                <a href="{{ route('imports.transactions', ['type' => 'advisory-fees']) }}" class="btn" style="background: #718096; padding: 10px 20px; text-decoration: none;">Clear</a>
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
                        <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Client Name</th>
                        <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Plan Info</th>
                        <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Transaction Type</th>
                        <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Fund Description</th>
                        <th style="padding: 12px; text-align: right; font-weight: 600; color: #2d3748;">Amount</th>
                        <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Effective Date</th>
                        <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Settlement Date</th>
                        <th style="padding: 12px; text-align: center; font-weight: 600; color: #2d3748;">Currency</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($transactions as $transaction)
                        <tr style="border-bottom: 1px solid #e2e8f0; hover: background: #f7fafc;">
                            <td style="padding: 12px; color: #2d3748; font-family: monospace;">{{ $transaction->full_name ?? '-' }}</td>
                            <td style="padding: 12px; color: #2d3748; font-family: monospace;">{{ $transaction->plan_info ?? '-' }}</td>
                            <td style="padding: 12px; color: #2d3748; font-family: monospace;">{{ $transaction->transaction_type ?? '-' }}</td>
                            <td style="padding: 12px; color: #2d3748; font-family: monospace;">{{ $transaction->fund_description ?? '-' }}</td>
                            <td style="padding: 12px; text-align: right; color: #2d3748; font-weight: 500; font-family: monospace;">
                                ${{ number_format($transaction->amount, 2) }}
                            </td>
                            <td style="padding: 12px; color: #2d3748; font-family: monospace;">
                                {{ $transaction->effective_date ? \Carbon\Carbon::parse($transaction->effective_date)->format('M d, Y') : '-' }}
                            </td>
                            <td style="padding: 12px; color: #2d3748; font-family: monospace;">
                                {{ $transaction->settlement_date ? \Carbon\Carbon::parse($transaction->settlement_date)->format('M d, Y') : '-' }}
                            </td>
                            <td style="padding: 12px; text-align: center; color: #2d3748; font-family: monospace;">
                                <span style="display: inline-block; padding: 4px 8px; background: #f7fafc; color: #4a5568; border-radius: 4px; font-size: 12px; font-weight: 500;">
                                    {{ $transaction->currency ?? 'CAD' }}
                                </span>
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
            <p style="font-size: 18px; color: #4a5568;">No advisory fee transactions found.</p>
        </div>
    @endif
</div>
