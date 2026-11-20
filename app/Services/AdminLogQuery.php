<?php

namespace App\Services;

use App\Models\AdminLog;
use App\Models\AdminUser;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class AdminLogQuery
{
    public function __construct(private AdminManagementScope $scope) {}

    /** @param array<string, mixed> $filters */
    public function paginate(AdminUser $actor, array $filters): LengthAwarePaginator
    {
        $query = $this->query($actor)->select([
            'id', 'actor_id', 'actor_username', 'actor_nickname', 'attempted_username',
            'module', 'action', 'permission_name', 'route_name', 'method', 'path',
            'target_type', 'target_id', 'target_label', 'status_code', 'success',
            'ip_address', 'user_agent', 'duration_ms', 'created_at',
        ])->orderByDesc('created_at');

        
        return BaseListService::paginateQuery(
            $query,
            $filters,
            TableFilters::keyword('actor_username', 'actor_nickname', 'attempted_username', 'target_label'),
            AllowedFilter::exact('actor_id'),
            AllowedFilter::exact('module'),
            AllowedFilter::exact('action'),
            AllowedFilter::exact('success'),
            AllowedFilter::exact('status_code'),
            AllowedFilter::exact('ip_address'),
            ...TableFilters::dateRange(),
        );
    }

    public function paginatePersonal(AdminUser $actor, int $page): LengthAwarePaginator
    {
        return AdminLog::where('actor_id', $actor->id)
            ->latest()->orderByDesc('id')
            ->paginate(10, ['id', 'module', 'action', 'success', 'created_at'], 'page', $page);
    }

    public function lastLoginAt(AdminUser $actor): ?string
    {
        return AdminLog::where('actor_id', $actor->id)
            ->where('module', 'auth')
            ->where('action', 'login')
            ->where('success', true)
            ->latest()
            ->first(['created_at'])?->created_at?->toISOString();
    }

    public function find(AdminUser $actor, AdminLog $log): AdminLog
    {
        return $this->query($actor)->whereKey($log->id)->firstOrFail();
    }

    /** @return Builder<AdminLog> */
    private function query(AdminUser $actor): Builder
    {
        $query = AdminLog::query();
        if ($this->scope->isSuper($actor)) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($actor): void {
            $query->where('actor_id', $actor->id)
                ->orWhereIn('actor_id', $this->scope->adminQuery($actor)->select('admin_users.id'));
        });
    }
}
