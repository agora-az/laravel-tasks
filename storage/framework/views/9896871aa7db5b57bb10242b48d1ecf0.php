<!-- Summary Cards -->
<div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 20px;">
    <div class="card" style="background: linear-gradient(135deg, #345262 0%, #5a7585 100%); color: white;">
        <div style="text-align: center;">
            <div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;"><?php echo e(number_format($totalRecords)); ?></div>
            <div style="font-size: 14px; opacity: 0.9;">Total Records</div>
        </div>
    </div>
    <div class="card" style="background: linear-gradient(135deg, #38a169 0%, #2f855a 100%); color: white;">
        <div style="text-align: center;">
            <div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;"><?php echo e(number_format($transactions->total())); ?></div>
            <div style="font-size: 14px; opacity: 0.9;">Total Transactions</div>
        </div>
    </div>
    <div class="card" style="background: linear-gradient(135deg, #3182ce 0%, #2c5aa0 100%); color: white;">
        <div style="text-align: center;">
            <div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;"><?php echo e($transactions->currentPage()); ?>/<?php echo e($transactions->lastPage()); ?></div>
            <div style="font-size: 14px; opacity: 0.9;">Current Page</div>
        </div>
    </div>
</div>

<!-- Search Bar -->
<div class="card" style="margin-bottom: 20px;">
    <form action="<?php echo e(route('imports.transactions', ['type' => 'bank'])); ?>" method="GET" style="display: flex; gap: 10px;">
        <div class="form-group" style="flex: 1; margin-bottom: 0;">
                 <input type="text" name="search" placeholder="Search by description or type..." 
                   value="<?php echo e(request('search')); ?>"
                   style="padding: 10px 14px; border: 1px solid #cbd5e0; border-radius: 4px; width: 100%;">
        </div>
        <div style="display: flex; gap: 10px;">
            <button type="submit" class="btn" style="padding: 10px 30px;">Search</button>
            <?php if(request('search')): ?>
                <a href="<?php echo e(route('imports.transactions', ['type' => 'bank'])); ?>" class="btn" style="background: #718096; padding: 10px 20px; text-decoration: none;">Clear</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Transactions Table -->
<div class="card">
    <?php if($transactions->count() > 0): ?>
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f7fafc; border-bottom: 2px solid #e2e8f0; white-space: nowrap;">
                        <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Date</th>
                        <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Description</th>
                        <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Type</th>
                        <th style="padding: 12px; text-align: right; font-weight: 600; color: #2d3748;">Amount</th>
                        <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Account #</th>
                        <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Currency</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $__currentLoopData = $transactions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $transaction): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <tr class="bank-row" style="border-bottom: 1px solid #e2e8f0; cursor: pointer;"
                            data-txn-date="<?php echo e($transaction->txn_date); ?>"
                            data-description="<?php echo e($transaction->description); ?>"
                            data-type="<?php echo e($transaction->type); ?>"
                            data-amount="<?php echo e($transaction->amount); ?>"
                            data-account-number="<?php echo e($transaction->account_number); ?>"
                            data-currency="<?php echo e($transaction->currency); ?>">
                            <td style="padding: 12px; color: #2d3748; font-family: monospace;"><?php echo e($transaction->txn_date); ?></td>
                            <td style="padding: 12px; color: #4a5568; font-family: monospace;"><?php echo e($transaction->description); ?></td>
                            <td style="padding: 12px; font-family: monospace;">
                                <span style="background: <?php echo e($transaction->type === 'deposit' ? '#c6f6d5' : '#fed7d7'); ?>; 
                                             color: <?php echo e($transaction->type === 'deposit' ? '#22543d' : '#742a2a'); ?>; 
                                             padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500;">
                                    <?php echo e(ucfirst($transaction->type)); ?>

                                </span>
                            </td>
                            <td style="padding: 12px; text-align: right; color: <?php echo e($transaction->type === 'deposit' ? '#38a169' : '#e53e3e'); ?>; font-weight: 500; font-family: monospace;">
                                $<?php echo e(number_format($transaction->amount ?? 0, 2)); ?>

                            </td>
                            <td style="padding: 12px; color: #4a5568; font-family: monospace;"><?php echo e($transaction->account_number ?? '-'); ?></td>
                            <td style="padding: 12px; color: #4a5568; font-family: monospace;"><?php echo e($transaction->currency ?? '-'); ?></td>
                        </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div style="margin-top: 20px; display: flex; justify-content: center;">
            <?php echo e($transactions->onEachSide(1)->appends(['search' => request('search')])->links()); ?>

        </div>
    <?php else: ?>
        <div style="text-align: center; padding: 60px 40px; color: #718096;">
            <div style="font-size: 64px; margin-bottom: 20px;">🏦</div>
            <p style="font-size: 18px; margin-bottom: 10px; font-weight: 600; color: #2d3748;">No Bank Transactions Available</p>
            <p style="font-size: 14px; color: #718096; margin-bottom: 20px;">Upload a bank statement PDF to get started.</p>
            <a href="<?php echo e(route('imports.index')); ?>" class="btn" style="background: var(--primary-blue); padding: 10px 30px; text-decoration: none; color: white; display: inline-block;">
                Go to Import Page
            </a>
        </div>
    <?php endif; ?>
</div>

<!-- Transaction Details Modal -->
<div id="bank-modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); align-items: center; justify-content: center; z-index: 50;">
    <div style="background: #fff; width: 90%; max-width: 700px; border-radius: 10px; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.2);">
        <div style="display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; border-bottom: 1px solid #e2e8f0;">
            <h3 style="margin: 0; color: #2d3748;">Bank Transaction Details</h3>
            <button type="button" id="bank-modal-close" class="btn" style="background: #718096; padding: 6px 12px;">Close</button>
        </div>
        <div style="padding: 20px; max-height: 70vh; overflow: auto;">
            <div style="display: grid; grid-template-columns: 160px 1fr; row-gap: 8px; column-gap: 12px;">
                <div style="font-weight: 600; color: #2d3748;">Date</div><div data-field="txn-date"></div>
                <div style="font-weight: 600; color: #2d3748;">Description</div><div data-field="description"></div>
                <div style="font-weight: 600; color: #2d3748;">Type</div><div data-field="type"></div>
                <div style="font-weight: 600; color: #2d3748;">Amount</div><div data-field="amount"></div>
                <div style="font-weight: 600; color: #2d3748;">Account #</div><div data-field="account-number"></div>
                <div style="font-weight: 600; color: #2d3748;">Currency</div><div data-field="currency"></div>
            </div>
        </div>
    </div>
</div>

<script>
    (function () {
        const modal = document.getElementById('bank-modal');
        const closeBtn = document.getElementById('bank-modal-close');
        const rows = document.querySelectorAll('.bank-row');

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
<?php /**PATH /Users/staceyrattlesnake/Projects/opus_reconciliation/resources/views/imports/partials/bank-table.blade.php ENDPATH**/ ?>