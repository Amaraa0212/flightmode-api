<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\BaggageRuleType;
use App\Enums\CabinClass;
use App\Exceptions\BusinessRuleException;
use App\Http\Resources\BaggageRuleGroupResource;
use App\Models\BaggageRule;
use App\Models\BaggageRuleGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Baggage rules page: a bag's common details plus its SSR rows (FBAG / XBAG / EBAG with kg, pieces and price).
 * Each SSR row is saved as a `baggage_rules` record of the group; an SSR appears once per group.
 */
class BaggageRuleGroupController extends AirlineCrudController
{
    protected function model(): string
    {
        return BaggageRuleGroup::class;
    }

    protected function resource(): string
    {
        return BaggageRuleGroupResource::class;
    }

    protected function label(): string
    {
        return 'Baggage rule';
    }

    protected function with(): array
    {
        return ['rules'];
    }

    protected function nonAttributes(): array
    {
        return ['rules'];
    }

    protected function rules(Request $request, ?Model $model): array
    {
        $required = $model ? 'sometimes' : 'required';
        // SSR codes are compared upper case (fbag = FBAG)
        if (is_array($request->input('rules'))) {
            $request->merge(['rules' => array_map(
                fn ($row) => is_array($row) && is_string($row['ssr_code'] ?? null) ? ['ssr_code' => strtoupper(trim($row['ssr_code']))] + $row : $row,
                $request->input('rules'))]);
        }

        return [
            'name' => [$required, 'string', 'max:255'],
            'type' => [$required, Rule::enum(BaggageRuleType::class)],
            'cabin_class' => ['nullable', Rule::enum(CabinClass::class)],
            'max_quantity' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'is_active' => ['boolean'],
            'rules' => [$required, 'array', 'min:1', 'max:'.count(BaggageRuleGroup::SSR_CODES)],
            'rules.*.id' => ['nullable', 'integer'],
            'rules.*.ssr_code' => ['required', 'string', Rule::in(BaggageRuleGroup::SSR_CODES), 'distinct'],
            'rules.*.pieces' => ['nullable', 'integer', 'min:0'],
            'rules.*.weight_kg' => ['nullable', 'numeric', 'min:0'],
            'rules.*.price' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    protected function messages(): array
    {
        return [
            'rules.*.ssr_code.distinct' => 'Each SSR (FBAG, XBAG, EBAG) can be added once per baggage rule.',
            'rules.*.ssr_code.in' => 'SSR must be FBAG, XBAG or EBAG.',
            'rules.min' => 'Add at least one SSR (FBAG, XBAG or EBAG).',
        ];
    }

    /** Write the SSR rows: kept rows are updated, new ones created, missing ones deleted; all carry the group's details */
    protected function afterSave(Model $model, array $data): void
    {
        /** @var BaggageRuleGroup $model */
        $model->refresh(); // column defaults (currency, is_active) of a new group
        $existing = $model->rules()->get()->keyBy('id');
        $shared = $model->sharedAttributes();

        if (array_key_exists('rules', $data)) {
            $rows = collect($data['rules'])->map(fn (array $row) => $row + ['ssr_code' => null]);
            $keep = $rows->pluck('id')->filter()->map(fn ($id) => (int) $id);
            $foreign = $keep->reject(fn (int $id) => $existing->has($id));
            if ($foreign->isNotEmpty()) {
                throw BusinessRuleException::make('baggage_rule_not_in_group', 'An SSR row does not belong to this baggage rule.');
            }

            $removed = $existing->keys()->diff($keep)->values()->all();
            $this->ensureNotInTemplates($removed);
            // deleted first, without model events (the group is not dropped while its new rows are still to come)
            BaggageRule::query()->whereKey($removed)->delete();

            foreach ($rows as $row) {
                $ssr = strtoupper($row['ssr_code']);
                $attributes = $shared + [
                    'name' => $model->name,
                    'ssr_code' => $ssr,
                    'pieces' => $row['pieces'] ?? null,
                    'weight_kg' => $row['weight_kg'] ?? null,
                    // FBAG is the free allowance: never priced
                    'price' => $ssr === BaggageRule::SSR_FREE ? 0 : ($row['price'] ?? 0),
                ];
                if (! empty($row['id'])) {
                    $existing[(int) $row['id']]->update($attributes);
                } else {
                    $model->rules()->create($attributes);
                }
            }

            return;
        }

        // details only: copied onto the rows already there
        $existing->each->update($shared + ['name' => $model->name]);
    }

    /** A rule a service template (Shop items) still uses stays: take it out of the template first */
    protected function beforeDelete(Model $model): void
    {
        /** @var BaggageRuleGroup $model */
        $this->ensureNotInTemplates($model->rules()->pluck('id')->all());
    }

    /** @param array<int, int> $ruleIds */
    private function ensureNotInTemplates(array $ruleIds): void
    {
        if (! $ruleIds) {
            return;
        }
        $templates = DB::table('baggage_rule_service_template')
            ->join('service_templates', 'service_templates.id', '=', 'baggage_rule_service_template.service_template_id')
            ->whereIn('baggage_rule_service_template.baggage_rule_id', $ruleIds)
            ->distinct()->pluck('service_templates.name');
        if ($templates->isNotEmpty()) {
            throw BusinessRuleException::make('baggage_rule_in_template',
                'This baggage rule is used by the template(s) '.$templates->implode(', ').': remove it there before deleting it.');
        }
    }
}
