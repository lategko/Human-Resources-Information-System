<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_assignment_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->string('change_type', 20);
            $table->unsignedBigInteger('previous_department_id')->nullable();
            $table->string('previous_department_name')->nullable();
            $table->unsignedBigInteger('previous_position_id')->nullable();
            $table->string('previous_position_title')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->string('department_name')->nullable();
            $table->unsignedBigInteger('position_id')->nullable();
            $table->string('position_title')->nullable();
            $table->date('effective_date');
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->foreign('employee_id', 'eah_employee_fk')
                ->references('id')->on('employees')->restrictOnDelete();
            $table->foreign('previous_department_id', 'eah_previous_department_fk')
                ->references('id')->on('departments')->nullOnDelete();
            $table->foreign('previous_position_id', 'eah_previous_position_fk')
                ->references('id')->on('positions')->nullOnDelete();
            $table->foreign('department_id', 'eah_department_fk')
                ->references('id')->on('departments')->nullOnDelete();
            $table->foreign('position_id', 'eah_position_fk')
                ->references('id')->on('positions')->nullOnDelete();
            $table->foreign('changed_by', 'eah_changed_by_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->index(['employee_id', 'effective_date'], 'eah_employee_effective_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_assignment_histories');
    }
};
