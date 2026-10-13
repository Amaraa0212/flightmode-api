<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Manual fares: CHD / INF discount like fares.child_discount_percent / infant_discount_percent
        Schema::table('agency_allocation_fares', function (Blueprint $table) {
            $table->decimal('child_discount_percent', 5, 2)->default(0)->after('tax_amount')->comment('Manual fare only');
            $table->decimal('infant_discount_percent', 5, 2)->default(0)->after('child_discount_percent')->comment('Manual fare only');
        });
    }

    public function down(): void
    {
        Schema::table('agency_allocation_fares', function (Blueprint $table) {
            $table->dropColumn(['child_discount_percent', 'infant_discount_percent']);
        });
    }
};
