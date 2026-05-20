<?php

namespace App\Services\VieFund\Repositories;

use App\Services\VieFund\Contracts\VieFundRemoteRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SqlServerVieFundRemoteRepository implements VieFundRemoteRepositoryInterface
{
    private const CONNECTION = 'viefund_sqlsrv';

    public function ping(): bool
    {
        DB::connection(self::CONNECTION)->select('SELECT 1 AS ok');

        return true;
    }

    public function listTables(?string $schema = null): Collection
    {
        $schemaName = $schema ?: env('VIEFUND_DB_SCHEMA', 'dbo');

        return DB::connection(self::CONNECTION)
            ->table('INFORMATION_SCHEMA.TABLES')
            ->select(['TABLE_SCHEMA', 'TABLE_NAME'])
            ->where('TABLE_TYPE', 'BASE TABLE')
            ->where('TABLE_SCHEMA', $schemaName)
            ->orderBy('TABLE_NAME')
            ->get();
    }

    public function fetchRows(string $table, int $limit = 100, ?string $schema = null): Collection
    {
        $safeTable = $this->validateIdentifier($table);
        $safeSchema = $this->validateIdentifier($schema ?: env('VIEFUND_DB_SCHEMA', 'dbo'));

        return DB::connection(self::CONNECTION)
            ->table($safeSchema . '.' . $safeTable)
            ->limit(max(1, min($limit, 5000)))
            ->get();
    }

    private function validateIdentifier(string $value): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value)) {
            throw new InvalidArgumentException('Invalid SQL identifier provided.');
        }

        return $value;
    }
}
