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
        Schema::table('admin_roles', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->constrained('admin_roles')->restrictOnDelete();
            $table->string('display_name')->nullable();
            $table->boolean('status')->default(true);
            $table->text('remark')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('admin_roles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn(['display_name', 'status', 'remark']);
        });
    }
};
