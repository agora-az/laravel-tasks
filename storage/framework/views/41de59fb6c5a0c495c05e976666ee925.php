<?php $__env->startSection('title', 'Transaction Data'); ?>

<?php $__env->startSection('content'); ?>
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 style="margin: 0;">💹 Transaction Data</h2>
        <a href="<?php echo e(route('imports.index')); ?>" class="btn" style="background: #718096; padding: 10px 20px; text-decoration: none;">
            ← Back to Import
        </a>
    </div>

    <!-- Tabs -->
    <div style="border-bottom: 2px solid #e2e8f0; margin-bottom: 20px;">
        <div style="display: flex; gap: 0;">
            <a href="<?php echo e(route('imports.transactions', ['type' => 'viefund'])); ?>" 
               style="padding: 12px 24px; text-decoration: none; color: <?php echo e($type === 'viefund' ? '#fff' : '#4a5568'); ?>; background: <?php echo e($type === 'viefund' ? 'linear-gradient(135deg, #38a169 0%, #2f855a 100%)' : 'transparent'); ?>; border-radius: 6px 6px 0 0; font-weight: <?php echo e($type === 'viefund' ? '600' : '500'); ?>; transition: all 0.2s;">
                📊 VieFund
            </a>
            <a href="<?php echo e(route('imports.transactions', ['type' => 'fundserv'])); ?>" 
               style="padding: 12px 24px; text-decoration: none; color: <?php echo e($type === 'fundserv' ? '#fff' : '#4a5568'); ?>; background: <?php echo e($type === 'fundserv' ? 'linear-gradient(135deg, #3182ce 0%, #2c5aa0 100%)' : 'transparent'); ?>; border-radius: 6px 6px 0 0; font-weight: <?php echo e($type === 'fundserv' ? '600' : '500'); ?>; transition: all 0.2s;">
                📈 Fundserv
            </a>
            <a href="<?php echo e(route('imports.transactions', ['type' => 'bank'])); ?>" 
               style="padding: 12px 24px; text-decoration: none; color: <?php echo e($type === 'bank' ? '#fff' : '#4a5568'); ?>; background: <?php echo e($type === 'bank' ? 'linear-gradient(135deg, #f6ad55 0%, #dd6b20 100%)' : 'transparent'); ?>; border-radius: 6px 6px 0 0; font-weight: <?php echo e($type === 'bank' ? '600' : '500'); ?>; transition: all 0.2s;">
                🏦 Bank Statements
            </a>
        </div>
    </div>

    <?php if($type === 'viefund'): ?>
        <?php echo $__env->make('imports.partials.viefund-table', ['transactions' => $transactions, 'totalRecords' => $totalRecords], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
    <?php elseif($type === 'fundserv'): ?>
        <?php echo $__env->make('imports.partials.fundserv-table', ['transactions' => $transactions, 'totalRecords' => $totalRecords], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
    <?php elseif($type === 'bank'): ?>
        <?php echo $__env->make('imports.partials.bank-table', ['transactions' => $transactions, 'totalRecords' => $totalRecords], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
    <?php endif; ?>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /Users/staceyrattlesnake/Projects/opus_reconciliation/resources/views/imports/transactions.blade.php ENDPATH**/ ?>