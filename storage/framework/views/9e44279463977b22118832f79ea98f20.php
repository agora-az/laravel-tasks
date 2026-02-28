<?php $__env->startSection('title', 'Bank Transactions'); ?>

<?php $__env->startSection('content'); ?>
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 style="margin: 0;">🏦 Bank Statement Transactions</h2>
        <a href="<?php echo e(route('imports.index')); ?>" class="btn" style="background: #718096; padding: 10px 20px; text-decoration: none;">
            ← Back to Import
        </a>
    </div>

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
                <div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;"><?php echo e(number_format($transactions->count())); ?></div>
                <div style="font-size: 14px; opacity: 0.9;">Results on this page</div>
            </div>
        </div>
        <div class="card" style="background: linear-gradient(135deg, #3182ce 0%, #2c5aa0 100%); color: white;">
            <div style="text-align: center;">
                <div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;">-</div>
                <div style="font-size: 14px; opacity: 0.9;">Page 0 of 0</div>
            </div>
        </div>
    </div>

    <!-- Search Bar -->
    <div class="card" style="margin-bottom: 20px;">
        <form action="<?php echo e(route('imports.bank.transactions')); ?>" method="GET" style="display: flex; gap: 10px;">
            <div class="form-group" style="flex: 1; margin-bottom: 0;">
                <input type="text" name="search" placeholder="Search bank transactions..." 
                       value="<?php echo e(request('search')); ?>"
                       style="padding: 10px 14px; border: 1px solid #cbd5e0; border-radius: 4px; width: 100%;">
            </div>
            <div style="display: flex; gap: 10px;">
                <button type="submit" class="btn" style="padding: 10px 30px;">Search</button>
                <?php if(request('search')): ?>
                    <a href="<?php echo e(route('imports.bank.transactions')); ?>" class="btn" style="background: #718096; padding: 10px 20px; text-decoration: none;">Clear</a>
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
                        <tr style="background: #f7fafc; border-bottom: 2px solid #e2e8f0;">
                            <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Date</th>
                            <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Description</th>
                            <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Reference</th>
                            <th style="padding: 12px; text-align: right; font-weight: 600; color: #2d3748;">Debit</th>
                            <th style="padding: 12px; text-align: right; font-weight: 600; color: #2d3748;">Credit</th>
                            <th style="padding: 12px; text-align: right; font-weight: 600; color: #2d3748;">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $__currentLoopData = $transactions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $transaction): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <tr style="border-bottom: 1px solid #e2e8f0;">
                                <td style="padding: 12px; color: #2d3748; font-family: monospace;"><?php echo e($transaction->date); ?></td>
                                <td style="padding: 12px; color: #4a5568; font-family: monospace;"><?php echo e($transaction->description); ?></td>
                                <td style="padding: 12px; color: #4a5568; font-family: monospace;"><?php echo e($transaction->reference); ?></td>
                                <td style="padding: 12px; text-align: right; color: #e53e3e; font-weight: 500; font-family: monospace;">
                                    $<?php echo e(number_format($transaction->debit, 2)); ?>

                                </td>
                                <td style="padding: 12px; text-align: right; color: #38a169; font-weight: 500; font-family: monospace;">
                                    $<?php echo e(number_format($transaction->credit, 2)); ?>

                                </td>
                                <td style="padding: 12px; text-align: right; color: #2d3748; font-weight: 500; font-family: monospace;">
                                    $<?php echo e(number_format($transaction->balance, 2)); ?>

                                </td>
                            </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div style="margin-top: 20px; display: flex; justify-content: center;">
                <?php echo e($transactions->onEachSide(1)->links()); ?>

            </div>
        <?php else: ?>
            <div style="text-align: center; padding: 60px 40px; color: #718096;">
                <div style="font-size: 64px; margin-bottom: 20px;">🏦</div>
                <p style="font-size: 18px; margin-bottom: 10px; font-weight: 600; color: #2d3748;">No Bank Transactions Available</p>
                <p style="font-size: 14px; color: #718096; margin-bottom: 20px;">
                    Bank statement import functionality is currently under development.
                </p>
                <a href="<?php echo e(route('imports.index')); ?>" class="btn" style="background: var(--primary-blue); padding: 10px 30px; text-decoration: none; color: white; display: inline-block;">
                    Go to Import Page
                </a>
            </div>
        <?php endif; ?>
    </div>

    <?php if($totalRecords > 0): ?>
        <div class="card" style="margin-top: 20px; background: #fff5f5; border-left: 4px solid #e53e3e;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h3 style="margin-bottom: 5px; color: #742a2a;">⚠️ Delete All Bank Records</h3>
                    <p style="color: #742a2a; font-size: 14px; margin: 0;">
                        This will permanently delete all <?php echo e(number_format($totalRecords)); ?> bank transaction records from the database.
                    </p>
                </div>
                <form action="<?php echo e(route('imports.bank.truncate')); ?>" method="POST" style="margin: 0;"
                      onsubmit="return confirm('Are you sure you want to delete ALL bank transactions? This action cannot be undone!');">
                    <?php echo csrf_field(); ?>
                    <?php echo method_field('DELETE'); ?>
                    <button type="submit" class="btn" style="background: #e53e3e; padding: 10px 30px;">
                        🗑️ Delete All Records
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /Users/staceyrattlesnake/Projects/opus_reconciliation/resources/views/imports/bank-transactions.blade.php ENDPATH**/ ?>