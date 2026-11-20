<?php

use App\Models\AdminLog;
use App\Models\AdminMenu;
use App\Models\AdminPermission;
use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\Attachment;
use Database\Seeders\AttachmentManagementMenuSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

uses(LazilyRefreshDatabase::class);

function attachmentSuperAdmin(): AdminUser
{
    $admin = AdminUser::factory()->create();
    $admin->assignRole(AdminRole::findOrCreate('super-admin', 'web'));

    return $admin;
}

function attachmentUpload(string $name, string $content): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'attachment-test-');
    if ($path === false || file_put_contents($path, $content) === false) {
        throw new RuntimeException('Unable to create upload fixture.');
    }

    return new UploadedFile($path, $name, test: true);
}

function attachmentPng(string $name = 'pixel.png'): UploadedFile
{
    return attachmentUpload($name, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        true,
    ));
}

test('seeds a top-level attachment menu and five permissions idempotently without accounts or roles', function (): void {
    $this->seed(AttachmentManagementMenuSeeder::class);
    $this->seed(AttachmentManagementMenuSeeder::class);

    $menu = AdminMenu::where('name', 'system.attachment')->sole();
    expect($menu->parent_id)->toBeNull()
        ->and($menu->title)->toBe('附件管理')
        ->and($menu->path)->toBe('/system/attachment')
        ->and($menu->component)->toBe('/system/attachment/index')
        ->and($menu->icon)->toBe('ri:attachment-2');
    expect(AdminMenu::where('parent_id', $menu->id)->orderBy('id')->pluck('name')->all())
        ->toBe(['system.attachment.view', 'system.attachment.create', 'system.attachment.update', 'system.attachment.delete']);
    expect(AdminPermission::where('name', 'like', 'system.attachment.%')->pluck('name'))->toHaveCount(5);
    $this->assertDatabaseCount('admin_users', 0);
    $this->assertDatabaseCount('admin_roles', 0);
});

test('returns 401 for attachment requests without an administrator bearer token', function (): void {
    $this->seed(AttachmentManagementMenuSeeder::class);

    $this->getJson('/api/v1/admin/attachments')->assertUnauthorized()->assertJsonPath('code', 401);
    $this->post('/api/v1/admin/attachments', ['file' => attachmentPng()])
        ->assertUnauthorized()->assertJsonPath('code', 401);
    $this->assertDatabaseCount('attachments', 0);
});

test('returns 403 for each attachment operation without its button permission', function (string $method, string $suffix): void {
    $this->seed(AttachmentManagementMenuSeeder::class);
    $actor = AdminUser::factory()->create();
    $actor->givePermissionTo('system.attachment.access');
    $attachment = Attachment::factory()->create();

    $this->withToken($actor->createToken('attachment-denied')->plainTextToken)
        ->json($method, '/api/v1/admin/attachments'.str_replace('{id}', (string) $attachment->id, $suffix), [
            'confirmed' => true,
        ])->assertForbidden()->assertJsonPath('code', 403);
})->with([
    'list' => ['GET', ''],
    'detail' => ['GET', '/{id}'],
    'upload' => ['POST', ''],
    'update' => ['PATCH', '/{id}'],
    'delete' => ['DELETE', '/{id}'],
    'temporary URL' => ['GET', '/{id}/temporary-url'],
]);

test('returns 403 when an operation grant lacks attachment page access', function (): void {
    $this->seed(AttachmentManagementMenuSeeder::class);
    $actor = AdminUser::factory()->create();
    $actor->givePermissionTo('system.attachment.view');

    $this->withToken($actor->createToken('attachment-no-access')->plainTextToken)
        ->getJson('/api/v1/admin/attachments')->assertForbidden();
});

test('stores a private image by default with complete metadata and uploader', function (): void {
    Storage::fake('local');
    Storage::fake('public');
    $this->seed(AttachmentManagementMenuSeeder::class);
    $this->travelTo('2026-11-18 12:34:56');
    $actor = attachmentSuperAdmin();
    $file = attachmentPng('私有像素.png');
    $content = file_get_contents($file->getRealPath());

    $response = $this->withToken($actor->createToken('private-image')->plainTextToken)->post('/api/v1/admin/attachments', [
        'file' => $file,
        'title' => '私有图片',
        'category' => 'products',
    ]);

    $response->assertCreated()->assertJsonPath('code', 200)
        ->assertJsonPath('data.title', '私有图片')
        ->assertJsonPath('data.original_name', '私有像素.png')
        ->assertJsonPath('data.category', 'products')
        ->assertJsonPath('data.extension', 'png')
        ->assertJsonPath('data.mime_type', 'image/png')
        ->assertJsonPath('data.size', strlen($content))
        ->assertJsonPath('data.sha256', hash('sha256', $content))
        ->assertJsonPath('data.width', 1)->assertJsonPath('data.height', 1)
        ->assertJsonPath('data.disk', 'local')->assertJsonPath('data.visibility', 'private')
        ->assertJsonPath('data.is_image', true)->assertJsonPath('data.url', null)
        ->assertJsonPath('data.uploader.id', $actor->id)
        ->assertJsonPath('data.uploader.username', $actor->username)
        ->assertJsonPath('data.uploader.nickname', $actor->nickname)
        ->assertJsonPath('data.created_at', '2026-11-18 12:34:56')
        ->assertJsonPath('data.updated_at', '2026-11-18 12:34:56');
    $attachment = Attachment::sole();
    expect($attachment->object_key)->toStartWith('attachments/202611/')->toEndWith('.png');
    Storage::disk('local')->assertExists($attachment->object_key);
    expect(Storage::disk('local')->get($attachment->object_key))->toBe($content);
    Storage::disk('public')->assertEmpty();
});

test('stores an explicitly public image on the public disk with a long-term URL', function (): void {
    Storage::fake('local');
    Storage::fake('public');
    $this->seed(AttachmentManagementMenuSeeder::class);
    $actor = attachmentSuperAdmin();

    $response = $this->withToken($actor->createToken('public-image')->plainTextToken)->post('/api/v1/admin/attachments', [
        'file' => attachmentPng(), 'visibility' => 'public',
    ]);

    $response->assertCreated()->assertJsonPath('data.disk', 'public')
        ->assertJsonPath('data.visibility', 'public')->assertJsonPath('data.is_image', true)
        ->assertJsonPath('data.category', 'default');
    $attachment = Attachment::sole();
    expect($response->json('data.url'))->toBe(Storage::disk('public')->url($attachment->object_key));
    Storage::disk('public')->assertExists($attachment->object_key);
    Storage::disk('local')->assertEmpty();
});

test('stores duplicate content as independent records and files without deduplication', function (): void {
    Storage::fake('local');
    Storage::fake('public');
    $this->seed(AttachmentManagementMenuSeeder::class);
    $actor = attachmentSuperAdmin();
    $token = $actor->createToken('duplicate-content')->plainTextToken;

    $first = $this->withToken($token)->post('/api/v1/admin/attachments', ['file' => attachmentPng()])->assertCreated();
    $second = $this->post('/api/v1/admin/attachments', ['file' => attachmentPng()])->assertCreated();

    expect($first->json('data.sha256'))->toBe($second->json('data.sha256'));
    expect($first->json('data.object_key'))->not->toBe($second->json('data.object_key'));
    expect($first->json('data.id'))->not->toBe($second->json('data.id'));
    $this->assertDatabaseCount('attachments', 2);
    Storage::disk('local')->assertExists([$first->json('data.object_key'), $second->json('data.object_key')]);
});

test('returns 422 when the original extension disagrees with the real MIME type', function (): void {
    Storage::fake('local');
    $this->seed(AttachmentManagementMenuSeeder::class);
    $actor = attachmentSuperAdmin();

    $this->withToken($actor->createToken('mime-mismatch')->plainTextToken)->post('/api/v1/admin/attachments', [
        'file' => attachmentPng('disguised.txt'),
    ])->assertUnprocessable()->assertJsonValidationErrors(['file']);

    $this->assertDatabaseCount('attachments', 0);
    Storage::disk('local')->assertEmpty();
});

test('returns 422 for a corrupt image that passes MIME validation', function (): void {
    Storage::fake('local');
    $this->seed(AttachmentManagementMenuSeeder::class);
    $actor = attachmentSuperAdmin();

    $this->withToken($actor->createToken('corrupt-image')->plainTextToken)->post('/api/v1/admin/attachments', [
        'file' => attachmentUpload('broken.webp', 'RIFF'.pack('V', 4).'WEBP'),
    ])->assertUnprocessable()->assertJsonValidationErrors(['file']);

    $this->assertDatabaseCount('attachments', 0);
    Storage::disk('local')->assertEmpty();
});

test('returns 422 for SVG and executable uploads without persisting them', function (string $name, string $content): void {
    Storage::fake('local');
    $this->seed(AttachmentManagementMenuSeeder::class);
    $actor = attachmentSuperAdmin();

    $this->withToken($actor->createToken('unsafe-upload')->plainTextToken)->post('/api/v1/admin/attachments', [
        'file' => attachmentUpload($name, $content),
    ])->assertUnprocessable()->assertJsonValidationErrors(['file']);

    $this->assertDatabaseCount('attachments', 0);
    Storage::disk('local')->assertEmpty();
})->with([
    'SVG' => ['vector.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
    'PHP executable' => ['shell.php', '<?php echo "unsafe";'],
    'executable content disguised as text' => ['notes.txt', '<?php echo "unsafe";'],
]);

test('returns 422 for files larger than 50 MB', function (): void {
    Storage::fake('local');
    $this->seed(AttachmentManagementMenuSeeder::class);
    $actor = attachmentSuperAdmin();
    $file = UploadedFile::fake()->create('too-large.txt', 50 * 1024 + 1, 'text/plain');

    $this->withToken($actor->createToken('oversized')->plainTextToken)->post('/api/v1/admin/attachments', [
        'file' => $file,
    ])->assertUnprocessable()->assertJsonValidationErrors(['file']);

    $this->assertDatabaseCount('attachments', 0);
    Storage::disk('local')->assertEmpty();
});

test('returns 422 rather than making a non-image attachment public', function (): void {
    Storage::fake('local');
    Storage::fake('public');
    $this->seed(AttachmentManagementMenuSeeder::class);
    $actor = attachmentSuperAdmin();

    $this->withToken($actor->createToken('public-text')->plainTextToken)->post('/api/v1/admin/attachments', [
        'file' => attachmentUpload('private.txt', 'private text'), 'visibility' => 'public',
    ])->assertUnprocessable()->assertJsonValidationErrors(['visibility']);

    $this->assertDatabaseCount('attachments', 0);
    Storage::disk('local')->assertEmpty();
    Storage::disk('public')->assertEmpty();
});

test('filters the shared attachment library by every supported list field', function (): void {
    Storage::fake('local');
    $this->seed(AttachmentManagementMenuSeeder::class);
    config(['app.timezone' => 'UTC']);
    $actor = attachmentSuperAdmin();
    $otherActor = AdminUser::factory()->create();
    $matching = Attachment::factory()->create([
        'title' => 'Needle report', 'original_name' => 'needle.txt', 'category' => 'reports',
        'visibility' => 'private', 'admin_user_id' => $actor->id, 'created_at' => '2026-11-18 12:00:00',
    ]);
    Attachment::factory()->create(['title' => 'Other', 'category' => 'reports', 'admin_user_id' => $actor->id, 'created_at' => '2026-11-18 12:00:00']);
    Attachment::factory()->create(['title' => 'Needle other category', 'category' => 'other', 'admin_user_id' => $actor->id, 'created_at' => '2026-11-18 12:00:00']);
    Attachment::factory()->image()->public()->create(['title' => 'Needle public', 'category' => 'reports', 'admin_user_id' => $actor->id, 'created_at' => '2026-11-18 12:00:00']);
    Attachment::factory()->create(['title' => 'Needle other actor', 'category' => 'reports', 'admin_user_id' => $otherActor->id, 'created_at' => '2026-11-18 12:00:00']);
    Attachment::factory()->create(['title' => 'Needle old', 'category' => 'reports', 'admin_user_id' => $actor->id, 'created_at' => '2026-11-17 12:00:00']);
    $filters = http_build_query([
        'keyword' => 'needle', 'category' => 'reports', 'type' => 'text', 'visibility' => 'private',
        'admin_user_id' => $actor->id, 'date_from' => '2026-11-18T00:00:00Z',
        'date_to' => '2026-11-18T23:59:59Z', 'per_page' => 1,
    ]);

    $this->withToken($actor->createToken('attachment-filters')->plainTextToken)
        ->getJson('/api/v1/admin/attachments?'.$filters)->assertOk()
        ->assertJsonPath('data.total', 1)->assertJsonPath('data.current', 1)->assertJsonPath('data.size', 1)
        ->assertJsonPath('data.records.0.id', $matching->id)
        ->assertJsonPath('data.records.0.uploader.id', $actor->id);

    $this->getJson('/api/v1/admin/attachments?keyword='.urlencode("' OR 1=1 --"))
        ->assertOk()->assertJsonPath('data.total', 0);
});

test('lists attachments newest first with a stable order for equal timestamps', function (): void {
    $this->seed(AttachmentManagementMenuSeeder::class);
    $actor = attachmentSuperAdmin();
    $first = Attachment::factory()->create(['created_at' => '2026-11-18 12:00:00']);
    $second = Attachment::factory()->create(['created_at' => '2026-11-18 12:00:00']);
    $older = Attachment::factory()->create(['created_at' => '2026-11-17 12:00:00']);

    $this->withToken($actor->createToken('attachment-default-list')->plainTextToken)
        ->getJson('/api/v1/admin/attachments')->assertOk()
        ->assertJsonPath('data.current', 1)->assertJsonPath('data.size', 20)
        ->assertJsonPath('data.records.0.id', $second->id)
        ->assertJsonPath('data.records.1.id', $first->id)
        ->assertJsonPath('data.records.2.id', $older->id);
});

test('preserves literal text in attachment filters', function (string $field, string $value, string $otherValue): void {
    $this->seed(AttachmentManagementMenuSeeder::class);
    $actor = attachmentSuperAdmin();
    $column = $field === 'keyword' ? 'title' : 'category';
    $matching = Attachment::factory()->create([$column => $value]);
    Attachment::factory()->create([$column => $otherValue]);

    $this->withToken($actor->createToken('attachment-text-filter')->plainTextToken)
        ->getJson('/api/v1/admin/attachments?'.http_build_query([$field => $value]))
        ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.records.0.id', $matching->id);
})->with([
    'keyword containing a comma' => ['keyword', 'annual, draft', 'annual'],
    'category containing a comma' => ['category', 'reports, drafts', 'reports'],
    'keyword that looks like a boolean' => ['keyword', 'true', '1'],
    'category that looks like a boolean' => ['category', 'false', '0'],
]);

test('maps each list type filter to its attachment formats', function (string $type, string $extension): void {
    Storage::fake('local');
    $this->seed(AttachmentManagementMenuSeeder::class);
    $actor = attachmentSuperAdmin();
    $expected = Attachment::factory()->create(['extension' => $extension, 'is_image' => $type === 'image']);
    foreach (['png', 'pdf', 'zip', 'txt'] as $otherExtension) {
        if ($otherExtension !== $extension) {
            Attachment::factory()->create(['extension' => $otherExtension, 'is_image' => $otherExtension === 'png']);
        }
    }

    $this->withToken($actor->createToken('attachment-type')->plainTextToken)
        ->getJson('/api/v1/admin/attachments?type='.$type)->assertOk()
        ->assertJsonPath('data.total', 1)->assertJsonPath('data.records.0.id', $expected->id);
})->with([
    'images' => ['image', 'png'],
    'documents' => ['document', 'pdf'],
    'archives' => ['archive', 'zip'],
    'text' => ['text', 'txt'],
]);

test('updates only title and category and rejects file or visibility changes with 422', function (): void {
    Storage::fake('local');
    $this->seed(AttachmentManagementMenuSeeder::class);
    $actor = attachmentSuperAdmin();
    $attachment = Attachment::factory()->create(['title' => 'Before', 'category' => 'before']);
    Storage::disk('local')->put($attachment->object_key, 'unchanged content');
    $original = $attachment->only(['original_name', 'object_key', 'visibility', 'sha256']);

    $this->withToken($actor->createToken('attachment-update')->plainTextToken)
        ->patchJson('/api/v1/admin/attachments/'.$attachment->id, ['title' => 'After', 'category' => 'after'])
        ->assertOk()->assertJsonPath('data.title', 'After')->assertJsonPath('data.category', 'after');
    expect($attachment->fresh()->only(array_keys($original)))->toBe($original);
    expect(Storage::disk('local')->get($attachment->object_key))->toBe('unchanged content');

    $this->patchJson('/api/v1/admin/attachments/'.$attachment->id, ['visibility' => 'public', 'object_key' => 'changed'])
        ->assertUnprocessable()->assertJsonValidationErrors(['visibility', 'object_key']);
    expect($attachment->fresh()->only(array_keys($original)))->toBe($original);
});

test('deletes the physical file and database record directly after confirmation', function (): void {
    Storage::fake('local');
    $this->seed(AttachmentManagementMenuSeeder::class);
    $actor = attachmentSuperAdmin();
    $attachment = Attachment::factory()->create();
    Storage::disk('local')->put($attachment->object_key, 'delete me');

    $this->withToken($actor->createToken('attachment-delete')->plainTextToken)
        ->deleteJson('/api/v1/admin/attachments/'.$attachment->id, ['confirmed' => true])
        ->assertOk()->assertJsonPath('data', null);

    $this->assertModelMissing($attachment);
    Storage::disk('local')->assertMissing($attachment->object_key);
});

test('returns 422 for unconfirmed deletion without deleting the file or record', function (): void {
    Storage::fake('local');
    $this->seed(AttachmentManagementMenuSeeder::class);
    $actor = attachmentSuperAdmin();
    $attachment = Attachment::factory()->create();
    Storage::disk('local')->put($attachment->object_key, 'keep me');

    $this->withToken($actor->createToken('attachment-unconfirmed')->plainTextToken)
        ->deleteJson('/api/v1/admin/attachments/'.$attachment->id, ['confirmed' => false])
        ->assertUnprocessable()->assertJsonValidationErrors(['confirmed']);

    $this->assertModelExists($attachment);
    Storage::disk('local')->assertExists($attachment->object_key);
});

test('issues expiring signed streams with original filenames and rejects tampering with 403', function (): void {
    Storage::fake('local');
    $this->seed(AttachmentManagementMenuSeeder::class);
    $this->travelTo('2026-11-18 12:00:00');
    $actor = attachmentSuperAdmin();
    $attachment = Attachment::factory()->create(['original_name' => 'report.txt']);
    Storage::disk('local')->put($attachment->object_key, 'signed private content');
    $token = $actor->createToken('attachment-sign')->plainTextToken;

    $inline = $this->withToken($token)->getJson('/api/v1/admin/attachments/'.$attachment->id.'/temporary-url?disposition=inline')
        ->assertOk()->assertJsonPath('data.expires_at', '2026-11-18 12:05:00')->json('data.url');
    $this->flushHeaders();
    $stream = $this->get($inline)->assertOk()->assertHeaderContains('Content-Disposition', 'inline');
    expect($stream->streamedContent())->toBe('signed private content');
    $this->get(str_replace('disposition=inline', 'disposition=attachment', $inline))->assertForbidden();

    $download = $this->withToken($token)->getJson('/api/v1/admin/attachments/'.$attachment->id.'/temporary-url?disposition=attachment')
        ->assertOk()->json('data.url');
    $this->flushHeaders();
    $this->get($download)->assertOk()->assertDownload('report.txt');
    $this->travel(6)->minutes();
    $this->get($inline)->assertForbidden();
});

test('issues signed attachment downloads for public images', function (): void {
    Storage::fake('public');
    $this->seed(AttachmentManagementMenuSeeder::class);
    $actor = attachmentSuperAdmin();
    $attachment = Attachment::factory()->image()->public()->create(['original_name' => 'public.png']);
    Storage::disk('public')->put($attachment->object_key, 'public image content');

    $url = $this->withToken($actor->createToken('public-download')->plainTextToken)
        ->getJson('/api/v1/admin/attachments/'.$attachment->id.'/temporary-url?disposition=attachment')
        ->assertOk()->json('data.url');

    $this->flushHeaders();
    $this->get($url)->assertOk()->assertDownload('public.png');
});

test('forces rich editor images public and returns the editor payload with its audit ID', function (): void {
    Storage::fake('local');
    Storage::fake('public');
    $this->seed(AttachmentManagementMenuSeeder::class);
    $actor = attachmentSuperAdmin();

    $response = $this->withToken($actor->createToken('editor-image')->plainTextToken)
        ->post('/api/common/upload/wangeditor', ['file' => attachmentPng('editor.png'), 'title' => '正文图片']);

    $response->assertOk()->assertJsonPath('errno', 0)->assertJsonPath('data.alt', '正文图片')
        ->assertJsonMissingPath('code');
    $attachment = Attachment::sole();
    expect($response->json('data.id'))->toBe($attachment->id)
        ->and($attachment->visibility)->toBe('public')
        ->and($attachment->disk)->toBe('public');
    expect($response->json('data.url'))->toBe(Storage::disk('public')->url($attachment->object_key));
    expect($response->json('data.href'))->toBe($response->json('data.url'));
    Storage::disk('public')->assertExists($attachment->object_key);
    expect(AdminLog::where('module', 'attachment')->where('action', 'create')->sole()->target_id)->toBe($attachment->id);
});

test('wraps rich editor validation errors in the project API shape without persistence', function (): void {
    Storage::fake('public');
    $this->seed(AttachmentManagementMenuSeeder::class);
    $actor = attachmentSuperAdmin();

    $this->withToken($actor->createToken('editor-invalid')->plainTextToken)
        ->post('/api/common/upload/wangeditor', ['file' => attachmentUpload('not-image.txt', 'plain text')])
        ->assertUnprocessable()->assertJsonPath('code', 422)->assertJsonPath('data', null)
        ->assertJsonValidationErrors(['file']);

    $this->assertDatabaseCount('attachments', 0);
    Storage::disk('public')->assertEmpty();
});

test('returns 429 when an administrator exceeds the configured upload frequency', function (): void {
    Storage::fake('local');
    $this->seed(AttachmentManagementMenuSeeder::class);
    config(['attachments.uploads_per_minute' => 2]);
    $actor = attachmentSuperAdmin();
    $this->withToken($actor->createToken('attachment-throttle')->plainTextToken);

    $this->post('/api/v1/admin/attachments', ['file' => attachmentPng()])->assertCreated();
    $this->post('/api/v1/admin/attachments', ['file' => attachmentPng()])->assertCreated();
    $this->post('/api/v1/admin/attachments', ['file' => attachmentPng()])
        ->assertTooManyRequests()->assertJsonPath('code', 429);

    $this->assertDatabaseCount('attachments', 2);
    expect(Storage::disk('local')->allFiles())->toHaveCount(2);
});

test('records whitelisted attachment create update and delete snapshots without signed URLs', function (): void {
    Storage::fake('local');
    $this->seed(AttachmentManagementMenuSeeder::class);
    $actor = attachmentSuperAdmin();
    $token = $actor->createToken('attachment-audit')->plainTextToken;

    $created = $this->withToken($token)->post('/api/v1/admin/attachments', [
        'file' => attachmentUpload('audit.txt', 'audited content'), 'title' => 'Audit before', 'category' => 'before',
    ])->assertCreated();
    $attachment = Attachment::findOrFail($created->json('data.id'));
    $signedUrl = $this->getJson('/api/v1/admin/attachments/'.$attachment->id.'/temporary-url')->assertOk()->json('data.url');
    $this->patchJson('/api/v1/admin/attachments/'.$attachment->id, ['title' => 'Audit after', 'category' => 'after'])->assertOk();
    $this->deleteJson('/api/v1/admin/attachments/'.$attachment->id, ['confirmed' => true])->assertOk();

    $logs = AdminLog::where('module', 'attachment')->orderBy('id')->get();
    expect($logs)->toHaveCount(3);
    expect($logs[0]->permission_name)->toBe('system.attachment.create');
    expect($logs[0]->changes['title'])->toBe(['before' => null, 'after' => 'Audit before']);
    expect($logs[0]->changes['sha256']['after'])->toBe(hash('sha256', 'audited content'));
    expect($logs[0]->changes)->not->toHaveKey('url');
    expect($logs[1]->changes['title'])->toBe(['before' => 'Audit before', 'after' => 'Audit after']);
    expect($logs[1]->changes['category'])->toBe(['before' => 'before', 'after' => 'after']);
    expect($logs[1]->changes)->toHaveKeys(['title', 'category']);
    expect($logs[2]->changes['title'])->toBe(['before' => 'Audit after', 'after' => null]);
    expect($logs->toJson())->not->toContain($signedUrl, 'signature=');
    $this->assertModelMissing($attachment);
    Storage::disk('local')->assertMissing($attachment->object_key);
});

test('rolls back the attachment record and deletes the new file when audit persistence fails', function (): void {
    Storage::fake('local');
    $this->seed(AttachmentManagementMenuSeeder::class);
    $actor = attachmentSuperAdmin();
    $dispatcher = AdminLog::getEventDispatcher();
    AdminLog::setEventDispatcher(clone $dispatcher);
    AdminLog::creating(function (AdminLog $log): void {
        if ($log->module === 'attachment' && $log->success) {
            throw new RuntimeException('Forced attachment audit failure');
        }
    });

    try {
        $this->withToken($actor->createToken('attachment-rollback')->plainTextToken)
            ->post('/api/v1/admin/attachments', ['file' => attachmentPng()])->assertServerError();
    } finally {
        AdminLog::setEventDispatcher($dispatcher);
    }

    $this->assertDatabaseCount('attachments', 0);
    Storage::disk('local')->assertEmpty();
});

test('keeps the record when database deletion fails and tolerates the missing file on retry', function (): void {
    Storage::fake('local');
    $this->seed(AttachmentManagementMenuSeeder::class);
    $actor = attachmentSuperAdmin();
    $attachment = Attachment::factory()->create();
    Storage::disk('local')->put($attachment->object_key, 'retry deletion');
    $dispatcher = Attachment::getEventDispatcher();
    Attachment::setEventDispatcher(clone $dispatcher);
    Attachment::deleting(function (): void {
        throw new RuntimeException('Forced attachment database deletion failure');
    });

    try {
        $this->withToken($actor->createToken('attachment-delete-failure')->plainTextToken)
            ->deleteJson('/api/v1/admin/attachments/'.$attachment->id, ['confirmed' => true])->assertServerError();
    } finally {
        Attachment::setEventDispatcher($dispatcher);
    }

    $this->assertModelExists($attachment);
    Storage::disk('local')->assertMissing($attachment->object_key);
    $this->deleteJson('/api/v1/admin/attachments/'.$attachment->id, ['confirmed' => true])->assertOk();
    $this->assertModelMissing($attachment);
});
