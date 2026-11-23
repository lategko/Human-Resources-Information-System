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
            $table->string('status', 64)->default('Draft')->change();
        });

        Schema::table('leave_approval_histories', function (Blueprint $table) {
            $table->string('from_status', 64)->nullable()->change();
            $table->string('to_status', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        $hasLongApplicationStatus = DB::table('leave_applications')
            ->whereRaw('LENGTH(status) > 40')
            ->exists();
        $hasLongHistoryStatus = DB::table('leave_approval_histories')
            ->whereRaw('LENGTH(from_status) > 40 OR LENGTH(to_status) > 40')
            ->exists();

        if ($hasLongApplicationStatus || $hasLongHistoryStatus) {
            throw new RuntimeException('Cannot shrink leave status columns while longer status values exist.');
        }

        Schema::table('leave_applications', function (Blueprint $table) {
            $table->string('status', 40)->default('Draft')->change();
        });

        Schema::table('leave_approval_histories', function (Blueprint $table) {
            $table->string('from_status', 40)->nullable()->change();
            $table->string('to_status', 40)->nullable()->change();
        });
    }
};
