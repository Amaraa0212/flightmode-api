<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Legacy seat_rule: a named rule for some rows (exit rows, front rows …), the lowest position wins
        Schema::table('seat_prices', function (Blueprint $table) {
            $table->string('name', 100)->nullable()->after('airline_id');
            $table->string('rows', 50)->nullable()->after('seat_type')->comment('e.g. 14-15 or 1-3,12; null = all rows');
            $table->unsignedSmallInteger('position')->default(10)->after('rows');
        });

        // A template without an aircraft applies to any aircraft (it then has no seat assignment profile)
        Schema::table('service_templates', function (Blueprint $table) {
            $table->foreignId('aircraft_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('seat_prices', function (Blueprint $table) {
            $table->dropColumn(['name', 'rows', 'position']);
        });
    }
};
