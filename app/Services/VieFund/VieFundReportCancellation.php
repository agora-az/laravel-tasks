<?php

namespace App\Services\VieFund;

use App\Exceptions\ReportCancelled;
use Illuminate\Support\Facades\File;
use RuntimeException;

class VieFundReportCancellation
{
    public const TYPES = ['cashagch', 'daily-balance', 'daily-balance-legacy', 'customer-balances'];

    public static function path(string $type, int $userId, string $runId): string
    {
        return storage_path("app/reports/cancellations/{$type}-{$userId}-{$runId}.cancel");
    }

    public static function requested(?string $path): bool
    {
        if ($path === null) {
            return false;
        }
        clearstatcache(true, $path);

        return is_file($path);
    }

    public static function check(?string $path): void
    {
        if (self::requested($path)) {
            throw new ReportCancelled('Report cancelled by its owner.');
        }
    }

    public static function request(string $path): void
    {
        File::ensureDirectoryExists(dirname($path));
        if (File::put($path, now()->toIso8601String()) === false) {
            throw new RuntimeException('Unable to save the report cancellation request.');
        }
    }

    public static function locked(?string $path, callable $operation): mixed
    {
        if ($path === null) {
            return $operation();
        }
        File::ensureDirectoryExists(dirname($path));
        $handle = fopen($path.'.lock', 'c');
        if ($handle === false) {
            throw new RuntimeException('Unable to open the report state lock.');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to acquire the report state lock.');
            }

            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public static function status(): array
    {
        return [
            'state' => 'cancelled', 'phase' => 'cancelled', 'cancelled' => true,
            'inProgress' => false, 'success' => false,
            'eta_seconds' => null, 'estimated_completion_at' => null,
            'message' => 'Report cancelled. Partial output discarded.',
            'updated_at' => now()->toIso8601String(),
            'completed_at' => now()->toIso8601String(),
        ];
    }

    public static function decorate(array $status, string $type, int $userId, ?string $runId): array
    {
        $status['cancel_url'] = null;
        $status['cancellation_requested'] = false;
        $active = ($status['inProgress'] ?? false) === true
            || in_array($status['state'] ?? null, ['queued', 'running', 'cancelling'], true);
        if (
            !$active || ($status['success'] ?? null) !== null || empty($status['cancellation_supported']) || $runId === null
            || (int) ($status['user_id'] ?? 0) !== $userId
            || ($status['run_id'] ?? null) !== $runId
        ) {
            return $status;
        }
        $status['cancel_url'] = route('reports.cancel', ['report' => $type, 'run_id' => $runId]);
        if (self::requested(self::path($type, $userId, $runId))) {
            $status = array_merge($status, [
                'state' => 'cancelling', 'phase' => 'cancelling', 'cancellation_requested' => true,
                'eta_seconds' => null, 'estimated_completion_at' => null, 'download_url' => null,
                'message' => 'Stopping report after the current database operation finishes...',
            ]);
        }

        return $status;
    }
}
