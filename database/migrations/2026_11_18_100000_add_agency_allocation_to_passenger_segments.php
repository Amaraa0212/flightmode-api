<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The quota each coupon was sold from. A round trip booked on two quotas (out on one, back on another of the same
 * agency) is one booking with the outbound quota: its return coupon belongs to the return quota, which has to count it
 * and list the passenger. Existing coupons are filled in: the booking's quota for its flights, otherwise the agency's
 * quota on that flight.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('passenger_segments', function (Blueprint $table) {
            $table->foreignId('agency_allocation_id')->nullable()->after('agency_allocation_fare_id')
                ->constrained('agency_allocations')->nullOnDelete()->comment('Quota the coupon was sold from');
        });

        $allocations = DB::table('agency_allocations')->get(['id', 'agency_id', 'outbound_flight_id', 'return_flight_id'])->keyBy('id');
        $byAgencyFlight = $allocations->filter(fn ($a) => ! $a->return_flight_id)
            ->groupBy(fn ($a) => $a->agency_id.'-'.$a->outbound_flight_id);

        DB::table('passenger_segments')
            ->join('passengers', 'passengers.id', '=', 'passenger_segments.passenger_id')
            ->join('bookings', 'bookings.id', '=', 'passengers.booking_id')
            ->whereNotNull('bookings.agency_id')
            ->select('passenger_segments.id', 'passenger_segments.flight_id', 'bookings.agency_id', 'bookings.agency_allocation_id')
            ->orderBy('passenger_segments.id')
            ->chunk(500, function ($segments) use ($allocations, $byAgencyFlight) {
                foreach ($segments as $segment) {
                    $own = $allocations->get($segment->agency_allocation_id);
                    $id = $own && in_array($segment->flight_id, [$own->outbound_flight_id, $own->return_flight_id], false)
                        ? $own->id
                        : $byAgencyFlight->get($segment->agency_id.'-'.$segment->flight_id)?->first()?->id;
                    if ($id) {
                        DB::table('passenger_segments')->where('id', $segment->id)->update(['agency_allocation_id' => $id]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('passenger_segments', fn (Blueprint $table) => $table->dropConstrainedForeignId('agency_allocation_id'));
    }
};
