<?php

use App\Models\AdminLog;
use App\Models\AdminMenu;
use App\Models\AdminPermission;
use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\User;
use Database\Seeders\AdminManagementMenuSeeder;
use Database\Seeders\AdminMenuSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

uses(LazilyRefreshDatabase::class);

function auditLogSuperAdmin(): AdminUser
{
    $admin = AdminUser::factory()->create();
    $admin->assignRole(AdminRole::findOrCreate('super-admin', 'web'));

    return $admin;
}

function auditLogRole(string $name, ?AdminRole $parent = null, array $permissions = []): AdminRole
{
    $role = AdminRole::create([
        'name' => $name, 'display_name' => $name, 'guard_name' => 'web',
        'parent_id' => $parent?->id, 'status' => true,
    ]);
    $role->syncPermissions($permissions);

    return $role;
}

function auditLogRecord(?AdminUser $actor = null, array $attributes = []): AdminLog
{
    return AdminLog::create(array_replace([
        'actor_id' => $actor?->id, 'actor_username' => $actor?->username,
        'actor_nickname' => $actor?->nickname, 'attempted_username' => null,
        'module' => 'admin', 'action' => 'update', 'permission_name' => 'system.admin.update',
        'route_name' => 'admin.admins.update', 'method' => 'PATCH', 'path' => 'api/v1/admin/admins/{admin}',
        'target_type' => 'admin', 'target_id' => $actor?->id, 'target_label' => 'Audit target',
        'status_code' => 200, 'success' => true, 'ip_address' => '192.0.2.10',
        'user_agent' => 'Audit test client', 'duration_ms' => 12,
        'changes' => ['nickname' => ['before' => 'Before', 'after' => 'After']],
        'related_changes' => null, 'created_at' => now(),
    ], $attributes));
}

test('records sanitized administrator create update and delete diffs without credentials or request payloads', function (): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $actor = auditLogSuperAdmin();
    $first = auditLogRole('audit-first');
    $second = auditLogRole('audit-second');
    $bearer = $actor->createToken('audit-admin-crud')->plainTextToken;
    $this->withToken($bearer)->withHeaders(['User-Agent' => 'Audit browser', 'Cookie' => 'audit_secret_cookie=private-cookie']);

    $created = $this->postJson('/api/v1/admin/admins?private_query=private-query', [
        'username' => 'audited-account', 'nickname' => 'Before nickname', 'password' => 'private-create-password',
        'email' => 'before.private@example.com', 'mobile' => '+8613800000000',
        'role_ids' => [$second->id, $first->id], 'status' => true, 'unrelated_input' => 'private-extra-input',
    ])->assertCreated();
    $target = AdminUser::findOrFail($created->json('data.id'));
    $creation = AdminLog::where('module', 'admin')->where('action', 'create')->sole();
    expect($creation->actor_id)->toBe($actor->id);
    expect($creation->actor_username)->toBe($actor->username);
    expect($creation->actor_nickname)->toBe($actor->nickname);
    expect($creation->target_id)->toBe($target->id);
    expect($creation->route_name)->toBe('admin.admins.store');
    expect($creation->method)->toBe('POST');
    expect($creation->path)->not->toContain('?', 'private-query');
    expect($creation->permission_name)->toBe('system.admin.create');
    expect($creation->status_code)->toBe(201);
    expect($creation->success)->toBeTrue();
    expect($creation->user_agent)->toBe('Audit browser');
    expect($creation->duration_ms)->toBeInt()->toBeGreaterThanOrEqual(0);
    expect($creation->changes['username'])->toBe(['before' => null, 'after' => 'audited-account']);
    expect($creation->changes['role_ids'])->toBe(['before' => null, 'after' => [$first->id, $second->id]]);
    expect($creation->changes['password'])->toBe(['changed' => true]);
    expect($creation->changes['email']['after'])->toBeString()->toContain('*')->not->toBe('before.private@example.com');
    expect($creation->changes['mobile']['after'])->toBeString()->toContain('*')->not->toBe('+8613800000000');

    $this->patchJson('/api/v1/admin/admins/'.$target->id, [
        'nickname' => 'After nickname', 'password' => 'private-reset-password',
        'email' => 'after.private@example.com', 'mobile' => '+8613900000000', 'role_ids' => [$second->id],
    ])->assertOk();
    $update = AdminLog::where('module', 'admin')->where('action', 'update')->sole();
    expect($target->fresh()->nickname)->toBe('After nickname');
    expect(Hash::check('private-reset-password', $target->fresh()->password))->toBeTrue();
    expect($update->changes['nickname'])->toBe(['before' => 'Before nickname', 'after' => 'After nickname']);
    expect($update->changes['role_ids'])->toBe(['before' => [$first->id, $second->id], 'after' => [$second->id]]);
    expect($update->changes['password'])->toBe(['changed' => true]);
    expect($update->changes['email']['before'])->toBe($creation->changes['email']['after']);
    expect($update->changes['email']['after'])->toContain('*')->not->toBe('after.private@example.com');
    expect($update->changes['mobile']['before'])->toBe($creation->changes['mobile']['after']);
    expect($update->changes['mobile']['after'])->toContain('*')->not->toBe('+8613900000000');

    $this->deleteJson('/api/v1/admin/admins/'.$target->id, ['confirmed' => true])->assertOk();
    $deletion = AdminLog::where('module', 'admin')->where('action', 'delete')->sole();
    $this->assertModelMissing($target);
    expect($deletion->target_id)->toBe($target->id);
    expect($deletion->changes['nickname'])->toBe(['before' => 'After nickname', 'after' => null]);
    expect($deletion->changes['role_ids'])->toBe(['before' => [$second->id], 'after' => null]);
    expect(AdminLog::count())->toBe(3);
    $serialized = AdminLog::all()->toJson();
    foreach ([$bearer, $actor->tokens()->sole()->token, $target->password, 'private-create-password', 'private-reset-password',
        'before.private@example.com', 'after.private@example.com', '+8613800000000', '+8613900000000',
        'private-cookie', 'private-query', 'private-extra-input'] as $secret) {
        expect($serialized)->not->toContain($secret);
    }
});

test('records generated menu buttons as related changes and preserves actual menu update and deletion diffs', function (): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $actor = auditLogSuperAdmin();
    $this->withToken($actor->createToken('audit-menu-crud')->plainTextToken);

    $response = $this->postJson('/api/v1/admin/menus', [
        'type' => 'menu', 'name' => 'audit.goods', 'title' => 'Audit goods',
        'open_type' => 'page', 'path' => '/audit-goods', 'component' => '/audit-goods/index',
    ])->assertSuccessful();
    $menu = AdminMenu::findOrFail($response->json('data.id'));
    $log = AdminLog::where('module', 'menu')->where('action', 'create')->sole();
    expect($log->changes['title'])->toBe(['before' => null, 'after' => 'Audit goods']);
    expect($log->target_id)->toBe($menu->id);
    $buttons = AdminMenu::where('parent_id', $menu->id)->orderBy('id')->get();
    expect($buttons)->toHaveCount(4);
    $related = collect($log->related_changes)->keyBy('id');
    expect($related->keys()->all())->toEqualCanonicalizing($buttons->modelKeys());
    foreach ($buttons as $button) {
        expect($related[$button->id]['type'])->toBe('menu');
        expect($related[$button->id]['changes']['name'])->toBe(['before' => null, 'after' => $button->name]);
    }

    $button = $buttons->first();
    $this->patchJson('/api/v1/admin/menus/'.$button->id, ['title' => 'Audited button'])->assertOk();
    $update = AdminLog::where('module', 'menu')->where('action', 'update')->sole();
    expect($button->fresh()->title)->toBe('Audited button');
    expect($update->changes['title'])->toBe(['before' => $button->title, 'after' => 'Audited button']);

    $this->deleteJson('/api/v1/admin/menus/'.$button->id, ['confirmed' => true])->assertOk();
    $deletion = AdminLog::where('module', 'menu')->where('action', 'delete')->sole();
    $this->assertModelMissing($button);
    expect($deletion->target_id)->toBe($button->id);
    expect($deletion->changes['title'])->toBe(['before' => 'Audited button', 'after' => null]);
    expect(AdminLog::count())->toBe(3);
});

test('records role creation deletion and permission pruning for every changed descendant', function (): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $actor = auditLogSuperAdmin();
    $ids = AdminPermission::whereIn('name', ['system.menu.access', 'system.menu.view', 'system.menu.update'])->orderBy('id')->pluck('id')->all();
    $access = AdminPermission::findByName('system.menu.access', 'web')->id;
    $view = AdminPermission::findByName('system.menu.view', 'web')->id;
    $remaining = collect([$access, $view])->sort()->values()->all();
    $parent = auditLogRole('audit-parent', null, $ids);
    $child = auditLogRole('audit-child', $parent, $ids);
    $grandchild = auditLogRole('audit-grandchild', $child, $ids);
    $unchanged = auditLogRole('audit-unchanged', $parent, $remaining);
    $this->withToken($actor->createToken('audit-roles')->plainTextToken);

    $created = $this->postJson('/api/v1/admin/roles', [
        'name' => 'audit-new-role', 'display_name' => 'New audit role', 'parent_id' => $parent->id, 'permission_ids' => [],
    ])->assertCreated();
    $newRole = AdminRole::findOrFail($created->json('data.id'));
    $creation = AdminLog::where('module', 'role')->where('action', 'create')->sole();
    expect($creation->changes['display_name'])->toBe(['before' => null, 'after' => 'New audit role']);
    expect($creation->changes['parent_id'])->toBe(['before' => null, 'after' => $parent->id]);

    $this->patchJson('/api/v1/admin/roles/'.$parent->id, ['permission_ids' => array_reverse($remaining)])->assertOk();
    $update = AdminLog::where('module', 'role')->where('action', 'update')->sole();
    expect($update->changes['permission_ids'])->toBe(['before' => $ids, 'after' => $remaining]);
    $related = collect($update->related_changes)->keyBy('id');
    expect($related->keys()->all())->toEqualCanonicalizing([$child->id, $grandchild->id]);
    foreach ([$child, $grandchild] as $role) {
        expect($role->permissions()->orderBy('id')->pluck('id')->all())->toBe($remaining);
        expect($related[$role->id]['type'])->toBe('role');
        expect($related[$role->id]['label'])->toBe($role->display_name);
        expect($related[$role->id]['changes']['permission_ids'])->toBe(['before' => $ids, 'after' => $remaining]);
    }
    expect($unchanged->permissions()->orderBy('id')->pluck('id')->all())->toBe($remaining);

    $this->deleteJson('/api/v1/admin/roles/'.$newRole->id, ['confirmed' => true])->assertOk();
    $deletion = AdminLog::where('module', 'role')->where('action', 'delete')->sole();
    $this->assertModelMissing($newRole);
    expect($deletion->changes['display_name'])->toBe(['before' => 'New audit role', 'after' => null]);
    expect(AdminLog::count())->toBe(3);
});

test('records a 401 write failure without trusting supplied actor or target details', function (): void {
    $this->postJson('/api/v1/admin/admins', ['username' => 'untrusted-target', 'password' => 'private-password', 'actor_id' => 1])
        ->assertUnauthorized();

    $log = AdminLog::sole();
    expect($log->actor_id)->toBeNull();
    expect($log->module)->toBe('admin');
    expect($log->action)->toBe('create');
    expect($log->status_code)->toBe(401);
    expect($log->success)->toBeFalse();
    expect($log->target_label)->toBeNull();
    expect($log->changes)->toBeNull();
    expect($log->related_changes)->toBeNull();
    expect($log->toJson())->not->toContain('untrusted-target', 'private-password');
});

test('records a 403 denied write using the verified actor without target snapshots', function (): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $actor = AdminUser::factory()->create();
    $actor->givePermissionTo('system.admin.access');
    $target = AdminUser::factory()->create(['nickname' => 'Private denied target']);

    $this->withToken($actor->createToken('audit-denied')->plainTextToken)
        ->patchJson('/api/v1/admin/admins/'.$target->id, ['nickname' => 'Denied update'])->assertForbidden();

    $log = AdminLog::sole();
    expect($log->actor_id)->toBe($actor->id);
    expect($log->status_code)->toBe(403);
    expect($log->success)->toBeFalse();
    expect($log->changes)->toBeNull();
    expect($log->related_changes)->toBeNull();
    expect($log->target_label)->toBeNull();
    expect($target->fresh()->nickname)->toBe('Private denied target');
    expect($log->toJson())->not->toContain('Private denied target', 'Denied update');
});

test('records a 422 validation failure without diffs or submitted secrets', function (): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $actor = auditLogSuperAdmin();

    $this->withToken($actor->createToken('audit-invalid')->plainTextToken)
        ->postJson('/api/v1/admin/admins', ['email' => 'invalid-private-email', 'password' => 'private-invalid-password'])
        ->assertUnprocessable()->assertJsonValidationErrors(['username', 'nickname', 'role_ids']);

    $this->assertDatabaseCount('admin_users', 1);
    $log = AdminLog::sole();
    expect($log->actor_id)->toBe($actor->id);
    expect($log->status_code)->toBe(422);
    expect($log->success)->toBeFalse();
    expect($log->changes)->toBeNull();
    expect($log->related_changes)->toBeNull();
    expect($log->target_label)->toBeNull();
    expect($log->toJson())->not->toContain('invalid-private-email', 'private-invalid-password');
});

test('records a 409 rejected self deletion as a failure without a successful mutation', function (): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $actor = auditLogSuperAdmin();

    $this->withToken($actor->createToken('audit-conflict')->plainTextToken)
        ->deleteJson('/api/v1/admin/admins/'.$actor->id, ['confirmed' => true])->assertConflict();

    $this->assertModelExists($actor);
    $log = AdminLog::sole();
    expect($log->status_code)->toBe(409);
    expect($log->success)->toBeFalse();
    expect($log->changes)->toBeNull();
    expect($log->related_changes)->toBeNull();
    expect($log->target_label)->toBeNull();
});

test('records each failed login as 401 without identifying an unverified actor', function (string $state): void {
    $admin = AdminUser::factory()->create(['username' => 'audit-login-account', 'password' => 'private-correct-password', 'status' => $state !== 'disabled']);
    $username = $state === 'unknown' ? 'audit-missing-account' : $admin->username;
    $password = $state === 'disabled' ? 'private-correct-password' : 'private-wrong-password';

    $this->postJson('/api/v1/admin/auth/login', ['userName' => '  '.$username.'  ', 'password' => $password])->assertUnauthorized();

    $log = AdminLog::sole();
    expect($log->actor_id)->toBeNull();
    expect($log->actor_username)->toBeNull();
    expect($log->actor_nickname)->toBeNull();
    expect($log->attempted_username)->toBe($username);
    expect($log->module)->toBe('auth');
    expect($log->action)->toBe('login');
    expect($log->status_code)->toBe(401);
    expect($log->success)->toBeFalse();
    expect($log->changes)->toBeNull();
    expect($log->related_changes)->toBeNull();
    expect($log->target_label)->toBeNull();
    expect($log->toJson())->not->toContain('private-wrong-password', 'private-correct-password', $admin->password);
    expect($admin->tokens()->count())->toBe(0);
})->with(['unknown', 'wrong password', 'disabled']);

test('deduplicates only 429 login failures for the same normalized username IP and minute', function (): void {
    $this->travelTo(now()->startOfMinute());
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/api/v1/admin/auth/login', ['userName' => '  audit-rate-limit  ', 'password' => 'wrong'])->assertUnauthorized();
    }
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $this->postJson('/api/v1/admin/auth/login', ['userName' => 'audit-rate-limit', 'password' => 'wrong'])->assertTooManyRequests();
    }

    expect(AdminLog::where('status_code', 401)->count())->toBe(5);
    expect(AdminLog::where('status_code', 429)->count())->toBe(1);
    $limited = AdminLog::where('status_code', 429)->sole();
    expect($limited->attempted_username)->toBe('audit-rate-limit');
    expect($limited->actor_id)->toBeNull();
    expect($limited->changes)->toBeNull();

    $this->travel(61)->seconds();
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/api/v1/admin/auth/login', ['userName' => 'audit-rate-limit', 'password' => 'wrong'])->assertUnauthorized();
    }
    $this->postJson('/api/v1/admin/auth/login', ['userName' => 'audit-rate-limit', 'password' => 'wrong'])->assertTooManyRequests();
    expect(AdminLog::where('status_code', 401)->count())->toBe(10);
    expect(AdminLog::where('status_code', 429)->count())->toBe(2);
});

test('records successful login and logout once without storing bearer credentials', function (): void {
    $admin = AdminUser::factory()->create(['username' => 'audit-auth-success', 'password' => 'private-login-password']);

    $token = $this->postJson('/api/v1/admin/auth/login', ['userName' => $admin->username, 'password' => 'private-login-password'])
        ->assertOk()->json('data.token');
    $login = AdminLog::where('action', 'login')->sole();
    expect($login->actor_id)->toBe($admin->id);
    expect($login->actor_username)->toBe($admin->username);
    expect($login->success)->toBeTrue();
    expect($login->status_code)->toBe(200);
    $this->withToken($token)->postJson('/api/v1/admin/auth/logout')->assertOk();

    expect(PersonalAccessToken::findToken($token))->toBeNull();
    $logout = AdminLog::where('action', 'logout')->sole();
    expect($logout->actor_id)->toBe($admin->id);
    expect($logout->success)->toBeTrue();
    expect(AdminLog::count())->toBe(2);
    expect(AdminLog::all()->toJson())->not->toContain($token, 'private-login-password', $admin->password);
});

test('returns 500 and rolls back token issuance when successful login audit persistence fails', function (): void {
    $admin = AdminUser::factory()->create(['username' => 'audit-atomic-login', 'password' => 'private-login-password']);
    $existing = $admin->createToken('existing-session')->plainTextToken;
    $dispatcher = AdminLog::getEventDispatcher();
    AdminLog::setEventDispatcher(clone $dispatcher);
    AdminLog::creating(function (AdminLog $log): void {
        if ($log->module === 'auth' && $log->action === 'login' && $log->success) {
            throw new RuntimeException('private-audit-persistence-failure');
        }
    });

    try {
        $this->postJson('/api/v1/admin/auth/login', ['userName' => $admin->username, 'password' => 'private-login-password'])
            ->assertServerError()->assertJsonMissingPath('data.token');
    } finally {
        AdminLog::setEventDispatcher($dispatcher);
    }

    expect($admin->tokens()->count())->toBe(1);
    expect(PersonalAccessToken::findToken($existing))->not->toBeNull();
    expect(AdminLog::where('success', true)->count())->toBe(0);
    $failure = AdminLog::sole();
    expect($failure->status_code)->toBe(500);
    expect($failure->changes)->toBeNull();
    expect($failure->related_changes)->toBeNull();
    expect($failure->toJson())->not->toContain('private-audit-persistence-failure', 'private-login-password');
});

test('returns 500 and rolls back administrator mutations and tokens when successful audit persistence fails', function (string $operation): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $actor = auditLogSuperAdmin();
    $role = auditLogRole('audit-original-role');
    $replacement = auditLogRole('audit-replacement-role');
    $target = AdminUser::factory()->create(['username' => 'audit-original-target', 'nickname' => 'Original nickname']);
    $target->assignRole($role);
    $targetToken = $target->createToken('must-survive-rollback')->plainTextToken;
    $originalPassword = $target->password;
    $this->withToken($actor->createToken('audit-atomic-crud')->plainTextToken);
    $dispatcher = AdminLog::getEventDispatcher();
    AdminLog::setEventDispatcher(clone $dispatcher);
    AdminLog::creating(function (AdminLog $log): void {
        if ($log->module === 'admin' && $log->success) {
            throw new RuntimeException('private-crud-audit-failure');
        }
    });

    try {
        (match ($operation) {
            'create' => $this->postJson('/api/v1/admin/admins', [
                'username' => 'audit-rolled-back-create', 'nickname' => 'New account',
                'password' => 'private-new-password', 'role_ids' => [$replacement->id],
            ]),
            'update' => $this->patchJson('/api/v1/admin/admins/'.$target->id, [
                'nickname' => 'Changed nickname', 'password' => 'private-new-password', 'role_ids' => [$replacement->id],
            ]),
            'delete' => $this->deleteJson('/api/v1/admin/admins/'.$target->id, ['confirmed' => true]),
        })->assertServerError();
    } finally {
        AdminLog::setEventDispatcher($dispatcher);
    }

    $this->assertDatabaseCount('admin_users', 2);
    $this->assertDatabaseMissing('admin_users', ['username' => 'audit-rolled-back-create']);
    $this->assertModelExists($target);
    expect($target->fresh()->nickname)->toBe('Original nickname');
    expect($target->fresh()->password)->toBe($originalPassword);
    expect($target->roles()->pluck('id')->all())->toBe([$role->id]);
    expect(PersonalAccessToken::findToken($targetToken))->not->toBeNull();
    $this->assertDatabaseMissing('admin_model_has_roles', ['role_id' => $replacement->id]);
    $log = AdminLog::sole();
    expect($log->action)->toBe($operation);
    expect($log->status_code)->toBe(500);
    expect($log->success)->toBeFalse();
    expect($log->changes)->toBeNull();
    expect($log->related_changes)->toBeNull();
    expect($log->target_label)->toBeNull();
    expect($log->toJson())->not->toContain('private-crud-audit-failure', 'private-new-password');
})->with(['create', 'update', 'delete']);

test('records a rolled back business exception outside the failed transaction without exception details', function (): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $actor = auditLogSuperAdmin();
    $dispatcher = AdminMenu::getEventDispatcher();
    AdminMenu::setEventDispatcher(clone $dispatcher);
    AdminMenu::created(function (AdminMenu $menu): void {
        if ($menu->name === 'audit.rollback.update') {
            throw new RuntimeException('SQLSTATE private-business-exception');
        }
    });

    try {
        $this->withToken($actor->createToken('audit-business-failure')->plainTextToken)->postJson('/api/v1/admin/menus', [
            'type' => 'menu', 'name' => 'audit.rollback', 'title' => 'Private failed menu title',
            'open_type' => 'page', 'path' => '/audit-rollback', 'component' => '/audit-rollback/index',
        ])->assertServerError();
    } finally {
        AdminMenu::setEventDispatcher($dispatcher);
    }

    expect(AdminMenu::where('name', 'like', 'audit.rollback%')->count())->toBe(0);
    expect(AdminPermission::where('name', 'like', 'audit.rollback%')->count())->toBe(0);
    $log = AdminLog::sole();
    expect($log->module)->toBe('menu');
    expect($log->status_code)->toBe(500);
    expect($log->success)->toBeFalse();
    expect($log->changes)->toBeNull();
    expect($log->related_changes)->toBeNull();
    expect($log->target_label)->toBeNull();
    expect($log->toJson())->not->toContain('SQLSTATE', 'private-business-exception', 'Private failed menu title');
});

test('revokes only the current logout token even when audit persistence fails', function (): void {
    $admin = AdminUser::factory()->create();
    $current = $admin->createToken('logout-current')->plainTextToken;
    $other = $admin->createToken('logout-other')->plainTextToken;
    $dispatcher = AdminLog::getEventDispatcher();
    AdminLog::setEventDispatcher(clone $dispatcher);
    AdminLog::creating(function (AdminLog $log): void {
        if ($log->module === 'auth' && $log->action === 'logout') {
            throw new RuntimeException('private-logout-audit-failure');
        }
    });

    try {
        $this->withToken($current)->postJson('/api/v1/admin/auth/logout')->assertOk();
    } finally {
        AdminLog::setEventDispatcher($dispatcher);
    }

    expect(PersonalAccessToken::findToken($current))->toBeNull();
    expect(PersonalAccessToken::findToken($other))->not->toBeNull();
    $this->app['auth']->forgetGuards();
    $this->withToken($current)->getJson('/api/v1/admin/me')->assertUnauthorized();
    $this->app['auth']->forgetGuards();
    $this->withToken($other)->getJson('/api/v1/admin/me')->assertOk();
});

test('returns only own and currently manageable descendant actors before pagination and hides out of scope details with 404', function (): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $permissions = ['system.log.access', 'system.log.view'];
    $ancestor = auditLogRole('audit-ancestor', null, $permissions);
    $ownRole = auditLogRole('audit-own', $ancestor, $permissions);
    $childRole = auditLogRole('audit-descendant', $ownRole, $permissions);
    $foreignRole = auditLogRole('audit-foreign', null, $permissions);
    $actor = AdminUser::factory()->create();
    $actor->assignRole($ownRole);
    $child = AdminUser::factory()->create();
    $child->assignRole($childRole);
    $ownLog = auditLogRecord($actor);
    $childLog = auditLogRecord($child);
    $hidden = [];
    foreach ([$ownRole, $ancestor, $foreignRole] as $role) {
        $outside = AdminUser::factory()->create();
        $outside->assignRole($role);
        $hidden[] = auditLogRecord($outside);
    }
    $mixed = AdminUser::factory()->create();
    $mixed->assignRole([$childRole, $foreignRole]);
    $hidden[] = auditLogRecord($mixed);
    $deleted = AdminUser::factory()->create();
    $deleted->assignRole($childRole);
    $deletedLog = auditLogRecord($deleted);
    $deleted->delete();
    $hidden[] = $deletedLog;
    $hidden[] = auditLogRecord(null, ['attempted_username' => 'unverified-login']);
    $count = AdminLog::count();
    $this->withToken($actor->createToken('audit-scoped-reader')->plainTextToken);

    $first = $this->getJson('/api/v1/admin/logs?per_page=1')->assertOk()->assertJsonPath('data.total', 2)
        ->assertJsonPath('data.current', 1)->assertJsonPath('data.size', 1);
    $second = $this->getJson('/api/v1/admin/logs?per_page=1&page=2')->assertOk()->assertJsonPath('data.total', 2);
    expect([$first->json('data.records.0.id'), $second->json('data.records.0.id')])->toEqualCanonicalizing([$ownLog->id, $childLog->id]);
    $this->getJson('/api/v1/admin/logs/'.$ownLog->id)->assertOk()->assertJsonPath('data.id', $ownLog->id);
    $this->getJson('/api/v1/admin/logs/'.$childLog->id)->assertOk()->assertJsonPath('data.id', $childLog->id);
    foreach ($hidden as $log) {
        $this->getJson('/api/v1/admin/logs/'.$log->id)->assertNotFound();
    }
    $this->getJson('/api/v1/admin/logs?actor_id='.$mixed->id)->assertOk()->assertJsonPath('data.total', 0);
    expect($deletedLog->fresh()->actor_id)->toBeNull();

    $child->syncRoles([$foreignRole]);
    $this->getJson('/api/v1/admin/logs/'.$childLog->id)->assertNotFound();
    $this->getJson('/api/v1/admin/logs')->assertOk()->assertJsonPath('data.total', 1);
    $this->getJson('/api/v1/admin/me')->assertOk();
    expect(AdminLog::count())->toBe($count);
});

test('shows super administrators all actors including deleted actor snapshots and null actors without recursively logging reads', function (): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $super = auditLogSuperAdmin();
    $deleted = AdminUser::factory()->create(['username' => 'audit-deleted-actor', 'nickname' => 'Deleted actor snapshot']);
    $log = auditLogRecord($deleted);
    $deleted->delete();
    $anonymous = auditLogRecord(null, ['attempted_username' => 'unknown-login', 'success' => false, 'changes' => null]);
    $this->withToken($super->createToken('audit-super-reader')->plainTextToken);

    $this->getJson('/api/v1/admin/logs')->assertOk()->assertJsonPath('data.total', 2)
        ->assertJsonMissingPaths(['data.records.0.changes', 'data.records.0.related_changes', 'data.records.0.actor']);
    $this->getJson('/api/v1/admin/logs/'.$log->id)->assertOk()
        ->assertJsonPath('data.actor_id', null)->assertJsonPath('data.actor_username', 'audit-deleted-actor')
        ->assertJsonPath('data.actor_nickname', 'Deleted actor snapshot')
        ->assertJsonPath('data.changes.nickname', ['before' => 'Before', 'after' => 'After'])
        ->assertJsonMissingPaths(['data.actor', 'data.password', 'data.token', 'data.updated_at']);
    $this->getJson('/api/v1/admin/logs/'.$anonymous->id)->assertOk()->assertJsonPath('data.attempted_username', 'unknown-login');
    $this->getJson('/api/v1/admin/admins')->assertOk();
    $this->getJson('/api/v1/admin/roles')->assertOk();
    $this->getJson('/api/v1/admin/menus')->assertOk();
    expect(AdminLog::count())->toBe(2);
});

test('requires both log page access and view permission for list and detail with 403', function (array $permissions): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $actor = AdminUser::factory()->create();
    $role = auditLogRole('audit-insufficient-reader', null, $permissions);
    $actor->assignRole($role);
    $log = auditLogRecord($actor);
    $this->withToken($actor->createToken('audit-insufficient')->plainTextToken);

    $this->getJson('/api/v1/admin/logs')->assertForbidden();
    $this->getJson('/api/v1/admin/logs/'.$log->id)->assertForbidden();
    expect(AdminLog::count())->toBe(1);
})->with(['no grants' => [[]], 'access only' => [['system.log.access']], 'view only' => [['system.log.view']]]);

test('returns 401 for ordinary user tokens sharing an administrator identity on log list and detail', function (): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $admin = auditLogSuperAdmin();
    $user = User::factory()->create(['id' => $admin->id]);
    $log = auditLogRecord($admin);

    $this->getJson('/api/v1/admin/logs')->assertUnauthorized();
    $this->withToken($user->createToken('ordinary-audit-reader')->plainTextToken);
    $this->getJson('/api/v1/admin/logs')->assertUnauthorized();
    $this->getJson('/api/v1/admin/logs/'.$log->id)->assertUnauthorized();
    expect(AdminLog::count())->toBe(1);
});

test('exposes no audit mutation endpoint even to a super administrator', function (string $method, string $suffix): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $super = auditLogSuperAdmin();
    $log = auditLogRecord($super);

    $this->withToken($super->createToken('audit-readonly')->plainTextToken)
        ->json($method, '/api/v1/admin/logs'.($suffix === 'detail' ? '/'.$log->id : ''), ['changes' => null])->assertStatus(405);

    expect(AdminLog::count())->toBe(1);
    expect($log->fresh()->changes)->toBe(['nickname' => ['before' => 'Before', 'after' => 'After']]);
})->with([['POST', 'collection'], ['PATCH', 'detail'], ['DELETE', 'detail']]);

test('filters all supported audit fields and orders equal timestamps by descending ID', function (): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $this->travelTo(now()->setDate(2026, 11, 18)->setTime(12, 0));
    config(['app.timezone' => 'UTC']);
    $super = auditLogSuperAdmin();
    $actor = AdminUser::factory()->create(['username' => 'filter-actor']);
    $matching = [
        'module' => 'role', 'action' => 'delete', 'success' => false, 'status_code' => 409,
        'ip_address' => '203.0.113.9', 'target_label' => null, 'changes' => null,
        'actor_nickname' => 'Filter needle', 'created_at' => now()->subHour(),
    ];
    $first = auditLogRecord($actor, $matching);
    $second = auditLogRecord($actor, $matching);
    auditLogRecord($actor, array_replace($matching, ['module' => 'menu']));
    auditLogRecord($actor, array_replace($matching, ['action' => 'update']));
    auditLogRecord($actor, array_replace($matching, ['success' => true]));
    auditLogRecord($actor, array_replace($matching, ['status_code' => 403]));
    auditLogRecord($actor, array_replace($matching, ['ip_address' => '203.0.113.8']));
    auditLogRecord($actor, array_replace($matching, ['actor_nickname' => 'Other actor']));
    auditLogRecord($actor, array_replace($matching, ['created_at' => now()->subDays(2)]));
    auditLogRecord($super, $matching);
    $filters = http_build_query([
        'keyword' => 'needle', 'actor_id' => $actor->id, 'module' => 'role', 'action' => 'delete',
        'success' => '0', 'status_code' => 409, 'ip_address' => '203.0.113.9',
        'date_from' => '2026-11-18T18:00:00+08:00', 'date_to' => '2026-11-18T20:00:00+08:00', 'per_page' => 1,
    ]);
    $this->withToken($super->createToken('audit-filter-reader')->plainTextToken);

    $this->getJson('/api/v1/admin/logs?'.$filters)->assertOk()->assertJsonPath('data.total', 2)
        ->assertJsonPath('data.records.0.id', $second->id)->assertJsonPath('data.size', 1);
    $this->getJson('/api/v1/admin/logs?'.$filters.'&page=2')->assertOk()->assertJsonPath('data.records.0.id', $first->id);
    $this->getJson('/api/v1/admin/logs?actor_id=999999')->assertOk()->assertJsonPath('data.total', 0);
    $this->getJson('/api/v1/admin/logs?keyword='.urlencode("' OR 1=1 --"))->assertOk()->assertJsonPath('data.total', 0);
});

test('searches actor snapshots attempted usernames and target labels', function (array $attributes): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $super = auditLogSuperAdmin();
    $log = auditLogRecord(null, $attributes);
    auditLogRecord(null);

    $this->withToken($super->createToken('audit-keyword-reader')->plainTextToken)
        ->getJson('/api/v1/admin/logs?keyword=search-needle')->assertOk()->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.records.0.id', $log->id);
})->with([
    'username snapshot' => [['actor_username' => 'search-needle']],
    'nickname snapshot' => [['actor_nickname' => 'search-needle']],
    'unverified login username' => [['attempted_username' => 'search-needle']],
    'target label' => [['target_label' => 'search-needle']],
]);

test('returns 422 for invalid audit filters', function (array $filters, string $field): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $super = auditLogSuperAdmin();

    $this->withToken($super->createToken('audit-invalid-filter')->plainTextToken)
        ->getJson('/api/v1/admin/logs?'.http_build_query($filters))->assertUnprocessable()->assertJsonValidationErrors([$field]);

    expect(AdminLog::count())->toBe(0);
})->with([
    'module' => [['module' => 'invalid'], 'module'],
    'action' => [['action' => 'export'], 'action'],
    'success' => [['success' => 'maybe'], 'success'],
    'status range' => [['status_code' => 600], 'status_code'],
    'IP address' => [['ip_address' => 'invalid'], 'ip_address'],
    'actor ID' => [['actor_id' => 0], 'actor_id'],
    'page' => [['page' => 0], 'page'],
    'page size' => [['per_page' => 101], 'per_page'],
    'keyword length' => [['keyword' => str_repeat('x', 256)], 'keyword'],
    'date syntax' => [['date_from' => 'invalid'], 'date_from'],
    'reversed dates' => [['date_from' => '2026-11-18T12:00:00Z', 'date_to' => '2026-11-17T12:00:00Z'], 'date_to'],
]);

test('records 404 model binding failures for writes without snapshots or diffs', function (): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $actor = auditLogSuperAdmin();

    $this->withToken($actor->createToken('audit-missing-target')->plainTextToken)
        ->patchJson('/api/v1/admin/admins/999999', ['nickname' => 'Private missing target'])->assertNotFound();

    $log = AdminLog::sole();
    expect($log->module)->toBe('admin');
    expect($log->action)->toBe('update');
    expect($log->target_id)->toBe(999999);
    expect($log->status_code)->toBe(404);
    expect($log->success)->toBeFalse();
    expect($log->changes)->toBeNull();
    expect($log->related_changes)->toBeNull();
    expect($log->target_label)->toBeNull();
    expect($log->toJson())->not->toContain('Private missing target');
});

test('strips credentials query strings fragments and free text from menu URL audit diffs', function (): void {
    $this->seed([AdminMenuSeeder::class, AdminManagementMenuSeeder::class]);
    $actor = auditLogSuperAdmin();

    $response = $this->withToken($actor->createToken('audit-url-redaction')->plainTextToken)->postJson('/api/v1/admin/menus', [
        'type' => 'menu', 'name' => 'audit.external', 'title' => 'External audit page', 'open_type' => 'external',
        'path' => '/audit-external', 'url' => 'https://private-user:'.'audit-url-secret'.'@example.com/reports?token=private-query-token#private-fragment',
        'remark' => 'private-free-text',
    ])->assertSuccessful();

    $menu = AdminMenu::findOrFail($response->json('data.id'));
    expect($menu->url)->toContain('private-user', 'private-query-token');
    $log = AdminLog::sole();
    expect($log->changes['url'])->toBe(['before' => null, 'after' => 'https://example.com/reports']);
    expect($log->changes)->not->toHaveKey('remark');
    expect($log->toJson())->not->toContain('private-user', 'audit-url-secret', 'private-query-token', 'private-fragment', 'private-free-text');
});
