<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('member_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique()->comment('分组名称');
            $table->timestamps();
        });

        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->string('username', 64)->unique()->comment('用户名');
            $table->string('nickname', 100)->comment('昵称');
            $table->foreignId('member_group_id')->constrained()->restrictOnDelete();
            $table->string('avatar')->nullable()->comment('头像');
            $table->string('email')->nullable()->comment('电子邮箱');
            $table->string('mobile', 32)->nullable()->comment('手机号');
            $table->string('password')->comment('密码');
            $table->enum('gender', ['unknown', 'male', 'female'])->default('unknown')->comment('性别');
            $table->date('birthday')->nullable()->comment('生日');
            $table->string('signature', 500)->nullable()->comment('个性签名');
            $table->boolean('status')->default(true)->comment('状态：0 禁用，1 启用');
            $table->timestamps();
        });

        $now = now();
        DB::table('member_groups')->insert([
            'name' => '默认分组',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('members');
        Schema::dropIfExists('member_groups');
    }
};
