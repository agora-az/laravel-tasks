<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;

class BoundedQueryCount
{
    public static function run(Builder $query): int
    {
        $hint = $query->getConnection()->getDriverName() === 'mysql'
            ? '/*+ MAX_EXECUTION_TIME(30000) */ '
            : '';

        return (int) $query->selectRaw($hint.'COUNT(*) AS aggregate')->value('aggregate');
    }
}
