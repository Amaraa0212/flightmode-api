<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Fare settings (legacy flightmodedo `rbd`, `fare_class`, `fare_discount`, `fare`, `fare_tax`, `tax_code`, `modification`).
| `fares` keeps its flat columns for now; a fare linked to a fare class gets them synced by FareService.
*/
return new class extends Migration
{
    public function up(): void
    {
        // Reservation booking designator: booking class letter inside a cabin
        Schema::create('rbds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('airline_id')->constrained('airlines')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('rbds')->restrictOnDelete();
            $table->string('code', 2)->comment('A-Z');
            $table->char('cabin_class', 1)->comment('F | C | W | Y');
            $table->unsignedSmallInteger('rank')->default(0)->comment('Sort / sell order inside the cabin');
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['airline_id', 'code']);
        });

        Schema::create('fare_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('airline_id')->constrained('airlines')->cascadeOnDelete();
            $table->foreignId('rbd_id')->constrained('rbds')->restrictOnDelete();
            $table->string('booking_code', 10)->comment('A-Z / 0-9, e.g. YOW');
            $table->string('description', 100)->nullable();
            $table->string('status', 10)->default('HK')->comment('HK confirmed | SA stand-by');
            $table->boolean('waitlist')->default(false)->comment('HL allowed when sold out');
            $table->boolean('is_visible')->default(true);
            $table->string('trip_type', 2)->default('OW')->comment('OW | RT');
            $table->text('rule')->nullable();
            $table->boolean('is_group')->default(false);
            $table->unsignedSmallInteger('group_min')->nullable();
            $table->unsignedSmallInteger('group_max')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['airline_id', 'booking_code']);
        });

        Schema::create('fare_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fare_class_id')->constrained('fare_classes')->cascadeOnDelete();
            $table->char('passenger_type', 3)->comment('ADT | CHD | INF');
            $table->unsignedTinyInteger('age_from')->nullable();
            $table->unsignedTinyInteger('age_to')->nullable();
            $table->unsignedTinyInteger('percentage')->comment('Pays base * (100 - x) / 100');
            $table->timestamps();
        });

        Schema::create('tax_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('airline_id')->constrained('airlines')->cascadeOnDelete();
            $table->string('code', 10)->comment('e.g. YQ, MN');
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['airline_id', 'code']);
        });

        Schema::table('fares', function (Blueprint $table) {
            $table->foreignId('fare_class_id')->nullable()->after('airline_id')->constrained('fare_classes')->restrictOnDelete();
        });

        // Default tax per airport (fare_id null) or an override saved on one fare
        Schema::create('fare_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('airline_id')->constrained('airlines')->cascadeOnDelete();
            $table->foreignId('tax_code_id')->constrained('tax_codes')->restrictOnDelete();
            $table->foreignId('airport_id')->nullable()->constrained('airports')->restrictOnDelete();
            $table->string('bound', 3)->default('OUT')->comment('OUT departing the airport | IN arriving at it');
            $table->foreignId('fare_id')->nullable()->constrained('fares')->cascadeOnDelete()->comment('null = default for the airport');
            $table->char('passenger_type', 3)->comment('ADT | CHD | INF');
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('MNT');
            $table->boolean('refundable')->default(true);
            $table->timestamps();
            $table->index(['airline_id', 'airport_id', 'bound']);
        });

        // Change / refund / no-show penalties per RBD
        Schema::create('rbd_modifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('airline_id')->constrained('airlines')->cascadeOnDelete();
            $table->foreignId('rbd_id')->constrained('rbds')->cascadeOnDelete();
            $table->string('type', 20)->comment('REFUND | NAME_CHANGE | DATE_CHANGE | ROUTE_CHANGE | CANCEL | NOSHOW');
            $table->unsignedInteger('timeframe_hours')->default(0)->comment('Applies when less than this many hours before departure');
            $table->decimal('amount', 12, 2)->nullable();
            $table->unsignedTinyInteger('percentage')->nullable();
            $table->boolean('is_allowed')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rbd_modifications');
        Schema::dropIfExists('fare_taxes');
        Schema::table('fares', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fare_class_id');
        });
        Schema::dropIfExists('tax_codes');
        Schema::dropIfExists('fare_discounts');
        Schema::dropIfExists('fare_classes');
        Schema::dropIfExists('rbds');
    }
};
