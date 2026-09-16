<?php

namespace App\Jobs;

use App\Services\VieFund\VieFundRemoteService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class RefreshVieFundReportInceptionDates implements ShouldQueue
{
    use Queueable;

    private const DATE_BASES = [
        'create_date',
        'trade_date',
        'processing_date',
        'settlement_date',
    ];

    public int $tries = 1;

    public int $timeout = 75;

    public function handle(VieFundRemoteService $vieFund): void
    {
        $failures = [];

        try {
            foreach (self::DATE_BASES as $basis) {
                try {
                    Cache::forever(
                        "viefund:inception-date:{$basis}",
                        $vieFund->fetchInceptionDateByDateColumn($basis)
                    );
                    Cache::forever(
                        "viefund:legacy-inception-date:{$basis}",
                        $vieFund->fetchLegacyInceptionDateByDateColumn($basis)
                    );
                } catch (Throwable $exception) {
                    $failures[] = $basis;
                    Log::warning('Failed to refresh VieFund report inception date.', [
                        'date_basis' => $basis,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        } finally {
            Cache::forget('viefund:report-inception-dates-refresh-queued');
        }

        if ($failures !== []) {
            throw new RuntimeException('Failed to refresh report inception dates for: ' . implode(', ', $failures));
        }
    }
}
