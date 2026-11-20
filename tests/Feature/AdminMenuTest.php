<?php

use App\Models\AdminLog;
use App\Models\AdminMenu;
use App\Models\AdminPermission;
use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\User;
use App\Services\AdminMenuAccess;
use Database\Seeders\AdminMenuSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function menuTestSuperAdmin(): AdminUser
{
    $admin = AdminUser::factory()->create();
    $admin->assignRole(AdminRole::findOrCreate('super-admin', 'web'));

    return $admin;
}

function menuTestNode(array $attributes): AdminMenu
{
    $type = $attributes['type'] ?? 'menu';
    $name = $attributes['name'];
    $permission = $type === 'directory' ? null : AdminPermission::create([
        'name' => $type === 'menu' ? $name.'.access' : $name,
        'guard_name' => 'web',
    ]);

    return AdminMenu::create(array_merge([
        'type' => 'menu',
        'title' => $name,
        'permission_id' => $permission?->id,
        'open_type' => $type === 'menu' ? 'page' : null,
        'path' => $type === 'button' ? null : '/'.str_replace('.', '/', $name),
        'component' => $type === 'menu' ? '/system/menu' : null,
        'status' => true,
        'sort' => 0,
        'keep_alive' => false,
    ], $attributes));
}

test('initializes menu management permissions idempotently without creating accounts or roles', function (): void {
    $this->seed(AdminMenuSeeder::class);
    $this->seed(AdminMenuSeeder::class);

    $this->assertDatabaseCount('admin_menus', 5);
    $this->assertDatabaseCount('admin_permissions', 5);
    $this->assertDatabaseCount('admin_users', 0);
    $this->assertDatabaseCount('admin_roles', 0);
    expect(AdminPermission::query()->orderBy('name')->pluck('name')->all())->toBe([
        'system.menu.access', 'system.menu.create', 'system.menu.delete', 'system.menu.update', 'system.menu.view',
    ]);
    expect(AdminPermission::query()->pluck('guard_name')->unique()->all())->toBe(['web']);
});

test('creates a page and the default four button permissions atomically', function (): void {
    $this->travelTo('2026-11-18 02:41:19');
    $this->seed(AdminMenuSeeder::class);
    $admin = menuTestSuperAdmin();

    $response = $this->withToken($admin->createToken('create')->plainTextToken)->postJson('/api/v1/admin/menus', [
        'type' => 'menu', 'name' => 'goods', 'title' => '商品管理',
        'open_type' => 'page', 'path' => '/goods', 'component' => '/goods/index',
    ]);

    $response->assertSuccessful()->assertJsonPath('code', 200)->assertJsonPath('msg', '操作成功')
        ->assertJsonPath('data.created_at', '2026-11-18 02:41:19')
        ->assertJsonPath('data.updated_at', '2026-11-18 02:41:19')
        ->assertJsonPath('data.name', 'goods')->assertJsonPath('data.permission_name', 'goods.access');
    $menu = AdminMenu::query()->where('name', 'goods')->firstOrFail();
    expect(AdminMenu::query()->where('parent_id', $menu->id)->orderBy('id')->pluck('name')->all())
        ->toBe(['goods.view', 'goods.create', 'goods.update', 'goods.delete']);
    $this->assertDatabaseCount('admin_menus', 10);
    $this->assertDatabaseCount('admin_permissions', 10);
    foreach (['goods.access', 'goods.view', 'goods.create', 'goods.update', 'goods.delete'] as $name) {
        $this->assertDatabaseHas('admin_permissions', ['name' => $name, 'guard_name' => 'web']);
    }
});

test('uses only the explicitly selected basic page buttons', function (array $buttons, array $expected): void {
    $this->seed(AdminMenuSeeder::class);
    $admin = menuTestSuperAdmin();

    $this->withToken($admin->createToken('buttons')->plainTextToken)->postJson('/api/v1/admin/menus', [
        'type' => 'menu', 'name' => 'goods', 'title' => 'Goods', 'open_type' => 'page',
        'path' => '/goods', 'component' => '/goods/index', 'buttons' => $buttons,
    ])->assertSuccessful();

    $menu = AdminMenu::query()->where('name', 'goods')->firstOrFail();
    expect(AdminMenu::query()->where('parent_id', $menu->id)->orderBy('id')->pluck('name')->all())->toBe($expected);
    $this->assertDatabaseCount('admin_permissions', 6 + count($expected));
})->with([
    'empty selection' => [[], []],
    'partial selection' => [['view', 'delete'], ['goods.view', 'goods.delete']],
]);

test('creates directories and remote pages without automatic operation buttons', function (array $payload, int $permissions): void {
    $this->seed(AdminMenuSeeder::class);
    $admin = menuTestSuperAdmin();

    $this->withToken($admin->createToken('node')->plainTextToken)
        ->postJson('/api/v1/admin/menus', $payload + ['name' => 'node', 'title' => 'Node', 'path' => '/node'])
        ->assertSuccessful();

    $this->assertDatabaseCount('admin_menus', 6);
    $this->assertDatabaseCount('admin_permissions', 5 + $permissions);
    $node = AdminMenu::query()->where('name', 'node')->firstOrFail();
    expect($node->permission_id === null)->toBe($permissions === 0);
})->with([
    'directory' => [['type' => 'directory'], 0],
    'external link' => [['type' => 'menu', 'open_type' => 'external', 'url' => 'https://example.com/docs'], 1],
    'iframe' => [['type' => 'menu', 'open_type' => 'iframe', 'url' => 'http://example.com/embed'], 1],
]);

test('returns 422 for invalid creation fields without persisting nodes or permissions', function (array $changes, array $fields): void {
    $this->seed(AdminMenuSeeder::class);
    $admin = menuTestSuperAdmin();
    $payload = array_replace([
        'type' => 'menu', 'name' => 'goods', 'title' => 'Goods', 'open_type' => 'page',
        'path' => '/goods', 'component' => '/goods/index', 'buttons' => [],
    ], $changes);

    $this->withToken($admin->createToken('invalid')->plainTextToken)->postJson('/api/v1/admin/menus', $payload)
        ->assertUnprocessable()->assertJsonPath('code', 422)->assertJsonValidationErrors($fields);

    $this->assertDatabaseCount('admin_menus', 5);
    $this->assertDatabaseCount('admin_permissions', 5);
})->with([
    'missing title' => [['title' => ''], ['title']],
    'duplicate identifier' => [['name' => 'system.menu'], ['name']],
    'invalid type' => [['type' => 'unknown'], ['type']],
    'missing page path' => [['path' => null], ['path']],
    'missing page component' => [['component' => null], ['component']],
    'page cannot take URL' => [['url' => 'https://example.com'], ['url']],
    'permission binding is server owned' => [['permission_id' => 1], ['permission_id']],
    'permission name is server owned' => [['permission_name' => 'system.menu.access'], ['permission_name']],
    'nonexistent parent' => [['parent_id' => 999999], ['parent_id']],
    'menu cannot contain another menu' => [['parent_id' => 1], ['parent_id']],
    'invalid base operation' => [['buttons' => ['export']], ['buttons.0']],
    'directory rejects page configuration' => [['type' => 'directory'], ['open_type', 'component', 'buttons']],
    'external rejects component and cache' => [['open_type' => 'external', 'url' => 'https://example.com', 'keep_alive' => true], ['component', 'keep_alive', 'buttons']],
    'remote URL must be HTTP' => [['open_type' => 'iframe', 'url' => 'javascript:alert(1)', 'component' => null], ['url']],
]);

test('creates custom buttons only beneath menus and rejects route configuration', function (): void {
    $this->seed(AdminMenuSeeder::class);
    $admin = menuTestSuperAdmin();
    $menu = menuTestNode(['name' => 'goods']);
    $this->withToken($admin->createToken('button')->plainTextToken);

    $this->postJson('/api/v1/admin/menus', [
        'type' => 'button', 'parent_id' => $menu->id, 'title' => 'Export', 'name' => 'goods.export',
    ])->assertSuccessful()->assertJsonPath('data.permission_name', 'goods.export');

    $this->assertDatabaseHas('admin_permissions', ['name' => 'goods.export', 'guard_name' => 'web']);
    $this->postJson('/api/v1/admin/menus', [
        'type' => 'button', 'parent_id' => $menu->id, 'title' => 'Import', 'name' => 'goods.import',
        'path' => '/import', 'icon' => 'icon', 'component' => '/import', 'keep_alive' => true,
    ])->assertUnprocessable()->assertJsonValidationErrors(['path', 'icon', 'component', 'keep_alive']);
    $this->assertDatabaseMissing('admin_permissions', ['name' => 'goods.import']);
});

test('rolls back the full page and permissions when a generated button fails midway through creation', function (): void {
    $this->seed(AdminMenuSeeder::class);
    $admin = menuTestSuperAdmin();
    $dispatcher = AdminMenu::getEventDispatcher();
    $temporary = clone $dispatcher;
    $temporary->listen('eloquent.created: '.AdminMenu::class, function (AdminMenu $menu): void {
        if ($menu->name === 'goods.update') {
            throw new RuntimeException('Forced mid-create failure');
        }
    });
    AdminMenu::setEventDispatcher($temporary);

    try {
        $this->withToken($admin->createToken('rollback')->plainTextToken)->postJson('/api/v1/admin/menus', [
            'type' => 'menu', 'name' => 'goods', 'title' => 'Goods', 'open_type' => 'page',
            'path' => '/goods', 'component' => '/goods/index',
        ])->assertServerError();
    } finally {
        AdminMenu::setEventDispatcher($dispatcher);
    }

    $this->assertDatabaseCount('admin_menus', 5);
    $this->assertDatabaseCount('admin_permissions', 5);
    $this->assertDatabaseCount('admin_role_has_permissions', 0);
    $this->assertDatabaseCount('admin_model_has_permissions', 0);
});

test('rejects all type and name patch fields with 422 even when the value is unchanged', function (array $changes, string $field): void {
    $this->seed(AdminMenuSeeder::class);
    $admin = menuTestSuperAdmin();
    $menu = menuTestNode(['name' => 'goods']);

    $this->withToken($admin->createToken('immutable')->plainTextToken)->patchJson('/api/v1/admin/menus/'.$menu->id, $changes)
        ->assertUnprocessable()->assertJsonValidationErrors([$field]);

    expect($menu->fresh()->name)->toBe('goods');
    expect($menu->fresh()->type)->toBe('menu');
})->with([
    'same type' => [['type' => 'menu'], 'type'],
    'different type' => [['type' => 'directory'], 'type'],
    'same name' => [['name' => 'goods'], 'name'],
    'different name' => [['name' => 'products'], 'name'],
    'permission id' => [['permission_id' => 1], 'permission_id'],
    'permission name' => [['permission_name' => 'other.access'], 'permission_name'],
    'buttons do not regenerate' => [['buttons' => ['create']], 'buttons'],
]);

test('updates presentation and moves a page while preserving permissions grants and disabled children', function (): void {
    $this->seed(AdminMenuSeeder::class);
    $admin = menuTestSuperAdmin();
    $target = menuTestNode(['type' => 'directory', 'name' => 'disabled', 'status' => false]);
    $menu = menuTestNode(['name' => 'goods']);
    $button = menuTestNode(['type' => 'button', 'name' => 'goods.view', 'parent_id' => $menu->id, 'status' => false]);
    $role = AdminRole::create(['name' => 'reader', 'guard_name' => 'web']);
    $role->givePermissionTo(AdminPermission::findById($menu->permission_id, 'web'));
    $permissionId = $menu->permission_id;

    $this->withToken($admin->createToken('update')->plainTextToken)->patchJson('/api/v1/admin/menus/'.$menu->id, [
        'parent_id' => $target->id, 'title' => 'Products', 'path' => '/products', 'component' => '/products/index', 'sort' => 12,
    ])->assertOk()->assertJsonPath('data.permission_name', 'goods.access');

    expect($menu->fresh()->permission_id)->toBe($permissionId);
    expect($menu->fresh()->parent_id)->toBe($target->id);
    expect($menu->fresh()->status)->toBeTrue();
    expect($button->fresh()->status)->toBeFalse();
    expect(AdminMenu::query()->where('parent_id', $menu->id)->count())->toBe(1);
    $this->assertDatabaseHas('admin_role_has_permissions', ['role_id' => $role->id, 'permission_id' => $permissionId]);
});

test('persists dragged sibling order in management and navigation when sorts are tied', function (string $position): void {
    $this->seed(AdminMenuSeeder::class);
    $admin = menuTestSuperAdmin();
    $directory = menuTestNode(['name' => 'sortable', 'type' => 'directory']);
    $first = menuTestNode(['name' => 'sortable.first', 'parent_id' => $directory->id, 'sort' => -4]);
    $middle = menuTestNode(['name' => 'sortable.middle', 'parent_id' => $directory->id, 'sort' => -4]);
    $last = menuTestNode(['name' => 'sortable.last', 'parent_id' => $directory->id, 'sort' => 6]);
    $this->withToken($admin->createToken('reorder')->plainTextToken);

    [$moved, $anchor, $expected] = $position === 'before'
        ? [$last, $first, [$last->id, $first->id, $middle->id]]
        : [$first, $last, [$middle->id, $last->id, $first->id]];

    $this->patchJson('/api/v1/admin/menus/'.$moved->id, [
        'placement' => ['target_id' => $anchor->id, 'position' => $position],
    ])->assertOk()->assertJsonPath('data.parent_id', $directory->id);

    foreach (['/api/v1/admin/menus', '/api/v1/admin/me/menus'] as $path) {
        $response = $this->getJson($path)->assertOk();
        $root = collect($response->json('data'))->firstWhere('id', $directory->id);
        expect(array_column($root['children'], 'id'))->toBe($expected);
    }
    $audit = AdminLog::where('route_name', 'admin.menus.update')->sole();
    expect($audit->target_id)->toBe($moved->id)
        ->and(collect($audit->related_changes)->pluck('id')->contains($anchor->id))->toBeTrue();
})->with(['before', 'after']);

test('rejects relative placement into a descendant branch without changing any ranks', function (): void {
    $this->seed(AdminMenuSeeder::class);
    $admin = menuTestSuperAdmin();
    $directory = menuTestNode(['name' => 'parent', 'type' => 'directory', 'sort' => 11]);
    $child = menuTestNode(['name' => 'parent.child', 'parent_id' => $directory->id, 'sort' => 20]);

    $this->withToken($admin->createToken('invalid-reorder')->plainTextToken)
        ->patchJson('/api/v1/admin/menus/'.$directory->id, [
            'placement' => ['target_id' => $child->id, 'position' => 'after'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['parent_id']);

    $this->assertDatabaseHas('admin_menus', ['id' => $directory->id, 'parent_id' => null, 'sort' => 11]);
    $this->assertDatabaseHas('admin_menus', ['id' => $child->id, 'parent_id' => $directory->id, 'sort' => 20]);
});

test('rejects self descendant and invalid type moves with 422', function (string $move): void {
    $this->seed(AdminMenuSeeder::class);
    $admin = menuTestSuperAdmin();
    $root = menuTestNode(['type' => 'directory', 'name' => 'root']);
    $child = menuTestNode(['type' => 'directory', 'name' => 'child', 'parent_id' => $root->id]);
    $menu = menuTestNode(['name' => 'goods', 'parent_id' => $child->id]);
    $other = menuTestNode(['name' => 'other']);
    $button = menuTestNode(['type' => 'button', 'name' => 'goods.view', 'parent_id' => $menu->id]);
    [$node, $parent] = match ($move) {
        'self' => [$root, $root],
        'descendant' => [$root, $child],
        'directory under menu' => [$root, $menu],
        'menu under button' => [$menu, $button],
        'button across menus' => [$button, $other],
        'button under directory' => [$button, $root],
    };
    $oldParent = $node->parent_id;

    $this->withToken($admin->createToken('move')->plainTextToken)->patchJson('/api/v1/admin/menus/'.$node->id, ['parent_id' => $parent->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['parent_id']);

    expect($node->fresh()->parent_id)->toBe($oldParent);
})->with(['self', 'descendant', 'directory under menu', 'menu under button', 'button across menus', 'button under directory']);

test('requires the specific operation permission on every menu management endpoint', function (string $method, string $suffix, array $payload): void {
    $this->seed(AdminMenuSeeder::class);
    $admin = AdminUser::factory()->create();
    $admin->givePermissionTo('system.menu.access');
    $menu = menuTestNode(['name' => 'goods']);
    $path = '/api/v1/admin/menus'.str_replace('{id}', (string) $menu->id, $suffix);

    $this->withToken($admin->createToken('denied')->plainTextToken)->json($method, $path, $payload)
        ->assertForbidden()->assertJsonPath('code', 403);

    $this->assertModelExists($menu);
    expect($menu->fresh()->title)->toBe('goods');
    $this->assertDatabaseMissing('admin_menus', ['name' => 'unauthorized']);
})->with([
    'management tree' => ['GET', '', []],
    'permission tree' => ['GET', '/permission-tree', []],
    'detail' => ['GET', '/{id}', []],
    'impact' => ['GET', '/{id}/deletion-impact', []],
    'create' => ['POST', '', ['type' => 'directory', 'name' => 'unauthorized', 'title' => 'Unauthorized', 'path' => '/unauthorized']],
    'update' => ['PATCH', '/{id}', ['title' => 'Unauthorized']],
    'delete' => ['DELETE', '/{id}', ['confirmed' => true]],
]);

test('returns 403 for an operation grant without the management page access grant', function (): void {
    $this->seed(AdminMenuSeeder::class);
    $admin = AdminUser::factory()->create();
    $admin->givePermissionTo('system.menu.view');

    $this->withToken($admin->createToken('missing-access')->plainTextToken)->getJson('/api/v1/admin/menus')
        ->assertForbidden()->assertJsonPath('code', 403);
});

test('returns 404 for nonexistent nodes after authenticating an authorized administrator', function (string $method, string $suffix, array $payload): void {
    $this->seed(AdminMenuSeeder::class);
    $admin = menuTestSuperAdmin();

    $this->withToken($admin->createToken('missing')->plainTextToken)->json($method, '/api/v1/admin/menus/999999'.$suffix, $payload)
        ->assertNotFound()->assertJsonPath('code', 404);
})->with([
    'detail' => ['GET', '', []],
    'impact' => ['GET', '/deletion-impact', []],
    'update' => ['PATCH', '', ['title' => 'Missing']],
    'delete' => ['DELETE', '', ['confirmed' => true]],
]);

test('returns the complete sorted management tree including disabled buttons for an ordinary reader', function (): void {
    $this->seed(AdminMenuSeeder::class);
    $admin = AdminUser::factory()->create();
    $admin->givePermissionTo(['system.menu.access', 'system.menu.view']);
    $root = menuTestNode(['type' => 'directory', 'name' => 'root', 'sort' => -10]);
    $second = menuTestNode(['name' => 'second', 'parent_id' => $root->id, 'sort' => 5]);
    $first = menuTestNode(['name' => 'first', 'parent_id' => $root->id, 'sort' => 0, 'status' => false]);
    $third = menuTestNode(['name' => 'third', 'parent_id' => $root->id, 'sort' => 5]);
    $button = menuTestNode(['type' => 'button', 'name' => 'first.view', 'parent_id' => $first->id, 'status' => false]);

    $response = $this->withToken($admin->createToken('tree')->plainTextToken)->getJson('/api/v1/admin/menus');

    $response->assertOk()->assertJsonPath('code', 200)->assertJsonPath('data.0.id', $root->id)
        ->assertJsonPath('data.0.children.0.status', false)
        ->assertJsonPath('data.0.children.0.children.0.id', $button->id);
    expect(array_column($response->json('data.0.children'), 'id'))->toBe([$first->id, $second->id, $third->id]);
    $this->getJson('/api/v1/admin/menus/'.$first->id)->assertOk()
        ->assertJsonPath('data.permission_id', $first->permission_id)->assertJsonPath('data.permission_name', 'first.access');
});

test('returns permission IDs dependencies and effective ancestor status in the permission selection tree', function (): void {
    AdminPermission::create(['name' => 'unbound.permission', 'guard_name' => 'web']);
    AdminPermission::create(['name' => 'another.unbound.permission', 'guard_name' => 'web']);
    $this->seed(AdminMenuSeeder::class);
    $admin = AdminUser::factory()->create();
    $admin->givePermissionTo(['system.menu.access', 'system.menu.view']);
    $root = menuTestNode(['type' => 'directory', 'name' => 'root', 'status' => false, 'sort' => -10]);
    $menu = menuTestNode(['name' => 'goods', 'parent_id' => $root->id]);
    $button = menuTestNode(['type' => 'button', 'name' => 'goods.view', 'parent_id' => $menu->id]);

    $response = $this->withToken($admin->createToken('permission-tree')->plainTextToken)->getJson('/api/v1/admin/menus/permission-tree');

    $response->assertOk()->assertJsonPath('data.0.id', $root->id)
        ->assertJsonPath('data.0.permission_id', null)->assertJsonPath('data.0.depends_on', [])
        ->assertJsonPath('data.0.children.0.id', $menu->id)
        ->assertJsonPath('data.0.children.0.permission_id', $menu->permission_id)
        ->assertJsonPath('data.0.children.0.permission_name', 'goods.access')
        ->assertJsonPath('data.0.children.0.status', true)->assertJsonPath('data.0.children.0.effective_status', false)
        ->assertJsonPath('data.0.children.0.children.0.id', $button->id)
        ->assertJsonPath('data.0.children.0.children.0.permission_name', 'goods.view')
        ->assertJsonPath('data.0.children.0.children.0.depends_on', [$menu->permission_id])
        ->assertJsonPath('data.0.children.0.children.0.effective_status', false);
    expect($menu->id)->not->toBe($menu->permission_id);
});

test('filters routes and returns the union of effective role and direct operation grants without permission snapshots', function (): void {
    $admin = AdminUser::factory()->create();
    $root = menuTestNode(['type' => 'directory', 'name' => 'root']);
    $menu = menuTestNode(['name' => 'goods', 'parent_id' => $root->id, 'title' => 'Goods', 'icon' => 'ri:box-line', 'keep_alive' => true]);
    $view = menuTestNode(['type' => 'button', 'name' => 'goods.view', 'parent_id' => $menu->id]);
    $create = menuTestNode(['type' => 'button', 'name' => 'goods.create', 'parent_id' => $menu->id]);
    $update = menuTestNode(['type' => 'button', 'name' => 'goods.update', 'parent_id' => $menu->id]);
    $custom = menuTestNode(['type' => 'button', 'name' => 'goods.review.bulk', 'parent_id' => $menu->id]);
    $disabled = menuTestNode(['type' => 'button', 'name' => 'goods.delete', 'parent_id' => $menu->id, 'status' => false]);
    $hidden = menuTestNode(['name' => 'hidden', 'parent_id' => $root->id]);
    menuTestNode(['type' => 'directory', 'name' => 'empty']);
    $firstRole = AdminRole::create(['name' => 'reader', 'guard_name' => 'web']);
    $firstRole->givePermissionTo([$menu->permission_id, $view->permission_id]);
    $secondRole = AdminRole::create(['name' => 'writer', 'guard_name' => 'web']);
    $secondRole->givePermissionTo([$create->permission_id, $update->permission_id]);
    $admin->assignRole([$firstRole, $secondRole]);
    $admin->givePermissionTo([$custom->permission_id, $disabled->permission_id]);
    $this->withToken($admin->createToken('route-union')->plainTextToken);

    $response = $this->getJson('/api/v1/admin/me/menus');

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'root')
        ->assertJsonCount(1, 'data.0.children')->assertJsonPath('data.0.children.0.name', 'goods')
        ->assertJsonPath('data.0.children.0.meta.title', 'Goods')
        ->assertJsonPath('data.0.children.0.meta.icon', 'ri:box-line')
        ->assertJsonPath('data.0.children.0.meta.keepAlive', true);
    expect($response->json('data.0.component'))->not->toBe('directory');
    expect(array_column($response->json('data.0.children.0.meta.authList'), 'authMark'))
        ->toEqualCanonicalizing(['view', 'add', 'edit', 'review.bulk']);
    expect($response->json('data.0.children.0.children') ?? [])->toBe([]);
    $profile = $this->getJson('/api/v1/admin/me')->assertOk();
    expect($profile->json('data.buttons'))->toEqualCanonicalizing(['goods.view', 'goods.create', 'goods.update', 'goods.review.bulk']);
    expect($profile->json('data.roles'))->toEqualCanonicalizing(['reader', 'writer']);
    $firstRole->revokePermissionTo($view->permission_id);
    $this->app['auth']->forgetGuards();
    $profile = $this->getJson('/api/v1/admin/me')->assertOk();
    expect($profile->json('data.buttons'))->toEqualCanonicalizing(['goods.create', 'goods.update', 'goods.review.bulk']);
    $this->assertModelExists($hidden);
});

test('maps external and iframe URLs into frontend route metadata', function (): void {
    $admin = menuTestSuperAdmin();
    menuTestNode(['name' => 'external', 'open_type' => 'external', 'component' => null, 'url' => 'https://example.com/docs']);
    menuTestNode(['name' => 'embedded', 'open_type' => 'iframe', 'component' => null, 'url' => 'https://example.com/embed']);

    $response = $this->withToken($admin->createToken('remote')->plainTextToken)->getJson('/api/v1/admin/me/menus');

    $response->assertOk()->assertJsonPath('data.0.meta.link', 'https://example.com/docs')
        ->assertJsonPath('data.1.meta.link', 'https://example.com/embed')->assertJsonPath('data.1.meta.isIframe', true);
});

test('does not grant new custom buttons to existing ordinary roles', function (): void {
    $this->seed(AdminMenuSeeder::class);
    $super = menuTestSuperAdmin();
    $admin = AdminUser::factory()->create();
    $menu = menuTestNode(['name' => 'goods']);
    $role = AdminRole::create(['name' => 'existing', 'guard_name' => 'web']);
    $role->givePermissionTo($menu->permission_id);
    $admin->assignRole($role);

    $this->withToken($super->createToken('new-button')->plainTextToken)->postJson('/api/v1/admin/menus', [
        'type' => 'button', 'name' => 'goods.export', 'title' => 'Export', 'parent_id' => $menu->id,
    ])->assertSuccessful();

    expect(app(AdminMenuAccess::class)->allows($admin, 'goods.export'))->toBeFalse();
    $permission = AdminPermission::findByName('goods.export', 'web');
    $this->assertDatabaseMissing('admin_role_has_permissions', ['role_id' => $role->id, 'permission_id' => $permission->id]);
    $this->app['auth']->forgetGuards();
    $this->withToken($admin->createToken('ordinary')->plainTextToken)->getJson('/api/v1/admin/me/menus')
        ->assertOk()->assertJsonPath('data.0.meta.authList', []);
});

test('enforces custom business permission access operation and status boundaries', function (string $case, bool $allowed): void {
    $admin = $case === 'super-admin' || $case === 'disabled super-admin branch' ? menuTestSuperAdmin() : AdminUser::factory()->create();
    $root = menuTestNode(['type' => 'directory', 'name' => 'root', 'status' => ! in_array($case, ['disabled ancestor', 'disabled super-admin branch'], true)]);
    $menu = menuTestNode(['name' => 'goods', 'parent_id' => $root->id, 'status' => $case !== 'disabled menu']);
    $button = menuTestNode(['type' => 'button', 'name' => 'goods.export', 'parent_id' => $menu->id, 'status' => $case !== 'disabled button']);
    if (! in_array($case, ['operation only', 'super-admin', 'disabled super-admin branch'], true)) {
        $admin->givePermissionTo($menu->permission_id);
    }
    if (! in_array($case, ['access only', 'super-admin', 'disabled super-admin branch'], true)) {
        $admin->givePermissionTo($button->permission_id);
    }
    if ($case === 'disabled account') {
        $admin->update(['status' => false]);
    }

    expect(app(AdminMenuAccess::class)->allows($admin, 'goods.export'))->toBe($allowed);
    expect(app(AdminMenuAccess::class)->allows($admin, 'missing.permission'))->toBeFalse();
})->with([
    'access and operation' => ['both', true],
    'access only' => ['access only', false],
    'operation only' => ['operation only', false],
    'disabled button' => ['disabled button', false],
    'disabled menu' => ['disabled menu', false],
    'disabled ancestor' => ['disabled ancestor', false],
    'disabled administrator' => ['disabled account', false],
    'super-admin without grants' => ['super-admin', true],
    'super-admin disabled ancestor' => ['disabled super-admin branch', false],
]);

test('disabling and reenabling branches preserves grants and individually disabled descendants', function (): void {
    $this->seed(AdminMenuSeeder::class);
    $admin = menuTestSuperAdmin();
    $root = menuTestNode(['type' => 'directory', 'name' => 'root']);
    $menu = menuTestNode(['name' => 'goods', 'parent_id' => $root->id]);
    $enabled = menuTestNode(['type' => 'button', 'name' => 'goods.view', 'parent_id' => $menu->id]);
    $disabled = menuTestNode(['type' => 'button', 'name' => 'goods.delete', 'parent_id' => $menu->id, 'status' => false]);
    $ordinary = AdminUser::factory()->create();
    $ordinary->givePermissionTo([$menu->permission_id, $enabled->permission_id, $disabled->permission_id]);
    $this->withToken($admin->createToken('toggle')->plainTextToken);

    $this->patchJson('/api/v1/admin/menus/'.$root->id, ['status' => false])->assertOk();

    expect(app(AdminMenuAccess::class)->allows($admin, 'goods.view'))->toBeFalse();
    expect(app(AdminMenuAccess::class)->allows($ordinary, 'goods.view'))->toBeFalse();
    expect($menu->fresh()->status)->toBeTrue();
    expect($enabled->fresh()->status)->toBeTrue();
    $this->patchJson('/api/v1/admin/menus/'.$root->id, ['status' => true])->assertOk();
    expect(app(AdminMenuAccess::class)->allows($ordinary, 'goods.view'))->toBeTrue();
    expect(app(AdminMenuAccess::class)->allows($admin, 'goods.delete'))->toBeFalse();
    expect($disabled->fresh()->status)->toBeFalse();
    expect($ordinary->fresh()->getDirectPermissions()->count())->toBe(3);
});

test('super-admin cannot bypass disabled menu management nodes or their ancestors', function (string $disabled): void {
    $this->seed(AdminMenuSeeder::class);
    $admin = menuTestSuperAdmin();
    $management = AdminMenu::query()->where('name', 'system.menu')->firstOrFail();
    if ($disabled === 'ancestor') {
        $root = menuTestNode(['type' => 'directory', 'name' => 'root', 'status' => false]);
        $management->update(['parent_id' => $root->id]);
    } elseif ($disabled === 'menu') {
        $management->update(['status' => false]);
    } else {
        AdminMenu::query()->where('name', 'system.menu.view')->firstOrFail()->update(['status' => false]);
    }
    $this->withToken($admin->createToken('disabled-branch')->plainTextToken);

    $this->getJson('/api/v1/admin/menus')->assertForbidden()->assertJsonPath('code', 403);
    $this->getJson('/api/v1/admin/me')->assertOk();
})->with(['ancestor', 'menu', 'button']);

test('requires explicit deletion confirmation and preserves the leaf on 422', function (array $payload): void {
    $this->seed(AdminMenuSeeder::class);
    $admin = menuTestSuperAdmin();
    $menu = menuTestNode(['name' => 'goods']);

    $this->withToken($admin->createToken('confirm')->plainTextToken)->deleteJson('/api/v1/admin/menus/'.$menu->id, $payload)
        ->assertUnprocessable()->assertJsonValidationErrors(['confirmed']);

    $this->assertModelExists($menu);
    $this->assertDatabaseHas('admin_permissions', ['id' => $menu->permission_id]);
})->with([
    'confirmation absent' => [[]],
    'confirmation declined' => [['confirmed' => false]],
]);

test('previews distinct role and directly authorized user counts across models then removes the leaf and all grants', function (): void {
    $this->seed(AdminMenuSeeder::class);
    $admin = menuTestSuperAdmin();
    $menu = menuTestNode(['name' => 'goods']);
    $button = menuTestNode(['type' => 'button', 'name' => 'goods.export', 'parent_id' => $menu->id]);
    $first = AdminRole::create(['name' => 'first', 'guard_name' => 'web']);
    $second = AdminRole::create(['name' => 'second', 'guard_name' => 'web']);
    $first->givePermissionTo([$menu->permission_id, $button->permission_id]);
    $second->givePermissionTo($button->permission_id);
    $direct = AdminUser::factory()->create();
    $direct->assignRole([$first, $second]);
    $direct->givePermissionTo([$menu->permission_id, $button->permission_id]);
    $ordinaryDirect = User::factory()->create(['id' => $direct->id]);
    $ordinaryDirect->givePermissionTo($button->permission_id);
    $permissionId = $button->permission_id;
    $this->withToken($admin->createToken('delete')->plainTextToken);

    $this->getJson('/api/v1/admin/menus/'.$button->id.'/deletion-impact')->assertOk()->assertJsonPath('data', [
        'children_count' => 0, 'roles_count' => 2, 'direct_users_count' => 2, 'can_delete' => true,
    ]);
    $this->deleteJson('/api/v1/admin/menus/'.$button->id, ['confirmed' => true])
        ->assertOk()->assertExactJson(['code' => 200, 'msg' => '操作成功', 'data' => null]);

    $this->assertModelMissing($button);
    $this->assertDatabaseMissing('admin_permissions', ['id' => $permissionId]);
    $this->assertDatabaseMissing('admin_role_has_permissions', ['permission_id' => $permissionId]);
    $this->assertDatabaseMissing('admin_model_has_permissions', ['permission_id' => $permissionId]);
    $this->assertDatabaseHas('admin_role_has_permissions', ['role_id' => $first->id, 'permission_id' => $menu->permission_id]);
    $this->assertDatabaseHas('admin_model_has_permissions', ['model_id' => $direct->id, 'permission_id' => $menu->permission_id]);
    $this->postJson('/api/v1/admin/menus', [
        'type' => 'button', 'name' => 'goods.export', 'title' => 'New export', 'parent_id' => $menu->id,
    ])->assertSuccessful();
    $replacement = AdminMenu::query()->where('name', 'goods.export')->firstOrFail();
    expect($replacement->permission_id)->not->toBe($permissionId);
    expect(app(AdminMenuAccess::class)->allows($direct, 'goods.export'))->toBeFalse();
    $this->assertDatabaseMissing('admin_role_has_permissions', ['permission_id' => $replacement->permission_id]);
    $this->assertDatabaseMissing('admin_model_has_permissions', ['permission_id' => $replacement->permission_id]);
});

test('returns 409 instead of cascading a node that has children', function (): void {
    $this->seed(AdminMenuSeeder::class);
    $admin = menuTestSuperAdmin();
    $root = menuTestNode(['type' => 'directory', 'name' => 'root']);
    $menu = menuTestNode(['name' => 'goods', 'parent_id' => $root->id]);
    $this->withToken($admin->createToken('has-children')->plainTextToken);

    $this->getJson('/api/v1/admin/menus/'.$root->id.'/deletion-impact')->assertOk()->assertJsonPath('data', [
        'children_count' => 1, 'roles_count' => 0, 'direct_users_count' => 0, 'can_delete' => false,
    ]);
    $this->deleteJson('/api/v1/admin/menus/'.$root->id, ['confirmed' => true])->assertConflict()->assertJsonPath('code', 409);

    $this->assertModelExists($root);
    $this->assertModelExists($menu);
    $this->assertDatabaseHas('admin_permissions', ['id' => $menu->permission_id]);
});

test('rechecks children at deletion time even after an earlier preview allowed deletion', function (): void {
    $this->seed(AdminMenuSeeder::class);
    $admin = menuTestSuperAdmin();
    $menu = menuTestNode(['name' => 'goods']);
    $this->withToken($admin->createToken('stale-preview')->plainTextToken);
    $this->getJson('/api/v1/admin/menus/'.$menu->id.'/deletion-impact')->assertOk()->assertJsonPath('data.can_delete', true);
    $button = menuTestNode(['type' => 'button', 'name' => 'goods.view', 'parent_id' => $menu->id]);

    $this->deleteJson('/api/v1/admin/menus/'.$menu->id, ['confirmed' => true])->assertConflict();

    $this->assertModelExists($menu);
    $this->assertModelExists($button);
});

test('deletes an empty directory without altering unrelated permissions', function (): void {
    $this->seed(AdminMenuSeeder::class);
    $admin = menuTestSuperAdmin();
    $root = menuTestNode(['type' => 'directory', 'name' => 'root']);

    $this->withToken($admin->createToken('directory-delete')->plainTextToken)->deleteJson('/api/v1/admin/menus/'.$root->id, ['confirmed' => true])
        ->assertOk()->assertJsonPath('data', null);

    $this->assertModelMissing($root);
    $this->assertDatabaseCount('admin_menus', 5);
    $this->assertDatabaseCount('admin_permissions', 5);
});

test('rolls back node permission role grants and direct grants when deletion fails midway', function (): void {
    $this->seed(AdminMenuSeeder::class);
    $admin = menuTestSuperAdmin();
    $menu = menuTestNode(['name' => 'goods']);
    $button = menuTestNode(['type' => 'button', 'name' => 'goods.export', 'parent_id' => $menu->id]);
    $role = AdminRole::create(['name' => 'exporter', 'guard_name' => 'web']);
    $role->givePermissionTo($button->permission_id);
    $direct = AdminUser::factory()->create();
    $direct->givePermissionTo($button->permission_id);
    $dispatcher = AdminMenu::getEventDispatcher();
    $temporary = clone $dispatcher;
    $temporary->listen('eloquent.deleted: '.AdminPermission::class, function (AdminPermission $permission) use ($button): void {
        if ($permission->id === $button->permission_id) {
            throw new RuntimeException('Forced mid-delete failure');
        }
    });
    AdminMenu::setEventDispatcher($temporary);

    try {
        $this->withToken($admin->createToken('rollback-delete')->plainTextToken)
            ->deleteJson('/api/v1/admin/menus/'.$button->id, ['confirmed' => true])->assertServerError();
    } finally {
        AdminMenu::setEventDispatcher($dispatcher);
    }

    $this->assertModelExists($button);
    $this->assertDatabaseHas('admin_permissions', ['id' => $button->permission_id, 'name' => 'goods.export']);
    $this->assertDatabaseHas('admin_role_has_permissions', ['role_id' => $role->id, 'permission_id' => $button->permission_id]);
    $this->assertDatabaseHas('admin_model_has_permissions', [
        'model_id' => $direct->id, 'model_type' => $direct->getMorphClass(), 'permission_id' => $button->permission_id,
    ]);
});

test('returns 422 when a button has no parent menu', function (): void {
    $this->seed(AdminMenuSeeder::class);
    $admin = menuTestSuperAdmin();

    $this->withToken($admin->createToken('root-button')->plainTextToken)->postJson('/api/v1/admin/menus', [
        'type' => 'button', 'name' => 'goods.export', 'title' => 'Export',
    ])->assertUnprocessable()->assertJsonValidationErrors(['parent_id']);

    $this->assertDatabaseCount('admin_menus', 5);
    $this->assertDatabaseCount('admin_permissions', 5);
});

test('returns 422 when a custom button name leaves its menu prefix or uses the reserved access name', function (string $name): void {
    $this->seed(AdminMenuSeeder::class);
    $admin = menuTestSuperAdmin();
    $menu = menuTestNode(['name' => 'goods']);

    $this->withToken($admin->createToken('button-prefix')->plainTextToken)->postJson('/api/v1/admin/menus', [
        'type' => 'button', 'parent_id' => $menu->id, 'name' => $name, 'title' => 'Invalid',
    ])->assertUnprocessable()->assertJsonValidationErrors(['name']);

    $this->assertDatabaseCount('admin_menus', 6);
    $this->assertDatabaseCount('admin_permissions', 6);
})->with(['other.export', 'goods.access']);

test('returns 422 when a generated permission collides with an existing unbound permission', function (): void {
    $this->seed(AdminMenuSeeder::class);
    $admin = menuTestSuperAdmin();
    $permission = AdminPermission::create(['name' => 'goods.view', 'guard_name' => 'web']);

    $this->withToken($admin->createToken('permission-collision')->plainTextToken)->postJson('/api/v1/admin/menus', [
        'type' => 'menu', 'name' => 'goods', 'title' => 'Goods', 'open_type' => 'page',
        'path' => '/goods', 'component' => '/goods/index',
    ])->assertUnprocessable()->assertJsonValidationErrors(['name']);

    $this->assertDatabaseCount('admin_menus', 5);
    $this->assertDatabaseCount('admin_permissions', 6);
    $this->assertModelExists($permission);
    $this->assertDatabaseMissing('admin_permissions', ['name' => 'goods.access']);
});

test('super-admin receives newly created enabled operations using an existing bearer token', function (): void {
    $this->seed(AdminMenuSeeder::class);
    $admin = menuTestSuperAdmin();
    $menu = menuTestNode(['name' => 'goods']);
    $token = $admin->createToken('before-operation')->plainTextToken;
    $this->withToken($token)->getJson('/api/v1/admin/me')->assertOk();

    $this->postJson('/api/v1/admin/menus', [
        'type' => 'button', 'name' => 'goods.export', 'title' => 'Export', 'parent_id' => $menu->id,
    ])->assertSuccessful();

    $profile = $this->getJson('/api/v1/admin/me')->assertOk();
    expect($profile->json('data.buttons'))->toContain('goods.export')->not->toContain('*', 'goods.access');
    expect($admin->fresh()->getAllPermissions()->all())->toBe([]);
});
