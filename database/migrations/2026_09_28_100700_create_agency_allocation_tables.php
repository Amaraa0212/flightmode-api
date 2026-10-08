<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Seats an airline gives an agency on a flight / round trip (legacy agent_schedule)
        Schema::create('agency_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('airline_id')->constrained('airlines')->restrictOnDelete();
            $table->foreignId('agency_id')->constrained('agencies')->restrictOnDelete();
            $table->foreignId('outbound_flight_id')->constrained('flights')->restrictOnDelete();
            $table->foreignId('return_flight_id')->nullable()->constrained('flights')->restrictOnDelete();
            $table->unsignedSmallInteger('seats')->comment('Total seats (ADT + CHD, infants not counted)');
            $table->date('deadline')->comment('Name list deadline');
            $table->boolean('uses_fare_quotas')->default(false)->comment('Passengers must be priced from agency_allocation_fares');
            $table->boolean('is_waitlist')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['agency_id', 'outbound_flight_id']);
        });

        // Seats per cabin inside an allocation (legacy agent_schedule.F/C/W/Y)
        Schema::create('agency_allocation_cabins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_allocation_id')->constrained('agency_allocations')->cascadeOnDelete();
            $table->char('cabin_class', 1);
            $table->unsignedSmallInteger('seats');
            $table->unique(['agency_allocation_id', 'cabin_class'], 'allocation_cabin_unique');
        });

        // Fare buckets an agency may sell from (legacy agent_fare_quota). fare_id null = manual fare.
        Schema::create('agency_allocation_fares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_allocation_id')->constrained('agency_allocations')->cascadeOnDelete();
            $table->foreignId('fare_id')->nullable()->constrained('fares')->nullOnDelete();
            $table->char('cabin_class', 1);
            $table->unsignedSmallInteger('seats')->default(0)->comment('0 = unlimited');
            $table->unsignedSmallInteger('min_fare_seats')->default(0)->comment('Seats sold at the lowest price (manual fares)');
            $table->decimal('fare_amount', 12, 2)->nullable()->comment('Manual fare only');
            $table->decimal('fare_max_amount', 12, 2)->nullable()->comment('Manual fare only');
            $table->decimal('tax_amount', 12, 2)->nullable()->comment('Manual fare only');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_allocation_fares');
        Schema::dropIfExists('agency_allocation_cabins');
        Schema::dropIfExists('agency_allocations');
    }
};
