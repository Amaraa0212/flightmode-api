<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->char('alpha2', 2)->unique();
            $table->char('alpha3', 3)->unique();
            $table->char('numeric_code', 3)->nullable();
            $table->timestamps();
        });

        Schema::create('airports', function (Blueprint $table) {
            $table->id();
            $table->char('iata_code', 3)->unique();
            $table->char('icao_code', 4)->nullable()->index();
            $table->string('name');
            $table->string('city')->nullable();
            $table->string('city_code', 3)->nullable();
            $table->foreignId('country_id')->nullable()->constrained('countries')->nullOnDelete();
            $table->string('timezone', 64)->default('Asia/Ulaanbaatar');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('ssr_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 8)->unique()->comment('IATA SSR code, e.g. WCHR, VGML');
            $table->string('name');
            $table->string('category', 50)->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ssr_codes');
        Schema::dropIfExists('airports');
        Schema::dropIfExists('countries');
    }
};
