<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('airlines', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->char('iata_code', 2)->nullable()->unique();
            $table->char('icao_code', 3)->nullable()->unique();
            $table->char('numeric_code', 3)->nullable()->comment('Bag tag / ticket prefix');
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('address')->nullable();
            $table->string('registration_no', 50)->nullable();
            $table->string('logo_path')->nullable();
            $table->string('brand_color', 9)->default('#1e1e2d');
            $table->longText('conditions_of_carriage')->nullable();
            $table->unsignedTinyInteger('bag_tag_layout')->default(1)->comment('Bag tag printer layout / position');
            $table->char('currency', 3)->default('MNT');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('agencies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 20)->nullable()->unique();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('address')->nullable();
            $table->string('registration_no', 50)->nullable();
            $table->boolean('api_enabled')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('agency_airline', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('airline_id')->constrained('airlines')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['agency_id', 'airline_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_airline');
        Schema::dropIfExists('agencies');
        Schema::dropIfExists('airlines');
    }
};
