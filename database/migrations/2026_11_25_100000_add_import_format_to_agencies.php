<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which Excel layout an agency's passenger import reads (system administrator):
 *   standard  the FlightMode template (firstname, lastname, …)
 *   ot        Oyu Tolgoi's transport report (Passenger Name, Profile SAP, Profile Personal Mobile); a re-import syncs
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->string('import_format', 20)->default('standard')->after('open_jaw')
                ->comment('standard | ot — passenger Excel import layout (system administrator)');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', fn (Blueprint $table) => $table->dropColumn('import_format'));
    }
};
