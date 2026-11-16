<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_applications', function (Blueprint $table) {
            $table->foreignId('assigned_hr_reviewer_id')->nullable()->after('office_head_reviewer_id')->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_final_approver_id')->nullable()->after('assigned_hr_reviewer_id')->constrained('users')->nullOnDelete();
        });

        DB::table('users')
            ->whereIn('role', ['super_admin', 'hrmo'])
            ->update(['role' => 'admin']);
    }

    public function down(): void
    {
        Schema::table('leave_applications', function (Blueprint $table) {
            $table->dropForeign(['assigned_hr_reviewer_id']);
            $table->dropForeign(['assigned_final_approver_id']);
            $table->dropColumn(['assigned_hr_reviewer_id', 'assigned_final_approver_id']);
        });
    }
};
