<?php

namespace App\Console\Commands;

use App\Models\BankStatementEntry;
use App\Models\BankStatementSummary;
use App\Models\Import;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use phpseclib3\Net\SFTP;
use RuntimeException;
use Throwable;

class ImportCibcTransactionBalanceCsvCommand extends Command
{
    private const IMPORT_TYPE = 'bank-transaction-balance-csv';

    private const CAMT_IMPORT_TYPE = 'bank-camt-raw';

    private const EXPECTED_HEADERS = [
        'Account name',
        'Account number',
        'Currency',
        'BUSINESS DATE',
        'Opening balance',
        'Description',
        'ADDITIONAL DETAILS',
        'Debit amount',
        'Credit amount',
        'Balance',
        'CLOSING BALANCE',
        'TOTAL DEBIT AMOUNT',
        'TOTAL CREDIT AMOUNT',
        'TOTAL DEBIT COUNT',
        'TOTAL CREDIT COUNT',
    ];

    protected $signature = 'import:cibc-transaction-balance-csv
        {--path=resources/data/cibc/TransactionBalance.csv : Absolute path or path relative to the application root}
        {--sftp-file= : Exact CSV filename to download temporarily from the configured bank SFTP folder, such as bank_txns_09102025-04232026.csv}
        {--remote-path= : SFTP directory override; defaults to BANK_SFTP_REMOTE_PATH}
        {--parser=v2 : Parser version for analyze:bank-entries}
        {--dry-run : Validate and preview without changing the database}
        {--replace-existing : Atomically replace a previous import of this CSV}
        {--skip-analysis : Skip analyze:bank-entries after importing}';

    protected $description = 'One-off import of a CIBC Transaction Balance CSV, with CAMT data taking priority';

    public function handle(): int
    {
        $sftpFile = $this->scalarOption('sftp-file');
        if ($sftpFile === '') {
            $path = $this->resolvePath($this->scalarOption('path', 'resources/data/cibc/TransactionBalance.csv'));

            return $this->importFile($path);
        }

        $temporaryDirectory = null;
        try {
            [$path, $temporaryDirectory] = $this->downloadFromBankSftp($sftpFile);

            return $this->importFile($path);
        } catch (Throwable $e) {
            $this->error('SFTP CSV import failed: ' . $e->getMessage());

            return self::FAILURE;
        } finally {
            if ($temporaryDirectory !== null) {
                $this->cleanupTemporaryDirectory($temporaryDirectory);
                $this->line('Removed the temporary CSV copy from the web server.');
            }
        }
    }

    private function importFile(string $path): int
    {
        if (!is_file($path) || !is_readable($path)) {
            $this->error("CSV file is not readable: {$path}");

            return self::FAILURE;
        }

        try {
            $data = $this->readAndValidateCsv($path);
            $camtCoverage = $this->loadCamtCoverage($data['days']);
        } catch (Throwable $e) {
            $this->error('CSV validation failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $selectedDays = array_diff_key($data['days'], $camtCoverage);
        $plan = $this->buildPlan($data, $selectedDays, $camtCoverage);
        $this->displayPlan($path, $data, $plan);

        if ((bool) $this->option('dry-run')) {
            $this->info('Dry run complete. No database changes were made.');

            return self::SUCCESS;
        }

        $filename = basename($path);
        $matchingImports = Import::query()
            ->where('type', self::IMPORT_TYPE)
            ->where('filename', $filename)
            ->orderByDesc('id')
            ->get();
        $matchingImportIds = $matchingImports->pluck('id')->all();
        $hasExistingData = !empty($matchingImportIds) && (
            BankStatementEntry::query()->whereIn('import_id', $matchingImportIds)->exists()
            || BankStatementSummary::query()->whereIn('import_id', $matchingImportIds)->exists()
        );
        $hasCompletedImport = $matchingImports->contains(fn(Import $import) => $import->status === 'completed');

        if (!(bool) $this->option('replace-existing') && ($hasExistingData || $hasCompletedImport)) {
            $this->error(
                "A completed import already exists for {$filename}. "
                . 'Use --replace-existing to atomically rebuild only this CSV import.'
            );

            return self::FAILURE;
        }

        $import = $matchingImports->first() ?? new Import();
        $import->fill([
            'type' => self::IMPORT_TYPE,
            'filename' => $filename,
            'file_size' => filesize($path) ?: 0,
            'total_rows' => $data['row_count'],
            'imported_count' => 0,
            'duplicate_count' => $plan['skipped_transaction_count'],
            'error_count' => 0,
            'empty_row_count' => $data['empty_row_count'],
            'error_details' => null,
            'status' => 'processing',
            'import_started_at' => now(),
            'import_completed_at' => null,
        ]);
        $import->save();

        try {
            DB::transaction(function () use (
                $data,
                $selectedDays,
                $matchingImportIds,
                $import,
                $filename
            ): void {
                if ((bool) $this->option('replace-existing') && !empty($matchingImportIds)) {
                    BankStatementEntry::query()
                        ->whereIn('import_id', $matchingImportIds)
                        ->delete();
                    BankStatementSummary::query()
                        ->whereIn('import_id', $matchingImportIds)
                        ->delete();
                }

                $this->insertDays(
                    $selectedDays,
                    $import->id,
                    $filename,
                    $data['file_hash']
                );

                if (!(bool) $this->option('skip-analysis')) {
                    $exitCode = $this->call('analyze:bank-entries', [
                        '--parser' => $this->scalarOption('parser', 'v2'),
                        '--source-file' => $filename,
                    ]);

                    if ($exitCode !== self::SUCCESS) {
                        throw new RuntimeException('Bank entry analysis failed.');
                    }
                }
            });

            $import->update([
                'status' => 'completed',
                'imported_count' => $plan['import_transaction_count'],
                'duplicate_count' => $plan['skipped_transaction_count'],
                'error_count' => 0,
                'error_details' => null,
                'import_completed_at' => now(),
            ]);
        } catch (Throwable $e) {
            $import->update([
                'status' => 'failed',
                'error_count' => 1,
                'error_details' => $e->getMessage(),
                'import_completed_at' => now(),
            ]);

            $this->error('CSV import failed and was rolled back: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Import complete. Added %d transaction entries and %d daily summaries; skipped %d CAMT-covered transactions across %d dates.',
            $plan['import_transaction_count'],
            $plan['import_day_count'],
            $plan['skipped_transaction_count'],
            $plan['skipped_day_count']
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function downloadFromBankSftp(string $filename): array
    {
        if (
            $filename === ''
            || basename($filename) !== $filename
            || in_array($filename, ['.', '..'], true)
            || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'csv'
        ) {
            throw new RuntimeException('The SFTP filename must be one plain .csv filename without a directory path.');
        }

        $host = $this->configuredString('sftp.bank.host');
        $username = $this->configuredString('sftp.bank.username');
        $password = $this->configuredString('sftp.bank.password');
        $port = (int) ($this->configuredString('sftp.bank.port') ?? '22');
        $remotePath = $this->normalizeRemotePath(
            $this->scalarOption('remote-path', $this->configuredString('sftp.bank.remote_path') ?? '/')
        );

        if ($host === null || $username === null || $password === null) {
            throw new RuntimeException(
                'Missing bank SFTP credentials. Set BANK_SFTP_HOST, BANK_SFTP_USERNAME, and BANK_SFTP_PASSWORD.'
            );
        }

        $temporaryDirectory = storage_path(
            'app/bank-transaction-balance-csv/' . now()->format('Ymd_His_u') . '_' . bin2hex(random_bytes(4))
        );
        if (
            !is_dir($temporaryDirectory)
            && !mkdir($temporaryDirectory, 0700, true)
            && !is_dir($temporaryDirectory)
        ) {
            throw new RuntimeException("Unable to create temporary directory: {$temporaryDirectory}");
        }

        $localPath = $temporaryDirectory . DIRECTORY_SEPARATOR . $filename;
        $partialPath = $localPath . '.part';
        $remoteFile = $remotePath === '/' ? "/{$filename}" : "{$remotePath}/{$filename}";

        try {
            $sftp = new SFTP($host, $port);
            try {
                $loggedIn = $sftp->login($username, $password);
            } catch (Throwable $e) {
                throw new RuntimeException(
                    "Unable to connect to bank SFTP host {$host}:{$port}. " . trim($e->getMessage()),
                    0,
                    $e
                );
            }

            if (!$loggedIn) {
                throw new RuntimeException('Bank SFTP authentication failed.');
            }

            $remoteSize = $sftp->filesize($remoteFile);
            if ($remoteSize === false) {
                throw new RuntimeException("Remote CSV was not found: {$remoteFile}");
            }

            if (!$sftp->get($remoteFile, $partialPath)) {
                throw new RuntimeException("Unable to download remote CSV: {$remoteFile}");
            }

            $localSize = filesize($partialPath);
            if ($localSize === false || $localSize !== $remoteSize) {
                throw new RuntimeException(
                    "Downloaded CSV size mismatch for {$filename}: expected {$remoteSize} bytes, received "
                    . ($localSize === false ? 'an unreadable file' : "{$localSize} bytes") . '.'
                );
            }

            if (!rename($partialPath, $localPath)) {
                throw new RuntimeException("Unable to finalize the temporary CSV: {$localPath}");
            }
        } catch (Throwable $e) {
            try {
                $this->cleanupTemporaryDirectory($temporaryDirectory);
            } catch (Throwable $cleanupError) {
                throw new RuntimeException(
                    $e->getMessage() . ' Temporary file cleanup also failed: ' . $cleanupError->getMessage(),
                    0,
                    $e
                );
            }

            throw $e;
        }

        $this->line("Downloaded {$filename} temporarily from the bank SFTP folder.");

        return [$localPath, $temporaryDirectory];
    }

    /**
     * @return array{
     *     days: array<string, array<string, mixed>>,
     *     row_count: int,
     *     transaction_count: int,
     *     empty_row_count: int,
     *     debit_count: int,
     *     credit_count: int,
     *     debit_sum_cents: int,
     *     credit_sum_cents: int,
     *     first_date: string,
     *     last_date: string,
     *     file_hash: string
     * }
     */
    private function readAndValidateCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open the CSV file.');
        }

        try {
            $headers = fgetcsv($handle, null, ',', '"', '');
            if (!is_array($headers)) {
                throw new RuntimeException('The CSV file is empty.');
            }

            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]) ?? $headers[0];
            if ($headers !== self::EXPECTED_HEADERS) {
                throw new RuntimeException(
                    'Unexpected CSV headers. Expected: ' . implode(', ', self::EXPECTED_HEADERS)
                );
            }

            $days = [];
            $rowCount = 0;
            $transactionCount = 0;
            $emptyRowCount = 0;
            $debitCount = 0;
            $creditCount = 0;
            $debitSumCents = 0;
            $creditSumCents = 0;
            $reportedTotals = null;
            $lastDateByAccount = [];
            $recordNumber = 1;

            while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
                $recordNumber++;

                if ($row === [null]) {
                    continue;
                }

                $rowCount++;
                if (count($row) !== count(self::EXPECTED_HEADERS)) {
                    throw new RuntimeException(
                        "Record {$recordNumber} has " . count($row) . ' columns; expected ' . count(self::EXPECTED_HEADERS) . '.'
                    );
                }

                $raw = array_combine(self::EXPECTED_HEADERS, $row);
                if ($raw === false) {
                    throw new RuntimeException("Record {$recordNumber} could not be mapped to the CSV headers.");
                }

                $accountName = $this->requiredValue($raw['Account name'], 'Account name', $recordNumber);
                $accountNumber = $this->requiredValue($raw['Account number'], 'Account number', $recordNumber);
                $currency = strtoupper($this->requiredValue($raw['Currency'], 'Currency', $recordNumber));
                $date = $this->parseDate($raw['BUSINESS DATE'], $recordNumber);
                $openingBalanceCents = $this->parseMoneyCents($raw['Opening balance'], 'Opening balance', $recordNumber);
                $rowBalanceCents = $this->parseMoneyCents($raw['Balance'], 'Balance', $recordNumber);
                $closingBalanceCents = $this->parseMoneyCents($raw['CLOSING BALANCE'], 'CLOSING BALANCE', $recordNumber);
                $debitCents = $this->parseOptionalMoneyCents($raw['Debit amount'], 'Debit amount', $recordNumber);
                $creditCents = $this->parseOptionalMoneyCents($raw['Credit amount'], 'Credit amount', $recordNumber);
                $description = trim((string) $raw['Description']);
                $details = trim((string) $raw['ADDITIONAL DETAILS']);

                if ($debitCents !== null && $creditCents !== null) {
                    throw new RuntimeException("Record {$recordNumber} has both a debit and a credit amount.");
                }
                if (($debitCents !== null && $debitCents < 0) || ($creditCents !== null && $creditCents < 0)) {
                    throw new RuntimeException("Record {$recordNumber} contains a negative debit or credit amount.");
                }

                $isTransaction = $debitCents !== null || $creditCents !== null;
                if ($isTransaction && $description === '') {
                    throw new RuntimeException("Record {$recordNumber} has an amount but no Description.");
                }
                if (!$isTransaction && ($description !== '' || $details !== '')) {
                    throw new RuntimeException("Record {$recordNumber} has descriptive text but no debit or credit amount.");
                }

                $reported = [
                    'debit_sum_cents' => $this->parseMoneyCents(
                        $raw['TOTAL DEBIT AMOUNT'],
                        'TOTAL DEBIT AMOUNT',
                        $recordNumber
                    ),
                    'credit_sum_cents' => $this->parseMoneyCents(
                        $raw['TOTAL CREDIT AMOUNT'],
                        'TOTAL CREDIT AMOUNT',
                        $recordNumber
                    ),
                    'debit_count' => $this->parseCount(
                        $raw['TOTAL DEBIT COUNT'],
                        'TOTAL DEBIT COUNT',
                        $recordNumber
                    ),
                    'credit_count' => $this->parseCount(
                        $raw['TOTAL CREDIT COUNT'],
                        'TOTAL CREDIT COUNT',
                        $recordNumber
                    ),
                ];
                $reportedTotals ??= $reported;
                if ($reported !== $reportedTotals) {
                    throw new RuntimeException("Record {$recordNumber} does not repeat the same file-level totals.");
                }

                $accountKey = $accountNumber . '|' . $currency;
                if (isset($lastDateByAccount[$accountKey]) && $date < $lastDateByAccount[$accountKey]) {
                    throw new RuntimeException(
                        "Record {$recordNumber} is out of date order for account {$accountNumber}."
                    );
                }
                $lastDateByAccount[$accountKey] = $date;

                $dayKey = $this->coverageKey($accountNumber, $currency, $date);
                if (!isset($days[$dayKey])) {
                    $days[$dayKey] = [
                        'account_name' => $accountName,
                        'account_number' => $accountNumber,
                        'currency' => $currency,
                        'date' => $date,
                        'opening_balance_cents' => $openingBalanceCents,
                        'closing_balance_cents' => $closingBalanceCents,
                        'current_balance_cents' => $openingBalanceCents,
                        'debit_count' => 0,
                        'credit_count' => 0,
                        'debit_sum_cents' => 0,
                        'credit_sum_cents' => 0,
                        'rows' => [],
                    ];
                }

                $day = &$days[$dayKey];
                if (
                    $day['account_name'] !== $accountName
                    || $day['opening_balance_cents'] !== $openingBalanceCents
                    || $day['closing_balance_cents'] !== $closingBalanceCents
                ) {
                    throw new RuntimeException(
                        "Record {$recordNumber} conflicts with another row for {$accountNumber} on {$date}."
                    );
                }

                $expectedBalanceCents = $day['current_balance_cents']
                    - ($debitCents ?? 0)
                    + ($creditCents ?? 0);
                if ($expectedBalanceCents !== $rowBalanceCents) {
                    throw new RuntimeException(
                        "Record {$recordNumber} running balance does not reconcile. "
                        . 'Expected ' . $this->centsToDecimal($expectedBalanceCents)
                        . ', found ' . $this->centsToDecimal($rowBalanceCents) . '.'
                    );
                }

                $day['current_balance_cents'] = $rowBalanceCents;
                if ($debitCents !== null) {
                    $day['debit_count']++;
                    $day['debit_sum_cents'] += $debitCents;
                    $debitCount++;
                    $debitSumCents += $debitCents;
                }
                if ($creditCents !== null) {
                    $day['credit_count']++;
                    $day['credit_sum_cents'] += $creditCents;
                    $creditCount++;
                    $creditSumCents += $creditCents;
                }

                if ($isTransaction) {
                    $transactionCount++;
                } else {
                    $emptyRowCount++;
                }

                $day['rows'][] = [
                    'record_number' => $recordNumber,
                    'description' => $description,
                    'details' => $details,
                    'debit_cents' => $debitCents,
                    'credit_cents' => $creditCents,
                    'balance_cents' => $rowBalanceCents,
                    'is_transaction' => $isTransaction,
                    'raw' => $raw,
                ];
                unset($day);
            }
        } finally {
            fclose($handle);
        }

        if ($rowCount === 0 || empty($days) || $reportedTotals === null) {
            throw new RuntimeException('The CSV file contains no data rows.');
        }

        $previousClosingByAccount = [];
        foreach ($days as $day) {
            if ($day['current_balance_cents'] !== $day['closing_balance_cents']) {
                throw new RuntimeException(
                    "Closing balance does not reconcile for {$day['account_number']} on {$day['date']}."
                );
            }

            $accountKey = $day['account_number'] . '|' . $day['currency'];
            if (
                isset($previousClosingByAccount[$accountKey])
                && $previousClosingByAccount[$accountKey] !== $day['opening_balance_cents']
            ) {
                throw new RuntimeException(
                    "Opening balance for {$day['account_number']} on {$day['date']} "
                    . 'does not match the previous closing balance.'
                );
            }
            $previousClosingByAccount[$accountKey] = $day['closing_balance_cents'];
        }

        if (
            $debitSumCents !== $reportedTotals['debit_sum_cents']
            || $creditSumCents !== $reportedTotals['credit_sum_cents']
            || $debitCount !== $reportedTotals['debit_count']
            || $creditCount !== $reportedTotals['credit_count']
        ) {
            throw new RuntimeException(
                'Calculated transaction counts or amounts do not match the file-level totals.'
            );
        }

        $dates = array_column($days, 'date');
        $fileHash = hash_file('sha256', $path);
        if ($fileHash === false) {
            throw new RuntimeException('Unable to calculate the CSV file hash.');
        }

        return [
            'days' => $days,
            'row_count' => $rowCount,
            'transaction_count' => $transactionCount,
            'empty_row_count' => $emptyRowCount,
            'debit_count' => $debitCount,
            'credit_count' => $creditCount,
            'debit_sum_cents' => $debitSumCents,
            'credit_sum_cents' => $creditSumCents,
            'first_date' => min($dates),
            'last_date' => max($dates),
            'file_hash' => $fileHash,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $days
     * @return array<string, true>
     */
    private function loadCamtCoverage(array $days): array
    {
        $accounts = array_values(array_unique(array_column($days, 'account_number')));
        $currencies = array_values(array_unique(array_column($days, 'currency')));
        $dates = array_column($days, 'date');
        $firstDate = min($dates);
        $lastDate = max($dates);
        $coverage = [];

        $entries = DB::table('bank_statement_entries as entries')
            ->join('imports', 'imports.id', '=', 'entries.import_id')
            ->where('imports.type', self::CAMT_IMPORT_TYPE)
            ->whereIn('entries.account_number', $accounts)
            ->whereIn('entries.currency', $currencies)
            ->where(function ($query) use ($firstDate, $lastDate) {
                $query->whereBetween('entries.booking_date', [$firstDate, $lastDate])
                    ->orWhereBetween('entries.value_date', [$firstDate, $lastDate]);
            })
            ->get([
                'entries.account_number',
                'entries.currency',
                'entries.booking_date',
                'entries.value_date',
            ]);

        foreach ($entries as $entry) {
            foreach ([$entry->booking_date, $entry->value_date] as $date) {
                if ($date === null) {
                    continue;
                }

                $key = $this->coverageKey($entry->account_number, $entry->currency, (string) $date);
                if (isset($days[$key])) {
                    $coverage[$key] = true;
                }
            }
        }

        $summaries = DB::table('bank_statement_summaries as summaries')
            ->join('imports', 'imports.id', '=', 'summaries.import_id')
            ->where('imports.type', self::CAMT_IMPORT_TYPE)
            ->whereIn('summaries.account_number', $accounts)
            ->where(function ($query) use ($currencies) {
                $query->whereIn('summaries.closing_balance_currency', $currencies)
                    ->orWhereIn('summaries.opening_balance_currency', $currencies);
            })
            ->where(function ($query) use ($firstDate, $lastDate) {
                $query->whereBetween('summaries.closing_balance_date', [$firstDate, $lastDate])
                    ->orWhere(function ($openingQuery) use ($firstDate, $lastDate) {
                        $openingQuery->whereNull('summaries.closing_balance_date')
                            ->whereBetween('summaries.opening_balance_date', [$firstDate, $lastDate]);
                    });
            })
            ->get([
                'summaries.account_number',
                'summaries.opening_balance_currency',
                'summaries.closing_balance_currency',
                'summaries.opening_balance_date',
                'summaries.closing_balance_date',
            ]);

        foreach ($summaries as $summary) {
            $currency = $summary->closing_balance_currency ?: $summary->opening_balance_currency;
            $date = $summary->closing_balance_date ?: $summary->opening_balance_date;
            if ($currency === null || $date === null) {
                continue;
            }

            $key = $this->coverageKey($summary->account_number, $currency, (string) $date);
            if (isset($days[$key])) {
                $coverage[$key] = true;
            }
        }

        return $coverage;
    }

    /**
     * @param array<string, array<string, mixed>> $days
     */
    private function insertDays(array $days, int $importId, string $filename, string $fileHash): void
    {
        $messageId = 'CIBC-TRANSACTION-BALANCE-CSV-' . substr($fileHash, 0, 16);
        $statementIndex = 0;

        foreach ($days as $day) {
            $opening = $this->balanceFields($day['opening_balance_cents']);
            $closing = $this->balanceFields($day['closing_balance_cents']);
            $summary = BankStatementSummary::create([
                'import_id' => $importId,
                'source_file' => $filename,
                'statement_index' => $statementIndex,
                'message_id' => $messageId,
                'group_created_at' => null,
                'statement_id' => sprintf(
                    'CIBC-CSV-%s-%s',
                    $day['account_number'],
                    str_replace('-', '', $day['date'])
                ),
                'statement_created_at' => $day['date'] . ' 00:00:00',
                'account_number' => $day['account_number'],
                'opening_balance_type_code' => 'OPBD',
                'opening_balance_amount' => $opening['amount'],
                'opening_balance_currency' => $day['currency'],
                'opening_balance_indicator' => $opening['indicator'],
                'opening_balance_signed_amount' => $opening['signed_amount'],
                'opening_balance_date' => $day['date'],
                'closing_balance_type_code' => 'CLBD',
                'closing_balance_amount' => $closing['amount'],
                'closing_balance_currency' => $day['currency'],
                'closing_balance_indicator' => $closing['indicator'],
                'closing_balance_signed_amount' => $closing['signed_amount'],
                'closing_balance_date' => $day['date'],
                'total_credit_entries' => $day['credit_count'],
                'total_credit_sum' => $this->centsToDecimal($day['credit_sum_cents']),
                'total_debit_entries' => $day['debit_count'],
                'total_debit_sum' => $this->centsToDecimal($day['debit_sum_cents']),
                'raw_statement_xml' => null,
                'raw_statement_json' => [
                    'source_format' => self::IMPORT_TYPE,
                    'account_name' => $day['account_name'],
                    'business_date' => $day['date'],
                    'source_row_count' => count($day['rows']),
                    'transaction_count' => $day['debit_count'] + $day['credit_count'],
                ],
            ]);

            $summary->balances()->createMany([
                [
                    'balance_index' => 0,
                    'balance_type_code' => 'OPBD',
                    'amount' => $opening['amount'],
                    'currency' => $day['currency'],
                    'credit_debit_indicator' => $opening['indicator'],
                    'signed_amount' => $opening['signed_amount'],
                    'balance_date' => $day['date'],
                    'raw_json' => ['source_format' => self::IMPORT_TYPE],
                ],
                [
                    'balance_index' => 1,
                    'balance_type_code' => 'CLBD',
                    'amount' => $closing['amount'],
                    'currency' => $day['currency'],
                    'credit_debit_indicator' => $closing['indicator'],
                    'signed_amount' => $closing['signed_amount'],
                    'balance_date' => $day['date'],
                    'raw_json' => ['source_format' => self::IMPORT_TYPE],
                ],
            ]);

            $entries = [];
            $entryIndex = 0;
            $now = now();

            foreach ($day['rows'] as $row) {
                if (!$row['is_transaction']) {
                    continue;
                }

                $isDebit = $row['debit_cents'] !== null;
                $additionalInfo = implode(', ', array_values(array_filter(
                    [$row['description'], $row['details']],
                    fn(string $value) => $value !== ''
                )));

                $entries[] = [
                    'import_id' => $importId,
                    'bank_statement_summary_id' => $summary->id,
                    'source_file' => $filename,
                    'message_id' => $messageId,
                    'statement_id' => $summary->statement_id,
                    'account_number' => $day['account_number'],
                    'entry_index' => $entryIndex,
                    'entry_reference' => null,
                    'booking_date' => $day['date'],
                    'value_date' => $day['date'],
                    'credit_debit_indicator' => $isDebit ? 'DBIT' : 'CRDT',
                    'status' => 'BOOK',
                    'currency' => $day['currency'],
                    'amount' => $this->centsToDecimal(
                        $isDebit ? $row['debit_cents'] : $row['credit_cents']
                    ),
                    'bank_domain_code' => null,
                    'bank_family_code' => null,
                    'bank_sub_family_code' => null,
                    'additional_info' => $additionalInfo,
                    'raw_xml' => null,
                    'raw_json' => json_encode([
                        'source_format' => self::IMPORT_TYPE,
                        'source_record' => $row['record_number'],
                        'row' => $row['raw'],
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $entryIndex++;
            }

            if (!empty($entries)) {
                BankStatementEntry::insert($entries);
            }

            $statementIndex++;
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, array<string, mixed>> $selectedDays
     * @param array<string, true> $camtCoverage
     * @return array<string, int>
     */
    private function buildPlan(array $data, array $selectedDays, array $camtCoverage): array
    {
        $importTransactionCount = array_sum(array_map(
            fn(array $day) => $day['debit_count'] + $day['credit_count'],
            $selectedDays
        ));

        return [
            'import_day_count' => count($selectedDays),
            'import_transaction_count' => $importTransactionCount,
            'skipped_day_count' => count($camtCoverage),
            'skipped_transaction_count' => $data['transaction_count'] - $importTransactionCount,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, int> $plan
     */
    private function displayPlan(string $path, array $data, array $plan): void
    {
        $this->table(
            ['Item', 'Value'],
            [
                ['File', $path],
                ['Business-date range', $data['first_date'] . ' through ' . $data['last_date']],
                ['Validated CSV rows', number_format($data['row_count'])],
                ['Validated transaction rows', number_format($data['transaction_count'])],
                ['No-activity balance rows', number_format($data['empty_row_count'])],
                ['Debit count / total', number_format($data['debit_count']) . ' / ' . $this->centsToDecimal($data['debit_sum_cents'])],
                ['Credit count / total', number_format($data['credit_count']) . ' / ' . $this->centsToDecimal($data['credit_sum_cents'])],
                ['CAMT-covered dates skipped', number_format($plan['skipped_day_count'])],
                ['CAMT-covered transactions skipped', number_format($plan['skipped_transaction_count'])],
                ['Daily summaries to import', number_format($plan['import_day_count'])],
                ['Transaction entries to import', number_format($plan['import_transaction_count'])],
            ]
        );
    }

    /**
     * @return array{amount: string, indicator: string, signed_amount: string}
     */
    private function balanceFields(int $cents): array
    {
        return [
            'amount' => $this->centsToDecimal(abs($cents)),
            'indicator' => $cents < 0 ? 'DBIT' : 'CRDT',
            'signed_amount' => $this->centsToDecimal($cents),
        ];
    }

    private function requiredValue(mixed $value, string $column, int $recordNumber): string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            throw new RuntimeException("Record {$recordNumber} is missing {$column}.");
        }

        return $normalized;
    }

    private function parseDate(mixed $value, int $recordNumber): string
    {
        $normalized = trim((string) $value);
        if (!preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $normalized, $matches)) {
            throw new RuntimeException("Record {$recordNumber} has an invalid BUSINESS DATE.");
        }

        $month = (int) $matches[1];
        $day = (int) $matches[2];
        $year = (int) $matches[3];
        if (!checkdate($month, $day, $year)) {
            throw new RuntimeException("Record {$recordNumber} has an invalid BUSINESS DATE.");
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function parseOptionalMoneyCents(mixed $value, string $column, int $recordNumber): ?int
    {
        if (trim((string) $value) === '') {
            return null;
        }

        return $this->parseMoneyCents($value, $column, $recordNumber);
    }

    private function parseMoneyCents(mixed $value, string $column, int $recordNumber): int
    {
        $normalized = str_replace([',', ' '], '', trim((string) $value));
        if (!preg_match('/^-?\d+(?:\.\d{1,2})?$/', $normalized)) {
            throw new RuntimeException("Record {$recordNumber} has an invalid {$column}.");
        }

        $negative = str_starts_with($normalized, '-');
        $unsigned = ltrim($normalized, '-');
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $cents = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');

        return $negative ? -$cents : $cents;
    }

    private function parseCount(mixed $value, string $column, int $recordNumber): int
    {
        $normalized = trim((string) $value);
        if (!preg_match('/^\d+$/', $normalized)) {
            throw new RuntimeException("Record {$recordNumber} has an invalid {$column}.");
        }

        return (int) $normalized;
    }

    private function centsToDecimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return $sign . intdiv($absolute, 100) . '.' . str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    private function coverageKey(string $accountNumber, string $currency, string $date): string
    {
        return $accountNumber . '|' . strtoupper($currency) . '|' . $date;
    }

    private function configuredString(string $key): ?string
    {
        $value = config($key);
        if (is_array($value)) {
            $value = collect($value)->first(
                fn(mixed $candidate) => $candidate !== null && trim((string) $candidate) !== ''
            );
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeRemotePath(string $remotePath): string
    {
        $trimmed = trim($remotePath);
        if ($trimmed === '' || $trimmed === '/') {
            return '/';
        }

        return '/' . trim($trimmed, '/');
    }

    private function cleanupTemporaryDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file) && !unlink($file)) {
                throw new RuntimeException("Unable to delete temporary file: {$file}");
            }
        }

        if (is_dir($directory) && !rmdir($directory)) {
            throw new RuntimeException("Unable to remove temporary directory: {$directory}");
        }
    }

    private function resolvePath(string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            ? $path
            : base_path($path);
    }

    private function scalarOption(string $name, string $default = ''): string
    {
        $value = $this->option($name);
        if (is_array($value)) {
            $value = end($value);
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : $default;
    }
}
