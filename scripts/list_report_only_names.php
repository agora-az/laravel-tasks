<?php

require __DIR__ . '/../vendor/autoload.php';

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

$names = [];
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
        if (isset($clientSet[$key])) {
            continue;
        }

        $name = trim((string) ($row[0] ?? ''));
        if ($name === '') {
            $name = '(blank)';
        }

        if (!isset($names[$name])) {
            $names[$name] = [
                'count' => 0,
                'examples' => [],
            ];
        }

        $names[$name]['count']++;
        if (count($names[$name]['examples']) < 3) {
            $names[$name]['examples'][] = [
                'plan_account_id' => $planAccountId,
                'account_id' => $accountId,
                'status' => trim((string) ($row[4] ?? '')),
                'balance' => trim((string) ($row[9] ?? '')),
            ];
        }
    }

    break;
}
fclose($handle);

uasort($names, function (array $a, array $b): int {
    return $b['count'] <=> $a['count'];
});

echo json_encode([
    'distinct_names' => count($names),
    'names' => $names,
], JSON_PRETTY_PRINT), PHP_EOL;
