<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;

class CaseInsensitiveQuery
{
    public static function whereEquals(Builder|EloquentBuilder $query, string $column, string $value): Builder
    {
        $query = self::queryBuilder($query);
        if ($query->getConnection()->getDriverName() !== 'pgsql') {
            return $query->where($column, $value);
        }

        $column = $query->getGrammar()->wrap($column);

        return $query->whereRaw("LOWER({$column}) = LOWER(?)", [$value]);
    }

    public static function whereContains(Builder|EloquentBuilder $query, string $column, string $pattern): Builder
    {
        $query = self::queryBuilder($query);
        if ($query->getConnection()->getDriverName() !== 'pgsql') {
            return $query->where($column, 'like', $pattern);
        }

        $column = $query->getGrammar()->wrap($column);

        return $query->whereRaw("{$column} ILIKE ?", [$pattern]);
    }

    public static function orWhereContains(Builder|EloquentBuilder $query, string $column, string $pattern): Builder
    {
        $query = self::queryBuilder($query);
        if ($query->getConnection()->getDriverName() !== 'pgsql') {
            return $query->orWhere($column, 'like', $pattern);
        }

        $column = $query->getGrammar()->wrap($column);

        return $query->orWhereRaw("{$column} ILIKE ?", [$pattern]);
    }

    private static function queryBuilder(Builder|EloquentBuilder $query): Builder
    {
        return $query instanceof EloquentBuilder ? $query->getQuery() : $query;
    }
}
