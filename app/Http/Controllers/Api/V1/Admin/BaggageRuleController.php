<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\BaggageRuleType;
use App\Enums\CabinClass;
use App\Exceptions\BusinessRuleException;
use App\Http\Resources\BaggageRuleResource;
use App\Models\BaggageRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BaggageRuleController extends AirlineCrudController
{
    protected function model(): string
    {
        return BaggageRule::class;
    }

    protected function resource(): string
    {
        return BaggageRuleResource::class;
    }

    protected function label(): string
    {
        return 'Baggage rule';
    }

    /** A rule a service template (Shop items) still uses stays: take it out of the template first */
    protected function beforeDelete(Model $model): void
    {
        $templates = DB::table('baggage_rule_service_template')
            ->join('service_templates', 'service_templates.id', '=', 'baggage_rule_service_template.service_template_id')
            ->where('baggage_rule_service_template.baggage_rule_id', $model->getKey())
            ->pluck('service_templates.name');
        if ($templates->isNotEmpty()) {
            throw BusinessRuleException::make('baggage_rule_in_template',
                'This baggage rule is used by the template(s) '.$templates->implode(', ').': remove it there before deleting it.');
        }
    }

    protected function rules(Request $request, ?Model $model): array
    {
        $required = $model ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:255'],
            'type' => [$required, Rule::enum(BaggageRuleType::class)],
            'cabin_class' => ['nullable', Rule::enum(CabinClass::class)],
            'pieces' => ['nullable', 'integer', 'min:0'],
            'weight_kg' => ['nullable', 'numeric', 'min:0'],
            'max_quantity' => ['nullable', 'integer', 'min:0'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'ssr_code' => ['nullable', 'string', 'max:8'],
            'is_active' => ['boolean'],
        ];
    }
}
