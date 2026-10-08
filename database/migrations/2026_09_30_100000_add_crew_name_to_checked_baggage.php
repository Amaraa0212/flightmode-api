<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checked_baggage', function (Blueprint $table) {
            $table->string('crew_name', 100)->nullable()->after('type')->comment('Crew bags: owner name (printed on the tag)');
        });
    }

    public function down(): void
    {
        Schema::table('checked_baggage', function (Blueprint $table) {
            $table->dropColumn('crew_name');
        });
    }
};
