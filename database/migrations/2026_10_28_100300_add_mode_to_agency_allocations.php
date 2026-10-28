<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| fixed: the allocation's seats are taken out of the flight for the agency (as before).
| pool:  nothing is taken out — the chosen agencies sell the flight's free seats together (free sale), each up
|        to an optional limit (seats / cabin seats, 0 = no limit). A seat one agency holds is gone for the others.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_allocations', function (Blueprint $table) {
            $table->string('mode', 10)->default('fixed')->after('return_flight_id')->comment('fixed | pool');
        });
    }

    public function down(): void
    {
        Schema::table('agency_allocations', function (Blueprint $table) {
            $table->dropColumn('mode');
        });
    }
};
