<?php

namespace App\Console\Commands;

use App\Models\AsmSettlementSummary;
use App\Models\AsmSettlementTransaction;
use App\Models\Import;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ImportAsmSettlementsCommand extends Command
{
    protected $signature = 'asm:import-settlements
        {--path=resources/data/asm : Directory containing ASM workbooks}
        {--pattern= : Workbook filename glob (defaults to *.xlsx)}
        {--chunk=500 : Insert chunk size}
        {--status-file= : Optional progress JSON path}
        {--lock-file= : Optional lock file path}
        {--run-id= : Identifier supplied by the initiating web session}
        {--trigger=Command line : Description of what initiated the import}';

    protected $description = 'Import Fundserv ASM settlement workbooks';

    private const EXPECTED_HEADERS = [
        'settlementdate' => 'settlement_date',
        'paymentid' => 'payment_id',
        'sendercompanycode' => 'sender_company_code',
        'sendercompanyname' => 'sender_company_name',
        'receivercompanycode' => 'receiver_company_code',
        'receivercompanyname' => 'receiver_company_name',
        'paymenttype' => 'payment_type',
        'paymenttypedescription' => 'payment_type_description',
        'currency' => 'currency',
        'senderpaymentstream' => 'sender_payment_stream',
        'receiverpaymentstream' => 'receiver_payment_stream',
        'settlementamount' => 'settlement_amount',
        'senderaccountnumber' => 'sender_account_number',
        'senderaccountholdername' => 'sender_account_holder_name',
        'senderaccountholdersin' => 'sender_account_holder_sin',
        'senderdealercode' => 'sender_dealer_code',
        'senderrepcode' => 'sender_rep_code',
        'senderaccounttype' => 'sender_account_type',
        'senderaccounttypedescription' => 'sender_account_type_description',
        'senderlockedinaccount' => 'sender_locked_in_account',
        'senderjurisdiction' => 'sender_jurisdiction',
        'senderspousalaccount' => 'sender_spousal_account',
        'senderspousalname' => 'sender_spousal_name',
        'senderspousalsin' => 'sender_spousal_sin',
        'senderadditionalinformation' => 'sender_additional_information',
        'receiverdealeraccountnumber' => 'receiver_dealer_account_number',
        'receiverfundcompanyaccountnumber' => 'receiver_fund_company_account_number',
        'receiverintermediaryaccountnumber' => 'receiver_intermediary_account_number',
        'receiverdealercode' => 'receiver_dealer_code',
        'receiverrepcode' => 'receiver_rep_code',
        'receiveraccountholdername' => 'receiver_account_holder_name',
        'contactname' => 'contact_name',
        'contactemail' => 'contact_email',
        'contactphone' => 'contact_phone',
        'createdate' => 'create_date',
    ];

    public function handle(): int
    {
        $directory = base_path((string) $this->option('path'));
        $pattern = $this->stringOption('pattern') ?? '*.xlsx';
        $chunkSize = max(1, (int) $this->option('chunk'));
        $statusFile = $this->stringOption('status-file');
        $lockFile = $this->stringOption('lock-file');
        $runId = $this->stringOption('run-id');
        $trigger = $this->stringOption('trigger') ?? 'Command line';
        $startedAt = now()->toIso8601String();

        if ($lockFile) {
            @file_put_contents($lockFile, $startedAt);
        }

        try {
            if (!is_dir($directory)) {
                return $this->finish($statusFile, $runId, $trigger, $startedAt, false, "Directory not found: {$directory}");
            }

            $files = glob($directory . DIRECTORY_SEPARATOR . $pattern) ?: [];
            sort($files);

            if ($files === []) {
                return $this->finish($statusFile, $runId, $trigger, $startedAt, true, "No ASM files matched {$pattern}.", 0, 0);
            }

            $processed = 0;
            $importedFiles = 0;
            $skippedFiles = 0;
            $failedFiles = 0;
            $insertedRows = 0;
            $totalFiles = count($files);

            foreach ($files as $filePath) {
                $filename = basename($filePath);
                $this->writeStatus($statusFile, [
                    'run_id' => $runId,
                    'trigger' => $trigger,
                    'inProgress' => true,
                    'success' => null,
                    'message' => 'Importing ASM workbooks...',
                    'current_file' => $filename,
                    'processed_files' => $processed,
                    'total_files' => $totalFiles,
                    'progress_pct' => $this->percentage($processed, $totalFiles),
                    'started_at' => $startedAt,
                    'updated_at' => now()->toIso8601String(),
                ]);
                if ($lockFile) {
                    @touch($lockFile);
                }

                $fileHash = hash_file('sha256', $filePath);
                if (AsmSettlementSummary::query()->where('file_hash', $fileHash)->exists()) {
                    $skippedFiles++;
                    $processed++;
                    $this->line("Skipped previously imported file {$filename}");
                    continue;
                }

                try {
                    $insertedRows += $this->importFile($filePath, $fileHash, $chunkSize);
                    $importedFiles++;
                } catch (\Throwable $e) {
                    $failedFiles++;
                    $this->error("Failed {$filename}: {$e->getMessage()}");
                }

                $processed++;
            }

            $message = sprintf(
                'Completed ASM import. Imported %d files and %d rows; skipped %d previously imported files.',
                $importedFiles,
                $insertedRows,
                $skippedFiles
            );
            if ($failedFiles > 0) {
                $message .= sprintf(' %d files failed.', $failedFiles);
            }

            return $this->finish(
                $statusFile,
                $runId,
                $trigger,
                $startedAt,
                $failedFiles === 0,
                $message,
                $processed,
                $totalFiles
            );
        } finally {
            if ($lockFile && file_exists($lockFile)) {
                @unlink($lockFile);
            }
        }
    }

    private function importFile(string $filePath, string $fileHash, int $chunkSize): int
    {
        $filename = basename($filePath);
        $import = Import::create([
            'type' => 'asm-settlements',
            'filename' => $filename,
            'file_size' => filesize($filePath) ?: 0,
            'status' => 'processing',
            'import_started_at' => now(),
        ]);

        try {
            $spreadsheet = IOFactory::load($filePath);
            $sheet = $spreadsheet->getActiveSheet();
            $highestColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
            $highestRow = $sheet->getHighestDataRow();
            $headerMap = [];

            for ($column = 1; $column <= $highestColumn; $column++) {
                $normalized = $this->normalizeHeader($sheet->getCell([$column, 1])->getFormattedValue());
                if (isset(self::EXPECTED_HEADERS[$normalized])) {
                    $headerMap[self::EXPECTED_HEADERS[$normalized]] = $column;
                }
            }

            if (!isset($headerMap['settlement_date'])) {
                $settlementDateColumn = $this->inferSettlementDateColumn(
                    $sheet,
                    $filename,
                    $highestColumn,
                    $highestRow,
                    $headerMap
                );
                if ($settlementDateColumn !== null) {
                    $headerMap['settlement_date'] = $settlementDateColumn;
                    $this->warn(
                        "Recovered the settlement date column in {$filename} from values matching its filename date."
                    );
                }
            }

            $required = ['settlement_date', 'payment_id', 'payment_type', 'currency', 'settlement_amount'];
            $missing = array_values(array_diff($required, array_keys($headerMap)));
            if ($missing !== []) {
                throw new \RuntimeException('Missing required ASM columns: ' . implode(', ', $missing));
            }

            $inserted = DB::transaction(function () use (
                $sheet,
                $highestRow,
                $headerMap,
                $import,
                $filename,
                $fileHash,
                $chunkSize
            ): int {
                $summary = AsmSettlementSummary::create([
                    'import_id' => $import->id,
                    'source_file' => $filename,
                    'file_hash' => $fileHash,
                ]);
                $batch = [];
                $insertedRows = 0;

                for ($rowNumber = 2; $rowNumber <= $highestRow; $rowNumber++) {
                    $raw = [];
                    foreach ($headerMap as $field => $column) {
                        $raw[$field] = trim((string) $sheet->getCell([$column, $rowNumber])->getFormattedValue());
                    }

                    if (collect($raw)->every(fn(string $value): bool => $value === '')) {
                        continue;
                    }

                    $record = $this->normalizeRecord($raw);
                    $recordHash = hash('sha256', json_encode($record, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
                    $batch[] = array_merge($record, [
                        'import_id' => $import->id,
                        'asm_settlement_summary_id' => $summary->id,
                        'source_file' => $filename,
                        'row_number' => $rowNumber,
                        'record_hash' => $recordHash,
                        'raw_json' => json_encode($raw, JSON_THROW_ON_ERROR),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    if (count($batch) >= $chunkSize) {
                        $insertedRows += DB::table('asm_settlement_transactions')->insertOrIgnore($batch);
                        $batch = [];
                    }
                }

                if ($batch !== []) {
                    $insertedRows += DB::table('asm_settlement_transactions')->insertOrIgnore($batch);
                }

                $this->refreshSummary($summary, $highestRow - 1, $insertedRows);

                return $insertedRows;
            });

            $import->update([
                'status' => 'completed',
                'total_rows' => max(0, $highestRow - 1),
                'imported_count' => $inserted,
                'duplicate_count' => max(0, ($highestRow - 1) - $inserted),
                'import_completed_at' => now(),
            ]);

            $this->info("Imported {$inserted} ASM rows from {$filename}");

            return $inserted;
        } catch (\Throwable $e) {
            $import->update([
                'status' => 'failed',
                'error_count' => 1,
                'error_details' => $e->getMessage(),
                'import_completed_at' => now(),
            ]);
            throw $e;
        }
    }

    private function normalizeRecord(array $raw): array
    {
        $record = [];
        foreach (self::EXPECTED_HEADERS as $field) {
            $record[$field] = $this->nullIfEmpty($raw[$field] ?? null);
        }

        $record['settlement_date'] = $this->parseDate($record['settlement_date']);
        $record['create_date'] = $this->parseDateTime($record['create_date']);
        $record['currency'] = $record['currency'] ? strtoupper($record['currency']) : null;
        $record['settlement_amount'] = $this->parseAmount($record['settlement_amount']);
        $record['sender_locked_in_account'] = $this->parseBoolean($record['sender_locked_in_account']);
        $record['sender_spousal_account'] = $this->parseBoolean($record['sender_spousal_account']);

        return $record;
    }

    private function refreshSummary(AsmSettlementSummary $summary, int $sourceRows, int $insertedRows): void
    {
        $query = AsmSettlementTransaction::query()->where('asm_settlement_summary_id', $summary->id);
        $totals = (clone $query)->selectRaw(
            'count(*) as record_count,
             coalesce(sum(settlement_amount), 0) as settlement_total,
             min(settlement_date) as min_settlement_date,
             max(settlement_date) as max_settlement_date,
             min(create_date) as min_create_date,
             max(create_date) as max_create_date'
        )->first();
        $currencyTotals = (clone $query)
            ->selectRaw('currency, count(*) as record_count, sum(settlement_amount) as settlement_total')
            ->groupBy('currency')
            ->orderBy('currency')
            ->get()
            ->keyBy('currency')
            ->toArray();
        $paymentTypeTotals = (clone $query)
            ->selectRaw('payment_type, payment_type_description, count(*) as record_count, sum(settlement_amount) as settlement_total')
            ->groupBy('payment_type', 'payment_type_description')
            ->orderBy('payment_type')
            ->get()
            ->keyBy('payment_type')
            ->toArray();

        $summary->update([
            'record_count' => (int) ($totals->record_count ?? 0),
            'duplicate_count' => max(0, $sourceRows - $insertedRows),
            'total_settlement_amount' => $totals->settlement_total ?? 0,
            'min_settlement_date' => $totals->min_settlement_date,
            'max_settlement_date' => $totals->max_settlement_date,
            'min_create_date' => $totals->min_create_date,
            'max_create_date' => $totals->max_create_date,
            'currency_totals' => $currencyTotals,
            'payment_type_totals' => $paymentTypeTotals,
            'raw_summary_json' => [
                'source_rows' => $sourceRows,
                'inserted_rows' => $insertedRows,
            ],
        ]);
    }

    private function parseDate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (preg_match('/^\d{8}$/', $value)) {
            return Carbon::createFromFormat('Ymd', $value)->toDateString();
        }
        if (is_numeric($value)) {
            return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }

        return Carbon::parse($value)->toDateString();
    }

    private function parseDateTime(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (preg_match('/^\d{14}$/', $value)) {
            return Carbon::createFromFormat('YmdHis', $value)->format('Y-m-d H:i:s');
        }
        if (preg_match('/^\d{8}$/', $value)) {
            return Carbon::createFromFormat('Ymd', $value)->startOfDay()->format('Y-m-d H:i:s');
        }
        if (is_numeric($value)) {
            return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d H:i:s');
        }

        return Carbon::parse($value)->format('Y-m-d H:i:s');
    }

    private function parseAmount(?string $value): float
    {
        $normalized = str_replace([',', '$', ' '], '', (string) $value);
        if (preg_match('/^\((.*)\)$/', $normalized, $matches)) {
            $normalized = '-' . $matches[1];
        }

        return is_numeric($normalized) ? round((float) $normalized, 2) : 0.0;
    }

    private function parseBoolean(?string $value): ?bool
    {
        if ($value === null) {
            return null;
        }
        $normalized = strtolower(trim($value));
        if (in_array($normalized, ['1', 'y', 'yes', 'true'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'n', 'no', 'false'], true)) {
            return false;
        }

        return null;
    }

    private function finish(
        ?string $statusFile,
        ?string $runId,
        string $trigger,
        string $startedAt,
        bool $success,
        string $message,
        int $processedFiles = 0,
        int $totalFiles = 0
    ): int {
        $this->writeStatus($statusFile, [
            'run_id' => $runId,
            'trigger' => $trigger,
            'inProgress' => false,
            'success' => $success,
            'message' => $message,
            'current_file' => null,
            'processed_files' => $processedFiles,
            'total_files' => $totalFiles,
            'progress_pct' => 100,
            'started_at' => $startedAt,
            'updated_at' => now()->toIso8601String(),
            'completed_at' => now()->toIso8601String(),
        ]);
        $success ? $this->info($message) : $this->error($message);

        return $success ? self::SUCCESS : self::FAILURE;
    }

    private function writeStatus(?string $statusFile, array $payload): void
    {
        if (!$statusFile) {
            return;
        }
        $temporaryFile = $statusFile . '.tmp';
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (@file_put_contents($temporaryFile, $json, LOCK_EX) !== false) {
            @rename($temporaryFile, $statusFile);
        }
    }

    private function percentage(int $processed, int $total): int
    {
        return $total > 0 ? min(100, (int) floor(($processed / $total) * 100)) : 0;
    }

    private function normalizeHeader(mixed $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(trim((string) $value))) ?? '';
    }

    /** @param array<string, int> $headerMap */
    private function inferSettlementDateColumn(
        Worksheet $sheet,
        string $filename,
        int $highestColumn,
        int $highestRow,
        array $headerMap
    ): ?int {
        if (!preg_match('/^asm_AGRA_(\d{8})\.xlsx$/i', $filename, $matches)) {
            return null;
        }

        try {
            $expectedDate = Carbon::createFromFormat('Ymd', $matches[1])->toDateString();
        } catch (\Throwable) {
            return null;
        }

        $mappedColumns = array_values($headerMap);
        $candidates = [];

        for ($column = 1; $column <= $highestColumn; $column++) {
            if (in_array($column, $mappedColumns, true)) {
                continue;
            }

            $hasValue = false;
            for ($rowNumber = 2; $rowNumber <= $highestRow; $rowNumber++) {
                $value = trim((string) $sheet->getCell([$column, $rowNumber])->getFormattedValue());
                if ($value === '') {
                    continue;
                }

                $hasValue = true;
                try {
                    if ($this->parseDate($value) !== $expectedDate) {
                        continue 2;
                    }
                } catch (\Throwable) {
                    continue 2;
                }
            }

            if ($hasValue) {
                $candidates[] = $column;
            }
        }

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    private function nullIfEmpty(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function stringOption(string $option): ?string
    {
        $value = $this->option($option);
        if (is_array($value)) {
            $value = collect($value)->first(fn(mixed $item): bool => $this->nullIfEmpty($item) !== null);
        }

        return $this->nullIfEmpty($value);
    }
}
