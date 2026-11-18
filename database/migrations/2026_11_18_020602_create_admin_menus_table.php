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
        Schema::create('admin_menus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('admin_menus')->restrictOnDelete();
            $table->string('type');
            $table->string('title');
            $table->string('name')->unique();
            $table->foreignId('permission_id')->nullable()->unique()->constrained('admin_permissions')->restrictOnDelete();
            $table->string('open_type')->nullable();
            $table->string('path')->nullable();
            $table->string('component')->nullable();
            $table->string('url', 2048)->nullable();
            $table->string('icon')->nullable();
            $table->integer('sort')->default(0);
            $table->boolean('keep_alive')->default(false);
            $table->boolean('status')->default(true);
            $table->text('remark')->nullable();
            $table->index(['parent_id', 'sort', 'id']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_menus');
    }
};
