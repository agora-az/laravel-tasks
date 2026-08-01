<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$service = $app->make(App\Services\VieFund\VieFundRemoteService::class);
$rows = $service->fetchCustomerBalancesByDate(
    Carbon\Carbon::parse('2020-02-18'),
    'settlement_date',
    [
        'status_ids' => [6],
        'trust_status_names' => ['Settled'],
    ]
);

$targets = ['510559PAR', '512519PAT'];
$pairedPlanIds = ['5106594PAR(T)', '5125129PAT'];

foreach ($targets as $id) {
    $matches = $rows->filter(function ($row) use ($id) {
        return trim((string) ($row->account_id ?? '')) === $id
            || trim((string) ($row->plan_account_id ?? '')) === $id;
    })->values();

    echo "=== {$id} in report rows ===\n";
    if ($matches->isEmpty()) {
        echo "NOT FOUND\n";
        continue;
    }

    foreach ($matches as $match) {
        echo json_encode([
            'plan_account_id' => $match->plan_account_id ?? null,
            'account_id' => $match->account_id ?? null,
            'account_status' => $match->account_status ?? null,
            'fund_transaction_count' => $match->fund_transaction_count ?? null,
            'trust_transaction_count' => $match->trust_transaction_count ?? null,
            'total_balance' => $match->total_balance ?? null,
        ], JSON_PRETTY_PRINT), "\n";
    }
}

foreach ($pairedPlanIds as $planId) {
    $match = $rows->first(function ($row) use ($planId) {
        return trim((string) ($row->plan_account_id ?? '')) === $planId;
    });

    echo "=== paired plan {$planId} in report rows ===\n";
    if (!$match) {
        echo "NOT FOUND\n";
        continue;
    }

    echo json_encode([
        'plan_account_id' => $match->plan_account_id ?? null,
        'account_id' => $match->account_id ?? null,
        'account_status' => $match->account_status ?? null,
        'fund_transaction_count' => $match->fund_transaction_count ?? null,
        'trust_transaction_count' => $match->trust_transaction_count ?? null,
        'total_balance' => $match->total_balance ?? null,
    ], JSON_PRETTY_PRINT), "\n";
}
