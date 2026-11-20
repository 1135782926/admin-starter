<?php

namespace App\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\QueryBuilder\AllowedFilter;

/** @template TModel of Model */
abstract class BaseListService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, TModel>
     */
    final public function paginate(array $filters = []): LengthAwarePaginator
    {
        return self::paginateQuery($this->listQuery(), $filters, ...$this->allowedFilters());
    }

    /**
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, T>
     */
    public static function paginateQuery(Builder $query, array $filters, AllowedFilter ...$allowedFilters): LengthAwarePaginator
    {
        TableFilters::apply($query, $filters, ...$allowedFilters);

        return $query->orderByDesc($query->getModel()->getQualifiedKeyName())
            ->paginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1);
    }

    /** @return Builder<TModel> */
    abstract protected function listQuery(): Builder;

    /** @return list<AllowedFilter> */
    protected function allowedFilters(): array
    {
        return [];
    }
}
