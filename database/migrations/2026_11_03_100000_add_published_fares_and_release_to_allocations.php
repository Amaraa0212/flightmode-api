<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| uses_published_fares: the agency sells at the fare settings prices (fare bases of the route, with their rules)
|                       instead of fare buckets set on the allocation.
| released_at / released_seats: the unsold seats of a fixed allocation went back to the flight (pool / direct sale)
|                       when its names deadline passed.
| agency_holds.fare_id: the published fare a hold of such an allocation keeps for its names.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_allocations', function (Blueprint $table) {
            $table->boolean('uses_published_fares')->default(false)->after('uses_fare_quotas');
            $table->timestamp('released_at')->nullable()->after('deadline');
            $table->unsignedInteger('released_seats')->nullable()->after('released_at');
        });

        Schema::table('agency_holds', function (Blueprint $table) {
            $table->foreignId('fare_id')->nullable()->after('agency_allocation_fare_id')->constrained('fares')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('agency_holds', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fare_id');
        });
        Schema::table('agency_allocations', function (Blueprint $table) {
            $table->dropColumn(['uses_published_fares', 'released_at', 'released_seats']);
        });
    }
};
