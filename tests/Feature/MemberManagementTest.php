<?php

use App\Models\AdminLog;
use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\Member;
use App\Models\MemberGroup;
use Database\Seeders\MemberManagementMenuSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(MemberManagementMenuSeeder::class);
});

function memberManagementAdmin(): AdminUser
{
    $actor = AdminUser::factory()->create();
    $actor->assignRole(AdminRole::findOrCreate('super-admin', 'web'));

    return $actor;
}

test('requires an administrator token to list members', function (): void {
    $this->getJson('/api/v1/admin/members')->assertUnauthorized();
});

test('enforces separate member and group write permissions', function (string $method, string $path): void {
    $actor = AdminUser::factory()->create();
    $actor->givePermissionTo('system.member.access', 'system.member.view');
    $member = Member::factory()->create();
    $path = strtr($path, ['{member}' => $member->id, '{group}' => $member->member_group_id]);

    $this->withToken($actor->createToken('member-reader')->plainTextToken)
        ->json($method, '/api/v1/admin/'.$path)->assertForbidden();
})->with([
    'create member' => ['POST', 'members'],
    'edit member' => ['PATCH', 'members/{member}'],
    'create group' => ['POST', 'member-groups'],
    'rename group' => ['PATCH', 'member-groups/{group}'],
]);

test('shows the default group and exposes granted member actions in the menu', function (): void {
    $actor = memberManagementAdmin();
    $group = MemberGroup::where('name', '默认分组')->sole();
    $this->withToken($actor->createToken('member-menu')->plainTextToken);

    $this->getJson('/api/v1/admin/member-groups')->assertOk()
        ->assertJsonPath('data.0', ['id' => $group->id, 'name' => '默认分组']);
    $this->getJson('/api/v1/admin/me/menus')->assertOk()->assertJsonFragment(['path' => '/system/member'])
        ->assertJsonFragment(['authMark' => 'manage_groups']);
});

test('creates a member and records a masked audit of the supplied details', function (): void {
    $actor = memberManagementAdmin();
    $group = MemberGroup::where('name', '默认分组')->sole();
    $password = 'member-test-password';

    $response = $this->withToken($actor->createToken('member-create')->plainTextToken)->postJson('/api/v1/admin/members', [
        'username' => 'member-lin',
        'nickname' => '小林',
        'member_group_id' => $group->id,
        'email' => 'member@example.com',
        'mobile' => '13812345678',
        'gender' => 'female',
        'birthday' => '1999-09-21',
        'signature' => '会员签名',
        'password' => $password,
    ]);

    $response->assertCreated()->assertJsonPath('data.group.name', '默认分组')
        ->assertJsonPath('data.birthday', '1999-09-21')->assertJsonPath('data.status', true)
        ->assertJsonMissingPath('data.password');
    $member = Member::findOrFail($response->json('data.id'));
    expect(Hash::check($password, $member->password))->toBeTrue();
    $audit = AdminLog::where('module', 'member')->where('target_id', $member->id)->sole();
    expect($audit->changes['password'])->toBe(['changed' => true])
        ->and($audit->changes['birthday'])->toBe(['changed' => true])
        ->and($audit->changes['email']['after'])->toBe('m***@example.com')
        ->and($audit->changes['mobile']['after'])->toBe('138****5678');
});

test('can disable a member and clear optional text without resetting an omitted password', function (): void {
    $actor = memberManagementAdmin();
    $member = Member::factory()->create(['birthday' => '2001-01-01', 'signature' => '旧签名']);
    $passwordHash = $member->password;

    $this->withToken($actor->createToken('member-update')->plainTextToken)
        ->patchJson('/api/v1/admin/members/'.$member->id, ['status' => false, 'birthday' => null, 'signature' => null])
        ->assertOk()->assertJsonPath('data.status', false)->assertJsonPath('data.birthday', null)
        ->assertJsonPath('data.signature', null)->assertJsonMissingPath('data.password');

    expect($member->fresh()->password)->toBe($passwordHash);
});

test('filters and paginates members by keyword, group, and disabled status together', function (): void {
    $actor = memberManagementAdmin();
    $group = MemberGroup::factory()->create();
    $members = Member::factory()->disabled()->count(2)->create(['member_group_id' => $group->id, 'nickname' => '筛选测试会员']);
    Member::factory()->create(['member_group_id' => $group->id, 'nickname' => '筛选测试会员']);
    Member::factory()->disabled()->create(['nickname' => '筛选测试会员']);
    Member::factory()->disabled()->create(['member_group_id' => $group->id, 'nickname' => '其他昵称']);
    $query = http_build_query(['member_group_id' => $group->id, 'keyword' => '筛选测试', 'status' => 0, 'per_page' => 1, 'page' => 2]);

    $this->withToken($actor->createToken('member-filter')->plainTextToken)->getJson('/api/v1/admin/members?'.$query)
        ->assertOk()->assertJsonPath('data.total', 2)->assertJsonPath('data.current', 2)->assertJsonPath('data.size', 1)
        ->assertJsonPath('data.records.0.id', $members->first()->id)->assertJsonPath('data.records.0.status', false);
});

test('rejects duplicate usernames, missing groups, and passwords above the byte limit', function (): void {
    $actor = memberManagementAdmin();
    $existing = Member::factory()->create();

    $this->withToken($actor->createToken('member-validation')->plainTextToken)->postJson('/api/v1/admin/members', [
        'username' => $existing->username,
        'nickname' => '新会员',
        'member_group_id' => $existing->member_group_id + 1000,
        'password' => str_repeat('密', 25),
    ])->assertUnprocessable()->assertJsonValidationErrors(['username', 'member_group_id', 'password']);

    $this->assertDatabaseCount('members', 1);
});

test('replaces an avatar via multipart update and clears it with explicit null', function (): void {
    Storage::fake('public');
    $actor = memberManagementAdmin();
    $previous = 'member-avatars/'.str_repeat('a', 40).'.png';
    Storage::disk('public')->put($previous, 'previous avatar contents');
    $member = Member::factory()->create(['avatar' => '/storage/'.$previous]);
    $url = '/api/v1/admin/members/'.$member->id;
    $file = UploadedFile::fake()->createWithContent('avatar.png', base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        true,
    ));

    $this->withToken($actor->createToken('member-avatar')->plainTextToken)
        ->post($url, ['_method' => 'PATCH', 'avatar' => $file], ['Accept' => 'application/json'])->assertOk();
    $newPath = str($member->fresh()->avatar)->after('/storage/')->toString();
    Storage::disk('public')->assertExists($newPath);
    Storage::disk('public')->assertMissing($previous);

    $this->patchJson($url, ['avatar' => null])->assertOk()->assertJsonPath('data.avatar', null);
    Storage::disk('public')->assertMissing($newPath);
});

test('renames a member group and reflects its updated name on existing members', function (): void {
    $actor = memberManagementAdmin();
    $this->withToken($actor->createToken('member-groups')->plainTextToken);
    $groupId = $this->postJson('/api/v1/admin/member-groups', ['name' => '普通会员'])->assertCreated()->json('data.id');
    $member = Member::factory()->create(['member_group_id' => $groupId]);

    $this->patchJson('/api/v1/admin/member-groups/'.$groupId, ['name' => '正式会员'])
        ->assertOk()->assertJsonPath('data.name', '正式会员');

    $this->getJson('/api/v1/admin/members/'.$member->id)
        ->assertOk()->assertJsonPath('data.group', ['id' => $groupId, 'name' => '正式会员']);
    $audit = AdminLog::where('module', 'member_group')->where('action', 'update')->sole();
    expect($audit->target_label)->toBe('正式会员')
        ->and($audit->changes['name'])->toBe(['before' => '普通会员', 'after' => '正式会员']);
});
