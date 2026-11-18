<?php

namespace App\Services;

use App\Models\AdminMenu;
use App\Models\AdminUser;
use Illuminate\Database\Eloquent\Collection;

class AdminMenuAccess
{
    public function allows(AdminUser $admin, string $permission): bool
    {
        [$nodes, $granted, $super] = $this->context($admin);
        $node = $nodes->first(fn (AdminMenu $node): bool => $node->permission?->name === $permission && $node->permission?->guard_name === 'web');

        return $node && $admin->status && $this->accessible($node, $nodes, $granted, $super);
    }

    /** @return array<int, string> */
    public function buttons(AdminUser $admin): array
    {
        [$nodes, $granted, $super] = $this->context($admin);

        return $nodes->filter(fn (AdminMenu $node): bool => $node->type === 'button' && $admin->status && $this->accessible($node, $nodes, $granted, $super))
            ->map(fn (AdminMenu $node): string => $node->permission->name)->values()->all();
    }

    /** @return array{Collection<int, AdminMenu>, array<int, bool>, bool} */
    private function context(AdminUser $admin): array
    {
        $nodes = AdminMenu::with('permission')->orderBy('sort')->orderBy('id')->get()->keyBy('id');
        $context = app(AdminManagementScope::class)->context($admin);
        $permissions = $context['granted'];
        $super = $context['super'];

        return [$nodes, array_fill_keys($permissions, true), $super];
    }

    /** @param Collection<int, AdminMenu> $nodes */
    public function effective(AdminMenu $node, Collection $nodes): bool
    {
        $seen = [];
        while (true) {
            if (! $node->status || isset($seen[$node->id])) {
                return false;
            }
            $seen[$node->id] = true;
            if ($node->parent_id === null) {
                return true;
            }
            $node = $nodes->get($node->parent_id);
            if (! $node) {
                return false;
            }
        }
    }

    /**
     * @param  Collection<int, AdminMenu>  $nodes
     * @param  array<int, bool>  $granted
     */
    private function accessible(AdminMenu $node, Collection $nodes, array $granted, bool $super): bool
    {
        if (! $node->permission || $node->permission->guard_name !== 'web' || ! $this->effective($node, $nodes)) {
            return false;
        }
        if (! $super && ! isset($granted[$node->permission_id])) {
            return false;
        }
        if ($node->type === 'button') {
            $page = $nodes->get($node->parent_id);

            return $page && $page->type === 'menu' && $page->permission && $page->permission->guard_name === 'web' && ($super || isset($granted[$page->permission_id]));
        }

        return $node->type === 'menu';
    }

    /** @return array<int, array<string, mixed>> */
    public function routes(AdminUser $admin): array
    {
        if (! $admin->status) {
            return [];
        }
        [$nodes, $granted, $super] = $this->context($admin);
        $groups = $nodes->groupBy(fn (AdminMenu $node): int => $node->parent_id ?? 0);
        $build = function (int $parentId, int $depth) use (&$build, $groups, $nodes, $granted, $super): array {
            $result = [];
            foreach ($groups->get($parentId, collect()) as $node) {
                if ($node->type === 'button' || ! $this->effective($node, $nodes)) {
                    continue;
                }
                $meta = ['title' => $node->title, 'icon' => $node->icon, 'keepAlive' => $node->open_type === 'page' && $node->keep_alive];
                $route = ['id' => $node->id, 'name' => $node->name, 'path' => $node->path, 'meta' => $meta];
                if ($node->type === 'directory') {
                    $children = $build($node->id, $depth + 1);
                    if ($children === []) {
                        continue;
                    }
                    $route['children'] = $children;
                    if ($depth === 0) {
                        $route['component'] = '/index/index';
                    }
                } else {
                    if (! $this->accessible($node, $nodes, $granted, $super)) {
                        continue;
                    }
                    $authList = [];
                    foreach ($groups->get($node->id, collect()) as $button) {
                        $prefix = $node->name.'.';
                        if ($button->type !== 'button' || ! $this->accessible($button, $nodes, $granted, $super) || ! str_starts_with($button->permission->name, $prefix)) {
                            continue;
                        }
                        $operation = substr($button->permission->name, strlen($prefix));
                        $authList[] = ['title' => $button->title, 'authMark' => match ($operation) {
                            'create' => 'add', 'update' => 'edit', default => $operation
                        }];
                    }
                    $route['meta']['authList'] = $authList;
                    if ($node->open_type === 'page') {
                        $route['component'] = $node->component;
                    } else {
                        $route['meta']['link'] = $node->url;
                        $route['meta']['isIframe'] = $node->open_type === 'iframe';
                    }
                }
                $result[] = $route;
            }

            return $result;
        };

        return $build(0, 0);
    }
}
