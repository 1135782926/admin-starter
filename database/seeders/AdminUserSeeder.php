<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        AdminUser::factory()->disabled()->create([
            'username' => 'demo_admin',
            'nickname' => '示例管理员',
        ]);
    }
}
