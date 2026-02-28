<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Services\Reconciliation\TransactionMatcher;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Artisan::command('reconcile:match {--rule=order-id} {--dry-run}', function () {
    $rule = $this->option('rule');
    $dryRun = (bool) $this->option('dry-run');

    /** @var TransactionMatcher $matcher */
    $matcher = app(TransactionMatcher::class);

    if ($rule === 'order-id') {
        $count = $matcher->matchFundservOrderIdToVieFundWoNumber($dryRun);
    } elseif ($rule === 'bank-fundserv') {
        $count = $matcher->matchBankChained($dryRun);
    } else {
        $this->error('Unsupported rule. Use --rule=order-id or --rule=bank-fundserv');
        return 1;
    }
    if ($dryRun) {
        $this->info("Dry run: {$count} potential matches.");
    } else {
        $this->info("Inserted {$count} matches.");
    }

    return 0;
})->purpose('Run reconciliation matching rules');
