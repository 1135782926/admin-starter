<?php

use App\Models\AdminLog;
use App\Models\AdminRole;
use App\Models\AdminUser;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

test('returns 401 for personal center requests without an administrator token', function (string $method, string $path): void {
    $this->json($method, '/api/v1/admin/me/'.$path)->assertUnauthorized();
})->with([
    'read profile' => ['GET', 'profile'],
    'save profile' => ['PATCH', 'profile'],
    'change password' => ['PATCH', 'password'],
    'read personal logs' => ['GET', 'logs'],
]);

test('lists only the current administrator operations and their latest successful login', function (bool $isSuper): void {
    $admin = AdminUser::factory()->create();
    $other = AdminUser::factory()->create();
    if ($isSuper) {
        $admin->assignRole(AdminRole::findOrCreate('super-admin', 'web'));
    }
    $login = [
        'actor_id' => $admin->id,
        'module' => 'auth',
        'action' => 'login',
        'route_name' => 'admin.login',
        'method' => 'POST',
        'path' => '/api/v1/admin/auth/login',
        'status_code' => 200,
        'success' => true,
        'ip_address' => '192.0.2.12',
        'duration_ms' => 8,
        'created_at' => '2026-05-01 08:00:00',
    ];
    $ownLogin = AdminLog::create($login);
    AdminLog::create([...$login, 'actor_id' => $other->id, 'created_at' => '2026-05-01 12:00:00']);
    AdminLog::create([...$login, 'actor_id' => null, 'attempted_username' => $admin->username, 'created_at' => '2026-05-01 13:00:00']);
    $operations = [];
    for ($index = 0; $index < 9; $index++) {
        $operations[] = AdminLog::create([
            ...$login, 'module' => 'profile', 'action' => 'update', 'route_name' => 'admin.me.profile.update',
            'method' => 'PATCH', 'path' => '/api/v1/admin/me/profile', 'created_at' => '2026-05-01 09:00:00',
        ])->id;
    }
    $failedLogin = AdminLog::create([...$login, 'status_code' => 401, 'success' => false, 'created_at' => '2026-05-01 10:00:00']);
    $this->withToken($admin->createToken('personal activity')->plainTextToken);

    $page = $this->getJson('/api/v1/admin/me/logs?actor_id='.$other->id)->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.total', 11)
        ->assertJsonPath('data.current', 1)
        ->assertJsonPath('data.records.0.success', false)
        ->assertJsonPath('data.last_login_at', '2026-05-01T08:00:00.000000Z');
    expect(array_column($page->json('data.records'), 'id'))->toBe([$failedLogin->id, ...array_reverse($operations)]);

    $this->getJson('/api/v1/admin/me/logs?page=2')->assertOk()->assertExactJson([
        'code' => 200,
        'msg' => '操作成功',
        'data' => [
            'records' => [[
                'id' => $ownLogin->id,
                'module' => 'auth',
                'action' => 'login',
                'success' => true,
                'created_at' => '2026-05-01T08:00:00.000000Z',
            ]],
            'total' => 11,
            'current' => 2,
            'size' => 10,
            'last_login_at' => '2026-05-01T08:00:00.000000Z',
        ],
    ]);
})->with(['without menu permissions' => [false], 'super administrator' => [true]]);

test('returns an empty timeline and no login date for an administrator without audit history', function (): void {
    $admin = AdminUser::factory()->create();
    $this->withToken($admin->createToken('no activity')->plainTextToken);

    $this->getJson('/api/v1/admin/me/logs')->assertOk()
        ->assertJsonPath('data.records', [])
        ->assertJsonPath('data.total', 0)
        ->assertJsonPath('data.last_login_at', null);
    $this->getJson('/api/v1/admin/me/logs?page=0')->assertUnprocessable()->assertJsonValidationErrors(['page']);
});

test('an administrator without menu permissions can edit only their own profile fields', function (): void {
    $admin = AdminUser::factory()->create(['username' => 'profile-owner', 'nickname' => 'Original', 'email' => null]);
    $other = AdminUser::factory()->create(['nickname' => 'Other administrator']);
    $role = AdminRole::findOrCreate('super-admin', 'web');
    $this->withToken($admin->createToken('personal-center')->plainTextToken);

    $this->patchJson('/api/v1/admin/me/profile', [
        'nickname' => ' Updated ',
        'email' => 'personal@example.test',
        'mobile' => '13900001234',
        'signature' => 'Private signature',
        'id' => $other->id,
        'username' => 'changed-login',
        'status' => false,
        'role_ids' => [$role->id],
        'password' => 'ignored-password',
    ])->assertOk();

    $this->getJson('/api/v1/admin/me/profile')->assertOk()->assertExactJson([
        'code' => 200,
        'msg' => '操作成功',
        'data' => [
            'username' => 'profile-owner',
            'nickname' => 'Updated',
            'avatar' => null,
            'email' => 'personal@example.test',
            'mobile' => '13900001234',
            'signature' => 'Private signature',
        ],
    ]);
    expect($admin->fresh()->status)->toBeTrue();
    expect($admin->fresh()->password)->toBe($admin->password);
    expect($admin->roles()->exists())->toBeFalse();
    expect($other->fresh()->nickname)->toBe('Other administrator');

    $log = AdminLog::sole();
    expect($log->only(['module', 'action', 'target_id', 'permission_name', 'changes']))->toBe([
        'module' => 'profile',
        'action' => 'update',
        'target_id' => $admin->id,
        'permission_name' => null,
        'changes' => [
            'nickname' => ['before' => 'Original', 'after' => 'Updated'],
            'email' => ['before' => null, 'after' => 'p***@example.test'],
            'mobile' => ['before' => null, 'after' => '139****1234'],
            'signature' => ['changed' => true],
        ],
    ]);
});

test('replaces a saved avatar via multipart and allows clearing optional profile fields', function (): void {
    $disk = Storage::fake('public');
    $previous = 'admin-avatars/'.str_repeat('a', 40).'.png';
    $disk->put($previous, 'previous avatar');
    $admin = AdminUser::factory()->create(['nickname' => 'Saved nickname', 'avatar' => '/storage/'.$previous, 'signature' => 'Previous signature']);

    $response = $this->withToken($admin->createToken('avatar')->plainTextToken)
        ->withHeader('Accept', 'application/json')
        ->post('/api/v1/admin/me/profile', [
            '_method' => 'PATCH',
            'avatar' => UploadedFile::fake()->image('new.png'),
            'email' => '',
            'signature' => '',
        ])->assertOk();

    $admin->refresh();
    $response->assertJsonPath('data.avatar', url($admin->avatar))
        ->assertJsonPath('data.email', null)
        ->assertJsonPath('data.signature', null);
    $disk->assertExists(str($admin->avatar)->after('/storage/')->toString());
    $disk->assertMissing($previous);
    expect($admin->nickname)->toBe('Saved nickname');
    expect(AdminLog::sole()->changes['avatar'])->toBe(['changed' => true]);
});

test('returns 422 without changing the profile when its fields or avatar are invalid', function (): void {
    $disk = Storage::fake('public');
    $admin = AdminUser::factory()->create(['nickname' => 'Original nickname']);

    $this->withToken($admin->createToken('invalid-profile')->plainTextToken)
        ->withHeader('Accept', 'application/json')
        ->post('/api/v1/admin/me/profile', [
            '_method' => 'PATCH',
            'nickname' => ' ',
            'email' => 'invalid email',
            'avatar' => UploadedFile::fake()->create('invalid.png', 1, 'text/plain'),
        ])->assertUnprocessable()->assertJsonValidationErrors(['nickname', 'email', 'avatar']);

    expect($admin->fresh()->nickname)->toBe('Original nickname');
    expect($disk->allFiles())->toBe([]);
    expect(AdminLog::sole()->changes)->toBeNull();
});

test('changes the password, revokes both of the account tokens and logs only the password change marker', function (): void {
    $admin = AdminUser::factory()->create(['username' => 'rotating', 'password' => ' old password ']);
    $current = $admin->createToken('current device')->plainTextToken;
    $otherDevice = $admin->createToken('other device')->plainTextToken;
    $otherAccount = AdminUser::factory()->create()->createToken('unrelated device')->plainTextToken;

    $this->withToken($current)->patchJson('/api/v1/admin/me/password', [
        'current_password' => ' old password ',
        'password' => ' new password ',
        'password_confirmation' => ' new password ',
    ])->assertOk()->assertExactJson(['code' => 200, 'msg' => '操作成功', 'data' => null]);

    $log = AdminLog::sole();
    expect($log->only(['actor_id', 'target_id', 'module', 'action', 'changes']))->toBe([
        'actor_id' => $admin->id,
        'target_id' => $admin->id,
        'module' => 'profile',
        'action' => 'change_password',
        'changes' => ['password' => ['changed' => true]],
    ]);
    expect($log->toJson())->not->toContain('old password', 'new password', $admin->fresh()->password);
    $this->withToken($current)->getJson('/api/v1/admin/me')->assertUnauthorized();
    $this->withToken($otherDevice)->getJson('/api/v1/admin/me')->assertUnauthorized();
    $this->withToken($otherAccount)->getJson('/api/v1/admin/me')->assertOk();
    $this->postJson('/api/v1/admin/auth/login', ['userName' => 'rotating', 'password' => ' old password '])->assertUnauthorized();
    $this->postJson('/api/v1/admin/auth/login', ['userName' => 'rotating', 'password' => ' new password '])->assertOk()->assertJsonStructure(['data' => ['token']]);
});

test('returns 422 without changing the password or revoking a session when validation fails', function (string $current, string $new, string $confirmation, string $field, string $message): void {
    config(['hashing.driver' => 'bcrypt', 'hashing.bcrypt.limit' => null]);
    $admin = AdminUser::factory()->create(['password' => 'old-password']);
    $token = $admin->createToken('current device')->plainTextToken;

    $this->withToken($token)->patchJson('/api/v1/admin/me/password', [
        'current_password' => $current,
        'password' => $new,
        'password_confirmation' => $confirmation,
    ])->assertUnprocessable()->assertJsonPath("errors.{$field}.0", $message);

    expect($admin->fresh()->password)->toBe($admin->password);
    expect(AdminLog::sole()->changes)->toBeNull();
    $this->getJson('/api/v1/admin/me')->assertOk();
})->with([
    'wrong current password' => ['wrong-password', 'new-password', 'new-password', 'current_password', '当前密码不正确。'],
    'mismatched confirmation' => ['old-password', 'new-password', 'other-password', 'password', '两次输入的新密码不一致。'],
    'same password' => ['old-password', 'old-password', 'old-password', 'password', '新密码不能与当前密码相同。'],
    'too short' => ['old-password', 'short', 'short', 'password', '新密码至少需要 8 个字符。'],
    'above bcrypt byte limit' => ['old-password', str_repeat('密', 25), str_repeat('密', 25), 'password', '新密码不能超过 72 字节。'],
]);

test('rate limits repeated password attempts with the same token', function (): void {
    $this->freezeTime();
    $admin = AdminUser::factory()->create(['password' => 'old-password']);
    $this->withToken($admin->createToken('rate-limited')->plainTextToken);
    $payload = ['current_password' => 'wrong-password', 'password' => 'new-password', 'password_confirmation' => 'new-password'];

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->patchJson('/api/v1/admin/me/password', $payload)->assertUnprocessable();
    }
    $this->patchJson('/api/v1/admin/me/password', $payload)->assertTooManyRequests()->assertHeader('Retry-After');
});

test('preserves the password and tokens if the password change audit cannot be saved', function (): void {
    $admin = AdminUser::factory()->create(['password' => 'old-password']);
    $token = $admin->createToken('preserve session')->plainTextToken;
    $dispatcher = AdminLog::getEventDispatcher();
    AdminLog::setEventDispatcher(clone $dispatcher);
    AdminLog::creating(function (AdminLog $log): void {
        if ($log->action === 'change_password' && $log->success) {
            throw new RuntimeException('Audit write failed');
        }
    });

    try {
        $this->withToken($token)->patchJson('/api/v1/admin/me/password', [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertServerError();
    } finally {
        AdminLog::setEventDispatcher($dispatcher);
    }

    expect($admin->fresh()->password)->toBe($admin->password);
    expect(AdminLog::sole()->changes)->toBeNull();
    $this->getJson('/api/v1/admin/me')->assertOk();
});
