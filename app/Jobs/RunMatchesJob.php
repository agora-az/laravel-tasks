<?php

namespace App\Jobs;

use App\Services\Reconciliation\VieFundFundservMatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunMatchesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600; // Allow up to 1 hour for matching
    public $tries = 1; // Don't retry on failure

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('RunMatchesJob: Starting match process');

        try {
            $matcher = app(VieFundFundservMatcher::class);
            $matchCount = $matcher->matchAll(false);
            Log::info('RunMatchesJob: Completed successfully. Created ' . $matchCount . ' matches');
        } catch (\Throwable $e) {
            Log::error('RunMatchesJob: Failed with error: ' . $e->getMessage());
            Log::error('RunMatchesJob: File: ' . $e->getFile() . ':' . $e->getLine());
            Log::error('RunMatchesJob: Trace: ' . $e->getTraceAsString());
            throw $e;
        }
    }
}
