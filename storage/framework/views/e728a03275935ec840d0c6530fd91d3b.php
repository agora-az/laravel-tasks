<?php $__env->startSection('title', 'VieFund Transactions'); ?>

<?php $__env->startSection('content'); ?>
    <?php if(session('success')): ?>
        <div class="alert alert-success">
            <?php echo e(session('success')); ?>

        </div>
    <?php endif; ?>

    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 style="margin: 0;">VieFund Transactions</h2>
        <div style="display: flex; gap: 10px;">
            <a href="<?php echo e(route('imports.index')); ?>" class="btn" style="background: #718096; padding: 10px 20px; text-decoration: none;">
                ← Back to Import
            </a>
            <form action="<?php echo e(route('imports.viefund.truncate')); ?>" method="POST" style="display: inline;" 
                  onsubmit="return confirm('Are you sure you want to delete ALL VieFund transactions? This cannot be undone!');">
                <?php echo csrf_field(); ?>
                <?php echo method_field('DELETE'); ?>
                <button type="submit" class="btn" style="background: #e53e3e; padding: 10px 20px;">
                    🗑️ Delete All Records
                </button>
            </form>
        </div>
    </div>

    <!-- Summary Card -->
    <div class="card" style="margin-bottom: 20px; background: linear-gradient(135deg, #345262 0%, #5a7585 100%); color: white;">
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; text-align: center;">
            <div>
                <div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;"><?php echo e(number_format($totalRecords)); ?></div>
                <div style="font-size: 14px; opacity: 0.9;">Total Records</div>
            </div>
            <div>
                <div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;"><?php echo e(number_format($transactions->total())); ?></div>
                <div style="font-size: 14px; opacity: 0.9;">
                    <?php if(request('search')): ?>
                        Search Results
                    <?php else: ?>
                        Total Transactions
                    <?php endif; ?>
                </div>
            </div>
            <div>
                <div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;"><?php echo e($transactions->currentPage()); ?>/<?php echo e($transactions->lastPage()); ?></div>
                <div style="font-size: 14px; opacity: 0.9;">Current Page</div>
            </div>
        </div>
    </div>

    <!-- Search Bar -->
    <div class="card" style="margin-bottom: 20px;">
        <form action="<?php echo e(route('imports.viefund.transactions')); ?>" method="GET">
            <div style="display: flex; gap: 10px;">
                <input type="text" name="search" placeholder="Search by client name, rep code, account ID..." 
                       value="<?php echo e(request('search')); ?>"
                       style="flex: 1; padding: 10px; border: 1px solid #cbd5e0; border-radius: 4px;">
                <button type="submit" class="btn" style="padding: 10px 30px;">Search</button>
                <?php if(request('search')): ?>
                    <a href="<?php echo e(route('imports.viefund.transactions')); ?>" class="btn" style="background: #718096; padding: 10px 20px; text-decoration: none;">Clear</a>
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
                        <?php $__currentLoopData = $transactions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $transaction): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <tr style="border-bottom: 1px solid #e2e8f0;">
                                <td style="padding: 12px; color: #2d3748; font-family: monospace;"><?php echo e($transaction->client_name); ?></td>
                                <td style="padding: 12px; color: #4a5568; font-family: monospace;"><?php echo e($transaction->rep_code); ?></td>
                                <td style="padding: 12px; color: #4a5568; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-family: monospace;" title="<?php echo e($transaction->plan_description); ?>">
                                    <?php echo e($transaction->plan_description); ?>

                                </td>
                                <td style="padding: 12px; color: #4a5568; font-family: monospace;"><?php echo e($transaction->institution); ?></td>
                                <td style="padding: 12px; color: #4a5568; font-family: monospace;"><?php echo e($transaction->account_id); ?></td>
                                <td style="padding: 12px; text-align: right; color: #2d3748; font-weight: 500; font-family: monospace;">
                                    $<?php echo e(number_format($transaction->available_cad, 2)); ?>

                                </td>
                                <td style="padding: 12px; text-align: right; color: #2d3748; font-weight: 500; font-family: monospace;">
                                    $<?php echo e(number_format($transaction->balance_cad, 2)); ?>

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
            <div style="text-align: center; padding: 40px; color: #718096;">
                <p style="font-size: 18px; margin-bottom: 10px;">📂 No transactions found</p>
                <p><?php echo e(request('search') ? 'Try a different search term' : 'Import some data to get started'); ?></p>
            </div>
        <?php endif; ?>
    </div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /Users/staceyrattlesnake/Projects/opus_reconciliation/resources/views/imports/viefund-transactions.blade.php ENDPATH**/ ?>