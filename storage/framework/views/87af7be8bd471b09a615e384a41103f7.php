<?php $__env->startSection('title', 'Import History'); ?>

<?php $__env->startSection('content'); ?>
    <?php if(session('success')): ?>
        <div class="alert alert-success">
            <?php echo e(session('success')); ?>

        </div>
    <?php endif; ?>

    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 style="margin: 0;">📋 Import History</h2>
        <a href="<?php echo e(route('imports.index')); ?>" class="btn" style="background: #718096; padding: 10px 20px; text-decoration: none;">
            ← Back to Import
        </a>
    </div>

    <!-- Summary Cards -->
    <div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 20px; margin-bottom: 20px;">
        <div class="card" style="background: linear-gradient(135deg, #345262 0%, #5a7585 100%); color: white;">
            <div style="text-align: center;">
                <div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;"><?php echo e($imports->total()); ?></div>
                <div style="font-size: 14px; opacity: 0.9;">Total Import Attempts</div>
            </div>
        </div>
        <div class="card" style="background: linear-gradient(135deg, #38a169 0%, #2f855a 100%); color: white;">
            <div style="text-align: center;">
                <div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;">
                    <?php echo e(\App\Models\Import::where('type', 'viefund')->where('status', 'completed')->count()); ?>

                </div>
                <div style="font-size: 14px; opacity: 0.9;">Successful VieFund Imports</div>
            </div>
        </div>
        <div class="card" style="background: linear-gradient(135deg, #3182ce 0%, #2c5aa0 100%); color: white;">
            <div style="text-align: center;">
                <div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;">
                    <?php echo e(\App\Models\Import::where('type', 'fundserv')->where('status', 'completed')->count()); ?>

                </div>
                <div style="font-size: 14px; opacity: 0.9;">Successful Fundserv Imports</div>
            </div>
        </div>
        <div class="card" style="background: linear-gradient(135deg, #805ad5 0%, #6b46c1 100%); color: white;">
            <div style="text-align: center;">
                <div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;">
                    <?php echo e(\App\Models\Import::where('type', 'bank')->where('status', 'completed')->count()); ?>

                </div>
                <div style="font-size: 14px; opacity: 0.9;">Successful Bank Imports</div>
            </div>
        </div>
        <div class="card" style="background: linear-gradient(135deg, #e53e3e 0%, #c53030 100%); color: white;">
            <div style="text-align: center;">
                <div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;">
                    <?php echo e(\App\Models\Import::where('status', 'failed')->count()); ?>

                </div>
                <div style="font-size: 14px; opacity: 0.9;">Failed Imports</div>
            </div>
        </div>
    </div>

    <!-- Import History Table -->
    <div class="card">
        <?php if($imports->count() > 0): ?>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #f7fafc; border-bottom: 2px solid #e2e8f0;">
                            <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Date & Time</th>
                            <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Type</th>
                            <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Filename</th>
                            <th style="padding: 12px; text-align: center; font-weight: 600; color: #2d3748;">Size</th>
                            <th style="padding: 12px; text-align: center; font-weight: 600; color: #2d3748;">Imported</th>
                            <th style="padding: 12px; text-align: center; font-weight: 600; color: #2d3748;">Duplicates</th>
                            <th style="padding: 12px; text-align: center; font-weight: 600; color: #2d3748;">Errors</th>
                            <th style="padding: 12px; text-align: center; font-weight: 600; color: #2d3748;">Duration</th>
                            <th style="padding: 12px; text-align: center; font-weight: 600; color: #2d3748;">Status</th>
                            <th style="padding: 12px; text-align: center; font-weight: 600; color: #2d3748;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $__currentLoopData = $imports; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $import): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <tr style="border-bottom: 1px solid #e2e8f0;">
                                <td style="padding: 12px; color: #4a5568; font-family: monospace;">
                                    <?php echo e($import->created_at->format('Y-m-d H:i:s')); ?>

                                </td>
                                <td style="padding: 12px;">
                                    <span style="background: <?php echo e($import->type == 'viefund' ? '#e6fffa' : ($import->type == 'fundserv' ? '#ebf8ff' : '#faf5ff')); ?>; 
                                                 color: <?php echo e($import->type == 'viefund' ? '#234e52' : ($import->type == 'fundserv' ? '#2c5282' : '#553c9a')); ?>; 
                                                 padding: 4px 12px; border-radius: 4px; font-size: 12px; font-weight: 600; text-transform: uppercase;">
                                        <?php echo e($import->type); ?>

                                    </span>
                                </td>
                                <td style="padding: 12px; color: #2d3748; font-family: monospace; font-size: 13px;">
                                    <?php echo e($import->filename); ?>

                                </td>
                                <td style="padding: 12px; text-align: center; color: #718096; font-family: monospace;">
                                    <?php echo e($import->file_size_mb); ?> MB
                                </td>
                                <td style="padding: 12px; text-align: center; color: #2d3748; font-weight: 500; font-family: monospace;">
                                    <?php echo e(number_format($import->imported_count)); ?>

                                </td>
                                <td style="padding: 12px; text-align: center; color: #718096; font-family: monospace;">
                                    <?php echo e(number_format($import->duplicate_count)); ?>

                                </td>
                                <td style="padding: 12px; text-align: center; font-family: monospace;">
                                    <?php if($import->error_count > 0): ?>
                                        <span style="color: #e53e3e; font-weight: 600;" title="<?php echo e($import->error_details); ?>">
                                            <?php echo e($import->error_count); ?>

                                        </span>
                                    <?php else: ?>
                                        <span style="color: #38a169;">0</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px; text-align: center; color: #718096; font-family: monospace;">
                                    <?php if($import->duration): ?>
                                        <?php echo e($import->duration); ?>s
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px; text-align: center; font-family: monospace;">
                                    <?php if($import->status == 'completed'): ?>
                                        <span style="color: #38a169;">✓ Completed</span>
                                    <?php elseif($import->status == 'failed'): ?>
                                        <span style="color: #e53e3e;">✗ Failed</span>
                                    <?php else: ?>
                                        <span style="color: #f6ad55;">⟳ Processing</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px; text-align: center;">
                                    <div style="display: flex; gap: 8px; justify-content: center;">
                                        <a href="<?php echo e(route('imports.view', $import->id)); ?>" 
                                           style="background: #38a169; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; text-decoration: none; display: inline-block;">
                                            View
                                        </a>
                                        <a href="<?php echo e(route('imports.index')); ?>" 
                                           style="background: #4299e1; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; text-decoration: none; display: inline-block;">
                                            Reimport
                                        </a>
                                        <?php if($import->error_details): ?>
                                            <button onclick="showErrors(<?php echo e($import->id); ?>)" 
                                                    style="background: #f6ad55; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px;">
                                                Errors
                                            </button>
                                        <?php endif; ?>
                                        <form action="<?php echo e(route('imports.delete', $import->id)); ?>" method="POST" style="display: inline-block; margin: 0;"
                                              onsubmit="return confirm('Delete this import and all its transactions?');">
                                            <?php echo csrf_field(); ?>
                                            <?php echo method_field('DELETE'); ?>
                                            <button type="submit" style="background: #e53e3e; color: white; border: none; padding: 8px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; text-decoration: none; display: inline-block; vertical-align: middle; line-height: 1.2;">
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                    <?php if($import->error_details): ?>
                                        <div id="errors-<?php echo e($import->id); ?>" style="display: none; margin-top: 10px; padding: 10px; background: #fff5f5; border-radius: 4px; text-align: left; font-size: 12px; color: #742a2a;">
                                            <pre style="white-space: pre-wrap; margin: 0;"><?php echo e($import->error_details); ?></pre>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div style="margin-top: 20px; display: flex; justify-content: center;">
                <?php echo e($imports->onEachSide(1)->links()); ?>

            </div>
        <?php else: ?>
            <div style="text-align: center; padding: 40px; color: #718096;">
                <p style="font-size: 18px; margin-bottom: 10px;">📂 No import history found</p>
                <p>Start importing data to see history here</p>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function showErrors(importId) {
            const errorDiv = document.getElementById('errors-' + importId);
            errorDiv.style.display = errorDiv.style.display === 'none' ? 'block' : 'none';
        }
    </script>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /Users/staceyrattlesnake/Projects/opus_reconciliation/resources/views/imports/history.blade.php ENDPATH**/ ?>