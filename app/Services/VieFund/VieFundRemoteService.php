<?php

namespace App\Services\VieFund;

use App\Services\VieFund\Repositories\SqlServerVieFundRemoteRepository;
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
}
