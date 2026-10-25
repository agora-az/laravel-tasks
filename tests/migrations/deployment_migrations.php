<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$child = ($argv[1] ?? null) === '--child';
$directory = $child ? $argv[3] : sys_get_temp_dir().'/agora-deployment-'.bin2hex(random_bytes(8));
if (!$child) {
    mkdir($directory, 0700);
}
foreach (['APP_CONFIG_CACHE', 'APP_PACKAGES_CACHE', 'APP_SERVICES_CACHE', 'APP_ROUTES_CACHE'] as $key) {
    putenv($key.'='.$directory.'/'.$key.'-'.getmypid().'.php');
}
putenv('APP_ENV=testing');
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->useStoragePath($directory.'/storage-'.getmypid());
$app->useDatabasePath($directory.'/database');
$kernel = $app->make(Kernel::class);
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$admin = null;
$databaseName = null;
$databaseCreated = false;
$processes = [];
$exit = 0;

try {
    $kernel->bootstrap();
    $config = config('database.connections.mysql');
    if ($socket = getenv('DEPLOYMENT_TEST_DB_SOCKET')) {
        $config['host'] = 'localhost';
        $config['unix_socket'] = $socket;
        $config['url'] = null;
        $config['username'] = 'root';
        $config['password'] = '';
    }
    $check(
        in_array($config['host'], ['127.0.0.1', 'localhost', '::1'], true) && empty($config['url']),
        'Deployment regressions require a local MySQL connection without a DATABASE_URL override.'
    );

    if (!$child) {
        $adminConfig = $config;
        $adminConfig['database'] = null;
        config(['database.connections.deployment_test_admin' => $adminConfig]);
        $admin = DB::connection('deployment_test_admin');
        $databaseName = 'agora_deploy_test_'.bin2hex(random_bytes(6));
        $admin->statement("CREATE DATABASE `{$databaseName}`");
        $databaseCreated = true;
        File::makeDirectory($directory.'/database/migrations', 0700, true);
        File::put($directory.'/database/migrations/2026_01_01_000001_create_deployment_marker.php', <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void {
        DB::select('SELECT SLEEP(3)');
        DB::statement('CREATE TABLE deployment_marker (id INT PRIMARY KEY)');
        DB::insert('INSERT INTO deployment_marker (id) VALUES (1)');
    }
};
PHP);
    } else {
        $databaseName = $argv[2];
        $check((bool) preg_match('/^agora_deploy_test_[a-f0-9]{12}$/D', $databaseName), 'Invalid fixture database.');
    }

    $config['database'] = $databaseName;
    config([
        'database.default' => 'deployment_test',
        'database.connections.deployment_test' => $config,
        'cache.default' => 'array',
        'session.driver' => 'array',
        'view.compiled' => $directory,
    ]);

    if ($child) {
        $exit = $kernel->call('deploy:migrate', ['--wait' => $argv[4] ?? '10']);
        echo $kernel->output();
    } else {
        $start = static function (string $name, string $wait = '10') use ($databaseName, $directory, &$processes) {
            $process = proc_open(
                [PHP_BINARY, __FILE__, '--child', $databaseName, $directory, $wait],
                [0 => ['pipe', 'r'], 1 => ['file', $directory.'/'.$name.'.log', 'w'], 2 => ['file', $directory.'/'.$name.'.log', 'a']],
                $pipes
            );
            if (!is_resource($process)) {
                throw new RuntimeException('Unable to start a migration regression process.');
            }
            fclose($pipes[0]);
            $processes[] = $process;

            return $process;
        };
        $lockName = 'agora:migrate:'.substr(hash('sha256', $databaseName), 0, 48);
        $assertReleased = static function () use ($admin, $lockName, $check): void {
            $check((int) $admin->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lockName])->acquired === 1, 'Migration left its lock held.');
            $check((int) $admin->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName])->released === 1, 'Unable to release fixture verification lock.');
        };

        $first = $start('first');
        $deadline = microtime(true) + 10;
        while ($admin->selectOne('SELECT IS_USED_LOCK(?) AS owner', [$lockName])->owner === null) {
            $check(microtime(true) < $deadline, 'First migration did not obtain its lock: '.File::get($directory.'/first.log'));
            usleep(20000);
        }

        $check($kernel->call('deploy:migrate', ['--wait' => '0']) !== 0, 'A busy migration lock must fail closed.');
        $check(str_contains($kernel->output(), 'No migrations were run'), 'Busy-lock failure must be visible.');
        $second = $start('second');
        $check(proc_close($first) === 0, 'First migration failed: '.File::get($directory.'/first.log'));
        $check(proc_close($second) === 0, 'Waiting migration failed: '.File::get($directory.'/second.log'));
        $check(str_contains(File::get($directory.'/second.log'), 'Nothing to migrate'), 'Waiting process must recheck pending migrations after acquiring the lock.');
        $check(DB::table('deployment_marker')->count() === 1, 'Concurrent startup must apply fixture DDL once.');
        $check(DB::table('migrations')->count() === 1, 'Concurrent startup must record one migration.');
        $assertReleased();
        echo "PASS: concurrent processes serialize pending-migration discovery and DDL; a busy lock fails closed.\n";

        $check($kernel->call('deploy:migrate', ['--wait' => '-1']) !== 0, 'Negative lock waits must be rejected.');
        $check($kernel->call('deploy:migrate', ['--wait' => 'invalid']) !== 0, 'Invalid lock waits must be rejected.');
        $check($kernel->call('deploy:migrate', ['--wait' => '1801']) !== 0, 'Unbounded lock waits must be rejected.');

        $failurePath = $directory.'/database/migrations/2026_01_02_000001_fail_deployment.php';
        foreach ([
            'migration-failure' => 'throw new RuntimeException("Intentional deployment fixture failure.");',
            'connection-loss' => 'DB::disconnect(); DB::select("SELECT 1");',
        ] as $name => $body) {
            File::put($failurePath, '<?php use Illuminate\Database\Migrations\Migration; use Illuminate\Support\Facades\DB; return new class extends Migration { public function up(): void { '.$body.' } };');
            $process = $start($name);
            $check(proc_close($process) !== 0, "$name must fail the deployment.");
            $message = $name === 'migration-failure' ? 'Intentional deployment fixture failure' : 'database connection was lost';
            $check(str_contains(File::get($directory.'/'.$name.'.log'), $message), "$name must expose its original error.");
            $assertReleased();
            File::delete($failurePath);
        }
        echo "PASS: migration exceptions and connection loss fail visibly and release the session lock.\n";

        foreach (['2026_10_15_193500_create_viefund_transaction_mirror_tables', '2026_10_24_000001_add_fund_id_verification_to_viefund_mirror'] as $name) {
            (require dirname(__DIR__, 2).'/database/migrations/'.$name.'.php')->up();
        }
        DB::table('viefund_transaction_mirror_rows')->insert([
            'cash_transaction_id' => 1, 'transaction_id' => 'fixture', 'created_date' => '2020-01-02 03:04:05',
            'amount' => '123.4500', 'payload' => '{}', 'sync_generation' => 'fixture-generation',
            'source_fingerprint' => str_repeat('a', 64), 'source_synced_at' => '2026-01-01 00:00:00',
        ]);
        Schema::table('viefund_transaction_mirror_rows', static function (Blueprint $table): void {
            $table->decimal('trust_amount_used', 20, 4)->nullable();
            $table->dateTime('created_date', 6)->nullable()->change();
            $table->index(['created_date', 'cash_transaction_id'], 'viefund_mirror_created_cursor_idx');
        });
        DB::table('viefund_transaction_mirror_rows')->where('cash_transaction_id', 1)->update([
            'created_date' => '2020-01-02 03:04:05.123456', 'trust_amount_used' => '12.3400',
        ]);
        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_25_000001_add_cashagch_mirror_verification.php';
        DB::connection()->enableQueryLog();
        $migration->up();
        $alterStatements = static fn (): array => array_values(array_filter(
            DB::connection()->getQueryLog(),
            static fn (array $query): bool => str_starts_with(strtolower($query['query']), 'alter table `viefund_transaction_mirror_rows`')
        ));
        $check(count($alterStatements()) === 1, 'Pending mirror-row changes must use exactly one ALTER TABLE.');
        $check(!str_contains($alterStatements()[0]['query'], 'MODIFY COLUMN `created_date`'), 'Already-widened dates must not be rebuilt.');
        foreach (['created', 'trade', 'processing', 'settlement'] as $basis) {
            $check(Schema::hasIndex('viefund_transaction_mirror_rows', "viefund_mirror_{$basis}_cursor_idx"), "$basis cursor index missing.");
            $column = array_column(Schema::getColumns('viefund_transaction_mirror_rows'), null, 'name')[$basis.'_date'];
            $check($column['type'] === 'datetime(6)' && $column['nullable'], "$basis date precision/nullability changed.");
        }
        $row = DB::table('viefund_transaction_mirror_rows')->first();
        $check($row->created_date === '2020-01-02 03:04:05.123456', 'Migration lost fractional timestamps.');
        $check($row->amount === '123.4500' && $row->trust_amount_used === '12.3400', 'Migration changed financial values.');
        DB::connection()->flushQueryLog();
        $migration->up();
        $check($alterStatements() === [], 'A completed migration must not rebuild the mirror again.');
        Schema::table('viefund_transaction_mirror_rows', static fn (Blueprint $table) => $table->dropIndex('viefund_mirror_trade_cursor_idx'));
        DB::connection()->flushQueryLog();
        $migration->up();
        $check(count($alterStatements()) === 1 && !str_contains($alterStatements()[0]['query'], 'MODIFY COLUMN'), 'Index-only recovery must not rebuild completed timestamp changes.');
        $check(Schema::hasIndex('viefund_transaction_mirror_rows', 'viefund_mirror_trade_cursor_idx'), 'Interrupted index creation was not recovered.');
        echo "PASS: partial MySQL schema resumes in one ALTER, preserves values, and avoids repeated timestamp rebuilds.\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error::class.': '.$error->getMessage()."\n");
    $exit = 1;
} finally {
    if (!$child) {
        foreach ($processes as $process) {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
        DB::purge('deployment_test');
        if ($databaseCreated) {
            $admin->statement("DROP DATABASE `{$databaseName}`");
        }
        File::deleteDirectory($directory);
    }
}

exit($exit);
