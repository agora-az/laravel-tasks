<?php

$normalize = function (?string $account): string {
    $account = trim((string) $account);
    $account = preg_replace('/^AGRA\s+CASH\s+#?/', '', $account);
    $account = preg_replace('/^AGRP\s+CASH\s+#?/', '', $account);
    $account = preg_replace('/^AGRA\s+/', '', $account);
    $account = preg_replace('/^AGRP\s+/', '', $account);

    return trim((string) $account);
};

$clientFile = __DIR__ . '/../resources/data/reports/cash_bal_settle_20201802.csv';
$reportFile = __DIR__ . '/../storage/app/reports/viefund_customer_balances_20200218_settlement_debug.csv';

$clientRows = 0;
$clientSet = [];

$handle = fopen($clientFile, 'r');
while (is_array($row = fgetcsv($handle))) {
    $account = trim((string) ($row[4] ?? ''));
    if ($account === '' || $account === 'CASH AGCH' || $account === 'Account ID') {
        continue;
    }

    $clientRows++;
    $clientSet[$normalize($account)] = true;
}
fclose($handle);

$reportRows = 0;
$reportSet = [];
$reportZeroActivityRows = 0;

$handle = fopen($reportFile, 'r');
$header = fgetcsv($handle);
while (is_array($row = fgetcsv($handle))) {
    if (count($row) < 10) {
        continue;
    }

    $planAccountId = trim((string) ($row[2] ?? ''));
    if ($planAccountId === '' || $planAccountId === 'Plan Account ID') {
        continue;
    }

    $reportRows++;
    $account = trim((string) ($row[3] ?? ''));
    if ($account === '') {
        $account = $planAccountId;
    }

    $reportSet[$normalize($account)] = true;

    $fundCount = (int) ($row[5] ?? 0);
    $trustCount = (int) ($row[6] ?? 0);
    $balance = (float) str_replace([',', '$', '(', ')'], '', (string) ($row[9] ?? 0));
    if ($fundCount === 0 && $trustCount === 0 && abs($balance) < 0.00001) {
        $reportZeroActivityRows++;
    }
}
fclose($handle);

$clientOnly = array_values(array_diff(array_keys($clientSet), array_keys($reportSet)));
$reportOnly = array_values(array_diff(array_keys($reportSet), array_keys($clientSet)));

echo json_encode([
    'client_rows' => $clientRows,
    'client_distinct' => count($clientSet),
    'report_rows' => $reportRows,
    'report_distinct' => count($reportSet),
    'client_only_count' => count($clientOnly),
    'report_only_count' => count($reportOnly),
    'report_zero_activity_zero_balance_rows' => $reportZeroActivityRows,
    'sample_client_only' => array_slice($clientOnly, 0, 10),
    'sample_report_only' => array_slice($reportOnly, 0, 10),
], JSON_PRETTY_PRINT), PHP_EOL;
