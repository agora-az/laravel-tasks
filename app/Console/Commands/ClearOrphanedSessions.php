<?php

namespace App\Console\Commands;

use App\Models\MatchingSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ClearOrphanedSessions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reconcile:clear-orphaned-sessions 
                            {--all : Clear all incomplete sessions}
                            {--failed : Clear only failed sessions}
                            {--pending : Clear only pending sessions}
                            {--reconciliation-id= : Clear sessions for a specific reconciliation}
                            {--older-than= : Clear sessions older than X hours (default: 24)}
                            {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear orphaned or incomplete matching sessions from the database';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Clearing orphaned matching sessions...');

        // Build the query
        $query = MatchingSession::query();

        // Filter by status
        if ($this->option('failed')) {
            $query->where('status', 'failed');
            $this->line('Filtering: Failed sessions only');
        } elseif ($this->option('pending')) {
            $query->where('status', 'pending');
            $this->line('Filtering: Pending sessions only');
        } elseif ($this->option('all')) {
            $query->whereIn('status', ['pending', 'failed']);
            $this->line('Filtering: All incomplete sessions (pending or failed)');
        } else {
            // Default: clear pending sessions older than specified hours
            $hours = $this->option('older-than') ?? 24;
            $cutoffTime = now()->subHours($hours);
            $query->where('status', 'pending')
                  ->where('created_at', '<', $cutoffTime);
            $this->line("Filtering: Pending sessions created more than {$hours} hours ago");
        }

        // Filter by reconciliation ID if provided
        if ($this->option('reconciliation-id')) {
            $reconciliationId = $this->option('reconciliation-id');
            $query->where('reconciliation_id', $reconciliationId);
            $this->line("Filtering: Reconciliation ID = {$reconciliationId}");
        }

        // Count sessions to be deleted
        $count = $query->count();

        if ($count === 0) {
            $this->info('No orphaned sessions found to clear.');
            return self::SUCCESS;
        }

        // Show what will be deleted
        $this->warn("\nFound {$count} session(s) to delete:");
        $sessions = $query->get(['id', 'status', 'created_at', 'reconciliation_id', 'error_message']);
        
        foreach ($sessions as $session) {
            $status = $session->status;
            $createdAt = $session->created_at->format('Y-m-d H:i:s');
            $reconId = $session->reconciliation_id ?? 'N/A';
            $error = $session->error_message ? substr($session->error_message, 0, 50) . '...' : 'None';
            $this->line("  ID: {$session->id} | Status: {$status} | Created: {$createdAt} | Recon ID: {$reconId} | Error: {$error}");
        }

        // Confirm deletion unless --force is used
        if (!$this->option('force')) {
            if (!$this->confirm("\nProceed with deletion of {$count} session(s)?")) {
                $this->info('Operation cancelled.');
                return self::SUCCESS;
            }
        }

        // Delete the sessions
        $deleted = $query->delete();

        $this->info("\nSuccessfully deleted {$deleted} orphaned session(s).");

        return self::SUCCESS;
    }
}
