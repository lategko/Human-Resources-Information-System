<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deduction_records', function (Blueprint $table) {
            $table->foreignId('approved_by')->nullable()->after('approved_amount')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->text('approval_remarks')->nullable()->after('approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('deduction_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['approved_at', 'approval_remarks']);
        });
    }
};
