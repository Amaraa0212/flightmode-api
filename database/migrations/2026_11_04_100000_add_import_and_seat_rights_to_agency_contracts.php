<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| What an agency may do on an airline's flights besides selling (legacy OT: Oyu Tolgoi charter):
| can_import_passengers — upload the passenger list of its quota from Excel;
| can_assign_seats      — reserve seats for its passengers on charter flights (no check-in).
| Existing contracts keep the Excel import they already had.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_airline', function (Blueprint $table) {
            $table->boolean('can_import_passengers')->default(false)->after('is_active');
            $table->boolean('can_assign_seats')->default(false)->after('can_import_passengers');
        });

        DB::table('agency_airline')->update(['can_import_passengers' => true]);
    }

    public function down(): void
    {
        Schema::table('agency_airline', function (Blueprint $table) {
            $table->dropColumn(['can_import_passengers', 'can_assign_seats']);
        });
    }
};
