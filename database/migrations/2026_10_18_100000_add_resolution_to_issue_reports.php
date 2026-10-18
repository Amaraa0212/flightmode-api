<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Back office follow-up of a reported issue (status open | in_progress | resolved | rejected)
        Schema::table('issue_reports', function (Blueprint $table) {
            $table->text('resolution_note')->nullable()->after('status');
            $table->foreignId('resolved_by')->nullable()->after('resolution_note')->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable()->after('resolved_by');
        });
    }

    public function down(): void
    {
        Schema::table('issue_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resolved_by');
            $table->dropColumn(['resolution_note', 'resolved_at']);
        });
    }
};
