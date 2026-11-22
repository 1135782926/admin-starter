<?php

use App\Models\AdminMenu;
use App\Models\AdminPermission;
use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(LazilyRefreshDatabase::class);

test('hashes administrator passwords before storing them', function (): void {
    $admin = AdminUser::factory()->create(['password' => 'admin-test-password']);

    expect(Hash::check('admin-test-password', $admin->fresh()->password))->toBeTrue();
});

test('rejects duplicate administrator login names', function (): void {
    AdminUser::factory()->create(['username' => 'administrator']);

    expect(fn () => AdminUser::factory()->create(['username' => 'administrator']))
        ->toThrow(QueryException::class);
});

test('returns administrator profile without credentials for a valid API token', function (): void {
    $admin = AdminUser::factory()->create([
        'username' => 'administrator',
        'nickname' => '管理员',
        'avatar' => 'avatars/admin.png',
        'email' => 'admin@example.com',
        'mobile' => '+8613800000000',
        'signature' => '管理员签名',
    ]);
    $token = $admin->createToken('admin-test')->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/user');

    $response->assertOk()
        ->assertJsonPath('id', $admin->id)
        ->assertJsonPath('username', 'administrator')
        ->assertJsonPath('nickname', '管理员')
        ->assertJsonPath('avatar', 'avatars/admin.png')
        ->assertJsonPath('email', 'admin@example.com')
        ->assertJsonPath('mobile', '+8613800000000')
        ->assertJsonPath('signature', '管理员签名')
        ->assertJsonMissingPaths(['password', 'remember_token']);
});

test('keeps administrator role permissions separate from users with the same ID', function (): void {
    $admin = AdminUser::factory()->create();
    $user = User::factory()->create(['id' => $admin->id]);
    $permission = AdminPermission::create(['name' => 'admin-users.manage', 'guard_name' => 'web']);
    $role = AdminRole::create(['name' => 'administrator', 'guard_name' => 'web']);
    $role->givePermissionTo($permission);

    $admin->assignRole($role);

    expect($admin->getRoleNames()->all())->toBe(['administrator']);
    expect($admin->can('admin-users.manage'))->toBeTrue();
    expect($user->can('admin-users.manage'))->toBeFalse();
});

test('default database seeding provides admin login and preserves a changed password on repeat', function (): void {
    $this->seed();
    $admin = AdminUser::query()->where('username', 'admin')->firstOrFail();

    expect(AdminMenu::query()->where('name', 'system.menu')->exists())->toBeTrue();
    expect($admin->status)->toBeTrue()
        ->and($admin->hasRole('super-admin'))->toBeTrue()
        ->and(Hash::check('admin', $admin->password))->toBeTrue();

    $this->postJson('/api/v1/admin/auth/login', ['userName' => 'admin', 'password' => 'admin'])
        ->assertOk()
        ->assertJsonPath('code', 200)
        ->assertJsonStructure(['data' => ['token']]);

    $admin->update(['password' => 'changed-admin-password']);
    $this->seed();

    expect(AdminUser::query()->where('username', 'admin')->count())->toBe(1)
        ->and(User::query()->where('email', 'test@example.com')->count())->toBe(1)
        ->and(Hash::check('changed-admin-password', $admin->fresh()->password))->toBeTrue();
});
