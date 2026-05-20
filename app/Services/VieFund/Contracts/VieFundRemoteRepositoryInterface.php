<?php

namespace App\Services\VieFund\Contracts;

use Illuminate\Support\Collection;

interface VieFundRemoteRepositoryInterface
{
    public function ping(): bool;

    public function listTables(?string $schema = null): Collection;

    public function fetchRows(string $table, int $limit = 100, ?string $schema = null): Collection;
}
