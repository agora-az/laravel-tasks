<?php

namespace App\Services\VieFund;

use App\Services\VieFund\Repositories\SqlServerVieFundRemoteRepository;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class VieFundRemoteService
{
    public function __construct(
        private readonly SqlServerVieFundRemoteRepository $repository
    ) {
    }

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
}
