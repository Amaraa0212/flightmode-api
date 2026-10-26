<?php

namespace App\Http\Controllers\Api\V1\Agency;

use App\Http\Controllers\Controller;
use App\Http\Resources\AgencyAccountTransactionResource;
use App\Models\AgencyAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Agency portal: the agency's prepaid balance with each airline and its movements. */
class AccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $accounts = AgencyAccount::query()->with('airline:id,name,iata_code')
            ->where('agency_id', $request->user()->agency_id ?? 0)
            ->orderBy('airline_id')->get();

        return response()->json(['data' => $accounts->map(fn (AgencyAccount $a) => [
            'id' => $a->id,
            'airline' => $a->airline ? ['id' => $a->airline->id, 'name' => $a->airline->name, 'iata_code' => $a->airline->iata_code] : null,
            'balance' => $a->balance,
            'currency' => $a->currency,
            'updated_at' => $a->updated_at?->toIso8601String(),
        ])]);
    }

    public function transactions(Request $request, AgencyAccount $account): AnonymousResourceCollection
    {
        abort_unless($request->user()->isSystemAdmin() || (int) $account->agency_id === (int) $request->user()->agency_id, 403);

        return AgencyAccountTransactionResource::collection(
            $account->transactions()->with(['passenger:id,first_name,last_name', 'booking:id,pnr', 'creator:id,name'])
                ->latest('id')->paginate($request->integer('per_page', 50))
        );
    }
}
