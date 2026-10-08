<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            ReferenceDataSeeder::class,
        ]);

        // First system administrator (change the password after the first login).
        User::query()->firstOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@flightmode.mn')],
            [
                'name' => 'System administrator',
                'password' => env('ADMIN_PASSWORD', 'ChangeMe123!'),
                'role_id' => Role::query()->where('slug', 'system-admin')->value('id'),
                'is_active' => true,
            ],
        );
    }
}
