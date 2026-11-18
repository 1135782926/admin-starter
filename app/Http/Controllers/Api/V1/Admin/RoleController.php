<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminRoleRequest;
use App\Http\Resources\AdminRoleResource;
use App\Models\AdminMenu;
use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Services\AdminManagementScope;
use App\Services\AdminMenuAccess;
use App\Services\AdminRoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoleController extends Controller
{
    public function __construct(private AdminManagementScope $scope, private AdminRoleService $roles) {}

    public function index(Request $request): JsonResponse
    {
        $records = $this->records($request);
        $build = function (?int $parentId) use (&$build, $records): array {
            return collect($records)->filter(fn (array $role): bool => $role['parent_id'] === $parentId || ($parentId === null && ! array_key_exists($role['parent_id'], $records)))
                ->map(fn (array $role): array => [...$role, 'children' => $build($role['id'])])->values()->all();
        };

        return $this->success($build(null));
    }

    public function options(Request $request): JsonResponse
    {
        $access = app(AdminMenuAccess::class);
        abort_unless($access->allows($request->user(), 'system.admin.view') || $access->allows($request->user(), 'system.role.view'), 403, '无权查看角色组选项。');
        $assignable = $this->scope->assignableRoleIds($request->user());

        return $this->success(array_values(array_map(fn (array $role): array => [
            'id' => $role['id'], 'name' => $role['name'], 'display_name' => $role['display_name'],
            'parent_id' => $role['parent_id'], 'status' => $role['status'], 'effective_status' => $role['effective_status'],
            'can_assign' => in_array($role['id'], $assignable, true),
        ], $this->records($request))));
    }

    /** @return array<int, array<string, mixed>> */
    private function records(Request $request): array
    {
        $actor = $request->user();
        $context = $this->scope->context($actor);
        $nodes = $context['nodes'];
        $visible = $nodes->filter(fn (AdminRole $role): bool => $context['super'] || in_array($role->id, $context['own'], true) || $this->scope->canManageRole($actor, $role, $context));
        $counts = DB::table(config('permission.table_names.model_has_roles'))
            ->where('model_type', (new AdminUser)->getMorphClass())->whereIn('role_id', $visible->modelKeys())
            ->selectRaw('role_id, count(*) as total')->groupBy('role_id')->pluck('total', 'role_id');

        return $visible->map(function (AdminRole $role) use ($request, $actor, $context, $nodes, $counts): array {
            $role->setAttribute('permission_ids', $this->scope->allocatedPermissionIds($role, $nodes));
            $role->setAttribute('effective_status', $this->scope->roleEnabled($role, $nodes));
            $role->setAttribute('can_manage', $this->scope->canManageRole($actor, $role, $context));
            $role->setAttribute('admins_count', $counts->get($role->id, 0));

            return (new AdminRoleResource($role))->resolve($request);
        })->all();
    }

    public function show(Request $request, AdminRole $role): JsonResponse
    {
        $records = $this->records($request);
        abort_unless(isset($records[$role->id]), 403, '角色组不在管理范围内。');

        return $this->success($records[$role->id]);
    }

    public function store(AdminRoleRequest $request): JsonResponse
    {
        $role = $this->roles->create($request->user(), $request->validated());

        return $this->success($this->records($request)[$role->id], 201);
    }

    public function update(AdminRoleRequest $request, AdminRole $role): JsonResponse
    {
        $role = $this->roles->update($request->user(), $role, $request->validated());

        return $this->success($this->records($request)[$role->id]);
    }

    public function destroy(Request $request, AdminRole $role): JsonResponse
    {
        $request->validate(['confirmed' => ['required', 'accepted']]);
        $this->roles->delete($request->user(), $role);

        return $this->success(null);
    }

    public function permissionTree(Request $request): JsonResponse
    {
        $context = $this->scope->context($request->user());
        $granted = array_fill_keys($context['granted'], true);
        $nodes = AdminMenu::with('permission')->orderBy('sort')->orderBy('id')->get()->keyBy('id');
        $groups = $nodes->groupBy(fn (AdminMenu $node): int => $node->parent_id ?? 0);
        $access = app(AdminMenuAccess::class);
        $build = function (int $parentId) use (&$build, $nodes, $groups, $access, $granted, $context): array {
            $result = [];
            foreach ($groups->get($parentId, collect()) as $node) {
                $children = $build($node->id);
                $dependency = $node->type === 'button' ? $nodes->get($node->parent_id)?->permission_id : null;
                $assignable = $node->permission_id && $node->permission?->guard_name === 'web' && ($context['super'] || (isset($granted[$node->permission_id]) && (! $dependency || isset($granted[$dependency]))));
                if (! $context['super'] && ! $assignable && $children === []) {
                    continue;
                }
                $result[] = [
                    'id' => $node->id, 'key' => $node->permission_id ? 'permission:'.$node->permission_id : 'menu:'.$node->id,
                    'parent_id' => $node->parent_id, 'type' => $node->type, 'title' => $node->title,
                    'permission_id' => $node->permission_id, 'permission_name' => $node->permission?->name,
                    'status' => $node->status, 'effective_status' => $access->effective($node, $nodes),
                    'depends_on' => $dependency ? [$dependency] : [], 'assignable' => (bool) $assignable,
                    'children' => $children,
                ];
            }

            return $result;
        };

        return $this->success($build(0));
    }

    private function success(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['code' => 200, 'msg' => '操作成功', 'data' => $data], $status);
    }
}
