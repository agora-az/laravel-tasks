<?php

namespace App\Jobs;

use App\Services\VieFund\VieFundRemoteService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class RefreshVieFundDashboardStats implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 75;

    public function handle(VieFundRemoteService $vieFund): void
    {
        $stats = $vieFund->getDashboardStats();
        $refreshedAt = now();

        // Keep the last good snapshot through temporary remote outages; the
        // scheduler will normally replace it every hour.
        Cache::put('viefund_dashboard_stats', $stats, $refreshedAt->copy()->addDay());
        Cache::put('viefund_dashboard_stats_refreshed_at', $refreshedAt->toIso8601String(), $refreshedAt->copy()->addDay());
        Cache::forget('viefund_dashboard_stats_refresh_queued');
    }
}
