<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Resources\AgencyAccountTransactionResource;
use App\Models\AgencyAccount;
use App\Models\AgencyAccountTransaction;
use App\Services\Access\AccessScope;
use App\Services\Agency\AccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Prepaid agency accounts: every contracted agency of the airline with its balance; top up / adjust. */
class AgencyAccountController extends Controller
{
    public function __construct(
        private readonly AccessScope $access,
        private readonly AccountService $accounts,
    ) {}

    /** One row per agency contract (agency_airline); `account` is null until the first top-up. Filter: search. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $rows = DB::table('agency_airline')
            ->join('agencies', 'agencies.id', '=', 'agency_airline.agency_id')
            ->join('airlines', 'airlines.id', '=', 'agency_airline.airline_id')
            ->leftJoin('agency_accounts', fn ($j) => $j->on('agency_accounts.agency_id', '=', 'agency_airline.agency_id')
                ->on('agency_accounts.airline_id', '=', 'agency_airline.airline_id'))
            ->when(! $user->isSystemAdmin(), fn ($q) => $q->where('agency_airline.airline_id', $user->airline_id ?? 0))
            ->when($request->filled('search'), fn ($q) => $q->where('agencies.name', 'like', '%'.$request->string('search').'%'))
            ->orderBy('agencies.name')
            ->get([
                'agencies.id as agency_id', 'agencies.name as agency', 'agency_airline.is_active as contract_active',
                'airlines.id as airline_id', 'airlines.name as airline', 'airlines.iata_code', 'airlines.currency as airline_currency',
                'agency_accounts.id as account_id', 'agency_accounts.balance', 'agency_accounts.currency', 'agency_accounts.updated_at',
            ]);

        return response()->json(['data' => $rows->map(fn ($r) => [
            'agency_id' => $r->agency_id,
            'agency' => $r->agency,
            'contract_active' => (bool) $r->contract_active,
            'airline' => ['id' => $r->airline_id, 'name' => $r->airline, 'iata_code' => $r->iata_code],
            'account_id' => $r->account_id,
            'balance' => $r->account_id ? $r->balance : null,
            'currency' => $r->currency ?? $r->airline_currency,
            'updated_at' => $r->updated_at ? Carbon::parse($r->updated_at, 'UTC')->toIso8601String() : null,
        ])]);
    }

    /** Top up (positive) or adjust (signed). The first one opens the account: from then on tickets are charged. */
    public function topUp(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'agency_id' => ['required', 'integer', 'exists:agencies,id'],
            'airline_id' => [$user->isSystemAdmin() ? 'required' : 'nullable', 'integer', 'exists:airlines,id'],
            'type' => ['sometimes', Rule::in([AgencyAccountTransaction::TOPUP, AgencyAccountTransaction::ADJUSTMENT])],
            'amount' => ['required', 'numeric', 'not_in:0', 'between:-999999999,999999999'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $airlineId = $this->access->airlineIdForWrite($user, $data['airline_id'] ?? null);

        $contracted = DB::table('agency_airline')->where('agency_id', $data['agency_id'])->where('airline_id', $airlineId)->exists();
        if (! $contracted) {
            throw BusinessRuleException::make('agency_not_contracted', 'This agency has no contract with the airline.');
        }

        $tx = $this->accounts->topUp($data['agency_id'], $airlineId, (float) $data['amount'],
            $data['type'] ?? AgencyAccountTransaction::TOPUP, $data['note'] ?? null, $user);

        return $this->created('Balance updated: '.number_format((float) $tx->balance_after, 2).'.',
            new AgencyAccountTransactionResource($tx->load('creator:id,name')));
    }

    public function transactions(Request $request, AgencyAccount $account): AnonymousResourceCollection
    {
        $this->access->ensureAirlineOwned($request->user(), $account);

        return AgencyAccountTransactionResource::collection(
            $account->transactions()->with(['passenger:id,first_name,last_name', 'booking:id,pnr', 'creator:id,name'])
                ->latest('id')->paginate($request->integer('per_page', 50))
        );
    }
}
