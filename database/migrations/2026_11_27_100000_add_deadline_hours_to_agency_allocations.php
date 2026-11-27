<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| deadline_hours: when the names deadline is the departure day itself, the agency may add names until this many hours
|                 before check-in opens (check-in opens flightmode.allocations.checkin_opens_hours before departure).
|                 Null = until check-in opens.
| released_cabins: per cabin, the unsold seats given back at the names deadline, so moving the deadline later
|                 gives them back to the agency.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_allocations', function (Blueprint $table) {
            $table->unsignedSmallInteger('deadline_hours')->nullable()->after('deadline');
            $table->json('released_cabins')->nullable()->after('released_seats');
        });
    }

    public function down(): void
    {
        Schema::table('agency_allocations', function (Blueprint $table) {
            $table->dropColumn(['deadline_hours', 'released_cabins']);
        });
    }
};
