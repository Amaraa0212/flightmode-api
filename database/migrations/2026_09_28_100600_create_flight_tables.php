<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Recurring schedule that generates flights (legacy scheduled_flights)
        Schema::create('flight_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('airline_id')->constrained('airlines')->cascadeOnDelete();
            $table->foreignId('aircraft_id')->nullable()->constrained('aircraft')->nullOnDelete();
            $table->string('flight_number', 10);
            $table->foreignId('origin_airport_id')->constrained('airports')->restrictOnDelete();
            $table->foreignId('destination_airport_id')->constrained('airports')->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('days_of_week', 13)->comment('ISO weekdays, e.g. "1,3,5"');
            $table->time('departure_time')->comment('Local time at origin');
            $table->time('arrival_time')->comment('Local time at destination');
            $table->unsignedTinyInteger('arrival_day_offset')->default(0);
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->string('gate', 10)->nullable();
            $table->decimal('excess_baggage_max_kg', 6, 1)->nullable();
            $table->decimal('excess_baggage_price_per_kg', 12, 2)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('flights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('airline_id')->constrained('airlines')->restrictOnDelete();
            $table->foreignId('aircraft_id')->nullable()->constrained('aircraft')->restrictOnDelete();
            $table->foreignId('flight_series_id')->nullable()->constrained('flight_series')->nullOnDelete();
            $table->string('flight_number', 10);
            $table->foreignId('origin_airport_id')->constrained('airports')->restrictOnDelete();
            $table->foreignId('destination_airport_id')->constrained('airports')->restrictOnDelete();
            $table->dateTime('scheduled_departure_at')->comment('UTC');
            $table->dateTime('scheduled_arrival_at')->comment('UTC');
            $table->dateTime('estimated_departure_at')->nullable()->comment('ETD, UTC');
            $table->dateTime('off_block_at')->nullable()->comment('OBT, UTC');
            $table->dateTime('takeoff_at')->nullable()->comment('UTC');
            $table->dateTime('estimated_arrival_at')->nullable()->comment('UTC');
            $table->string('terminal', 5)->nullable();
            $table->string('gate', 10)->nullable();
            $table->string('status', 20)->default('scheduled')->comment('scheduled | checkin_open | checkin_closed | gate_closed | departed | cancelled');
            $table->text('status_reason')->nullable()->comment('Delay / change / cancel reason');
            $table->boolean('is_published')->default(true)->comment('Visible to agencies');
            $table->decimal('excess_baggage_max_kg', 6, 1)->nullable();
            $table->decimal('excess_baggage_price_per_kg', 12, 2)->nullable();
            // Online check-in (legacy shop)
            $table->boolean('online_checkin_enabled')->default(false);
            $table->unsignedSmallInteger('online_checkin_opens_minutes')->nullable()->comment('Minutes before departure');
            $table->unsignedSmallInteger('online_checkin_closes_minutes')->nullable()->comment('Minutes before departure');
            $table->foreignId('seat_assignment_profile_id')->nullable()->constrained('seat_assignment_profiles')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['airline_id', 'scheduled_departure_at']);
            $table->index(['origin_airport_id', 'scheduled_departure_at']);
            $table->index(['flight_number', 'scheduled_departure_at']);
        });

        Schema::create('baggage_rule_flight', function (Blueprint $table) {
            $table->foreignId('flight_id')->constrained('flights')->cascadeOnDelete();
            $table->foreignId('baggage_rule_id')->constrained('baggage_rules')->cascadeOnDelete();
            $table->primary(['flight_id', 'baggage_rule_id']);
        });

        Schema::create('flight_seat_price', function (Blueprint $table) {
            $table->foreignId('flight_id')->constrained('flights')->cascadeOnDelete();
            $table->foreignId('seat_price_id')->constrained('seat_prices')->cascadeOnDelete();
            $table->primary(['flight_id', 'seat_price_id']);
        });

        // Seats that cannot be sold/assigned on a flight (legacy seat_block, one row per seat)
        Schema::create('flight_seat_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_id')->constrained('flights')->cascadeOnDelete();
            $table->string('seat_number', 4);
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['flight_id', 'seat_number']);
        });

        // Gate app staff assigned to a flight (legacy gate_users)
        Schema::create('flight_gate_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_id')->constrained('flights')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['flight_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_gate_agents');
        Schema::dropIfExists('flight_seat_blocks');
        Schema::dropIfExists('flight_seat_price');
        Schema::dropIfExists('baggage_rule_flight');
        Schema::dropIfExists('flights');
        Schema::dropIfExists('flight_series');
    }
};
