<?php $__env->startSection('title', 'View Import - ' . $import->filename); ?>

<?php $__env->startSection('content'); ?>
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 style="margin: 0;">Import Details</h2>
        <a href="<?php echo e(route('imports.history')); ?>" class="btn" style="background: #718096; padding: 10px 20px; text-decoration: none;">
            ← Back to History
        </a>
    </div>

    <!-- Import Summary Card -->
    <div class="card" style="margin-bottom: 20px; background: linear-gradient(135deg, #345262 0%, #5a7585 100%); color: white;">
        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 20px;">
            <div>
                <div style="font-size: 14px; opacity: 0.9; margin-bottom: 5px;">Filename</div>
                <div style="font-size: 18px; font-weight: bold;"><?php echo e($import->filename); ?></div>
            </div>
            <div>
                <div style="font-size: 14px; opacity: 0.9; margin-bottom: 5px;">Import Date</div>
                <div style="font-size: 18px; font-weight: bold;"><?php echo e($import->created_at->format('Y-m-d H:i:s')); ?></div>
            </div>
        </div>
        <div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 20px; text-align: center; padding-top: 20px; border-top: 1px solid rgba(255,255,255,0.2);">
            <div>
                <div style="font-size: 24px; font-weight: bold; margin-bottom: 5px;"><?php echo e(number_format($import->imported_count)); ?></div>
                <div style="font-size: 12px; opacity: 0.9;">Imported</div>
            </div>
            <div>
                <div style="font-size: 24px; font-weight: bold; margin-bottom: 5px;"><?php echo e(number_format($import->duplicate_count)); ?></div>
                <div style="font-size: 12px; opacity: 0.9;">Duplicates</div>
            </div>
            <div>
                <div style="font-size: 24px; font-weight: bold; margin-bottom: 5px; color: <?php echo e($import->error_count > 0 ? '#fed7d7' : 'white'); ?>"><?php echo e(number_format($import->error_count)); ?></div>
                <div style="font-size: 12px; opacity: 0.9;">Errors</div>
            </div>
            <div>
                <div style="font-size: 24px; font-weight: bold; margin-bottom: 5px;"><?php echo e($import->file_size_mb); ?> MB</div>
                <div style="font-size: 12px; opacity: 0.9;">File Size</div>
            </div>
            <div>
                <div style="font-size: 24px; font-weight: bold; margin-bottom: 5px;"><?php echo e($import->duration ?? '-'); ?>s</div>
                <div style="font-size: 12px; opacity: 0.9;">Duration</div>
            </div>
        </div>
    </div>

    <!-- Transactions Table -->
    <div class="card">
        <h3 style="margin-bottom: 15px; color: #2d3748;">
            <?php echo e(ucfirst($import->type)); ?> Transactions (<?php echo e(number_format($transactions->total())); ?>)
        </h3>
        
        <?php if($transactions->count() > 0): ?>
            <div style="overflow-x: auto;">
                <?php if($import->type == 'viefund'): ?>
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
                <?php else: ?>
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
                            </tr>
                        </thead>
                        <tbody>
                            <?php $__currentLoopData = $transactions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $transaction): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <tr style="border-bottom: 1px solid #e2e8f0;">
                                    <td style="padding: 12px; color: #2d3748; font-weight: 500;"><?php echo e($transaction->company); ?></td>
                                    <td style="padding: 12px; color: #4a5568;"><?php echo e($transaction->settlement_date); ?></td>
                                    <td style="padding: 12px; color: #4a5568;"><?php echo e($transaction->trade_date); ?></td>
                                    <td style="padding: 12px; color: #4a5568; font-family: monospace;"><?php echo e($transaction->fund_id); ?></td>
                                    <td style="padding: 12px; color: #4a5568; font-family: monospace;"><?php echo e($transaction->dealer_account_id); ?></td>
                                    <td style="padding: 12px; color: #4a5568; font-family: monospace;"><?php echo e($transaction->order_id); ?></td>
                                    <td style="padding: 12px;">
                                        <span style="background: <?php echo e($transaction->tx_type == 'Buy' ? '#c6f6d5' : '#fed7d7'); ?>; 
                                                     color: <?php echo e($transaction->tx_type == 'Buy' ? '#22543d' : '#742a2a'); ?>; 
                                                     padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500;">
                                            <?php echo e($transaction->tx_type); ?>

                                        </span>
                                    </td>
                                    <td style="padding: 12px; text-align: right; color: #2d3748; font-weight: 500;">
                                        $<?php echo e(number_format($transaction->settlement_amt, 2)); ?>

                                    </td>
                                </tr>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Pagination -->
            <div style="margin-top: 20px; display: flex; justify-content: center;">
                <?php echo e($transactions->onEachSide(1)->links()); ?>

            </div>
        <?php else: ?>
            <div style="text-align: center; padding: 40px; color: #718096;">
                <p style="font-size: 18px; margin-bottom: 10px;">📂 No transactions found for this import</p>
            </div>
        <?php endif; ?>
    </div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /Users/staceyrattlesnake/Projects/opus_reconciliation/resources/views/imports/view-import.blade.php ENDPATH**/ ?>