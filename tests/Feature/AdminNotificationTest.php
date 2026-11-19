<?php

use App\Events\AdminNotificationsUpdated;
use App\Models\AdminUser;
use App\Notifications\AdminPasswordChanged;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

test('requires an administrator token for the inbox and channel authorization', function (string $method, string $path): void {
    $this->json($method, '/api/v1/admin/'.$path)->assertUnauthorized();
})->with([
    ['GET', 'me/notifications'],
    ['PATCH', 'me/notifications/read-all'],
    ['POST', 'broadcasting/auth'],
]);

test('paginates only the signed-in administrator notifications and counts unread records across pages', function (): void {
    $admin = AdminUser::factory()->create();
    $other = AdminUser::factory()->create();
    $this->travelTo(now()->startOfMinute());
    $admin->notify(new AdminPasswordChanged);
    $oldest = $admin->notifications()->sole();
    $oldest->markAsRead();
    $this->travel(1)->minute();
    for ($index = 0; $index < 15; $index++) {
        $admin->notify(new AdminPasswordChanged);
    }
    $other->notify(new AdminPasswordChanged);
    $this->withToken($admin->createToken('notification inbox')->plainTextToken);

    $this->getJson('/api/v1/admin/me/notifications')->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonCount(15, 'data.records')
        ->assertJsonPath('data.total', 16)
        ->assertJsonPath('data.unread_count', 15);
    $this->getJson('/api/v1/admin/me/notifications?page=2')->assertOk()
        ->assertJsonCount(1, 'data.records')
        ->assertJsonPath('data.records.0.id', $oldest->id)
        ->assertJsonPath('data.records.0.title', '登录密码已修改')
        ->assertJsonPath('data.records.0.read_at', $oldest->fresh()->read_at->toISOString())
        ->assertJsonPath('data.unread_count', 15);
});

test('marks one notification as read without touching another administrator and preserves its first read time', function (): void {
    $admin = AdminUser::factory()->create();
    $other = AdminUser::factory()->create();
    $admin->notify(new AdminPasswordChanged);
    $other->notify(new AdminPasswordChanged);
    $notice = $admin->notifications()->sole();
    $foreign = $other->notifications()->sole();
    $this->withToken($admin->createToken('mark notification')->plainTextToken);
    $this->travelTo(now()->startOfSecond());

    $this->patchJson('/api/v1/admin/me/notifications/'.$foreign->id.'/read')->assertNotFound();
    $this->patchJson('/api/v1/admin/me/notifications/'.$notice->id.'/read')->assertOk();
    $firstReadAt = $notice->fresh()->read_at;
    $this->travel(1)->minute();
    $this->patchJson('/api/v1/admin/me/notifications/'.$notice->id.'/read')->assertOk();

    expect($firstReadAt)->not->toBeNull();
    expect($notice->fresh()->read_at->toISOString())->toBe($firstReadAt->toISOString());
    expect($foreign->fresh()->read_at)->toBeNull();
});

test('marks all current notifications for the signed-in administrator only', function (): void {
    $admin = AdminUser::factory()->create();
    $other = AdminUser::factory()->create();
    $admin->notify(new AdminPasswordChanged);
    $admin->notify(new AdminPasswordChanged);
    $other->notify(new AdminPasswordChanged);

    $this->withToken($admin->createToken('mark all notifications')->plainTextToken)
        ->patchJson('/api/v1/admin/me/notifications/read-all')->assertOk();

    expect($admin->readNotifications()->count())->toBe(2);
    expect($other->unreadNotifications()->count())->toBe(1);
});

test('returns a raw private channel signature for the token owner and denies another recipient', function (): void {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'notification-test-key',
        'broadcasting.connections.reverb.secret' => 'notification-test-secret',
        'broadcasting.connections.reverb.app_id' => 'notification-test-app',
    ]);
    require base_path('routes/channels.php');
    $admin = AdminUser::factory()->create();
    $other = AdminUser::factory()->create();
    $url = '/api/v1/admin/broadcasting/auth';
    $this->withToken($admin->createToken('notification websocket')->plainTextToken);

    $channel = 'private-admin.'.$admin->id;
    $this->postJson($url, ['socket_id' => '1234.5678', 'channel_name' => $channel])->assertOk()
        ->assertExactJson(['auth' => 'notification-test-key:'.hash_hmac('sha256', '1234.5678:'.$channel, 'notification-test-secret')]);
    $this->postJson($url, ['socket_id' => '1234.5678', 'channel_name' => 'private-admin.'.$other->id])->assertForbidden();
    $this->postJson($url, ['socket_id' => 'invalid', 'channel_name' => $channel])->assertUnprocessable()
        ->assertJsonValidationErrors(['socket_id']);
});

test('queues a private notification update only after the transaction commits', function (): void {
    $admin = AdminUser::factory()->create();
    config(['broadcasting.default' => 'reverb']);
    Queue::fake([BroadcastEvent::class]);

    DB::transaction(function () use ($admin): void {
        $admin->notify(new AdminPasswordChanged);
        Queue::assertNotPushed(BroadcastEvent::class);
    });

    Queue::assertPushed(BroadcastEvent::class, function (BroadcastEvent $job) use ($admin): bool {
        return $job->event instanceof AdminNotificationsUpdated
            && $job->event->broadcastOn()->name === 'private-admin.'.$admin->id;
    });
});
