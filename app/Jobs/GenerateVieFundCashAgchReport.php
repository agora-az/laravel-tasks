<?php

namespace App\Jobs;

use App\Exceptions\ReportCancelled;
use App\Services\VieFund\VieFundAvailabilityWindow;
use App\Services\VieFund\VieFundCashAgchReport;
use App\Services\VieFund\VieFundCashAgchReportRun;
use App\Services\VieFund\VieFundReportCancellation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class GenerateVieFundCashAgchReport implements ShouldQueue
{
    use Queueable;

    /** @var array<string, resource> */
    private static array $executionLocks = [];

    public int $tries = 3;

    public int $maxExceptions = 3;

    public int $timeout = 600;

    public array $backoff = [15, 60, 180];

    public function __construct(
        public readonly int $userId,
        public readonly string $runId,
        public readonly array $criteria
    ) {}

    public function handle(VieFundCashAgchReportRun $run, VieFundAvailabilityWindow $availability): void
    {
        $directory = VieFundCashAgchReportRun::directory($this->userId, $this->runId);
        File::ensureDirectoryExists(dirname($directory));
        $lock = fopen($directory.'.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Unable to open the CASHagch execution lock.');
        }
        $acquired = false;
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB, $wouldBlock)) {
                if (!$wouldBlock) {
                    throw new RuntimeException('Unable to acquire the CASHagch execution lock.');
                }
                // The active owner will queue the continuation; acknowledge duplicates.
                return;
            }
            $acquired = true;
            self::$executionLocks[$directory] = $lock;
            try {
                VieFundReportCancellation::check($this->cancellationFile());
                $state = $run->load($directory, $this->criteria);
                if (in_array($state['phase'], ['cancelled', 'failed'], true)) {
                    return;
                }
                $state = $run->prepare($directory, $this->criteria, $state);
                $this->updateStatus($state['source'] ?? []);
                if ($run->requiresRemote($state) && $availability->isBlocked()) {
                    $this->deferForMaintenance($availability);
                    return;
                }
                if (!in_array($state['phase'], ['ready', 'completed'], true)) {
                    $state = $run->advance(
                        $directory,
                        VieFundCashAgchReportRun::buildingPath($this->userId, $this->runId),
                        $this->criteria,
                        $state,
                        fn(array $status) => $this->updateStatus($status)
                    );
                }
                if (in_array($state['phase'], ['ready', 'completed'], true)) {
                    $this->complete($run, $directory, $state);
                } else {
                    $this->continueReport();
                }
            } catch (Throwable $exception) {
                if ($exception instanceof ReportCancelled || VieFundReportCancellation::requested($this->cancellationFile())) {
                    if (!$exception instanceof ReportCancelled) {
                        Log::warning('CASHagch operation failed while cancellation was pending.', ['run_id' => $this->runId, 'error' => $exception->getMessage()]);
                    }
                    $this->finishCancellation($run, $directory);
                    return;
                }
                if (($exception instanceof QueryException || $exception instanceof \PDOException) && $availability->isBlocked()
                    && isset($state) && $run->requiresRemote($state)) {
                    Log::warning('CASHagch source operation interrupted by maintenance; retaining saved progress.', ['run_id' => $this->runId, 'error' => $exception->getMessage()]);
                    $this->deferForMaintenance($availability);
                    return;
                }
                Log::warning('CASHagch stage interrupted; saved progress retained for retry.', ['run_id' => $this->runId, 'error' => $exception->getMessage()]);
                if ((Cache::get(VieFundCashAgchReport::statusKey($this->userId, $this->runId), [])['success'] ?? null) !== true) {
                    $this->updateStatus([
                        'eta_seconds' => null, 'estimated_completion_at' => null,
                        'message' => 'An interrupted report stage is being retried. Saved transactions are retained.',
                    ]);
                }
                throw $exception;
            }
        } finally {
            if ($acquired) {
                unset(self::$executionLocks[$directory]);
                flock($lock, LOCK_UN);
            }
            fclose($lock);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $directory = VieFundCashAgchReportRun::directory($this->userId, $this->runId);
        // Laravel invokes timeout failure on a fresh job instance before killing the lock owner.
        if (isset(self::$executionLocks[$directory])) {
            $this->failReport($exception);
            return;
        }
        File::ensureDirectoryExists(dirname($directory));
        $lock = fopen($directory.'.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Unable to open the CASHagch failure lock.');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Unable to acquire the CASHagch failure lock.');
            }
            $this->failReport($exception);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function failReport(?Throwable $exception): void
    {
        $status = Cache::get(VieFundCashAgchReport::statusKey($this->userId, $this->runId), []);
        if (($status['success'] ?? null) === true) {
            return;
        }
        $run = app(VieFundCashAgchReportRun::class);
        $directory = VieFundCashAgchReportRun::directory($this->userId, $this->runId);
        if ($run->load($directory, $this->criteria)['phase'] === 'completed') {
            return;
        }
        if (VieFundReportCancellation::requested($this->cancellationFile())) {
            $this->finishCancellation($run, $directory);
            return;
        }
        $message = $exception?->getMessage() ?? 'The report worker stopped before completion.';
        Log::error('CASHagch report failed.', ['run_id' => $this->runId, 'user_id' => $this->userId, 'error' => $message]);
        VieFundReportCancellation::locked($this->cancellationFile(), function () use ($run, $directory, $message): void {
            if (VieFundReportCancellation::requested($this->cancellationFile())) {
                $this->discard($run, $directory, 'cancelled');
                $this->writeStatus(VieFundReportCancellation::status());
                return;
            }
            $this->discard($run, $directory, 'failed');
            $this->writeStatus([
                'state' => 'failed', 'phase' => 'failed', 'success' => false,
                'eta_seconds' => null, 'estimated_completion_at' => null,
                'message' => 'Report failed after retrying the interrupted stage: '.$message,
            ]);
        });
    }

    private function complete(VieFundCashAgchReportRun $run, string $directory, array $state): void
    {
        VieFundReportCancellation::locked($this->cancellationFile(), function () use ($run, $directory, $state): void {
            VieFundReportCancellation::check($this->cancellationFile());
            $path = VieFundCashAgchReport::outputPath($this->userId, $this->runId);
            $building = VieFundCashAgchReportRun::buildingPath($this->userId, $this->runId);
            // A previous attempt may have renamed the file before its checkpoint committed.
            if ($state['phase'] === 'ready' && is_file($building) && !rename($building, $path)) {
                throw new RuntimeException('Unable to publish the completed CASHagch workbook.');
            }
            if (!is_file($path)) {
                throw new RuntimeException('The completed CASHagch workbook is missing.');
            }
            $state['phase'] = 'completed';
            $state['completed_at'] ??= now()->toIso8601String();
            $run->save($directory, $state);
            $this->writeStatus([
                ...$state['result'],
                'state' => 'completed', 'phase' => 'completed', 'success' => true,
                'expected_transactions' => $state['result']['processed_transactions'],
                'eta_seconds' => null, 'estimated_completion_at' => null,
                'message' => $state['result']['review_transactions'] > 0
                    ? 'Report ready. Some transaction types need review; see the Summary sheet.'
                    : 'CASHagch report ready to download.',
                'completed_at' => $state['completed_at'],
            ]);
        });
        $run->cleanup($directory, VieFundCashAgchReportRun::buildingPath($this->userId, $this->runId));
    }

    private function deferForMaintenance(VieFundAvailabilityWindow $availability): void
    {
        $this->updateStatus([
            'state' => 'queued', 'phase' => 'queued',
            'eta_seconds' => null, 'estimated_completion_at' => null,
            'message' => 'Paused for VieFund maintenance. Saved progress will resume automatically when it ends.',
        ]);
        $this->continueReport($availability->nextAvailableAt());
    }

    private function continueReport(?\DateTimeInterface $delay = null): void
    {
        $next = self::dispatch($this->userId, $this->runId, $this->criteria)
            ->onConnection('database_reports')->onQueue('cashagch-reports');
        if ($delay !== null) {
            $next->delay($delay);
        }
    }

    private function updateStatus(array $values): void
    {
        VieFundReportCancellation::locked($this->cancellationFile(), function () use ($values): void {
            if (empty($values['cancelled'])) {
                VieFundReportCancellation::check($this->cancellationFile());
            }
            $this->writeStatus($values);
        });
    }

    private function writeStatus(array $values): void
    {
        $key = VieFundCashAgchReport::statusKey($this->userId, $this->runId);
        Cache::put($key, array_merge(Cache::get($key, []), $values, [
            'run_id' => $this->runId, 'user_id' => $this->userId, 'cancellation_supported' => true,
            'criteria' => $this->criteria,
            'updated_at' => now()->toIso8601String(),
        ]), now()->addHours(12));
    }

    private function cancellationFile(): string
    {
        return VieFundReportCancellation::path('cashagch', $this->userId, $this->runId);
    }

    private function finishCancellation(VieFundCashAgchReportRun $run, string $directory): void
    {
        $this->discard($run, $directory, 'cancelled');
        $this->updateStatus(VieFundReportCancellation::status());
    }

    private function discard(VieFundCashAgchReportRun $run, string $directory, string $phase): void
    {
        $path = VieFundCashAgchReport::outputPath($this->userId, $this->runId);
        if (is_file($path) && !File::delete($path)) {
            throw new RuntimeException('Unable to discard the incomplete CASHagch workbook.');
        }
        $run->cleanup($directory, VieFundCashAgchReportRun::buildingPath($this->userId, $this->runId));
        $run->save($directory, [
            'criteria_hash' => hash('sha256', json_encode($this->criteria, JSON_THROW_ON_ERROR)),
            'phase' => $phase,
        ]);
    }
}
