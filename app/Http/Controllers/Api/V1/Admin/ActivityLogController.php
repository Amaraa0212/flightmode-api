<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use App\Models\Flight;
use App\Services\Access\AccessScope;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Audit trail (legacy admin / DCS / passenger action logs in one list). */
class ActivityLogController extends Controller
{
    public function __construct(private readonly AccessScope $access) {}

    /**
     * Filters: channel, event, flight_id, passenger_id, user_id, from, to,
     * search (event, description, user, IP), flight (flight number), passenger (name, PNR, document number).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $logs = ActivityLog::query()->with(['user', 'flight', 'passenger.booking'])
            ->when(! $user->isSystemAdmin(), fn ($q) => $q->where(fn ($x) => $x
                ->whereIn('flight_id', $this->access->flights(Flight::query(), $user)->select('flights.id'))
                ->orWhereIn('user_id', fn ($u) => $u->select('id')->from('users')->where('airline_id', $user->airline_id ?? 0))))
            ->when($request->filled('channel'), fn ($q) => $q->where('channel', $request->string('channel')))
            ->when($request->filled('event'), fn ($q) => $q->where('event', 'like', $request->string('event').'%'))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search')->trim().'%';
                $q->where(fn ($x) => $x->where('event', 'like', $term)->orWhere('description', 'like', $term)
                    ->orWhere('ip_address', 'like', $term)
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $term)));
            })
            ->when($request->filled('flight'), fn ($q) => $q->whereHas('flight', fn ($f) => $f
                ->where('flight_number', 'like', $request->string('flight')->trim()->upper().'%')))
            ->when($request->filled('passenger'), function ($q) use ($request) {
                $term = $request->string('passenger')->trim();
                $q->whereHas('passenger', fn ($p) => $p->where('last_name', 'like', "%{$term}%")
                    ->orWhere('first_name', 'like', "%{$term}%")
                    ->orWhere('document_number', (string) $term)
                    ->orWhereHas('booking', fn ($b) => $b->where('pnr', $term->upper())));
            })
            ->when($request->filled('flight_id'), fn ($q) => $q->where('flight_id', $request->integer('flight_id')))
            ->when($request->filled('passenger_id'), fn ($q) => $q->where('passenger_id', $request->integer('passenger_id')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->date('to')->endOfDay()))
            ->latest('id')
            ->paginate($request->integer('per_page', 100));

        return ActivityLogResource::collection($logs);
    }
}
