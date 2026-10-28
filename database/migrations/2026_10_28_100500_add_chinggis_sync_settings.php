<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Chinggis agent API: which agencies' quota is pushed is set on the agency (before: CHINGGIS_AGENCY_IDS only,
| still honoured), and every allocation keeps the result of its last push.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->boolean('chinggis_sync')->default(false)->after('api_enabled')->comment('Push its allocations to the Chinggis agent API');
        });
        Schema::table('agency_allocations', function (Blueprint $table) {
            $table->timestamp('chinggis_synced_at')->nullable()->after('is_waitlist');
            $table->string('chinggis_error', 500)->nullable()->after('chinggis_synced_at')->comment('Last push failed: why');
        });
    }

    public function down(): void
    {
        Schema::table('agency_allocations', function (Blueprint $table) {
            $table->dropColumn(['chinggis_synced_at', 'chinggis_error']);
        });
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('chinggis_sync');
        });
    }
};
