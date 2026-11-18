<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminMenuRequest;
use App\Http\Resources\AdminMenuResource;
use App\Models\AdminMenu;
use App\Services\AdminMenuAccess;
use App\Services\AdminMenuService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MenuController extends Controller
{
    public function __construct(private AdminMenuService $menus, private AdminMenuAccess $access) {}

    public function index(Request $request): JsonResponse
    {
        return $this->success($this->tree($request));
    }

    public function permissionTree(Request $request): JsonResponse
    {
        return $this->success($this->tree($request, true));
    }

    /** @return array<int, array<string, mixed>> */
    private function tree(Request $request, bool $permissions = false): array
    {
        $nodes = AdminMenu::with('permission')->orderBy('sort')->orderBy('id')->get()->keyBy('id');
        $groups = $nodes->groupBy(fn (AdminMenu $node): int => $node->parent_id ?? 0);
        $build = function (int $parentId) use (&$build, $request, $permissions, $nodes, $groups): array {
            return $groups->get($parentId, collect())->map(function (AdminMenu $node) use (&$build, $request, $permissions, $nodes): array {
                $item = (new AdminMenuResource($node))->resolve($request);
                if ($permissions) {
                    $item = array_intersect_key($item, array_flip(['id', 'parent_id', 'type', 'title', 'permission_id', 'permission_name', 'status']));
                    $item['effective_status'] = $this->access->effective($node, $nodes);
                    $item['depends_on'] = $node->type === 'button' && $nodes->get($node->parent_id)?->permission_id ? [$nodes->get($node->parent_id)->permission_id] : [];
                }
                $item['children'] = $build($node->id);

                return $item;
            })->values()->all();
        };

        return $build(0);
    }

    public function show(AdminMenu $menu): JsonResponse
    {
        return $this->success(new AdminMenuResource($menu->load('permission')));
    }

    public function store(AdminMenuRequest $request): JsonResponse
    {
        return $this->success(new AdminMenuResource($this->menus->create($request->validated())), 201);
    }

    public function update(AdminMenuRequest $request, AdminMenu $menu): JsonResponse
    {
        return $this->success(new AdminMenuResource($this->menus->update($menu, $request->validated())));
    }

    public function deletionImpact(AdminMenu $menu): JsonResponse
    {
        return $this->success($this->menus->deletionImpact($menu));
    }

    public function destroy(Request $request, AdminMenu $menu): JsonResponse
    {
        $request->validate(['confirmed' => ['required', 'accepted']]);
        $this->menus->delete($menu);

        return $this->success(null);
    }

    public function current(Request $request): JsonResponse
    {
        return $this->success($this->access->routes($request->user()));
    }

    private function success(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['code' => 200, 'msg' => '操作成功', 'data' => $data], $status);
    }
}
