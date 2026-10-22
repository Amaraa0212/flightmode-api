<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Scheduled (fares) or charter (contract; bags are free at the counter)
        Schema::table('flights', function (Blueprint $table) {
            $table->string('service_type', 10)->default('scheduled')->after('flight_series_id')->index()->comment('scheduled | charter');
        });
        Schema::table('flight_series', function (Blueprint $table) {
            $table->string('service_type', 10)->default('scheduled')->after('flight_number')->comment('scheduled | charter');
        });
        Schema::table('checked_baggage', function (Blueprint $table) {
            $table->string('type', 10)->default('passenger')->comment('passenger | crew | cargo')->change();
        });
    }

    public function down(): void
    {
        Schema::table('flights', function (Blueprint $table) {
            $table->dropIndex(['service_type']);
            $table->dropColumn('service_type');
        });
        Schema::table('flight_series', function (Blueprint $table) {
            $table->dropColumn('service_type');
        });
    }
};
