<?php

namespace App\Services\VieFund;

use Carbon\CarbonInterface;
use App\Services\VieFund\Repositories\SqlServerVieFundRemoteRepository;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class VieFundRemoteService
{
    public function __construct(
        private readonly SqlServerVieFundRemoteRepository $repository
    ) {}

    public function testConnection(): bool
    {
        return $this->repository->ping();
    }

    public function discoverTables(?string $schema = null): Collection
    {
        return $this->repository->listTables($schema);
    }

    public function fetchSampleRows(string $table, int $limit = 100, ?string $schema = null): Collection
    {
        return $this->repository->fetchRows($table, $limit, $schema);
    }

    public function fetchTransactions(?string $search = null, array $filters = []): LengthAwarePaginator
    {
        return $this->repository->fetchTransactions($search, $filters);
    }

    public function countTransactions(): int
    {
        return $this->repository->countTransactions();
    }

    public function exportTransactions(?string $search = null, array $filters = []): Collection
    {
        return $this->repository->exportTransactions($search, $filters);
    }

    public function fetchDailyNetTotals(CarbonInterface $fromDate, CarbonInterface $toDate): Collection
    {
        return $this->repository->fetchDailyNetTotals($fromDate, $toDate);
    }

    public function fetchDailySettlementFundTransactions(CarbonInterface $date, int $perPage = 250, int $page = 1): LengthAwarePaginator
    {
        return $this->repository->fetchDailySettlementFundTransactions($date, $perPage, $page);
    }

    public function fetchDailySettlementTransactions(CarbonInterface $date, int $perPage = 250, int $page = 1): LengthAwarePaginator
    {
        return $this->repository->fetchDailySettlementTransactions($date, $perPage, $page);
    }

    public function getLatestBalance(array $filters = []): ?float
    {
        return $this->repository->getLatestBalance($filters);
    }

    public function getCalculatedBalance(array $filters = []): ?float
    {
        return $this->repository->getCalculatedBalance($filters);
    }

    public function getCalculatedBalancesByPlan(array $filters = []): array
    {
        return $this->repository->getCalculatedBalancesByPlan($filters);
    }

    public function getPageStartBalance(array $filters = [], int $page = 1, int $perPage = 50, ?string $search = null): float
    {
        return $this->repository->getPageStartBalance($filters, $page, $perPage, $search);
    }

    public function getPageStartBalancesByPlan(array $filters = [], int $page = 1, int $perPage = 50, ?string $search = null): array
    {
        return $this->repository->getPageStartBalancesByPlan($filters, $page, $perPage, $search);
    }

    public function searchCustomers(string $search): array
    {
        return $this->repository->searchCustomers($search);
    }

    public function getCustomerForPlanAccount(string $accountId): ?array
    {
        return $this->repository->getCustomerForPlanAccount($accountId);
    }

    public function searchPlanAccounts(string $search): array
    {
        return $this->repository->searchPlanAccounts($search);
    }

    public function fetchDistinctTrxTypes(array $filters = []): array
    {
        return $this->repository->fetchDistinctTrxTypes($filters);
    }

    public function getPlanAccountSnapshot(string $accountId): ?object
    {
        return $this->repository->getPlanAccountSnapshot($accountId);
    }

    public function getDashboardStats(): array
    {
        return $this->repository->getDashboardStats();
    }

    public function fetchMatchingPlanAccounts(?string $search, array $filters): array
    {
        return $this->repository->fetchMatchingPlanAccounts($search, $filters);
    }
}
