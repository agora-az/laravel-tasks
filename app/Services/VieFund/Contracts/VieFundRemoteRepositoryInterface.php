<?php

namespace App\Services\VieFund\Contracts;

use Carbon\CarbonInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface VieFundRemoteRepositoryInterface
{
    public function ping(): bool;

    public function listTables(?string $schema = null): Collection;

    public function fetchRows(string $table, int $limit = 100, ?string $schema = null): Collection;

    public function fetchTransactions(?string $search = null, array $filters = []): LengthAwarePaginator;

    public function countTransactions(): int;

    public function searchCustomers(string $search): array;

    public function getCustomerForPlanAccount(string $accountId): ?array;

    public function searchPlanAccounts(string $search): array;

    public function fetchDistinctTrxTypes(array $filters = []): array;

    public function exportTransactions(?string $search = null, array $filters = []): Collection;

    public function fetchDailyNetTotals(CarbonInterface $fromDate, CarbonInterface $toDate, array $filters = [], string $basis = 'settlement_date'): Collection;

    public function fetchCustomerBalancesByDate(CarbonInterface $asOfDate, string $dateColumn, array $filters = []): Collection;

    public function fetchDailySettlementFundTransactions(CarbonInterface $date, int $perPage = 250, int $page = 1): LengthAwarePaginator;

    /**
     * @return array{items: LengthAwarePaginator, transaction_count: int, net_total: float}
     */
    public function fetchDailyTransactions(CarbonInterface $date, array $statusIds, array $trustStatusNames, int $perPage = 250, int $page = 1, string $basis = 'settlement_date', ?bool $hideZeroAmount = null): array;

    public function fetchDailySettlementTransactions(CarbonInterface $date, int $perPage = 250, int $page = 1): LengthAwarePaginator;

    public function getLatestBalance(array $filters = []): ?float;

    public function getCalculatedBalance(array $filters = []): ?float;

    public function getCalculatedBalancesByPlan(array $filters = []): array;

    public function getPageStartBalance(array $filters = [], int $page = 1, int $perPage = 50, ?string $search = null): float;

    public function getPageStartBalancesByPlan(array $filters = [], int $page = 1, int $perPage = 50, ?string $search = null): array;

    public function getPlanAccountSnapshot(string $accountId): ?object;

    public function getDashboardStats(): array;

    public function fetchMatchingPlanAccounts(?string $search, array $filters): array;
}
