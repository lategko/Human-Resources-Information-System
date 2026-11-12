<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->nullable()->constrained()->restrictOnDelete();
            $table->json('working_days');
            $table->time('start_time');
            $table->time('end_time');
            $table->time('lunch_start')->nullable();
            $table->time('lunch_end')->nullable();
            $table->unsignedSmallInteger('grace_period_minutes')->default(0);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->text('reason');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'effective_from', 'effective_to'], 'work_schedules_employee_period_idx');
        });

        Schema::table('holidays', function (Blueprint $table) {
            $table->string('holiday_type', 40)->nullable();
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->string('calendar_classification', 40)->nullable();
            $table->unsignedSmallInteger('unapproved_extra_minutes')->nullable();
            $table->json('schedule_snapshot')->nullable();
            $table->string('calculation_warning')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropColumn([
                'calendar_classification',
                'unapproved_extra_minutes',
                'schedule_snapshot',
                'calculation_warning',
            ]);
        });

        Schema::table('holidays', function (Blueprint $table) {
            $table->dropColumn('holiday_type');
        });

        Schema::dropIfExists('work_schedules');
    }
};