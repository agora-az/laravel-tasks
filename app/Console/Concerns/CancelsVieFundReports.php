<?php

namespace App\Console\Concerns;

use App\Exceptions\ReportCancelled;
use App\Services\VieFund\VieFundReportCancellation;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

trait CancelsVieFundReports
{
    private ?string $cancellationFile = null;
    private ?string $cancellationOutput = null;
    private array $cancellationIdentity = [];

    private function initializeCancellation(?string $statusFile): void
    {
        $this->cancellationFile = $this->option('cancel-file') ?: null;
        $this->cancellationOutput = null;
        $runId = $this->option('run-id') ?: null;
        $initial = $statusFile && is_file($statusFile)
            ? json_decode(File::get($statusFile), true, 512, JSON_THROW_ON_ERROR)
            : [];
        $this->cancellationIdentity = [
            'run_id' => $runId,
            'user_id' => ($initial['run_id'] ?? null) === $runId ? (int) ($initial['user_id'] ?? 0) : 0,
            'cancellation_supported' => $this->cancellationFile !== null,
        ];
    }

    private function checkForCancellation(): void
    {
        VieFundReportCancellation::check($this->cancellationFile);
    }

    private function isCancellation(Throwable $exception): bool
    {
        if ($exception instanceof ReportCancelled) {
            return true;
        }
        if (!VieFundReportCancellation::requested($this->cancellationFile)) {
            return false;
        }
        Log::warning('Report operation failed while cancellation was pending.', [
            'run_id' => $this->cancellationIdentity['run_id'],
            'error' => $exception->getMessage(),
        ]);

        return true;
    }

    private function finishCancellation(?string $statusFile): int
    {
        if ($this->cancellationOutput !== null && is_file($this->cancellationOutput)) {
            if (!File::delete($this->cancellationOutput)) {
                throw new \RuntimeException('Unable to discard the cancelled report output.');
            }
        }
        $this->writeStatus($statusFile, VieFundReportCancellation::status());
        $this->warn('Report cancelled.');

        return self::SUCCESS;
    }

    private function writeStatus(?string $statusFile, array $payload): void
    {
        VieFundReportCancellation::locked($this->cancellationFile, function () use ($statusFile, $payload): void {
            if (empty($payload['cancelled'])) {
                $this->checkForCancellation();
            }
            if ($statusFile === null) {
                return;
            }
            File::ensureDirectoryExists(dirname($statusFile));
            File::replace($statusFile, json_encode(
                array_merge($this->cancellationIdentity, $payload),
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            ), 0600);
        });
    }

    private function releaseReportLock(?string $lockFile): void
    {
        if ($lockFile === null || !is_file($lockFile)) {
            return;
        }
        $lock = json_decode(File::get($lockFile), true);
        $runId = $this->cancellationIdentity['run_id'];
        if ($runId !== null && (!is_array($lock) || ($lock['run_id'] ?? null) !== $runId)) {
            return;
        }
        File::delete($lockFile);
    }
}
