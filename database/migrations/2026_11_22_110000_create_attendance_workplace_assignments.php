<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_workplace_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->nullable();
            $table->foreignId('department_id')->nullable();
            $table->foreignId('attendance_workplace_id')->nullable();
            $table->string('assignment_type', 20);
            $table->date('effective_from');
            $table->foreignId('assigned_by')->nullable();
            $table->string('reason', 2000);
            $table->timestamps();
            $table->index(['employee_id', 'effective_from', 'id'], 'workplace_assignments_employee_effective_idx');
            $table->index(['department_id', 'effective_from', 'id'], 'workplace_assignments_department_effective_idx');
            $table->foreign('employee_id', 'awpa_employee_fk')->references('id')->on('employees')->nullOnDelete();
            $table->foreign('department_id', 'awpa_department_fk')->references('id')->on('departments')->nullOnDelete();
            $table->foreign('attendance_workplace_id', 'awpa_workplace_fk')->references('id')->on('attendance_workplaces')->nullOnDelete();
            $table->foreign('assigned_by', 'awpa_assigned_by_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('attendance_workplace_assignment_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->nullable();
            $table->foreignId('employee_id')->nullable();
            $table->foreignId('department_id')->nullable();
            $table->foreignId('previous_workplace_id')->nullable();
            $table->foreignId('new_workplace_id')->nullable();
            $table->string('assignment_type', 20);
            $table->date('effective_date');
            $table->foreignId('assigned_by')->nullable();
            $table->string('reason', 2000);
            $table->timestamps();
            $table->index(['employee_id', 'effective_date'], 'awph_employee_effective_idx');
            $table->index(['department_id', 'effective_date'], 'awph_department_effective_idx');
            $table->foreign('assignment_id', 'awph_assignment_fk')->references('id')->on('attendance_workplace_assignments')->nullOnDelete();
            $table->foreign('employee_id', 'awph_employee_fk')->references('id')->on('employees')->nullOnDelete();
            $table->foreign('department_id', 'awph_department_fk')->references('id')->on('departments')->nullOnDelete();
            $table->foreign('previous_workplace_id', 'awph_previous_workplace_fk')->references('id')->on('attendance_workplaces')->nullOnDelete();
            $table->foreign('new_workplace_id', 'awph_new_workplace_fk')->references('id')->on('attendance_workplaces')->nullOnDelete();
            $table->foreign('assigned_by', 'awph_assigned_by_fk')->references('id')->on('users')->nullOnDelete();
        });

        $now = now();
        DB::table('employees')
            ->whereNotNull('attendance_workplace_id')
            ->orderBy('id')
            ->chunk(500, function ($employees) use ($now) {
                foreach ($employees as $employee) {
                    $assignmentId = DB::table('attendance_workplace_assignments')->insertGetId([
                        'employee_id' => $employee->id,
                        'department_id' => $employee->department_id,
                        'attendance_workplace_id' => $employee->attendance_workplace_id,
                        'assignment_type' => 'individual',
                        'effective_from' => $now->toDateString(),
                        'assigned_by' => null,
                        'reason' => 'Migrated existing employee workplace assignment; original assignee not recorded',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    DB::table('attendance_workplace_assignment_history')->insert([
                        'assignment_id' => $assignmentId,
                        'employee_id' => $employee->id,
                        'department_id' => $employee->department_id,
                        'previous_workplace_id' => null,
                        'new_workplace_id' => $employee->attendance_workplace_id,
                        'assignment_type' => 'individual',
                        'effective_date' => $now->toDateString(),
                        'assigned_by' => null,
                        'reason' => 'Migrated existing employee workplace assignment; original assignee not recorded',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_workplace_assignment_history');
        Schema::dropIfExists('attendance_workplace_assignments');
    }
};
