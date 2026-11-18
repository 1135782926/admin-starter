<?php

use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

uses(LazilyRefreshDatabase::class);

test('signs in with the trimmed administrator name and stores only a seven day token hash', function (): void {
    $this->travelTo(now()->startOfSecond());
    $admin = AdminUser::factory()->create(['username' => 'CaseSensitiveAdmin', 'password' => 'x']);

    $response = $this->postJson('/api/v1/admin/auth/login', [
        'userName' => '  CaseSensitiveAdmin  ',
        'password' => 'x',
        'expires_at' => now()->addYears(10)->toIso8601String(),
        'roles' => ['super-admin'],
    ]);

    $response->assertOk()->assertJsonPath('code', 200)->assertJsonPath('msg', '操作成功');
    expect(array_keys($response->json('data')))->toBe(['token']);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $plainText = $response->json('data.token');
    expect($plainText)->toBeString()->not->toStartWith('Bearer ');
    $token = PersonalAccessToken::findToken($plainText);
    expect($token)->not->toBeNull();
    expect($token->tokenable_type)->toBe($admin->getMorphClass());
    expect($token->tokenable_id)->toBe($admin->id);
    expect($token->token)->toBe(hash('sha256', explode('|', $plainText, 2)[1]));
    expect($token->expires_at->equalTo(now()->addDays(7)))->toBeTrue();
    expect($admin->fresh()->getRoleNames()->all())->toBe([]);
});

test('returns a uniform 401 without issuing tokens for invalid administrator credentials', function (string $case): void {
    $admin = AdminUser::factory()->create(['username' => 'administrator', 'password' => 'correct-password']);
    User::factory()->create(['name' => 'ordinary-user', 'password' => 'correct-password']);
    if ($case === 'disabled') {
        $admin->update(['status' => false]);
    }
    $username = match ($case) {
        'unknown' => 'missing-admin',
        'ordinary user' => 'ordinary-user',
        default => 'administrator',
    };

    $response = $this->postJson('/api/v1/admin/auth/login', [
        'userName' => $username,
        'password' => $case === 'wrong password' ? 'wrong-password' : 'correct-password',
    ]);

    $response->assertUnauthorized()
        ->assertJsonPath('code', 401)
        ->assertJsonPath('msg', '账号或密码错误，或账号不可用')
        ->assertJsonMissingPath('data.token');
    $this->assertDatabaseCount('personal_access_tokens', 0);
})->with(['unknown', 'wrong password', 'disabled', 'ordinary user']);

test('preserves leading and trailing password whitespace', function (string $password, int $status): void {
    AdminUser::factory()->create(['username' => 'spaces', 'password' => ' password ']);

    $response = $this->postJson('/api/v1/admin/auth/login', ['userName' => 'spaces', 'password' => $password]);

    $response->assertStatus($status);
    $this->assertDatabaseCount('personal_access_tokens', $status === 200 ? 1 : 0);
})->with([
    'exact password' => [' password ', 200],
    'trimmed password' => ['password', 401],
]);

test('returns 422 with field errors for invalid login input', function (array $payload, array $fields): void {
    $this->postJson('/api/v1/admin/auth/login', $payload)
        ->assertUnprocessable()->assertJsonPath('code', 422)->assertJsonValidationErrors($fields);

    $this->assertDatabaseCount('personal_access_tokens', 0);
})->with([
    'required credentials' => [[], ['userName', 'password']],
    'blank name' => [['userName' => '   ', 'password' => 'password'], ['userName']],
    'non string credentials' => [['userName' => [], 'password' => []], ['userName', 'password']],
    'name too long' => [['userName' => str_repeat('a', 256), 'password' => 'password'], ['userName']],
]);

test('rehashes valid administrator passwords using the current hash configuration', function (): void {
    $admin = AdminUser::factory()->create(['username' => 'rehash', 'password' => Hash::make('password', ['rounds' => 4])]);
    config(['hashing.bcrypt.rounds' => 5]);

    $this->postJson('/api/v1/admin/auth/login', ['userName' => 'rehash', 'password' => 'password'])->assertOk();

    expect(Hash::check('password', $admin->fresh()->password))->toBeTrue();
    expect(Hash::needsRehash($admin->fresh()->password))->toBeFalse();
});

test('returns 401 for missing or invalid bearer credentials on administrator endpoints', function (string $method, string $path): void {
    $this->json($method, '/api/v1/admin'.$path, [], ['Authorization' => 'Bearer invalid-token'])
        ->assertUnauthorized()->assertJsonPath('code', 401)->assertJsonStructure(['msg']);
})->with([
    'profile' => ['GET', '/me'],
    'logout' => ['POST', '/auth/logout'],
    'routes' => ['GET', '/me/menus'],
    'management tree' => ['GET', '/menus'],
    'permission tree' => ['GET', '/menus/permission-tree'],
    'create' => ['POST', '/menus'],
]);

test('returns 401 when administrator bearer credentials are absent', function (): void {
    $this->getJson('/api/v1/admin/me')->assertUnauthorized()->assertJsonPath('code', 401);
});

test('rejects a normal user bearer token even when an administrator has the same ID', function (): void {
    $admin = AdminUser::factory()->create();
    $user = User::factory()->create(['id' => $admin->id]);
    $token = $user->createToken('ordinary-user')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/admin/me')->assertUnauthorized();
    $this->withToken($token)->getJson('/api/v1/admin/me/menus')->assertUnauthorized();
    $this->withToken($token)->getJson('/api/v1/admin/menus')->assertUnauthorized();
});

test('rejects an administrator web session without a bearer token', function (): void {
    $admin = AdminUser::factory()->create();

    $this->actingAs($admin, 'web')->getJson('/api/v1/admin/me')->assertUnauthorized();
    $this->getJson('/api/v1/admin/me/menus')->assertUnauthorized();
});

test('returns the real administrator profile and an empty permission set without menu grants', function (): void {
    $admin = AdminUser::factory()->create(['username' => 'real-admin', 'nickname' => 'Different nickname', 'email' => null, 'avatar' => null]);
    $role = AdminRole::create(['name' => 'operator', 'guard_name' => 'web']);
    $admin->assignRole($role);
    $token = $admin->createToken('profile')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/admin/me')->assertOk()->assertExactJson([
        'code' => 200,
        'msg' => '操作成功',
        'data' => ['userId' => $admin->id, 'userName' => 'real-admin', 'email' => '', 'roles' => ['operator'], 'buttons' => []],
    ]);
    $this->withToken($token)->getJson('/api/v1/admin/me/menus')
        ->assertOk()->assertJsonPath('data', []);
});

test('does not extend a login token when it is used and returns 401 after seven days', function (): void {
    $this->freezeTime();
    AdminUser::factory()->create(['username' => 'expires', 'password' => 'password']);
    $plainText = $this->postJson('/api/v1/admin/auth/login', ['userName' => 'expires', 'password' => 'password'])
        ->assertOk()->json('data.token');
    $token = PersonalAccessToken::findToken($plainText);
    $expiresAt = $token->expires_at->copy();
    $this->travel(6)->days();

    $this->withToken($plainText)->getJson('/api/v1/admin/me')->assertOk();
    expect($token->fresh()->expires_at->equalTo($expiresAt))->toBeTrue();
    $this->travelTo($expiresAt->addSecond());
    $this->app['auth']->forgetGuards();
    $this->withToken($plainText)->getJson('/api/v1/admin/me')->assertUnauthorized();
    $this->withToken($plainText)->postJson('/api/v1/admin/auth/logout')->assertUnauthorized();
});

test('allows independent logins and logout revokes only the current bearer token', function (): void {
    $admin = AdminUser::factory()->create(['username' => 'terminals', 'password' => 'password']);
    $first = $this->postJson('/api/v1/admin/auth/login', ['userName' => 'terminals', 'password' => 'password'])->assertOk()->json('data.token');
    $second = $this->postJson('/api/v1/admin/auth/login', ['userName' => 'terminals', 'password' => 'password'])->assertOk()->json('data.token');
    expect($first)->not->toBe($second);
    expect($admin->tokens()->count())->toBe(2);

    $this->withToken($first)->postJson('/api/v1/admin/auth/logout')
        ->assertOk()->assertExactJson(['code' => 200, 'msg' => '操作成功', 'data' => null]);

    expect(PersonalAccessToken::findToken($first))->toBeNull();
    expect(PersonalAccessToken::findToken($second))->not->toBeNull();
    $this->app['auth']->forgetGuards();
    $this->withToken($first)->postJson('/api/v1/admin/auth/logout')->assertUnauthorized();
    $this->app['auth']->forgetGuards();
    $this->withToken($second)->getJson('/api/v1/admin/me')->assertOk();
});

test('disabling an administrator revokes every token and enabling does not restore old credentials', function (): void {
    $admin = AdminUser::factory()->create(['username' => 'disabled-admin', 'password' => 'password']);
    $first = $admin->createToken('first')->plainTextToken;
    $second = $admin->createToken('second')->plainTextToken;

    $admin->update(['status' => false]);

    expect($admin->tokens()->count())->toBe(0);
    $this->withToken($first)->getJson('/api/v1/admin/me')->assertUnauthorized();
    $admin->update(['status' => true]);
    $this->app['auth']->forgetGuards();
    $this->withToken($second)->getJson('/api/v1/admin/me')->assertUnauthorized();
    $this->postJson('/api/v1/admin/auth/login', ['userName' => 'disabled-admin', 'password' => 'password'])->assertOk();
});

test('returns 401 for a disabled administrator even if a token was issued after disabling', function (): void {
    $admin = AdminUser::factory()->disabled()->create();
    $token = $admin->createToken('disabled')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/admin/me')->assertUnauthorized();
});

test('limits a normalized administrator name and IP to five login attempts per minute', function (): void {
    $this->freezeTime();
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/api/v1/admin/auth/login', ['userName' => '  rate-limited  ', 'password' => 'wrong'])->assertUnauthorized();
    }

    $this->postJson('/api/v1/admin/auth/login', ['userName' => 'rate-limited', 'password' => 'wrong'])
        ->assertTooManyRequests()->assertJsonPath('code', 429)->assertHeader('Retry-After');
    $this->travel(61)->seconds();
    $this->postJson('/api/v1/admin/auth/login', ['userName' => 'rate-limited', 'password' => 'wrong'])->assertUnauthorized();
});

test('limits an IP to sixty login attempts even when administrator names change', function (): void {
    $this->freezeTime();
    for ($attempt = 0; $attempt < 60; $attempt++) {
        $this->postJson('/api/v1/admin/auth/login', ['userName' => 'missing-'.$attempt, 'password' => 'wrong'])->assertUnauthorized();
    }

    $this->postJson('/api/v1/admin/auth/login', ['userName' => 'another-name', 'password' => 'wrong'])
        ->assertTooManyRequests()->assertJsonPath('code', 429)->assertHeader('Retry-After');
});

test('returns the stored email and accessible avatar without exposing administrator credentials', function (): void {
    $admin = AdminUser::factory()->create([
        'email' => 'administrator@example.com',
        'avatar' => 'https://example.com/avatars/administrator.png',
    ]);

    $this->withToken($admin->createToken('populated-profile')->plainTextToken)->getJson('/api/v1/admin/me')
        ->assertOk()->assertJsonPath('data.email', 'administrator@example.com')
        ->assertJsonPath('data.avatar', 'https://example.com/avatars/administrator.png')
        ->assertJsonMissingPaths(['data.password', 'data.remember_token', 'data.token', 'data.tokens']);
});

test('a disabled super-admin cannot authenticate with an otherwise valid bearer token', function (): void {
    $admin = AdminUser::factory()->disabled()->create();
    $admin->assignRole(AdminRole::create(['name' => 'super-admin', 'guard_name' => 'web']));

    $this->withToken($admin->createToken('disabled-super')->plainTextToken)->getJson('/api/v1/admin/me')
        ->assertUnauthorized()->assertJsonPath('code', 401);
});
