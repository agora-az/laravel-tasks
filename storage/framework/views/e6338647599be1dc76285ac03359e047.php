<?php $__env->startSection('title', 'Reconciliation Reports'); ?>

<?php $__env->startSection('content'); ?>
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2>Reconciliation Reports</h2>
        <a href="<?php echo e(route('reconciliations.create')); ?>" class="btn">Create New Report</a>
    </div>

    <div class="card">
        <?php if($reconciliations->isEmpty()): ?>
            <p style="color: #718096;">No reconciliation reports found. Create your first report to get started.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Period Start</th>
                        <th>Period End</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $__currentLoopData = $reconciliations; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $reconciliation): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <tr>
                            <td><?php echo e($reconciliation->title); ?></td>
                            <td><?php echo e($reconciliation->period_start->format('Y-m-d')); ?></td>
                            <td><?php echo e($reconciliation->period_end->format('Y-m-d')); ?></td>
                            <td><?php echo e($reconciliation->created_at->format('Y-m-d')); ?></td>
                            <td>
                                <a href="<?php echo e(route('reconciliations.show', $reconciliation->id)); ?>" style="color: #4299e1;">View</a> |
                                <a href="<?php echo e(route('reconciliations.export', $reconciliation->id)); ?>" style="color: #48bb78;">Export</a>
                            </td>
                        </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /Users/staceyrattlesnake/Projects/opus_reconciliation/resources/views/reconciliations/index.blade.php ENDPATH**/ ?>