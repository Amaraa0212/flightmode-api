<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Fare family (brand a booking class is sold under), booking class advance purchase / upgrade flag,
| fare base min / max stay and which fare bases may be combined on one itinerary.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fare_families', function (Blueprint $table) {
            $table->id();
            $table->foreignId('airline_id')->constrained('airlines')->cascadeOnDelete();
            $table->string('code', 20)->comment('e.g. BASIC, FLEX');
            $table->string('name');
            $table->string('description')->nullable();
            $table->unsignedSmallInteger('rank')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['airline_id', 'code']);
        });

        Schema::table('rbds', function (Blueprint $table) {
            $table->foreignId('fare_family_id')->nullable()->after('parent_id')->constrained('fare_families')->nullOnDelete();
            $table->unsignedSmallInteger('advance_booking_days')->nullable()->after('rank')
                ->comment('Sold only when booked at least this many days before departure');
            $table->boolean('is_upgrade')->default(false)->after('advance_booking_days')
                ->comment('Booking class used for upgrades');
        });

        Schema::table('fare_classes', function (Blueprint $table) {
            $table->unsignedSmallInteger('min_stay_days')->nullable()->after('trip_type')
                ->comment('RT: return at least this many days after departure');
            $table->unsignedSmallInteger('max_stay_days')->nullable()->after('min_stay_days')
                ->comment('RT: return at the latest this many days after departure');
        });

        // Fare bases that may be sold together (e.g. outbound + return); stored once per pair
        Schema::create('fare_combinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('airline_id')->constrained('airlines')->cascadeOnDelete();
            $table->foreignId('fare_class_id')->constrained('fare_classes')->cascadeOnDelete();
            $table->foreignId('combinable_fare_class_id')->constrained('fare_classes')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['fare_class_id', 'combinable_fare_class_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fare_combinations');
        Schema::table('fare_classes', function (Blueprint $table) {
            $table->dropColumn(['min_stay_days', 'max_stay_days']);
        });
        Schema::table('rbds', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fare_family_id');
            $table->dropColumn(['advance_booking_days', 'is_upgrade']);
        });
        Schema::dropIfExists('fare_families');
    }
};
