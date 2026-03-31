<?php

namespace App\Console\Commands;

use App\Models\AccountFeeTransaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ImportAccountFeesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:account-fees {file : Path to the CSV file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import account fees and taxes from a CSV file';

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
            $this->info("Starting import of account fees from: {$filePath}");
            
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
                    $data = [
                        'rep_code' => $row[0] ?? null,
                        'client_name' => $row[1] ?? null,
                        'plan_description' => $row[2] ?? null,
                        'account_description' => $row[3] ?? null,
                        'transaction_type' => $row[4] ?? null,
                        'wire_number' => $row[5] ?? null,
                        'trade_date' => !empty($row[6]) ? \Carbon\Carbon::createFromFormat('m/d/Y', $row[6])->toDateString() : null,
                        'settlement_date' => !empty($row[7]) ? \Carbon\Carbon::createFromFormat('m/d/Y', $row[7])->toDateString() : null,
                        'amount' => !empty($row[8]) ? floatval(str_replace(['$', ','], '', $row[8])) : 0,
                        'order_status' => $row[9] ?? null,
                        'trust_status' => $row[10] ?? null,
                        'user_id' => $row[11] ?? null,
                    ];

                    $batch[] = $data;

                    if (count($batch) >= $batchSize) {
                        AccountFeeTransaction::insert($batch);
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
                AccountFeeTransaction::insert($batch);
                $imported += count($batch);
            }

            fclose($file);

            $this->info("✓ Import completed!");
            $this->info("Imported: {$imported} records");
            $this->info("Skipped: {$skipped} records");

            Log::info("ImportAccountFeesCommand: Imported {$imported} account fee records, skipped {$skipped}");

            return 0;
        } catch (\Exception $e) {
            $this->error("Error during import: " . $e->getMessage());
            Log::error("ImportAccountFeesCommand failed: " . $e->getMessage());
            return 1;
        }
    }
}
