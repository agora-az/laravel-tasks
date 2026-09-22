<?php

namespace App\Services\VieFund;

use Carbon\Carbon;
use Carbon\CarbonInterface;

class VieFundDailyBalanceService
{
    public function __construct(
        private readonly VieFundRemoteService $vieFundRemoteService,
        private readonly VieFundCashSnapshotService $snapshotService,
    ) {}

    /**
     * Build the same daily series used by the VieFund Daily Net + Running Balance report.
     *
     * @return array{
     *     rows: array<int, array{report_date: string, transaction_count: int, daily_net_transactions: float, running_daily_balance: float}>,
     *     opening_balance: float,
     *     final_balance: float,
     *     balance_source: string,
     *     uses_snapshots: bool,
     *     snapshot_last_verified_at: ?string,
     *     changed_days: int
     * }
     */
    public function build(
        CarbonInterface $fromDate,
        CarbonInterface $toDate,
        string $dateBasis,
        string $currencyCode,
        array $statusIds,
        ?string $openedBefore = null,
        string $outputOrder = 'asc',
    ): array {
        config([
            'viefund.balance_report_cash_account_scope.currency_code' => $currencyCode,
            'viefund.balance_report_cash_account_scope.opened_before' => $openedBefore,
        ]);

        $snapshotResult = $openedBefore === null
            ? $this->snapshotService->completeSeries($fromDate, $toDate, $dateBasis, $currencyCode, $statusIds)
            : null;
        $dailyTotals = $snapshotResult
            ? $snapshotResult['rows']->map(fn($snapshot) => (object) [
                'total_date' => $snapshot->total_date,
                'transaction_count' => $snapshot->transaction_count,
                'net_total' => $snapshot->net_total,
            ])
            : $this->vieFundRemoteService->fetchCustomerCashDailyNetTotalsByDateColumn(
                $fromDate,
                $toDate,
                $dateBasis,
                [
                    'status_ids' => $statusIds,
                    'availability_as_of' => $toDate->toDateString(),
                ]
            );

        $byDate = [];
        foreach ($dailyTotals as $row) {
            $dateKey = Carbon::parse($row->total_date)->toDateString();
            $byDate[$dateKey] ??= [
                'transaction_count' => 0,
                'net_total' => 0.0,
            ];
            $byDate[$dateKey]['transaction_count'] += (int) $row->transaction_count;
            $byDate[$dateKey]['net_total'] += (float) $row->net_total;
        }

        if ($snapshotResult) {
            $openingBalance = (float) $snapshotResult['opening_balance'];
        } else {
            $periodNetTotal = array_sum(array_column($byDate, 'net_total'));
            $endingBalance = (float) $this->vieFundRemoteService
                ->fetchCustomerBalancesByDate($toDate, $dateBasis, ['status_ids' => $statusIds])
                ->sum(fn($row) => (float) ($row->total_balance ?? 0));
            $openingBalance = $endingBalance - $periodNetTotal;
        }

        $rows = [];
        $runningBalance = $openingBalance;
        $cursor = $fromDate->copy()->startOfDay();
        $lastDate = $toDate->copy()->startOfDay();

        while ($cursor->lte($lastDate)) {
            $dateKey = $cursor->toDateString();
            $day = $byDate[$dateKey] ?? [
                'transaction_count' => 0,
                'net_total' => 0.0,
            ];
            $dailyNet = (float) $day['net_total'];
            $runningBalance += $dailyNet;

            $rows[] = [
                'report_date' => $dateKey,
                'transaction_count' => (int) $day['transaction_count'],
                'daily_net_transactions' => $dailyNet,
                'running_daily_balance' => $runningBalance,
            ];

            $cursor->addDay();
        }

        if ($outputOrder === 'desc') {
            $rows = array_reverse($rows);
        }

        return [
            'rows' => $rows,
            'opening_balance' => $openingBalance,
            'final_balance' => $runningBalance,
            'balance_source' => $snapshotResult ? 'Audited Daily Cash Snapshots' : 'Direct Cash Ledger (Live)',
            'uses_snapshots' => $snapshotResult !== null,
            'snapshot_last_verified_at' => $snapshotResult['last_verified_at'] ?? null,
            'changed_days' => (int) ($snapshotResult['changed_days'] ?? 0),
        ];
    }
}
