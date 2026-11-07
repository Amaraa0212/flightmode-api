<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('airlines', function (Blueprint $table) {
            $table->unsignedSmallInteger('bag_tag_rotation')->default(0)->after('bag_tag_layout')->comment('Bag tag print direction: 0 or 180 degrees');
        });
    }

    public function down(): void
    {
        Schema::table('airlines', function (Blueprint $table) {
            $table->dropColumn('bag_tag_rotation');
        });
    }
};
