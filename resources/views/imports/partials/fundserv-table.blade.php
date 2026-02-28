@if(session('success'))
    <div class="alert alert-success">
        {{ session('success') }}
    </div>
@endif

<!-- Summary Card -->
<div class="card" style="margin-bottom: 20px; background: linear-gradient(135deg, #345262 0%, #5a7585 100%); color: white;">
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
    <div style="display: flex; gap: 10px;">
        <form action="{{ route('imports.transactions', ['type' => 'fundserv']) }}" method="GET" style="flex: 1; display: flex; gap: 10px;">
            <input type="text" name="search" placeholder="Search by company, order ID, dealer account, fund ID..."
                   value="{{ request('search') }}"
                   style="flex: 1; padding: 10px; border: 1px solid #cbd5e0; border-radius: 4px;">
            <button type="submit" class="btn" style="padding: 10px 30px;">Search</button>
            @if(request('search'))
                <a href="{{ route('imports.transactions', ['type' => 'fundserv']) }}" class="btn" style="background: #718096; padding: 10px 20px; text-decoration: none;">Clear</a>
            @endif
        </form>
        <form action="{{ route('imports.fundserv.truncate') }}" method="POST" style="display: inline;"
              onsubmit="return confirm('Are you sure you want to delete ALL Fundserv transactions? This cannot be undone!');">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn" style="background: #e53e3e; padding: 10px 20px;">
                🗑️ Delete All
            </button>
        </form>
    </div>
</div>

<!-- Transactions Table -->
<div class="card">
    @if($transactions->count() > 0)
        <div style="overflow-x: auto;">
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
                        <tr class="fundserv-row" style="border-bottom: 1px solid #e2e8f0; cursor: pointer;"
                            data-company="{{ $transaction->company }}"
                            data-settlement-date="{{ $transaction->settlement_date }}"
                            data-trade-date="{{ $transaction->trade_date }}"
                            data-fund-id="{{ $transaction->fund_id }}"
                            data-dealer-account-id="{{ $transaction->dealer_account_id }}"
                            data-order-id="{{ $transaction->order_id }}"
                            data-tx-type="{{ $transaction->tx_type }}"
                            data-settlement-amt="{{ $transaction->settlement_amt }}"
                            data-actual-amount="{{ $transaction->actual_amount }}"
                            data-code="{{ $transaction->code }}"
                            data-src="{{ $transaction->src }}"
                            data-source-identifier="{{ $transaction->source_identifier }}">
                            <td style="padding: 12px; color: #4a5568; font-family: monospace;">{{ $transaction->company }}</td>
                            <td style="padding: 12px; color: #4a5568; font-family: monospace;">{{ $transaction->settlement_date }}</td>
                            <td style="padding: 12px; color: #4a5568; font-family: monospace;">{{ $transaction->trade_date }}</td>
                            <td style="padding: 12px; color: #4a5568; font-family: monospace;">{{ $transaction->fund_id }}</td>
                            <td style="padding: 12px; color: #4a5568; font-family: monospace;">{{ $transaction->dealer_account_id }}</td>
                            <td style="padding: 12px; color: #4a5568; font-family: monospace;">{{ $transaction->order_id }}</td>
                            <td style="padding: 12px; font-family: monospace;">
                                <span style="background: {{ $transaction->tx_type == 'Buy' ? '#c6f6d5' : '#fed7d7' }}; 
                                             color: {{ $transaction->tx_type == 'Buy' ? '#22543d' : '#742a2a' }}; 
                                             padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500;">
                                    {{ $transaction->tx_type }}
                                </span>
                            </td>
                            <td style="padding: 12px; text-align: right; color: #2d3748; font-family: monospace;">
                                ${{ number_format($transaction->settlement_amt, 2) }}
                            </td>
                            <td style="padding: 12px; text-align: right; color: #2d3748; font-family: monospace;">
                                {{ $transaction->actual_amount !== null ? '$' . number_format($transaction->actual_amount, 2) : '-' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div style="margin-top: 20px; display: flex; justify-content: center;">
            {{ $transactions->onEachSide(1)->appends(['search' => request('search')])->links() }}
        </div>
    @else
        <div style="text-align: center; padding: 40px; color: #718096;">
            <p style="font-size: 18px; margin-bottom: 10px;">📂 No transactions found</p>
            <p>{{ request('search') ? 'Try a different search term' : 'Import some data to get started' }}</p>
        </div>
    @endif
</div>

<!-- Transaction Details Modal -->
<div id="fundserv-modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); align-items: center; justify-content: center; z-index: 50;">
    <div style="background: #fff; width: 90%; max-width: 800px; border-radius: 10px; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.2);">
        <div style="display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; border-bottom: 1px solid #e2e8f0;">
            <h3 style="margin: 0; color: #2d3748;">Fundserv Transaction Details</h3>
            <button type="button" id="fundserv-modal-close" class="btn" style="background: #718096; padding: 6px 12px;">Close</button>
        </div>
        <div style="padding: 20px; max-height: 70vh; overflow: auto;">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div style="display: grid; grid-template-columns: 160px 1fr; row-gap: 8px; column-gap: 12px;">
                    <div style="font-weight: 600; color: #2d3748;">Company</div><div data-field="company"></div>
                    <div style="font-weight: 600; color: #2d3748;">Settlement Date</div><div data-field="settlement-date"></div>
                    <div style="font-weight: 600; color: #2d3748;">Trade Date</div><div data-field="trade-date"></div>
                    <div style="font-weight: 600; color: #2d3748;">Fund ID</div><div data-field="fund-id"></div>
                    <div style="font-weight: 600; color: #2d3748;">Dealer Account</div><div data-field="dealer-account-id"></div>
                </div>
                <div style="display: grid; grid-template-columns: 160px 1fr; row-gap: 8px; column-gap: 12px;">
                    <div style="font-weight: 600; color: #2d3748;">Order ID</div><div data-field="order-id"></div>
                    <div style="font-weight: 600; color: #2d3748;">Type</div><div data-field="tx-type"></div>
                    <div style="font-weight: 600; color: #2d3748;">Settlement Amt</div><div data-field="settlement-amt"></div>
                    <div style="font-weight: 600; color: #2d3748;">Actual Amt</div><div data-field="actual-amount"></div>
                    <div style="font-weight: 600; color: #2d3748;">Code</div><div data-field="code"></div>
                    <div style="font-weight: 600; color: #2d3748;">Src</div><div data-field="src"></div>
                    <div style="font-weight: 600; color: #2d3748;">Source Identifier</div><div data-field="source-identifier"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    (function () {
        const modal = document.getElementById('fundserv-modal');
        const closeBtn = document.getElementById('fundserv-modal-close');
        const rows = document.querySelectorAll('.fundserv-row');

        if (!modal || !closeBtn || rows.length === 0) {
            return;
        }

        const setField = (field, value) => {
            const el = modal.querySelector(`[data-field="${field}"]`);
            if (el) {
                el.textContent = value && String(value).trim() !== '' ? value : '-';
            }
        };

        rows.forEach((row) => {
            row.addEventListener('click', () => {
                Object.keys(row.dataset).forEach((key) => {
                    const field = key.replace(/[A-Z]/g, (m) => `-${m.toLowerCase()}`);
                    setField(field, row.dataset[key]);
                });
                modal.style.display = 'flex';
            });
        });

        const closeModal = () => {
            modal.style.display = 'none';
        };

        closeBtn.addEventListener('click', closeModal);
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                closeModal();
            }
        });
    })();
</script>
