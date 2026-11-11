<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Worldwide IATA airport list (from the previous system's `iata` table), used to fill in the airport form:
 * cities and time zones of a country, and an airport's details from its code. Not the airline's own stations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('iata_airports', function (Blueprint $table) {
            $table->id();
            $table->char('code', 3)->unique();
            $table->string('icao', 4)->nullable();
            $table->string('name');
            $table->string('city', 100)->nullable();
            $table->string('city_code', 4)->nullable();
            $table->string('timezone', 64);
            $table->char('country', 2)->index()->comment('ISO alpha-2');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
        });

        $file = fopen(database_path('data/iata_airports.csv'), 'r');
        $header = fgetcsv($file, null, ',', '"', '');
        $rows = [];
        while (($line = fgetcsv($file, null, ',', '"', '')) !== false) {
            $rows[] = array_map(fn ($v) => $v === '' ? null : $v, array_combine($header, $line));
            if (count($rows) === 1000) {
                DB::table('iata_airports')->insert($rows);
                $rows = [];
            }
        }
        DB::table('iata_airports')->insert($rows);
        fclose($file);
    }

    public function down(): void
    {
        Schema::dropIfExists('iata_airports');
    }
};
