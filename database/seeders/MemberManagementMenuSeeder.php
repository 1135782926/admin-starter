<?php

namespace Database\Seeders;

use App\Models\AdminMenu;
use App\Services\AdminMenuService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MemberManagementMenuSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(AdminMenuService $menus): void
    {
        DB::transaction(function () use ($menus): void {
            if (AdminMenu::where('name', 'system.member')->exists()) {
                return;
            }

            $menu = $menus->create([
                'type' => 'menu',
                'title' => '会员管理',
                'name' => 'system.member',
                'open_type' => 'page',
                'path' => '/system/member',
                'component' => '/system/member/index',
                'icon' => 'ri:team-line',
                'sort' => 50,
                'buttons' => ['view', 'create', 'update'],
            ]);

            $menus->create([
                'type' => 'button',
                'title' => '分组管理',
                'name' => 'system.member.manage_groups',
                'parent_id' => $menu->id,
                'sort' => 3,
            ]);
        });
    }
}
