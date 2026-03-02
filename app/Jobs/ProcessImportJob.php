<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use App\Models\VieFundTransaction;
use App\Models\FundservTransaction;
use App\Models\BankTransaction;
use App\Models\Import;
use Smalot\PdfParser\Parser;
use Carbon\Carbon;

class ProcessImportJob implements ShouldQueue
{
    use Queueable, SerializesModels;

    protected $filePath;
    protected $importType;
    protected $originalFilename;
    protected $importId;
    protected $tempDir;

    public function __construct($filePath, $importType, $originalFilename, $importId, $tempDir = null)
    {
        \Log::info("ProcessImportJob constructor called", [
            'filePath' => $filePath,
            'importType' => $importType,
            'originalFilename' => $originalFilename,
            'importId' => $importId,
            'tempDir' => $tempDir,
        ]);
        
        $this->filePath = $filePath;
        $this->importType = $importType;
        $this->originalFilename = $originalFilename;
        $this->importId = $importId;
        $this->tempDir = $tempDir;
    }

    public function handle()
    {
        set_time_limit(1800); // 30 minutes

        \Log::info("ProcessImportJob handle method called", [
            'file' => $this->filePath,
            'type' => $this->importType,
            'import_id' => $this->importId,
            'tempDir' => $this->tempDir,
            'file_exists' => file_exists($this->filePath ?? ''),
        ]);

        try {
            \Log::info("ProcessImportJob started", [
                'file' => $this->filePath,
                'type' => $this->importType,
                'import_id' => $this->importId,
                'tempDir' => $this->tempDir,
            ]);

            $import = Import::find($this->importId);
            if (!$import) {
                \Log::error("Import record not found", ['import_id' => $this->importId]);
                return;
            }
            
            \Log::info("Import record found", ['import_id' => $this->importId, 'status' => $import->status]);

            if (!file_exists($this->filePath)) {
                \Log::error("File not found at path", [
                    'path' => $this->filePath,
                    'expected_dir' => dirname($this->filePath),
                    'dir_exists' => is_dir(dirname($this->filePath)),
                    'files_in_dir' => file_exists(dirname($this->filePath)) ? implode(', ', array_slice(glob(dirname($this->filePath) . '/*'), 0, 10)) : 'dir not found',
                ]);
                
                $import->update([
                    'status' => 'failed',
                    'error_details' => "File not found: {$this->filePath}",
                ]);
                return;
            }

            \Log::info("File found, processing", [
                'path' => $this->filePath,
                'size' => filesize($this->filePath),
                'type' => $this->importType,
            ]);

            // Process based on type
            if ($this->importType === 'viefund') {
                $result = $this->processVieFundFile();
            } elseif ($this->importType === 'fundserv') {
                $result = $this->processFundservFile();
            } elseif ($this->importType === 'bank') {
                $result = $this->processBankFile();
            } else {
                throw new \Exception('Invalid import type: ' . $this->importType);
            }

            \Log::info("ProcessImportJob completed", [
                'result' => $result,
                'import_id' => $this->importId,
            ]);

            // Update import with results
            $import->update([
                'status' => 'completed',
                'imported_count' => $result['imported'] ?? 0,
                'duplicate_count' => $result['duplicates'] ?? 0,
                'error_count' => $result['errors'] ?? 0,
                'import_completed_at' => now(),
            ]);

        } catch (\Exception $e) {
            \Log::error("Error in ProcessImportJob", [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'import_id' => $this->importId,
            ]);

            $import = Import::find($this->importId);
            if ($import) {
                $import->update([
                    'status' => 'failed',
                    'error_details' => $e->getMessage(),
                ]);
            }
        } finally {
            // Clean up temp directory after job completes
            if ($this->tempDir && is_dir($this->tempDir)) {
                \Log::info("Cleaning up temp directory", ['dir' => $this->tempDir]);
                $this->cleanupTempDirectory($this->tempDir);
            }
        }
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

    private function processVieFundFile()
    {
        \Log::info("processVieFundFile started", ['path' => $this->filePath, 'import_id' => $this->importId]);
        
        $handle = fopen($this->filePath, 'r');
        if (!$handle) {
            \Log::error("Could not open file for reading", ['path' => $this->filePath]);
            throw new \Exception('Could not open file');
        }

        \Log::info("File opened successfully");

        $header = fgetcsv($handle);
        if (!$header) {
            \Log::error("Could not read header from CSV");
            fclose($handle);
            throw new \Exception('Could not read CSV header');
        }
        
        \Log::info("CSV header read", ['column_count' => count($header)]);
        
        $normalizeHeader = fn($value) => preg_replace(
            '/[^a-z0-9]/',
            '',
            strtolower(trim((string) $value))
        );

        $headerMap = [];
        foreach ($header as $index => $label) {
            $headerMap[$normalizeHeader($label)] = $index;
        }

        $imported = 0;
        $duplicates = 0;
        $errors = [];
        $batch = [];
        $batchSize = 500;
        $existingHashes = [];
        $lineNumber = 1;

        $isNewFormat = isset($headerMap['trxid']) || isset($headerMap['fundtrxtype']);

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
                        'import_id' => $this->importId,
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
                        'import_id' => $this->importId,
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

        return [
            'imported' => $imported,
            'duplicates' => $duplicates,
            'errors' => count($errors),
        ];
    }

    private function processFundservFile()
    {
        // Similar logic to processVieFundFile but for Fundserv
        $handle = fopen($this->filePath, 'r');
        if (!$handle) {
            throw new \Exception('Could not open file');
        }

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
                    'import_id' => $this->importId,
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

        return [
            'imported' => $imported,
            'duplicates' => $duplicates,
            'errors' => count($errors),
        ];
    }

    private function processBankFile()
    {
        \Log::info("processBankFile started", ['path' => $this->filePath, 'import_id' => $this->importId]);
        
        $fileSize = filesize($this->filePath);
        $fileExt = pathinfo($this->filePath, PATHINFO_EXTENSION);
        
        \Log::info("Processing bank file", ['size' => $fileSize, 'extension' => $fileExt]);

        $imported = 0;
        $duplicateCount = 0;
        $errors = [];
        $batch = [];
        $batchSize = 500;
        $existingHashes = [];

        try {
            if (strtolower($fileExt) === 'pdf') {
                \Log::info("Processing as PDF");
                try {
                    $parser = new Parser();
                    $pdf = $parser->parseFile($this->filePath);
                    $text = $pdf->getText();
                    
                    \Log::info("PDF text extracted", ['length' => strlen($text)]);

                    $records = $this->parseBankStatementText($text);
                    \Log::info("PDF processing complete", ['transactions_found' => count($records)]);

                    foreach ($records as $record) {
                        if (empty($record['txn_date']) || empty($record['description']) || empty($record['amount'])) {
                            continue;
                        }

                        try {
                            $hashData = $record;
                            $record_hash = hash('sha256', json_encode($hashData));

                            if (!isset($existingHashes[$record_hash])) {
                                $existingHashes[$record_hash] = true;
                                
                                $existing = BankTransaction::where('record_hash', $record_hash)->exists();
                                if ($existing) {
                                    $duplicateCount++;
                                    continue;
                                }

                                $record['import_id'] = $this->importId;
                                $record['record_hash'] = $record_hash;
                                $record['type'] = 'bank_statement';
                                $record['created_at'] = now();
                                $record['updated_at'] = now();
                                $batch[] = $record;

                                if (count($batch) >= $batchSize) {
                                    \Log::info("Inserting bank batch", ['batch_size' => count($batch), 'total_imported' => $imported]);
                                    BankTransaction::insert($batch);
                                    $imported += count($batch);
                                    $batch = [];
                                }
                            } else {
                                $duplicateCount++;
                            }
                        } catch (\Exception $e) {
                            $errors[] = $e->getMessage();
                        }
                    }
                } catch (\Exception $e) {
                    \Log::error("PDF parsing error", ['error' => $e->getMessage()]);
                    $errors[] = "PDF parsing error: " . $e->getMessage();
                }
            } else {
                // CSV processing
                try {
                    $handle = fopen($this->filePath, 'r');
                    if (!$handle) {
                        throw new \Exception('Could not open file');
                    }

                    $header = fgetcsv($handle);
                    
                    while (($data = fgetcsv($handle)) !== false) {
                        if (count($data) >= 2) {
                            try {
                                $batch[] = [
                                    'txn_date' => $this->parseDate($data[0]) ?? now(),
                                    'description' => $this->cleanString($data[1] ?? ''),
                                    'amount' => $this->parseAmount($data[2] ?? '0'),
                                    'import_id' => $this->importId,
                                    'type' => 'bank_statement',
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ];

                                if (count($batch) >= $batchSize) {
                                    \Log::info("Inserting bank CSV batch", ['batch_size' => count($batch), 'total_imported' => $imported]);
                                    BankTransaction::insert($batch);
                                    $imported += count($batch);
                                    $batch = [];
                                }
                            } catch (\Exception $e) {
                                $errors[] = "Row error: " . $e->getMessage();
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
            }

            \Log::info("Bank processing complete", ['imported' => $imported, 'duplicates' => $duplicateCount, 'errors' => count($errors)]);

            return [
                'imported' => $imported,
                'duplicates' => $duplicateCount,
                'errors' => count($errors),
            ];
        } catch (\Exception $e) {
            \Log::error("Error in bank processing", ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function parseBankStatementText(string $text): array
    {
        $year = $this->extractStatementYear($text) ?? now()->year;
        $accountNumber = $this->extractAccountNumber($text);
        $currency = $this->extractStatementCurrency($text) ?? 'CAD';
        $lines = preg_split('/\r\n|\r|\n/', $text);
        $transactions = [];
        $currentDate = null;
        $currentDescription = '';

        foreach ($lines as $rawLine) {
            $line = trim($rawLine);
            if ($line === '') {
                continue;
            }

            $lower = strtolower($line);
            if (
                str_contains($lower, 'transaction details')
                || str_contains($lower, 'account summary')
                || str_contains($lower, 'account statement')
                || str_contains($lower, 'continued on next page')
                || (str_contains($lower, 'withdrawals') && str_contains($lower, 'deposits') && str_contains($lower, 'balance'))
                || str_contains($lower, 'opening balance')
                || str_contains($lower, 'balance forward')
            ) {
                continue;
            }

            if (preg_match('/^(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+\d{1,2}\b/i', $line, $matches)) {
                $currentDate = $this->parseStatementDate($matches[0], $year);
                $line = trim(substr($line, strlen($matches[0])));
                $currentDescription = $line;
                if ($line === '') {
                    continue;
                }
            }

            if ($currentDate === null) {
                continue;
            }

            preg_match_all('/\d{1,3}(?:,\d{3})*\.\d{2}/', $line, $amountMatches);
            $amounts = $amountMatches[0] ?? [];

            if (count($amounts) < 2) {
                $currentDescription = trim(($currentDescription ? $currentDescription . ' ' : '') . $line);
                continue;
            }

            $balance = $this->parseAmount($amounts[count($amounts) - 1]);
            $amount = $this->parseAmount($amounts[count($amounts) - 2]);
            $lineWithoutAmounts = trim(preg_replace('/\d{1,3}(?:,\d{3})*\.\d{2}/', '', $line));
            $lineWithoutAmounts = trim(preg_replace('/\s{2,}/', ' ', $lineWithoutAmounts));
            $description = trim($this->normalizeBankDescription(trim($currentDescription . ' ' . $lineWithoutAmounts)));

            if ($description === '') {
                $description = 'Transaction';
            }

            $transactions[] = [
                'account_number' => $accountNumber,
                'currency' => $currency,
                'txn_date' => $currentDate,
                'description' => $description,
                'amount' => $amount,
                'balance' => $balance,
            ];

            $currentDescription = '';
        }

        return $transactions;
    }

    private function extractStatementYear(string $text): ?int
    {
        if (preg_match('/\b(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+\d{1,2}\s+to\s+(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+\d{1,2},\s+(\d{4})/i', $text, $matches)) {
            return (int) $matches[1];
        }

        if (preg_match('/\bFor\s+(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+\d{1,2},\s+(\d{4})/i', $text, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    private function extractAccountNumber(string $text): ?string
    {
        if (preg_match('/Account number\s*\n?\s*([0-9\-]+)/i', $text, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    private function extractStatementCurrency(string $text): ?string
    {
        if (preg_match('/Closing balance\s+on\s+[^\n]+\s+(CAD|USD)\s*=/i', $text, $matches)) {
            return strtoupper($matches[1]);
        }

        return null;
    }

    private function parseStatementDate(string $dateText, int $year): ?string
    {
        try {
            return Carbon::createFromFormat('M j Y', trim($dateText) . ' ' . $year)->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }

    private function normalizeBankDescription(string $description): string
    {
        return preg_replace('/\s{2,}/', ' ', trim($description));
    }

    protected function cleanString($value)
    {
        if (null === $value) {
            return null;
        }
        return trim((string) $value) ?: null;
    }

    protected function parseDate($value)
    {
        if (empty($value)) {
            return null;
        }
        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function parseDateTime($value)
    {
        if (empty($value)) {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function parseAmount($value)
    {
        if (empty($value)) {
            return 0;
        }
        $cleaned = preg_replace('/[^0-9.-]/', '', (string) $value);
        return (float) $cleaned;
    }
}
