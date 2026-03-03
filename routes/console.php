<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Services\Reconciliation\VieFundFundservMatcher;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

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
