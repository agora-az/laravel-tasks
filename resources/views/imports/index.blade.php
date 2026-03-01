@extends('layouts.app')

@section('title', 'Import Data')

@section('content')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/dropzone/5.9.3/basic.min.css">
    <style>
        .dz-preview {
            margin: 0;
        }
        .dz-message {
            margin: 0;
        }
        #dropzone {
            border: 2px dashed #cbd5e0;
            border-radius: 4px;
            padding: 40px;
            text-align: center;
            background: #f7fafc;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        #dropzone.dz-drag-hover {
            background: #e2e8f0;
            border-color: #345262;
        }
        .dz-progress {
            background: linear-gradient(90deg, #345262, #5a7585);
        }
    </style>

    @if(session('success'))
        <div class="alert alert-success">
            {{ session('success') }}
        </div>
    @endif

    @if(session('error'))
        <div class="alert" style="background: #fed7d7; color: #742a2a; border-left: 4px solid #e53e3e; margin-bottom: 20px; padding: 14px 20px; border-radius: 4px;">
            <strong>⚠️ Error:</strong> {{ session('error') }}
        </div>
    @endif

    @if($errors->any())
        <div class="alert" style="background: #fed7d7; color: #742a2a; margin-bottom: 20px;">
            <strong>Errors:</strong>
            <ul style="margin: 10px 0 0 20px;">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

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
        <a href="{{ route('imports.transactions', ['type' => 'viefund']) }}" class="card" style="text-decoration: none; color: inherit; transition: transform 0.2s;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h3 style="margin-bottom: 5px; color: #2d3748;">View VieFund Data</h3>
                    <p style="color: #718096; font-size: 14px;">Browse imported VieFund transactions</p>
                </div>
                <div style="font-size: 32px;">📊</div>
            </div>
        </a>
        
        <a href="{{ route('imports.transactions', ['type' => 'fundserv']) }}" class="card" style="text-decoration: none; color: inherit; transition: transform 0.2s;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h3 style="margin-bottom: 5px; color: #2d3748;">View Fundserv Data</h3>
                    <p style="color: #718096; font-size: 14px;">Browse imported Fundserv transactions</p>
                </div>
                <div style="font-size: 32px;">📈</div>
            </div>
        </a>
        
        <a href="{{ route('imports.transactions', ['type' => 'bank']) }}" class="card" style="text-decoration: none; color: inherit; transition: transform 0.2s;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
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
            Select the data type and upload your CSV file. Files are uploaded in chunks (5 MB each) to handle large files up to 100 MB.
        </p>
        
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
            <label>Select File (Drag & Drop or Click)</label>
            <form id="uploadForm" class="dropzone" action="{{ route('api.upload.chunk') }}" method="POST" enctype="multipart/form-data">
                @csrf
                <div class="dz-message">
                    <div style="font-size: 32px; margin-bottom: 10px;">📁</div>
                    <div style="font-size: 16px; color: #2d3748; margin-bottom: 5px;"><strong>Drag files here or click to select</strong></div>
                    <div style="font-size: 14px; color: #718096;">Supports CSV and PDF files up to 100 MB</div>
                </div>
            </form>
        </div>
        
        <div id="format_info" style="margin-top: 15px; padding: 12px; background: #f7fafc; border-radius: 4px; border-left: 3px solid #cbd5e0; display: none;">
            <strong style="color: #2d3748; font-size: 14px;">Expected Format:</strong>
            <p id="format_text" style="color: #4a5568; font-size: 13px; margin-top: 5px;"></p>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/dropzone/5.9.3/min/dropzone.min.js"></script>
    <script>
        // Disable Dropzone auto-discovery
        Dropzone.autoDiscover = false;

        // Initialize Dropzone
        const dropzone = new Dropzone('#uploadForm', {
            paramName: 'file',
            maxFilesize: 100, // 100 MB
            maxFiles: 1,
            acceptedFiles: '.csv,.txt,.pdf',
            chunkSize: 5242880, // 5 MB chunks
            parallelChunkUploads: false,
            chunking: true,
            retryChunks: true,
            retryChunksLimit: 3,
            autoProcessQueue: false,
            timeout: 300000, // 5 minutes per chunk
            
            init: function() {
                const dz = this;

                // Add CSRF token
                this.on('sending', function(file, xhr, formData) {
                    console.log('Sending chunk:', file.name);
                    const importType = document.getElementById('import_type').value;
                    if (!importType) {
                        dz.removeFile(file);
                        alert('Please select a data type first');
                        return;
                    }

                    formData.append('_token', @json(csrf_token()));
                    formData.append('importType', importType);
                    formData.append('fileId', file.upload.uuid);
                    formData.append('originalFilename', file.name);
                    
                    if (file.upload.chunked) {
                        formData.append('chunkIndex', file.upload.chunk);
                        formData.append('totalChunks', Math.ceil(file.size / dz.options.chunkSize));
                        console.log(`Uploading chunk ${file.upload.chunk} of ${Math.ceil(file.size / dz.options.chunkSize)}`);
                    } else {
                        formData.append('chunkIndex', 0);
                        formData.append('totalChunks', 1);
                    }
                });

                this.on('uploadprogress', function(file, progress, bytesSent) {
                    console.log('Progress:', Math.round(progress) + '%');
                    const progressBar = document.querySelector('.progress-bar');
                    if (progressBar) {
                        progressBar.style.width = Math.round(progress) + '%';
                    }
                });

                this.on('success', function(file, response) {
                    console.log('Upload success, response:', response);
                    
                    if (response && response.success) {
                        console.log('Import successful:', response);
                        showUploadOverlay(response.imported || 0, response.duplicates || 0, response.errors || 0);
                    } else if (response) {
                        // Partial success or still processing
                        console.log('Chunk received, response:', response);
                    }
                });

                this.on('error', function(file, errorMessage, xhr) {
                    console.error('Upload error:', errorMessage, xhr);
                    const overlay = document.getElementById('uploadOverlay');
                    overlay.style.display = 'none';
                    alert('Upload failed: ' + (errorMessage?.message || errorMessage || 'Unknown error'));
                    dz.removeFile(file);
                });

                this.on('complete', function(file) {
                    console.log('File upload complete, status:', file.status);
                });

                // Handle file added
                this.on('addedfile', function(file) {
                    console.log('File added:', file.name, 'Size:', file.size);
                    const importType = document.getElementById('import_type').value;
                    if (!importType) {
                        dz.removeFile(file);
                        alert('Please select a data type first');
                        return false;
                    }
                    
                    // Show overlay and start upload
                    const overlay = document.getElementById('uploadOverlay');
                    overlay.style.display = 'flex';
                    const statusEl = document.querySelector('#uploadStatus');
                    const progressBar = document.querySelector('.progress-bar');
                    statusEl.textContent = `Uploading file (${(file.size / (1024 * 1024)).toFixed(2)} MB)...`;
                    progressBar.style.width = '5%';
                    
                    console.log('Starting upload for:', file.name);
                    dz.processFile(file);
                });
            }
        });

        function updateFormatInfo() {
            const type = document.getElementById('import_type').value;
            const formatInfo = document.getElementById('format_info');
            const formatText = document.getElementById('format_text');
            
            if (type === 'viefund') {
                formatInfo.style.display = 'block';
                formatInfo.style.background = '#e6fffa';
                formatInfo.style.borderColor = '#38b2ac';
                formatText.style.color = '#2c7a7b';
                formatText.textContent = 'Client Name, Rep Code, Plan Description, Institution, Account ID, Trx ID, Created Date, Trx Type, Trade Date, Settlement Date, Processing Date, Source ID, Status, Amount, Balance, Fund Code, Fund TrxType, Fund Trx Amount, Fund Settlement Source, Fund WO#, Fund SourceID';
            } else if (type === 'fundserv') {
                formatInfo.style.display = 'block';
                formatInfo.style.background = '#ebf8ff';
                formatInfo.style.borderColor = '#4299e1';
                formatText.style.color = '#2c5282';
                formatText.textContent = '#, Company, Settlement Date, Code, Src, Trade date, FundID, Dealer Account ID, Order ID, Source Identifier, TxType, Settlement Amt, Actual Amount (or Actual Amunt)';
            } else if (type === 'bank') {
                formatInfo.style.display = 'block';
                formatInfo.style.background = '#fef5e7';
                formatInfo.style.borderColor = '#f6ad55';
                formatText.style.color = '#7c2d12';
                formatText.textContent = 'Upload a bank statement PDF (e.g., CIBC account statement) with Transaction details section.';
            } else {
                formatInfo.style.display = 'none';
            }
        }

        function showUploadOverlay(imported, duplicates, errors) {
            const overlay = document.getElementById('uploadOverlay');
            const statusElement = document.getElementById('uploadStatus');
            const progressBar = overlay.querySelector('.progress-bar');
            
            progressBar.style.width = '100%';
            
            let message = `✅ Import complete! ${imported} records imported`;
            if (duplicates > 0) {
                message += `, ${duplicates} duplicates skipped`;
            }
            if (errors > 0) {
                message += `, ${errors} errors`;
            }
            
            statusElement.textContent = message;
            
            // Redirect to import history page and show toast
            setTimeout(() => {
                // Store import summary in sessionStorage for the history page to display as toast
                sessionStorage.setItem('importSummary', JSON.stringify({
                    imported: imported,
                    duplicates: duplicates,
                    errors: errors,
                    timestamp: new Date().toISOString()
                }));
                window.location.href = '{{ route("imports.history") }}';
            }, 1000);
        }
    </script>
@endsection
