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
        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique()->comment('用户名');
            $table->string('nickname')->comment('昵称');
            $table->string('avatar')->nullable()->comment('头像');
            $table->string('email')->nullable()->comment('电子邮箱');
            $table->string('mobile', 32)->nullable()->comment('手机号');
            $table->string('password')->comment('密码');
            $table->text('signature')->nullable()->comment('个性签名');
            $table->boolean('status')->default(true)->comment('状态：0 禁用，1 启用');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_users');
    }
};
