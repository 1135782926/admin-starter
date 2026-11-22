<?php

namespace Database\Seeders;

use App\Models\AdminRole;
use App\Models\AdminUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        DB::transaction(function (): void {
            $admin = AdminUser::firstOrCreate(
                ['username' => 'admin'],
                ['nickname' => '超级管理员', 'password' => 'admin', 'status' => true],
            );

            if ($admin->wasRecentlyCreated) {
                $admin->assignRole(AdminRole::findOrCreate('super-admin', 'web'));
            }
        });
    }
}
