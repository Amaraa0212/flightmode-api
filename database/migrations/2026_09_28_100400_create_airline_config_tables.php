<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SSRs an airline offers, with optional price (legacy ssr_permissions)
        Schema::create('airline_ssrs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('airline_id')->constrained('airlines')->cascadeOnDelete();
            $table->foreignId('ssr_code_id')->constrained('ssr_codes')->cascadeOnDelete();
            $table->decimal('price', 12, 2)->nullable();
            $table->char('currency', 3)->default('MNT');
            $table->timestamps();
            $table->unique(['airline_id', 'ssr_code_id']);
        });

        // Checked / excess baggage products (legacy baggage_rule)
        Schema::create('baggage_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('airline_id')->constrained('airlines')->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 10)->comment('piece | weight');
            $table->char('cabin_class', 1)->nullable()->comment('F | C | W | Y, null = all');
            $table->unsignedSmallInteger('pieces')->nullable();
            $table->decimal('weight_kg', 6, 1)->nullable();
            $table->unsignedSmallInteger('max_quantity')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->char('currency', 3)->default('MNT');
            $table->string('ssr_code', 8)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Passenger-facing baggage policy texts (legacy cabin_baggage_rule)
        Schema::create('baggage_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('airline_id')->nullable()->constrained('airlines')->cascadeOnDelete();
            $table->string('type', 20)->default('cabin')->comment('cabin | checked');
            $table->string('locale', 5)->default('mn');
            $table->string('title');
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('fares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('airline_id')->constrained('airlines')->cascadeOnDelete();
            $table->foreignId('origin_airport_id')->constrained('airports')->restrictOnDelete();
            $table->foreignId('destination_airport_id')->constrained('airports')->restrictOnDelete();
            $table->string('trip_type', 2)->default('OW')->comment('OW | RT');
            $table->char('cabin_class', 1);
            $table->string('fare_class', 2)->comment('Booking class letter');
            $table->date('valid_from');
            $table->date('valid_to');
            $table->decimal('base_amount', 12, 2);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->unsignedTinyInteger('child_discount_percent')->default(0)->comment('CHD pays base * (100 - x) / 100');
            $table->unsignedTinyInteger('infant_discount_percent')->default(90)->comment('INF pays base * (100 - x) / 100, no tax');
            $table->char('currency', 3)->default('MNT');
            $table->text('conditions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['airline_id', 'origin_airport_id', 'destination_airport_id', 'valid_from'], 'fares_route_lookup_index');
        });

        // Paid seat selection prices (legacy seat_rule)
        Schema::create('seat_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('airline_id')->constrained('airlines')->cascadeOnDelete();
            $table->char('cabin_class', 1);
            $table->string('seat_type', 10)->nullable()->comment('window | middle | aisle, null = any');
            $table->decimal('price', 12, 2);
            $table->char('currency', 3)->default('MNT');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seat_prices');
        Schema::dropIfExists('fares');
        Schema::dropIfExists('baggage_policies');
        Schema::dropIfExists('baggage_rules');
        Schema::dropIfExists('airline_ssrs');
    }
};
