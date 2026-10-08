<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('pnr', 8)->unique();
            $table->foreignId('airline_id')->constrained('airlines')->restrictOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->restrictOnDelete();
            $table->foreignId('agency_allocation_id')->nullable()->constrained('agency_allocations')->restrictOnDelete();
            $table->string('source', 20)->default('admin')->comment('admin | counter | agency | agency_api | import');
            $table->string('status', 20)->default('confirmed')->comment('confirmed | waitlisted | cancelled');
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 50)->nullable()->comment('Agency / booker phone');
            $table->timestamp('ticket_emailed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('passengers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('infant_of_passenger_id')->nullable()->constrained('passengers')->cascadeOnDelete();
            $table->string('type', 3)->default('ADT')->comment('ADT | CHD | INF');
            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100);
            $table->char('gender', 1)->nullable()->comment('M | F');
            $table->date('date_of_birth')->nullable();
            $table->char('nationality', 3)->nullable()->comment('ISO alpha-3');
            $table->string('person_code', 20)->nullable()->index()->comment('Company / SAP person number, used for online check-in lookup');
            $table->string('document_type', 20)->nullable()->comment('passport | id_card | ...');
            $table->string('document_number', 50)->nullable();
            $table->char('document_issuing_country', 3)->nullable();
            $table->date('document_issued_at')->nullable();
            $table->date('document_expires_at')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('emergency_contact')->nullable();
            $table->string('remark')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['last_name', 'first_name']);
        });

        // Group check-in (shared baggage allowance / grouped seating)
        Schema::create('checkin_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_id')->constrained('flights')->cascadeOnDelete();
            $table->string('name', 100)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // One passenger on one flight (replaces scheduleID/scheduleID2, seat/seat2, tkt_status/_sch_2 ...)
        Schema::create('passenger_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('passenger_id')->constrained('passengers')->cascadeOnDelete();
            $table->foreignId('flight_id')->constrained('flights')->restrictOnDelete();
            $table->unsignedTinyInteger('segment_no')->default(1)->comment('1 = outbound, 2 = return, ...');
            $table->char('cabin_class', 1);
            $table->string('fare_class', 2)->nullable();
            $table->foreignId('fare_id')->nullable()->constrained('fares')->nullOnDelete();
            $table->foreignId('agency_allocation_fare_id')->nullable()->constrained('agency_allocation_fares')->nullOnDelete();
            $table->string('ticket_number', 20)->nullable()->index()->comment('Same ticket on all coupons of a trip');
            $table->decimal('fare_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('agency_sale_amount', 12, 2)->nullable()->comment('Price the agency sold at');
            $table->char('currency', 3)->default('MNT');
            $table->string('seat_number', 4)->nullable();
            $table->string('status', 20)->default('booked')->comment('booked | waitlisted | checked_in | boarded | flown | cancelled');
            $table->boolean('is_go_show')->default(false)->comment('Added at the counter (DCS), not pre-booked');
            $table->unsignedSmallInteger('boarding_sequence')->nullable();
            $table->foreignId('checkin_group_id')->nullable()->constrained('checkin_groups')->nullOnDelete();
            $table->string('checkin_channel', 10)->nullable()->comment('dcs | online | agency');
            $table->timestamp('checked_in_at')->nullable();
            $table->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('boarding_pass_printed_at')->nullable();
            $table->timestamp('boarded_at')->nullable();
            $table->foreignId('boarded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('offload_type', 20)->nullable()->comment('Set when a boarded passenger is taken off at the gate');
            $table->text('offload_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['passenger_id', 'flight_id']);
            $table->unique(['flight_id', 'seat_number']);
            $table->index(['flight_id', 'status']);
        });

        Schema::create('passenger_segment_ssr', function (Blueprint $table) {
            $table->id();
            $table->foreignId('passenger_segment_id')->constrained('passenger_segments')->cascadeOnDelete();
            $table->foreignId('ssr_code_id')->constrained('ssr_codes')->restrictOnDelete();
            $table->string('remark')->nullable();
            $table->timestamps();
            $table->unique(['passenger_segment_id', 'ssr_code_id']);
        });

        // Extra baggage bought in advance by the agency (legacy airline_baggage)
        Schema::create('prepaid_baggage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('passenger_segment_id')->constrained('passenger_segments')->cascadeOnDelete();
            $table->unsignedSmallInteger('pieces')->default(0);
            $table->decimal('weight_kg', 6, 1)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // One baggage acceptance at the counter: N pieces weighed together (legacy baggages rows sharing group_seq)
        Schema::create('checked_baggage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_id')->constrained('flights')->restrictOnDelete();
            $table->foreignId('passenger_segment_id')->nullable()->constrained('passenger_segments')->cascadeOnDelete()->comment('null = crew baggage');
            $table->string('type', 10)->default('passenger')->comment('passenger | crew');
            $table->foreignId('baggage_rule_id')->nullable()->constrained('baggage_rules')->nullOnDelete();
            $table->foreignId('excess_baggage_rule_id')->nullable()->constrained('baggage_rules')->nullOnDelete();
            $table->unsignedSmallInteger('pieces')->default(1);
            $table->decimal('weight_kg', 6, 1)->comment('Total weight of all pieces');
            $table->decimal('free_allowance_kg', 6, 1)->default(0);
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('excess_price', 12, 2)->default(0);
            $table->char('currency', 3)->default('MNT');
            $table->foreignId('checkin_group_id')->nullable()->constrained('checkin_groups')->nullOnDelete();
            $table->string('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // One printed bag tag (one physical piece)
        Schema::create('baggage_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checked_baggage_id')->constrained('checked_baggage')->cascadeOnDelete();
            $table->foreignId('flight_id')->constrained('flights')->cascadeOnDelete();
            $table->string('tag_number', 10)->comment('1 + airline numeric code + pax sequence + bag sequence');
            $table->unsignedSmallInteger('sequence')->comment('Bag sequence on the flight');
            $table->timestamps();
            $table->index(['flight_id', 'tag_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('baggage_tags');
        Schema::dropIfExists('checked_baggage');
        Schema::dropIfExists('prepaid_baggage');
        Schema::dropIfExists('passenger_segment_ssr');
        Schema::dropIfExists('passenger_segments');
        Schema::dropIfExists('checkin_groups');
        Schema::dropIfExists('passengers');
        Schema::dropIfExists('bookings');
    }
};
