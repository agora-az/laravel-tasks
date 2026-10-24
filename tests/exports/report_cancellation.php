<?php

use App\Console\Commands\GenerateLegacyVieFundDailyBalanceReportCommand;
use App\Console\Commands\GenerateVieFundCustomerBalancesReportCommand;
use App\Console\Commands\GenerateVieFundDailyBalanceReportCommand;
use App\Http\Controllers\VieFundCashAgchReportController;
use App\Http\Controllers\VieFundReportCancellationController;
use App\Http\Controllers\VieFundReportsController;
use App\Jobs\GenerateVieFundCashAgchReport;
use App\Models\User;
use App\Services\RuntimeSettings;
use App\Services\VieFund\VieFundAvailabilityWindow;
use App\Services\VieFund\VieFundCashAgchReport;
use App\Services\VieFund\VieFundRemoteService;
use App\Services\VieFund\VieFundReportCancellation as Cancellation;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\Exception\HttpException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$directory = sys_get_temp_dir().'/agora-cancellation-test-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);
foreach (['APP_CONFIG_CACHE', 'APP_PACKAGES_CACHE', 'APP_SERVICES_CACHE', 'APP_ROUTES_CACHE'] as $key) {
    putenv($key.'='.$directory.'/'.$key.'.php');
}
putenv('APP_ENV=testing');
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->useEnvironmentPath($directory);
$app->useStoragePath($directory);
$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$exitCode = 0;
try {
    $app->make(Kernel::class)->bootstrap();
    config([
        'database.default' => 'sqlite',
        'database.connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']],
        'cache.default' => 'array', 'session.driver' => 'array', 'view.compiled' => $directory,
        'filesystems.default' => 'local', 'filesystems.disks.local.root' => $directory.'/app',
        'viefund.all_transactions_export_batch_size' => 100,
        'viefund.balance_report_cash_account_scope.opened_before' => '2026-10-24',
    ]);
    foreach (['_ENV', '_SERVER'] as $name) $GLOBALS[$name]['VIEFUND_BALANCE_REPORT_CASH_OPENED_BEFORE'] = '2026-10-24';
    putenv('VIEFUND_BALANCE_REPORT_CASH_OPENED_BEFORE=2026-10-24');
    Carbon::setTestNow('2026-10-24 12:00:00 UTC');
    CarbonImmutable::setTestNow('2026-10-24 12:00:00 UTC');
    Schema::create('application_settings', function (Blueprint $table): void {
        $table->string('setting_key')->primary();
        $table->text('value');
    });
    Bus::fake([GenerateVieFundCashAgchReport::class]);
    $owner = (new User())->forceFill(['id' => 17]);
    $other = (new User())->forceFill(['id' => 18]);
    $app['auth']->guard()->setUser($owner);
    $requestFor = static function (string $runId, User $user) use ($app): Request {
        $request = Request::create('/reports/cancel', 'POST', ['run_id' => $runId]);
        $request->headers->set('Accept', 'application/json');
        $request->setUserResolver(static fn() => $user);
        return $request;
    };
    $cancel = new VieFundReportCancellationController();
    $source = new class extends VieFundRemoteService
    {
        public array $calls = [];
        public ?Closure $onQuery = null;
        public function __construct() {}
        private function query(string $name): void
        {
            $this->calls[] = $name;
            if ($this->onQuery !== null) ($this->onQuery)($name);
        }
        public function fetchCustomerCashDailyNetTotalsByDateColumn(CarbonInterface $from, CarbonInterface $to, string $basis, array $filters = []): Collection
        {
            $this->query('daily');
            return collect();
        }
        public function fetchDailyNetTotalsByDateColumn(CarbonInterface $from, CarbonInterface $to, string $basis, array $filters = []): Collection
        {
            $this->query('legacy');
            return collect();
        }
        public function fetchCustomerBalancesByDate(CarbonInterface $date, string $basis, array $filters = []): Collection
        {
            $this->query('customers');
            return collect([(object) ['plan_id' => 1, 'plan_account_id' => 'TEST', 'account_id' => 'TEST', 'total_balance' => 1.23]]);
        }
        public function fetchCustomerBalanceCutoffReview(CarbonInterface $date, string $basis, array $filters = []): Collection
        {
            $this->query('review');
            return collect();
        }
        public function countAllTransactions(?string $search = null, array $filters = []): int
        {
            $this->query('count');
            return 1000;
        }
        public function fetchAllTransactionExportRowsAfter(?string $search, array $filters, ?array $cursor, int $limit): Collection
        {
            $this->query('cashagch');
            return collect([(object) [
                'fund_id' => 'CASHagch', 'transaction_id' => 'C-1', 'sort_id' => 1, 'plan_account_id' => 'TEST',
                'transaction_type' => 'Purchase', 'amount' => '-1.0000', 'basis_date' => '2026-01-01 00:00:00',
            ]]);
        }
    };
    $app->instance(VieFundRemoteService::class, $source);
    $reports = $app->make(VieFundReportsController::class);
    foreach ([
        'daily-balance' => GenerateVieFundDailyBalanceReportCommand::class,
        'daily-balance-legacy' => GenerateLegacyVieFundDailyBalanceReportCommand::class,
        'customer-balances' => GenerateVieFundCustomerBalancesReportCommand::class,
    ] as $type => $class) {
        foreach (['queued', 'running', 'packaging', 'normal', 'failed'] as $mode) {
            $runId = (string) Str::uuid();
            $path = $type === 'daily-balance-legacy'
                ? storage_path('app/reports/viefund-daily-balance-legacy-status.json')
                : storage_path("app/reports/viefund-{$type}-17-{$runId}-status.json");
            $lock = storage_path("app/reports/viefund-{$type}.lock");
            $marker = Cancellation::path($type, 17, $runId);
            $output = "reports/{$type}-{$runId}.csv";
            File::ensureDirectoryExists(dirname($path));
            $initial = ['run_id' => $runId, 'user_id' => 17, 'cancellation_supported' => true, 'inProgress' => true, 'success' => null];
            File::put($path, json_encode($initial, JSON_THROW_ON_ERROR));
            File::put($lock, json_encode(['run_id' => $runId, 'user_id' => 17], JSON_THROW_ON_ERROR));
            $request = $requestFor($runId, $owner);
            $source->calls = [];
            $source->onQuery = null;
            try {
                $cancel($requestFor($runId, $other), $type);
                throw new RuntimeException('Another owner must not cancel this report.');
            } catch (HttpException $exception) {
                $check($exception->getStatusCode() === 404 && !Cancellation::requested($marker), 'Foreign cancellation must not affect the actual report marker.');
            }
            try {
                $cancel($requestFor((string) Str::uuid(), $owner), $type);
                throw new RuntimeException('A stale run ID must not cancel the current run.');
            } catch (HttpException $exception) {
                $check($exception->getStatusCode() === 404 && !Cancellation::requested($marker), 'Cancellation must target exactly the requested run.');
            }
            if ($mode === 'queued') {
                $check($cancel($request, $type)->getStatusCode() === 202, 'CLI cancellation should wait for command acknowledgement.');
            } elseif ($mode === 'running') {
                $source->onQuery = static function () use ($cancel, $request, $type, $check, $reports, $runId): void {
                    $visible = match ($type) {
                        'daily-balance' => $reports->reportStatus($request),
                        'customer-balances' => $reports->customerBalancesReportStatus($request),
                        'daily-balance-legacy' => $reports->legacyDailyBalanceReportStatus($request),
                    };
                    $url = $visible->getData(true)['cancel_url'];
                    $check(str_contains($url, "/reports/{$type}/cancel") && str_contains($url, $runId), 'Each report status must expose its own owner/run-scoped Cancel URL.');
                    $check($cancel($request, $type)->getStatusCode() === 202, 'Running cancellation must be accepted.');
                };
            } elseif ($mode === 'failed') {
                $source->onQuery = static fn() => throw new RuntimeException('Simulated source failure');
            } elseif ($mode === 'packaging') {
                $output = "reports/{$type}-{$runId}.xlsx";
                Excel::swap(new class($cancel, $request, $type)
                {
                    public function __construct(private object $cancel, private Request $request, private string $type) {}
                    public function store($export, string $path, ...$options): bool
                    {
                        File::put(storage_path('app/'.$path), 'partial workbook');
                        ($this->cancel)($this->request, $this->type);
                        return true;
                    }
                });
            }
            $command = $app->make($class);
            $command->setLaravel($app);
            $tester = new CommandTester($command);
            $options = [
                '--run-id' => $runId, '--status-file' => $path, '--lock-file' => $lock,
                '--cancel-file' => $marker, '--output-file' => $output,
                '--format' => $mode === 'packaging' ? 'excel' : 'csv',
            ];
            $options += $type === 'customer-balances'
                ? ['--report-date' => '2026-02-10']
                : ['--date-from' => '2026-01-01', '--date-to' => '2026-02-10'];
            try {
                $code = $tester->execute($options);
                $check($mode !== 'failed' && $code === 0, 'Commands must acknowledge cancellation or complete normally.');
            } catch (RuntimeException $exception) {
                $check($mode === 'failed' && $exception->getMessage() === 'Simulated source failure', $exception->getMessage());
            }
            $status = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
            $check($status['run_id'] === $runId && $status['user_id'] === 17, 'CLI status updates must preserve owner/run identity.');
            $check(!is_file($lock), 'Every terminal CLI path must release its report lock.');
            if ($mode === 'normal') {
                $check($status['success'] === true && is_file(storage_path('app/'.$output)), 'Existing CSV generation must still complete.');
                $hash = hash_file('sha256', storage_path('app/'.$output));
                try {
                    $cancel($request, $type);
                    throw new RuntimeException('Completed reports must reject cancellation.');
                } catch (HttpException $exception) {
                    $check($exception->getStatusCode() === 409, 'Completed reports must return a conflict, not be deleted.');
                }
                $check(hash_file('sha256', storage_path('app/'.$output)) === $hash, 'Late cancellation must preserve completed files.');
            } elseif ($mode === 'failed') {
                $check($status['success'] === false && empty($status['cancelled']), 'An ordinary source error must remain a visible failure.');
            } else {
                $check($status['cancelled'] === true && $status['success'] === false && $status['inProgress'] === false, 'Cancellation must be terminal, not a successful partial report.');
                $check(!is_file(storage_path('app/'.$output)), 'Cancellation must discard even a workbook already written during packaging.');
                $expectedCalls = match ($mode) {
                    'queued' => 0,
                    'running' => 1,
                    'packaging' => $type === 'daily-balance' ? 3 : 2,
                };
                $check(count($source->calls) === $expectedCalls, 'Cancellation must prevent additional source queries.');
                $statusRequest = $requestFor($runId, $owner);
                $visible = match ($type) {
                    'daily-balance' => $reports->reportStatus($statusRequest),
                    'customer-balances' => $reports->customerBalancesReportStatus($statusRequest),
                    'daily-balance-legacy' => $reports->legacyDailyBalanceReportStatus($statusRequest),
                };
                $check($visible->getData(true)['download_url'] === null, 'Cancelled CLI reports must not expose downloads.');
            }
            echo "PASS: {$type} {$mode}, owner isolation, cleanup and completion ordering\n";
        }
    }
    $cashController = new VieFundCashAgchReportController();
    $availability = $app->make(VieFundAvailabilityWindow::class);
    $criteria = ['date_to' => '2026-10-24', 'date_basis' => 'settlement_date', 'currency_code' => '00', 'status_ids' => [6]];
    $realReport = new VieFundCashAgchReport($source, $app->make(RuntimeSettings::class));
    foreach (['queued', 'queued-expired', 'counting', 'running', 'completed-race'] as $mode) {
        $start = Request::create('/reports/cashagch/run', 'POST', $criteria);
        $start->headers->set('Accept', 'application/json');
        $start->setUserResolver(static fn() => $owner);
        $runId = $cashController->run($start, $availability)->getData(true)['run_id'];
        $job = Bus::dispatched(GenerateVieFundCashAgchReport::class)->last();
        $cancelRequest = $requestFor($runId, $owner);
        $source->calls = [];
        $source->onQuery = null;
        $report = $realReport;
        try {
            $cancel($requestFor($runId, $other), 'cashagch');
            throw new RuntimeException('Another owner must not cancel a CASHagch run.');
        } catch (HttpException $exception) {
            $check($exception->getStatusCode() === 404, 'CASHagch cancellation must be owner-scoped.');
        }
        if (in_array($mode, ['queued', 'queued-expired'], true)) {
            $check($cancel($cancelRequest, 'cashagch')->getData(true)['cancelled'] === true, 'Queued CASHagch cancellation must be immediate.');
            if ($mode === 'queued-expired') Cache::forget(VieFundCashAgchReport::statusKey(17, $runId));
        } elseif ($mode === 'completed-race') {
            $report = new class($cancel, $cancelRequest) extends VieFundCashAgchReport
            {
                public function __construct(private object $cancel, private Request $request) {}
                public function generate(array $criteria, string $path, callable $progress): array
                {
                    $progress(0, 1, 'exporting');
                    File::put($path, 'finished but not yet published');
                    ($this->cancel)($this->request, 'cashagch');
                    return ['processed_transactions' => 1, 'review_transactions' => 0];
                }
            };
        } else {
            $source->onQuery = static function (string $query) use ($mode, $cancel, $cancelRequest): void {
                if (($mode === 'counting' && $query === 'count') || ($mode === 'running' && $query === 'cashagch')) {
                    $cancel($cancelRequest, 'cashagch');
                }
            };
        }
        $job->handle($report, $availability);
        $status = $cashController->status($cancelRequest, $runId)->getData(true);
        $check($status['state'] === 'cancelled' && $status['success'] === false && $status['eta_seconds'] === null, 'CASHagch cancellation must clear ETA and stay terminal.');
        $check($status['download_url'] === null && !is_file(VieFundCashAgchReport::outputPath(17, $runId)), 'CASHagch cancellation must never publish partial or racing output.');
        $check(count($source->calls) === match ($mode) { 'queued', 'queued-expired', 'completed-race' => 0, 'counting' => 1, 'running' => 2 }, 'Cancellation must stop CASHagch before any additional source batch.');
        $check($cancel($cancelRequest, 'cashagch')->getData(true)['cancelled'] === true, 'Repeated cancellation must be idempotent.');
        echo "PASS: CASHagch {$mode}, terminal state, no further batches and partial-output rejection\n";
    }
    $finishedRunId = (string) Str::uuid();
    $finishedKey = VieFundCashAgchReport::statusKey(17, $finishedRunId);
    $finishedPath = VieFundCashAgchReport::outputPath(17, $finishedRunId);
    File::put($finishedPath, 'previously completed workbook');
    Cache::put($finishedKey, [
        'run_id' => $finishedRunId, 'user_id' => 17, 'cancellation_supported' => true,
        'state' => 'completed', 'success' => true,
    ]);
    try {
        $cancel($requestFor($finishedRunId, $owner), 'cashagch');
        throw new RuntimeException('Already completed CASHagch reports must not be cancelled.');
    } catch (HttpException $exception) {
        $check($exception->getStatusCode() === 409 && File::get($finishedPath) === 'previously completed workbook', 'Completion winning the race must preserve its output.');
    }
    Cache::put($finishedKey, [
        'run_id' => $finishedRunId, 'user_id' => 17, 'state' => 'running', 'success' => null,
    ]);
    $legacyStatus = $cashController->status($requestFor($finishedRunId, $owner), $finishedRunId)->getData(true);
    $check($legacyStatus['cancel_url'] === null, 'Old worker runs must not advertise unsupported cancellation.');
    try {
        $cancel($requestFor($finishedRunId, $owner), 'cashagch');
        throw new RuntimeException('Unsupported old runs must not be falsely marked cancelled.');
    } catch (HttpException $exception) {
        $check($exception->getStatusCode() === 404, 'Unsupported cancellation must fail explicitly.');
    }
    $route = $app['router']->getRoutes()->getByName('reports.cancel');
    $check(in_array('auth.check', $route->gatherMiddleware(), true) && $route->methods() === ['POST'], 'Cancellation must be authenticated and POST-only.');
    $reportInput = [
        'date_from' => '2026-09-01', 'date_to' => '2026-09-30', 'date_basis' => 'settlement_date',
        'output_order' => 'asc', 'status' => [6], 'daily_balance_currency_code' => 'CAD',
        'legacy_date_from' => '2026-09-01', 'legacy_date_to' => '2026-09-30',
        'legacy_date_basis' => 'settlement_date', 'legacy_output_order' => 'asc',
        'legacy_status' => [6], 'legacy_trust_status' => ['Settled'],
        'customer_balance_date' => '2026-09-30', 'customer_balance_date_basis' => 'settlement_date',
        'customer_balance_status' => [6], 'customer_balance_currency_code' => 'CAD',
    ];
    foreach ([
        ['exportDailyBalance', 'format', 'date_from'],
        ['exportLegacyDailyBalance', 'legacy_format', 'legacy_date_from'],
        ['runDailyBalanceReport', 'format', 'date_from'],
        ['runLegacyDailyBalanceReport', 'legacy_format', 'legacy_date_from'],
        ['runCustomerBalancesReport', 'format', 'customer_balance_date'],
    ] as [$method, $formatField, $dateField]) {
        foreach (['csv', 'excel'] as $format) {
            $input = [...$reportInput, $formatField => $format];
            if ($format === 'excel') $input[$dateField] = 'invalid-date';
            $request = Request::create('/reports/test', 'POST', $input);
            $request->headers->set('Accept', 'application/json');
            $request->setUserResolver(static fn() => $owner);
            try {
                $reports->{$method}($request);
                throw new RuntimeException('Format validation fixtures must not start a real report process.');
            } catch (\Illuminate\Validation\ValidationException $exception) {
                $errors = $exception->errors();
                $check($format === 'csv' ? isset($errors[$formatField]) : !isset($errors[$formatField]),
                    "$method must reject CSV and allow the Excel format.");
                if ($format === 'excel') $check(isset($errors[$dateField]), 'Excel format checks must stop at date validation without launching a report.');
            }
        }
    }
    $index = File::get(base_path('resources/views/reports/index.blade.php'));
    $check(!str_contains($index, 'data-format=') && !str_contains($index, 'value="csv"')
        && !str_contains($index, 'Run CSV') && !str_contains($index, 'run-panel'), 'Report cards must not retain CSV menus or obsolete menu handlers.');
    $check(substr_count($index, 'value="excel"') === 3, 'Every existing report form must default to Excel.');
    $check(str_contains($index, 'reports.viefund-cash-snapshots.export-unreviewed')
        && str_contains($index, 'Download CSV'), 'Snapshot Monitor audit CSV must remain available as requested.');
    foreach (['cashagch', 'cancel-button', 'download-helper', 'index'] as $view) {
        token_get_all($app['blade.compiler']->compileString(File::get(base_path("resources/views/reports/{$view}.blade.php"))), TOKEN_PARSE);
    }
    echo "PASS: protected cancellation route, Excel-only report web formats, preserved snapshot audit CSV and Blade compilation of all report cards\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n".$exception->getTraceAsString()."\n");
    $exitCode = 1;
} finally {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
    File::deleteDirectory($directory);
}
exit($exitCode);
