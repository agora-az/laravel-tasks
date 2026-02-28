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
    <form action="{{ route('imports.transactions', ['type' => 'viefund']) }}" method="GET">
        <div style="display: flex; gap: 10px;">
            <input type="text" name="search" placeholder="Search by client name, rep code, account ID..." 
                   value="{{ request('search') }}"
                   style="flex: 1; padding: 10px; border: 1px solid #cbd5e0; border-radius: 4px;">
            <button type="submit" class="btn" style="padding: 10px 30px;">Search</button>
            @if(request('search'))
                <a href="{{ route('imports.transactions', ['type' => 'viefund']) }}" class="btn" style="background: #718096; padding: 10px 20px; text-decoration: none;">Clear</a>
            @endif
            <form action="{{ route('imports.viefund.truncate') }}" method="POST" style="display: inline;" 
                  onsubmit="return confirm('Are you sure you want to delete ALL VieFund transactions? This cannot be undone!');">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn" style="background: #e53e3e; padding: 10px 20px;">
                    🗑️ Delete All
                </button>
            </form>
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
                        <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Rep Code</th>
                        <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Account ID</th>
                        <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Txn Type</th>
                        <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Trade Date</th>
                        <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Settlement Date</th>
                        <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Processing Date</th>
                        <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Status</th>
                        <th style="padding: 12px; text-align: right; font-weight: 600; color: #2d3748;">Available CAD</th>
                        <th style="padding: 12px; text-align: right; font-weight: 600; color: #2d3748;">Balance CAD</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($transactions as $transaction)
                        <tr class="viefund-row" style="border-bottom: 1px solid #e2e8f0; cursor: pointer;"
                            data-client-name="{{ $transaction->client_name }}"
                            data-rep-code="{{ $transaction->rep_code }}"
                            data-account-id="{{ $transaction->account_id }}"
                            data-trx-id="{{ $transaction->trx_id }}"
                            data-created-date="{{ $transaction->created_date }}"
                            data-trx-type="{{ $transaction->trx_type }}"
                            data-trade-date="{{ $transaction->trade_date }}"
                            data-settlement-date="{{ $transaction->settlement_date }}"
                            data-processing-date="{{ $transaction->processing_date }}"
                            data-source-id="{{ $transaction->source_id }}"
                            data-status="{{ $transaction->status }}"
                            data-amount="{{ $transaction->amount }}"
                            data-balance="{{ $transaction->balance }}"
                            data-fund-code="{{ $transaction->fund_code }}"
                            data-fund-trx-type="{{ $transaction->fund_trx_type }}"
                            data-fund-trx-amount="{{ $transaction->fund_trx_amount }}"
                            data-fund-settlement-source="{{ $transaction->fund_settlement_source }}"
                            data-fund-wo-number="{{ $transaction->fund_wo_number }}"
                            data-fund-source-id="{{ $transaction->fund_source_id }}"
                            data-available-cad="{{ $transaction->available_cad }}"
                            data-balance-cad="{{ $transaction->balance_cad }}"
                            data-currency="{{ $transaction->currency }}"
                            data-plan-description="{{ $transaction->plan_description }}"
                            data-institution="{{ $transaction->institution }}">
                            <td style="padding: 12px; color: #2d3748; font-family: monospace;">{{ $transaction->client_name }}</td>
                            <td style="padding: 12px; color: #4a5568; font-family: monospace;">{{ $transaction->rep_code }}</td>
                            <td style="padding: 12px; color: #4a5568; font-family: monospace;">{{ $transaction->account_id }}</td>
                            <td style="padding: 12px; color: #4a5568; font-family: monospace;">{{ $transaction->trx_type ?? '-' }}</td>
                            <td style="padding: 12px; color: #4a5568; font-family: monospace;">{{ $transaction->trade_date ?? '-' }}</td>
                            <td style="padding: 12px; color: #4a5568; font-family: monospace;">{{ $transaction->settlement_date ?? '-' }}</td>
                            <td style="padding: 12px; color: #4a5568; font-family: monospace;">{{ $transaction->processing_date ?? '-' }}</td>
                            <td style="padding: 12px; color: #4a5568; font-family: monospace;">{{ $transaction->status ?? '-' }}</td>
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
<div id="viefund-modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); align-items: center; justify-content: center; z-index: 50;">
    <div style="background: #fff; width: 90%; max-width: 900px; border-radius: 10px; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.2);">
        <div style="display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; border-bottom: 1px solid #e2e8f0;">
            <h3 style="margin: 0; color: #2d3748;">VieFund Transaction Details</h3>
            <button type="button" id="viefund-modal-close" class="btn" style="background: #718096; padding: 6px 12px;">Close</button>
        </div>
        <div style="padding: 20px; max-height: 70vh; overflow: auto;">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div style="display: grid; grid-template-columns: 160px 1fr; row-gap: 8px; column-gap: 12px;">
                    <div style="font-weight: 600; color: #2d3748;">Client Name</div><div data-field="client-name"></div>
                    <div style="font-weight: 600; color: #2d3748;">Plan Description</div><div data-field="plan-description"></div>
                    <div style="font-weight: 600; color: #2d3748;">Account ID</div><div data-field="account-id"></div>
                    <div style="font-weight: 600; color: #2d3748;">Created Date</div><div data-field="created-date"></div>
                    <div style="font-weight: 600; color: #2d3748;">Trade Date</div><div data-field="trade-date"></div>
                    <div style="font-weight: 600; color: #2d3748;">Processing Date</div><div data-field="processing-date"></div>
                    <div style="font-weight: 600; color: #2d3748;">Status</div><div data-field="status"></div>
                    <div style="font-weight: 600; color: #2d3748;">Balance</div><div data-field="balance"></div>
                    <div style="font-weight: 600; color: #2d3748;">Fund Trx Type</div><div data-field="fund-trx-type"></div>
                    <div style="font-weight: 600; color: #2d3748;">Fund Settlement Source</div><div data-field="fund-settlement-source"></div>
                    <div style="font-weight: 600; color: #2d3748;">Fund SourceID</div><div data-field="fund-source-id"></div>
                    <div style="font-weight: 600; color: #2d3748;">Balance CAD</div><div data-field="balance-cad"></div>
                </div>
                <div style="display: grid; grid-template-columns: 160px 1fr; row-gap: 8px; column-gap: 12px;">
                    <div style="font-weight: 600; color: #2d3748;">Rep Code</div><div data-field="rep-code"></div>
                    <div style="font-weight: 600; color: #2d3748;">Institution</div><div data-field="institution"></div>
                    <div style="font-weight: 600; color: #2d3748;">Trx ID</div><div data-field="trx-id"></div>
                    <div style="font-weight: 600; color: #2d3748;">Txn Type</div><div data-field="trx-type"></div>
                    <div style="font-weight: 600; color: #2d3748;">Settlement Date</div><div data-field="settlement-date"></div>
                    <div style="font-weight: 600; color: #2d3748;">Source ID</div><div data-field="source-id"></div>
                    <div style="font-weight: 600; color: #2d3748;">Amount</div><div data-field="amount"></div>
                    <div style="font-weight: 600; color: #2d3748;">Fund Code</div><div data-field="fund-code"></div>
                    <div style="font-weight: 600; color: #2d3748;">Fund Trx Amount</div><div data-field="fund-trx-amount"></div>
                    <div style="font-weight: 600; color: #2d3748;">Fund WO#</div><div data-field="fund-wo-number"></div>
                    <div style="font-weight: 600; color: #2d3748;">Available CAD</div><div data-field="available-cad"></div>
                    <div style="font-weight: 600; color: #2d3748;">Currency</div><div data-field="currency"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    (function () {
        const modal = document.getElementById('viefund-modal');
        const closeBtn = document.getElementById('viefund-modal-close');
        const rows = document.querySelectorAll('.viefund-row');

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
