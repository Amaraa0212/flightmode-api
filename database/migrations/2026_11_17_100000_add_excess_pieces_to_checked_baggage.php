<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** PC rules: pieces of a bag over the rule's free pieces, charged at the piece EBAG price. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checked_baggage', function (Blueprint $table) {
            $table->unsignedSmallInteger('excess_pieces')->default(0)->after('free_allowance_kg')
                ->comment('PC rule: pieces over the free ones (piece EBAG price each)');
        });
    }

    public function down(): void
    {
        Schema::table('checked_baggage', fn (Blueprint $table) => $table->dropColumn('excess_pieces'));
    }
};
