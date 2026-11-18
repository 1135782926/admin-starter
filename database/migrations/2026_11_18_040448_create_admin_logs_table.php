<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('admin_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('actor_username')->nullable();
            $table->string('actor_nickname')->nullable();
            $table->string('attempted_username')->nullable();
            $table->string('module', 32);
            $table->string('action', 32);
            $table->string('permission_name')->nullable();
            $table->string('route_name', 100);
            $table->string('method', 10);
            $table->string('path');
            $table->string('target_type', 32)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('target_label')->nullable();
            $table->unsignedSmallInteger('status_code');
            $table->boolean('success');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->unsignedInteger('duration_ms');
            $table->json('changes')->nullable();
            $table->json('related_changes')->nullable();
            $table->timestamp('created_at')->index();
            $table->index(['actor_id', 'created_at', 'id']);
            $table->index(['module', 'action', 'created_at']);
            $table->index(['success', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_logs');
    }
};
