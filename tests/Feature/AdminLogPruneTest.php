<?php

use App\Models\AdminLog;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

test('prunes only logs strictly older than the configured retention cutoff across multiple chunks', function (int $days): void {
    $this->travelTo(now()->setDate(2026, 11, 18)->setTime(12, 0));
    config(['admin_logs.retention_days' => $days]);
    $cutoff = now()->subDays($days);
    $attributes = [
        'module' => 'auth', 'action' => 'login', 'route_name' => 'admin.login',
        'method' => 'POST', 'path' => 'api/v1/admin/auth/login', 'status_code' => 401,
        'success' => false, 'duration_ms' => 0,
    ];
    AdminLog::insert(array_fill(0, 1001, [...$attributes, 'created_at' => $cutoff->copy()->subSecond()]));
    $boundary = AdminLog::create([...$attributes, 'created_at' => $cutoff]);
    $newer = AdminLog::create([...$attributes, 'created_at' => $cutoff->copy()->addSecond()]);
    $current = AdminLog::create([...$attributes, 'created_at' => now()]);

    $this->artisan('admin-logs:prune')->expectsOutput('Pruned 1001 administrator audit logs.')->assertSuccessful();

    $this->assertDatabaseCount('admin_logs', 3);
    $this->assertModelExists($boundary);
    $this->assertModelExists($newer);
    $this->assertModelExists($current);
    $this->artisan('admin-logs:prune')->expectsOutput('Pruned 0 administrator audit logs.')->assertSuccessful();
})->with(['ninety days' => [90], 'custom thirty days' => [30]]);

test('refuses invalid retention configuration without deleting logs', function (mixed $days): void {
    $this->freezeTime();
    config(['admin_logs.retention_days' => $days]);
    $log = AdminLog::create([
        'module' => 'auth', 'action' => 'login', 'route_name' => 'admin.login',
        'method' => 'POST', 'path' => 'api/v1/admin/auth/login', 'status_code' => 401,
        'success' => false, 'duration_ms' => 0, 'created_at' => now()->subYears(2),
    ]);

    $this->artisan('admin-logs:prune')->assertFailed();

    $this->assertModelExists($log);
})->with(['zero' => [0], 'negative' => [-1], 'noninteger' => ['invalid']]);
