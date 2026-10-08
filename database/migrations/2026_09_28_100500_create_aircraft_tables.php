<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aircraft', function (Blueprint $table) {
            $table->id();
            $table->foreignId('airline_id')->constrained('airlines')->restrictOnDelete();
            $table->string('registration', 20)->unique();
            $table->string('type', 50)->comment('e.g. B737-800');
            $table->string('seat_configuration', 50)->nullable()->comment('e.g. C12Y150');
            $table->unsignedSmallInteger('total_seats');
            $table->unsignedSmallInteger('max_infants')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('aircraft_cabins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aircraft_id')->constrained('aircraft')->cascadeOnDelete();
            $table->char('cabin_class', 1)->comment('F | C | W | Y');
            $table->unsignedSmallInteger('seat_count');
            $table->unique(['aircraft_id', 'cabin_class']);
        });

        // Seat map: one row per physical seat (legacy seatmaps)
        Schema::create('aircraft_seats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aircraft_id')->constrained('aircraft')->cascadeOnDelete();
            $table->unsignedSmallInteger('seat_row');
            $table->char('seat_column', 1);
            $table->string('seat_number', 4)->comment('row + column, e.g. 12A');
            $table->char('cabin_class', 1);
            $table->string('seat_type', 10)->comment('window | middle | aisle');
            $table->string('side', 10)->comment('left | middle | right');
            $table->string('zone', 5)->nullable()->comment('Balance zone, e.g. A, B, C');
            $table->boolean('is_emergency_exit')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['aircraft_id', 'seat_number']);
        });

        // Which zone is opened for seating at a given load (legacy airplane_rule)
        Schema::create('aircraft_load_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aircraft_id')->constrained('aircraft')->cascadeOnDelete();
            $table->string('zone', 5);
            $table->unsignedSmallInteger('pax_from');
            $table->unsignedSmallInteger('pax_to');
            $table->timestamps();
        });

        // Automatic seat assignment order by zone (legacy seat_zone)
        Schema::create('seat_assignment_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aircraft_id')->constrained('aircraft')->cascadeOnDelete();
            $table->string('name', 100);
            $table->timestamps();
        });

        Schema::create('seat_assignment_profile_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seat_assignment_profile_id')->constrained('seat_assignment_profiles')->cascadeOnDelete();
            $table->string('zone', 5);
            $table->unsignedSmallInteger('priority');
            $table->unique(['seat_assignment_profile_id', 'zone'], 'sap_zones_profile_zone_unique');
        });

        // Online check-in settings template applied to flights (legacy templates)
        Schema::create('service_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('airline_id')->constrained('airlines')->cascadeOnDelete();
            $table->foreignId('aircraft_id')->constrained('aircraft')->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('seat_assignment_profile_id')->nullable()->constrained('seat_assignment_profiles')->nullOnDelete();
            $table->unsignedSmallInteger('checkin_opens_minutes')->default(1440)->comment('Minutes before departure');
            $table->unsignedSmallInteger('checkin_closes_minutes')->default(60)->comment('Minutes before departure');
            $table->timestamps();
        });

        Schema::create('baggage_rule_service_template', function (Blueprint $table) {
            $table->foreignId('service_template_id')->constrained('service_templates')->cascadeOnDelete();
            $table->foreignId('baggage_rule_id')->constrained('baggage_rules')->cascadeOnDelete();
            $table->primary(['service_template_id', 'baggage_rule_id'], 'brst_primary');
        });

        Schema::create('seat_price_service_template', function (Blueprint $table) {
            $table->foreignId('service_template_id')->constrained('service_templates')->cascadeOnDelete();
            $table->foreignId('seat_price_id')->constrained('seat_prices')->cascadeOnDelete();
            $table->primary(['service_template_id', 'seat_price_id'], 'spst_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seat_price_service_template');
        Schema::dropIfExists('baggage_rule_service_template');
        Schema::dropIfExists('service_templates');
        Schema::dropIfExists('seat_assignment_profile_zones');
        Schema::dropIfExists('seat_assignment_profiles');
        Schema::dropIfExists('aircraft_load_zones');
        Schema::dropIfExists('aircraft_seats');
        Schema::dropIfExists('aircraft_cabins');
        Schema::dropIfExists('aircraft');
    }
};
