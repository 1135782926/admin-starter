<?php

namespace Database\Seeders;

use App\Models\AdminRole;
use App\Models\AdminUser;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $admin = AdminUser::firstOrCreate(
            ['username' => 'admin'],
            ['nickname' => '超级管理员', 'password' => 'admin', 'status' => true],
        );
        $superAdmin = AdminRole::findOrCreate('super-admin', 'web');

        if (! $admin->hasRole($superAdmin)) {
            $admin->assignRole($superAdmin);
        }
    }
}
