<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

class BoundedQueryCount
{
    public static function run(Builder $query): int
    {
        return (int) self::value($query, 'COUNT(*)');
    }

    public static function value(Builder $query, string $expression): mixed
    {
        $hint = $query->getConnection()->getDriverName() === 'mysql'
            ? '/*+ MAX_EXECUTION_TIME(30000) */ '
            : '';

        return self::withinTimeout(
            $query,
            fn() => $query->selectRaw($hint.$expression.' AS aggregate')->value('aggregate')
        );
    }

    public static function withinTimeout(Builder|EloquentBuilder $query, callable $callback, int $timeoutMilliseconds = 30000): mixed
    {
        $connection = $query->getConnection();
        if ($connection->getDriverName() !== 'pgsql') {
            return $callback();
        }

        $timeoutMilliseconds = max(1, $timeoutMilliseconds);

        return $connection->transaction(function () use ($connection, $callback, $timeoutMilliseconds): mixed {
            $connection->statement("SET LOCAL statement_timeout = '{$timeoutMilliseconds}ms'");

            return $callback();
        });
    }
}
