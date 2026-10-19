<?php

namespace App\Console\Commands;

use App\Models\BankStatementEntry;
use App\Models\BankStatementSummary;
use App\Models\Import;
use App\Services\Bank\BankSftpFileDownloader;
use App\Services\Bank\CibcMonthlyPdfStatementParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ImportCibcMonthlyPdfStatementsCommand extends Command
{
    private const IMPORT_TYPE = 'bank-statement-pdf';

    private const HIGHER_PRIORITY_TYPES = [
        'bank-camt-raw' => 2,
        'bank-transaction-balance-csv' => 1,
    ];

    protected $signature = 'import:cibc-monthly-pdf
        {--path=resources/data/cibc : Local PDF file or directory}
        {--pattern=*.pdf : Glob used when path is a local directory}
        {--sftp-pattern= : Bank SFTP filename glob; for example *.pdf or 2023*.pdf}
        {--remote-path= : SFTP directory override; defaults to BANK_SFTP_REMOTE_PATH}
        {--currency=CAD : Statement currency when the PDF displays only a dollar symbol}
        {--parser=v2 : Parser version for analyze:bank-entries}
        {--dry-run : Download, parse, validate, and compare without changing the database}
        {--replace-existing : Atomically replace prior PDF imports with the same filenames}
        {--skip-analysis : Skip analyze:bank-entries after importing}';

    protected $description = 'Import reusable CIBC monthly PDF statements from local storage or bank SFTP';

    public function handle(
        BankSftpFileDownloader $downloader,
        CibcMonthlyPdfStatementParser $parser
    ): int {
        $sftpPattern = $this->scalarOption('sftp-pattern');
        $replaceExisting = (bool) $this->option('replace-existing');
        $completedNames = $replaceExisting
            ? []
            : Import::query()
                ->where('type', self::IMPORT_TYPE)
                ->where('status', 'completed')
                ->distinct()
                ->pluck('filename')
                ->all();

        try {
            $files = $sftpPattern !== ''
                ? $downloader->listFiles($sftpPattern, $this->scalarOption('remote-path') ?: null)
                : $this->localFiles();
        } catch (Throwable $e) {
            $this->error('Unable to locate PDF statements: ' . $e->getMessage());

            return self::FAILURE;
        }

        $files = array_values(array_filter(
            $files,
            fn(string $file) => $replaceExisting || !in_array(basename($file), $completedNames, true)
        ));

        if (empty($files)) {
            $this->info('No eligible CIBC PDF statements were found.');

            return self::SUCCESS;
        }

        $processed = 0;
        $failed = 0;
        $importedEntries = 0;
        $skippedEntries = 0;

        foreach ($files as $file) {
            $temporaryDirectory = null;
            $path = $file;
            $filename = basename($file);

            try {
                if ($sftpPattern !== '') {
                    $download = $downloader->download(
                        $filename,
                        'pdf',
                        $this->scalarOption('remote-path') ?: null
                    );
                    $path = $download['path'];
                    $temporaryDirectory = $download['directory'];
                    $this->line("Downloaded {$filename} temporarily from the bank SFTP folder.");
                }

                $result = $this->processFile($path, $filename, $parser);
                $importedEntries += $result['imported_entries'];
                $skippedEntries += $result['skipped_entries'];
                $processed++;
            } catch (Throwable $e) {
                $failed++;
                $this->error("Failed {$filename}: {$e->getMessage()}");
                if (!(bool) $this->option('dry-run')) {
                    $this->recordFailedImport($path, $filename, $e->getMessage());
                }
            } finally {
                if ($temporaryDirectory !== null) {
                    $downloader->cleanup($temporaryDirectory);
                    $this->line("Removed the temporary {$filename} copy from the web server.");
                }
            }
        }

        $this->newLine();
        $this->info(
            "PDF statement processing complete: {$processed} succeeded, {$failed} failed, "
            . "{$importedEntries} entries imported, {$skippedEntries} higher-priority entries skipped."
        );

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{imported_entries: int, skipped_entries: int}
     */
    private function processFile(
        string $path,
        string $filename,
        CibcMonthlyPdfStatementParser $parser
    ): array {
        $this->line("Processing {$filename}");
        $statement = $parser->parseFile($path, strtoupper($this->scalarOption('currency', 'CAD')));
        $higherPriority = $this->loadHigherPriorityData($statement);
        $this->assertCoveredDaysMatch($statement, $higherPriority);

        $selectedDays = array_diff_key($statement['days'], $higherPriority['coverage']);
        $importedEntryCount = array_sum(array_map(
            fn(array $day) => count($day['transactions']),
            $selectedDays
        ));
        $skippedEntryCount = $statement['transaction_count'] - $importedEntryCount;

        $this->displayPlan(
            $filename,
            $statement,
            count($selectedDays),
            $importedEntryCount,
            count($higherPriority['coverage']),
            $skippedEntryCount
        );

        if ((bool) $this->option('dry-run')) {
            $this->info("Dry run complete for {$filename}. No database changes were made.");

            return [
                'imported_entries' => 0,
                'skipped_entries' => $skippedEntryCount,
            ];
        }

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
            throw new RuntimeException(
                "A completed PDF import already exists for {$filename}. Use --replace-existing to rebuild it."
            );
        }

        $this->assertNoOtherPdfOverlap($statement, $filename, $matchingImportIds);

        $import = $matchingImports->first() ?? new Import();
        $import->fill([
            'type' => self::IMPORT_TYPE,
            'filename' => $filename,
            'file_size' => filesize($path) ?: 0,
            'total_rows' => $statement['transaction_count'],
            'imported_count' => 0,
            'duplicate_count' => $skippedEntryCount,
            'error_count' => 0,
            'empty_row_count' => count(array_filter(
                $statement['days'],
                fn(array $day) => empty($day['transactions'])
            )),
            'error_details' => null,
            'status' => 'processing',
            'import_started_at' => now(),
            'import_completed_at' => null,
        ]);
        $import->save();

        try {
            DB::transaction(function () use (
                $statement,
                $selectedDays,
                $matchingImportIds,
                $import,
                $filename,
                $path
            ): void {
                if ((bool) $this->option('replace-existing') && !empty($matchingImportIds)) {
                    BankStatementEntry::query()->whereIn('import_id', $matchingImportIds)->delete();
                    BankStatementSummary::query()->whereIn('import_id', $matchingImportIds)->delete();
                }

                $this->insertDays(
                    $statement,
                    $selectedDays,
                    $import->id,
                    $filename,
                    hash_file('sha256', $path) ?: throw new RuntimeException('Unable to hash the PDF file.')
                );

                if (!(bool) $this->option('skip-analysis') && !empty($selectedDays)) {
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
                'imported_count' => $importedEntryCount,
                'duplicate_count' => $skippedEntryCount,
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

            throw new RuntimeException('PDF import was rolled back: ' . $e->getMessage(), 0, $e);
        }

        $this->info(
            "Imported {$importedEntryCount} entries from {$filename}; "
            . "skipped {$skippedEntryCount} entries already covered by CAMT or CSV."
        );

        return [
            'imported_entries' => $importedEntryCount,
            'skipped_entries' => $skippedEntryCount,
        ];
    }

    /**
     * @param array<string, mixed> $statement
     * @return array{
     *     coverage: array<string, string>,
     *     entry_stats: array<string, array<string, array<string, int>>>,
     *     summaries: array<string, array<string, object>>
     * }
     */
    private function loadHigherPriorityData(array $statement): array
    {
        $types = array_keys(self::HIGHER_PRIORITY_TYPES);
        $coverage = [];
        $entryStats = [];
        $summariesByTypeDate = [];

        $entries = DB::table('bank_statement_entries as entries')
            ->join('imports', 'imports.id', '=', 'entries.import_id')
            ->whereIn('imports.type', $types)
            ->where('entries.account_number', $statement['account_number'])
            ->where('entries.currency', $statement['currency'])
            ->where(function ($query) use ($statement) {
                $query->whereBetween(
                    'entries.value_date',
                    [$statement['statement_start_date'], $statement['statement_end_date']]
                )->orWhereBetween(
                    'entries.booking_date',
                    [$statement['statement_start_date'], $statement['statement_end_date']]
                );
            })
            ->get([
                'imports.type',
                'entries.value_date',
                'entries.booking_date',
                'entries.credit_debit_indicator',
                'entries.amount',
            ]);

        foreach ($entries as $entry) {
            $date = (string) ($entry->value_date ?: $entry->booking_date);
            if ($date === '') {
                continue;
            }
            $type = (string) $entry->type;
            $this->setCoverageType($coverage, $date, $type);
            $entryStats[$type][$date] ??= [
                'debit_count' => 0,
                'credit_count' => 0,
                'debit_sum_cents' => 0,
                'credit_sum_cents' => 0,
            ];
            $amountCents = $this->decimalToCents($entry->amount);
            if ($entry->credit_debit_indicator === 'DBIT') {
                $entryStats[$type][$date]['debit_count']++;
                $entryStats[$type][$date]['debit_sum_cents'] += $amountCents;
            } else {
                $entryStats[$type][$date]['credit_count']++;
                $entryStats[$type][$date]['credit_sum_cents'] += $amountCents;
            }
        }

        $summaries = DB::table('bank_statement_summaries as summaries')
            ->join('imports', 'imports.id', '=', 'summaries.import_id')
            ->whereIn('imports.type', $types)
            ->where('summaries.account_number', $statement['account_number'])
            ->where(function ($query) use ($statement) {
                $query->where('summaries.closing_balance_currency', $statement['currency'])
                    ->orWhere('summaries.opening_balance_currency', $statement['currency']);
            })
            ->where(function ($query) use ($statement) {
                $query->whereBetween(
                    'summaries.closing_balance_date',
                    [$statement['statement_start_date'], $statement['statement_end_date']]
                )->orWhere(function ($openingQuery) use ($statement) {
                    $openingQuery->whereNull('summaries.closing_balance_date')
                        ->whereBetween(
                            'summaries.opening_balance_date',
                            [$statement['statement_start_date'], $statement['statement_end_date']]
                        );
                });
            })
            ->get([
                'imports.type',
                'summaries.opening_balance_date',
                'summaries.closing_balance_date',
                'summaries.opening_balance_signed_amount',
                'summaries.closing_balance_signed_amount',
            ]);

        foreach ($summaries as $summary) {
            $date = (string) ($summary->closing_balance_date ?: $summary->opening_balance_date);
            $type = (string) $summary->type;
            if (isset($summariesByTypeDate[$type][$date])) {
                throw new RuntimeException("Multiple {$type} summaries cover {$date}.");
            }
            $summariesByTypeDate[$type][$date] = $summary;
            $this->setCoverageType($coverage, $date, $type);
        }

        return [
            'coverage' => $coverage,
            'entry_stats' => $entryStats,
            'summaries' => $summariesByTypeDate,
        ];
    }

    /**
     * @param array<string, mixed> $statement
     * @param array<string, mixed> $higherPriority
     */
    private function assertCoveredDaysMatch(array $statement, array $higherPriority): void
    {
        foreach ($higherPriority['coverage'] as $date => $type) {
            $pdfDay = $statement['days'][$date];
            $existingStats = $higherPriority['entry_stats'][$type][$date] ?? [
                'debit_count' => 0,
                'credit_count' => 0,
                'debit_sum_cents' => 0,
                'credit_sum_cents' => 0,
            ];

            foreach (['debit_count', 'credit_count', 'debit_sum_cents', 'credit_sum_cents'] as $field) {
                if ($pdfDay[$field] !== $existingStats[$field]) {
                    throw new RuntimeException(
                        "PDF data for {$date} does not match higher-priority {$type} {$field}."
                    );
                }
            }

            $summary = $higherPriority['summaries'][$type][$date] ?? null;
            if ($summary !== null) {
                $existingOpening = $this->decimalToCents($summary->opening_balance_signed_amount);
                $existingClosing = $this->decimalToCents($summary->closing_balance_signed_amount);
                if (
                    $existingOpening !== $pdfDay['opening_balance_cents']
                    || $existingClosing !== $pdfDay['closing_balance_cents']
                ) {
                    throw new RuntimeException(
                        "PDF balances for {$date} do not match higher-priority {$type} balances."
                    );
                }
            }
        }
    }

    /**
     * @param array<string, string> $coverage
     */
    private function setCoverageType(array &$coverage, string $date, string $type): void
    {
        $current = $coverage[$date] ?? null;
        if (
            $current === null
            || self::HIGHER_PRIORITY_TYPES[$type] > self::HIGHER_PRIORITY_TYPES[$current]
        ) {
            $coverage[$date] = $type;
        }
    }

    /**
     * @param array<string, mixed> $statement
     * @param array<int, int> $matchingImportIds
     */
    private function assertNoOtherPdfOverlap(
        array $statement,
        string $filename,
        array $matchingImportIds
    ): void {
        $query = BankStatementSummary::query()
            ->whereHas('import', fn($importQuery) => $importQuery->where('type', self::IMPORT_TYPE))
            ->where('account_number', $statement['account_number'])
            ->where(function ($currencyQuery) use ($statement) {
                $currencyQuery->where('closing_balance_currency', $statement['currency'])
                    ->orWhere('opening_balance_currency', $statement['currency']);
            })
            ->whereBetween(
                'closing_balance_date',
                [$statement['statement_start_date'], $statement['statement_end_date']]
            )
            ->where('source_file', '<>', $filename);

        if (!empty($matchingImportIds)) {
            $query->whereNotIn('import_id', $matchingImportIds);
        }

        $overlap = $query->value('source_file');
        if ($overlap !== null) {
            throw new RuntimeException(
                "PDF statement dates overlap an existing PDF import: {$overlap}."
            );
        }
    }

    /**
     * @param array<string, mixed> $statement
     * @param array<string, array<string, mixed>> $days
     */
    private function insertDays(
        array $statement,
        array $days,
        int $importId,
        string $filename,
        string $fileHash
    ): void {
        $messageId = 'CIBC-MONTHLY-PDF-' . substr($fileHash, 0, 16);
        $dayIndexes = array_flip(array_keys($statement['days']));

        foreach ($days as $date => $day) {
            $opening = $this->balanceFields($day['opening_balance_cents']);
            $closing = $this->balanceFields($day['closing_balance_cents']);
            $summary = BankStatementSummary::create([
                'import_id' => $importId,
                'source_file' => $filename,
                'statement_index' => $dayIndexes[$date],
                'message_id' => $messageId,
                'group_created_at' => null,
                'statement_id' => 'CIBC-PDF-' . $statement['account_number'] . '-' . str_replace('-', '', $date),
                'statement_created_at' => $date . ' 00:00:00',
                'account_number' => $statement['account_number'],
                'opening_balance_type_code' => 'OPBD',
                'opening_balance_amount' => $opening['amount'],
                'opening_balance_currency' => $statement['currency'],
                'opening_balance_indicator' => $opening['indicator'],
                'opening_balance_signed_amount' => $opening['signed_amount'],
                'opening_balance_date' => $date,
                'closing_balance_type_code' => 'CLBD',
                'closing_balance_amount' => $closing['amount'],
                'closing_balance_currency' => $statement['currency'],
                'closing_balance_indicator' => $closing['indicator'],
                'closing_balance_signed_amount' => $closing['signed_amount'],
                'closing_balance_date' => $date,
                'total_credit_entries' => $day['credit_count'],
                'total_credit_sum' => $this->centsToDecimal($day['credit_sum_cents']),
                'total_debit_entries' => $day['debit_count'],
                'total_debit_sum' => $this->centsToDecimal($day['debit_sum_cents']),
                'raw_statement_xml' => null,
                'raw_statement_json' => [
                    'source_format' => self::IMPORT_TYPE,
                    'text_source' => $statement['text_source'],
                    'statement_start_date' => $statement['statement_start_date'],
                    'statement_end_date' => $statement['statement_end_date'],
                    'display_account_number' => $statement['display_account_number'],
                    'branch_transit_number' => $statement['branch_transit_number'],
                ],
            ]);

            $summary->balances()->createMany([
                [
                    'balance_index' => 0,
                    'balance_type_code' => 'OPBD',
                    'amount' => $opening['amount'],
                    'currency' => $statement['currency'],
                    'credit_debit_indicator' => $opening['indicator'],
                    'signed_amount' => $opening['signed_amount'],
                    'balance_date' => $date,
                    'raw_json' => ['source_format' => self::IMPORT_TYPE],
                ],
                [
                    'balance_index' => 1,
                    'balance_type_code' => 'CLBD',
                    'amount' => $closing['amount'],
                    'currency' => $statement['currency'],
                    'credit_debit_indicator' => $closing['indicator'],
                    'signed_amount' => $closing['signed_amount'],
                    'balance_date' => $date,
                    'raw_json' => ['source_format' => self::IMPORT_TYPE],
                ],
            ]);

            $entries = [];
            $now = now();
            foreach ($day['transactions'] as $entryIndex => $transaction) {
                $entries[] = [
                    'import_id' => $importId,
                    'bank_statement_summary_id' => $summary->id,
                    'source_file' => $filename,
                    'message_id' => $messageId,
                    'statement_id' => $summary->statement_id,
                    'account_number' => $statement['account_number'],
                    'entry_index' => $entryIndex,
                    'entry_reference' => null,
                    'booking_date' => $date,
                    'value_date' => $date,
                    'credit_debit_indicator' => $transaction['credit_debit_indicator'],
                    'status' => 'BOOK',
                    'currency' => $statement['currency'],
                    'amount' => $this->centsToDecimal($transaction['amount_cents']),
                    'bank_domain_code' => null,
                    'bank_family_code' => null,
                    'bank_sub_family_code' => null,
                    'additional_info' => $transaction['description'],
                    'raw_xml' => null,
                    'raw_json' => json_encode([
                        'source_format' => self::IMPORT_TYPE,
                        'text_source' => $statement['text_source'],
                        'source_line' => $transaction['source_line'],
                        'running_balance' => $this->centsToDecimal($transaction['balance_cents']),
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if (!empty($entries)) {
                BankStatementEntry::insert($entries);
            }
        }
    }

    /**
     * @param array<string, mixed> $statement
     */
    private function displayPlan(
        string $filename,
        array $statement,
        int $importDayCount,
        int $importEntryCount,
        int $skippedDayCount,
        int $skippedEntryCount
    ): void {
        $this->table(
            ['Item', 'Value'],
            [
                ['File', $filename],
                ['Text extraction', $statement['text_source']],
                ['Account', $statement['account_number']],
                ['Statement period', $statement['statement_start_date'] . ' through ' . $statement['statement_end_date']],
                ['Opening balance', $this->centsToDecimal($statement['opening_balance_cents'])],
                ['Closing balance', $this->centsToDecimal($statement['closing_balance_cents'])],
                ['Withdrawals', $statement['debit_count'] . ' / ' . $this->centsToDecimal($statement['withdrawal_total_cents'])],
                ['Deposits', $statement['credit_count'] . ' / ' . $this->centsToDecimal($statement['deposit_total_cents'])],
                ['Higher-priority dates verified/skipped', $skippedDayCount],
                ['Higher-priority entries verified/skipped', $skippedEntryCount],
                ['Daily summaries to import', $importDayCount],
                ['Transaction entries to import', $importEntryCount],
            ]
        );
    }

    private function recordFailedImport(string $path, string $filename, string $message): void
    {
        Import::create([
            'type' => self::IMPORT_TYPE,
            'filename' => $filename,
            'file_size' => is_file($path) ? (filesize($path) ?: 0) : 0,
            'status' => 'failed',
            'error_count' => 1,
            'error_details' => $message,
            'import_started_at' => now(),
            'import_completed_at' => now(),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function localFiles(): array
    {
        $path = $this->resolvePath($this->scalarOption('path', 'resources/data/cibc'));
        if (is_file($path)) {
            if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'pdf') {
                throw new RuntimeException("Local file is not a PDF: {$path}");
            }

            return [$path];
        }
        if (!is_dir($path)) {
            throw new RuntimeException("Local PDF path was not found: {$path}");
        }

        $files = glob($path . DIRECTORY_SEPARATOR . $this->scalarOption('pattern', '*.pdf')) ?: [];
        sort($files);

        return $files;
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

    private function decimalToCents(mixed $value): int
    {
        $normalized = trim((string) $value);
        if (!preg_match('/^-?\d+(?:\.\d{1,2})?$/', $normalized)) {
            throw new RuntimeException("Invalid database money value: {$normalized}");
        }

        $negative = str_starts_with($normalized, '-');
        $unsigned = ltrim($normalized, '-');
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $cents = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');

        return $negative ? -$cents : $cents;
    }

    private function centsToDecimal(int $cents): string
    {
        $absolute = abs($cents);
        $sign = $cents < 0 ? '-' : '';

        return $sign . intdiv($absolute, 100) . '.' . str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    private function resolvePath(string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
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
