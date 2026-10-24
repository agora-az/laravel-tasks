<?php

namespace App\Jobs;

use App\Exceptions\ReportCancelled;
use App\Services\VieFund\VieFundCashAgchReport;
use App\Services\VieFund\VieFundAvailabilityWindow;
use App\Services\VieFund\VieFundReportCancellation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

class GenerateVieFundCashAgchReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(
        public readonly int $userId,
        public readonly string $runId,
        public readonly array $criteria
    ) {}

    public function handle(VieFundCashAgchReport $report, VieFundAvailabilityWindow $availability): void
    {
        try {
            VieFundReportCancellation::check($this->cancellationFile());
            $this->generateReport($report, $availability);
        } catch (Throwable $exception) {
            if (!$exception instanceof ReportCancelled && !VieFundReportCancellation::requested($this->cancellationFile())) {
                throw $exception;
            }
            if (!$exception instanceof ReportCancelled) {
                Log::warning('CASHagch operation failed while cancellation was pending.', ['run_id' => $this->runId, 'error' => $exception->getMessage()]);
            }
            $this->finishCancellation();
        }
    }

    private function generateReport(VieFundCashAgchReport $report, VieFundAvailabilityWindow $availability): void
    {
        if ($availability->isBlocked()) {
            self::dispatch($this->userId, $this->runId, $this->criteria)
                ->onConnection('database')
                ->delay($availability->nextAvailableAt());
            $this->updateStatus([
                'state' => 'queued', 'phase' => 'queued',
                'eta_seconds' => null, 'estimated_completion_at' => null,
                'message' => 'Queued until the VieFund maintenance window ends.',
            ]);
            return;
        }
        $this->updateStatus([
            'state' => 'running', 'phase' => 'counting',
            'processed_transactions' => 0, 'expected_transactions' => null,
            'rows_per_second' => null, 'eta_seconds' => null, 'estimated_completion_at' => null,
            'message' => 'Counting matching CASHagch transactions for the selected period...',
        ]);
        $exportStartedAt = null;
        $batches = 0;
        $result = $report->generate(
            $this->criteria,
            VieFundCashAgchReport::outputPath($this->userId, $this->runId),
            function (int $count, int $total, string $phase) use ($availability, &$exportStartedAt, &$batches): void {
                VieFundReportCancellation::check($this->cancellationFile());
                if ($phase === 'exporting' && $availability->isBlocked()) {
                    throw new RuntimeException('VieFund maintenance began before the report finished. Generate the report again after '.$availability->nextAvailableAt()->format('g:i a T').'.');
                }
                $exportStartedAt ??= $this->monotonicSeconds();
                if ($phase === 'exporting' && $count > 0) {
                    ++$batches;
                }
                $elapsed = max(0, $this->monotonicSeconds() - $exportStartedAt);
                $rate = $phase === 'exporting' && $batches >= 2 && $elapsed > 0 ? $count / $elapsed : null;
                $remaining = max(0, $total - $count);
                $eta = $rate !== null && $rate > 0 && $remaining > 0 ? (int) ceil($remaining / $rate) : null;
                $message = $count === 0
                    ? 'Preparing Excel export for approximately '.number_format($total).' transactions...'
                    : ($remaining > 0
                        ? number_format($count).' of approximately '.number_format($total).' transactions written. Reading the next batch...'
                        : number_format($count).' transactions written. Checking for remaining transactions...');
                $this->updateStatus([
                    'phase' => $phase,
                    'processed_transactions' => $count,
                    'expected_transactions' => $total,
                    'rows_per_second' => $rate !== null ? round($rate, 2) : null,
                    'eta_seconds' => $eta,
                    'estimated_completion_at' => $eta !== null ? now()->addSeconds($eta)->toIso8601String() : null,
                    'message' => $phase === 'finalizing'
                        ? number_format($count).' transactions written. Finalizing Excel workbook...'
                        : $message,
                ]);
            }
        );
        $this->updateStatus([
            ...$result,
            'state' => 'completed',
            'phase' => 'completed',
            'success' => true,
            'expected_transactions' => $result['processed_transactions'],
            'eta_seconds' => null,
            'estimated_completion_at' => null,
            'message' => $result['review_transactions'] > 0
                ? 'Report ready. Some transaction types need review; see the Summary sheet.'
                : 'CASHagch report ready to download.',
            'completed_at' => now()->toIso8601String(),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        if (VieFundReportCancellation::requested($this->cancellationFile())) {
            $this->finishCancellation();
            return;
        }
        $message = $exception?->getMessage() ?? 'The report worker stopped before completion.';
        Log::error('CASHagch report failed.', ['run_id' => $this->runId, 'user_id' => $this->userId, 'error' => $message]);
        $this->updateStatus([
            'state' => 'failed', 'phase' => 'failed', 'success' => false,
            'eta_seconds' => null, 'estimated_completion_at' => null,
            'message' => 'Report failed: '.$message,
        ]);
    }

    protected function monotonicSeconds(): float
    {
        return hrtime(true) / 1_000_000_000;
    }

    private function updateStatus(array $values): void
    {
        VieFundReportCancellation::locked($this->cancellationFile(), function () use ($values): void {
            if (empty($values['cancelled'])) {
                VieFundReportCancellation::check($this->cancellationFile());
            }
            $key = VieFundCashAgchReport::statusKey($this->userId, $this->runId);
            Cache::put($key, array_merge(Cache::get($key, []), $values, [
                'run_id' => $this->runId, 'user_id' => $this->userId, 'cancellation_supported' => true,
                'updated_at' => now()->toIso8601String(),
            ]), now()->addHours(12));
        });
    }

    private function cancellationFile(): string
    {
        return VieFundReportCancellation::path('cashagch', $this->userId, $this->runId);
    }

    private function finishCancellation(): void
    {
        $path = VieFundCashAgchReport::outputPath($this->userId, $this->runId);
        if (is_file($path) && !File::delete($path)) {
            throw new RuntimeException('Unable to discard the cancelled report workbook.');
        }
        $this->updateStatus(VieFundReportCancellation::status());
    }
}
