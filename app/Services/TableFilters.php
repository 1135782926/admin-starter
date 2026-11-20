<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Spatie\QueryBuilder\AllowedFilter;

final class TableFilters
{
    /** @param array<string, mixed> $validated */
    public static function apply(Builder $query, array $validated, AllowedFilter ...$filters): void
    {
        foreach ($filters as $filter) {
            $value = $validated[$filter->getName()] ?? null;

            if ($value !== null) {
                // Preserve scalar values from the existing flat query parameters.
                $filter->delimiter('')->applyTo($query, $value);
            }
        }
    }

    public static function keyword(string ...$columns): AllowedFilter
    {
        return AllowedFilter::callback('keyword', function (Builder $query, string $keyword) use ($columns): void {
            $keyword = trim($keyword);

            if ($keyword === '') {
                return;
            }

            $pattern = '%'.$keyword.'%';
            $query->where(function (Builder $query) use ($columns, $pattern): void {
                foreach ($columns as $column) {
                    $query->orWhere($column, 'like', $pattern);
                }
            });
        });
    }

    /** @return list<AllowedFilter> */
    public static function dateRange(string $column = 'created_at'): array
    {
        return [
            self::dateBoundary('date_from', $column, '>='),
            self::dateBoundary('date_to', $column, '<='),
        ];
    }

    private static function dateBoundary(string $name, string $column, string $operator): AllowedFilter
    {
        return AllowedFilter::callback($name, function (Builder $query, string $value) use ($column, $operator): void {
            $datetime = CarbonImmutable::parse($value)->setTimezone(config('app.timezone', 'UTC'));
            $query->where($column, $operator, $datetime->toDateTimeString());
        });
    }
}
