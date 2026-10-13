<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\PassengerType;
use App\Http\Controllers\Controller;
use App\Http\Resources\FareDiscountResource;
use App\Models\FareClass;
use App\Models\FareDiscount;
use App\Services\Access\AccessScope;
use App\Services\Fare\FareService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Age discounts of a fare class: /fare-classes/{fareClass}/discounts */
class FareDiscountController extends Controller
{
    public function __construct(private readonly AccessScope $access, private readonly FareService $fares) {}

    public function index(Request $request, FareClass $fareClass): AnonymousResourceCollection
    {
        $this->access->ensureAirlineOwned($request->user(), $fareClass);

        return FareDiscountResource::collection($fareClass->discounts()->orderBy('passenger_type')->get());
    }

    public function store(Request $request, FareClass $fareClass): JsonResponse
    {
        $this->access->ensureAirlineOwned($request->user(), $fareClass);
        $discount = $fareClass->discounts()->create($this->validated($request, $fareClass, null));
        $this->sync($fareClass);

        return $this->created('Fare discount created.', new FareDiscountResource($discount));
    }

    public function update(Request $request, FareClass $fareClass, FareDiscount $discount): JsonResponse
    {
        $this->ensureOwned($request, $fareClass, $discount);
        $discount->update($this->validated($request, $fareClass, $discount));
        $this->sync($fareClass);

        return $this->success('Fare discount updated.', new FareDiscountResource($discount));
    }

    public function destroy(Request $request, FareClass $fareClass, FareDiscount $discount): JsonResponse
    {
        $this->ensureOwned($request, $fareClass, $discount);
        $discount->delete();
        $this->sync($fareClass);

        return $this->deleted('Fare discount deleted.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, FareClass $fareClass, ?FareDiscount $discount): array
    {
        return $request->validate([
            // one discount per passenger type, it is what pricing reads
            'passenger_type' => ['required', Rule::enum(PassengerType::class),
                Rule::unique('fare_discounts')->where('fare_class_id', $fareClass->id)->ignore($discount?->id)],
            'age_from' => ['nullable', 'integer', 'min:0', 'max:120'],
            'age_to' => ['nullable', 'integer', 'min:0', 'max:120', Rule::when($request->filled('age_from'), 'gte:age_from')],
            'percentage' => ['required', 'integer', 'between:0,100'],
        ]);
    }

    private function ensureOwned(Request $request, FareClass $fareClass, FareDiscount $discount): void
    {
        $this->access->ensureAirlineOwned($request->user(), $fareClass);
        abort_unless($discount->fare_class_id === $fareClass->id, 404);
    }

    private function sync(FareClass $fareClass): void
    {
        $this->fares->syncWhere(fn (Builder $q) => $q->where('fare_class_id', $fareClass->id));
    }
}
