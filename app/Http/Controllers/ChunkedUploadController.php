<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Queue;
use App\Models\VieFundTransaction;
use App\Models\FundservTransaction;
use App\Models\BankTransaction;
use App\Models\Import;
use App\Jobs\ProcessImportJob;
use Smalot\PdfParser\Parser;

class ChunkedUploadController extends Controller
{
    public function __construct()
    {
        // Increase execution time for file processing
        // Some large files may take 10+ minutes to process
        set_time_limit(1800); // 30 minutes
    }

    /**
     * Handle chunk upload
     */
    public function uploadChunk(Request $request)
    {
        // Extra safety: set time limit inside method too
        set_time_limit(1800); // 30 minutes

        try {
            // Dropzone.js sends chunk info with 'dz' prefix (dzchunkindex, dztotalchunkcount)
            // Extract the actual chunk info
            $chunkIndex = (int)($request->input('dzchunkindex') ?? $request->input('chunkIndex') ?? 0);
            $totalChunks = (int)($request->input('dztotalchunkcount') ?? $request->input('totalChunks') ?? 1);
            $fileId = $request->input('dzuuid') ?? $request->input('fileId');
            $importType = $request->input('importType');
            $originalFilename = $request->input('originalFilename');

            // Generate fileId if not provided (fallback for some upload scenarios)
            if (empty($fileId)) {
                $fileId = md5($originalFilename . time() . rand(1000, 9999));
                \Log::info("Generated fileId for upload", ['fileId' => $fileId, 'filename' => $originalFilename]);
            }

            // Log incoming request for debugging
            \Log::info("uploadChunk request received", [
                'chunkIndex' => $chunkIndex,
                'totalChunks' => $totalChunks,
                'fileId' => $fileId,
                'importType' => $importType,
                'originalFilename' => $originalFilename,
            ]);

            $request->validate([
                'file' => 'required|file',
                'importType' => 'required|in:viefund,fundserv,bank',
                'originalFilename' => 'required|string',
            ]);

            $chunk = $request->file('file');
            $chunkDir = "temp/uploads/{$fileId}";

            // Store original filename on first chunk
            if ($chunkIndex == 0) {
                Storage::disk('local')->makeDirectory($chunkDir, 0755, true);
                Storage::disk('local')->put("{$chunkDir}/filename.txt", $originalFilename);
            }

            \Log::info("Chunk upload: {$chunkIndex}/{$totalChunks} for {$importType}, fileId: {$fileId}, filename: {$originalFilename}");

            // Ensure directory exists
            Storage::disk('local')->makeDirectory($chunkDir, 0755, true);

            // Read the chunk file as binary data
            $chunkContent = file_get_contents($chunk->getRealPath());

            if ($chunkContent === false) {
                throw new \Exception('Failed to read uploaded chunk');
            }

            \Log::info("Chunk {$chunkIndex} size: " . strlen($chunkContent) . " bytes");

            // Store chunk with numeric filename
            $result = Storage::disk('local')->put("{$chunkDir}/chunk_{$chunkIndex}", $chunkContent);

            if (!$result) {
                throw new \Exception('Failed to store chunk to disk');
            }

            // Check if all chunks are uploaded
            $allChunksUploaded = true;
            for ($i = 0; $i < $totalChunks; $i++) {
                if (!Storage::disk('local')->exists("{$chunkDir}/chunk_{$i}")) {
                    $allChunksUploaded = false;
                    break;
                }
            }

            if ($allChunksUploaded) {
                \Log::info("All {$totalChunks} chunks uploaded, merging...");
                // All chunks uploaded, merge them
                return $this->mergeChunks($fileId, $totalChunks, $importType);
            }

            return response()->json([
                'success' => true,
                'chunkIndex' => $chunkIndex,
                'message' => "Chunk {$chunkIndex} of {$totalChunks} received"
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::error('Validation error in uploadChunk: ' . json_encode($e->errors()));
            return response()->json([
                'success' => false,
                'message' => 'Validation error: ' . json_encode($e->errors())
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Error in uploadChunk: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            return response()->json([
                'success' => false,
                'message' => 'Error uploading chunk: ' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * Merge chunks and process the file
     */
    private function mergeChunks(string $fileId, int $totalChunks, string $importType)
    {
        $uploadDir = "temp/uploads/{$fileId}";
        $mergedPath = "temp/uploads/{$fileId}/merged";
        $storagePath = storage_path('app');

        try {
            // Get the storage path
            $mergedFilePath = "{$storagePath}/{$mergedPath}";

            \Log::info("Starting merge: {$totalChunks} chunks, import type: {$importType}");

            // Ensure we can write
            if (!is_dir(dirname($mergedFilePath))) {
                mkdir(dirname($mergedFilePath), 0755, true);
            }

            // Merge chunks using direct file operations for binary safety
            $mergedHandle = fopen($mergedFilePath, 'wb');
            if (!$mergedHandle) {
                throw new \Exception('Could not open merged file for writing at: ' . $mergedFilePath);
            }

            $totalSize = 0;
            for ($i = 0; $i < $totalChunks; $i++) {
                $chunkPath = "{$storagePath}/{$uploadDir}/chunk_{$i}";

                if (!file_exists($chunkPath)) {
                    fclose($mergedHandle);
                    throw new \Exception("Missing chunk {$i} at: {$chunkPath}");
                }

                $chunkSize = filesize($chunkPath);
                \Log::info("Merging chunk {$i}: {$chunkSize} bytes");

                $chunkHandle = fopen($chunkPath, 'rb');
                if (!$chunkHandle) {
                    fclose($mergedHandle);
                    throw new \Exception("Could not read chunk {$i}");
                }

                // Copy chunk data in 1MB blocks
                while (!feof($chunkHandle)) {
                    $chunk = fread($chunkHandle, 1024 * 1024); // 1MB at a time
                    if ($chunk === false) {
                        break;
                    }
                    $written = fwrite($mergedHandle, $chunk, strlen($chunk));
                    if ($written === false) {
                        fclose($chunkHandle);
                        fclose($mergedHandle);
                        throw new \Exception("Error writing merged data");
                    }
                    $totalSize += $written;
                }

                fclose($chunkHandle);
            }

            fclose($mergedHandle);

            \Log::info("Merge complete: {$totalSize} bytes written");

            // Determine file extension for proper processing
            $firstChunkPath = "{$storagePath}/{$uploadDir}/chunk_0";
            $extension = $this->detectFileType($firstChunkPath);

            \Log::info("Detected file type: {$extension}");

            // Rename to proper extension
            $finalPath = "{$mergedPath}.{$extension}";
            $finalFilePath = "{$storagePath}/{$finalPath}";

            if (file_exists($finalFilePath)) {
                unlink($finalFilePath);
            }

            if (!rename($mergedFilePath, $finalFilePath)) {
                throw new \Exception("Could not rename merged file");
            }

            // Retrieve original filename
            $filenameFile = "{$storagePath}/{$uploadDir}/filename.txt";
            $originalFilename = file_exists($filenameFile) ? trim(file_get_contents($filenameFile)) : "uploaded_file";

            \Log::info("Processing file: {$finalFilePath}, original filename: {$originalFilename}");

            // Create import record
            $import = Import::create([
                'type' => $importType,
                'filename' => $originalFilename,
                'file_size' => filesize($finalFilePath),
                'status' => 'processing',
                'import_started_at' => now(),
            ]);

            \Log::info("Import record created", ['import_id' => $import->id]);

            $tempDir = "{$storagePath}/{$uploadDir}";
            \Log::info('Before ProcessImportJob execution', [
                'import_id' => $import->id,
                'finalFilePath' => $finalFilePath,
                'file_exists' => file_exists($finalFilePath),
                'file_size' => file_exists($finalFilePath) ? filesize($finalFilePath) : 'N/A',
                'tempDir' => $tempDir,
                'tempDir_exists' => is_dir($tempDir),
                'importType' => $importType,
            ]);

            // Execute processing job synchronously (no queue worker available on Azure)
            // The job's handle() method will:
            // - Set 30-minute time limit
            // - Process the file
            // - Update Import record with results
            // - Clean up temp directory in finally block
            $job = new ProcessImportJob($finalFilePath, $importType, $originalFilename, $import->id, $tempDir);
            $job->handle();

            \Log::info('ProcessImportJob executed successfully', [
                'import_id' => $import->id,
            ]);

            // Refresh import record to get updated counts
            $import->refresh();

            return response()->json([
                'success' => true,
                'message' => 'File processing completed',
                'import_id' => $import->id,
                'imported' => $import->imported_count ?? 0,
                'duplicates' => $import->duplicate_count ?? 0,
                'errors' => $import->error_count ?? 0,
            ]);
        } catch (\Exception $e) {
            \Log::error('Error in mergeChunks: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            $this->cleanupTempDirectory("{$storagePath}/{$uploadDir}");
            return response()->json([
                'success' => false,
                'message' => 'Error merging chunks: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Detect file type from magic bytes
     */
    private function detectFileType(string $filePath)
    {
        $handle = fopen($filePath, 'rb');
        $bytes = fread($handle, 8);
        fclose($handle);

        // PDF magic bytes
        if (strpos($bytes, '%PDF') === 0) {
            return 'pdf';
        }

        // CSV/text - just assume csv for text files
        return 'csv';
    }

    /**
     * Cleanup temporary directory
     */
    private function cleanupTempDirectory(string $dir)
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($dir);
    }

    /**
     * Process VieFund CSV file (same logic as ImportController)
     */
    private function processVieFundFile(string $filePath, string $originalFilename = 'uploaded_file')
    {
        if (!file_exists($filePath)) {
            return ['success' => false, 'message' => 'File not found after merge'];
        }

        \Log::info("Starting VieFund processing", ['file' => $filePath, 'filename' => $originalFilename]);

        $import = Import::create([
            'type' => 'viefund',
            'filename' => $originalFilename,
            'file_size' => filesize($filePath),
            'status' => 'processing',
            'import_started_at' => now(),
        ]);

        try {
            $handle = fopen($filePath, 'r');
            if (!$handle) {
                throw new \Exception('Could not open file');
            }

            \Log::info("File opened for reading");

            // Read header
            $header = fgetcsv($handle);
            $normalizeHeader = fn($value) => preg_replace(
                '/[^a-z0-9]/',
                '',
                strtolower(trim((string) $value))
            );

            $headerMap = [];
            foreach ($header as $index => $label) {
                $headerMap[$normalizeHeader($label)] = $index;
            }

            \Log::info("Header parsed", ['column_count' => count($headerMap)]);

            $imported = 0;
            $duplicates = 0;
            $errors = [];
            $batch = [];
            $batchSize = 500;
            $existingHashes = [];
            $lineNumber = 1;

            // Check for format
            $isNewFormat = isset($headerMap['trxid']) || isset($headerMap['fundtrxtype']);
            \Log::info("Format detected", ['isNewFormat' => $isNewFormat]);

            while (($data = fgetcsv($handle)) !== false) {
                $lineNumber++;

                $nonEmpty = array_filter($data, fn($v) => trim((string) $v) !== '');
                if (empty($nonEmpty) || strpos(strtolower((string) ($data[0] ?? '')), 'total') !== false) {
                    continue;
                }

                try {
                    $getValue = fn(string $key) => $data[$headerMap[$key] ?? null] ?? null;

                    if ($isNewFormat) {
                        $recordData = [
                            'client_name' => $this->cleanString($getValue('clientname')),
                            'rep_code' => $this->cleanString($getValue('repcode')),
                            'plan_description' => $this->cleanString($getValue('plandescription')),
                            'institution' => $this->cleanString($getValue('institution')),
                            'account_id' => $this->cleanString($getValue('accountid')),
                            'trx_id' => $this->cleanString($getValue('trxid')),
                            'created_date' => $this->parseDateTime($getValue('createddate')),
                            'trx_type' => $this->cleanString($getValue('trxtype')),
                            'trade_date' => $this->parseDate($getValue('tradedate')),
                            'settlement_date' => $this->parseDate($getValue('settlementdate')),
                            'processing_date' => $this->parseDate($getValue('processingdate')),
                            'source_id' => $this->cleanString($getValue('sourceid')),
                            'status' => $this->cleanString($getValue('status')),
                            'amount' => $this->parseAmount($getValue('amount') ?? '0'),
                            'balance' => $this->parseAmount($getValue('balance') ?? '0'),
                            'fund_code' => $this->cleanString($getValue('fundcode')),
                            'fund_trx_type' => $this->cleanString($getValue('fundtrxtype')),
                            'fund_trx_amount' => $this->parseAmount($getValue('fundtrxamount') ?? '0'),
                            'fund_settlement_source' => $this->cleanString($getValue('fundsettlementsource')),
                            'fund_wo_number' => $this->cleanString($getValue('fundwo')),
                            'fund_source_id' => $this->cleanString($getValue('fundsourceid')),
                            'import_id' => $import->id,
                        ];
                    } else {
                        $recordData = [
                            'client_name' => $this->cleanString($getValue('clientname')),
                            'rep_code' => $this->cleanString($getValue('repcode')),
                            'plan_description' => $this->cleanString($getValue('plandescription')),
                            'institution' => $this->cleanString($getValue('institution')),
                            'account_id' => $this->cleanString($getValue('accountid')),
                            'status' => $this->cleanString($getValue('status')),
                            'available_cad' => $this->parseAmount($getValue('availablecad') ?? '0'),
                            'balance_cad' => $this->parseAmount($getValue('balancecad') ?? '0'),
                            'currency' => $this->cleanString($getValue('currency')),
                            'import_id' => $import->id,
                        ];
                    }

                    $hashData = $recordData;
                    unset($hashData['import_id']);
                    $record_hash = hash('sha256', json_encode($hashData));

                    if (!isset($existingHashes[$record_hash])) {
                        $existingHashes[$record_hash] = true;
                        $recordData['record_hash'] = $record_hash;
                        $batch[] = $recordData;

                        if (count($batch) >= $batchSize) {
                            \Log::info("Inserting batch", ['batch_size' => count($batch), 'total_imported' => $imported]);
                            VieFundTransaction::insert($batch);
                            $imported += count($batch);
                            \Log::info("Batch inserted successfully", ['total_imported' => $imported]);
                            $batch = [];
                        }
                    } else {
                        $duplicates++;
                    }

                    // Log progress every 10000 lines
                    if (($lineNumber - 1) % 10000 === 0 && $lineNumber > 1) {
                        \Log::info("Processing progress", ['lines_read' => $lineNumber, 'imported' => $imported, 'duplicates' => $duplicates]);
                    }
                } catch (\Exception $e) {
                    $errors[] = "Line {$lineNumber}: " . $e->getMessage();
                }
            }

            if (!empty($batch)) {
                \Log::info("Inserting final batch", ['batch_size' => count($batch), 'total_imported' => $imported]);
                VieFundTransaction::insert($batch);
                $imported += count($batch);
                \Log::info("Final batch inserted", ['total_imported' => $imported]);
            }

            fclose($handle);

            \Log::info("VieFund processing complete", ['imported' => $imported, 'duplicates' => $duplicates, 'errors' => count($errors)]);

            $import->update([
                'status' => 'completed',
                'imported_count' => $imported,
                'duplicate_count' => $duplicates,
                'error_count' => count($errors),
                'import_completed_at' => now(),
                'error_details' => empty($errors) ? null : implode("\n", array_slice($errors, 0, 10)),
            ]);

            return [
                'success' => true,
                'message' => "Import completed successfully",
                'imported' => $imported,
                'duplicates' => $duplicates,
                'errors' => count($errors),
            ];
        } catch (\Exception $e) {
            \Log::error("Error in VieFund processing", ['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
            $import->update([
                'status' => 'failed',
                'error_details' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Error processing file: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Process Fundserv CSV file
     */
    private function processFundservFile(string $filePath, string $originalFilename = 'uploaded_file')
    {
        if (!file_exists($filePath)) {
            return ['success' => false, 'message' => 'File not found after merge'];
        }

        \Log::info("Starting Fundserv processing", ['file' => $filePath, 'filename' => $originalFilename]);

        $import = Import::create([
            'type' => 'fundserv',
            'filename' => $originalFilename,
            'file_size' => filesize($filePath),
            'status' => 'processing',
            'import_started_at' => now(),
        ]);

        try {
            $handle = fopen($filePath, 'r');
            if (!$handle) {
                throw new \Exception('Could not open file');
            }

            \Log::info("File opened for reading");

            $header = fgetcsv($handle);
            $normalizeHeader = fn($value) => preg_replace(
                '/[^a-z0-9]/',
                '',
                strtolower(trim((string) $value))
            );

            $headerMap = [];
            foreach ($header as $index => $label) {
                $headerMap[$normalizeHeader($label)] = $index;
            }

            \Log::info("Header parsed", ['column_count' => count($headerMap)]);

            $imported = 0;
            $duplicates = 0;
            $errors = [];
            $batch = [];
            $batchSize = 500;
            $existingHashes = [];
            $lineNumber = 1;

            while (($data = fgetcsv($handle)) !== false) {
                $lineNumber++;

                $nonEmpty = array_filter($data, fn($v) => trim((string) $v) !== '');
                if (empty($nonEmpty) || strpos(strtolower((string) ($data[0] ?? '')), 'total') !== false) {
                    continue;
                }

                try {
                    $getValue = fn(string $key) => $data[$headerMap[$key] ?? null] ?? null;

                    $recordData = [
                        'company' => $this->cleanString($getValue('company')),
                        'settlement_date' => $this->parseDate($getValue('settlementdate')),
                        'code' => $this->cleanString($getValue('code')),
                        'src' => $this->cleanString($getValue('src')),
                        'trade_date' => $this->parseDate($getValue('tradedate')),
                        'fund_id' => $this->cleanString($getValue('fundid')),
                        'dealer_account_id' => $this->cleanString($getValue('dealeraccountid')),
                        'order_id' => $this->cleanString($getValue('orderid')),
                        'source_identifier' => $this->cleanString($getValue('sourceidentifier')),
                        'tx_type' => $this->cleanString($getValue('txtype')),
                        'settlement_amt' => $this->parseAmount($getValue('settlementamt') ?? '0'),
                        'actual_amount' => $this->parseAmount($getValue('actualamount') ?? $getValue('actualam') ?? '0'),
                        'import_id' => $import->id,
                    ];

                    $hashData = $recordData;
                    unset($hashData['import_id']);
                    $record_hash = hash('sha256', json_encode($hashData));

                    if (!isset($existingHashes[$record_hash])) {
                        $existingHashes[$record_hash] = true;
                        $recordData['record_hash'] = $record_hash;
                        $batch[] = $recordData;

                        if (count($batch) >= $batchSize) {
                            \Log::info("Inserting batch", ['batch_size' => count($batch), 'total_imported' => $imported]);
                            FundservTransaction::insert($batch);
                            $imported += count($batch);
                            \Log::info("Batch inserted successfully", ['total_imported' => $imported]);
                            $batch = [];
                        }
                    } else {
                        $duplicates++;
                    }

                    // Log progress every 10000 lines
                    if (($lineNumber - 1) % 10000 === 0 && $lineNumber > 1) {
                        \Log::info("Processing progress", ['lines_read' => $lineNumber, 'imported' => $imported, 'duplicates' => $duplicates]);
                    }
                } catch (\Exception $e) {
                    $errors[] = "Line {$lineNumber}: " . $e->getMessage();
                }
            }

            if (!empty($batch)) {
                \Log::info("Inserting final batch", ['batch_size' => count($batch), 'total_imported' => $imported]);
                FundservTransaction::insert($batch);
                $imported += count($batch);
                \Log::info("Final batch inserted", ['total_imported' => $imported]);
            }

            fclose($handle);

            \Log::info("Fundserv processing complete", ['imported' => $imported, 'duplicates' => $duplicates, 'errors' => count($errors)]);

            $import->update([
                'status' => 'completed',
                'imported_count' => $imported,
                'duplicate_count' => $duplicates,
                'error_count' => count($errors),
                'import_completed_at' => now(),
                'error_details' => empty($errors) ? null : implode("\n", array_slice($errors, 0, 10)),
            ]);

            return [
                'success' => true,
                'message' => "Import completed successfully",
                'imported' => $imported,
                'duplicates' => $duplicates,
                'errors' => count($errors),
            ];
        } catch (\Exception $e) {
            $import->update([
                'status' => 'failed',
                'error_details' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Error processing file: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Process Bank PDF/CSV file
     */
    private function processBankFile(string $filePath, string $originalFilename = 'uploaded_file')
    {
        if (!file_exists($filePath)) {
            return ['success' => false, 'message' => 'File not found after merge'];
        }

        $fileSize = filesize($filePath);
        $fileExt = pathinfo($filePath, PATHINFO_EXTENSION);

        \Log::info("Processing bank file: {$filePath}, size: {$fileSize}, extension: {$fileExt}");

        $import = Import::create([
            'type' => 'bank',
            'filename' => $originalFilename,
            'file_size' => $fileSize,
            'status' => 'processing',
            'import_started_at' => now(),
        ]);

        try {
            $imported = 0;
            $duplicateCount = 0;
            $errors = [];
            $batch = [];
            $batchSize = 500;

            // Check if it's a PDF
            if (strtolower($fileExt) === 'pdf') {
                \Log::info("Processing as PDF");
                try {
                    $parser = new Parser();
                    $pdf = $parser->parseFile($filePath);
                    $text = $pdf->getText();

                    \Log::info("PDF text extracted, length: " . strlen($text));

                    // Remove null bytes and control characters from entire text
                    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $text);

                    \Log::info("Text sanitized, length: " . strlen($text));

                    // Simple parsing - extract transaction lines
                    $lines = explode("\n", $text);
                    \Log::info("Total lines found: " . count($lines));

                    // Log first 10 lines for debugging
                    $sampleLines = array_slice(array_filter(array_map('trim', $lines)), 0, 10);
                    \Log::info("Sample PDF lines: " . json_encode($sampleLines));

                    // Find where transactions start (usually after header section)
                    $startLine = 0;
                    foreach ($lines as $i => $line) {
                        if (preg_match('/^(Date|Date\s+Description|Posting\s+Date|Transaction\s+Date)/i', trim($line))) {
                            $startLine = $i + 1;
                            break;
                        }
                        // For CIBC, transactions often start after "Account Summary" or a date range line
                        if (preg_match('/^For\s+(\w+\s+\d+)\s+to\s+(\w+\s+\d+)/i', trim($line))) {
                            $startLine = $i + 5; // Skip a few lines after the date range
                            break;
                        }
                    }

                    \Log::info("Transaction section starts around line: {$startLine}");

                    $transactionCount = 0;
                    $skippedLines = [];
                    foreach ($lines as $lineNum => $line) {
                        // Skip header section
                        if ($lineNum < $startLine) {
                            continue;
                        }

                        $line = trim($line);
                        if (empty($line) || strlen($line) < 5) {
                            continue;
                        }

                        try {
                            // Skip non-transaction lines more carefully
                            if (preg_match('/^(Page|-----|[=]+|Cheques|Deposits|Transfers|Balance|Total|Statement|Opening|Closing|Summary|Beginning|Ending|Subtotal|Debit|Credit)\s*$/i', $line)) {
                                continue;
                            }

                            // CIBC format: typically has date at start, description in middle, amount at end
                            // Look for patterns like: "Nov 15" or "11/15" or "2025-11-15"
                            $hasDate = preg_match('/(\d{1,2}\/\d{1,2}|Nov|December|January|February|March|April|May|June|July|August|September|October|Nov|Dec|Jan|Feb|Mar|Apr|Jun|Jul|Aug|Sep)\s+(\d{1,2}|(\d{4}-\d{2}-\d{2}))/i', $line, $dateMatch);

                            if ($hasDate) {
                                // Skip balance/opening/closing lines (they shouldn't be transactions)
                                if (preg_match('/(opening|closing|balance forward)\s/i', $line)) {
                                    if (count($skippedLines) < 5) {
                                        $skippedLines[] = "Skipped: " . substr($line, 0, 80);
                                    }
                                    continue;
                                }

                                // Look for numeric amounts (currency values) - typically at end of line
                                $hasAmount = preg_match('/\d+\.\d{2}/', $line);

                                if ($hasAmount) {
                                    $amount = 0;
                                    // Try to find a numeric amount
                                    if (preg_match('/(?:[\$])?(\d+(?:,\d{3})*(?:\.\d{2})?)\s*(?:CR|DR|$)/i', $line, $amountMatch)) {
                                        $cleaned = str_replace(['$', ','], '', $amountMatch[1]);
                                        $amount = (float)$cleaned;
                                    }

                                    // Try to parse the date
                                    $parsedDate = null;

                                    // Try various date formats
                                    $dateFormats = [
                                        'd/m/Y',
                                        'm/d/Y',
                                        'Y-m-d',
                                        'd-m-Y',
                                        'j M Y',
                                        'd M Y',
                                        'M d, Y',
                                    ];

                                    foreach ($dateFormats as $format) {
                                        try {
                                            $parsedDate = \Carbon\Carbon::createFromFormat($format, $dateMatch[0]);
                                            break;
                                        } catch (\Exception $e) {
                                            continue;
                                        }
                                    }

                                    // If no format worked, try parsing naturally
                                    if (!$parsedDate) {
                                        try {
                                            $parsedDate = \Carbon\Carbon::parse($dateMatch[0]);
                                        } catch (\Exception $e) {
                                            $parsedDate = null;
                                        }
                                    }

                                    // Generate hash for duplicate detection
                                    $hashInput = json_encode([
                                        'date' => $parsedDate?->toDateString(),
                                        'description' => substr($line, 0, 255),
                                        'amount' => $amount,
                                    ]);
                                    $recordHash = hash('sha256', $hashInput);

                                    // Check if this hash already exists in the database
                                    if (BankTransaction::where('record_hash', $recordHash)->exists()) {
                                        // Duplicate found, skip it
                                        $duplicateCount++;
                                        continue;
                                    }

                                    $batch[] = [
                                        'txn_date' => $parsedDate ?? now(),
                                        'description' => substr($line, 0, 255),
                                        'type' => 'bank_statement',
                                        'amount' => $amount,
                                        'record_hash' => $recordHash,
                                        'import_id' => $import->id,
                                        'created_at' => now(),
                                        'updated_at' => now(),
                                    ];

                                    $transactionCount++;

                                    if (count($batch) >= $batchSize) {
                                        if (!empty($batch)) {
                                            \Log::info("Inserting bank transaction batch", ['batch_size' => count($batch), 'total_imported' => $imported]);
                                            BankTransaction::insert($batch);
                                            $imported += count($batch);
                                            \Log::info("Bank batch inserted successfully", ['total_imported' => $imported]);
                                        }
                                        $batch = [];
                                    }
                                }
                            }
                        } catch (\Exception $e) {
                            $errors[] = "Line {$lineNum} parse error: " . $e->getMessage();
                            continue;
                        }
                    }

                    \Log::info("PDF processing complete: {$transactionCount} transactions found");
                    if (count($skippedLines) > 0) {
                        \Log::info("Sample skipped lines: " . json_encode($skippedLines));
                    }
                } catch (\Exception $e) {
                    \Log::error("PDF parsing error: " . $e->getMessage());
                    $errors[] = "PDF parsing error: " . $e->getMessage();
                }
            } else {
                // CSV processing
                try {
                    $handle = fopen($filePath, 'r');
                    if (!$handle) {
                        throw new \Exception('Could not open file');
                    }

                    // Skip header
                    $header = fgetcsv($handle);

                    while (($data = fgetcsv($handle)) !== false) {
                        if (count($data) >= 2) {
                            try {
                                $batch[] = [
                                    'txn_date' => $this->parseDate($data[0] ?? null) ?? now(),
                                    'description' => $this->cleanString($data[1] ?? ''),
                                    'type' => 'bank_statement',
                                    'amount' => $this->parseAmount($data[2] ?? '0'),
                                    'import_id' => $import->id,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ];

                                if (count($batch) >= $batchSize) {
                                    \Log::info("Inserting bank CSV batch", ['batch_size' => count($batch), 'total_imported' => $imported]);
                                    BankTransaction::insert($batch);
                                    $imported += count($batch);
                                    \Log::info("Bank CSV batch inserted successfully", ['total_imported' => $imported]);
                                    $batch = [];
                                }
                            } catch (\Exception $e) {
                                $errors[] = "Row parse error: " . $e->getMessage();
                                continue;
                            }
                        }
                    }
                    fclose($handle);
                } catch (\Exception $e) {
                    $errors[] = "File reading error: " . $e->getMessage();
                }
            }

            if (!empty($batch)) {
                \Log::info("Inserting final bank batch", ['batch_size' => count($batch), 'total_imported' => $imported]);
                BankTransaction::insert($batch);
                $imported += count($batch);
                \Log::info("Final bank batch inserted", ['total_imported' => $imported]);
            }

            \Log::info("Bank processing complete", ['imported' => $imported, 'duplicates' => $duplicateCount, 'errors' => count($errors)]);

            $import->update([
                'status' => 'completed',
                'imported_count' => $imported,
                'duplicate_count' => $duplicateCount,
                'error_count' => count($errors),
                'import_completed_at' => now(),
                'error_details' => empty($errors) ? null : implode("\n", array_slice($errors, 0, 10)),
            ]);

            return [
                'success' => true,
                'message' => "Import completed successfully",
                'imported' => $imported,
                'duplicates' => $duplicateCount,
                'errors' => count($errors),
                'import_id' => $import->id,
                'type' => 'bank',
            ];
        } catch (\Exception $e) {
            $import->update([
                'status' => 'failed',
                'error_details' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Error processing file: ' . $e->getMessage(),
            ];
        }
    }

    protected function cleanString($value)
    {
        return $value !== null ? trim((string) $value) : null;
    }

    protected function parseDate($value)
    {
        if (empty($value)) {
            return null;
        }

        try {
            // Remove null bytes and other control characters
            $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', trim((string) $value));

            if (empty($value)) {
                return null;
            }

            return \Carbon\Carbon::createFromFormat('m/d/Y', $value);
        } catch (\Exception $e) {
            try {
                return \Carbon\Carbon::parse($value);
            } catch (\Exception $e2) {
                return null;
            }
        }
    }

    protected function parseDateTime($value)
    {
        if (empty($value)) {
            return null;
        }

        try {
            return \Carbon\Carbon::createFromFormat('m/d/Y H:i', trim((string) $value));
        } catch (\Exception $e) {
            try {
                return \Carbon\Carbon::createFromFormat('m/d/Y H:i:s', trim((string) $value));
            } catch (\Exception $e2) {
                try {
                    return \Carbon\Carbon::parse(trim((string) $value));
                } catch (\Exception $e3) {
                    return null;
                }
            }
        }
    }

    protected function parseAmount($value)
    {
        if (empty($value)) {
            return 0;
        }

        $amount = str_replace(['$', ','], '', (string) $value);
        return floatval($amount) ?? 0;
    }
}
