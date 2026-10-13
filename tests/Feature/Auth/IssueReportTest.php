<?php

namespace Tests\Feature\Auth;

use App\Models\IssueReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

class IssueReportTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    public function test_signed_in_user_reports_a_problem_of_a_page(): void
    {
        $user = $this->actingAsRole('agency-agent');

        $this->postJson('/api/v1/issue-reports', [
            'page_path' => '/agency/quotas',
            'page_title' => 'Миний квот',
            'message' => 'Зорчигч нэмэхэд алдаа гарч байна.',
        ])->assertCreated()->assertJsonStructure(['message', 'data' => ['id']]);

        $report = IssueReport::query()->sole();
        $this->assertSame($user->id, $report->user_id);
        $this->assertSame($this->agency->id, $report->agency_id);
        $this->assertSame('/agency/quotas', $report->page_path);
        $this->assertSame(IssueReport::STATUS_OPEN, $report->status);
    }

    public function test_message_is_required_and_login_is_needed(): void
    {
        $this->postJson('/api/v1/issue-reports', ['page_path' => '/', 'message' => 'x'])->assertUnauthorized();

        $this->actingAsRole('airline-admin');
        $this->postJson('/api/v1/issue-reports', ['page_path' => '/', 'message' => ''])
            ->assertStatus(422)->assertJsonValidationErrors('message');
    }
}
