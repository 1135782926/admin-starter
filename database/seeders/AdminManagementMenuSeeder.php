<?php

namespace Database\Seeders;

use App\Models\AdminMenu;
use App\Services\AdminMenuService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AdminManagementMenuSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $menus = app(AdminMenuService::class);
            $directory = AdminMenu::where('name', 'system')->first();
            if (! $directory) {
                $directory = $menus->create([
                    'type' => 'directory',
                    'name' => 'system',
                    'title' => '权限管理',
                    'path' => '/system',
                    'icon' => 'ri:shield-user-line',
                ]);
            }
            abort_unless($directory->type === 'directory', 409, 'system 节点已存在且不是目录，无法初始化权限管理分组。');

            $this->call(AdminMenuSeeder::class);
            foreach ([
                ['name' => 'system.admin', 'title' => '管理员管理', 'path' => '/system/user', 'component' => '/system/user/index', 'icon' => 'ri:admin-line', 'sort' => 10],
                ['name' => 'system.role', 'title' => '角色组管理', 'path' => '/system/role', 'component' => '/system/role/index', 'icon' => 'ri:group-line', 'sort' => 20],
                ['name' => 'system.log', 'title' => '管理员日志管理', 'path' => '/system/log', 'component' => '/system/log/index', 'icon' => 'ri:file-list-3-line', 'sort' => 30, 'buttons' => ['view']],
            ] as $page) {
                if (! AdminMenu::where('name', $page['name'])->exists()) {
                    $menus->create(['type' => 'menu', 'open_type' => 'page', ...$page]);
                }
            }

            foreach (['system.menu', 'system.admin', 'system.role', 'system.log'] as $name) {
                $menu = AdminMenu::where('name', $name)->firstOrFail();
                if ($menu->parent_id !== $directory->id) {
                    $menus->update($menu, ['parent_id' => $directory->id]);
                }
            }
        });
    }
}
