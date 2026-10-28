<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Ticket time limit (TL): a booking can be held unpaid / without tickets until a deadline set by its fare class
| (hours after booking, and / or hours before departure — the earlier wins). Not ticketed by then = cancelled.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fare_classes', function (Blueprint $table) {
            $table->unsignedSmallInteger('ticket_time_limit_hours')->nullable()->after('group_max')
                ->comment('TL: hours after booking');
            $table->unsignedSmallInteger('ticket_time_limit_before_departure_hours')->nullable()->after('ticket_time_limit_hours')
                ->comment('TL: at the latest this many hours before departure');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('ticketing_deadline_at')->nullable()->after('status')->index()
                ->comment('on_hold: tickets must be issued before this, or the booking is cancelled');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('ticketing_deadline_at');
        });
        Schema::table('fare_classes', function (Blueprint $table) {
            $table->dropColumn(['ticket_time_limit_hours', 'ticket_time_limit_before_departure_hours']);
        });
    }
};
