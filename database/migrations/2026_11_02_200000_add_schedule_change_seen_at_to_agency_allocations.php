<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_allocations', function (Blueprint $table) {
            // the agency acknowledged the schedule changes of its flights up to this moment
            $table->timestamp('schedule_change_seen_at')->nullable()->after('chinggis_error');
        });
    }

    public function down(): void
    {
        Schema::table('agency_allocations', function (Blueprint $table) {
            $table->dropColumn('schedule_change_seen_at');
        });
    }
};
