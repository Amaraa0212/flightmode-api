<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Open jaw on charters: an agency allowed to (set by a system administrator) books the return on another airline's
 * charter. One booking is one airline's, so the trip is two bookings, linked, with their passengers linked too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->boolean('open_jaw')->default(false)->after('chinggis_sync')
                ->comment('Charter round trips may return on another airline (system administrator)');
        });
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('linked_booking_id')->nullable()->after('agency_hold_id')->constrained('bookings')->nullOnDelete()
                ->comment('Open jaw: the booking of the other airline of the same trip');
        });
        Schema::table('passengers', function (Blueprint $table) {
            $table->foreignId('linked_passenger_id')->nullable()->after('infant_of_passenger_id')->constrained('passengers')->nullOnDelete()
                ->comment('Open jaw: the same person in the linked booking');
        });
    }

    public function down(): void
    {
        Schema::table('passengers', fn (Blueprint $table) => $table->dropConstrainedForeignId('linked_passenger_id'));
        Schema::table('bookings', fn (Blueprint $table) => $table->dropConstrainedForeignId('linked_booking_id'));
        Schema::table('agencies', fn (Blueprint $table) => $table->dropColumn('open_jaw'));
    }
};
