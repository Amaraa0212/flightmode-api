<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Messages shown to agencies / airline staff (legacy notification_required)
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('airline_id')->nullable()->constrained('airlines')->cascadeOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->cascadeOnDelete()->comment('null = all agencies');
            $table->string('title', 300);
            $table->text('body');
            $table->boolean('requires_acknowledgement')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('announcement_dismissals', function (Blueprint $table) {
            $table->foreignId('announcement_id')->constrained('announcements')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('dismissed_at')->useCurrent();
            $table->primary(['announcement_id', 'user_id']);
        });

        Schema::create('charter_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('origin_airport_id')->constrained('airports')->restrictOnDelete();
            $table->foreignId('destination_airport_id')->constrained('airports')->restrictOnDelete();
            $table->date('departure_date');
            $table->date('return_date')->nullable();
            $table->unsignedSmallInteger('pax_count');
            $table->string('status', 20)->default('requested')->comment('requested | pending | approved | rejected');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('airline_charter_request', function (Blueprint $table) {
            $table->foreignId('charter_request_id')->constrained('charter_requests')->cascadeOnDelete();
            $table->foreignId('airline_id')->constrained('airlines')->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['charter_request_id', 'airline_id']);
        });

        // Unified audit log (legacy admin_action_logs + dcs_action_log + passenger_action_logs)
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 20)->index()->comment('admin | dcs | gate | online | agency | agency_api');
            $table->string('event', 60)->comment('e.g. passenger.checked_in, flight.updated');
            $table->string('description', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->nullableMorphs('subject');
            $table->foreignId('flight_id')->nullable()->constrained('flights')->nullOnDelete();
            $table->foreignId('passenger_id')->nullable()->constrained('passengers')->nullOnDelete();
            $table->json('properties')->nullable()->comment('old / new values, extra context');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('airline_charter_request');
        Schema::dropIfExists('charter_requests');
        Schema::dropIfExists('announcement_dismissals');
        Schema::dropIfExists('announcements');
    }
};
