<?php

use App\Console\Commands\GenerateVieFundAllTransactionsExportCommand;
use App\Models\VieFundTransactionWorkingSet;
use App\Services\RuntimeSettings;
use App\Services\VieFund\VieFundTransactionWorkingSetManager;
use App\Support\AllTransactionColumns;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use OpenSpout\Reader\XLSX\Reader;
use Symfony\Component\Console\Tester\CommandTester;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$testDirectory = sys_get_temp_dir().'/agora-export-test-'.bin2hex(random_bytes(8));
mkdir($testDirectory, 0700);
foreach (['APP_CONFIG_CACHE', 'APP_PACKAGES_CACHE', 'APP_SERVICES_CACHE', 'APP_ROUTES_CACHE'] as $key) {
    putenv($key.'='.$testDirectory.'/'.$key.'.php');
}
putenv('APP_ENV=testing');

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->useEnvironmentPath($testDirectory);
$app->useStoragePath($testDirectory);

$check = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$testExitCode = 0;

try {
    $app->make(Kernel::class)->bootstrap();
    config([
        'database.default' => 'sqlite',
        'database.connections' => [
            'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        ],
        'cache.default' => 'array',
        'viefund.all_transactions_visible_columns' => array_keys(AllTransactionColumns::availableTransactionColumns()),
        'viefund.all_transactions_match_visible_columns' => ['matched_to_bank'],
        'viefund.all_transactions_export_rows_per_sheet' => 5000,
        'viefund.all_transactions_export_split_target_rows' => 5000,
        'viefund.all_transactions_export_batch_size' => 100,
    ]);

    Schema::create('application_settings', function (Blueprint $table): void {
        $table->string('setting_key')->primary();
        $table->text('value');
    });
    Schema::create('viefund_transaction_working_set_rows', function (Blueprint $table): void {
        $table->unsignedBigInteger('working_set_id');
        $table->string('generation');
        $table->unsignedBigInteger('cash_transaction_id');
        $table->string('fund_id', 100)->nullable();
        $table->dateTime('basis_date');
        $table->decimal('amount', 20, 4);
        $table->string('match_status');
        $table->text('payload');
        $table->text('enrichment');
    });

    foreach (['CASHagch', null, 'VIEFund'] as $index => $fundId) {
        $payload = [
            'cash_transaction_id' => $index + 1,
            'transaction_id' => 'C-'.($index + 1),
            'source_id' => '12345678901234567890',
            'fund_id' => $fundId,
            'customer_name' => 'Test Customer',
            'plan_account_id' => 'TEST-ACCOUNT',
            'amount' => [-2100.77, 100, 300][$index],
            'currency_code' => '00',
            'created_date' => '2026-10-01 12:34:56',
            'settlement_date' => '2026-10-01',
        ];
        DB::table('viefund_transaction_working_set_rows')->insert([
            'working_set_id' => 1,
            'generation' => 'test-generation',
            'cash_transaction_id' => $index + 1,
            'fund_id' => $fundId,
            'basis_date' => '2026-10-01 00:00:00',
            'amount' => $payload['amount'],
            'match_status' => 'Unknown',
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'enrichment' => '{}',
        ]);
    }

    $manager = new class($app->make(RuntimeSettings::class)) extends VieFundTransactionWorkingSetManager
    {
        public function findReady(array $filters): ?VieFundTransactionWorkingSet
        {
            return new VieFundTransactionWorkingSet([
                'id' => 1,
                'active_generation' => 'test-generation',
                'balance_generation' => 'test-generation',
                'balance_report' => [
                    'rows' => [[
                        'report_date' => '2026-10-01',
                        'daily_net_transactions' => -1700.77,
                    ]],
                    'opening_balance' => 0.0,
                    'final_balance' => -1700.77,
                    'balance_source' => 'Test cash ledger',
                ],
            ]);
        }
    };
    $app->instance(VieFundTransactionWorkingSetManager::class, $manager);

    foreach ([
        'all-funds' => ['', true, 3, -1700.77],
        'fund-filtered' => ['CASHagch', true, 1, -2100.77],
        'fund-hidden' => ['', false, 3, -1700.77],
        'fund-filtered-hidden' => ['CASHagch', false, 1, -2100.77],
    ] as $scenario => [$fundFilter, $showFund, $expectedCount, $expectedNet]) {
        $columns = array_keys(AllTransactionColumns::availableTransactionColumns());
        if (! $showFund) {
            $columns = array_values(array_diff($columns, ['fund_id']));
        }
        config(['viefund.all_transactions_visible_columns' => $columns]);

        foreach (['single', 'split'] as $layout) {
            $name = $scenario.'-'.$layout;
            $statusPath = $testDirectory.'/'.$name.'.json';
            $command = $app->make(GenerateVieFundAllTransactionsExportCommand::class);
            $command->setLaravel($app);
            $tester = new CommandTester($command);
            $exitCode = $tester->execute([
                '--fund-id' => $fundFilter,
                '--date-from' => '2026-10-01',
                '--date-to' => '2026-10-01',
                '--output-order' => 'asc',
                '--linked-record-layout' => $layout,
                '--output-base' => $name,
                '--status-file' => $statusPath,
            ]);
            $status = json_decode(file_get_contents($statusPath), true, 512, JSON_THROW_ON_ERROR);
            $check($exitCode === 0 && $status['success'] === true, $name.': '.$tester->getDisplay());
            $check($status['processed_transactions'] === $expectedCount, "$name: incorrect exported count");

            $reader = new Reader();
            $reader->open($testDirectory.'/app/'.$name.'.xlsx');
            $sheets = [];
            try {
                foreach ($reader->getSheetIterator() as $sheet) {
                    $sheets[$sheet->getName()] = [];
                    foreach ($sheet->getRowIterator() as $row) {
                        $sheets[$sheet->getName()][] = $row->toArray();
                    }
                }
            } finally {
                $reader->close();
            }

            $transactions = reset($sheets);
            $headers = array_shift($transactions);
            $check(count($transactions) === $expectedCount, "$name: incorrect workbook row count");
            $fundIndex = array_search('Fund / Unit ID', $headers, true);
            $check(($fundIndex !== false) === $showFund, "$name: incorrect fund column visibility");
            $sourceIndex = array_search('Source ID', $headers, true);
            $amountIndex = array_search('Amount', $headers, true);
            foreach ($transactions as $row) {
                $check(count($row) === count($headers), "$name: headers and cells are misaligned");
                $check($row[$sourceIndex] === '12345678901234567890', "$name: source ID lost text precision");
            }
            if ($showFund) {
                $funds = array_column($transactions, $fundIndex);
                $check($funds === ($fundFilter !== '' ? ['CASHagch'] : ['CASHagch', '', 'VIEFund']), "$name: incorrect fund cells");
            }
            $check(abs(array_sum(array_column($transactions, $amountIndex)) - $expectedNet) < 0.00001, "$name: incorrect transaction total");
            $summary = [];
            foreach ($sheets['Summary'] as $row) {
                if (isset($row[0], $row[1])) {
                    $summary[(string) $row[0]] = $row[1];
                }
            }
            $check($summary['Fund / Unit ID Filter'] === ($fundFilter ?: 'All funds'), "$name: incorrect summary filter");
            $check((int) $summary['Cash Ledger Transactions in Complete Result Set'] === $expectedCount, "$name: incorrect summary count");
            $check(abs((float) $summary['Exported Cash Ledger Amount Total'] - $expectedNet) < 0.00001, "$name: incorrect summary total");
            echo "PASS: $name\n";
        }
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    $testExitCode = 1;
} finally {
    $app->make('files')->deleteDirectory($testDirectory);
}

exit($testExitCode);
