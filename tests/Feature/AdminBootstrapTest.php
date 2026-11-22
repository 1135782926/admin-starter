<?php

use App\Models\AdminMenu;
use App\Models\AdminPermission;
use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\SystemMenuSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(LazilyRefreshDatabase::class);

test('the shared system menu seeder installs member permissions and preserves existing menu configuration when rerun', function (): void {
    $this->seed(SystemMenuSeeder::class);

    $memberMenu = AdminMenu::query()->where('name', 'system.member')->sole();
    $memberMenu->update(['title' => '业务会员']);
    $permission = AdminPermission::query()->where('name', 'system.member.manage_groups')->sole();
    $role = AdminRole::create(['name' => 'member-manager', 'guard_name' => 'web']);
    $role->givePermissionTo($permission);

    $this->seed(SystemMenuSeeder::class);

    expect(AdminMenu::query()->whereIn('name', [
        'system.menu', 'system.admin', 'system.role', 'system.log', 'system.attachment', 'system.member',
    ])->count())->toBe(6)
        ->and($memberMenu->fresh()->title)->toBe('业务会员')
        ->and($role->fresh()->permissions->modelKeys())->toBe([$permission->id]);
    $this->assertDatabaseCount('admin_users', 0);
});

test('production seeding initializes system menus without provision of demo accounts', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');

    $this->artisan('db:seed', ['--force' => true, '--no-interaction' => true])->assertSuccessful();
    $this->artisan('db:seed', ['--class' => AdminUserSeeder::class, '--force' => true, '--no-interaction' => true])->assertSuccessful();

    $this->assertDatabaseHas('admin_menus', ['name' => 'system.member']);
    $this->assertDatabaseCount('admin_users', 0);
    $this->assertDatabaseCount('admin_roles', 0);
    $this->assertDatabaseCount((new User)->getTable(), 0);
});

test('demo seeding preserves the roles of an existing administrator named admin', function (): void {
    $admin = AdminUser::factory()->create(['username' => 'admin']);

    $this->seed(AdminUserSeeder::class);

    expect($admin->fresh()->getRoleNames()->all())->toBe([]);
});

test('bootstraps a super administrator in production and refuses a second bootstrap', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    $this->artisan('db:seed', ['--class' => SystemMenuSeeder::class, '--force' => true, '--no-interaction' => true])->assertSuccessful();
    $password = ' production-admin-secret ';

    $this->artisan('admin:bootstrap', ['username' => 'site-owner'])
        ->expectsQuestion('New password', $password)
        ->expectsQuestion('Confirm new password', $password)
        ->doesntExpectOutputToContain($password)
        ->assertSuccessful();

    $admin = AdminUser::query()->where('username', 'site-owner')->sole();
    expect($admin->status)->toBeTrue()
        ->and($admin->getRoleNames()->all())->toBe(['super-admin'])
        ->and(Hash::check($password, $admin->password))->toBeTrue();
    $token = $this->postJson('/api/v1/admin/auth/login', ['userName' => $admin->username, 'password' => $password])
        ->assertOk()->json('data.token');
    $this->withToken($token)->getJson('/api/v1/admin/me/menus')
        ->assertOk()->assertJsonFragment(['name' => 'system.member']);

    $this->artisan('admin:bootstrap', ['username' => 'second-owner'])
        ->expectsQuestion('New password', $password)
        ->expectsQuestion('Confirm new password', $password)
        ->expectsOutputToContain('An enabled super administrator already exists.')
        ->assertFailed();

    $this->assertDatabaseCount('admin_users', 1);
    expect($admin->fresh()->password)->toBe($admin->password);
});

test('refuses to overwrite or promote an existing login during bootstrap', function (): void {
    $admin = AdminUser::factory()->create();

    $this->artisan('admin:bootstrap', ['username' => $admin->username])
        ->expectsQuestion('New password', 'bootstrap-password')
        ->expectsQuestion('Confirm new password', 'bootstrap-password')
        ->expectsOutput('That administrator username is already in use.')
        ->assertFailed();

    expect($admin->fresh()->password)->toBe($admin->password)
        ->and($admin->fresh()->getRoleNames()->all())->toBe([]);
    $this->assertDatabaseCount('admin_roles', 0);
});

test('recovers an enabled administrator password and revokes only that account tokens', function (): void {
    $admin = AdminUser::factory()->create(['password' => 'old-password', 'remember_token' => 'old-remember-token']);
    $role = AdminRole::findOrCreate('super-admin', 'web');
    $admin->assignRole($role);
    $token = $admin->createToken('old session')->plainTextToken;
    $other = AdminUser::factory()->create();
    $otherToken = $other->createToken('unrelated session')->plainTextToken;

    $this->artisan('admin:bootstrap', ['username' => $admin->username, '--reset-password' => true])
        ->expectsQuestion('New password', 'recovered-password')
        ->expectsQuestion('Confirm new password', 'recovered-password')
        ->assertSuccessful();

    expect($admin->fresh()->getRawOriginal('remember_token'))->toBeNull()
        ->and($admin->fresh()->getRoleNames()->all())->toBe(['super-admin']);
    $this->withToken($token)->getJson('/api/v1/admin/me')->assertUnauthorized();
    $this->withToken($otherToken)->getJson('/api/v1/admin/me')->assertOk();
    $this->postJson('/api/v1/admin/auth/login', ['userName' => $admin->username, 'password' => 'old-password'])->assertUnauthorized();
    $this->postJson('/api/v1/admin/auth/login', ['userName' => $admin->username, 'password' => 'recovered-password'])->assertOk();
});

test('refuses to recover a disabled administrator', function (): void {
    $admin = AdminUser::factory()->create(['status' => false]);

    $this->artisan('admin:bootstrap', ['username' => $admin->username, '--reset-password' => true])
        ->expectsQuestion('New password', 'recovered-password')
        ->expectsQuestion('Confirm new password', 'recovered-password')
        ->expectsOutput('No enabled administrator has that username.')
        ->assertFailed();

    expect($admin->fresh()->password)->toBe($admin->password)
        ->and($admin->fresh()->status)->toBeFalse();
});

test('rejects invalid bootstrap passwords without creating accounts', function (string $password, string $confirmation, string $message): void {
    config(['hashing.driver' => 'bcrypt']);

    $this->artisan('admin:bootstrap', ['username' => 'site-owner'])
        ->expectsQuestion('New password', $password)
        ->expectsQuestion('Confirm new password', $confirmation)
        ->expectsOutputToContain($message)
        ->assertFailed();

    $this->assertDatabaseCount('admin_users', 0);
})->with([
    'too short' => ['short', 'short', 'at least 12 characters'],
    'confirmation mismatch' => ['bootstrap-password', 'different-password', 'confirmation does not match'],
    'above bcrypt byte limit' => [str_repeat('密', 25), str_repeat('密', 25), 'must not exceed 72 bytes'],
]);

test('refuses to bootstrap without interactive input', function (): void {
    $this->artisan('admin:bootstrap', ['username' => 'site-owner', '--no-interaction' => true])
        ->expectsOutput('Run this command interactively to enter the password.')
        ->assertFailed();

    $this->assertDatabaseCount('admin_users', 0);
});
