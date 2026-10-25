<?php

use App\Jobs\GenerateVieFundCashAgchReport;
use App\Services\RuntimeSettings;
use App\Services\VieFund\VieFundAvailabilityWindow;
use App\Services\VieFund\VieFundCashAgchReport;
use App\Services\VieFund\VieFundCashAgchReportRun;
use App\Services\VieFund\VieFundRemoteService;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use OpenSpout\Reader\XLSX\Reader;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$directory = sys_get_temp_dir().'/agora-cashagch-resume-'.bin2hex(random_bytes(8));
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
    File::put($directory.'/queue.sqlite', '');
    config([
        'database.default' => 'sqlite',
        'database.connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => $directory.'/queue.sqlite', 'prefix' => '']],
        'queue.connections.database_reports.connection' => 'sqlite',
        'queue.failed.driver' => 'null',
        'cache.default' => 'array', 'session.driver' => 'array',
        'viefund.all_transactions_export_batch_size' => 20000,
        'viefund.all_transactions_export_rows_per_sheet' => 5000,
        'viefund.balance_report_cash_account_scope.opened_before' => '2018-01-01',
    ]);
    $setClock = static function (CarbonImmutable $time): void {
        Carbon::setTestNow($time);
        CarbonImmutable::setTestNow($time);
    };
    $started = CarbonImmutable::parse('2026-10-25 03:00:00 UTC');
    $setClock($started);
    Schema::create('application_settings', function (Blueprint $table): void {
        $table->string('setting_key')->primary();
        $table->text('value');
    });
    Schema::create('jobs', function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    $source = new class extends VieFundRemoteService
    {
        public int $total = 100001;
        public int $first;
        public int $last;
        public array $fetches = [];
        public array $counts = [];
        public bool $failOnce = true;
        public bool $alwaysFail = false;
        public const PATTERN = [
            ['Purchase', '-100.1234'], ['Purchase', '0.1234'],
            ['Redemption', '50.2500'], ['Fee Redemption', '2.0001'],
            ['Redemption', '-1.0001'], ['Unknown', '-0.9999'],
        ];
        public function __construct()
        {
            $this->first = CarbonImmutable::parse('2005-01-01 00:00:00 UTC')->timestamp;
            $this->last = CarbonImmutable::parse('2026-10-24 23:59:59 UTC')->timestamp;
        }
        private function bounds(array $filters): array
        {
            if (config('viefund.balance_report_cash_account_scope.opened_before') !== null
                || $filters['fund_id'] !== 'CASHagch' || empty($filters['fund_id_exact'])) {
                throw new RuntimeException('Incorrect source scope.');
            }
            $before = function (int $timestamp): int {
                return max(0, min($this->total, (int) ceil(($timestamp - $this->first) * ($this->total - 1) / ($this->last - $this->first))));
            };
            return [
                !empty($filters['date_from']) ? $before(CarbonImmutable::parse($filters['date_from'], 'UTC')->timestamp) : 0,
                $before(CarbonImmutable::parse($filters['date_to'], 'UTC')->addDay()->startOfDay()->timestamp),
            ];
        }
        public function countAllTransactions(?string $search = null, array $filters = []): int
        {
            $this->counts[] = $filters;
            if (empty($filters['date_from']) || substr($filters['date_from'], 0, 7) !== substr($filters['date_to'], 0, 7)) {
                throw new RuntimeException('A count attempted to scan more than one calendar month.');
            }
            [$from, $to] = $this->bounds($filters);
            return max(0, $to - $from);
        }
        public function fetchAllTransactionExportRowsAfter(?string $search, array $filters, ?array $cursor, int $limit): Collection
        {
            $this->fetches[] = [$cursor, $limit];
            if ($this->alwaysFail || ($this->failOnce && ($cursor['sort_id'] ?? null) === 1000)) {
                $this->failOnce = false;
                throw new RuntimeException('Intentional source interruption');
            }
            [$from, $to] = $this->bounds($filters);
            $first = max($from + 1, ($cursor['sort_id'] ?? 0) + 1);
            $rows = [];
            for ($id = $first; $id <= min($to, $first + $limit - 1); ++$id) {
                $date = gmdate('Y-m-d H:i:s', $this->first + (int) floor(($id - 1) * ($this->last - $this->first) / ($this->total - 1)));
                [$type, $amount] = self::PATTERN[($id - 1) % count(self::PATTERN)];
                $rows[] = (object) [
                    'transaction_id' => 'C-'.$id, 'sort_id' => $id,
                    'fund_transaction_id' => 1000000 + $id, 'source_id' => '0000'.$id,
                    'fund_id' => 'CASHagch', 'customer_name' => '=Literal text', 'plan_account_id' => '00000123',
                    'transaction_type' => $type, 'status' => 'Confirmed',
                    'created_date' => $date, 'trade_date' => $date, 'processing_date' => $date,
                    'settlement_date' => $date, 'basis_date' => $date, 'currency_code' => '00', 'amount' => $amount,
                ];
            }
            return collect($rows);
        }
    };
    $app->instance(VieFundRemoteService::class, $source);
    $report = new class($source, $app->make(RuntimeSettings::class)) extends VieFundCashAgchReport
    {
        public bool $interruptPackaging = false;
        public bool $timeoutPackaging = false;
        public function writeWorkbook(array $criteria, string $path, iterable $batches, int $expectedRows, callable $progress, ?string $tempFolder = null): array
        {
            return parent::writeWorkbook($criteria, $path, $batches, $expectedRows, function (int $count, int $total, string $phase) use ($progress): void {
                $progress($count, $total, $phase);
                if ($this->timeoutPackaging && $count >= 1000) sleep(5);
                if ($this->interruptPackaging && $count >= 1000) {
                    $this->interruptPackaging = false;
                    throw new RuntimeException('Intentional packaging interruption');
                }
            }, $tempFolder);
        }
    };
    $run = new class($source, $app->make(RuntimeSettings::class), $report) extends VieFundCashAgchReportRun
    {
        public ?Closure $afterCheckpoint = null;
        public function advance(string $directory, string $buildingPath, array $criteria, array $state, callable $progress): array
        {
            $state = parent::advance($directory, $buildingPath, $criteria, $state, $progress);
            if ($this->afterCheckpoint !== null) ($this->afterCheckpoint)($state);
            return $state;
        }
    };
    $app->instance(VieFundCashAgchReportRun::class, $run);
    $availability = $app->make(VieFundAvailabilityWindow::class);
    $queue = $app['queue']->connection('database_reports');
    $worker = $app['queue.worker'];
    $options = new WorkerOptions(maxTries: 3, timeout: 600, memory: 512, sleep: 0, maxJobs: 1);
    $criteria = ['date_from' => null, 'date_to' => '2026-10-24', 'date_basis' => 'settlement_date', 'currency_code' => '00', 'status_ids' => [6]];
    $enqueue = static function () use ($criteria): string {
        $id = (string) Str::uuid();
        GenerateVieFundCashAgchReport::dispatch(17, $id, $criteria)->onConnection('database_reports')->onQueue('cashagch-reports');
        return $id;
    };
    $stateFor = static fn(string $id): array => $run->load(VieFundCashAgchReportRun::directory(17, $id), $criteria);
    $work = static function () use ($queue, $worker, $options, $check, $setClock): void {
        $job = $queue->pop('cashagch-reports');
        $check($job !== null && $job->attempts() <= 3, 'Each continuation must have its own bounded retry budget.');
        try {
            $worker->process('database_reports', $job, $options);
        } catch (RuntimeException $exception) {
            $check(in_array($exception->getMessage(), ['Intentional source interruption', 'Intentional publication interruption',
                'Intentional packaging interruption', 'A staged CASHagch batch failed its integrity check.'], true), $exception->getMessage());
        }
        $setClock(CarbonImmutable::now()->addSeconds(61));
    };
    $id = $enqueue();
    $stages = 0;
    $killed = false;
    $packagingSeconds = 0.0;
    while (($stateFor($id)['phase'] ?? null) !== 'completed' && $stages++ < 2000) {
        $state = $stateFor($id);
        if (!$killed && $state['phase'] === 'exporting' && $state['processed_transactions'] === 2000) {
            $check(function_exists('pcntl_fork') && function_exists('pcntl_async_signals'), 'Actual timeout recovery requires PHP pcntl on this development platform.');
            $row = DB::table('jobs')->where('queue', 'cashagch-reports')->sole();
            $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
            $payload['timeout'] = 1;
            DB::table('jobs')->where('id', $row->id)->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
            DB::disconnect('sqlite');
            $run->afterCheckpoint = static function (array $saved): void {
                if ($saved['processed_transactions'] === 3000) sleep(5);
            };
            $app['events']->listen(\Illuminate\Queue\Events\JobTimedOut::class,
                static fn() => File::put($directory.'/worker-timed-out', 'yes'));
            $pid = pcntl_fork();
            $check($pid !== -1, 'Unable to start the isolated timeout test worker.');
            if ($pid === 0) {
                $worker->daemon('database_reports', 'cashagch-reports', $options);
                exit(0);
            }
            pcntl_waitpid($pid, $waitStatus);
            $run->afterCheckpoint = null;
            $check(is_file($directory.'/worker-timed-out')
                && ((pcntl_wifsignaled($waitStatus) && pcntl_wtermsig($waitStatus) === SIGKILL)
                    || (pcntl_wifexited($waitStatus) && pcntl_wexitstatus($waitStatus) === 1)), 'Laravel must actually terminate the child worker on its timeout.');
            $check($stateFor($id)['processed_transactions'] === 3000, 'A hard worker timeout must retain the last committed batch.');
            $reserved = DB::table('jobs')->where('queue', 'cashagch-reports')->sole();
            $check($reserved->reserved_at !== null && $reserved->attempts === 1, 'Timed-out work must remain reserved for queue recovery.');
            $payload['timeout'] = 600;
            DB::table('jobs')->where('id', $reserved->id)->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
            $check($queue->pop('cashagch-reports') === null, 'The reservation must not expire before the job timeout.');
            $setClock(CarbonImmutable::now()->addSeconds(661));
            $killed = true;
            continue;
        }
        $before = hrtime(true);
        $work();
        if ($state['phase'] === 'finalizing') $packagingSeconds = (hrtime(true) - $before) / 1_000_000_000;
    }
    $status = Cache::get(VieFundCashAgchReport::statusKey(17, $id));
    $check($stages > 255 && $stages < 2000 && $killed && CarbonImmutable::now()->diffInSeconds($started, true) > 3600,
        'The actual queue must complete more than 255 independent stages over more than an hour of report lifetime.');
    $check($status['success'] === true && $status['processed_transactions'] === 100001
        && $status['expected_transactions'] === 100001 && $status['eta_seconds'] === null, 'Final completion must use the exact exported count and clear ETA.');
    $check($stateFor($id)['counted_transactions'] === 100001
        && count(array_filter($source->counts, static fn(array $filters): bool => $filters['availability_date_to'] !== '2026-10-24')) === 0,
        'Monthly counts must reconcile to the export while retaining the full-period cash availability cutoff.');
    $check($queue->size('cashagch-reports') === 0, 'A completed chain must leave no continuations behind.');
    $check($packagingSeconds < 120 && config('queue.connections.database_reports.retry_after') > (new GenerateVieFundCashAgchReport(17, $id, $criteria))->timeout,
        'A 100,001-row local assembly must complete under two minutes, with reservation visibility longer than the job timeout.');
    $check(max(array_column($source->fetches, 1)) === 1000 && count($source->counts) > 240, 'Large runtime batch settings must still produce bounded 1,000-row reads and monthly counts across all history.');
    $check(count(array_filter($source->fetches, static fn(array $request): bool => ($request[0]['sort_id'] ?? null) === 1000)) === 2,
        'An interrupted read must retry exactly the uncommitted cursor.');
    $check(glob(VieFundCashAgchReportRun::directory(17, $id).'/batch-*') === [], 'Successful publication must discard staged transaction data.');
    $reader = new Reader();
    $reader->open(VieFundCashAgchReport::outputPath(17, $id));
    $details = 0;
    $names = [];
    $summary = [];
    $monthly = [];
    try {
        foreach ($reader->getSheetIterator() as $sheet) {
            $names[] = $sheet->getName();
            foreach ($sheet->getRowIterator() as $number => $row) {
                $values = $row->toArray();
                if (str_starts_with($sheet->getName(), 'Transactions ') && $number > 1) {
                    ++$details;
                    $check($values[0] === 'C-'.$details && $values[4] === '=Literal text' && $values[5] === '00000123',
                        'Every staged transaction must appear exactly once, in source order, preserving text identifiers.');
                } elseif ($sheet->getName() === 'Summary') {
                    $summary[$values[0]] = $values[1] ?? null;
                } elseif ($sheet->getName() === 'Monthly Summary'
                    && (preg_match('/^\d{4}-\d{2}$/D', (string) $values[0]) || $values[0] === 'Grand Total')) {
                    $monthly[$values[0]] = $values;
                }
            }
        }
    } finally {
        $reader->close();
    }
    $check($details === 100001 && count($names) === 23 && array_slice($names, -2) === ['Summary', 'Monthly Summary'],
        'The complete workbook must contain 21 consecutive detail sheets followed by both summaries.');
    $expected = ['Total Purchases' => BigDecimal::zero(), 'Standard Redemptions' => BigDecimal::zero(),
        'Fee Redemptions' => BigDecimal::zero(), 'Other / Review Required Signed Amount' => BigDecimal::zero(),
        'Net Signed Transaction Amount' => BigDecimal::zero()];
    foreach ($source::PATTERN as $index => [$type, $amount]) {
        $frequency = intdiv($source->total, count($source::PATTERN)) + ($index < $source->total % count($source::PATTERN) ? 1 : 0);
        $signed = BigDecimal::of($amount)->multipliedBy($frequency);
        $label = match ($type) {
            'Purchase' => 'Total Purchases', 'Redemption' => 'Standard Redemptions',
            'Fee Redemption' => 'Fee Redemptions', default => 'Other / Review Required Signed Amount',
        };
        $expected[$label] = $expected[$label]->plus($type === 'Purchase' ? $signed->negated() : $signed);
        $expected['Net Signed Transaction Amount'] = $expected['Net Signed Transaction Amount']->plus($signed);
    }
    foreach ($expected as $label => $amount) {
        $check($amount->isEqualTo((string) $summary[$label]), 'Resumed financial totals do not reconcile: '.$label);
    }
    $grand = $monthly['Grand Total'];
    unset($monthly['Grand Total']);
    $check(count($monthly) === 262 && array_key_first($monthly) === '2005-01' && array_key_last($monthly) === '2026-10', 'Monthly Summary must retain every inception month.');
    for ($column = 1; $column <= 12; ++$column) {
        $sum = BigDecimal::zero();
        foreach ($monthly as $month) $sum = $sum->plus((string) $month[$column]);
        $check($sum->isEqualTo((string) $grand[$column]), 'Monthly Grand Total does not reconcile at column '.$column);
    }
    $check($grand[7] === 100001 && memory_get_peak_usage(true) < 256 * 1024 * 1024, 'Large reports must preserve exact counts and bounded memory.');
    echo 'PASS: real database queue, actual Laravel worker timeout/resume, transient read retry, >255 stages and >1-hour lifetime; 100,001 rows, 262 months, exact totals, no duplicates; packaging '.round($packagingSeconds, 2).'s; peak '.round(memory_get_peak_usage(true) / 1024 / 1024)." MiB\n";

    $source->total = 2501;
    $source->first = CarbonImmutable::parse('2026-10-01 00:00:00 UTC')->timestamp;
    $source->last = CarbonImmutable::parse('2026-10-02 00:00:00 UTC')->timestamp;
    $id = $enqueue();
    $workspace = VieFundCashAgchReportRun::directory(17, $id);
    $lock = fopen($workspace.'.lock', 'c');
    flock($lock, LOCK_EX);
    $calls = count($source->fetches);
    (new GenerateVieFundCashAgchReport(17, $id, $criteria))->handle($run, $availability);
    $check(count($source->fetches) === $calls && $queue->size('cashagch-reports') === 1, 'A concurrent duplicate must neither read source rows nor queue another chain.');
    flock($lock, LOCK_UN);
    fclose($lock);
    $interruptedPublication = false;
    $run->afterCheckpoint = static function (array $state) use (&$interruptedPublication, &$id): void {
        if ($state['phase'] === 'ready' && !$interruptedPublication) {
            $interruptedPublication = true;
            rename(VieFundCashAgchReportRun::buildingPath(17, $id), VieFundCashAgchReport::outputPath(17, $id));
            throw new RuntimeException('Intentional publication interruption');
        }
    };
    for ($stage = 0; $stage < 20 && $stateFor($id)['phase'] !== 'completed'; ++$stage) $work();
    $check($interruptedPublication && Cache::get(VieFundCashAgchReport::statusKey(17, $id))['success'] === true,
        'A crash after atomic rename must recover publication, not rebuild or lose the workbook.');
    $calls = count($source->fetches);
    $completedAt = Cache::get(VieFundCashAgchReport::statusKey(17, $id))['completed_at'];
    (new GenerateVieFundCashAgchReport(17, $id, $criteria))->handle($run, $availability);
    $check(count($source->fetches) === $calls && $queue->size('cashagch-reports') === 0
        && Cache::get(VieFundCashAgchReport::statusKey(17, $id))['completed_at'] === $completedAt,
        'Delayed duplicate jobs must not restart an export or change its completed timestamp.');
    $run->afterCheckpoint = null;
    echo "PASS: exclusive execution lock, interrupted atomic publication recovery and completed-run idempotence\n";

    $id = $enqueue();
    $report->interruptPackaging = true;
    for ($stage = 0; $stage < 20 && $stateFor($id)['phase'] !== 'finalizing'; ++$stage) $work();
    $calls = count($source->fetches);
    $work();
    $check($stateFor($id)['phase'] === 'finalizing' && !is_file(VieFundCashAgchReportRun::buildingPath(17, $id)),
        'Interrupted local assembly must retain its source checkpoint and remove the incomplete workbook.');
    $work();
    $check($stateFor($id)['phase'] === 'completed' && count($source->fetches) === $calls
        && !is_dir(VieFundCashAgchReportRun::directory(17, $id).'/xlsx-tmp'),
        'Packaging retry must complete entirely from saved batches and clean writer temporary files.');
    echo "PASS: interrupted workbook assembly retries independently without rereading VieFund\n";

    foreach (['source', 'integrity'] as $failure) {
        $id = $enqueue();
        $workspace = VieFundCashAgchReportRun::directory(17, $id);
        for ($stage = 0; $stage < 20 && $stateFor($id)['phase'] !== 'finalizing'; ++$stage) {
            $work();
            if ($failure === 'source' && $stateFor($id)['processed_transactions'] === 2000) break;
        }
        if ($failure === 'source') {
            $source->alwaysFail = true;
        } else {
            $state = $stateFor($id);
            File::append($workspace.'/'.$state['parts'][0]['name'], "corrupt\n");
        }
        $beforeCalls = count($source->fetches);
        for ($attempt = 0; $attempt < 3; ++$attempt) $work();
        $status = Cache::get(VieFundCashAgchReport::statusKey(17, $id));
        $check($status['state'] === 'failed' && $status['success'] === false && $status['eta_seconds'] === null,
            'Repeated unrecoverable stage errors must become an explicit terminal failure.');
        $check(!is_file(VieFundCashAgchReport::outputPath(17, $id)) && !is_file(VieFundCashAgchReportRun::buildingPath(17, $id))
            && glob($workspace.'/batch-*') === [] && !is_dir($workspace.'/xlsx-tmp'), 'Terminal failure must remove partial workbooks, parts and writer temporary files.');
        if ($failure === 'integrity') $check(count($source->fetches) === $beforeCalls, 'Packaging retries must not reread VieFund.');
        $source->alwaysFail = false;
        echo "PASS: {$failure} failures retry only their stage, then fail visibly with complete partial-artifact cleanup\n";
    }

    $id = $enqueue();
    for ($stage = 0; $stage < 20 && $stateFor($id)['phase'] !== 'finalizing'; ++$stage) $work();
    $report->timeoutPackaging = true;
    $app['events']->listen(\Illuminate\Queue\Events\JobFailed::class, static function () use ($directory, &$id): void {
        File::put($directory.'/terminal-timeout-status.json', json_encode(
            Cache::get(VieFundCashAgchReport::statusKey(17, $id)), JSON_THROW_ON_ERROR
        ));
    });
    File::delete($directory.'/worker-timed-out');
    $row = DB::table('jobs')->where('queue', 'cashagch-reports')->sole();
    $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
    $payload['timeout'] = 1;
    $payload['maxTries'] = 1;
    DB::table('jobs')->where('id', $row->id)->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
    DB::disconnect('sqlite');
    $pid = pcntl_fork();
    $check($pid !== -1, 'Unable to start the terminal-timeout test worker.');
    if ($pid === 0) {
        $worker->daemon('database_reports', 'cashagch-reports', $options);
        exit(0);
    }
    $deadline = microtime(true) + 10;
    do {
        $finished = pcntl_waitpid($pid, $waitStatus, WNOHANG);
        if ($finished !== 0) break;
        usleep(20000);
    } while (microtime(true) < $deadline);
    if ($finished === 0) {
        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $waitStatus);
        $check(false, 'The terminal-timeout failure handler deadlocked on its own execution lock.');
    }
    $report->timeoutPackaging = false;
    $terminal = json_decode(File::get($directory.'/terminal-timeout-status.json'), true, 512, JSON_THROW_ON_ERROR);
    $workspace = VieFundCashAgchReportRun::directory(17, $id);
    $check(is_file($directory.'/worker-timed-out') && $stateFor($id)['phase'] === 'failed'
        && $terminal['state'] === 'failed' && $terminal['success'] === false && $terminal['eta_seconds'] === null,
        'A terminal hard timeout must publish failure before its lock-owning process is killed.');
    $check($queue->size('cashagch-reports') === 0 && glob($workspace.'/batch-*') === []
        && !is_dir($workspace.'/xlsx-tmp') && !is_file(VieFundCashAgchReportRun::buildingPath(17, $id))
        && !is_file(VieFundCashAgchReport::outputPath(17, $id)),
        'A hard packaging timeout must discard the queue job, staged data and open writer files.');
    echo "PASS: actual terminal packaging timeout, fresh failure-handler instance, no lock deadlock and hard-timeout artifact cleanup\n";
} catch (Throwable $exception) {
    $exitCode = 1;
    fwrite(STDERR, 'FAIL: '.$exception->getMessage()."\n".$exception->getTraceAsString()."\n");
} finally {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
    File::deleteDirectory($directory);
}
exit($exitCode);
