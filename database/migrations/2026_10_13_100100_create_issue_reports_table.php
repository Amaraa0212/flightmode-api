<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Problems users report from the "?" help panel of a back office page
        Schema::create('issue_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('airline_id')->nullable()->constrained('airlines')->nullOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->nullOnDelete();
            $table->string('page_path', 255)->index()->comment('Route of the page, e.g. /operations/flights');
            $table->string('page_title', 150)->nullable();
            $table->text('message');
            $table->string('user_agent', 500)->nullable();
            $table->string('status', 20)->default('open')->index()->comment('open | resolved');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_reports');
    }
};
