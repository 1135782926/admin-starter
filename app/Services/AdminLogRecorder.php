<?php

namespace App\Services;

use App\Models\AdminLog;
use App\Models\AdminMenu;
use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\Attachment;
use App\Models\Member;
use App\Models\MemberGroup;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AdminLogRecorder
{
    /** @var array<string, array{string, string, string, ?string}> */
    private const ROUTES = [
        'admin.login' => ['auth', 'login', 'admin', null],
        'admin.logout' => ['auth', 'logout', 'admin', null],
        'admin.me.profile.update' => ['profile', 'update', 'admin', null],
        'admin.me.password.update' => ['profile', 'change_password', 'admin', null],
        'admin.admins.store' => ['admin', 'create', 'admin', null],
        'admin.admins.update' => ['admin', 'update', 'admin', 'adminUser'],
        'admin.admins.destroy' => ['admin', 'delete', 'admin', 'adminUser'],
        'admin.roles.store' => ['role', 'create', 'role', null],
        'admin.roles.update' => ['role', 'update', 'role', 'role'],
        'admin.roles.destroy' => ['role', 'delete', 'role', 'role'],
        'admin.menus.store' => ['menu', 'create', 'menu', null],
        'admin.menus.update' => ['menu', 'update', 'menu', 'menu'],
        'admin.menus.destroy' => ['menu', 'delete', 'menu', 'menu'],
        'admin.attachments.store' => ['attachment', 'create', 'attachment', null],
        'admin.attachments.update' => ['attachment', 'update', 'attachment', 'attachment'],
        'admin.attachments.destroy' => ['attachment', 'delete', 'attachment', 'attachment'],
        'admin.members.store' => ['member', 'create', 'member', null],
        'admin.members.update' => ['member', 'update', 'member', 'member'],
        'admin.member-groups.store' => ['member_group', 'create', 'member_group', null],
        'admin.member-groups.update' => ['member_group', 'update', 'member_group', 'memberGroup'],
        'admin.wangeditor.store' => ['attachment', 'create', 'attachment', null],
    ];

    public function prepare(Request $request): bool
    {
        $plan = self::ROUTES[$request->route()?->getName() ?? ''] ?? null;
        if (! $plan) {
            return false;
        }
        $request->attributes->set('admin_log.plan', $plan);
        $request->attributes->set('admin_log.started', hrtime(true));
        $request->attributes->set('admin_log.state', new ParameterBag([
            'recorded' => false,
            'login_actor' => null,
            'rollback_callbacks' => [],
        ]));

        return true;
    }

    public function isBusinessWrite(Request $request): bool
    {
        $plan = $request->attributes->get('admin_log.plan');

        return $plan && $plan[0] !== 'auth';
    }

    /** @param Closure(): void $callback */
    public function onRollback(Request $request, Closure $callback): void
    {
        $state = $request->attributes->get('admin_log.state');
        $callbacks = $state->get('rollback_callbacks', []);
        $callbacks[] = $callback;
        $state->set('rollback_callbacks', $callbacks);
    }

    /** @param Closure(): Response $next */
    public function transaction(Request $request, Closure $next): Response
    {
        $level = DB::transactionLevel();
        DB::beginTransaction();
        try {
            $profileChange = $request->attributes->get('admin_log.plan')[0] === 'profile';
            $admins = AdminUser::query();
            if ($profileChange) {
                $admins->lockForUpdate();
            } else {
                AdminRole::where('guard_name', 'web')->orderBy('id')->select('id')->lockForUpdate()->get();
                AdminUser::orderBy('id')->select('id')->lockForUpdate()->get();
                AdminMenu::orderBy('id')->select('id')->lockForUpdate()->get();
            }
            $actor = $request->user();
            $current = $actor instanceof AdminUser ? $admins->find($actor->id) : null;
            if (! $current || ! $current->status || ($profileChange && ! $current->tokens()->whereKey($actor->currentAccessToken()->getKey())->exists())) {
                throw new AuthenticationException;
            }
            $current->withAccessToken($actor->currentAccessToken());
            $request->setUserResolver(fn () => $current);
            $response = $next();
            if (! $response->isSuccessful()) {
                DB::rollBack($level);
                $this->runRollbackCallbacks($request);

                return $response;
            }
            $this->recordSuccess($request, $response);
            DB::commit();

            return $response;
        } catch (Throwable $exception) {
            if (DB::transactionLevel() > $level) {
                DB::rollBack($level);
            }
            $this->runRollbackCallbacks($request);
            throw $exception;
        }
    }

    private function runRollbackCallbacks(Request $request): void
    {
        $state = $request->attributes->get('admin_log.state');
        $callbacks = array_reverse($state->get('rollback_callbacks', []));
        $state->set('rollback_callbacks', []);
        foreach ($callbacks as $callback) {
            try {
                $callback();
            } catch (Throwable $exception) {
                Log::warning('管理员事务回滚清理失败', [
                    'route' => $request->route()?->getName(),
                    'exception_type' => $exception::class,
                ]);
            }
        }
    }

    public function captureBefore(Request $request): void
    {
        if (! $this->isBusinessWrite($request)) {
            return;
        }
        $plan = $request->attributes->get('admin_log.plan');
        $id = $this->targetId($request);
        $request->attributes->set('admin_log.before', $this->snapshot($plan[0], $id));
        $request->attributes->set('admin_log.related_before', $plan[0] === 'role' && $id ? $this->descendants($id) : []);
        if ($plan[0] === 'menu' && $plan[1] === 'update' && ($anchorId = $this->positiveId($request->input('placement.target_id')))) {
            $anchor = AdminMenu::find($anchorId, ['id', 'parent_id']);
            if ($anchor) {
                $request->attributes->set('admin_log.menu_sorts_before', AdminMenu::where('parent_id', $anchor->parent_id)
                    ->whereKeyNot($id)->pluck('sort', 'id')->all());
            }
        }
    }

    public function loginSucceeded(Request $request, AdminUser $actor): void
    {
        $request->attributes->get('admin_log.state')->set('login_actor', $actor);
        $this->persist($request, 200, $actor->id);
    }

    public function finish(Request $request, int $status): void
    {
        if ($request->attributes->get('admin_log.state')->get('recorded')) {
            return;
        }
        try {
            $plan = $request->attributes->get('admin_log.plan');
            if ($plan[1] === 'login' && $status === 429) {
                $username = mb_strtolower($this->attemptedUsername($request) ?? '');
                $key = 'admin-log:throttle:'.hash('sha256', $username.'|'.$request->ip().'|'.now()->format('YmdHi'));
                if (! Cache::add($key, true, 90)) {
                    return;
                }
            }
            $actor = $this->actor($request);
            $id = $plan[0] === 'auth' ? $actor?->id : $this->targetId($request);
            $this->persist($request, $status, $id);
        } catch (Throwable $exception) {
            Log::warning('管理员操作日志写入失败', [
                'route' => $request->route()?->getName(),
                'status' => $status,
                'exception_type' => $exception::class,
            ]);
        }
    }

    private function recordSuccess(Request $request, Response $response): void
    {
        $plan = $request->attributes->get('admin_log.plan');
        $id = $this->targetId($request);
        if ($plan[1] === 'change_password') {
            $this->persist($request, $response->getStatusCode(), $id, $this->actor($request)?->username, ['password' => ['changed' => true]]);

            return;
        }
        if ($plan[1] === 'create') {
            $payload = json_decode($response->getContent(), true);
            $id = $this->positiveId($payload['data']['id'] ?? null);
            if (! $id) {
                throw new \RuntimeException('操作响应缺少审计目标 ID。');
            }
        }
        $before = $request->attributes->get('admin_log.before', []);
        $after = $this->snapshot($plan[0], $id);
        $changes = $this->diff($before, $after);
        if (in_array($plan[0], ['admin', 'member'], true) && $plan[1] !== 'delete' && $request->filled('password')) {
            $changes['password'] = ['changed' => true];
        }
        $related = [];
        if ($plan[0] === 'role' && $id) {
            $relatedBefore = $request->attributes->get('admin_log.related_before', []);
            $relatedAfter = $this->descendants($id);
            foreach (array_unique([...array_keys($relatedBefore), ...array_keys($relatedAfter)]) as $roleId) {
                $old = $relatedBefore[$roleId] ?? [];
                $new = $relatedAfter[$roleId] ?? [];
                $delta = $this->diff($old, $new);
                if ($delta !== []) {
                    $related[] = ['type' => 'role', 'id' => $roleId, 'label' => $new['display_name'] ?? $old['display_name'] ?? '', 'changes' => $delta];
                }
            }
        }
        if ($plan[0] === 'menu' && $plan[1] === 'create') {
            foreach (AdminMenu::where('parent_id', $id)->where('type', 'button')->get() as $button) {
                $related[] = ['type' => 'menu', 'id' => $button->id, 'label' => $button->title, 'changes' => $this->diff([], $this->menuSnapshot($button))];
            }
        }
        $previousSorts = $request->attributes->get('admin_log.menu_sorts_before', []);
        if ($previousSorts !== []) {
            foreach (AdminMenu::whereKey(array_keys($previousSorts))->get(['id', 'title', 'sort']) as $sibling) {
                $previousSort = $previousSorts[$sibling->id];
                if ($previousSort !== $sibling->sort) {
                    $related[] = [
                        'type' => 'menu', 'id' => $sibling->id, 'label' => $sibling->title,
                        'changes' => ['sort' => ['before' => $previousSort, 'after' => $sibling->sort]],
                    ];
                }
            }
        }
        $label = $after['username'] ?? $after['display_name'] ?? $after['title'] ?? $before['username'] ?? $before['display_name'] ?? $before['title'] ?? null;
        if ($plan[0] === 'member_group') {
            $label = $after['name'] ?? $before['name'] ?? null;
        }
        $this->persist($request, $response->getStatusCode(), $id, $label, $changes ?: null, $related ?: null);
    }

    /**
     * @param  array<string, mixed>|null  $changes
     * @param  array<int, array<string, mixed>>|null  $related
     */
    private function persist(Request $request, int $status, ?int $targetId, ?string $label = null, ?array $changes = null, ?array $related = null): void
    {
        $plan = $request->attributes->get('admin_log.plan', self::ROUTES['admin.login']);
        $actor = $this->actor($request);
        $actorId = $actor && AdminUser::whereKey($actor->id)->exists() ? $actor->id : null;
        $success = $status >= 200 && $status < 300;
        AdminLog::create([
            'actor_id' => $actorId,
            'actor_username' => $actor?->username,
            'actor_nickname' => $actor?->nickname,
            'attempted_username' => $plan[1] === 'login' && ! $actor ? $this->attemptedUsername($request) : null,
            'module' => $plan[0],
            'action' => $plan[1],
            'permission_name' => in_array($plan[0], ['auth', 'profile'], true) ? null : 'system.'.$plan[0].'.'.$plan[1],
            'route_name' => $request->route()?->getName() ?? 'admin.login',
            'method' => $request->method(),
            'path' => '/'.ltrim($request->route()?->uri() ?? 'api/v1/admin/auth/login', '/'),
            'target_type' => $plan[2],
            'target_id' => $targetId,
            'target_label' => $success ? $label : null,
            'status_code' => $status,
            'success' => $success,
            'ip_address' => $request->ip(),
            'user_agent' => $this->limited($request->userAgent(), 512),
            'duration_ms' => min(4294967295, max(0, (int) round((hrtime(true) - $request->attributes->get('admin_log.started', hrtime(true))) / 1000000))),
            'changes' => $success ? $changes : null,
            'related_changes' => $success ? $related : null,
        ]);
        $request->attributes->get('admin_log.state')->set('recorded', true);
    }

    private function actor(Request $request): ?AdminUser
    {
        $loginActor = $request->attributes->get('admin_log.state')->get('login_actor');
        if ($loginActor instanceof AdminUser) {
            return $loginActor;
        }
        $actor = $request->attributes->get('admin.authenticated') ? $request->user() : null;

        return $actor instanceof AdminUser ? $actor : null;
    }

    private function attemptedUsername(Request $request): ?string
    {
        $username = $request->input('userName');

        return is_string($username) ? $this->limited(trim($username), 255) : null;
    }

    private function targetId(Request $request): ?int
    {
        $plan = $request->attributes->get('admin_log.plan');
        if (($plan[0] ?? null) === 'profile') {
            return $this->actor($request)?->id;
        }
        $target = $plan && $plan[3] ? $request->route($plan[3]) : null;

        return $this->positiveId($target instanceof Model ? $target->getKey() : $target);
    }

    private function positiveId(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }

    /** @return array<string, mixed> */
    private function snapshot(string $module, ?int $id): array
    {
        if (! $id) {
            return [];
        }
        if ($module === 'profile') {
            return AdminUser::find($id)?->only(['username', 'nickname', 'email', 'mobile', 'avatar', 'signature']) ?? [];
        }
        if ($module === 'admin') {
            $admin = AdminUser::find($id);
            if (! $admin) {
                return [];
            }

            return [
                ...$admin->only(['username', 'nickname', 'email', 'mobile', 'status']),
                'role_ids' => $this->sorted($admin->roles()->pluck('admin_roles.id')->all()),
                'permission_ids' => $this->sorted($admin->permissions()->pluck('admin_permissions.id')->all()),
            ];
        }
        if ($module === 'role') {
            $role = AdminRole::with('permissions')->find($id);

            return $role ? $this->roleSnapshot($role) : [];
        }
        if ($module === 'attachment') {
            $attachment = Attachment::find($id);

            return $attachment ? $attachment->only([
                'title', 'original_name', 'category', 'extension', 'mime_type', 'size', 'sha256',
                'width', 'height', 'disk', 'object_key', 'visibility', 'is_image', 'admin_user_id',
            ]) : [];
        }
        if ($module === 'member') {
            $member = Member::find($id);

            return $member ? [
                ...$member->only(['username', 'nickname', 'member_group_id', 'email', 'mobile', 'gender', 'avatar', 'signature', 'status']),
                'birthday' => $member->birthday?->toDateString(),
            ] : [];
        }
        if ($module === 'member_group') {
            return MemberGroup::find($id)?->only(['name']) ?? [];
        }
        $menu = AdminMenu::find($id);

        return $menu ? $this->menuSnapshot($menu) : [];
    }

    /** @return array<string, mixed> */
    private function roleSnapshot(AdminRole $role): array
    {
        return [...$role->only(['name', 'display_name', 'parent_id', 'status']), 'permission_ids' => $this->sorted($role->permissions->pluck('id')->all())];
    }

    /** @return array<string, mixed> */
    private function menuSnapshot(AdminMenu $menu): array
    {
        $data = $menu->only(['name', 'title', 'type', 'parent_id', 'open_type', 'path', 'component', 'url', 'sort', 'keep_alive', 'status', 'permission_id']);
        if ($menu->permission_id) {
            $data['granted_role_ids'] = $this->sorted(DB::table(config('permission.table_names.role_has_permissions'))->where('permission_id', $menu->permission_id)->pluck('role_id')->all());
            $data['direct_admin_ids'] = $this->sorted(DB::table(config('permission.table_names.model_has_permissions'))->where('permission_id', $menu->permission_id)->where('model_type', (new AdminUser)->getMorphClass())->pluck('model_id')->all());
        }

        return $data;
    }

    /** @return array<int, array<string, mixed>> */
    private function descendants(int $rootId): array
    {
        $roles = AdminRole::where('guard_name', 'web')->with('permissions')->get()->groupBy('parent_id');
        $result = [];
        $pending = [$rootId];
        $seen = [$rootId => true];
        while ($pending !== []) {
            $id = array_pop($pending);
            foreach ($roles->get($id, collect()) as $role) {
                if (isset($seen[$role->id])) {
                    continue;
                }
                $seen[$role->id] = true;
                $result[$role->id] = $this->roleSnapshot($role);
                $pending[] = $role->id;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{before: mixed, after: mixed}|array{changed: true}>
     */
    private function diff(array $before, array $after): array
    {
        $changes = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $field) {
            $old = $before[$field] ?? null;
            $new = $after[$field] ?? null;
            if ($old !== $new) {
                $changes[$field] = in_array($field, ['avatar', 'birthday', 'signature'], true)
                    ? ['changed' => true]
                    : ['before' => $this->sanitize($field, $old), 'after' => $this->sanitize($field, $new)];
            }
        }

        return $changes;
    }

    private function sanitize(string $field, mixed $value): mixed
    {
        if ($value === null || ! is_string($value)) {
            return $value;
        }
        if ($field === 'email') {
            $at = strrpos($value, '@');

            return $at === false ? '***' : mb_substr(substr($value, 0, $at), 0, 1).'***'.substr($value, $at);
        }
        if ($field === 'mobile') {
            return mb_strlen($value) > 7 ? mb_substr($value, 0, 3).'****'.mb_substr($value, -4) : '***';
        }
        if ($field === 'url') {
            $parts = parse_url($value);
            if (! $parts || ! isset($parts['scheme'], $parts['host'])) {
                return '[地址已修改]';
            }

            return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '');
        }

        return $value;
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return array<int, int>
     */
    private function sorted(array $ids): array
    {
        $ids = array_map(intval(...), $ids);
        sort($ids);

        return $ids;
    }

    private function limited(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }
}
