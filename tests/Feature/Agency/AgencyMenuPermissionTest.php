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
