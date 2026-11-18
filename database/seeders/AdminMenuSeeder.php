<?php

namespace Database\Seeders;

use App\Models\AdminMenu;
use App\Services\AdminMenuService;
use Illuminate\Database\Seeder;

class AdminMenuSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! AdminMenu::where('name', 'system.menu')->exists()) {
            app(AdminMenuService::class)->create([
                'type' => 'menu',
                'title' => '菜单管理',
                'name' => 'system.menu',
                'open_type' => 'page',
                'path' => '/system/menu',
                'component' => '/system/menu/index',
                'icon' => 'ri:menu-line',
            ]);
        }
    }
}
