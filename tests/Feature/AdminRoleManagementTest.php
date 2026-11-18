<?php

use App\Models\AdminMenu;
use App\Models\AdminPermission;
use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\User;
use Database\Seeders\AdminManagementMenuSeeder;
use Database\Seeders\AdminMenuSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Events\PermissionAttachedEvent;

uses(LazilyRefreshDatabase::class);

function roleManagementSuperAdmin(): AdminUser
{
    $admin = AdminUser::factory()->create();
    $admin->assignRole(AdminRole::findOrCreate('super-admin', 'web'));

    return $admin;
}

function roleManagementRole(string $name, ?AdminRole $parent = null, array $permissions = []): AdminRole
{
    $role = AdminRole::create([
        'name' => $name, 'display_name' => $name, 'guard_name' => 'web',
        'parent_id' => $parent?->id, 'status' => true,
    ]);
    $role->syncPermissions($permissions);

    return $role;
}

function roleManagementPermissionIds(array $names): array
{
    return AdminPermission::whereIn('name', $names)->where('guard_name', 'web')->pluck('id')->all();
}

function roleManagementFlatten(array $nodes): array
{
    $flat = [];
    foreach ($nodes as $node) {
        $flat[] = $node;
        $flat = array_merge($flat, roleManagementFlatten($node['children'] ?? []));
    }

    return $flat;
}

test('creates reads updates and deletes a real hierarchical role with persisted permissions', function (): void {
    $this->travelTo('2026-11-18 02:41:19');
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $admin = roleManagementSuperAdmin();
    $parent = roleManagementRole('operations', null, roleManagementPermissionIds(['system.menu.access', 'system.menu.view']));
    $ids = roleManagementPermissionIds(['system.menu.access', 'system.menu.view']);
    $this->withToken($admin->createToken('role-crud')->plainTextToken);

    $created = $this->postJson('/api/v1/admin/roles', [
        'name' => 'operations.readers', 'display_name' => 'Readers', 'parent_id' => $parent->id,
        'status' => true, 'remark' => 'Read operations', 'permission_ids' => $ids,
    ])->assertCreated()->assertJsonPath('code', 200)->assertJsonPath('data.display_name', 'Readers')
        ->assertJsonPath('data.created_at', '2026-11-18 02:41:19')
        ->assertJsonPath('data.updated_at', '2026-11-18 02:41:19')
        ->assertJsonPath('data.parent_id', $parent->id)->assertJsonPath('data.can_manage', true);
    $role = AdminRole::findOrFail($created->json('data.id'));
    expect($role->permissions()->pluck('id')->all())->toEqualCanonicalizing($ids);
    $this->getJson('/api/v1/admin/roles/'.$role->id)->assertOk()->assertJsonPath('data.remark', 'Read operations');
    $tree = roleManagementFlatten($this->getJson('/api/v1/admin/roles')->assertOk()->json('data'));
    expect(array_column($tree, 'id'))->toContain($parent->id, $role->id);

    $this->patchJson('/api/v1/admin/roles/'.$role->id, ['display_name' => 'Auditors', 'remark' => null])
        ->assertOk()->assertJsonPath('data.display_name', 'Auditors')->assertJsonPath('data.remark', null);
    expect($role->fresh()->display_name)->toBe('Auditors');
    expect($role->fresh()->name)->toBe('operations.readers');
    $this->deleteJson('/api/v1/admin/roles/'.$role->id, ['confirmed' => true])->assertOk();
    $this->assertModelMissing($role);
    $this->assertDatabaseMissing('admin_role_has_permissions', ['role_id' => $role->id]);
    $this->assertModelExists($parent);
});

test('requires both role page access and the operation permission', function (string $method, string $suffix, array $payload, string $missing): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $admin = AdminUser::factory()->create();
    $admin->givePermissionTo($missing === 'action' ? 'system.role.access' : 'system.role.view');
    $role = roleManagementRole('unchanged');

    $this->withToken($admin->createToken('role-denied')->plainTextToken)
        ->json($method, '/api/v1/admin/roles'.str_replace('{id}', (string) $role->id, $suffix), $payload)
        ->assertForbidden()->assertJsonPath('code', 403);

    $this->assertModelExists($role);
    expect($role->fresh()->display_name)->toBe('unchanged');
    $this->assertDatabaseMissing('admin_roles', ['name' => 'unauthorized']);
})->with([
    'list action' => ['GET', '', [], 'action'],
    'detail action' => ['GET', '/{id}', [], 'action'],
    'permission tree action' => ['GET', '/permission-tree', [], 'action'],
    'create action' => ['POST', '', ['name' => 'unauthorized', 'display_name' => 'Unauthorized', 'permission_ids' => []], 'action'],
    'update action' => ['PATCH', '/{id}', ['display_name' => 'Changed'], 'action'],
    'delete action' => ['DELETE', '/{id}', ['confirmed' => true], 'action'],
    'page access' => ['GET', '', [], 'access'],
]);

test('returns 401 on role management without an administrator bearer token', function (): void {
    $this->getJson('/api/v1/admin/roles')->assertUnauthorized();
});

test('shows own groups as read only and allows managing only strict descendants', function (): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $permissions = AdminPermission::pluck('id')->all();
    $ancestor = roleManagementRole('ancestor', null, $permissions);
    $own = roleManagementRole('own', $ancestor, $permissions);
    $child = roleManagementRole('child', $own, $permissions);
    $peer = roleManagementRole('peer', $ancestor, $permissions);
    $admin = AdminUser::factory()->create();
    $admin->assignRole($own);
    $this->withToken($admin->createToken('hierarchy')->plainTextToken);

    $roles = collect(roleManagementFlatten($this->getJson('/api/v1/admin/roles')->assertOk()->json('data')))->keyBy('id');

    expect($roles->keys()->all())->toEqualCanonicalizing([$own->id, $child->id]);
    expect($roles[$own->id]['can_manage'])->toBeFalse();
    expect($roles[$child->id]['can_manage'])->toBeTrue();
    $options = collect($this->getJson('/api/v1/admin/roles/options')->assertOk()->json('data'))->keyBy('id');
    expect($options[$own->id]['can_assign'])->toBeFalse();
    expect($options[$child->id]['can_assign'])->toBeTrue();
    expect($options->keys()->all())->not->toContain($ancestor->id, $peer->id);
    $this->postJson('/api/v1/admin/roles', [
        'name' => 'new-child', 'display_name' => 'New child', 'parent_id' => $own->id,
        'permission_ids' => roleManagementPermissionIds(['system.menu.access', 'system.menu.view']),
    ])->assertCreated()->assertJsonPath('data.parent_id', $own->id);
    $this->patchJson('/api/v1/admin/roles/'.$child->id, ['display_name' => 'Managed child'])->assertOk();
    expect($child->fresh()->display_name)->toBe('Managed child');
});

test('returns 403 for own peer and ancestor role mutations', function (string $target, string $method): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $permissions = AdminPermission::pluck('id')->all();
    $ancestor = roleManagementRole('ancestor', null, $permissions);
    $own = roleManagementRole('own', $ancestor, $permissions);
    $peer = roleManagementRole('peer', $ancestor, $permissions);
    $admin = AdminUser::factory()->create();
    $admin->assignRole($own);
    $role = ['own' => $own, 'peer' => $peer, 'ancestor' => $ancestor][$target];

    $this->withToken($admin->createToken('scope-denied')->plainTextToken)
        ->json($method, '/api/v1/admin/roles/'.$role->id, ['display_name' => 'Escalated', 'confirmed' => true])
        ->assertForbidden();

    $this->assertModelExists($role);
    expect($role->fresh()->display_name)->toBe($target);
})->with(['own', 'peer', 'ancestor'])->with(['PATCH', 'DELETE']);

test('returns 422 for role cycles and immutable identity fields', function (string $change): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $admin = roleManagementSuperAdmin();
    $parent = roleManagementRole('parent');
    $child = roleManagementRole('child', $parent);
    $payload = match ($change) {
        'self' => ['parent_id' => $parent->id],
        'descendant' => ['parent_id' => $child->id],
        'name' => ['name' => 'parent'],
        'guard_name' => ['guard_name' => 'web'],
    };
    $field = array_key_first($payload);

    $this->withToken($admin->createToken('invalid-role')->plainTextToken)
        ->patchJson('/api/v1/admin/roles/'.$parent->id, $payload)->assertUnprocessable()->assertJsonValidationErrors([$field]);

    expect($parent->fresh()->parent_id)->toBeNull();
    expect($parent->fresh()->name)->toBe('parent');
})->with(['self', 'descendant', 'name', 'guard_name']);

test('returns 422 when permission allocation exceeds the selected parent or uses an unbound permission', function (string $allocation): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $admin = roleManagementSuperAdmin();
    $parent = roleManagementRole('limited', null, roleManagementPermissionIds(['system.menu.access', 'system.menu.view']));
    $unbound = AdminPermission::create(['name' => 'unbound', 'guard_name' => 'web']);
    $ids = $allocation === 'unbound' ? [$unbound->id] : roleManagementPermissionIds(['system.menu.delete']);

    $this->withToken($admin->createToken('bounds')->plainTextToken)->postJson('/api/v1/admin/roles', [
        'name' => 'escalated', 'display_name' => 'Escalated', 'parent_id' => $parent->id, 'permission_ids' => $ids,
    ])->assertUnprocessable()->assertJsonValidationErrors(['permission_ids']);

    $this->assertDatabaseMissing('admin_roles', ['name' => 'escalated']);
})->with(['parent capacity', 'unbound']);

test('normalizes button access and prunes every descendant when a parent allocation shrinks', function (): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $admin = roleManagementSuperAdmin();
    $all = roleManagementPermissionIds(['system.menu.access', 'system.menu.view', 'system.menu.update']);
    $parent = roleManagementRole('parent', null, $all);
    $child = roleManagementRole('child', $parent, $all);
    $grandchild = roleManagementRole('grandchild', $child, $all);
    $this->withToken($admin->createToken('normalization')->plainTextToken);
    $view = AdminPermission::findByName('system.menu.view', 'web');
    $access = AdminPermission::findByName('system.menu.access', 'web');

    $created = $this->postJson('/api/v1/admin/roles', [
        'name' => 'button-only', 'display_name' => 'Button only', 'parent_id' => $parent->id, 'permission_ids' => [$view->id],
    ])->assertCreated();
    expect($created->json('data.permission_ids'))->toEqualCanonicalizing([$access->id, $view->id]);

    $this->patchJson('/api/v1/admin/roles/'.$parent->id, ['permission_ids' => [$access->id, $view->id]])->assertOk();
    foreach ([$parent, $child, $grandchild] as $role) {
        expect($role->permissions()->pluck('id')->all())->toEqualCanonicalizing([$access->id, $view->id]);
    }
    $this->patchJson('/api/v1/admin/roles/'.$parent->id, ['permission_ids' => $all])->assertOk();
    expect($grandchild->permissions()->pluck('id')->all())->toEqualCanonicalizing([$access->id, $view->id]);
    $this->patchJson('/api/v1/admin/roles/'.$parent->id, ['permission_ids' => [$view->id]])
        ->assertOk()->assertJsonPath('data.permission_ids', []);
    expect($child->permissions()->count())->toBe(0);
    expect($grandchild->permissions()->count())->toBe(0);
});

test('returns assignable permission identifiers and access dependencies rather than directory IDs', function (): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $permissions = roleManagementPermissionIds(['system.role.access', 'system.role.view', 'system.menu.access', 'system.menu.view']);
    $own = roleManagementRole('reader', null, $permissions);
    $admin = AdminUser::factory()->create();
    $admin->assignRole($own);
    $access = AdminPermission::findByName('system.menu.access', 'web');
    $view = AdminPermission::findByName('system.menu.view', 'web');

    $response = $this->withToken($admin->createToken('permission-tree')->plainTextToken)
        ->getJson('/api/v1/admin/roles/permission-tree')->assertOk();

    $nodes = collect(roleManagementFlatten($response->json('data')));
    $button = $nodes->firstWhere('permission_id', $view->id);
    expect($button['key'])->toBe('permission:'.$view->id);
    expect($button['permission_name'])->toBe('system.menu.view');
    expect($button['depends_on'])->toBe([$access->id]);
    expect($button['assignable'])->toBeTrue();
    expect($nodes->where('assignable', true)->pluck('permission_id')->filter()->values()->all())
        ->toEqualCanonicalizing($permissions);
    expect($nodes->pluck('permission_name')->all())->not->toContain('system.menu.delete');
});

test('reflects permission edits and disabled ancestors immediately using existing bearer tokens', function (): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $super = roleManagementSuperAdmin();
    $ids = roleManagementPermissionIds(['system.menu.access', 'system.menu.view', 'system.menu.update']);
    $parent = roleManagementRole('parent', null, $ids);
    $child = roleManagementRole('child', $parent, $ids);
    $reader = AdminUser::factory()->create();
    $reader->assignRole($child);
    $readerToken = $reader->createToken('live-role')->plainTextToken;
    $superToken = $super->createToken('edit-role')->plainTextToken;
    $this->withToken($readerToken)->getJson('/api/v1/admin/me')->assertOk()
        ->assertJsonPath('data.buttons', ['system.menu.view', 'system.menu.update']);

    $this->withToken($superToken)->patchJson('/api/v1/admin/roles/'.$child->id, [
        'permission_ids' => roleManagementPermissionIds(['system.menu.access', 'system.menu.view']),
    ])->assertOk();
    $this->app['auth']->forgetGuards();
    $profile = $this->withToken($readerToken)->getJson('/api/v1/admin/me')->assertOk();
    expect($profile->json('data.buttons'))->toBe(['system.menu.view']);
    $this->withToken($superToken)->patchJson('/api/v1/admin/roles/'.$parent->id, ['status' => false])->assertOk();
    $this->app['auth']->forgetGuards();
    $this->withToken($readerToken)->getJson('/api/v1/admin/me')->assertOk()->assertJsonPath('data.buttons', []);
    $this->getJson('/api/v1/admin/me/menus')->assertOk()->assertJsonPath('data', []);
    $this->withToken($superToken)->getJson('/api/v1/admin/roles/'.$child->id)->assertOk()
        ->assertJsonPath('data.effective_status', false);
    $this->patchJson('/api/v1/admin/roles/'.$parent->id, ['status' => true])->assertOk();
    $this->app['auth']->forgetGuards();
    $this->withToken($readerToken)->getJson('/api/v1/admin/me')->assertOk()->assertJsonPath('data.buttons', ['system.menu.view']);
    expect($child->permissions()->count())->toBe(2);
});

test('returns 409 for reserved super group updates and deletion', function (string $method): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $admin = roleManagementSuperAdmin();
    $role = AdminRole::findByName('super-admin', 'web');

    $this->withToken($admin->createToken('reserved')->plainTextToken)
        ->json($method, '/api/v1/admin/roles/'.$role->id, ['display_name' => 'Changed', 'status' => false, 'confirmed' => true])
        ->assertConflict();

    $this->assertModelExists($role);
    expect($admin->fresh()->hasRole('super-admin'))->toBeTrue();
})->with(['PATCH', 'DELETE']);

test('returns 409 when deleting a role that has children or assigned administrators', function (string $dependency): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $admin = roleManagementSuperAdmin();
    $role = roleManagementRole('occupied');
    if ($dependency === 'child') {
        roleManagementRole('child', $role);
    } else {
        AdminUser::factory()->create()->assignRole($role);
    }

    $this->withToken($admin->createToken('occupied')->plainTextToken)
        ->deleteJson('/api/v1/admin/roles/'.$role->id, ['confirmed' => true])->assertConflict();

    $this->assertModelExists($role);
})->with(['child', 'administrator']);

test('requires explicit confirmation before role deletion', function (): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $admin = roleManagementSuperAdmin();
    $role = roleManagementRole('protected');

    $this->withToken($admin->createToken('confirm')->plainTextToken)
        ->deleteJson('/api/v1/admin/roles/'.$role->id)->assertUnprocessable()->assertJsonValidationErrors(['confirmed']);

    $this->assertModelExists($role);
});

test('rolls back role deletion and its permission pivots when a model event fails', function (): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $admin = roleManagementSuperAdmin();
    $ids = roleManagementPermissionIds(['system.menu.access', 'system.menu.view']);
    $role = roleManagementRole('rollback-role', null, $ids);
    $dispatcher = AdminRole::getEventDispatcher();
    $temporary = clone $dispatcher;
    $temporary->listen('eloquent.deleted: '.AdminRole::class, function (AdminRole $deleted) use ($role): void {
        if ($deleted->id === $role->id) {
            throw new RuntimeException('Forced role deletion failure');
        }
    });
    AdminRole::setEventDispatcher($temporary);

    try {
        $this->withToken($admin->createToken('rollback-role')->plainTextToken)
            ->deleteJson('/api/v1/admin/roles/'.$role->id, ['confirmed' => true])->assertServerError();
    } finally {
        AdminRole::setEventDispatcher($dispatcher);
    }

    $this->assertModelExists($role);
    expect($role->permissions()->pluck('id')->all())->toEqualCanonicalizing($ids);
});

test('keeps directory grouping and disabled menu ancestry in the super permission tree', function (): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $actor = roleManagementSuperAdmin();
    $directory = AdminMenu::create([
        'type' => 'directory', 'name' => 'grouping', 'title' => 'Grouping', 'path' => '/grouping', 'status' => false,
    ]);
    $page = AdminMenu::where('name', 'system.menu')->firstOrFail();
    $page->update(['parent_id' => $directory->id]);

    $nodes = collect(roleManagementFlatten($this->withToken($actor->createToken('grouping')->plainTextToken)
        ->getJson('/api/v1/admin/roles/permission-tree')->assertOk()->json('data')))->keyBy('id');

    expect($nodes[$directory->id]['key'])->toBe('menu:'.$directory->id);
    expect($nodes[$directory->id]['permission_id'])->toBeNull();
    expect($nodes[$directory->id]['assignable'])->toBeFalse();
    expect($nodes[$page->id]['status'])->toBeTrue();
    expect($nodes[$page->id]['effective_status'])->toBeFalse();
});

test('preserves stored allocations for disabled roles while removing their live menu authority', function (): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $actor = roleManagementSuperAdmin();
    $ids = roleManagementPermissionIds(['system.menu.access', 'system.menu.view']);
    $role = roleManagementRole('disabled-reader', null, $ids);
    $reader = AdminUser::factory()->create();
    $reader->assignRole($role);
    $readerToken = $reader->createToken('disabled-role')->plainTextToken;

    $response = $this->withToken($actor->createToken('disable-role')->plainTextToken)
        ->patchJson('/api/v1/admin/roles/'.$role->id, ['status' => false])->assertOk()
        ->assertJsonPath('data.status', false)->assertJsonPath('data.effective_status', false);

    expect($response->json('data.permission_ids'))->toEqualCanonicalizing($ids);
    expect($role->permissions()->pluck('id')->all())->toEqualCanonicalizing($ids);
    $this->app['auth']->forgetGuards();
    $this->withToken($readerToken)->getJson('/api/v1/admin/me')->assertOk()->assertJsonPath('data.buttons', []);
    $this->getJson('/api/v1/admin/me/menus')->assertOk()->assertJsonPath('data', []);
    $this->getJson('/api/v1/admin/menus')->assertForbidden();
});

test('a disabled reserved super role no longer bypasses operation permissions', function (): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $actor = roleManagementSuperAdmin();
    $token = $actor->createToken('disabled-super-role')->plainTextToken;
    AdminRole::findByName('super-admin', 'web')->update(['status' => false]);

    $this->withToken($token)->getJson('/api/v1/admin/roles')->assertForbidden();
    $this->getJson('/api/v1/admin/admins')->assertForbidden();
    $this->getJson('/api/v1/admin/me')->assertOk()->assertJsonPath('data.buttons', []);
});

test('rolls back parent edits and all descendant permission pruning when attaching a descendant grant fails', function (): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $actor = roleManagementSuperAdmin();
    $ids = roleManagementPermissionIds(['system.menu.access', 'system.menu.view', 'system.menu.update']);
    $parent = roleManagementRole('rollback-parent', null, $ids);
    $child = roleManagementRole('rollback-child', $parent, $ids);
    $grandchild = roleManagementRole('rollback-grandchild', $child, $ids);
    config(['permission.events_enabled' => true]);
    $dispatcher = Event::getFacadeRoot();
    $temporary = clone $dispatcher;
    $temporary->listen(PermissionAttachedEvent::class, function (PermissionAttachedEvent $event) use ($grandchild): void {
        if ($event->model instanceof AdminRole && $event->model->id === $grandchild->id) {
            throw new RuntimeException('Forced descendant pruning failure');
        }
    });
    Event::swap($temporary);

    try {
        $this->withToken($actor->createToken('rollback-pruning')->plainTextToken)
            ->patchJson('/api/v1/admin/roles/'.$parent->id, [
                'display_name' => 'Should roll back',
                'permission_ids' => roleManagementPermissionIds(['system.menu.access', 'system.menu.view']),
            ])->assertServerError();
    } finally {
        Event::swap($dispatcher);
    }

    expect($parent->fresh()->display_name)->toBe('rollback-parent');
    foreach ([$parent, $child, $grandchild] as $role) {
        expect($role->permissions()->pluck('id')->all())->toEqualCanonicalizing($ids);
    }
});

test('returns 422 for invalid role creation and reserved role codes', function (array $changes, array $fields): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $actor = roleManagementSuperAdmin();
    $count = AdminRole::count();

    $this->withToken($actor->createToken('invalid-role-create')->plainTextToken)
        ->postJson('/api/v1/admin/roles', array_replace([
            'name' => 'new-role', 'display_name' => 'New role', 'permission_ids' => [],
        ], $changes))->assertUnprocessable()->assertJsonValidationErrors($fields);

    $this->assertDatabaseCount('admin_roles', $count);
    $this->assertDatabaseMissing('admin_roles', ['name' => 'new-role']);
})->with([
    'reserved super code' => [['name' => 'super-admin'], ['name']],
    'missing code' => [['name' => ''], ['name']],
    'invalid code' => [['name' => 'bad/code'], ['name']],
    'missing display name' => [['display_name' => ''], ['display_name']],
    'missing parent' => [['parent_id' => 999999], ['parent_id']],
    'missing permission' => [['permission_ids' => [999999]], ['permission_ids.0']],
    'guard injection' => [['guard_name' => 'web'], ['guard_name']],
]);

test('allows role options through either authorized management view', function (string $page): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $actor = AdminUser::factory()->create();
    $actor->givePermissionTo([$page.'.access', $page.'.view']);

    $this->withToken($actor->createToken('role-options')->plainTextToken)
        ->getJson('/api/v1/admin/roles/options')->assertOk()->assertJsonPath('data', []);
})->with(['system.admin', 'system.role']);

test('returns 403 for role options when only an operation permission is granted', function (): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $actor = AdminUser::factory()->create();
    $actor->givePermissionTo(['system.admin.view', 'system.role.view']);

    $this->withToken($actor->createToken('options-missing-access')->plainTextToken)
        ->getJson('/api/v1/admin/roles/options')->assertForbidden();
});

test('moves a role subtree to an allowed parent with explicitly reduced allocations', function (): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $actor = roleManagementSuperAdmin();
    $all = roleManagementPermissionIds(['system.menu.access', 'system.menu.view', 'system.menu.update']);
    $limited = roleManagementPermissionIds(['system.menu.access', 'system.menu.view']);
    $oldParent = roleManagementRole('old-parent', null, $all);
    $newParent = roleManagementRole('new-parent', null, $limited);
    $role = roleManagementRole('moving', $oldParent, $all);
    $child = roleManagementRole('moving-child', $role, $all);

    $this->withToken($actor->createToken('move-role')->plainTextToken)
        ->patchJson('/api/v1/admin/roles/'.$role->id, ['parent_id' => $newParent->id, 'permission_ids' => $limited])
        ->assertOk()->assertJsonPath('data.parent_id', $newParent->id);

    expect($role->fresh()->parent_id)->toBe($newParent->id);
    expect($child->fresh()->parent_id)->toBe($role->id);
    expect($child->permissions()->pluck('id')->all())->toEqualCanonicalizing($limited);
});

test('creates a valid role with an explicitly empty permission allocation', function (): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $actor = roleManagementSuperAdmin();

    $response = $this->withToken($actor->createToken('empty-role')->plainTextToken)
        ->postJson('/api/v1/admin/roles', ['name' => 'empty-role', 'display_name' => 'Empty role', 'permission_ids' => []])
        ->assertCreated()->assertJsonPath('data.permission_ids', [])->assertJsonPath('data.permissions_count', 0);

    $role = AdminRole::findOrFail($response->json('data.id'));
    expect($role->permissions()->count())->toBe(0);
});

test('counts only administrator members but protects an ordinary user role assignment from deletion', function (): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $actor = roleManagementSuperAdmin();
    $role = roleManagementRole('ordinary-user-membership');
    $user = User::factory()->create(['id' => $actor->id]);
    $user->assignRole($role);
    $this->withToken($actor->createToken('shared-membership')->plainTextToken);

    $this->getJson('/api/v1/admin/roles/'.$role->id)->assertOk()->assertJsonPath('data.admins_count', 0);
    $this->deleteJson('/api/v1/admin/roles/'.$role->id, ['confirmed' => true])->assertConflict();

    $this->assertModelExists($role);
    $this->assertDatabaseHas('admin_model_has_roles', ['role_id' => $role->id, 'model_id' => $user->id, 'model_type' => $user->getMorphClass()]);
});
