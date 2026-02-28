<?php $__env->startSection('title', 'Reconciliation Management'); ?>

<?php $__env->startSection('content'); ?>
    <!-- Header -->
    <div style="margin-bottom: 30px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
            <div>
                <h2 style="margin: 0 0 5px 0;">Bank Reconcile</h2>
                <div style="color: #718096; font-size: 14px;">
                    Banking / Business Checking / Bank Reconcile
                </div>
            </div>
            <div style="display: flex; gap: 10px;">
                <button class="btn" style="background: var(--primary-blue); padding: 12px 30px;">
                    ✓ Finish
                </button>
                <button class="btn" style="background: #9f7aea; padding: 12px 30px;">
                    🕐 Finish Later
                </button>
            </div>
        </div>
    </div>

    <!-- Date Range Selector -->
    <div class="card" style="margin-bottom: 30px;">
        <div style="display: flex; gap: 15px; align-items: center;">
            <div>
                <label style="display: block; margin-bottom: 5px; color: #4a5568; font-weight: 500;">Date Range</label>
                <select style="padding: 10px 14px; border: 1px solid #cbd5e0; border-radius: 4px; background: white;">
                    <option>Custom</option>
                    <option>Last Month</option>
                    <option>Last Quarter</option>
                    <option>Year to Date</option>
                </select>
            </div>
            <div>
                <label style="display: block; margin-bottom: 5px; color: #4a5568; font-weight: 500;">From</label>
                <input type="date" value="2025-08-01" style="padding: 10px 14px; border: 1px solid #cbd5e0; border-radius: 4px;">
            </div>
            <div style="padding-top: 25px; color: #718096;">to</div>
            <div>
                <label style="display: block; margin-bottom: 5px; color: #4a5568; font-weight: 500;">To</label>
                <input type="date" value="2025-09-16" style="padding: 10px 14px; border: 1px solid #cbd5e0; border-radius: 4px;">
            </div>
        </div>
    </div>

    <!-- Summary Cards -->
    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 30px;">
        <div class="card" style="text-align: center; background: linear-gradient(135deg, #345262 0%, #5a7585 100%); color: white;">
            <div style="font-size: 36px; font-weight: bold; margin-bottom: 5px;">3,550.00</div>
            <div style="font-size: 14px; opacity: 0.9;">Cleared Balance</div>
        </div>
        <div class="card" style="text-align: center; background: linear-gradient(135deg, #345262 0%, #5a7585 100%); color: white;">
            <div style="font-size: 36px; font-weight: bold; margin-bottom: 5px;">3,550.00</div>
            <div style="font-size: 14px; opacity: 0.9;">Ending Balance</div>
        </div>
        <div class="card" style="text-align: center; background: linear-gradient(135deg, #38a169 0%, #2f855a 100%); color: white;">
            <div style="font-size: 36px; font-weight: bold; margin-bottom: 5px;">0.00</div>
            <div style="font-size: 14px; opacity: 0.9;">Difference</div>
        </div>
    </div>

    <!-- Two-Column Layout for Debits and Credits -->
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
        
        <!-- Debits & Withdrawals -->
        <div class="card">
            <h3 style="margin-bottom: 20px; color: #2d3748;">Debits & Withdrawals</h3>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #2c5282; color: white;">
                            <th style="padding: 12px 8px; text-align: center; width: 40px;">
                                <input type="checkbox" style="width: 18px; height: 18px;">
                            </th>
                            <th style="padding: 12px; text-align: left; font-weight: 600;">Date</th>
                            <th style="padding: 12px; text-align: left; font-weight: 600;">Check #</th>
                            <th style="padding: 12px; text-align: left; font-weight: 600;">Description</th>
                            <th style="padding: 12px; text-align: right; font-weight: 600;">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="border-bottom: 1px solid #e2e8f0; background: #f7fafc;">
                            <td style="padding: 12px 8px; text-align: center;">
                                <input type="checkbox">
                            </td>
                            <td style="padding: 12px; color: #2d3748;">09/16/2025</td>
                            <td style="padding: 12px; color: #4a5568; font-family: monospace;"></td>
                            <td style="padding: 12px; color: #4a5568;">Excellent Prop...</td>
                            <td style="padding: 12px; text-align: right; color: #e53e3e; font-weight: 500; font-family: monospace;">(125.00)</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #e2e8f0; background: #f7fafc;">
                            <td style="padding: 12px 8px; text-align: center;">
                                <input type="checkbox">
                            </td>
                            <td style="padding: 12px; color: #2d3748;">09/16/2025</td>
                            <td style="padding: 12px; color: #4a5568; font-family: monospace;"></td>
                            <td style="padding: 12px; color: #4a5568;">Excellent Prop...</td>
                            <td style="padding: 12px; text-align: right; color: #e53e3e; font-weight: 500; font-family: monospace;">(50.00)</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #e2e8f0; background: #f7fafc;">
                            <td style="padding: 12px 8px; text-align: center;">
                                <input type="checkbox">
                            </td>
                            <td style="padding: 12px; color: #2d3748;">09/16/2025</td>
                            <td style="padding: 12px; color: #4a5568; font-family: monospace;"></td>
                            <td style="padding: 12px; color: #4a5568;">Kraft, Katrina</td>
                            <td style="padding: 12px; text-align: right; color: #e53e3e; font-weight: 500; font-family: monospace;">(5,350.00)</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #e2e8f0; background: #f7fafc;">
                            <td style="padding: 12px 8px; text-align: center;">
                                <input type="checkbox">
                            </td>
                            <td style="padding: 12px; color: #2d3748;">09/16/2025</td>
                            <td style="padding: 12px; color: #4a5568; font-family: monospace;"></td>
                            <td style="padding: 12px; color: #4a5568;">Oliver, Ollie</td>
                            <td style="padding: 12px; text-align: right; color: #e53e3e; font-weight: 500; font-family: monospace;">(825.00)</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr style="border-top: 2px solid #2c5282; background: #edf2f7;">
                            <td colspan="4" style="padding: 12px; font-weight: 600; color: #2d3748;">Total Debits & Withdrawals</td>
                            <td style="padding: 12px; text-align: right; font-weight: 700; color: #2d3748; font-family: monospace; font-size: 16px;">0.00</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Credits & Deposits -->
        <div class="card">
            <h3 style="margin-bottom: 20px; color: #2d3748;">Credits & Deposits</h3>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #2c5282; color: white;">
                            <th style="padding: 12px 8px; text-align: center; width: 40px;">
                                <input type="checkbox" style="width: 18px; height: 18px;">
                            </th>
                            <th style="padding: 12px; text-align: left; font-weight: 600;">Date</th>
                            <th style="padding: 12px; text-align: left; font-weight: 600;">Check #</th>
                            <th style="padding: 12px; text-align: left; font-weight: 600;">Description</th>
                            <th style="padding: 12px; text-align: right; font-weight: 600;">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="border-bottom: 1px solid #e2e8f0; background: #e6f7ff;">
                            <td style="padding: 12px 8px; text-align: center;">
                                <input type="checkbox" checked>
                            </td>
                            <td style="padding: 12px; color: #2d3748;">08/02/2025</td>
                            <td style="padding: 12px; color: #4a5568; font-family: monospace;"></td>
                            <td style="padding: 12px; color: #4a5568;">Hayes, Hugh</td>
                            <td style="padding: 12px; text-align: right; color: #38a169; font-weight: 500; font-family: monospace;">1,350.00</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #e2e8f0; background: #e6f7ff;">
                            <td style="padding: 12px 8px; text-align: center;">
                                <input type="checkbox" checked>
                            </td>
                            <td style="padding: 12px; color: #2d3748;">08/04/2025</td>
                            <td style="padding: 12px; color: #4a5568; font-family: monospace;"></td>
                            <td style="padding: 12px; color: #4a5568;">Sampson, Lisa</td>
                            <td style="padding: 12px; text-align: right; color: #38a169; font-weight: 500; font-family: monospace;">2,200.00</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #e2e8f0; background: #f7fafc;">
                            <td style="padding: 12px 8px; text-align: center;">
                                <input type="checkbox">
                            </td>
                            <td style="padding: 12px; color: #2d3748;">09/03/2025</td>
                            <td style="padding: 12px; color: #4a5568; font-family: monospace;"></td>
                            <td style="padding: 12px; color: #4a5568;">Hayes, Hugh</td>
                            <td style="padding: 12px; text-align: right; color: #38a169; font-weight: 500; font-family: monospace;">1,350.00</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #e2e8f0; background: #f7fafc;">
                            <td style="padding: 12px 8px; text-align: center;">
                                <input type="checkbox">
                            </td>
                            <td style="padding: 12px; color: #2d3748;">09/04/2025</td>
                            <td style="padding: 12px; color: #4a5568; font-family: monospace;"></td>
                            <td style="padding: 12px; color: #4a5568;">Sampson, Lisa</td>
                            <td style="padding: 12px; text-align: right; color: #38a169; font-weight: 500; font-family: monospace;">2,200.00</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr style="border-top: 2px solid #2c5282; background: #edf2f7;">
                            <td colspan="4" style="padding: 12px; font-weight: 600; color: #2d3748;">Total Credits & Deposits</td>
                            <td style="padding: 12px; text-align: right; font-weight: 700; color: #2d3748; font-family: monospace; font-size: 16px;">3,550.00</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Help Text -->
    <div class="card" style="margin-top: 20px; background: #ebf8ff; border-left: 4px solid #3182ce;">
        <p style="color: #2c5aa0; margin: 0;">
            ℹ️ <strong>Note:</strong> This is a placeholder interface. Reconciliation functionality will be implemented in a future update.
            Check transactions that have cleared your bank statement, then click "Finish" to complete the reconciliation.
        </p>
    </div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /Users/staceyrattlesnake/Projects/opus_reconciliation/resources/views/reconciliations/manage.blade.php ENDPATH**/ ?>