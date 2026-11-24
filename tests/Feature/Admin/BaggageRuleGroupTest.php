<?php

namespace Tests\Feature\Admin;

use App\Models\BaggageRule;
use App\Models\BaggageRuleGroup;
use App\Models\ServiceTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

/** Baggage rules page: a bag's common details with SSR rows, each row saved as a baggage rule of the group. */
class BaggageRuleGroupTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->actingAsRole('airline-admin');
    }

    private function payload(array $rules, array $overrides = []): array
    {
        return $overrides + ['name' => 'Economy 1PC', 'type' => 'piece', 'cabin_class' => 'Y', 'max_quantity' => 1, 'is_active' => true, 'rules' => $rules];
    }

    public function test_a_group_saves_one_rule_per_ssr_with_the_groups_details(): void
    {
        $id = $this->postJson('/api/v1/admin/baggage-rule-groups', $this->payload([
            ['ssr_code' => 'FBAG', 'pieces' => 1, 'weight_kg' => 23, 'price' => 99000],
            ['ssr_code' => 'xbag', 'pieces' => 1, 'weight_kg' => 23, 'price' => 150000],
            ['ssr_code' => 'EBAG', 'weight_kg' => 1, 'price' => 10000],
        ]))->assertCreated()->assertJsonPath('data.rules.1.ssr_code', 'XBAG')->json('data.id');

        $rules = BaggageRule::query()->where('baggage_rule_group_id', $id)->get();
        $this->assertCount(3, $rules);
        foreach ($rules as $rule) {
            $this->assertSame('Economy 1PC', $rule->name);
            $this->assertSame('Y', $rule->cabin_class);
            $this->assertSame($this->airline->id, $rule->airline_id);
        }
        $this->assertSame('150000.00', $rules->firstWhere('ssr_code', 'XBAG')->price);
        // FBAG is free: a price sent with it is not kept
        $this->assertSame('0.00', $rules->firstWhere('ssr_code', 'FBAG')->price);

        // the rules list (flights, templates, counter) still has every row
        $this->getJson('/api/v1/admin/baggage-rules')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_an_ssr_cannot_be_used_twice_in_a_group_on_create_or_edit(): void
    {
        $this->postJson('/api/v1/admin/baggage-rule-groups', $this->payload([
            ['ssr_code' => 'FBAG', 'weight_kg' => 23], ['ssr_code' => 'fbag', 'weight_kg' => 20],
        ]))->assertStatus(422)->assertJsonValidationErrors('rules.1.ssr_code');
        $this->postJson('/api/v1/admin/baggage-rule-groups', $this->payload([['ssr_code' => 'ABAG']]))
            ->assertStatus(422)->assertJsonValidationErrors('rules.0.ssr_code');
        $this->postJson('/api/v1/admin/baggage-rule-groups', $this->payload([]))->assertStatus(422)->assertJsonValidationErrors('rules');

        $group = $this->postJson('/api/v1/admin/baggage-rule-groups', $this->payload([['ssr_code' => 'FBAG', 'weight_kg' => 23]]))->json('data');
        $this->putJson("/api/v1/admin/baggage-rule-groups/{$group['id']}", $this->payload([
            ['id' => $group['rules'][0]['id'], 'ssr_code' => 'FBAG', 'weight_kg' => 23], ['ssr_code' => 'FBAG', 'weight_kg' => 30],
        ]))->assertStatus(422)->assertJsonValidationErrors('rules.1.ssr_code');
        $this->assertSame(1, BaggageRule::query()->where('baggage_rule_group_id', $group['id'])->count());
    }

    public function test_edit_keeps_updates_adds_and_removes_rows(): void
    {
        $group = $this->postJson('/api/v1/admin/baggage-rule-groups', $this->payload([
            ['ssr_code' => 'FBAG', 'pieces' => 1, 'weight_kg' => 23], ['ssr_code' => 'XBAG', 'pieces' => 1, 'price' => 150000],
        ]))->json('data');
        [$fbag, $xbag] = array_column($group['rules'], 'id');
        $flight = $this->makeFlight();
        $flight->baggageRules()->attach($fbag);

        $this->putJson("/api/v1/admin/baggage-rule-groups/{$group['id']}", $this->payload([
            ['id' => $fbag, 'ssr_code' => 'FBAG', 'pieces' => 1, 'weight_kg' => 20],
            ['ssr_code' => 'EBAG', 'weight_kg' => 1, 'price' => 8000],
        ], ['name' => 'Economy 1PC 20KG', 'cabin_class' => null]))->assertOk()->assertJsonCount(2, 'data.rules');

        $kept = BaggageRule::query()->findOrFail($fbag);
        $this->assertSame('20.0', $kept->weight_kg);
        $this->assertSame('Economy 1PC 20KG', $kept->name);
        $this->assertNull($kept->cabin_class);
        // the flight keeps the same rule
        $this->assertTrue($flight->baggageRules()->whereKey($fbag)->exists());
        $this->assertNull(BaggageRule::query()->find($xbag));
        $this->assertTrue(BaggageRule::query()->where('baggage_rule_group_id', $group['id'])->where('ssr_code', 'EBAG')->exists());

        // an SSR can move between rows: FBAG removed and added back in the same save
        $this->putJson("/api/v1/admin/baggage-rule-groups/{$group['id']}", $this->payload([['ssr_code' => 'FBAG', 'weight_kg' => 23]]))->assertOk();
        $this->assertSame(['FBAG'], BaggageRule::query()->where('baggage_rule_group_id', $group['id'])->pluck('ssr_code')->all());
    }

    public function test_a_row_of_another_group_is_refused(): void
    {
        $a = $this->postJson('/api/v1/admin/baggage-rule-groups', $this->payload([['ssr_code' => 'FBAG']]))->json('data');
        $b = $this->postJson('/api/v1/admin/baggage-rule-groups', $this->payload([['ssr_code' => 'FBAG']], ['name' => 'Other']))->json('data');

        $this->putJson("/api/v1/admin/baggage-rule-groups/{$a['id']}", $this->payload([['id' => $b['rules'][0]['id'], 'ssr_code' => 'FBAG']]))
            ->assertStatus(422)->assertJsonPath('code', 'baggage_rule_not_in_group');
    }

    public function test_a_group_used_by_a_template_is_not_deleted_and_a_lone_rule_gets_its_own_group(): void
    {
        $rule = BaggageRule::create(['airline_id' => $this->airline->id, 'name' => '1PC', 'type' => 'piece', 'pieces' => 1, 'weight_kg' => 23, 'ssr_code' => 'FBAG']);
        $this->assertNotNull($rule->baggage_rule_group_id);
        $template = ServiceTemplate::create(['airline_id' => $this->airline->id, 'aircraft_id' => $this->aircraft->id, 'name' => 'Summer']);
        $template->baggageRules()->attach($rule->id);

        $this->deleteJson("/api/v1/admin/baggage-rule-groups/{$rule->baggage_rule_group_id}")->assertStatus(422)->assertJsonPath('code', 'baggage_rule_in_template');
        $this->putJson("/api/v1/admin/baggage-rule-groups/{$rule->baggage_rule_group_id}", $this->payload([['ssr_code' => 'EBAG']]))
            ->assertStatus(422)->assertJsonPath('code', 'baggage_rule_in_template');

        $template->baggageRules()->detach();
        $this->deleteJson("/api/v1/admin/baggage-rule-groups/{$rule->baggage_rule_group_id}")->assertOk();
        $this->assertNull(BaggageRule::query()->find($rule->id));

        // deleting a group's last rule on its own drops the group too
        $lone = BaggageRule::create(['airline_id' => $this->airline->id, 'name' => 'EB', 'type' => 'weight', 'weight_kg' => 1, 'ssr_code' => 'EBAG']);
        $this->deleteJson("/api/v1/admin/baggage-rules/{$lone->id}")->assertOk();
        $this->assertNull(BaggageRuleGroup::query()->find($lone->baggage_rule_group_id));
    }

    public function test_a_template_lists_the_names_of_its_bags(): void
    {
        $group = $this->postJson('/api/v1/admin/baggage-rule-groups', $this->payload([['ssr_code' => 'FBAG'], ['ssr_code' => 'EBAG']]))->json('data');

        $this->postJson('/api/v1/admin/service-templates', ['name' => 'Summer', 'baggage_rule_ids' => array_column($group['rules'], 'id')])
            ->assertCreated()->assertJsonPath('data.baggage_rule_groups', ['Economy 1PC'])->assertJsonCount(2, 'data.baggage_rule_ids');
    }
}
