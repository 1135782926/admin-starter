<?php

use App\Models\AdminPermission;
use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\User;
use Database\Seeders\AdminManagementMenuSeeder;
use Database\Seeders\AdminMenuSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Events\RoleAttachedEvent;

uses(LazilyRefreshDatabase::class);

function accountManagementSuperAdmin(): AdminUser
{
    $admin = AdminUser::factory()->create();
    $admin->assignRole(AdminRole::findOrCreate('super-admin', 'web'));

    return $admin;
}

function accountManagementRole(string $name, ?AdminRole $parent = null, array $permissions = []): AdminRole
{
    $role = AdminRole::create([
        'name' => $name, 'display_name' => $name, 'guard_name' => 'web',
        'parent_id' => $parent?->id, 'status' => true,
    ]);
    $role->syncPermissions($permissions);

    return $role;
}

function accountManagementPayload(AdminRole $role, array $changes = []): array
{
    return array_replace([
        'username' => 'new-operator', 'nickname' => 'New operator', 'password' => 'original-password',
        'email' => 'operator@example.com', 'mobile' => '+8613800000000', 'status' => true, 'role_ids' => [$role->id],
    ], $changes);
}

test('creates lists reads updates and deletes administrator accounts with real role assignments', function (): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $actor = accountManagementSuperAdmin();
    $first = accountManagementRole('reader');
    $second = accountManagementRole('writer');
    $this->withToken($actor->createToken('accounts-crud')->plainTextToken);

    $response = $this->postJson('/api/v1/admin/admins', accountManagementPayload($first))
        ->assertCreated()->assertJsonPath('code', 200)->assertJsonPath('msg', '操作成功')
        ->assertJsonPath('data.username', 'new-operator')->assertJsonPath('data.role_ids', [$first->id])
        ->assertJsonMissingPaths(['data.password', 'data.remember_token', 'data.tokens']);
    $target = AdminUser::findOrFail($response->json('data.id'));
    expect(Hash::check('original-password', $target->password))->toBeTrue();
    expect($target->roles()->pluck('id')->all())->toBe([$first->id]);
    $this->getJson('/api/v1/admin/admins/'.$target->id)->assertOk()
        ->assertJsonPath('data.email', 'operator@example.com')->assertJsonPath('data.mobile', '+8613800000000');
    $this->getJson('/api/v1/admin/admins?keyword=new-operator&status=1&per_page=1')->assertOk()
        ->assertJsonPath('data.total', 1)->assertJsonPath('data.current', 1)->assertJsonPath('data.size', 1)
        ->assertJsonPath('data.records.0.id', $target->id)->assertJsonPath('data.records.0.can_manage', true);

    $this->patchJson('/api/v1/admin/admins/'.$target->id, [
        'username' => 'renamed-operator', 'nickname' => 'Updated operator', 'email' => null, 'mobile' => null, 'role_ids' => [$second->id],
    ])->assertOk()->assertJsonPath('data.username', 'renamed-operator')->assertJsonPath('data.role_ids', [$second->id]);
    expect($target->fresh()->nickname)->toBe('Updated operator');
    expect($target->fresh()->email)->toBeNull();
    expect($target->roles()->pluck('id')->all())->toBe([$second->id]);
    $this->deleteJson('/api/v1/admin/admins/'.$target->id, ['confirmed' => true])->assertOk();
    $this->assertModelMissing($target);
    $this->assertDatabaseMissing('admin_model_has_roles', ['model_id' => $target->id, 'model_type' => $target->getMorphClass()]);
});

test('requires administrator authentication and rejects ordinary users with the same numeric identity', function (): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $admin = accountManagementSuperAdmin();
    $user = User::factory()->create(['id' => $admin->id]);

    $this->getJson('/api/v1/admin/admins')->assertUnauthorized();
    $this->withToken($user->createToken('ordinary-user')->plainTextToken)->getJson('/api/v1/admin/admins')->assertUnauthorized();
});

test('requires both administrator page access and the specific operation', function (string $method, string $suffix, array $payload, string $grant): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $actor = AdminUser::factory()->create();
    $actor->givePermissionTo($grant);
    $target = AdminUser::factory()->create(['nickname' => 'Unchanged']);

    $this->withToken($actor->createToken('missing-grant')->plainTextToken)
        ->json($method, '/api/v1/admin/admins'.str_replace('{id}', (string) $target->id, $suffix), $payload)
        ->assertForbidden()->assertJsonPath('code', 403);

    $this->assertModelExists($target);
    expect($target->fresh()->nickname)->toBe('Unchanged');
    $this->assertDatabaseMissing('admin_users', ['username' => 'unauthorized']);
})->with([
    'list operation' => ['GET', '', [], 'system.admin.access'],
    'detail operation' => ['GET', '/{id}', [], 'system.admin.access'],
    'create operation' => ['POST', '', ['username' => 'unauthorized'], 'system.admin.access'],
    'update operation' => ['PATCH', '/{id}', ['nickname' => 'Changed'], 'system.admin.access'],
    'delete operation' => ['DELETE', '/{id}', ['confirmed' => true], 'system.admin.access'],
    'page access' => ['GET', '', [], 'system.admin.view'],
]);

test('allows administrators to list create and assign only strict descendant groups', function (): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $ids = AdminPermission::pluck('id')->all();
    $own = accountManagementRole('manager', null, $ids);
    $child = accountManagementRole('operators', $own, $ids);
    $peer = accountManagementRole('peer', null, $ids);
    $actor = AdminUser::factory()->create();
    $actor->assignRole($own);
    $visible = AdminUser::factory()->create(['username' => 'visible']);
    $visible->assignRole($child);
    $hidden = AdminUser::factory()->create(['username' => 'hidden']);
    $hidden->assignRole($peer);
    $this->withToken($actor->createToken('account-scope')->plainTextToken);

    $list = $this->getJson('/api/v1/admin/admins')->assertOk()->assertJsonPath('data.total', 1);

    expect(array_column($list->json('data.records'), 'id'))->toBe([$visible->id]);
    $this->getJson('/api/v1/admin/admins/'.$visible->id)->assertOk();
    $this->getJson('/api/v1/admin/admins/'.$hidden->id)->assertNotFound();
    $created = $this->postJson('/api/v1/admin/admins', accountManagementPayload($child))->assertCreated();
    $target = AdminUser::findOrFail($created->json('data.id'));
    expect($target->roles()->pluck('id')->all())->toBe([$child->id]);
    $this->patchJson('/api/v1/admin/admins/'.$visible->id, ['nickname' => 'Managed'])->assertOk();
    expect($visible->fresh()->nickname)->toBe('Managed');
});

test('returns 403 when assigning own peer ancestor or super groups', function (string $assignment, string $method): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $ids = AdminPermission::pluck('id')->all();
    $ancestor = accountManagementRole('ancestor', null, $ids);
    $own = accountManagementRole('own', $ancestor, $ids);
    $child = accountManagementRole('child', $own, $ids);
    $peer = accountManagementRole('peer', $ancestor, $ids);
    $super = AdminRole::findOrCreate('super-admin', 'web');
    $actor = AdminUser::factory()->create();
    $actor->assignRole($own);
    $target = AdminUser::factory()->create();
    $target->assignRole($child);
    $role = ['own' => $own, 'peer' => $peer, 'ancestor' => $ancestor, 'super' => $super][$assignment];
    $path = '/api/v1/admin/admins'.($method === 'PATCH' ? '/'.$target->id : '');

    $this->withToken($actor->createToken('assignment-denied')->plainTextToken)
        ->json($method, $path, accountManagementPayload($role))->assertForbidden();

    expect($target->roles()->pluck('id')->all())->toBe([$child->id]);
    $this->assertDatabaseMissing('admin_users', ['username' => 'new-operator']);
})->with(['own', 'peer', 'ancestor', 'super'])->with(['POST', 'PATCH']);

test('returns 403 when a target has any peer role or an out of scope direct permission', function (string $boundary): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $ids = AdminPermission::where('name', '!=', 'system.menu.delete')->pluck('id')->all();
    $own = accountManagementRole('manager', null, $ids);
    $child = accountManagementRole('child', $own, $ids);
    $actor = AdminUser::factory()->create();
    $actor->assignRole($own);
    $target = AdminUser::factory()->create(['nickname' => 'Protected']);
    $target->assignRole($child);
    if ($boundary === 'peer role') {
        $target->assignRole(accountManagementRole('peer'));
    } else {
        $target->givePermissionTo('system.menu.delete');
    }
    $this->withToken($actor->createToken('mixed-scope')->plainTextToken);

    $this->getJson('/api/v1/admin/admins')->assertOk()->assertJsonPath('data.total', 0);
    $this->getJson('/api/v1/admin/admins/'.$target->id)->assertNotFound();
    $this->patchJson('/api/v1/admin/admins/'.$target->id, ['nickname' => 'Escalated'])->assertForbidden();
    $this->deleteJson('/api/v1/admin/admins/'.$target->id, ['confirmed' => true])->assertForbidden();

    $this->assertModelExists($target);
    expect($target->fresh()->nickname)->toBe('Protected');
})->with(['peer role', 'direct permission']);

test('returns 422 for invalid account creation without persisting an account', function (array $changes, array $fields): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $actor = accountManagementSuperAdmin();
    $role = accountManagementRole('operator');

    $this->withToken($actor->createToken('invalid-account')->plainTextToken)
        ->postJson('/api/v1/admin/admins', accountManagementPayload($role, $changes))
        ->assertUnprocessable()->assertJsonValidationErrors($fields);

    $this->assertDatabaseCount('admin_users', 1);
    $this->assertDatabaseMissing('admin_users', ['username' => 'new-operator']);
})->with([
    'missing login name' => [['username' => ''], ['username']],
    'short password' => [['password' => 'short'], ['password']],
    'empty password' => [['password' => ''], ['password']],
    'invalid email' => [['email' => 'not-email'], ['email']],
    'no groups' => [['role_ids' => []], ['role_ids']],
    'missing group' => [['role_ids' => [999999]], ['role_ids.0']],
    'invalid status' => [['status' => 'enabled'], ['status']],
]);

test('returns 422 for duplicate usernames while preserving both accounts', function (string $method): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $actor = accountManagementSuperAdmin();
    $role = accountManagementRole('operator');
    $taken = AdminUser::factory()->create(['username' => 'taken']);
    $target = AdminUser::factory()->create(['username' => 'existing']);
    $target->assignRole($role);

    $this->withToken($actor->createToken('duplicate')->plainTextToken)
        ->json($method, '/api/v1/admin/admins'.($method === 'PATCH' ? '/'.$target->id : ''), accountManagementPayload($role, ['username' => 'taken']))
        ->assertUnprocessable()->assertJsonValidationErrors(['username']);

    expect($taken->fresh()->username)->toBe('taken');
    expect($target->fresh()->username)->toBe('existing');
    $this->assertDatabaseCount('admin_users', 3);
})->with(['POST', 'PATCH']);

test('keeps an omitted or blank edit password and the current username without revoking tokens', function (array $password): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $actor = accountManagementSuperAdmin();
    $target = AdminUser::factory()->create(['username' => 'unchanged', 'password' => 'original-password']);
    $target->assignRole(accountManagementRole('operator'));
    $hash = $target->password;
    $targetToken = $target->createToken('unchanged')->plainTextToken;

    $this->withToken($actor->createToken('edit-profile')->plainTextToken)
        ->patchJson('/api/v1/admin/admins/'.$target->id, array_merge(['username' => 'unchanged', 'nickname' => 'Changed'], $password))->assertOk();

    expect($target->fresh()->password)->toBe($hash);
    expect(PersonalAccessToken::findToken($targetToken))->not->toBeNull();
})->with(['omitted' => [[]], 'blank' => [['password' => '']], 'null' => [['password' => null]]]);

test('hashes a password reset revokes every target token and accepts only the new login password', function (): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $actor = accountManagementSuperAdmin();
    $target = AdminUser::factory()->create(['username' => 'reset-target', 'password' => 'original-password']);
    $target->assignRole(accountManagementRole('operator'));
    $first = $target->createToken('first')->plainTextToken;
    $second = $target->createToken('second')->plainTextToken;
    $actorToken = $actor->createToken('reset')->plainTextToken;

    $this->withToken($actorToken)->patchJson('/api/v1/admin/admins/'.$target->id, ['password' => 'replacement-password'])->assertOk()
        ->assertJsonMissingPaths(['data.password', 'data.remember_token']);

    expect(Hash::check('replacement-password', $target->fresh()->password))->toBeTrue();
    expect($target->tokens()->count())->toBe(0);
    expect(PersonalAccessToken::findToken($actorToken))->not->toBeNull();
    $this->app['auth']->forgetGuards();
    $this->withToken($first)->getJson('/api/v1/admin/me')->assertUnauthorized();
    $this->withToken($second)->getJson('/api/v1/admin/me')->assertUnauthorized();
    $this->postJson('/api/v1/admin/auth/login', ['userName' => 'reset-target', 'password' => 'original-password'])->assertUnauthorized();
    $this->postJson('/api/v1/admin/auth/login', ['userName' => 'reset-target', 'password' => 'replacement-password'])->assertOk();
});

test('disables another administrator and revokes tokens without restoring them on enable', function (): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $actor = accountManagementSuperAdmin();
    $target = AdminUser::factory()->create(['username' => 'status-target', 'password' => 'status-password']);
    $target->assignRole(accountManagementRole('operator'));
    $token = $target->createToken('status')->plainTextToken;
    $this->withToken($actor->createToken('disable')->plainTextToken);

    $this->patchJson('/api/v1/admin/admins/'.$target->id, ['status' => false])->assertOk()->assertJsonPath('data.status', false);

    expect($target->fresh()->status)->toBeFalse();
    expect($target->tokens()->count())->toBe(0);
    $this->postJson('/api/v1/admin/auth/login', ['userName' => 'status-target', 'password' => 'status-password'])->assertUnauthorized();
    $this->patchJson('/api/v1/admin/admins/'.$target->id, ['status' => true])->assertOk();
    expect(PersonalAccessToken::findToken($token))->toBeNull();
    $this->postJson('/api/v1/admin/auth/login', ['userName' => 'status-target', 'password' => 'status-password'])->assertOk();
});

test('returns 409 for self disable deletion and demotion even when another enabled super exists', function (string $operation, bool $anotherSuper): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $actor = accountManagementSuperAdmin();
    $role = accountManagementRole('ordinary');
    if ($anotherSuper) {
        accountManagementSuperAdmin();
    }
    $token = $actor->createToken('self-guard')->plainTextToken;
    $payload = match ($operation) {
        'disable' => ['status' => false],
        'delete' => ['confirmed' => true],
        'demote' => ['role_ids' => [$role->id]],
    };

    $this->withToken($token)->json($operation === 'delete' ? 'DELETE' : 'PATCH', '/api/v1/admin/admins/'.$actor->id, $payload)->assertConflict();

    $this->assertModelExists($actor);
    expect($actor->fresh()->status)->toBeTrue();
    expect($actor->fresh()->hasRole('super-admin'))->toBeTrue();
    expect(PersonalAccessToken::findToken($token))->not->toBeNull();
})->with(['disable', 'delete', 'demote'])->with(['last enabled super' => [false], 'another enabled super' => [true]]);

test('allows a super administrator to provision super membership and remove another super while retaining one', function (): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $actor = accountManagementSuperAdmin();
    $superRole = AdminRole::findByName('super-admin', 'web');
    $ordinaryRole = accountManagementRole('ordinary');
    $this->withToken($actor->createToken('provision-super')->plainTextToken);

    $created = $this->postJson('/api/v1/admin/admins', accountManagementPayload($superRole))->assertCreated();
    $target = AdminUser::findOrFail($created->json('data.id'));
    expect($target->hasRole('super-admin'))->toBeTrue();
    $this->patchJson('/api/v1/admin/admins/'.$target->id, ['role_ids' => [$ordinaryRole->id]])->assertOk();

    expect($target->fresh()->hasRole('super-admin'))->toBeFalse();
    expect($actor->fresh()->hasRole('super-admin'))->toBeTrue();
});

test('requires explicit confirmation before deleting an administrator', function (array $payload): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $actor = accountManagementSuperAdmin();
    $target = AdminUser::factory()->create();

    $this->withToken($actor->createToken('confirm')->plainTextToken)
        ->deleteJson('/api/v1/admin/admins/'.$target->id, $payload)->assertUnprocessable()->assertJsonValidationErrors(['confirmed']);

    $this->assertModelExists($target);
})->with(['absent' => [[]], 'declined' => [['confirmed' => false]]]);

test('deletes only the administrator tokens and permission pivots when an ordinary user shares its ID', function (): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $actor = accountManagementSuperAdmin();
    $target = AdminUser::factory()->create();
    $user = User::factory()->create(['id' => $target->id]);
    $role = accountManagementRole('shared-role');
    $permission = AdminPermission::findByName('system.menu.view', 'web');
    $target->assignRole($role);
    $target->givePermissionTo($permission);
    $user->assignRole($role);
    $user->givePermissionTo($permission);
    $targetToken = $target->createToken('admin-token')->plainTextToken;
    $userToken = $user->createToken('user-token')->plainTextToken;

    $this->withToken($actor->createToken('delete-isolated')->plainTextToken)
        ->deleteJson('/api/v1/admin/admins/'.$target->id, ['confirmed' => true])->assertOk();

    $this->assertModelMissing($target);
    $this->assertModelExists($user);
    expect(PersonalAccessToken::findToken($targetToken))->toBeNull();
    expect(PersonalAccessToken::findToken($userToken))->not->toBeNull();
    $this->assertDatabaseMissing('admin_model_has_roles', ['model_id' => $target->id, 'model_type' => $target->getMorphClass()]);
    $this->assertDatabaseMissing('admin_model_has_permissions', ['model_id' => $target->id, 'model_type' => $target->getMorphClass()]);
    $this->assertDatabaseHas('admin_model_has_roles', ['model_id' => $user->id, 'model_type' => $user->getMorphClass(), 'role_id' => $role->id]);
    $this->assertDatabaseHas('admin_model_has_permissions', ['model_id' => $user->id, 'model_type' => $user->getMorphClass(), 'permission_id' => $permission->id]);
});

test('rolls back account deletion including tokens and morph specific role and permission pivots on an event error', function (): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $actor = accountManagementSuperAdmin();
    $target = AdminUser::factory()->create();
    $role = accountManagementRole('rollback-role');
    $permission = AdminPermission::findByName('system.menu.view', 'web');
    $target->assignRole($role);
    $target->givePermissionTo($permission);
    $token = $target->createToken('restore-token')->plainTextToken;
    $dispatcher = AdminUser::getEventDispatcher();
    $temporary = clone $dispatcher;
    $temporary->listen('eloquent.deleted: '.AdminUser::class, function (AdminUser $deleted) use ($target): void {
        if ($deleted->id === $target->id) {
            throw new RuntimeException('Forced administrator deletion failure');
        }
    });
    AdminUser::setEventDispatcher($temporary);

    try {
        $this->withToken($actor->createToken('rollback-delete')->plainTextToken)
            ->deleteJson('/api/v1/admin/admins/'.$target->id, ['confirmed' => true])->assertServerError();
    } finally {
        AdminUser::setEventDispatcher($dispatcher);
    }

    $this->assertModelExists($target);
    expect(PersonalAccessToken::findToken($token))->not->toBeNull();
    $this->assertDatabaseHas('admin_model_has_roles', ['model_id' => $target->id, 'model_type' => $target->getMorphClass(), 'role_id' => $role->id]);
    $this->assertDatabaseHas('admin_model_has_permissions', ['model_id' => $target->id, 'model_type' => $target->getMorphClass(), 'permission_id' => $permission->id]);
});

test('updates live roles buttons and menus without replacing the target bearer token', function (): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $actor = accountManagementSuperAdmin();
    $readerIds = AdminPermission::whereIn('name', ['system.menu.access', 'system.menu.view'])->pluck('id')->all();
    $writerIds = AdminPermission::whereIn('name', ['system.role.access', 'system.role.update'])->pluck('id')->all();
    $readerRole = accountManagementRole('reader', null, $readerIds);
    $writerRole = accountManagementRole('writer', null, $writerIds);
    $target = AdminUser::factory()->create();
    $target->assignRole($readerRole);
    $token = $target->createToken('live-assignment')->plainTextToken;
    $this->withToken($token)->getJson('/api/v1/admin/me')->assertOk()
        ->assertJsonPath('data.roles', ['reader'])->assertJsonPath('data.buttons', ['system.menu.view']);

    $this->withToken($actor->createToken('assign-writer')->plainTextToken)
        ->patchJson('/api/v1/admin/admins/'.$target->id, ['role_ids' => [$writerRole->id]])->assertOk();

    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/admin/me')->assertOk()
        ->assertJsonPath('data.roles', ['writer'])->assertJsonPath('data.buttons', ['system.role.update']);
    $routes = $this->getJson('/api/v1/admin/me/menus')->assertOk();
    $routes->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'system')
        ->assertJsonPath('data.0.meta.title', '权限管理')
        ->assertJsonPath('data.0.component', '/index/index')
        ->assertJsonCount(1, 'data.0.children')
        ->assertJsonPath('data.0.children.0.name', 'system.role');
    expect(PersonalAccessToken::findToken($token))->not->toBeNull();
});

test('returns 422 for client supplied permission and credential metadata', function (string $field, mixed $value): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $actor = accountManagementSuperAdmin();
    $role = accountManagementRole('operator');

    $this->withToken($actor->createToken('injected-fields')->plainTextToken)
        ->postJson('/api/v1/admin/admins', accountManagementPayload($role, [$field => $value]))
        ->assertUnprocessable()->assertJsonValidationErrors([$field]);

    $this->assertDatabaseCount('admin_users', 1);
})->with([
    'direct grants' => ['permissions', ['system.menu.delete']],
    'permission IDs' => ['permission_ids', [1]],
    'role names' => ['roles', ['super-admin']],
    'guard' => ['guard_name', 'web'],
    'remember token' => ['remember_token', 'injected'],
    'tokens' => ['tokens', ['injected']],
]);

test('direct management permissions without an assigned group confer no administrator hierarchy', function (): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $actor = AdminUser::factory()->create();
    $actor->givePermissionTo(AdminPermission::all());
    $role = accountManagementRole('unrelated');
    $target = AdminUser::factory()->create();
    $target->assignRole($role);
    $this->withToken($actor->createToken('no-hierarchy')->plainTextToken);

    $this->getJson('/api/v1/admin/admins')->assertOk()->assertJsonPath('data.records', [])->assertJsonPath('data.total', 0);
    $this->postJson('/api/v1/admin/admins', accountManagementPayload($role))->assertForbidden();

    $this->assertDatabaseMissing('admin_users', ['username' => 'new-operator']);
});

test('rolls back new accounts and role pivots when assignment events fail after attachment', function (): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $actor = accountManagementSuperAdmin();
    $role = accountManagementRole('operator');
    config(['permission.events_enabled' => true]);
    $dispatcher = Event::getFacadeRoot();
    $temporary = clone $dispatcher;
    $temporary->listen(RoleAttachedEvent::class, function (RoleAttachedEvent $event): void {
        if ($event->model instanceof AdminUser && $event->model->username === 'new-operator') {
            throw new RuntimeException('Forced account assignment failure');
        }
    });
    Event::swap($temporary);

    try {
        $this->withToken($actor->createToken('rollback-create')->plainTextToken)
            ->postJson('/api/v1/admin/admins', accountManagementPayload($role))->assertServerError();
    } finally {
        Event::swap($dispatcher);
    }

    $this->assertDatabaseCount('admin_users', 1);
    $this->assertDatabaseMissing('admin_users', ['username' => 'new-operator']);
    $this->assertDatabaseMissing('admin_model_has_roles', ['role_id' => $role->id]);
});

test('rolls back account attributes and old role membership when replacement assignment fails', function (): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $actor = accountManagementSuperAdmin();
    $oldRole = accountManagementRole('old-role');
    $newRole = accountManagementRole('new-role');
    $target = AdminUser::factory()->create(['nickname' => 'Original', 'password' => 'original-password']);
    $target->assignRole($oldRole);
    $token = $target->createToken('preserved-token')->plainTextToken;
    config(['permission.events_enabled' => true]);
    $dispatcher = Event::getFacadeRoot();
    $temporary = clone $dispatcher;
    $temporary->listen(RoleAttachedEvent::class, function (RoleAttachedEvent $event) use ($target): void {
        if ($event->model instanceof AdminUser && $event->model->id === $target->id) {
            throw new RuntimeException('Forced account reassignment failure');
        }
    });
    Event::swap($temporary);

    try {
        $this->withToken($actor->createToken('rollback-update')->plainTextToken)
            ->patchJson('/api/v1/admin/admins/'.$target->id, [
                'nickname' => 'Should roll back', 'password' => 'replacement-password', 'role_ids' => [$newRole->id],
            ])->assertServerError();
    } finally {
        Event::swap($dispatcher);
    }

    expect($target->fresh()->nickname)->toBe('Original');
    expect(Hash::check('original-password', $target->fresh()->password))->toBeTrue();
    expect($target->roles()->pluck('id')->all())->toBe([$oldRole->id]);
    expect(PersonalAccessToken::findToken($token))->not->toBeNull();
});

test('returns 403 for account writes across own peer ancestor and super groups', function (string $targetGroup, string $method): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $ids = AdminPermission::pluck('id')->all();
    $ancestor = accountManagementRole('ancestor', null, $ids);
    $own = accountManagementRole('own', $ancestor, $ids);
    $peer = accountManagementRole('peer', $ancestor, $ids);
    $super = AdminRole::findOrCreate('super-admin', 'web');
    $actor = AdminUser::factory()->create();
    $actor->assignRole($own);
    $target = AdminUser::factory()->create(['nickname' => 'Protected']);
    $target->assignRole(['own' => $own, 'peer' => $peer, 'ancestor' => $ancestor, 'super' => $super][$targetGroup]);

    $this->withToken($actor->createToken('target-scope')->plainTextToken)
        ->json($method, '/api/v1/admin/admins/'.$target->id, ['nickname' => 'Escalated', 'confirmed' => true])->assertForbidden();

    $this->assertModelExists($target);
    expect($target->fresh()->nickname)->toBe('Protected');
})->with(['own', 'peer', 'ancestor', 'super'])->with(['PATCH', 'DELETE']);

test('paginates actual matching accounts and excludes disabled records when filtering active accounts', function (): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $actor = accountManagementSuperAdmin();
    AdminUser::factory()->create(['username' => 'matching-one']);
    AdminUser::factory()->create(['username' => 'matching-two']);
    AdminUser::factory()->create(['username' => 'matching-three']);
    AdminUser::factory()->create(['username' => 'matching-disabled', 'status' => false]);
    $this->withToken($actor->createToken('pagination')->plainTextToken);

    $first = $this->getJson('/api/v1/admin/admins?keyword=matching&status=1&per_page=1&page=1')->assertOk()
        ->assertJsonPath('data.total', 3)->assertJsonPath('data.current', 1)->assertJsonCount(1, 'data.records');
    $second = $this->getJson('/api/v1/admin/admins?keyword=matching&status=1&per_page=1&page=2')->assertOk()
        ->assertJsonPath('data.total', 3)->assertJsonPath('data.current', 2)->assertJsonCount(1, 'data.records');

    expect($first->json('data.records.0.id'))->not->toBe($second->json('data.records.0.id'));
    expect($first->json('data.records.0.status'))->toBeTrue();
    expect($second->json('data.records.0.status'))->toBeTrue();
});
