<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class SystemMenuSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AdminManagementMenuSeeder::class,
            AttachmentManagementMenuSeeder::class,
            MemberManagementMenuSeeder::class,
        ]);
    }
}
