<?php

namespace Database\Seeders;

use App\Models\AdminMenu;
use App\Services\AdminMenuService;
use Illuminate\Database\Seeder;

class AttachmentManagementMenuSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (AdminMenu::where('name', 'system.attachment')->exists()) {
            return;
        }

        app(AdminMenuService::class)->create([
            'type' => 'menu',
            'title' => '附件管理',
            'name' => 'system.attachment',
            'open_type' => 'page',
            'path' => '/system/attachment',
            'component' => '/system/attachment/index',
            'icon' => 'ri:attachment-2',
            'sort' => 40,
            'buttons' => ['view', 'create', 'update', 'delete'],
        ]);
    }
}
