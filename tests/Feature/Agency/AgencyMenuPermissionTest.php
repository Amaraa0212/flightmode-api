<?php

namespace Tests\Feature\Agency;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

class AgencyMenuPermissionTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    private function paths(): array
    {
        return collect($this->getJson('/api/v1/auth/me')->assertOk()->json('data.menus'))
            ->flatMap(fn ($g) => array_column($g['items'], 'path'))->filter()->values()->all();
    }

    public function test_an_agency_role_sees_only_the_portal_menus_it_is_given(): void
    {
        $user = $this->actingAsRole('agency-agent');
        $this->assertContains('/agency/quotas', $this->paths());
        $this->assertContains('/agency/account', $this->paths());

        Role::query()->where('slug', 'agency-agent')->firstOrFail()->permissions()
            ->detach(Permission::query()->where('key', 'agency_account.view')->value('id'));
        Cache::flush();
        Sanctum::actingAs($user->fresh());
        $this->assertNotContains('/agency/account', $this->paths());
        $this->assertContains('/agency/quotas', $this->paths());
    }

    public function test_a_role_given_some_agency_menus_also_gets_the_portal(): void
    {
        $this->actingAsRole('system-admin');
        $id = $this->postJson('/api/v1/admin/roles', [
            'name' => 'Agency cashier', 'slug' => 'agency-cashier', 'scope' => 'agency', 'permissions' => ['agency_quotas.view', 'agency_holds.view'],
        ])->assertCreated()->json('data.id');

        $this->assertEqualsCanonicalizing(['agency.portal', 'agency_holds.view', 'agency_quotas.view'],
            Role::query()->findOrFail($id)->permissions()->pluck('key')->all());

        $this->actingAsRole('agency-agent', ['role_id' => $id]);
        $this->assertEqualsCanonicalizing(['/agency/holds', '/agency/quotas'], $this->paths());
    }

    public function test_the_migration_resumes_after_a_failed_run_and_skips_deleted_roles(): void
    {
        // production: menus as before the migration, a role deleted by hand left its permission rows, and an
        // earlier run stopped after converting the first menu
        $migration = require database_path('migrations/2026_11_12_100000_add_agency_menu_permissions.php');
        $migration->down();
        DB::statement('PRAGMA defer_foreign_keys = ON');   // checked at commit; the test transaction is rolled back
        DB::table('permission_role')->insert(['permission_id' => Permission::query()->where('key', 'agency.portal')->value('id'), 'role_id' => 9999]);
        DB::table('menus')->where('path', '/agency')->update(['key' => 'agency_home', 'permission_key' => null]);
        DB::table('permissions')->insert(['key' => 'agency_home.view', 'action' => 'view', 'name' => 'Нүүр: View', 'category' => 'agency_home',
            'menu_id' => DB::table('menus')->where('path', '/agency')->value('id')]);

        $migration->up();
        $migration->up();   // and once more: nothing twice

        $agent = Role::query()->where('slug', 'agency-agent')->firstOrFail();
        foreach (['agency_home', 'agency_booking', 'agency_quotas', 'agency_account'] as $key) {
            $this->assertSame(1, $agent->permissions()->where('key', "{$key}.view")->count(), $key);
        }
        $this->assertFalse(DB::table('permission_role')->where('role_id', 9999)
            ->whereIn('permission_id', Permission::query()->where('key', 'like', 'agency\_%.view')->pluck('id'))->exists());

        $this->actingAsRole('agency-agent');
        $this->assertContains('/agency', $this->paths());
        $this->assertContains('/agency/quotas', $this->paths());
    }

    public function test_the_migration_gives_an_agency_admin_its_users_and_adds_the_ticketing_agent_role(): void
    {
        // production after the reset: Agency Admin with the portal only, no ticketing agent role
        $admin = Role::query()->where('slug', 'agency-admin')->firstOrFail();
        $admin->permissions()->detach(Permission::query()->where('key', 'like', 'users.%')->pluck('id'));
        Role::query()->where('slug', 'agency-agent')->delete();

        (require database_path('migrations/2026_11_12_100000_add_agency_menu_permissions.php'))->up();

        $this->assertEqualsCanonicalizing(['users.view', 'users.create', 'users.update', 'users.delete'],
            $admin->permissions()->where('key', 'like', 'users.%')->pluck('key')->all());
        $agent = Role::query()->where('slug', 'agency-agent')->firstOrFail();
        $this->assertSame('agency', $agent->scope->value);
        $this->assertTrue($agent->permissions()->where('key', 'agency_quotas.view')->exists());
        $this->assertFalse($agent->permissions()->where('key', 'like', 'users.%')->exists());

        // the agency administrator adds a ticketing agent to their own agency
        $this->actingAsRole('agency-admin');
        $this->postJson('/api/v1/admin/users', [
            'name' => 'Agent One', 'email' => 'agent1@test.travel', 'password' => 'Secret-2026!', 'role_id' => $agent->id, 'is_active' => true,
        ])->assertCreated();
        $this->assertSame($this->agency->id, DB::table('users')->where('email', 'agent1@test.travel')->value('agency_id'));
    }
}
