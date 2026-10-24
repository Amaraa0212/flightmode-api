<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Seat inventory per flight (legacy ameliaRES "Seat Allocations"): seats per cabin and a nested booking limit per RBD.
| Sold counts are not stored, they come from passenger_segments.fare_class. A flight without cabin rows is not inventory-controlled.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flight_cabin_inventory', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_id')->constrained('flights')->cascadeOnDelete();
            $table->char('cabin_class', 1)->comment('F | C | W | Y');
            $table->unsignedSmallInteger('seats')->comment('Seats sold in this cabin on this flight');
            $table->unsignedSmallInteger('oversell')->default(0)->comment('Extra seats that may be sold above seats');
            $table->unsignedSmallInteger('curtail')->default(0)->comment('Seats taken out of sale');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['flight_id', 'cabin_class']);
        });

        Schema::create('flight_rbd_inventory', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_id')->constrained('flights')->cascadeOnDelete();
            $table->foreignId('rbd_id')->constrained('rbds')->cascadeOnDelete();
            $table->unsignedSmallInteger('seats')->comment('Nested limit: this RBD and every RBD below it together');
            $table->boolean('is_closed')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['flight_id', 'rbd_id']);
        });

        Schema::create('inventory_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('airline_id')->constrained('airlines')->cascadeOnDelete();
            $table->foreignId('aircraft_id')->nullable()->constrained('aircraft')->nullOnDelete()->comment('Hint only: the aircraft it was made for');
            $table->string('name', 100);
            $table->string('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['airline_id', 'name']);
        });

        Schema::create('inventory_template_cabins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_template_id')->constrained('inventory_templates')->cascadeOnDelete();
            $table->char('cabin_class', 1);
            $table->unsignedSmallInteger('seats');
            $table->unsignedSmallInteger('oversell')->default(0);
            $table->unsignedSmallInteger('curtail')->default(0);
            $table->unique(['inventory_template_id', 'cabin_class'], 'inventory_template_cabin_unique');
        });

        Schema::create('inventory_template_rbds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_template_id')->constrained('inventory_templates')->cascadeOnDelete();
            $table->foreignId('rbd_id')->constrained('rbds')->cascadeOnDelete();
            $table->unsignedSmallInteger('seats');
            $table->boolean('is_closed')->default(false);
            $table->unique(['inventory_template_id', 'rbd_id'], 'inventory_template_rbd_unique');
        });

        Schema::table('flight_series', function (Blueprint $table) {
            $table->foreignId('inventory_template_id')->nullable()->after('aircraft_id')
                ->constrained('inventory_templates')->nullOnDelete()->comment('Applied to flights the series creates');
        });

        Schema::table('passenger_segments', function (Blueprint $table) {
            $table->index(['flight_id', 'fare_class']);
        });
    }

    public function down(): void
    {
        Schema::table('passenger_segments', function (Blueprint $table) {
            $table->dropIndex(['flight_id', 'fare_class']);
        });
        Schema::table('flight_series', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inventory_template_id');
        });
        Schema::dropIfExists('inventory_template_rbds');
        Schema::dropIfExists('inventory_template_cabins');
        Schema::dropIfExists('inventory_templates');
        Schema::dropIfExists('flight_rbd_inventory');
        Schema::dropIfExists('flight_cabin_inventory');
    }
};
