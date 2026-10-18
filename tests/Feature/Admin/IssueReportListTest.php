<?php

namespace Tests\Feature\Admin;

use App\Models\Airline;
use App\Models\IssueReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

class IssueReportListTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    private function report(User $user, array $attributes = []): IssueReport
    {
        return IssueReport::create(array_merge([
            'user_id' => $user->id,
            'airline_id' => $user->airline_id,
            'agency_id' => $user->agency_id,
            'page_path' => '/operations/flights',
            'message' => 'Something is broken.',
            'status' => IssueReport::STATUS_OPEN,
        ], $attributes));
    }

    public function test_airline_admin_lists_own_airline_reports_newest_first_with_relations(): void
    {
        $staff = $this->makeUser('airline-staff', ['name' => 'Bold Staff']);
        $older = $this->report($staff, ['message' => 'Older report']);
        $newer = $this->report($staff, ['message' => 'Newer report']);

        $other = Airline::create(['name' => 'Other Air', 'iata_code' => 'OA', 'numeric_code' => '456']);
        $this->report($this->makeUser('airline-staff', ['airline_id' => $other->id]), ['message' => 'Foreign report']);

        $this->actingAsRole('airline-admin');
        $this->getJson('/api/v1/admin/issue-reports?per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.0.user.name', 'Bold Staff')
            ->assertJsonPath('data.0.airline.id', $this->airline->id)
            ->assertJsonStructure(['data' => [['id', 'user', 'airline', 'agency', 'page_path', 'page_title', 'message', 'status', 'created_at']], 'links', 'meta']);

        $this->getJson('/api/v1/admin/issue-reports?page=2&per_page=1')->assertJsonPath('data.0.id', $older->id);
    }

    public function test_filters_by_search_status_and_date(): void
    {
        $staff = $this->makeUser('airline-staff', ['name' => 'Saraa']);
        $resolved = $this->report($staff, ['message' => 'Seat map does not load', 'status' => IssueReport::STATUS_RESOLVED]);
        $old = $this->report($staff, ['page_path' => '/bookings', 'page_title' => 'Захиалга']);
        $old->forceFill(['created_at' => now()->subDays(10)])->save();

        $this->actingAsRole('airline-admin');

        $this->getJson('/api/v1/admin/issue-reports?search=seat map')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $resolved->id);
        $this->getJson('/api/v1/admin/issue-reports?'.http_build_query(['search' => 'Захиалга']))
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $old->id);
        $this->getJson('/api/v1/admin/issue-reports?search=Saraa')->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/admin/issue-reports?status=open')
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $old->id);
        $this->getJson('/api/v1/admin/issue-reports?from='.now()->subDay()->toDateString())
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $resolved->id);
        $this->getJson('/api/v1/admin/issue-reports?to='.now()->subDays(5)->toDateString())
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $old->id);
        $this->getJson('/api/v1/admin/issue-reports?status=bogus')->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_system_admin_sees_reports_of_every_airline_and_agency(): void
    {
        $this->report($this->makeUser('agency-agent'));
        $this->report($this->makeUser('airline-staff'));

        $this->actingAsRole('system-admin');
        $this->getJson('/api/v1/admin/issue-reports')->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_users_without_permission_cannot_list(): void
    {
        $this->getJson('/api/v1/admin/issue-reports')->assertUnauthorized();

        $this->actingAsRole('airline-staff');
        $this->getJson('/api/v1/admin/issue-reports')->assertForbidden();

        $this->actingAsRole('agency-agent');
        $this->getJson('/api/v1/admin/issue-reports')->assertForbidden();
    }

    public function test_airline_admin_resolves_and_reopens_a_report(): void
    {
        $report = $this->report($this->makeUser('airline-staff'));
        $admin = $this->actingAsRole('airline-admin');

        $this->patchJson("/api/v1/admin/issue-reports/{$report->id}", ['status' => 'resolved', 'resolution_note' => 'Fixed in v1.2'])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.resolution_note', 'Fixed in v1.2')
            ->assertJsonPath('data.resolved_by.id', $admin->id);
        $this->assertNotNull($report->refresh()->resolved_at);
        $this->assertDatabaseHas('activity_logs', ['event' => 'issue_report.updated']);

        $this->patchJson("/api/v1/admin/issue-reports/{$report->id}", ['status' => 'in_progress'])
            ->assertOk()->assertJsonPath('data.resolved_by', null)->assertJsonPath('data.resolved_at', null);

        $this->getJson("/api/v1/admin/issue-reports/{$report->id}")->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->patchJson("/api/v1/admin/issue-reports/{$report->id}", ['status' => 'done'])->assertStatus(422);
    }

    public function test_cannot_update_reports_of_another_airline_or_without_permission(): void
    {
        $other = Airline::create(['name' => 'Other Air', 'iata_code' => 'OA', 'numeric_code' => '456']);
        $foreign = $this->report($this->makeUser('airline-staff', ['airline_id' => $other->id]));
        $own = $this->report($this->makeUser('airline-staff'));

        $this->actingAsRole('airline-admin');
        $this->patchJson("/api/v1/admin/issue-reports/{$foreign->id}", ['status' => 'resolved'])->assertForbidden();
        $this->getJson("/api/v1/admin/issue-reports/{$foreign->id}")->assertForbidden();

        $this->actingAsRole('airline-staff');
        $this->patchJson("/api/v1/admin/issue-reports/{$own->id}", ['status' => 'resolved'])->assertForbidden();
    }
}
