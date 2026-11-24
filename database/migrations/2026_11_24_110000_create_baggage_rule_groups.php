<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Baggage rule groups: the admin page edits a bag's common details (name, limit by, cabin, max pieces, active) once,
 * with one row per SSR (FBAG / XBAG / EBAG: its kg, pieces and price). Each SSR row stays a `baggage_rules` record, so
 * flights, templates, the counter and checked bags keep pointing at rules. Every existing rule becomes a group of its
 * own; a rule without an SSR becomes FBAG (free allowance).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('baggage_rule_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('airline_id')->constrained('airlines')->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 10)->comment('Limit by: piece | weight | combined');
            $table->char('cabin_class', 1)->nullable()->comment('F | C | W | Y, null = all');
            $table->unsignedSmallInteger('max_quantity')->nullable();
            $table->char('currency', 3)->default('MNT');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('baggage_rules', function (Blueprint $table) {
            $table->foreignId('baggage_rule_group_id')->nullable()->after('airline_id')
                ->constrained('baggage_rule_groups')->cascadeOnDelete()->comment('Group whose SSR row this rule is');
        });

        DB::table('baggage_rules')->whereNull('ssr_code')->orWhere('ssr_code', '')->update(['ssr_code' => 'FBAG']);

        foreach (DB::table('baggage_rules')->orderBy('id')->get() as $rule) {
            $groupId = DB::table('baggage_rule_groups')->insertGetId([
                'airline_id' => $rule->airline_id,
                'name' => $rule->name,
                'type' => $rule->type,
                'cabin_class' => $rule->cabin_class,
                'max_quantity' => $rule->max_quantity,
                'currency' => $rule->currency,
                'is_active' => $rule->is_active,
                'created_at' => $rule->created_at,
                'updated_at' => $rule->updated_at,
            ]);
            DB::table('baggage_rules')->where('id', $rule->id)->update(['baggage_rule_group_id' => $groupId]);
        }
    }

    public function down(): void
    {
        Schema::table('baggage_rules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('baggage_rule_group_id');
        });
        Schema::dropIfExists('baggage_rule_groups');
    }
};
