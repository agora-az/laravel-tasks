<?php

namespace App\Console\Commands;

use App\Models\BankEftFile;
use App\Models\BankEftTransaction;
use App\Models\Import;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ImportBankEftFilesCommand extends Command
{
    protected $signature = 'bank:import-eft-files
        {--path=storage/app/bank-eft-sync : Directory containing fixed-width bank EFT files}
        {--pattern=CIBC1464.P*.dat : Filename glob}
        {--chunk=500 : Insert chunk size}
        {--force : Re-import filenames already completed}';

    protected $description = 'Parse CIBC fixed-width bank EFT files for reconciliation with VieFund EFT records';

    public function handle(): int
    {
        $directory = base_path((string) $this->option('path'));
        $files = glob($directory . DIRECTORY_SEPARATOR . (string) $this->option('pattern')) ?: [];
        sort($files);

        if (!is_dir($directory)) {
            $this->error("Directory not found: {$directory}");
            return self::FAILURE;
        }
        if ($files === []) {
            $this->info('No bank EFT files found.');
            return self::SUCCESS;
        }

        $failures = 0;
        foreach ($files as $path) {
            $filename = basename($path);
            $completed = Import::query()
                ->where('type', 'bank-eft-fixed-width')
                ->where('filename', $filename)
                ->where('status', 'completed')
                ->exists();
            if ($completed && !$this->option('force')) {
                $this->line("Skipping previously imported {$filename}");
                continue;
            }

            $import = Import::create([
                'type' => 'bank-eft-fixed-width',
                'filename' => $filename,
                'file_size' => filesize($path) ?: 0,
                'status' => 'processing',
                'import_started_at' => now(),
            ]);

            try {
                $count = DB::transaction(fn() => $this->importFile($path, $import, max(1, (int) $this->option('chunk'))));
                $import->update([
                    'status' => 'completed',
                    'total_rows' => $count,
                    'imported_count' => $count,
                    'error_count' => 0,
                    'import_completed_at' => now(),
                ]);
                $this->info("Imported {$count} bank EFT transactions from {$filename}");
            } catch (\Throwable $e) {
                $failures++;
                $errorMessage = mb_substr(mb_scrub($e->getMessage(), 'UTF-8'), 0, 60000);
                $import->update([
                    'status' => 'failed',
                    'error_count' => 1,
                    'error_details' => $errorMessage,
                    'import_completed_at' => now(),
                ]);
                $this->error("Failed {$filename}: {$errorMessage}");
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function importFile(string $path, Import $import, int $chunkSize): int
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false || count($lines) < 3) {
            throw new \RuntimeException('The file does not contain a header, details, and trailer.');
        }

        $lines = array_map(fn($line) => rtrim($line, "\r\n"), $lines);
        $header = $lines[0];
        $trailer = $lines[count($lines) - 1];
        if (($header[0] ?? '') !== 'A' || ($trailer[0] ?? '') !== 'Z') {
            throw new \RuntimeException('Expected an A header and Z trailer record.');
        }

        $filename = basename($path);
        if (!preg_match('/\.P(\d{8})_(\d+)_/i', $filename, $filenameMatch)) {
            throw new \RuntimeException('Unable to read the file date and sequence from the filename.');
        }

        $sequence = (int) $filenameMatch[2];
        $headerSequence = (int) substr($header, 20, 4);
        $trailerSequence = (int) substr($trailer, 20, 4);
        if ($sequence !== $headerSequence || $sequence !== $trailerSequence) {
            throw new \RuntimeException("Filename sequence {$sequence} does not match the header/trailer sequence.");
        }

        $fileDate = Carbon::createFromFormat('Ymd', $filenameMatch[1])->toDateString();
        $declaredDebitTotalCents = (int) substr($trailer, 24, 14);
        $declaredDebitCount = (int) substr($trailer, 38, 8);
        $declaredCreditTotalCents = (int) substr($trailer, 46, 14);
        $declaredCreditCount = (int) substr($trailer, 60, 8);
        $declaredCount = $declaredDebitCount + $declaredCreditCount;
        $declaredTotalCents = $declaredDebitTotalCents + $declaredCreditTotalCents;
        $logicalRecordCount = (int) substr($trailer, 1, 9);
        if ($logicalRecordCount !== count($lines)) {
            throw new \RuntimeException("Trailer declares {$logicalRecordCount} logical records; found " . count($lines) . '.');
        }

        if ($this->option('force')) {
            BankEftFile::query()->where('source_file', $filename)->delete();
        }

        $file = BankEftFile::create([
            'import_id' => $import->id,
            'source_file' => $filename,
            'sequence_number' => $sequence,
            'file_date' => $fileDate,
            'client_number' => trim(substr($header, 10, 10)) ?: null,
            'currency' => preg_match('/\b(CAD|USD)\b/', substr($header, 24, 60), $currencyMatch) ? $currencyMatch[1] : null,
            'logical_record_count' => $logicalRecordCount,
            'declared_transaction_count' => $declaredCount,
            'parsed_transaction_count' => 0,
            'declared_total_amount' => $declaredTotalCents / 100,
            'parsed_total_amount' => 0,
            'header_hash' => hash('sha256', $header),
            'trailer_hash' => hash('sha256', $trailer),
        ]);

        $batch = [];
        $parsedCount = 0;
        $parsedTotalCents = 0;
        foreach (array_slice($lines, 1, -1, true) as $lineIndex => $line) {
            $recordType = $line[0] ?? '';
            if (!in_array($recordType, ['C', 'D'], true)) {
                throw new \RuntimeException('Unexpected record type on line ' . ($lineIndex + 1) . '.');
            }
            if (strlen($line) !== 1464) {
                throw new \RuntimeException('Line ' . ($lineIndex + 1) . ' is not 1,464 characters.');
            }

            for ($segmentNumber = 1; $segmentNumber <= 6; $segmentNumber++) {
                $segment = substr($line, 24 + (($segmentNumber - 1) * 240), 240);
                if (trim($segment) === '') {
                    continue;
                }

                $amountCents = (int) substr($segment, 3, 10);
                $account = trim(substr($segment, 28, 12));
                $trustAccount = trim(substr($segment, 178, 12));
                $batch[] = [
                    'bank_eft_file_id' => $file->id,
                    'import_id' => $import->id,
                    'source_file' => $filename,
                    'sequence_number' => $sequence,
                    'line_number' => $lineIndex + 1,
                    'segment_number' => $segmentNumber,
                    'record_type' => $recordType,
                    'transaction_code' => trim(substr($segment, 0, 3)) ?: null,
                    'amount' => $amountCents / 100,
                    'effective_date' => $this->parseJulianDate(substr($segment, 13, 6)),
                    'bank_code' => trim(substr($segment, 20, 3)) ?: null,
                    'bank_transit' => trim(substr($segment, 23, 5)) ?: null,
                    'bank_account_last4' => $account !== '' ? substr($account, -4) : null,
                    'bank_account_hash' => $account !== '' ? hash('sha256', $account) : null,
                    'originator_short_name' => $this->text(substr($segment, 65, 15)),
                    'holder_name' => $this->text(substr($segment, 80, 30)),
                    'originator_long_name' => $this->text(substr($segment, 110, 30)),
                    'originator_id' => $this->text(substr($segment, 140, 10)),
                    'holder_id' => $this->text(substr($segment, 150, 19)),
                    'trust_account_last4' => $trustAccount !== '' ? substr($trustAccount, -4) : null,
                    'trust_account_hash' => $trustAccount !== '' ? hash('sha256', $trustAccount) : null,
                    'record_hash' => hash('sha256', $segment),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $parsedCount++;
                $parsedTotalCents += $amountCents;

                if (count($batch) >= $chunkSize) {
                    BankEftTransaction::insert($batch);
                    $batch = [];
                }
            }
        }

        if ($batch !== []) {
            BankEftTransaction::insert($batch);
        }
        if ($parsedCount !== $declaredCount || $parsedTotalCents !== $declaredTotalCents) {
            throw new \RuntimeException(sprintf(
                'Trailer mismatch: parsed %d / $%.2f; declared %d / $%.2f.',
                $parsedCount,
                $parsedTotalCents / 100,
                $declaredCount,
                $declaredTotalCents / 100
            ));
        }

        $file->update([
            'parsed_transaction_count' => $parsedCount,
            'parsed_total_amount' => $parsedTotalCents / 100,
        ]);

        return $parsedCount;
    }

    private function parseJulianDate(string $raw): ?string
    {
        $digits = preg_replace('/\D/', '', $raw);
        if (strlen($digits) < 5) {
            return null;
        }

        $year = 2000 + (int) substr($digits, -5, 2);
        $day = (int) substr($digits, -3);
        if ($day < 1 || $day > 366) {
            throw new \RuntimeException("Invalid Julian date: {$raw}");
        }

        return Carbon::create($year, 1, 1)->addDays($day - 1)->toDateString();
    }

    private function text(string $raw): ?string
    {
        $value = trim(mb_convert_encoding($raw, 'UTF-8', 'Windows-1252'));

        return $value !== '' ? $value : null;
    }
}
