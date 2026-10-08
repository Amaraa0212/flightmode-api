<?php

namespace Database\Seeders;

use App\Enums\PermissionKey;
use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PermissionKey::cases() as $permission) {
            Permission::query()->updateOrCreate(
                ['key' => $permission->value],
                ['name' => $permission->label(), 'category' => $permission->category()],
            );
        }

        Permission::query()->whereNotIn('key', array_map(fn ($p) => $p->value, PermissionKey::cases()))->delete();
    }
}
