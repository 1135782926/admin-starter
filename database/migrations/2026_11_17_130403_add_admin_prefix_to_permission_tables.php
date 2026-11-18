<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::rename('roles', 'admin_roles');
        Schema::rename('permissions', 'admin_permissions');
        Schema::rename('model_has_roles', 'admin_model_has_roles');
        Schema::rename('model_has_permissions', 'admin_model_has_permissions');
        Schema::rename('role_has_permissions', 'admin_role_has_permissions');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::rename('admin_role_has_permissions', 'role_has_permissions');
        Schema::rename('admin_model_has_permissions', 'model_has_permissions');
        Schema::rename('admin_model_has_roles', 'model_has_roles');
        Schema::rename('admin_permissions', 'permissions');
        Schema::rename('admin_roles', 'roles');
    }
};
