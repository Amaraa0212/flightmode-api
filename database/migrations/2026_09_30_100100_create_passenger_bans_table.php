<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passenger_bans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('airline_id')->nullable()->constrained('airlines')->cascadeOnDelete()->comment('null = every airline');
            $table->string('ban_type', 20)->comment('no_fly | unruly | unpaid_debt | watch');
            $table->string('last_name', 100);
            $table->string('first_name', 100);
            $table->date('date_of_birth')->nullable();
            $table->string('document_number', 50)->nullable()->index();
            $table->string('nationality', 3)->nullable();
            $table->text('reason');
            $table->date('valid_until')->nullable()->comment('null = permanent');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['last_name', 'first_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passenger_bans');
    }
};
