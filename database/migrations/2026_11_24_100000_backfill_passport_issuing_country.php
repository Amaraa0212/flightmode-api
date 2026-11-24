<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-off: the agency booking form had no passport issuing country, so those passengers (and their infants) were saved
 * without one: the China APIS "Issuing Country" column and the PNL DOCS line came out empty. Filled in from the
 * passenger's nationality, in the same 2-letter code (APIS converts it to alpha-3 on export).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('passengers')
            ->whereNull('document_issuing_country')
            ->whereNotNull('document_number')
            ->where('document_number', '!=', '')
            ->where(fn ($q) => $q->whereNull('document_type')->orWhere('document_type', 'passport'))
            ->whereNotNull('nationality')
            ->where('nationality', '!=', '')
            ->update(['document_issuing_country' => DB::raw('UPPER(nationality)')]);
    }

    /** Nothing to undo: the filled rows cannot be told apart from ones entered by hand. */
    public function down(): void {}
};
