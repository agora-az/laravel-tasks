<?php

namespace App\Jobs;

use App\Services\Reconciliation\VieFundFundservMatcher;
use App\Services\Reconciliation\FeeTransactionMatcher;
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
        Log::info('RunMatchesJob: Starting integrated match process');

        try {
            // Run VieFund to Fundserv matching
            Log::info('RunMatchesJob: Starting VieFund to Fundserv matching');
            $viefundMatcher = app(VieFundFundservMatcher::class);
            $viefundMatchCount = $viefundMatcher->matchAll(false);
            Log::info('RunMatchesJob: VieFund to Fundserv completed. Created ' . $viefundMatchCount . ' matches');

            // Run Fee matching (Account Fees to Advisory Fees)
            Log::info('RunMatchesJob: Starting Account Fees to Advisory Fees matching');
            $feeMatcher = app(FeeTransactionMatcher::class);
            $feeResults = $feeMatcher->matchFeesToFees(false);
            Log::info('RunMatchesJob: Fee matching completed.', $feeResults);

            $totalMatches = $viefundMatchCount + $feeResults['matched_count'];
            Log::info('RunMatchesJob: Completed successfully. Total matches created: ' . $totalMatches);
        } catch (\Throwable $e) {
            Log::error('RunMatchesJob: Failed with error: ' . $e->getMessage());
            Log::error('RunMatchesJob: File: ' . $e->getFile() . ':' . $e->getLine());
            Log::error('RunMatchesJob: Trace: ' . $e->getTraceAsString());
            throw $e;
        }
    }
}
