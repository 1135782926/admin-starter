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
        $query = $this->listQuery();
        TableFilters::apply($query, $filters, ...$this->allowedFilters());

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
