<?php

use App\Console\Commands\GenerateVieFundAllTransactionsExportCommand;
use App\Http\Controllers\RemoteVieFundController;
use App\Models\User;
use App\Services\VieFund\VieFundTransactionMirrorManager;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$directory = sys_get_temp_dir().'/agora-export-status-'.bin2hex(random_bytes(8));
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
$exitCode = 0;

try {
    $app->make(Kernel::class)->bootstrap();
    config([
        'cache.default' => 'array',
        'session.driver' => 'array',
        'view.compiled' => $directory,
        'logging.channels.all_transactions_export.path' => $directory.'/export.log',
    ]);
    $user = new User();
    $user->id = 17;
    auth()->setUser($user);
    $controller = (new ReflectionClass(RemoteVieFundController::class))->newInstanceWithoutConstructor();
    $runId = (string) Str::uuid();
    $requestId = (string) Str::uuid();
    $request = Request::create('/viefund-transactions/export/status', 'GET', ['run_id' => $runId]);
    $request->headers->set('Accept', 'application/json');
    $statusPath = $directory."/app/reports/viefund-all-transactions-17-{$runId}-status.json";
    $lockPath = $directory.'/app/reports/viefund-all-transactions-17.lock';
    mkdir(dirname($statusPath), 0700, true);
    $write = static function (string $path, array $data): void {
        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
        clearstatcache(true, $path);
    };
    $base = [
        'run_id' => $runId, 'request_id' => $requestId, 'user_id' => 17,
        'inProgress' => true, 'success' => null, 'updated_at' => now()->toIso8601String(),
    ];
    $lock = ['run_id' => $runId, 'user_id' => 17];

    $invalid = $controller->allTransactionsExportStatus(Request::create('/status'));
    $check($invalid->getStatusCode() === 422 && $invalid->getData(true)['state'] === 'invalid_run',
        'Missing run IDs must not return Idle.');
    $missing = $controller->allTransactionsExportStatus($request)->getData(true);
    $check($missing['state'] === 'unavailable' && $missing['inProgress'] === false
        && str_contains($missing['message'], 'Generate a new'), 'Stale runs must have explicit recovery guidance.');
    $check(str_contains($controller->allTransactionsExportStatus($request)->headers->get('Cache-Control'), 'no-store'),
        'Status responses must not be cached.');
    echo "PASS: missing/invalid run states and no-store headers\n";

    file_put_contents($statusPath, '{incomplete');
    $check($controller->allTransactionsExportStatus($request)->getStatusCode() === 503,
        'Corrupt status files must be retryable errors, not missing or successful runs.');
    $write($statusPath, [...$base, 'run_id' => (string) Str::uuid()]);
    $check($controller->allTransactionsExportStatus($request)->getStatusCode() === 503,
        'Status identity must match the requested run.');
    $write($statusPath, ['run_id' => $runId]);
    $check($controller->allTransactionsExportStatus($request)->getStatusCode() === 503,
        'A status with missing required fields must be rejected.');
    echo "PASS: corrupt and mismatched status data is explicitly rejected\n";

    $write($lockPath, $lock);
    $write($statusPath, [...$base, 'worker_pid' => getmypid(), 'worker_host' => gethostname()]);
    $check($controller->allTransactionsExportStatus($request)->getData(true)['inProgress'] === true,
        'A live local worker must continue polling.');
    $write($statusPath, [...$base, 'worker_pid' => 2147483647, 'worker_host' => 'another-instance']);
    $check($controller->allTransactionsExportStatus($request)->getData(true)['inProgress'] === true && is_file($lockPath),
        'A different instance PID must not be checked locally or have its lock removed.');
    $write($statusPath, [...$base, 'worker_pid' => 2147483647]);
    $check($controller->allTransactionsExportStatus($request)->getData(true)['inProgress'] === true,
        'Legacy workers without instance identity must not be declared dead by a local PID check.');
    if (function_exists('posix_kill')) {
        $write($statusPath, [...$base, 'worker_pid' => 2147483647, 'worker_host' => gethostname()]);
        $dead = $controller->allTransactionsExportStatus($request)->getData(true);
        $check($dead['success'] === false && $dead['state'] === 'failed' && !is_file($lockPath),
            'A confirmed dead local worker must fail explicitly and release its lock.');
    }
    $write($lockPath, $lock);
    $write($statusPath, [...$base, 'updated_at' => now()->subMinutes(2)->toIso8601String()]);
    $unacknowledged = $controller->allTransactionsExportStatus($request)->getData(true);
    $check($unacknowledged['success'] === false && !is_file($lockPath),
        'A worker that never acknowledges startup must fail and release its lock.');
    $write($statusPath, $base);
    $expired = $controller->allTransactionsExportStatus($request)->getData(true);
    $check($expired['state'] === 'unavailable' && str_contains($expired['message'], 'expired'),
        'A nonterminal status without a live lock must not appear successful.');
    echo "PASS: local/remote worker identity, startup acknowledgement and expired-lock handling\n";

    $output = 'reports/viefund_all_transactions_17_test.xlsx';
    file_put_contents($directory.'/app/'.$output, 'test workbook');
    $write($statusPath, [...$base, 'inProgress' => false, 'success' => true, 'output_relative_path' => $output]);
    URL::forceRootUrl('https://wrong-configured-host.example.test');
    $complete = $controller->allTransactionsExportStatus($request)->getData(true);
    $check($complete['state'] === 'complete' && str_starts_with($complete['download_url'], '/viefund-transactions/export/download?'),
        'Download URLs must remain relative even with a different configured hostname.');
    $download = $controller->downloadAllTransactionsExport($request);
    $check($download->getFile()->getPathname() === $directory.'/app/'.$output, 'Completed exports must still be downloadable.');
    unlink($directory.'/app/'.$output);
    $check($controller->allTransactionsExportStatus($request)->getData(true)['state'] === 'unavailable',
        'A completed export without its workbook must not announce a successful download.');
    echo "PASS: completed downloads use same-origin paths and missing workbooks are explicit\n";

    $mirror = new class extends VieFundTransactionMirrorManager {
        public function __construct() {}

        public function shouldUseMirror(?User $user, array $filters = []): bool
        {
            return true;
        }
    };
    (new ReflectionProperty(RemoteVieFundController::class, 'mirrorManager'))->setValue($controller, $mirror);
    $write($lockPath, $lock);
    $startRequest = Request::create('/viefund-transactions/export', 'POST', [
        'request_id' => $requestId,
        'filter_date_from' => '2026-10-01', 'filter_date_to' => '2026-10-25',
        'filter_date_basis' => 'settlement_date', 'filter_output_order' => 'desc', 'filter_currency_code' => '00',
    ]);
    $startRequest->setUserResolver(static fn() => $user);
    $conflict = $controller->startAllTransactionsExport($startRequest);
    $check($conflict->getStatusCode() === 409 && $conflict->getData(true)['run_id'] === $runId
        && $conflict->getData(true)['state'] === 'running', 'Retrying a lost start response must identify the existing run.');
    echo "PASS: retrying an already-running export returns its tracking ID without dispatching a duplicate\n";

    unlink($lockPath);
    $fakePhp = $directory.'/php-cli-fixture';
    file_put_contents($fakePhp, "#!/bin/sh\nexit 0\n");
    chmod($fakePhp, 0700);
    mkdir($directory.'/logs', 0700);
    config(['app.php_cli_path' => $fakePhp]);
    $accepted = $controller->startAllTransactionsExport($startRequest);
    $acceptedData = $accepted->getData(true);
    $queuedPath = $directory.'/app/reports/viefund-all-transactions-17-'.$acceptedData['run_id'].'-status.json';
    $queued = json_decode(file_get_contents($queuedPath), true, 512, JSON_THROW_ON_ERROR);
    $check($accepted->getStatusCode() === 202 && $queued['inProgress'] === true
        && $queued['request_id'] === $requestId && $queued['dispatch_host'] === gethostname(),
        'An accepted dispatch must persist correlated, atomic startup tracking before responding.');
    echo "PASS: dispatch shell and queued tracking tested with an isolated no-op CLI fixture, without a live data source\n";

    $diagnosticData = [
        'request_id' => $requestId, 'run_id' => $runId, 'phase' => 'start', 'category' => 'network',
        'http_status' => null, 'attempt' => 0, 'online' => false,
        'message' => 'PRIVATE-MARKER-SHOULD-NOT-BE-LOGGED', 'customer_name' => 'PRIVATE-CUSTOMER',
    ];
    $diagnosticRequest = Request::create('/diagnostics', 'POST', $diagnosticData);
    $check($controller->reportAllTransactionsExportIssue($diagnosticRequest)->getStatusCode() === 200,
        'Valid browser diagnostic metadata must be accepted.');
    try {
        $controller->reportAllTransactionsExportIssue(Request::create('/diagnostics', 'POST', [
            ...$diagnosticData, 'category' => 'arbitrary-log-content',
        ]));
        throw new RuntimeException('Unrecognized diagnostic categories must be rejected.');
    } catch (ValidationException) {
        // Expected input rejection.
    }
    $route = $app['router']->getRoutes()->getByName('viefund-transactions.export.diagnostics');
    $check($route !== null && in_array('auth.check', $route->gatherMiddleware(), true)
        && in_array('throttle:20,1', $route->gatherMiddleware(), true),
        'Browser diagnostics must be authenticated and rate limited.');
    $logs = glob($directory.'/export-*.log');
    $check(count($logs) === 1, 'Diagnostics must use the rotating export log.');
    $log = file_get_contents($logs[0]);
    $check(str_contains($log, $requestId) && str_contains($log, $runId) && str_contains($log, 'Browser export diagnostic.')
        && str_contains($log, 'Export tracking no longer exists.') && str_contains($log, 'Export start requested.')
        && str_contains($log, 'Export dispatch accepted.'),
        'Logs must correlate browser diagnostics, launch requests and tracking failures.');
    $check(!str_contains($log, 'PRIVATE-MARKER') && !str_contains($log, 'PRIVATE-CUSTOMER'),
        'Arbitrary client text and transaction/customer data must not enter diagnostic logs.');
    echo "PASS: correlated, rotating logs accept only validated metadata; diagnostic route has auth and throttling\n";

    $command = $app->make(GenerateVieFundAllTransactionsExportCommand::class);
    (new ReflectionProperty($command, 'runId'))->setValue($command, $runId);
    $writeStatus = new ReflectionMethod($command, 'writeStatus');
    $writeStatus->invoke($command, $statusPath, ['inProgress' => true, 'success' => null]);
    $saved = json_decode(file_get_contents($statusPath), true, 512, JSON_THROW_ON_ERROR);
    $check($saved['request_id'] === $requestId && $saved['worker_host'] === gethostname() && $saved['worker_pid'] === getmypid(),
        'Worker status updates must preserve request correlation and record instance identity.');
    mkdir($directory.'/status-is-directory');
    try {
        $writeStatus->invoke($command, $directory.'/status-is-directory', []);
        throw new LogicException('Storage publication failures must not be silently ignored.');
    } catch (RuntimeException) {
        // Expected storage failure.
    }
    $compiled = $app->make('blade.compiler')->compileString(file_get_contents(
        dirname(__DIR__, 2).'/resources/views/viefund-transactions/index.blade.php'
    ));
    file_put_contents($directory.'/compiled.php', $compiled);
    (new Symfony\Component\Process\Process([PHP_BINARY, '-l', $directory.'/compiled.php']))->mustRun();
    echo "PASS: worker status identity and explicit storage failures; Blade compiles successfully\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception.PHP_EOL);
    $exitCode = 1;
} finally {
    $app->make('files')->deleteDirectory($directory);
}
exit($exitCode);
