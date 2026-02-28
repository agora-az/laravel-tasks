<?php $__env->startSection('title', 'Import Data'); ?>

<?php $__env->startSection('content'); ?>
    <?php if(session('success')): ?>
        <div class="alert alert-success">
            <?php echo e(session('success')); ?>

        </div>
    <?php endif; ?>

    <?php if(session('error')): ?>
        <div class="alert" style="background: #fed7d7; color: #742a2a; border-left: 4px solid #e53e3e; margin-bottom: 20px; padding: 14px 20px; border-radius: 4px;">
            <strong>⚠️ Error:</strong> <?php echo e(session('error')); ?>

        </div>
    <?php endif; ?>

    <?php if($errors->any()): ?>
        <div class="alert" style="background: #fed7d7; color: #742a2a; margin-bottom: 20px;">
            <strong>Errors:</strong>
            <ul style="margin: 10px 0 0 20px;">
                <?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $error): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <li><?php echo e($error); ?></li>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- Loading Overlay -->
    <div id="uploadOverlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 9999; align-items: center; justify-content: center;">
        <div style="background: white; padding: 40px; border-radius: 8px; text-align: center; max-width: 400px;">
            <div class="spinner" style="margin: 0 auto 20px;"></div>
            <h3 style="margin-bottom: 10px; color: #2d3748;">Processing Upload</h3>
            <p style="color: #718096; margin-bottom: 20px;">Please wait while we import your data...</p>
            <div style="background: #e2e8f0; height: 8px; border-radius: 4px; overflow: hidden;">
                <div class="progress-bar" style="background: linear-gradient(90deg, #345262, #5a7585); height: 100%; width: 0%;"></div>
            </div>
            <p id="uploadStatus" style="color: #718096; font-size: 14px; margin-top: 15px;">Uploading file...</p>
        </div>
    </div>

    <h2 style="margin-bottom: 20px;">Import Transaction Data</h2>

    <!-- Quick Links to View Data -->
    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; margin-bottom: 20px;">
        <a href="<?php echo e(route('imports.transactions', ['type' => 'viefund'])); ?>" class="card" style="text-decoration: none; color: inherit; transition: transform 0.2s;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h3 style="margin-bottom: 5px; color: #2d3748;">View VieFund Data</h3>
                    <p style="color: #718096; font-size: 14px;">Browse imported VieFund transactions</p>
                </div>
                <div style="font-size: 32px;">📊</div>
            </div>
        </a>
        
        <a href="<?php echo e(route('imports.transactions', ['type' => 'fundserv'])); ?>" class="card" style="text-decoration: none; color: inherit; transition: transform 0.2s;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h3 style="margin-bottom: 5px; color: #2d3748;">View Fundserv Data</h3>
                    <p style="color: #718096; font-size: 14px;">Browse imported Fundserv transactions</p>
                </div>
                <div style="font-size: 32px;">📈</div>
            </div>
        </a>
        
        <a href="<?php echo e(route('imports.transactions', ['type' => 'bank'])); ?>" class="card" style="text-decoration: none; color: inherit; transition: transform 0.2s;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h3 style="margin-bottom: 5px; color: #2d3748;">View Bank Data</h3>
                    <p style="color: #718096; font-size: 14px;">Browse imported bank statement transactions</p>
                </div>
                <div style="font-size: 32px;">🏦</div>
            </div>
        </a>
    </div>

    <!-- Unified Import Form -->
    <div class="card">
        <h3 style="margin-bottom: 15px; color: #2d3748;">Upload Transaction Data</h3>
        <p style="color: #718096; margin-bottom: 20px; font-size: 14px;">
            Select the data type and upload your CSV file.
        </p>
        
        <form action="<?php echo e(route('imports.upload')); ?>" method="POST" enctype="multipart/form-data" class="upload-form">
            <?php echo csrf_field(); ?>
            
            <div class="form-group">
                <label for="import_type">Data Type</label>
                <select id="import_type" name="import_type" required 
                        style="padding: 10px 14px; border: 1px solid #cbd5e0; border-radius: 4px; width: 100%; background: white; font-size: 14px;"
                        onchange="updateFormatInfo()">
                    <option value="">Select data type...</option>
                    <option value="viefund">VieFund - Cash Position Data</option>
                    <option value="fundserv">Fundserv - Transaction Data</option>
                    <option value="bank">Bank Statements</option>
                </select>
            </div>

            <div class="form-group">
                <label for="csv_file">Select File</label>
                <input type="file" id="csv_file" name="csv_file" accept=".csv,.txt,.pdf" required 
                       style="padding: 8px; border: 1px solid #cbd5e0; border-radius: 4px; width: 100%; background: white;"
                       onchange="updateFileInfo(this, 'main')">
                <p id="main_file_info" style="margin-top: 8px; font-size: 13px; color: #718096;"></p>
            </div>
            
            <div id="format_info" style="margin-top: 15px; padding: 12px; background: #f7fafc; border-radius: 4px; border-left: 3px solid #cbd5e0; display: none;">
                <strong style="color: #2d3748; font-size: 14px;">Expected Format:</strong>
                <p id="format_text" style="color: #4a5568; font-size: 13px; margin-top: 5px;"></p>
            </div>

            <button type="submit" class="btn" style="margin-top: 20px; width: 100%;" id="upload_button" disabled>
                Upload Data
            </button>
        </form>
    </div>

    <script>
        function updateFormatInfo() {
            const type = document.getElementById('import_type').value;
            const formatInfo = document.getElementById('format_info');
            const formatText = document.getElementById('format_text');
            const uploadButton = document.getElementById('upload_button');
            
            if (type === 'viefund') {
                formatInfo.style.display = 'block';
                formatInfo.style.background = '#e6fffa';
                formatInfo.style.borderColor = '#38b2ac';
                formatText.style.color = '#2c7a7b';
                formatText.textContent = 'Client Name, Rep Code, Plan Description, Institution, Account ID, Trx ID, Created Date, Trx Type, Trade Date, Settlement Date, Processing Date, Source ID, Status, Amount, Balance, Fund Code, Fund TrxType, Fund Trx Amount, Fund Settlement Source, Fund WO#, Fund SourceID';
                uploadButton.disabled = false;
            } else if (type === 'fundserv') {
                formatInfo.style.display = 'block';
                formatInfo.style.background = '#ebf8ff';
                formatInfo.style.borderColor = '#4299e1';
                formatText.style.color = '#2c5282';
                formatText.textContent = '#, Company, Settlement Date, Code, Src, Trade date, FundID, Dealer Account ID, Order ID, Source Identifier, TxType, Settlement Amt, Actual Amount (or Actual Amunt)';
                uploadButton.disabled = false;
            } else if (type === 'bank') {
                formatInfo.style.display = 'block';
                formatInfo.style.background = '#fef5e7';
                formatInfo.style.borderColor = '#f6ad55';
                formatText.style.color = '#7c2d12';
                formatText.textContent = 'Upload a bank statement PDF (e.g., CIBC account statement) with Transaction details section.';
                uploadButton.disabled = false;
            } else {
                formatInfo.style.display = 'none';
                uploadButton.disabled = true;
            }
        }

        function updateFileInfo(input, prefix) {
            const file = input.files[0];
            const infoEl = document.getElementById(prefix + '_file_info');
            
            if (file) {
                const sizeMB = (file.size / (1024 * 1024)).toFixed(2);
                infoEl.textContent = `📄 ${file.name} (${sizeMB} MB)`;
                infoEl.style.color = '#38a169';
            }
        }

        document.querySelectorAll('.upload-form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const fileInput = this.querySelector('input[type="file"]');
                const file = fileInput.files[0];
                
                if (file) {
                    const overlay = document.getElementById('uploadOverlay');
                    const status = document.getElementById('uploadStatus');
                    const progressBar = overlay.querySelector('.progress-bar');
                    
                    overlay.style.display = 'flex';
                    status.textContent = `Uploading file (${(file.size / (1024 * 1024)).toFixed(2)} MB)...`;
                    progressBar.style.width = '10%';
                    progressBar.style.transition = 'width 0.5s ease';
                    
                    // Simulate progress updates
                    setTimeout(() => {
                        progressBar.style.width = '30%';
                        status.textContent = 'Processing CSV data...';
                    }, 500);
                    
                    setTimeout(() => {
                        progressBar.style.width = '60%';
                        status.textContent = 'Importing records to database...';
                    }, 1500);
                    
                    setTimeout(() => {
                        progressBar.style.width = '90%';
                        status.textContent = 'Finalizing import...';
                    }, 3000);
                }
            });
        });
    </script>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /Users/staceyrattlesnake/Projects/agora-reconciliation/resources/views/imports/index.blade.php ENDPATH**/ ?>