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
$outputFile = __DIR__ . '/../docs/viefund_customer_balances_exclusion_review.md';

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
                'name' => trim((string) ($row[0] ?? '')),
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
$quoted = array_map(fn(string $value): string => "'" . str_replace("'", "''", $value) . "'", $keys);
$chunks = array_chunk($quoted, 50);

$sourceRows = collect();
foreach ($chunks as $chunk) {
    $list = implode(',', $chunk);
    $sql = "
SELECT
    p.ID AS plan_id,
    p.DealerAccountID,
    p.DealerCode,
    p.ThirdPartyAccount,
    p.dtStartDate,
    p.dtEndDate,
    c.ID AS customer_id,
    c.FirstName,
    c.LastName,
    c.iDealershipID,
    c.iStatus,
    c.FileID,
    c.SIN_BN,
    c.iFlag,
    ca.AccountID,
    ca.AccountStatus,
    ca.dtCreated
FROM dbo.UB_Plan p
LEFT JOIN dbo.UB_Customer c ON c.ID = p.iClientID
LEFT JOIN dbo.UB_CashAccount ca ON ca.iPlanID = p.ID
WHERE ca.AccountID IN ($list)
   OR p.DealerAccountID IN ($list)
   OR p.ThirdPartyAccount IN ($list)
ORDER BY p.DealerAccountID, p.ID, ca.ID
";
    $sourceRows = $sourceRows->concat(DB::connection('viefund_sqlsrv')->select($sql));
}

$grouped = [];
foreach ($sourceRows as $row) {
    $grouped[$row->DealerAccountID][] = $row;
}

$rows = [];
$flagCounts = [];
foreach ($reportOnly as $key => $item) {
    $dealer = null;
    $planId = null;
    $customer = null;
    $customerId = null;
    $dealershipId = null;
    $customerStatus = null;
    $iFlag = null;
    $dealerCode = null;
    $planStart = null;
    $planEnd = null;
    $cashAccountIds = [];
    $cashStatuses = [];

    foreach ($grouped as $dealerKey => $matches) {
        foreach ($matches as $match) {
            if (
                $normalize((string) ($match->AccountID ?? '')) === $key ||
                $normalize((string) ($match->DealerAccountID ?? '')) === $key ||
                $normalize((string) ($match->ThirdPartyAccount ?? '')) === $key
            ) {
                $dealer = $dealerKey;
                $planId = (string) ($match->plan_id ?? '');
                $customer = trim((string) ($match->FirstName ?? '') . ' ' . (string) ($match->LastName ?? ''));
                $customer = trim($customer);
                $customerId = (string) ($match->customer_id ?? '');
                $dealershipId = (string) ($match->iDealershipID ?? '');
                $customerStatus = (string) ($match->iStatus ?? '');
                $iFlag = (string) ($match->iFlag ?? '');
                $dealerCode = (string) ($match->DealerCode ?? '');
                $planStart = (string) ($match->dtStartDate ?? '');
                $planEnd = (string) ($match->dtEndDate ?? '');
                $cashAccountIds[] = (string) ($match->AccountID ?? '');
                $cashStatuses[] = (string) ($match->AccountStatus ?? '');
            }
        }
    }

    $flags = [];
    if ($planEnd !== '') {
        $flags[] = 'plan_ended';
    } else {
        $flags[] = 'plan_active';
    }
    if ($dealershipId !== '') {
        $flags[] = 'dealership_' . $dealershipId;
        $flagCounts['dealership_' . $dealershipId] = ($flagCounts['dealership_' . $dealershipId] ?? 0) + 1;
    }
    if ($customerStatus !== '') {
        $flags[] = 'customer_status_' . $customerStatus;
        $flagCounts['customer_status_' . $customerStatus] = ($flagCounts['customer_status_' . $customerStatus] ?? 0) + 1;
    }
    if ($iFlag !== '') {
        $flags[] = 'customer_flag_' . $iFlag;
        $flagCounts['customer_flag_' . $iFlag] = ($flagCounts['customer_flag_' . $iFlag] ?? 0) + 1;
    }
    if (in_array('T', $cashStatuses, true)) {
        $flags[] = 'cash_terminated';
        $flagCounts['cash_terminated'] = ($flagCounts['cash_terminated'] ?? 0) + 1;
    }
    if (in_array('A', $cashStatuses, true)) {
        $flags[] = 'cash_active';
        $flagCounts['cash_active'] = ($flagCounts['cash_active'] ?? 0) + 1;
    }

    $rows[] = [
        'name' => $item['name'] ?: '(blank)',
        'plan_account_id' => $item['plan_account_id'],
        'account_id' => $item['account_id'],
        'dealer_account_id' => $dealer ?? '',
        'plan_id' => $planId ?? '',
        'dealer_code' => $dealerCode ?? '',
        'customer_id' => $customerId ?? '',
        'customer_name' => $customer,
        'iDealershipID' => $dealershipId ?? '',
        'iStatus' => $customerStatus ?? '',
        'iFlag' => $iFlag ?? '',
        'plan_start_date' => $planStart ?? '',
        'plan_end_date' => $planEnd ?? '',
        'cash_account_statuses' => implode(',', array_values(array_filter(array_unique($cashStatuses)))),
        'flags' => implode(', ', $flags),
        'balance' => $item['balance'],
    ];
}

usort($rows, function (array $a, array $b): int {
    return strcmp($a['name'], $b['name']) ?: strcmp($a['plan_account_id'], $b['plan_account_id']);
});

$lines = [];
$lines[] = '# VieFund Customer Balances Exclusion Review';
$lines[] = '';
$lines[] = 'Report-only rows after removing cashless placeholders. Review this table for explicit source flags before choosing any exclusion rule.';
$lines[] = '';
$lines[] = '## Summary';
$lines[] = '';
$lines[] = '- Report-only rows: ' . count($reportOnly);
$lines[] = '- Distinct names: ' . count(array_unique(array_column($rows, 'name')));
$lines[] = '';
$lines[] = '## Candidate Flags';
$lines[] = '';
arsort($flagCounts);
foreach ($flagCounts as $flag => $count) {
    $lines[] = '- ' . $flag . ': ' . $count;
}
$lines[] = '';
$lines[] = '## Review Table';
$lines[] = '';
$lines[] = '| Name | Plan Account ID | Account ID | Dealer Account ID | Plan ID | DealerCode | Customer ID | Dealership ID | Customer Status | Customer Flag | Plan Start | Plan End | Cash Statuses | Flags | Balance |';
$lines[] = '| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |';
foreach ($rows as $row) {
    $escape = static function (string $value): string {
        return str_replace(['|', "\n", "\r"], ['\\|', ' ', ' '], $value);
    };
    $lines[] = sprintf(
        '| %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s |',
        $escape($row['name']),
        $escape($row['plan_account_id']),
        $escape($row['account_id']),
        $escape($row['dealer_account_id']),
        $escape($row['plan_id']),
        $escape($row['dealer_code']),
        $escape($row['customer_id']),
        $escape($row['iDealershipID']),
        $escape($row['iStatus']),
        $escape($row['iFlag']),
        $escape($row['plan_start_date']),
        $escape($row['plan_end_date']),
        $escape($row['cash_account_statuses']),
        $escape($row['flags']),
        $escape($row['balance']),
    );
}

file_put_contents($outputFile, implode(PHP_EOL, $lines) . PHP_EOL);
echo $outputFile . PHP_EOL;
