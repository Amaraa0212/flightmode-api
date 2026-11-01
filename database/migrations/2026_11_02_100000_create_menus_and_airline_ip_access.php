<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Back-office menus kept in the database (xmeta-admin style): menu groups → menus → sub-menus.
| A menu owns its permissions (`{menu.key}.{action}`: view / create / update / delete / custom),
| roles get them through permission_role. Airlines may create their own roles and limit
| their back-office staff to registered IP addresses.
| Data (menus, permissions, mapping of the old *.manage keys) comes from MenuSeeder.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });

        Schema::create('menus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('menus')->cascadeOnDelete();
            $table->foreignId('menu_group_id')->nullable()->constrained('menu_groups')->nullOnDelete();
            $table->string('key', 50)->nullable()->unique()->comment('Permission prefix: flights → flights.view, flights.create ...');
            $table->string('name', 100);
            $table->string('icon', 50)->nullable()->comment('lucide icon name');
            $table->string('path')->nullable()->comment('Admin route; empty for a parent or an app (DCS, gate)');
            $table->string('permission_key', 100)->nullable()->comment('Permission needed to see it; default {key}.view');
            $table->json('scopes')->nullable()->comment('Role scopes that see it (admin, airline, agency, airport); null = all');
            $table->unsignedInteger('order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('permissions', function (Blueprint $table) {
            $table->foreignId('menu_id')->nullable()->after('id')->constrained('menus')->cascadeOnDelete();
            $table->string('action', 30)->nullable()->after('key')->comment('view | create | update | delete | custom');
            $table->unsignedInteger('order')->default(0)->after('category');
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->foreignId('airline_id')->nullable()->after('scope')->constrained('airlines')->cascadeOnDelete()
                ->comment('Set: a role the airline created for its own staff; null: a shared role');
        });

        Schema::table('airlines', function (Blueprint $table) {
            $table->boolean('ip_restriction_enabled')->default(false)->comment('Back-office staff only from airline_ip_addresses');
        });

        Schema::create('airline_ip_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('airline_id')->constrained('airlines')->cascadeOnDelete();
            $table->string('ip', 64)->comment('IPv4 / IPv6 address or CIDR range');
            $table->string('note')->nullable();
            $table->timestamps();
            $table->unique(['airline_id', 'ip']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('airline_ip_addresses');
        Schema::table('airlines', fn (Blueprint $table) => $table->dropColumn('ip_restriction_enabled'));
        Schema::table('roles', fn (Blueprint $table) => $table->dropConstrainedForeignId('airline_id'));
        Schema::table('permissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('menu_id');
            $table->dropColumn(['action', 'order']);
        });
        Schema::dropIfExists('menus');
        Schema::dropIfExists('menu_groups');
    }
};
