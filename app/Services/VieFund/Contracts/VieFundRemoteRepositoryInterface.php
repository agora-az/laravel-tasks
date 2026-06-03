<?php

namespace App\Services\VieFund\Contracts;

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
}
