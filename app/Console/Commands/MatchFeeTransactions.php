<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Reconciliation\FeeTransactionMatcher;

class MatchFeeTransactions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reconciliation:match-fees {--dry-run : Run without persisting results}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Match account fees to advisory fees on same client/date/amount';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting Fee Transaction Matching...');
        $this->newLine();

        $matcher = new FeeTransactionMatcher();
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('⚠️  Running in DRY-RUN mode - no data will be persisted');
        }

        $this->info('Matching Account Fees to Advisory Fees...');
        
        $result = $matcher->matchFeesToFees(!$dryRun);

        $this->newLine();
        $this->info('=== Match Results ===');
        $this->line("Account Fees Processed: <fg=cyan>{$result['account_fees_processed']}</>");
        $this->line("Account Fees Matched:   <fg=green>{$result['account_fees_matched']}</>");
        $this->line("Advisory Fees Matched:  <fg=green>{$result['advisory_fees_matched']}</>");
        $this->line("Total Matches Found:    <fg=yellow>{$result['matched_count']}</>");
        $this->newLine();

        if ($result['matched_count'] > 0) {
            $this->table(
                ['Left Type', 'Left ID', 'Right Type', 'Right ID', 'Amount', 'Confidence', 'Rule'],
                array_map(function ($match) {
                    return [
                        $match['left_type'],
                        $match['left_id'],
                        $match['right_type'],
                        $match['right_id'],
                        '$' . number_format($match['matched_amount'], 2),
                        round($match['confidence'] * 100, 1) . '%',
                        $match['match_rule'],
                    ];
                }, array_slice($result['matches'], 0, 10))
            );
            
            if ($result['matched_count'] > 10) {
                $this->line("... and " . ($result['matched_count'] - 10) . " more matches");
            }
        }

        // Show summary
        $this->newLine();
        $this->info('=== Fee Matching Summary ===');
        $summary = $matcher->getMatchSummary();
        
        $this->line("Account Fees:");
        $this->line("  Total:        <fg=cyan>{$summary['account_fees']['total']}</>");
        $this->line("  Matched:      <fg=green>{$summary['account_fees']['matched']}</>");
        $this->line("  Unmatched:    <fg=yellow>{$summary['account_fees']['unmatched']}</>");
        $this->line("  Match Rate:   <fg=blue>{$summary['account_fees']['match_percentage']}%</>");
        
        $this->newLine();
        $this->line("Advisory Fees:");
        $this->line("  Total:        <fg=cyan>{$summary['advisory_fees']['total']}</>");
        $this->line("  Matched:      <fg=green>{$summary['advisory_fees']['matched']}</>");
        $this->line("  Unmatched:    <fg=yellow>{$summary['advisory_fees']['unmatched']}</>");
        $this->line("  Match Rate:   <fg=blue>{$summary['advisory_fees']['match_percentage']}%</>");

        if ($dryRun) {
            $this->newLine();
            $this->warn('This was a dry-run. To persist results, run without --dry-run flag.');
        } else {
            $this->newLine();
            $this->info('✅ Fee matching completed and results persisted.');
        }

        return Command::SUCCESS;
    }
}
