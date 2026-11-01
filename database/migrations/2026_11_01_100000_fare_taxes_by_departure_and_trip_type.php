<?php

use App\Services\Fare\FareService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Fare taxes are charged by the departure airport only (every fare departing it), split by trip type OW / RT.
| Replaces `bound` (OUT departing / IN arriving): OUT airport lines become OW, IN airport lines are dropped,
| fare overrides take their fare's trip type.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fare_taxes', function (Blueprint $table) {
            $table->string('trip_type', 2)->default('OW')->after('airport_id')->comment('OW | RT: fares of this trip type departing the airport');
        });

        DB::table('fare_taxes')->whereNull('fare_id')->where('bound', 'IN')->delete();
        DB::table('fare_taxes')->whereNotNull('fare_id')->update([
            'trip_type' => DB::raw('(select fares.trip_type from fares where fares.id = fare_taxes.fare_id)'),
        ]);

        Schema::table('fare_taxes', function (Blueprint $table) {
            // new index first: the airline_id foreign key needs one
            $table->index(['airline_id', 'airport_id', 'trip_type']);
        });
        Schema::table('fare_taxes', function (Blueprint $table) {
            $table->dropIndex(['airline_id', 'airport_id', 'bound']);
            $table->dropColumn('bound');
        });

        // flat fares.tax_amount follows the new rule
        app(FareService::class)->syncWhere(fn () => null);
    }

    public function down(): void
    {
        Schema::table('fare_taxes', function (Blueprint $table) {
            $table->string('bound', 3)->default('OUT')->after('airport_id');
            $table->index(['airline_id', 'airport_id', 'bound']);
        });
        Schema::table('fare_taxes', function (Blueprint $table) {
            $table->dropIndex(['airline_id', 'airport_id', 'trip_type']);
            $table->dropColumn('trip_type');
        });
    }
};
