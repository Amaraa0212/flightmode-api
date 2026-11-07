<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['flights', 'flight_series'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->boolean('no_baggage_rule')->default(false)->after('service_type')->comment('Charter: bags are free with no kg limit (e.g. Oyu Tolgoi)');
            });
            // charters so far carried every bag free: they keep doing so until unchecked
            DB::table($table)->where('service_type', 'charter')->update(['no_baggage_rule' => true]);
        }

        Schema::table('checked_baggage', function (Blueprint $t) {
            $t->string('rush_passenger_name', 100)->nullable()->after('crew_name');
            $t->string('rush_original_flight', 50)->nullable()->after('rush_passenger_name');
            $t->string('rush_phone', 50)->nullable()->after('rush_original_flight');
        });
    }

    public function down(): void
    {
        Schema::table('checked_baggage', function (Blueprint $t) {
            $t->dropColumn(['rush_passenger_name', 'rush_original_flight', 'rush_phone']);
        });
        foreach (['flights', 'flight_series'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('no_baggage_rule'));
        }
    }
};
