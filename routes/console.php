<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Services\Reconciliation\VieFundFundservMatcher;
use App\Jobs\RefreshVieFundDashboardStats;
use App\Jobs\RefreshVieFundReportInceptionDates;

Artisan::command('reconcile:match {--rule=viefund-fundserv} {--dry-run}', function () {
    $rule = $this->option('rule');
    $dryRun = (bool) $this->option('dry-run');

    /** @var VieFundFundservMatcher $matcher */
    $matcher = app(VieFundFundservMatcher::class);

    if ($rule === 'viefund-fundserv') {
        $count = $matcher->matchAll($dryRun);
    } else {
        $this->error('Unsupported rule. Use --rule=viefund-fundserv');
        return 1;
    }
    if ($dryRun) {
        $this->info("Dry run: {$count} potential matches.");
    } else {
        $this->info("Inserted {$count} matches.");
    }

    return 0;
})->purpose('Run reconciliation matching rules');

Schedule::job(new RefreshVieFundDashboardStats, 'default', 'database')
    ->hourly()
    ->timezone('America/Toronto')
    ->withoutOverlapping();

Schedule::job(new RefreshVieFundReportInceptionDates, 'default', 'database')
    ->dailyAt('20:45')
    ->timezone('America/Toronto')
    ->withoutOverlapping();

Schedule::command('viefund:sync-daily-totals --days=90')
    ->dailyAt('21:00')
    ->timezone('America/Toronto')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('viefund:sync-cash-daily-snapshots --days=90 --date-basis=settlement_date --currency=00 --statuses=6')
    ->dailyAt('21:15')
    ->timezone('America/Toronto')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('viefund:sync-cash-daily-snapshots --days=90 --date-basis=trade_date --currency=00 --statuses=6')
    ->dailyAt('21:30')
    ->timezone('America/Toronto')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('viefund:sync-cash-daily-snapshots --full --date-basis=settlement_date --currency=00 --statuses=6')
    ->weeklyOn(1, '22:00')
    ->timezone('America/Toronto')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('viefund:sync-cash-daily-snapshots --full --date-basis=trade_date --currency=00 --statuses=6')
    ->weeklyOn(1, '22:15')
    ->timezone('America/Toronto')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('viefund:sync-customers')
    ->dailyAt('22:30')
    ->timezone('America/Toronto')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('bank:sync-entries --parser=v2 --trigger="Scheduled sync" --lock-file=' . storage_path('app/bank-entries-sync.lock') . ' --status-file=' . storage_path('app/bank-entries-sync-status.json'))
    ->dailyAt('23:00')
    ->timezone('America/Toronto')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('settlement:sync-instructions --trigger="Scheduled sync" --lock-file=' . storage_path('app/settlement-instructions-sync.lock') . ' --status-file=' . storage_path('app/settlement-instructions-sync-status.json'))
    ->dailyAt('23:15')
    ->timezone('America/Toronto')
    ->withoutOverlapping()
    ->runInBackground();
