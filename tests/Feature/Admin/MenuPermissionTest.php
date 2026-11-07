<?php

namespace Tests\Feature\Admin;

use App\Enums\RoleScope;
use App\Models\Airline;
use App\Models\Menu;
use App\Models\MenuGroup;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions\MenuCatalog;
use Database\Seeders\MenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

class MenuPermissionTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    /** An airline role with exactly these permission keys */
    private function actingWith(array $keys, array $attributes = []): User
    {
        $role = Role::create(['name' => 'Custom', 'slug' => 'custom-'.uniqid(), 'scope' => RoleScope::Airline]);
        $role->permissions()->sync(Permission::query()->whereIn('key', $keys)->pluck('id'));
        $user = User::factory()->create(['role_id' => $role->id, 'airline_id' => $this->airline->id] + $attributes);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_every_admin_route_checks_a_permission(): void
    {
        $unchecked = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/admin/'))
            ->reject(fn ($route) => collect($route->gatherMiddleware())->contains(
                fn ($m) => is_string($m) && preg_match('/^(crud|perm|can):/', $m)
            ))
            ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())
            ->values()->all();

        $this->assertSame([], $unchecked);
    }

    public function test_seeder_moves_old_manage_keys_to_menu_permissions_once(): void
    {
        // a database from before menus: the old keys, no menu
        Permission::query()->delete();
        $old = collect(['flights.view', 'flights.manage', 'reports.view'])->map(fn ($key) => Permission::create(['key' => $key, 'name' => $key]));
        $role = Role::create(['name' => 'Old', 'slug' => 'old', 'scope' => RoleScope::Airline]);
        $role->permissions()->sync($old->pluck('id'));

        $this->seed(MenuSeeder::class);

        $keys = $role->permissions()->pluck('key')->sort()->values()->all();
        $this->assertContains('flights.create', $keys);
        $this->assertContains('flight_series.delete', $keys);
        $this->assertContains('flight_load.view', $keys);
        $this->assertContains('manifest.view', $keys);
        $this->assertNotContains('flights.manage', $keys);
        $this->assertFalse(Permission::query()->where('key', 'flights.manage')->exists());

        // removed afterwards in the back office: a second run does not give it back
        $role->permissions()->detach(Permission::query()->where('key', 'flight_load.view')->value('id'));
        $this->seed(MenuSeeder::class);
        $this->assertNotContains('flight_load.view', $role->permissions()->pluck('key')->all());
        $this->assertSame(count(MenuCatalog::keys()), Permission::query()->count());
    }

    public function test_seeder_keeps_menus_edited_in_the_back_office(): void
    {
        Menu::query()->where('key', 'flights')->update(['name' => 'Нислэгүүд', 'order' => 9]);

        $this->seed(MenuSeeder::class);

        $this->assertSame('Нислэгүүд', Menu::query()->where('key', 'flights')->value('name'));
        $this->assertSame(1, Menu::query()->where('key', 'flights')->count());
    }

    public function test_crud_follows_the_http_method(): void
    {
        $flight = $this->makeFlight();
        $this->actingWith(['flights.view', 'flights.update']);

        $this->getJson('/api/v1/admin/flights')->assertOk();
        $this->getJson("/api/v1/admin/flights/{$flight->id}")->assertOk();
        $this->deleteJson("/api/v1/admin/flights/{$flight->id}")->assertForbidden();
        $this->postJson('/api/v1/admin/flight-series', [])->assertForbidden();
    }

    public function test_lookup_lists_open_to_back_office_but_not_their_writes_or_agencies(): void
    {
        $this->actingWith(['seat_blocks.view']);
        $this->getJson('/api/v1/admin/flights')->assertOk();
        $this->getJson('/api/v1/admin/aircraft')->assertOk();
        $this->postJson('/api/v1/admin/aircraft', [])->assertForbidden();
        $this->getJson('/api/v1/admin/flight-series')->assertForbidden();

        $this->actingAsRole('agency-admin');
        $this->getJson('/api/v1/admin/flights')->assertForbidden();
    }

    public function test_profile_lists_only_the_menus_the_user_may_see(): void
    {
        $user = $this->actingWith(['flights.view', 'bookings.view']);

        $menus = $this->getJson('/api/v1/auth/me')->assertOk()->json('data.menus');
        $paths = collect($menus)->flatMap(fn ($g) => collect($g['items'])->flatMap(fn ($i) => [$i['path'], ...array_column($i['children'], 'path')]))->filter()->values()->all();

        $this->assertEqualsCanonicalizing(['/operations/flights', '/inventory/bookings'], $paths);

        $this->actingAsRole('system-admin');
        $paths = collect($this->getJson('/api/v1/auth/me')->json('data.menus'))->flatMap(fn ($g) => array_column($g['items'], 'path'))->all();
        $this->assertContains('/access/menus', $paths);
        $this->assertNotContains('/agency', $paths); // agency portal pages: agency logins only
    }

    public function test_airline_admin_creates_roles_only_within_their_own_permissions(): void
    {
        $this->actingAsRole('airline-admin');
        $other = Airline::create(['name' => 'Other Air', 'iata_code' => 'OA']);

        $id = $this->postJson('/api/v1/admin/roles', [
            'name' => 'Sales desk', 'slug' => 'ta-sales', 'scope' => 'airline', 'airline_id' => $other->id,
            'permissions' => ['flights.view', 'bookings.view', 'bookings.update'],
        ])->assertCreated()->json('data.id');

        $role = Role::query()->findOrFail($id);
        $this->assertSame($this->airline->id, $role->airline_id);
        $this->assertFalse($role->is_system);

        $this->postJson('/api/v1/admin/roles', [
            'name' => 'Too much', 'slug' => 'ta-too-much', 'scope' => 'airline', 'permissions' => ['menus.update'],
        ])->assertUnprocessable()->assertJsonValidationErrors('permissions');

        $this->postJson('/api/v1/admin/roles', ['name' => 'Admins', 'slug' => 'ta-admins', 'scope' => 'admin'])
            ->assertUnprocessable()->assertJsonValidationErrors('scope');
    }

    public function test_airline_admin_cannot_touch_shared_other_airline_or_own_roles(): void
    {
        $admin = $this->actingAsRole('airline-admin');
        $other = Airline::create(['name' => 'Other Air', 'iata_code' => 'OA']);
        $foreign = Role::create(['name' => 'Foreign', 'slug' => 'oa-foreign', 'scope' => RoleScope::Airline, 'airline_id' => $other->id]);
        $shared = Role::query()->where('slug', 'airline-staff')->firstOrFail();

        $this->putJson("/api/v1/admin/roles/{$shared->id}", ['name' => 'X'])->assertForbidden();
        $this->putJson("/api/v1/admin/roles/{$foreign->id}", ['name' => 'X'])->assertForbidden();
        $this->putJson("/api/v1/admin/roles/{$admin->role_id}", ['name' => 'X'])->assertForbidden();
        $this->assertNotContains($foreign->id, array_column($this->getJson('/api/v1/admin/roles')->json('data'), 'id'));
        $this->assertNotContains($foreign->id, array_column($this->getJson('/api/v1/admin/users/role-options')->json('data'), 'id'));

        // a role the airline made for itself, and its own administrator holding a copy of their role
        $own = Role::create(['name' => 'Own', 'slug' => 'ta-own', 'scope' => RoleScope::Airline, 'airline_id' => $this->airline->id]);
        $this->putJson("/api/v1/admin/roles/{$own->id}", ['name' => 'Own desk', 'permissions' => ['flights.view']])->assertOk();
        $this->assertSame(['flights.view'], $own->permissions()->pluck('key')->all());
    }

    public function test_permission_matrix_shows_only_grantable_actions(): void
    {
        $this->actingWith(['roles.view', 'flights.view', 'flights.update']);

        $keys = collect($this->getJson('/api/v1/admin/permissions')->assertOk()->json('data'))
            ->flatMap(fn ($g) => collect($g['menus'])->flatMap(fn ($m) => array_column($m['permissions'], 'key')))->all();

        $this->assertEqualsCanonicalizing(['roles.view', 'flights.view', 'flights.update'], $keys);
    }

    public function test_menus_are_edited_by_system_administrators_only(): void
    {
        $this->actingAsRole('system-admin');
        $id = $this->postJson('/api/v1/admin/menus', [
            'name' => 'Promotions', 'key' => 'promotions', 'path' => '/sales/promotions', 'menu_group_id' => MenuGroup::query()->value('id'),
            'actions' => ['view', 'create', 'export'],
        ])->assertCreated()->json('data.id');
        $this->assertEqualsCanonicalizing(['promotions.view', 'promotions.create', 'promotions.export'], Permission::query()->where('menu_id', $id)->pluck('key')->all());

        $flights = Menu::query()->where('key', 'flights')->firstOrFail();
        $this->putJson("/api/v1/admin/menus/{$flights->id}", ['key' => 'flights2'])->assertUnprocessable();
        $this->deleteJson("/api/v1/admin/menus/{$flights->id}")->assertUnprocessable();
        $this->deleteJson("/api/v1/admin/menus/{$id}")->assertOk();
        $this->assertFalse(Permission::query()->where('key', 'promotions.view')->exists());

        $this->actingWith(['menus.view', 'menus.create']);
        $this->getJson('/api/v1/admin/menus')->assertForbidden();
    }

    public function test_airline_ip_restriction_limits_airline_staff_only(): void
    {
        $admin = $this->actingAsRole('airline-admin');
        $this->postJson("/api/v1/admin/ip-access/{$this->airline->id}/addresses", ['ip' => '203.0.113.0/24', 'note' => 'HQ'])->assertCreated();
        $this->postJson("/api/v1/admin/ip-access/{$this->airline->id}/addresses", ['ip' => 'not-an-ip'])->assertUnprocessable();

        // turning it on from outside the list would lock the administrator out
        $this->putJson("/api/v1/admin/ip-access/{$this->airline->id}", ['enabled' => true])->assertUnprocessable();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->putJson("/api/v1/admin/ip-access/{$this->airline->id}", ['enabled' => true])->assertOk();

        // other network: every API call and the sign-in itself are refused
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])
            ->getJson('/api/v1/admin/flights')->assertForbidden()->assertJsonPath('code', 'ip_not_allowed');
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.200'])->getJson('/api/v1/admin/flights')->assertOk();

        $admin->update(['password' => 'Secret-123']);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])
            ->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'Secret-123'])
            ->assertForbidden()->assertJsonPath('code', 'ip_not_allowed');
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'Secret-123'])->assertOk();

        // airport staff (DCS / gate) and system administrators are not limited
        $this->actingAsRole('station-agent');
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])->getJson('/api/v1/auth/me')->assertOk();
        $this->actingAsRole('system-admin');
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])->getJson('/api/v1/admin/flights')->assertOk();
    }

    public function test_airline_user_manages_only_their_own_ip_list(): void
    {
        $this->actingAsRole('airline-admin');
        $other = Airline::create(['name' => 'Other Air', 'iata_code' => 'OA']);

        $this->postJson("/api/v1/admin/ip-access/{$other->id}/addresses", ['ip' => '10.0.0.1'])->assertForbidden();
        $this->getJson('/api/v1/admin/ip-access')->assertOk()->assertJsonPath('data.airline.id', $this->airline->id);
    }

    public function test_the_migration_adds_new_items_without_touching_menus_changed_by_hand(): void
    {
        // production: the menus were renamed and moved; these did not exist yet
        Permission::query()->whereIn('key', ['flight_manager.reopen', 'gate.view_all'])->delete();
        Menu::query()->where('key', 'sales_summary')->delete();
        $group = MenuGroup::query()->create(['name' => 'Business Rules', 'order' => 99]);
        Menu::query()->where('key', 'sales_report')->update(['name' => 'Ticket Sales', 'parent_id' => null, 'menu_group_id' => $group->id, 'order' => 5]);
        MenuGroup::query()->where('name', 'Flight')->update(['name' => 'Schedule']);
        $groups = MenuGroup::query()->count();
        $menus = Menu::query()->count();

        $migration = require database_path('migrations/2026_11_07_120000_add_reopen_gate_view_all_and_sales_summary_permissions.php');
        $migration->up();
        $migration->up(); // twice: nothing is added again

        $this->assertSame($groups, MenuGroup::query()->count());
        $this->assertSame($menus + 1, Menu::query()->count());
        $this->assertSame('Ticket Sales', Menu::query()->where('key', 'sales_report')->value('name'));
        $this->assertTrue(MenuGroup::query()->where('name', 'Schedule')->exists());

        $summary = Menu::query()->where('key', 'sales_summary')->firstOrFail();
        $this->assertSame([$group->id, 6, '/reports/sales-summary'], [$summary->menu_group_id, $summary->order, $summary->path]);

        $roles = fn (string $key) => Role::query()->whereHas('permissions', fn ($p) => $p->where('key', $key))->pluck('slug')->all();
        $this->assertEqualsCanonicalizing($roles('flight_manager.cancel'), $roles('flight_manager.reopen'));
        $this->assertSame(['airline-admin'], $roles('gate.view_all'));
        $this->assertEqualsCanonicalizing($roles('sales_report.view'), $roles('sales_summary.view'));
    }
}
