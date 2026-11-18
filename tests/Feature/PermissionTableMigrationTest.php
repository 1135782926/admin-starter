<?php

use App\Models\AdminPermission;
use App\Models\AdminRole;
use App\Models\AdminUser;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

uses(LazilyRefreshDatabase::class);

test('preserves role and direct permission assignments through table renaming', function (): void {
    $admin = AdminUser::factory()->create();
    $rolePermission = AdminPermission::create(['name' => 'admin-users.manage', 'guard_name' => 'web']);
    $directPermission = AdminPermission::create(['name' => 'admin-users.audit', 'guard_name' => 'web']);
    $role = AdminRole::create(['name' => 'migration-admin', 'guard_name' => 'web']);
    $role->givePermissionTo($rolePermission);
    $admin->assignRole($role);
    $admin->givePermissionTo($directPermission);
    $migration = require database_path('migrations/2026_11_17_130403_add_admin_prefix_to_permission_tables.php');

    $migration->down();

    $this->assertDatabaseHas('roles', ['id' => $role->id, 'name' => 'migration-admin']);
    $this->assertDatabaseHas('permissions', ['id' => $rolePermission->id, 'name' => 'admin-users.manage']);
    $this->assertDatabaseHas('permissions', ['id' => $directPermission->id, 'name' => 'admin-users.audit']);
    $this->assertDatabaseHas('model_has_roles', [
        'role_id' => $role->id,
        'model_type' => AdminUser::class,
        'model_id' => $admin->id,
    ]);
    $this->assertDatabaseHas('model_has_permissions', [
        'permission_id' => $directPermission->id,
        'model_type' => AdminUser::class,
        'model_id' => $admin->id,
    ]);
    $this->assertDatabaseHas('role_has_permissions', [
        'permission_id' => $rolePermission->id,
        'role_id' => $role->id,
    ]);

    $migration->up();
    $admin->unsetRelations();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($admin->getRoleNames()->all())->toBe(['migration-admin']);
    expect($admin->can('admin-users.manage'))->toBeTrue();
    expect($admin->can('admin-users.audit'))->toBeTrue();
});

test('removes permission tables when rolling back both permission migrations', function (): void {
    AdminRole::create(['name' => 'rollback-admin', 'guard_name' => 'web']);
    $renameMigration = require database_path('migrations/2026_11_17_130403_add_admin_prefix_to_permission_tables.php');
    $createMigration = require database_path('migrations/2026_11_17_123828_create_permission_tables.php');
    $menuMigration = require database_path('migrations/2026_11_18_020602_create_admin_menus_table.php');

    $menuMigration->down();
    $renameMigration->down();
    $createMigration->down();

    foreach (['roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
        expect(Schema::hasTable('admin_'.$table))->toBeFalse();
    }
});
