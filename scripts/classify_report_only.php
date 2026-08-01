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

$ids = array_keys($reportOnly);

$quote = function (string $value): string {
    return "'" . str_replace("'", "''", $value) . "'";
};

$classification = [
    'report_only_count' => count($reportOnly),
    'historical_plan_count' => 0,
    'active_plan_count' => 0,
    'terminated_plan_count' => 0,
    'current_third_party_match_count' => 0,
    'legacy_third_party_match_count' => 0,
    'sample' => array_slice($reportOnly, 0, 12, true),
];

if ($ids !== []) {
    $chunks = array_chunk($ids, 50);
    $rows = collect();

    foreach ($chunks as $chunk) {
        $quoted = implode(',', array_map($quote, $chunk));
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
WHERE ca.AccountID IN ($quoted)
   OR p.DealerAccountID IN ($quoted)
   OR p.ThirdPartyAccount IN ($quoted)
ORDER BY p.DealerAccountID, p.ID, ca.ID
";

        $rows = $rows->concat(DB::connection('viefund_sqlsrv')->select($sql));
    }

    $byAccount = [];
    foreach ($rows as $row) {
        $account = trim((string) ($row->AccountID ?? ''));
        if ($account === '') {
            continue;
        }
        $byAccount[$account][] = $row;
    }

    foreach ($reportOnly as $accountId => $item) {
        $related = $byAccount[$item['account_id']] ?? [];
        $planRows = [];
        foreach ($related as $row) {
            $planRows[$row->plan_id] = $row;
        }

        foreach ($planRows as $planRow) {
            if (!empty($planRow->dtEndDate)) {
                $classification['historical_plan_count']++;
            } else {
                $classification['active_plan_count']++;
            }

            if (($planRow->AccountStatus ?? '') === 'T') {
                $classification['terminated_plan_count']++;
            }

            if ($planRow->ThirdPartyAccount === $planRow->AccountID) {
                $classification['current_third_party_match_count']++;
            } elseif ($planRow->ThirdPartyAccount !== null && $planRow->ThirdPartyAccount !== '') {
                $classification['legacy_third_party_match_count']++;
            }
        }
    }

    $classification['sample_source_rows'] = $rows->take(20)->values()->all();
}

echo json_encode($classification, JSON_PRETTY_PRINT), PHP_EOL;
