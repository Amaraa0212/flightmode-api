<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Agency holds (seats of an allocation kept for names entered later) and prepaid agency accounts
| (one per agency and airline: topped up by the airline, charged when a ticket is written, refunded on cancel).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('airline_id')->constrained('airlines')->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('agency_allocation_id')->constrained('agency_allocations')->cascadeOnDelete();
            $table->foreignId('agency_allocation_fare_id')->nullable()->constrained('agency_allocation_fares')->nullOnDelete();
            $table->char('cabin_class', 1);
            $table->unsignedSmallInteger('seats');
            $table->string('status', 10)->default('active')->comment('active | released');
            $table->timestamp('released_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['agency_id', 'status']);
            $table->index(['agency_allocation_id', 'status']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('agency_hold_id')->nullable()->after('agency_allocation_id')
                ->constrained('agency_holds')->nullOnDelete()->comment('Names entered on this hold');
        });

        Schema::create('agency_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('airline_id')->constrained('airlines')->cascadeOnDelete();
            $table->decimal('balance', 14, 2)->default(0);
            $table->char('currency', 3)->default('MNT');
            $table->timestamps();
            $table->unique(['agency_id', 'airline_id']);
        });

        Schema::create('agency_account_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_account_id')->constrained('agency_accounts')->cascadeOnDelete();
            $table->string('type', 12)->comment('topup | ticket | refund | adjustment');
            $table->decimal('amount', 14, 2)->comment('Signed: + into the account, - out of it');
            $table->decimal('balance_after', 14, 2);
            $table->foreignId('passenger_id')->nullable()->constrained('passengers')->nullOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['agency_account_id', 'created_at']);
            $table->index('passenger_id');
        });

        // permission for existing databases (RoleSeeder does the same on a fresh one)
        DB::table('permissions')->updateOrInsert(['key' => 'agency_accounts.manage'],
            ['name' => 'Agency accounts: top up / adjust balances', 'category' => 'agency_accounts', 'updated_at' => now()]);
        $permissionId = DB::table('permissions')->where('key', 'agency_accounts.manage')->value('id');
        $roleId = DB::table('roles')->where('slug', 'airline-admin')->value('id');
        if ($roleId) {
            DB::table('permission_role')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
        }
    }

    public function down(): void
    {
        DB::table('permissions')->where('key', 'agency_accounts.manage')->delete();
        Schema::dropIfExists('agency_account_transactions');
        Schema::dropIfExists('agency_accounts');
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agency_hold_id');
        });
        Schema::dropIfExists('agency_holds');
    }
};
