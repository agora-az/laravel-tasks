<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$normalize = function (string $account): string {
    $account = trim($account);
    $account = preg_replace('/^AGRA\s+CASH\s+#?/', '', $account);
    $account = preg_replace('/^AGRP\s+CASH\s+#?/', '', $account);
    $account = preg_replace('/^AGRA\s+/', '', $account);
    $account = preg_replace('/^AGRP\s+/', '', $account);
    return trim($account);
};

$clientFile = __DIR__ . '/../resources/data/reports/cash_bal_settle_20201802.csv';
$reportFile = __DIR__ . '/../storage/app/reports/viefund_customer_balances_20200218_settlement_debug.csv';

$clientSet = [];
$handle = fopen($clientFile, 'r');
while (($row = fgetcsv($handle)) !== false) {
    $account = trim((string) ($row[4] ?? ''));
    if ($account === '' || $account === 'CASH AGCH' || $account === 'Account ID') {
        continue;
    }

    $clientSet[$normalize($account)] = true;
}
fclose($handle);

$reportOnly = [];
$handle = fopen($reportFile, 'r');
while (($header = fgetcsv($handle)) !== false) {
    if (($header[0] ?? null) !== 'Client Name') {
        continue;
    }

    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) < 10) {
            continue;
        }

        $planAccountId = trim((string) ($row[2] ?? ''));
        if ($planAccountId === '' || $planAccountId === 'Plan Account ID') {
            continue;
        }

        $accountId = trim((string) ($row[3] ?? ''));
        if ($accountId === '') {
            $accountId = $planAccountId;
        }

        $key = $normalize($accountId);
        if (!isset($clientSet[$key])) {
            $reportOnly[$key] = [
                'plan_account_id' => $planAccountId,
                'account_id' => $accountId,
                'account_status' => trim((string) ($row[4] ?? '')),
                'fund_transactions' => trim((string) ($row[5] ?? '')),
                'trust_transactions' => trim((string) ($row[6] ?? '')),
                'fund_balance' => trim((string) ($row[7] ?? '')),
                'trust_balance' => trim((string) ($row[8] ?? '')),
                'balance' => trim((string) ($row[9] ?? '')),
            ];
        }
    }

    break;
}
fclose($handle);

$keys = array_keys($reportOnly);
$quoted = array_map(function (string $value): string {
    return "'" . str_replace("'", "''", $value) . "'";
}, $keys);
$chunks = array_chunk($quoted, 50);

$rows = collect();
foreach ($chunks as $chunk) {
    $list = implode(',', $chunk);
    $sql = "
SELECT
    p.ID AS plan_id,
    p.DealerAccountID,
    p.ThirdPartyAccount,
    p.dtStartDate,
    p.dtEndDate,
    ca.AccountID,
    ca.AccountStatus,
    ca.dtCreated
FROM dbo.UB_Plan p
LEFT JOIN dbo.UB_CashAccount ca ON ca.iPlanID = p.ID
WHERE ca.AccountID IN ($list)
   OR p.DealerAccountID IN ($list)
   OR p.ThirdPartyAccount IN ($list)
ORDER BY p.DealerAccountID, p.ID, ca.ID
";
    $rows = $rows->concat(DB::connection('viefund_sqlsrv')->select($sql));
}

$examples = [
    'historical_ended' => null,
    'active_current' => null,
    'terminated' => null,
    'legacy_alias' => null,
];

$grouped = [];
foreach ($rows as $row) {
    $grouped[$row->DealerAccountID][] = $row;
}

foreach ($grouped as $dealer => $groupRows) {
    $hasEndDate = false;
    $isTerminated = false;
    $hasLegacyAlias = false;
    $isActive = false;

    foreach ($groupRows as $r) {
        if (!empty($r->dtEndDate)) {
            $hasEndDate = true;
        }
        if (($r->AccountStatus ?? '') === 'T') {
            $isTerminated = true;
        }
        if (($r->AccountStatus ?? '') === 'A') {
            $isActive = true;
        }
        if (!empty($r->ThirdPartyAccount) && $r->ThirdPartyAccount !== $r->DealerAccountID && $r->AccountID === $r->ThirdPartyAccount) {
            $hasLegacyAlias = true;
        }
    }

    $first = $groupRows[0] ?? null;
    $payload = [
        'dealer_account_id' => $dealer,
        'plan_id' => $first->plan_id ?? null,
        'third_party_account' => $first->ThirdPartyAccount ?? null,
        'plan_start_date' => $first->dtStartDate ?? null,
        'plan_end_date' => $first->dtEndDate ?? null,
        'account_id' => $first->AccountID ?? null,
        'account_status' => $first->AccountStatus ?? null,
        'created_at' => $first->dtCreated ?? null,
        'row_count' => count($groupRows),
    ];

    if ($examples['historical_ended'] === null && $hasEndDate) {
        $examples['historical_ended'] = $payload;
    }
    if ($examples['active_current'] === null && $isActive && empty($first->dtEndDate)) {
        $examples['active_current'] = $payload;
    }
    if ($examples['terminated'] === null && $isTerminated) {
        $examples['terminated'] = $payload;
    }
    if ($examples['legacy_alias'] === null && $hasLegacyAlias) {
        $examples['legacy_alias'] = $payload;
    }
}

echo json_encode([
    'report_only_count' => count($reportOnly),
    'examples' => $examples,
], JSON_PRETTY_PRINT), PHP_EOL;
