<?php

namespace App\Http\Requests\Shared;

use App\Enums\CabinClass;
use App\Enums\Gender;
use Illuminate\Validation\Rule;

/** Validation rules of passenger fields, shared by every endpoint that creates or edits passengers. */
final class PassengerFields
{
    /** A 4-digit year first: `date` alone lets typos like 199001-05-12 through. */
    private const FOUR_DIGIT_YEAR = 'regex:/^\d{4}-\d{2}-\d{2}/';

    /** @return array<string, mixed> */
    public static function rules(string $prefix = '', bool $creating = true, bool $withTravel = true): array
    {
        $required = $creating ? 'required' : 'sometimes';

        $rules = [
            $prefix.'first_name' => [$required, 'string', 'max:100'],
            $prefix.'middle_name' => ['nullable', 'string', 'max:100'],
            $prefix.'last_name' => [$required, 'string', 'max:100'],
            $prefix.'gender' => ['nullable', Rule::enum(Gender::class)],
            $prefix.'date_of_birth' => ['nullable', 'date', self::FOUR_DIGIT_YEAR, 'after:1900-01-01', 'before:today'],
            $prefix.'nationality' => ['nullable', 'string', 'between:2,3'],
            $prefix.'person_code' => ['nullable', 'string', 'max:20'],
            $prefix.'document_type' => ['nullable', 'string', 'max:20'],
            $prefix.'document_number' => ['nullable', 'string', 'max:50'],
            $prefix.'document_issuing_country' => ['nullable', 'string', 'between:2,3'],
            $prefix.'document_issued_at' => ['nullable', 'date'],
            $prefix.'document_expires_at' => ['nullable', 'date'],
            $prefix.'email' => ['nullable', 'email', 'max:255'],
            $prefix.'phone' => ['nullable', 'string', 'max:50'],
            $prefix.'emergency_contact' => ['nullable', 'string', 'max:255'],
            $prefix.'remark' => ['nullable', 'string', 'max:255'],
        ];

        if ($withTravel) {
            $rules += [
                $prefix.'cabin_class' => [$creating ? 'required' : 'sometimes', Rule::enum(CabinClass::class)],
                $prefix.'allocation_fare_id' => ['nullable', 'integer'],
                $prefix.'return_allocation_fare_id' => ['nullable', 'integer'],
                $prefix.'fare_id' => ['nullable', 'integer', 'exists:fares,id'],
                $prefix.'agency_sale_amount' => ['nullable', 'numeric', 'min:0'],
                $prefix.'ssr_codes' => ['sometimes', 'array'],
                $prefix.'ssr_codes.*' => ['string', 'max:8'],
                $prefix.'prepaid_baggage' => ['sometimes', 'array'],
                $prefix.'prepaid_baggage.pieces' => ['nullable', 'integer', 'min:0', 'max:10'],
                $prefix.'prepaid_baggage.weight_kg' => ['nullable', 'numeric', 'min:0'],
                $prefix.'infant' => ['sometimes', 'nullable', 'array'],
            ];
            foreach (self::rules($prefix.'infant.', true, false) as $field => $fieldRules) {
                $rules[$field] = array_map(fn ($r) => $r === 'required' ? 'required_with:'.$prefix.'infant' : $r, $fieldRules);
            }
            $rules[$prefix.'infant.date_of_birth'] = ['required_with:'.$prefix.'infant', 'nullable', 'date', self::FOUR_DIGIT_YEAR, 'after:1900-01-01', 'before_or_equal:today'];
        }

        return $rules;
    }
}
