<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An agency's own conditions of carriage (Markdown), printed on the e-tickets that carry the agency's details, and the
 * agency portal menu where its administrator edits them. Menus were edited by hand on production: only this one is added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->text('conditions_of_carriage')->nullable()->after('address')->comment('Markdown; e-tickets with the agency header');
        });

        $account = DB::table('menus')->where('path', '/agency/account')->first();
        if (! $account || DB::table('menus')->where('key', 'agency_conditions')->exists()) {
            return;
        }
        DB::table('menus')->where('menu_group_id', $account->menu_group_id)->where('parent_id', $account->parent_id)
            ->where('order', '>', $account->order)->increment('order');
        $menu = DB::table('menus')->insertGetId([
            'menu_group_id' => $account->menu_group_id,
            'parent_id' => $account->parent_id,
            'key' => 'agency_conditions',
            'name' => 'Тээвэрлэлтийн нөхцөл',
            'icon' => 'FileText',
            'path' => '/agency/conditions',
            'scopes' => json_encode(['agency']),
            'order' => $account->order + 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // every agency role reads them; the agency administrators change them
        $portal = DB::table('permission_role')->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->join('roles', 'roles.id', '=', 'permission_role.role_id')
            ->where('permissions.key', 'agency.portal')->pluck('permission_role.role_id')->unique();
        $admins = DB::table('roles')->where('slug', 'agency-admin')->pluck('id');
        foreach (['view' => [$portal, 'View'], 'update' => [$admins, 'Update']] as $action => [$roles, $label]) {
            $id = DB::table('permissions')->insertGetId([
                'menu_id' => $menu,
                'key' => "agency_conditions.{$action}",
                'action' => $action,
                'name' => "Тээвэрлэлтийн нөхцөл: {$label}",
                'category' => 'agency_conditions',
                'order' => $action === 'view' ? 1 : 2,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('permission_role')->insert($roles->map(fn ($role) => ['permission_id' => $id, 'role_id' => $role])->values()->all());
        }
    }

    public function down(): void
    {
        DB::table('menus')->where('key', 'agency_conditions')->delete();
        Schema::table('agencies', fn (Blueprint $table) => $table->dropColumn('conditions_of_carriage'));
    }
};
