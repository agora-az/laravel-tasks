<?php

namespace App\Console\Commands;

use App\Models\AdvisoryFeeTransaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ImportAdvisoryFeesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:advisory-fees {file : Path to the CSV file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import advisory fee transactions from a CSV file';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $filePath = $this->argument('file');

        if (!file_exists($filePath)) {
            $this->error("File not found: {$filePath}");
            return 1;
        }

        try {
            $this->info("Starting import of advisory fee transactions from: {$filePath}");
            
            $file = fopen($filePath, 'r');
            $headers = fgetcsv($file); // Skip header row
            
            $imported = 0;
            $skipped = 0;
            $batchSize = 100;
            $batch = [];

            while (($row = fgetcsv($file)) !== false) {
                if (empty(array_filter($row))) {
                    continue; // Skip empty rows
                }

                try {
                    $firstName = $row[1] ?? null;
                    $lastName = $row[2] ?? null;
                    $fullName = trim(($firstName ?? '') . ' ' . ($lastName ?? ''));

                    $data = [
                        'rep_code' => $row[0] ?? null,
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'full_name' => !empty($fullName) ? $fullName : null,
                        'plan_id' => $row[3] ?? null,
                        'plan_info' => $row[4] ?? null,
                        'transaction_type' => $row[5] ?? null,
                        'fund_code' => $row[6] ?? null,
                        'fund_id' => $row[7] ?? null,
                        'fund_description' => $row[9] ?? null,
                        'description' => $row[10] ?? null,
                        'trust_status' => $row[11] ?? null,
                        'effective_date' => !empty($row[12]) ? \Carbon\Carbon::createFromFormat('m/d/Y', $row[12])->toDateString() : null,
                        'settlement_date' => !empty($row[13]) ? \Carbon\Carbon::createFromFormat('m/d/Y', $row[13])->toDateString() : null,
                        'amount' => !empty($row[14]) ? floatval(str_replace(['$', ',', '-'], '', $row[14])) : 0,
                        'currency' => $row[15] ?? 'CAD',
                        'created_user_id' => $row[16] ?? null,
                        'last_modified_user_id' => $row[18] ?? null,
                    ];

                    $batch[] = $data;

                    if (count($batch) >= $batchSize) {
                        AdvisoryFeeTransaction::insert($batch);
                        $imported += count($batch);
                        $batch = [];
                        $this->line("Imported {$imported} records...");
                    }
                } catch (\Exception $e) {
                    Log::warning("Skipped row due to error: " . $e->getMessage());
                    $skipped++;
                }
            }

            // Insert remaining batch
            if (!empty($batch)) {
                AdvisoryFeeTransaction::insert($batch);
                $imported += count($batch);
            }

            fclose($file);

            $this->info("✓ Import completed!");
            $this->info("Imported: {$imported} records");
            $this->info("Skipped: {$skipped} records");

            Log::info("ImportAdvisoryFeesCommand: Imported {$imported} advisory fee transaction records, skipped {$skipped}");

            return 0;
        } catch (\Exception $e) {
            $this->error("Error during import: " . $e->getMessage());
            Log::error("ImportAdvisoryFeesCommand failed: " . $e->getMessage());
            return 1;
        }
    }
}
