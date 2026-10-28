<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| An airline's own sales channels (ticket office, web, call center, its own agents): agencies owned by one
| airline, seen and contracted by that airline only. Global agencies (no owner) are added by the system admin.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->foreignId('owner_airline_id')->nullable()->after('id')->constrained('airlines')->nullOnDelete()
                ->comment('Set: a sales channel of this airline only');
            $table->string('channel', 20)->default('agency')->after('name')->comment('agency | ticket_office | web | call_center');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_airline_id');
            $table->dropColumn('channel');
        });
    }
};
