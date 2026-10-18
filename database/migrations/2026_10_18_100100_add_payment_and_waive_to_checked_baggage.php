<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Excess baggage is either paid (billing / transaction number) or waived (who allowed it and why)
        Schema::table('checked_baggage', function (Blueprint $table) {
            $table->string('payment_reference', 100)->nullable()->after('excess_price')->comment('Billing / transaction number of the excess payment');
            $table->decimal('waived_amount', 12, 2)->default(0)->after('payment_reference');
            $table->string('waive_reason', 500)->nullable()->after('waived_amount');
            $table->string('waive_authorized_by', 100)->nullable()->after('waive_reason')->comment('Person who allowed the waiver');
            $table->foreignId('waived_by')->nullable()->after('waive_authorized_by')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('checked_baggage', function (Blueprint $table) {
            $table->dropConstrainedForeignId('waived_by');
            $table->dropColumn(['payment_reference', 'waived_amount', 'waive_reason', 'waive_authorized_by']);
        });
    }
};
