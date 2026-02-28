<?php $__env->startSection('title', 'Reconciliation Matches'); ?>

<?php $__env->startSection('content'); ?>
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 style="margin: 0;">Reconciliation Matches</h2>
        <div style="display: flex; gap: 10px; align-items: center;">
            <form method="POST" action="<?php echo e(route('reconciliations.matches.find')); ?>" class="match-action-form" data-action="find">
                <?php echo csrf_field(); ?>
                <button type="submit" class="btn" style="background: #2f855a; padding: 10px 20px;">Find Matches</button>
            </form>
            <form method="POST" action="<?php echo e(route('reconciliations.matches.delete')); ?>" class="match-action-form" data-action="delete" onsubmit="return confirm('Delete all reconciliation matches?');">
                <?php echo csrf_field(); ?>
                <?php echo method_field('DELETE'); ?>
                <button type="submit" class="btn" style="background: #c53030; padding: 10px 20px;">Delete Matches</button>
            </form>
        </div>
    </div>

    <?php if(session('success')): ?>
        <div class="card" style="background: #f0fff4; color: #22543d; margin-bottom: 20px; padding: 12px 16px; border: 1px solid #c6f6d5;">
            <?php echo e(session('success')); ?>

        </div>
    <?php endif; ?>

    <!-- Summary Cards -->
    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 20px;">
        <div class="card" style="background: linear-gradient(135deg, #345262 0%, #5a7585 100%); color: white; text-align: center;">
            <div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;"><?php echo e(number_format($totalMatches)); ?></div>
            <div style="font-size: 14px; opacity: 0.9;">Total Matches</div>
        </div>
        <div class="card" style="background: linear-gradient(135deg, #3182ce 0%, #2c5aa0 100%); color: white; text-align: center;">
            <div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;">
                <?php echo e($fundservTotal > 0 ? number_format(($fundservMatched / $fundservTotal) * 100, 1) : '0.0'); ?>%
            </div>
            <div style="font-size: 14px; opacity: 0.9;">Fundserv Matched (<?php echo e(number_format($fundservMatched)); ?>/<?php echo e(number_format($fundservTotal)); ?>)</div>
        </div>
        <div class="card" style="background: linear-gradient(135deg, #38a169 0%, #2f855a 100%); color: white; text-align: center;">
            <div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;">
                <?php echo e($viefundTotal > 0 ? number_format(($viefundMatched / $viefundTotal) * 100, 1) : '0.0'); ?>%
            </div>
            <div style="font-size: 14px; opacity: 0.9;">VieFund Matched (<?php echo e(number_format($viefundMatched)); ?>/<?php echo e(number_format($viefundTotal)); ?>)</div>
        </div>
        <div class="card" style="background: linear-gradient(135deg, #805ad5 0%, #6b46c1 100%); color: white; text-align: center;">
            <div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;">
                <?php echo e($bankTotal > 0 ? number_format(($bankMatched / $bankTotal) * 100, 1) : '0.0'); ?>%
            </div>
            <div style="font-size: 14px; opacity: 0.9;">Bank Matched (<?php echo e(number_format($bankMatched)); ?>/<?php echo e(number_format($bankTotal)); ?>)</div>
        </div>
    </div>

    <div class="card">
        <?php if($matchesPaginator->count() > 0): ?>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                    <?php
                        $nextDirection = $direction === 'asc' ? 'desc' : 'asc';
                        $sortParams = function ($column) use ($sort, $direction, $nextDirection) {
                            $dir = $sort === $column ? $nextDirection : 'asc';
                            return array_merge(request()->query(), ['sort' => $column, 'direction' => $dir]);
                        };
                        $sortIndicator = function ($column) use ($sort, $direction) {
                            if ($sort !== $column) {
                                return ' ↕';
                            }
                            return $direction === 'asc' ? ' ▲' : ' ▼';
                        };
                    ?>
                    <thead>
                        <tr style="background: #f7fafc; border-bottom: 2px solid #e2e8f0; white-space: nowrap;">
                            <th style="padding: 12px; text-align: center; font-weight: 600; color: #2d3748; width: 110px;">Rule</th>
                            <th style="padding: 12px; text-align: right; font-weight: 600; color: #2d3748;">
                                <a href="<?php echo e(route('reconciliations.matches', $sortParams('viefund'))); ?>" style="color: inherit; text-decoration: none;">VieFund<?php echo e($sortIndicator('viefund')); ?></a>
                            </th>
                            <th style="padding: 12px; text-align: right; font-weight: 600; color: #2d3748;">
                                <a href="<?php echo e(route('reconciliations.matches', $sortParams('fundserv'))); ?>" style="color: inherit; text-decoration: none;">Fundserv<?php echo e($sortIndicator('fundserv')); ?></a>
                            </th>
                            <th style="padding: 12px; text-align: right; font-weight: 600; color: #2d3748;">
                                <a href="<?php echo e(route('reconciliations.matches', $sortParams('bank'))); ?>" style="color: inherit; text-decoration: none;">Bank<?php echo e($sortIndicator('bank')); ?></a>
                            </th>
                            <th style="padding: 12px; text-align: right; font-weight: 600; color: #2d3748;">
                                <a href="<?php echo e(route('reconciliations.matches', $sortParams('difference'))); ?>" style="color: inherit; text-decoration: none;">Diff<?php echo e($sortIndicator('difference')); ?></a>
                            </th>
                            <th style="padding: 12px; text-align: center; font-weight: 600; color: #2d3748;">
                                <a href="<?php echo e(route('reconciliations.matches', $sortParams('confidence'))); ?>" style="color: inherit; text-decoration: none;">Confidence<?php echo e($sortIndicator('confidence')); ?></a>
                            </th>
                            <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Details</th>
                            <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Matched At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $__currentLoopData = $pagedGroups; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $group): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <?php
                                $groupId = 'group-' . substr(md5($group['key']), 0, 8);
                                $first = $group['first'];
                                $hasDiff = $group['diff'] !== null && abs((float) $group['diff']) > 0.005;
                                $columnBackground = $hasDiff ? '#fef3c7' : 'transparent';
                            ?>

                            <tr class="group-toggle" data-group="<?php echo e($groupId); ?>" data-expandable="<?php echo e($group['is_multi'] ? 'true' : 'false'); ?>" style="border-bottom: 1px solid #e2e8f0; cursor: <?php echo e($group['is_multi'] ? 'pointer' : 'default'); ?>;">
                                <td style="padding: 12px; color: #2d3748; font-size: 12px; max-width: 110px; white-space: normal; text-align: center;">
                                    <?php
                                        $ruleLabel = match($first->match_rule) {
                                            'fundserv_order_id_to_viefund_fund_wo_number' => 'Fundserv<br>to<br>VieFund',
                                            'bank_to_fundserv_amount_date' => !empty($first->metadata_array['viefund_id'] ?? null)
                                                ? 'Bank<br>to<br>Fundserv<br>&amp;<br>VieFund'
                                                : 'Bank<br>to<br>Fundserv',
                                            'bank_to_viefund_amount_date' => 'Bank<br>to<br>VieFund',
                                            default => str_replace('_', ' ', $first->match_rule),
                                        };
                                    ?>
                                    <?php echo $ruleLabel; ?>

                                    <?php if($group['is_multi']): ?>
                                        <span style="margin-left: 6px; background: #805ad5; color: #fff; padding: 2px 6px; border-radius: 10px; font-size: 10px;">Multi</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px; text-align: right; color: #2d3748; font-family: monospace;">
                                    <?php echo e($group['viefund_total'] !== null ? number_format($group['viefund_total'], 2) : '-'); ?>

                                </td>
                                <td style="padding: 12px; text-align: right; color: #2d3748; font-family: monospace;">
                                    <?php echo e($group['fundserv_amount'] !== null ? number_format($group['fundserv_amount'], 2) : '-'); ?>

                                </td>
                                <td style="padding: 12px; text-align: right; color: #2d3748; font-family: monospace;">
                                    <?php echo e($group['bank_amount'] !== null ? number_format($group['bank_amount'], 2) : '-'); ?>

                                </td>
                                <td style="padding: 12px; text-align: right; color: #2d3748; font-family: monospace; background: <?php echo e($columnBackground); ?>;">
                                    <?php echo e($group['diff'] !== null ? number_format($group['diff'], 2) : '-'); ?>

                                </td>
                                <td style="padding: 12px; text-align: center;">
                                    <?php
                                        $confidence = (float) ($first->confidence ?? 0);
                                        $pctText = number_format($confidence * 100, 1) . '%';
                                        $barWidth = $confidence * 100;
                                    ?>
                                    <div style="display: flex; flex-direction: column; align-items: center; gap: 4px;">
                                        <div style="font-family: monospace; font-size: 12px; font-weight: 600; color: #2d3748;">
                                            <?php echo e($pctText); ?>

                                        </div>
                                        <div style="width: 96px; height: 16px; background: #f7fafc; border: 2px solid #cbd5e0; border-radius: 4px; overflow: hidden; position: relative;">
                                            <div style="height: 100%; width: <?php echo e($barWidth); ?>%; background: #48bb78; transition: width 0.3s ease;"></div>
                                        </div>
                                    </div>
                                </td>
                                <td style="padding: 12px; color: #4a5568; font-size: 12px;">
                                    <?php if($first->match_rule === 'fundserv_order_id_to_viefund_fund_wo_number'): ?>
                                        <div><strong>Order ID:</strong> <?php echo e($first->fundserv_order_id ?? '-'); ?></div>
                                        <div><strong>Fund WO#:</strong> <?php echo e($first->viefund_fund_wo_number ?? '-'); ?></div>
                                        <div><strong>Transactions:</strong> <?php echo e($group['count']); ?></div>
                                    <?php elseif($first->match_rule === 'bank_to_fundserv_amount_date'): ?>
                                        <?php
                                            $orderId = $first->fundserv_order_id ?? $first->viefund_fund_wo_number ?? null;
                                        ?>
                                        <?php if(!empty($orderId)): ?>
                                            <div><strong>Fundserv - Order ID:</strong> <?php echo e($orderId); ?></div>
                                        <?php endif; ?>
                                        <div><strong>Bank Date:</strong> <?php echo e($first->bank_txn_date ?? '-'); ?></div>
                                        <div><strong>Fundserv - Settlement Date:</strong> <?php echo e($first->fundserv_right_date ?? '-'); ?></div>
                                        <?php if(!empty($first->viefund_settlement_date)): ?>
                                            <div><strong>VieFund - Settlement Date:</strong> <?php echo e($first->viefund_settlement_date); ?></div>
                                        <?php endif; ?>
                                        <?php if($group['count'] > 1): ?>
                                            <div><strong>Transactions:</strong> <?php echo e($group['count']); ?></div>
                                        <?php endif; ?>
                                        <div><strong>Description:</strong> <?php echo e($first->bank_description ?? '-'); ?></div>
                                    <?php elseif($first->match_rule === 'bank_to_viefund_amount_date'): ?>
                                        <div><strong>Bank Date:</strong> <?php echo e($first->bank_txn_date ?? '-'); ?></div>
                                        <?php if(!empty($first->viefund_settlement_date)): ?>
                                            <div><strong>VieFund - Settlement Date:</strong> <?php echo e($first->viefund_settlement_date); ?></div>
                                        <?php endif; ?>
                                        <?php if(!empty($first->viefund_trade_date)): ?>
                                            <div><strong>VieFund - Trade Date:</strong> <?php echo e($first->viefund_trade_date); ?></div>
                                        <?php endif; ?>
                                        <?php if(!empty($first->viefund_processing_date)): ?>
                                            <div><strong>VieFund - Processing Date:</strong> <?php echo e($first->viefund_processing_date); ?></div>
                                        <?php endif; ?>
                                        <?php if($group['count'] > 1): ?>
                                            <div><strong>Transactions:</strong> <?php echo e($group['count']); ?></div>
                                        <?php endif; ?>
                                        <div><strong>Description:</strong> <?php echo e($first->bank_description ?? '-'); ?></div>
                                    <?php else: ?>
                                        <div style="font-family: monospace; white-space: pre-wrap;"><?php echo e($first->metadata ?? '-'); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px; color: #4a5568; font-family: monospace; font-size: 12px; white-space: nowrap;">
                                    <?php echo \Carbon\Carbon::parse($first->created_at)->format('Y-M-d') . '<br>' . \Carbon\Carbon::parse($first->created_at)->format('H:i:s'); ?>

                                </td>
                            </tr>

                            <?php $__currentLoopData = $group['items']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $match): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <tr class="group-row <?php echo e($groupId); ?>" style="border-bottom: 1px solid #e2e8f0; display: none; background: #f9fafb;">
                                    <td style="padding: 12px; color: #2d3748; font-size: 12px; max-width: 110px; white-space: normal; text-align: center;">
                                        <?php
                                            $ruleLabel = match($match->match_rule) {
                                                'fundserv_order_id_to_viefund_fund_wo_number' => 'Fundserv<br>to<br>VieFund',
                                                'bank_to_fundserv_amount_date' => !empty($match->metadata_array['viefund_id'] ?? null)
                                                    ? 'Bank<br>to<br>Fundserv<br>&amp;<br>VieFund'
                                                    : 'Bank<br>to<br>Fundserv',
                                                'bank_to_viefund_amount_date' => 'Bank<br>to<br>VieFund',
                                                default => str_replace('_', ' ', $match->match_rule),
                                            };
                                        ?>
                                        <?php echo $ruleLabel; ?>

                                    </td>
                                    <td style="padding: 12px; text-align: right; color: #2d3748; font-family: monospace;">
                                        <?php echo e($match->viefund_amount !== null ? number_format($match->viefund_amount, 2) : '-'); ?>

                                    </td>
                                    <td style="padding: 12px; text-align: right; color: #2d3748; font-family: monospace;">
                                        <?php echo e($match->fundserv_actual_amount !== null
                                            ? number_format($match->fundserv_actual_amount, 2)
                                            : ($match->fundserv_amount !== null
                                                ? number_format($match->fundserv_amount, 2)
                                                : ($match->fundserv_right_actual_amount !== null
                                                    ? number_format($match->fundserv_right_actual_amount, 2)
                                                    : ($match->fundserv_right_amount !== null
                                                        ? number_format($match->fundserv_right_amount, 2)
                                                        : '-')))); ?>

                                    </td>
                                    <td style="padding: 12px; text-align: right; color: #2d3748; font-family: monospace;">
                                        <?php echo e($match->bank_amount !== null ? number_format($match->bank_amount, 2) : '-'); ?>

                                    </td>
                                    <td style="padding: 12px; text-align: right; color: #2d3748; font-family: monospace;">
                                        -
                                    </td>
                                    <td style="padding: 12px; text-align: center;">
                                        <?php
                                            $confidence = (float) ($match->confidence ?? 0);
                                            if ($confidence >= 0.9) {
                                                $barBg = '#c6f6d5';
                                                $barFill = '#2f855a';
                                            } elseif ($confidence >= 0.75) {
                                                $barBg = '#9ae6b4';
                                                $barFill = '#2f855a';
                                            } elseif ($confidence >= 0.6) {
                                                $barBg = '#68d391';
                                                $barFill = '#276749';
                                            } elseif ($confidence >= 0.4) {
                                                $barBg = '#48bb78';
                                                $barFill = '#22543d';
                                            } else {
                                                $barBg = '#38a169';
                                                $barFill = '#1c4532';
                                            }
                                            $pctText = number_format($confidence * 100, 1) . '%';
                                        ?>
                                        <div style="font-family: monospace; font-size: 12px; font-weight: 600; color: #2d3748; margin-bottom: 6px;">
                                            <?php echo e($pctText); ?>

                                        </div>
                                        <div style="height: 8px; width: 96px; margin: 0 auto; background: <?php echo e($barBg); ?>; border-radius: 999px; overflow: hidden;">
                                            <div style="height: 100%; width: <?php echo e($pctText); ?>; background: <?php echo e($barFill); ?>;"></div>
                                        </div>
                                    </td>
                                    <td style="padding: 12px; color: #4a5568; font-size: 12px;">
                                        <?php if($match->match_rule === 'fundserv_order_id_to_viefund_fund_wo_number'): ?>
                                            <div><strong>Order ID:</strong> <?php echo e($match->fundserv_order_id ?? '-'); ?></div>
                                            <div><strong>Fund WO#:</strong> <?php echo e($match->viefund_fund_wo_number ?? '-'); ?></div>
                                        <?php elseif($match->match_rule === 'bank_to_fundserv_amount_date'): ?>
                                            <?php
                                                $orderId = $match->fundserv_order_id ?? $match->viefund_fund_wo_number ?? null;
                                            ?>
                                            <?php if(!empty($orderId)): ?>
                                                <div><strong>Fundserv - Order ID:</strong> <?php echo e($orderId); ?></div>
                                            <?php endif; ?>
                                            <div><strong>Bank Date:</strong> <?php echo e($match->bank_txn_date ?? '-'); ?></div>
                                            <div><strong>Fundserv - Settlement Date:</strong> <?php echo e($match->fundserv_right_date ?? '-'); ?></div>
                                            <?php if(!empty($match->viefund_settlement_date)): ?>
                                                <div><strong>VieFund - Settlement Date:</strong> <?php echo e($match->viefund_settlement_date); ?></div>
                                            <?php endif; ?>
                                            <div><strong>Description:</strong> <?php echo e($match->bank_description ?? '-'); ?></div>
                                        <?php elseif($match->match_rule === 'bank_to_viefund_amount_date'): ?>
                                            <div><strong>Bank Date:</strong> <?php echo e($match->bank_txn_date ?? '-'); ?></div>
                                            <?php if(!empty($match->viefund_settlement_date)): ?>
                                                <div><strong>VieFund - Settlement Date:</strong> <?php echo e($match->viefund_settlement_date); ?></div>
                                            <?php endif; ?>
                                            <?php if(!empty($match->viefund_trade_date)): ?>
                                                <div><strong>VieFund - Trade Date:</strong> <?php echo e($match->viefund_trade_date); ?></div>
                                            <?php endif; ?>
                                            <?php if(!empty($match->viefund_processing_date)): ?>
                                                <div><strong>VieFund - Processing Date:</strong> <?php echo e($match->viefund_processing_date); ?></div>
                                            <?php endif; ?>
                                            <div><strong>Description:</strong> <?php echo e($match->bank_description ?? '-'); ?></div>
                                        <?php else: ?>
                                            <div style="font-family: monospace; white-space: pre-wrap;"><?php echo e($match->metadata ?? '-'); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 12px; color: #4a5568; font-family: monospace; font-size: 12px; white-space: nowrap;">
                                        <?php echo \Carbon\Carbon::parse($match->created_at)->format('Y-M-d') . '<br>' . \Carbon\Carbon::parse($match->created_at)->format('H:i:s'); ?>

                                    </td>
                                </tr>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </tbody>
                </table>
            </div>

            <div style="margin-top: 20px; display: flex; justify-content: center;">
                <?php echo e($matchesPaginator->onEachSide(1)->links()); ?>

            </div>
        <?php else: ?>
            <div style="text-align: center; padding: 40px; color: #718096;">
                <p style="font-size: 18px; margin-bottom: 10px;">No reconciliation matches found.</p>
            </div>
        <?php endif; ?>
    </div>

    <script>
        const processingModal = document.createElement('div');
        processingModal.id = 'processing-modal';
        processingModal.style.cssText = 'display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); align-items:center; justify-content:center; z-index:60;';
        processingModal.innerHTML = `
            <div style="background:#fff; padding:24px 28px; border-radius:10px; min-width:320px; text-align:center; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
                <div style="width:40px; height:40px; margin:0 auto 12px; border:4px solid #cbd5e0; border-top-color:#2f855a; border-radius:50%; animation:spin 0.9s linear infinite;"></div>
                <div style="font-weight:600; color:#2d3748; margin-bottom:6px;">Processing</div>
                <div style="font-size:13px; color:#718096;">Please wait while we process matches.</div>
            </div>
        `;
        document.body.appendChild(processingModal);

        const styleEl = document.createElement('style');
        styleEl.textContent = '@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }';
        document.head.appendChild(styleEl);

        document.querySelectorAll('.match-action-form').forEach(function (form) {
            form.addEventListener('submit', function () {
                processingModal.style.display = 'flex';
            });
        });

        document.querySelectorAll('.group-toggle').forEach(function (row) {
            row.addEventListener('click', function () {
                if (row.getAttribute('data-expandable') !== 'true') {
                    return;
                }
                var groupId = row.getAttribute('data-group');
                document.querySelectorAll('.' + groupId).forEach(function (detailRow) {
                    var isHidden = detailRow.style.display === 'none' || detailRow.style.display === '';
                    detailRow.style.display = isHidden ? 'table-row' : 'none';
                });
            });
        });
    </script>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /Users/staceyrattlesnake/Projects/opus_reconciliation/resources/views/reconciliations/matches.blade.php ENDPATH**/ ?>