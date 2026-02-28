<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\VieFundTransaction;
use App\Models\FundservTransaction;
use App\Models\BankTransaction;
use App\Models\Import;
use Smalot\PdfParser\Parser;

class ImportController extends Controller
{
    public function index()
    {
        return view('imports.index');
    }

    public function transactions(Request $request, $type = 'viefund')
    {
        // Validate type
        if (!in_array($type, ['viefund', 'fundserv', 'bank'])) {
            $type = 'viefund';
        }

        if ($type === 'viefund') {
            $query = VieFundTransaction::query();
            
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('client_name', 'like', "%{$search}%")
                      ->orWhere('rep_code', 'like', "%{$search}%")
                      ->orWhere('account_id', 'like', "%{$search}%")
                      ->orWhere('plan_description', 'like', "%{$search}%");
                });
            }
            
            $totalRecords = VieFundTransaction::count();
            $transactions = $query->orderBy('created_at', 'desc')->paginate(50);
        } elseif ($type === 'fundserv') {
            $query = FundservTransaction::query();
            
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('company', 'like', "%{$search}%")
                      ->orWhere('order_id', 'like', "%{$search}%")
                      ->orWhere('dealer_account_id', 'like', "%{$search}%")
                      ->orWhere('fund_id', 'like', "%{$search}%");
                });
            }
            
            $totalRecords = FundservTransaction::count();
            $transactions = $query->orderBy('created_at', 'desc')->paginate(50);
        } else { // bank
            $query = BankTransaction::query();

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('description', 'like', "%{$search}%")
                      ->orWhere('type', 'like', "%{$search}%");
                });
            }

            $totalRecords = BankTransaction::count();
            $transactions = $query->orderBy('txn_date', 'desc')->paginate(50);
        }

        return view('imports.transactions', compact('transactions', 'totalRecords', 'type'));
    }

    public function upload(Request $request)
    {
        $request->validate([
            'import_type' => 'required|in:viefund,fundserv,bank',
            'csv_file' => 'required|file|mimes:csv,txt,pdf|max:102400',
        ]);

        $type = $request->input('import_type');

        // Route to the appropriate upload handler
        if ($type === 'viefund') {
            return $this->vieFundUpload($request);
        } elseif ($type === 'fundserv') {
            return $this->fundservUpload($request);
        } elseif ($type === 'bank') {
            return $this->bankUpload($request);
        }

        return redirect()->route('imports.index')
            ->with('error', 'Invalid import type selected.');
    }

    public function vieFundUpload(Request $request)
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:102400',
        ]);

        $file = $request->file('csv_file');
        
        // Create import record
        $import = Import::create([
            'type' => 'viefund',
            'filename' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'status' => 'processing',
            'import_started_at' => now(),
        ]);
        
        $handle = fopen($file->getRealPath(), 'r');
        
        // Read and validate header row
        $header = fgetcsv($handle);

        $normalizeHeader = function ($value) {
            $value = strtolower(trim((string) $value));
            return preg_replace('/[^a-z0-9]/', '', $value);
        };

        $headerMap = [];
        foreach ($header as $index => $label) {
            $headerMap[$normalizeHeader($label)] = $index;
        }

        $newFormatHeaders = [
            'clientname',
            'repcode',
            'plandescription',
            'institution',
            'accountid',
            'trxid',
            'createddate',
            'trxtype',
            'tradedate',
            'settlementdate',
            'processingdate',
            'sourceid',
            'status',
            'amount',
            'balance',
            'fundcode',
            'fundtrxtype',
            'fundtrxamount',
            'fundsettlementsource',
            'fundwo',
            'fundsourceid',
        ];

        $legacyFormatHeaders = [
            'clientname',
            'repcode',
            'plandescription',
            'institution',
            'accountid',
            'status',
            'availablecad',
            'balancecad',
            'currency',
        ];

        $isNewFormat = isset($headerMap['trxid']) || isset($headerMap['fundtrxtype']);
        $isLegacyFormat = isset($headerMap['availablecad']) || isset($headerMap['balancecad']);

        // Check if this looks like a VieFund file
        if (empty($header) || (!isset($headerMap['clientname']) && !isset($headerMap['accountid'])) || (!$isNewFormat && !$isLegacyFormat)) {
            fclose($handle);
            $expectedHeaders = $isLegacyFormat ? $legacyFormatHeaders : $newFormatHeaders;
            $import->update([
                'status' => 'failed',
                'error_details' => 'Invalid file format. Expected VieFund CSV with headers similar to: ' . implode(', ', $expectedHeaders),
            ]);
            return redirect()->route('imports.index')->with('error', 'Wrong file format. This appears to be a Fundserv file. Please use the Fundserv uploader.');
        }
        
        $imported = 0;
        $duplicates = 0;
        $emptyRows = 0;
        $errors = [];
        $lineNumber = 1; // Start at 1 for header
        $batch = [];
        $batchSize = 500; // Insert 500 records at a time
        $existingHashes = []; // Cache of hashes we've seen

        while (($data = fgetcsv($handle)) !== false) {
            $lineNumber++;
            
            // Skip empty or total rows
            $nonEmpty = array_filter($data, function ($value) {
                return trim((string) $value) !== '';
            });

            if (empty($nonEmpty) || strpos(strtolower((string) ($data[0] ?? '')), 'total') !== false) {
                $emptyRows++;
                continue;
            }

            try {
                $getValue = function (string $key) use ($data, $headerMap) {
                    $index = $headerMap[$key] ?? null;
                    if ($index === null) {
                        return null;
                    }
                    return $data[$index] ?? null;
                };

                $getValueAny = function (array $keys) use ($getValue) {
                    foreach ($keys as $key) {
                        $value = $getValue($key);
                        if ($value !== null && $value !== '') {
                            return $value;
                        }
                    }
                    return null;
                };

                $getValueAny = function (array $keys) use ($getValue) {
                    foreach ($keys as $key) {
                        $value = $getValue($key);
                        if ($value !== null && $value !== '') {
                            return $value;
                        }
                    }
                    return null;
                };

                $getValueAny = function (array $keys) use ($getValue) {
                    foreach ($keys as $key) {
                        $value = $getValue($key);
                        if ($value !== null && $value !== '') {
                            return $value;
                        }
                    }
                    return null;
                };

                $getValueAny = function (array $keys) use ($getValue) {
                    foreach ($keys as $key) {
                        $value = $getValue($key);
                        if ($value !== null && $value !== '') {
                            return $value;
                        }
                    }
                    return null;
                };

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
                        'available_cad' => $this->parseAmount($getValue('amount') ?? '0'),
                        'balance_cad' => $this->parseAmount($getValue('balance') ?? '0'),
                        'currency' => null,
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
                    ];
                }

                // Generate hash for duplicate detection (exclude import_id and timestamps)
                $hashData = $recordData;
                unset($hashData['import_id'], $hashData['record_hash'], $hashData['created_at'], $hashData['updated_at']);
                $record_hash = hash('sha256', json_encode($hashData));

                // Check if we've already seen this hash in this import
                if (isset($existingHashes[$record_hash])) {
                    $duplicates++;
                    continue;
                }

                // Check if record already exists in database (only check once per unique hash)
                $existing = VieFundTransaction::where('record_hash', $record_hash)->exists();
                
                if ($existing) {
                    $duplicates++;
                    $existingHashes[$record_hash] = true;
                } else {
                    $recordData['import_id'] = $import->id;
                    $recordData['record_hash'] = $record_hash;
                    $recordData['created_at'] = now();
                    $recordData['updated_at'] = now();
                    $batch[] = $recordData;
                    $existingHashes[$record_hash] = true;
                    
                    // Insert batch when it reaches the batch size
                    if (count($batch) >= $batchSize) {
                        VieFundTransaction::insert($batch);
                        $imported += count($batch);
                        $batch = [];
                    }
                }
            } catch (\Exception $e) {
                $errors[] = "Line {$lineNumber}: " . $e->getMessage();
            }
        }

        // Insert any remaining records
        if (count($batch) > 0) {
            VieFundTransaction::insert($batch);
            $imported += count($batch);
        }

        fclose($handle);

        // Determine status - failed if no records were imported
        $status = $imported > 0 ? 'completed' : 'failed';
        $errorDetails = null;
        
        if ($imported === 0 && $duplicates > 0) {
            $errorDetails = "All {$duplicates} records were duplicates. No new data was imported.";
        } elseif (!empty($errors)) {
            $errorDetails = implode("\n", array_slice($errors, 0, 20));
        }

        // Update import record
        $import->update([
            'imported_count' => $imported,
            'duplicate_count' => $duplicates,
            'empty_row_count' => $emptyRows,
            'error_count' => count($errors),
            'error_details' => $errorDetails,
            'status' => $status,
            'import_completed_at' => now(),
        ]);

        $message = "VieFund import " . ($status === 'completed' ? 'complete' : 'failed') . ": {$imported} imported, {$duplicates} duplicates";
        if ($emptyRows > 0) {
            $message .= ", {$emptyRows} empty rows";
        }
        if (count($errors) > 0) {
            $message .= ", " . count($errors) . " errors";
        }

        return redirect()->route('imports.index')->with($status === 'completed' ? 'success' : 'error', $message);
    }

    public function bankUpload(Request $request)
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:pdf|max:102400',
        ]);

        $file = $request->file('csv_file');

        $import = Import::create([
            'type' => 'bank',
            'filename' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'status' => 'processing',
            'import_started_at' => now(),
        ]);

        $imported = 0;
        $duplicates = 0;
        $emptyRows = 0;
        $errors = [];
        $batch = [];
        $batchSize = 500;
        $existingHashes = [];

        try {
            $parser = new Parser();
            $pdf = $parser->parseFile($file->getRealPath());
            $text = $pdf->getText();
            $records = $this->parseBankStatementText($text);
        } catch (\Exception $e) {
            $import->update([
                'status' => 'failed',
                'error_details' => 'Unable to parse PDF: ' . $e->getMessage(),
                'import_completed_at' => now(),
            ]);

            return redirect()->route('imports.index')->with('error', 'Unable to parse the bank statement PDF.');
        }

        foreach ($records as $record) {
            if (empty($record['txn_date']) || empty($record['description']) || empty($record['amount'])) {
                $emptyRows++;
                continue;
            }

            try {
                $hashData = $record;
                $record_hash = hash('sha256', json_encode($hashData));

                if (isset($existingHashes[$record_hash])) {
                    $duplicates++;
                    continue;
                }

                $existing = BankTransaction::where('record_hash', $record_hash)->exists();
                if ($existing) {
                    $duplicates++;
                    $existingHashes[$record_hash] = true;
                    continue;
                }

                $record['import_id'] = $import->id;
                $record['record_hash'] = $record_hash;
                $record['created_at'] = now();
                $record['updated_at'] = now();
                $batch[] = $record;
                $existingHashes[$record_hash] = true;

                if (count($batch) >= $batchSize) {
                    BankTransaction::insert($batch);
                    $imported += count($batch);
                    $batch = [];
                }
            } catch (\Exception $e) {
                $errors[] = $e->getMessage();
            }
        }

        if (count($batch) > 0) {
            BankTransaction::insert($batch);
            $imported += count($batch);
        }

        $status = $imported > 0 ? 'completed' : 'failed';
        $errorDetails = null;

        if ($imported === 0 && $duplicates > 0) {
            $errorDetails = "All {$duplicates} records were duplicates. No new data was imported.";
        } elseif (!empty($errors)) {
            $errorDetails = implode("\n", array_slice($errors, 0, 20));
        }

        $import->update([
            'total_rows' => count($records),
            'imported_count' => $imported,
            'duplicate_count' => $duplicates,
            'empty_row_count' => $emptyRows,
            'error_count' => count($errors),
            'error_details' => $errorDetails,
            'status' => $status,
            'import_completed_at' => now(),
        ]);

        $message = "Bank import " . ($status === 'completed' ? 'complete' : 'failed') . ": {$imported} imported, {$duplicates} duplicates";
        if ($emptyRows > 0) {
            $message .= ", {$emptyRows} empty rows";
        }
        if (count($errors) > 0) {
            $message .= ", " . count($errors) . " errors";
        }

        return redirect()->route('imports.index')->with($status === 'completed' ? 'success' : 'error', $message);
    }

    public function fundservUpload(Request $request)
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:102400',
        ]);

        $file = $request->file('csv_file');
        
        // Create import record
        $import = Import::create([
            'type' => 'fundserv',
            'filename' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'status' => 'processing',
            'import_started_at' => now(),
        ]);
        
        $handle = fopen($file->getRealPath(), 'r');
        
        // Read and validate header row
        $header = fgetcsv($handle);

        $normalizeHeader = function ($value) {
            $value = strtolower(trim((string) $value));
            return preg_replace('/[^a-z0-9]/', '', $value);
        };

        $headerMap = [];
        foreach ($header as $index => $label) {
            $headerMap[$normalizeHeader($label)] = $index;
        }

        $requiredHeaders = ['company', 'settlementdate', 'tradedate', 'fundid', 'dealeraccountid', 'orderid'];
        $hasRequired = count(array_intersect($requiredHeaders, array_keys($headerMap))) >= 3;

        // Check if this looks like a Fundserv file
        if (empty($header) || !$hasRequired) {
            fclose($handle);
            $import->update([
                'status' => 'failed',
                'error_details' => 'Invalid file format. Expected Fundserv CSV with headers like: #, Company, Settlement Date, Code, Src, Trade date, FundID, Dealer Account ID, Order ID, Source Identifier, TxType, Settlement Amt',
            ]);
            return redirect()->route('imports.index')->with('error', 'Wrong file format. This appears to be a VieFund file. Please use the VieFund uploader.');
        }
        
        $imported = 0;
        $skipped = 0;
        $duplicates = 0;
        $emptyRows = 0;
        $errors = [];
        $lineNumber = 1; // Start at 1 for header
        $batch = [];
        $batchSize = 500; // Insert 500 records at a time
        $existingHashes = []; // Cache of hashes we've seen

        while (($data = fgetcsv($handle)) !== false) {
            $lineNumber++;
            
            // Skip empty rows
            $nonEmpty = array_filter($data, function ($value) {
                return trim((string) $value) !== '';
            });

            if (empty($nonEmpty)) {
                $emptyRows++;
                continue;
            }

            try {
                $getValue = function (string $key) use ($data, $headerMap) {
                    $index = $headerMap[$key] ?? null;
                    if ($index === null) {
                        return null;
                    }
                    return $data[$index] ?? null;
                };

                $getValueAny = function (array $keys) use ($getValue) {
                    foreach ($keys as $key) {
                        $value = $getValue($key);
                        if ($value !== null && $value !== '') {
                            return $value;
                        }
                    }
                    return null;
                };

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
                    'actual_amount' => $this->parseAmount($getValueAny(['actualamount', 'actualamunt']) ?? '0'),
                ];

                // Generate hash for duplicate detection (exclude import_id and timestamps)
                $hashData = [
                    'company' => $recordData['company'],
                    'settlement_date' => $recordData['settlement_date'],
                    'code' => $recordData['code'],
                    'src' => $recordData['src'],
                    'trade_date' => $recordData['trade_date'],
                    'fund_id' => $recordData['fund_id'],
                    'dealer_account_id' => $recordData['dealer_account_id'],
                    'order_id' => $recordData['order_id'],
                    'source_identifier' => $recordData['source_identifier'],
                    'tx_type' => $recordData['tx_type'],
                    'settlement_amt' => $recordData['settlement_amt'],
                    'actual_amount' => $recordData['actual_amount'],
                ];
                $record_hash = hash('sha256', json_encode($hashData));

                // Check if we've already seen this hash in this import
                if (isset($existingHashes[$record_hash])) {
                    $duplicates++;
                    continue;
                }

                // Check if record already exists in database (only check once per unique hash)
                $existing = FundservTransaction::where('record_hash', $record_hash)->exists();
                
                if ($existing) {
                    $duplicates++;
                    $existingHashes[$record_hash] = true;
                } else {
                    $recordData['import_id'] = $import->id;
                    $recordData['record_hash'] = $record_hash;
                    $recordData['created_at'] = now();
                    $recordData['updated_at'] = now();
                    $batch[] = $recordData;
                    $existingHashes[$record_hash] = true;
                    
                    // Insert batch when it reaches the batch size
                    if (count($batch) >= $batchSize) {
                        FundservTransaction::insert($batch);
                        $imported += count($batch);
                        $batch = [];
                    }
                }
            } catch (\Exception $e) {
                $skipped++;
                $errors[] = "Line {$lineNumber}: " . $e->getMessage();
            }
        }

        // Insert any remaining records
        if (count($batch) > 0) {
            FundservTransaction::insert($batch);
            $imported += count($batch);
        }

        fclose($handle);

        // Determine status - failed if no records were imported
        $status = $imported > 0 ? 'completed' : 'failed';
        $errorDetails = null;
        
        if ($imported === 0 && $duplicates > 0) {
            $errorDetails = "All {$duplicates} records were duplicates. No new data was imported.";
        } elseif (!empty($errors)) {
            $errorDetails = implode("\n", array_slice($errors, 0, 20));
        }

        // Update import record
        $import->update([
            'imported_count' => $imported,
            'duplicate_count' => $duplicates,
            'empty_row_count' => $emptyRows,
            'error_count' => $skipped,
            'error_details' => $errorDetails,
            'status' => $status,
            'import_completed_at' => now(),
        ]);

        $message = "Fundserv import " . ($status === 'completed' ? 'complete' : 'failed') . ": {$imported} imported, {$duplicates} duplicates";
        if ($emptyRows > 0) {
            $message .= ", {$emptyRows} empty rows";
        }
        if ($skipped > 0) {
            $message .= ", {$skipped} errors";
        }
        
        if (!empty($errors) && count($errors) > 0) {
            $message .= "\n\nErrors:\n" . implode("\n", array_slice($errors, 0, 10));
            if (count($errors) > 10) {
                $message .= "\n... and " . (count($errors) - 10) . " more errors";
            }
        }

        return redirect()->route('imports.index')->with($status === 'completed' ? 'success' : 'error', $message);
    }

    private function parseAmount($value)
    {
        // Remove currency symbols and commas
        $cleaned = preg_replace('/[^0-9.-]/', '', $value);
        return floatval($cleaned);
    }

    private function parseDate($value)
    {
        if (empty($value)) {
            return null;
        }

        try {
            $trimmed = trim((string) $value);
            if (str_contains($trimmed, '/')) {
                return \Carbon\Carbon::createFromFormat('m/d/Y', $trimmed)->format('Y-m-d');
            }
            return \Carbon\Carbon::parse($trimmed)->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }

    private function parseDateTime($value)
    {
        if (empty($value)) {
            return null;
        }

        try {
            $trimmed = trim((string) $value);
            if (str_contains($trimmed, '/')) {
                return \Carbon\Carbon::createFromFormat('m/d/Y', $trimmed)->format('Y-m-d H:i:s');
            }
            return \Carbon\Carbon::parse($trimmed)->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return null;
        }
    }

    private function cleanString($value)
    {
        if (empty($value)) {
            return null;
        }

        // Replace non-breaking spaces and other problematic characters
        $cleaned = str_replace(["\xA0", "\xC2\xA0"], ' ', $value);
        
        // Remove any remaining non-printable characters except regular spaces
        $cleaned = preg_replace('/[^\x20-\x7E\x0A\x0D]/', '', $cleaned);
        
        // Trim whitespace
        $cleaned = trim($cleaned);
        
        return $cleaned ?: null;
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
            if (str_contains($lower, 'transaction details')
                || str_contains($lower, 'account summary')
                || str_contains($lower, 'account statement')
                || str_contains($lower, 'continued on next page')
                || (str_contains($lower, 'withdrawals') && str_contains($lower, 'deposits') && str_contains($lower, 'balance'))
                || str_contains($lower, 'opening balance')
                || str_contains($lower, 'balance forward')) {
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
                'type' => $this->detectBankTransactionType($description),
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
            return \Carbon\Carbon::createFromFormat('M j Y', trim($dateText) . ' ' . $year)->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }

    private function normalizeBankDescription(string $description): string
    {
        return preg_replace('/\s{2,}/', ' ', trim($description));
    }

    private function detectBankTransactionType(string $description): string
    {
        $lower = strtolower($description);
        if (preg_match('/\b(credit|deposit|preauth credit|interest|payment received|pay)\b/i', $lower)) {
            return 'deposit';
        }

        if (preg_match('/\b(debit|withdrawal|payment|memo|fee|wire|eft)\b/i', $lower)) {
            return 'withdrawal';
        }

        return 'withdrawal';
    }

    // Transaction Management Methods
    
    public function vieFundTransactions(Request $request)
    {
        $query = VieFundTransaction::query();
        
        // Search functionality
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('client_name', 'like', "%{$search}%")
                  ->orWhere('rep_code', 'like', "%{$search}%")
                  ->orWhere('account_id', 'like', "%{$search}%")
                  ->orWhere('plan_description', 'like', "%{$search}%");
            });
        }
        
        $transactions = $query->orderBy('created_at', 'desc')->paginate(50);
        $totalRecords = VieFundTransaction::count();
        
        return view('imports.viefund-transactions', compact('transactions', 'totalRecords'));
    }
    
    public function fundservTransactions(Request $request)
    {
        $query = FundservTransaction::query();
        
        // Search functionality
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('company', 'like', "%{$search}%")
                  ->orWhere('order_id', 'like', "%{$search}%")
                  ->orWhere('dealer_account_id', 'like', "%{$search}%")
                  ->orWhere('fund_id', 'like', "%{$search}%");
            });
        }
        
        $transactions = $query->orderBy('created_at', 'desc')->paginate(50);
        $totalRecords = FundservTransaction::count();
        
        return view('imports.fundserv-transactions', compact('transactions', 'totalRecords'));
    }
    
    public function bankTransactions(Request $request)
    {
        $query = BankTransaction::query();

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                  ->orWhere('type', 'like', "%{$search}%");
            });
        }

        $transactions = $query->orderBy('txn_date', 'desc')->paginate(50);
        $totalRecords = BankTransaction::count();
        
        return view('imports.bank-transactions', compact('transactions', 'totalRecords'));
    }
    
    public function deleteVieFund($id)
    {
        $transaction = VieFundTransaction::findOrFail($id);
        $transaction->delete();
        
        return redirect()->back()->with('success', 'Transaction deleted successfully.');
    }
    
    public function deleteFundserv($id)
    {
        $transaction = FundservTransaction::findOrFail($id);
        $transaction->delete();
        
        return redirect()->back()->with('success', 'Transaction deleted successfully.');
    }
    
    public function truncateVieFund()
    {
        VieFundTransaction::truncate();
        
        return redirect()->route('imports.viefund.transactions')->with('success', 'All VieFund transactions deleted.');
    }
    
    public function truncateFundserv()
    {
        FundservTransaction::truncate();
        
        return redirect()->route('imports.fundserv.transactions')->with('success', 'All Fundserv transactions deleted.');
    }

    public function truncateBank()
    {
        BankTransaction::truncate();

        return redirect()->route('imports.bank.transactions')->with('success', 'All bank transactions deleted.');
    }
    
    public function history()
    {
        $imports = Import::orderBy('created_at', 'desc')->paginate(25);
        
        return view('imports.history', compact('imports'));
    }
    
    public function deleteImport($id)
    {
        $import = Import::findOrFail($id);
        $import->delete();
        
        return redirect()->route('imports.history')->with('success', 'Import record deleted successfully.');
    }
    
    public function viewImport($id)
    {
        $import = Import::findOrFail($id);
        
        if ($import->type == 'viefund') {
            $transactions = VieFundTransaction::where('import_id', $id)->paginate(50);
            return view('imports.view-import', compact('import', 'transactions'));
        } elseif ($import->type == 'fundserv') {
            $transactions = FundservTransaction::where('import_id', $id)->paginate(50);
            return view('imports.view-import', compact('import', 'transactions'));
        } else {
            $transactions = BankTransaction::where('import_id', $id)->paginate(50);
            return view('imports.view-import', compact('import', 'transactions'));
        }
    }
}
