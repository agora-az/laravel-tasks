<?php

use App\Services\VieFund\Repositories\SqlServerVieFundRemoteRepository;
use App\Support\BoundedQueryCount;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$directory = sys_get_temp_dir().'/agora-count-deadline-test-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);
foreach (['APP_CONFIG_CACHE', 'APP_PACKAGES_CACHE', 'APP_SERVICES_CACHE', 'APP_ROUTES_CACHE'] as $key) {
    putenv($key.'='.$directory.'/'.$key.'.php');
}
putenv('APP_ENV=testing');
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->useEnvironmentPath($directory);
$app->useStoragePath($directory);
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$exit = 0;

try {
    $app->make(Kernel::class)->bootstrap();
    config([
        'database.connections.count_deadline_test' => [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306,
            'database' => null, 'username' => 'root', 'password' => '',
            'unix_socket' => getenv('DEPLOYMENT_TEST_DB_SOCKET') ?: '',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
        ],
    ]);
    $connection = DB::connection('count_deadline_test');
    $query = $connection->query()->fromRaw('(SELECT 1 AS id) AS count_fixture');
    $check(BoundedQueryCount::run(clone $query) === 1, 'Bounded count must return the actual aggregate.');
    $connection->enableQueryLog();
    $started = microtime(true);
    $interrupted = false;
    try {
        BoundedQueryCount::run($query->whereRaw('SLEEP(31) = 0'));
    } catch (QueryException $exception) {
        $check(($exception->errorInfo[1] ?? null) === 3024, 'Expected MySQL execution-deadline error 3024, not another database error.');
        $interrupted = true;
    }
    $elapsed = microtime(true) - $started;
    $check($interrupted && $elapsed >= 29 && $elapsed < 35,
        sprintf('The real MySQL count must be interrupted at 30 seconds; elapsed %.3fs.', $elapsed));
    echo sprintf("PASS: real MySQL execution deadline interrupted the aggregate after %.3fs without modifying any tables\n", $elapsed);

    $repository = $app->make(SqlServerVieFundRemoteRepository::class);
    $sqlsrv = DB::connection('viefund_count_sqlsrv');
    $pdo = new PDO('sqlite::memory:');
    $sqlsrv->setPdo($pdo)->setReadPdo($pdo);
    $filters = [
        'date_basis' => 'trade_date', 'date_from' => '2026-01-01', 'date_to' => '2026-07-31',
        'currency_code' => '00', 'status_ids' => [6],
    ];
    foreach ([null, 'Client'] as $search) {
        $queries = $sqlsrv->pretend(static fn() => $repository->countAllTransactionsWithDeadline($search, $filters));
        $check(count($queries) === 1 && str_contains(strtolower($queries[0]['query']), 'count(*)'),
            'Both fast and enriched remote counts must execute through the deadline-protected connection.');
    }
    $options = config('database.connections.viefund_count_sqlsrv.options');
    $check(defined('PDO::SQLSRV_ATTR_QUERY_TIMEOUT')
        && ($options[constant('PDO::SQLSRV_ATTR_QUERY_TIMEOUT')] ?? null) === 30,
        'Remote count connection must retain its SQL Server statement deadline.');
    $rejected = false;
    try {
        $repository->countAllTransactionsWithDeadline(null, $filters);
    } catch (RuntimeException $exception) {
        $rejected = str_contains($exception->getMessage(), '30-second query deadline');
    }
    $check($rejected, 'A remote count must fail closed when driver-level timeout protection is absent.');
    echo "PASS: fast and enriched SQL Server counts use the dedicated 30-second connection (SQL generation, not a live VieFund timing)\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n".$exception->getTraceAsString()."\n");
    $exit = 1;
} finally {
    DB::disconnect('count_deadline_test');
    DB::disconnect('viefund_count_sqlsrv');
    (new Filesystem())->deleteDirectory($directory);
}
exit($exit);
