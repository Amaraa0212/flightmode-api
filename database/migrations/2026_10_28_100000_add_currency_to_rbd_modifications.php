<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* A modification penalty is either a fixed amount (in this currency) or a percentage of the fare. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rbd_modifications', function (Blueprint $table) {
            $table->char('currency', 3)->nullable()->after('amount')->comment('Currency of a fixed amount penalty');
        });
    }

    public function down(): void
    {
        Schema::table('rbd_modifications', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
