<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code', 20)->nullable()->unique();
            $table->foreignId('head_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('salary_grade', 10)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['department_id', 'title']);
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->foreignId('position_id')->nullable()->constrained()->nullOnDelete();
            $table->string('employee_no')->unique();
            $table->string('biometric_id')->nullable()->unique();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('suffix', 20)->nullable();
            $table->string('sex', 20)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('civil_status', 40)->nullable();
            $table->string('contact_number', 40)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('employment_status', 40)->default('Active');
            $table->string('employment_type', 60)->nullable();
            $table->date('date_hired')->nullable();
            $table->string('salary_grade', 10)->nullable();
            $table->unsignedSmallInteger('salary_step')->nullable();
            $table->decimal('monthly_salary', 12, 2)->nullable();
            $table->text('government_ids')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_number', 40)->nullable();
            $table->date('archived_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['department_id', 'employment_status']);
        });

        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code', 40)->unique();
            $table->text('guidance')->nullable();
            $table->json('required_documents')->nullable();
            $table->json('rules')->nullable();
            $table->boolean('requires_credit')->default(false);
            $table->boolean('is_sensitive')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('holiday_date')->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('leave_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->string('status', 40)->default('Draft')->index();
            $table->string('office_snapshot');
            $table->string('position_snapshot')->nullable();
            $table->decimal('salary_snapshot', 12, 2)->nullable();
            $table->string('last_name_snapshot');
            $table->string('first_name_snapshot');
            $table->string('middle_name_snapshot')->nullable();
            $table->date('filed_at')->nullable();
            $table->string('other_leave_type')->nullable();
            $table->string('leave_detail_category')->nullable();
            $table->text('leave_detail')->nullable();
            $table->string('destination')->nullable();
            $table->text('additional_details')->nullable();
            $table->unsignedSmallInteger('working_days')->nullable();
            $table->unsignedSmallInteger('verified_working_days')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('commutation_requested')->nullable();
            $table->string('applicant_acknowledgment')->nullable();
            $table->json('document_waivers')->nullable();
            $table->json('credit_certification')->nullable();
            $table->foreignId('certified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('certified_at')->nullable();
            $table->text('hr_remarks')->nullable();
            $table->string('recommendation')->nullable();
            $table->text('recommendation_remarks')->nullable();
            $table->foreignId('recommended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recommended_at')->nullable();
            $table->decimal('approved_days_with_pay', 6, 2)->nullable();
            $table->decimal('approved_days_without_pay', 6, 2)->nullable();
            $table->string('approval_others')->nullable();
            $table->text('disapproval_reason')->nullable();
            $table->text('decision_remarks')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'start_date', 'end_date']);
            $table->index(['leave_type_id', 'status']);
        });

        Schema::create('leave_application_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('document_type')->nullable();
            $table->string('original_name');
            $table->string('stored_path');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->boolean('is_sensitive')->default(false);
            $table->timestamps();
        });

        Schema::create('leave_approval_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('role', 40);
            $table->string('action', 60);
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40)->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->index(['leave_application_id', 'created_at']);
        });

        Schema::create('leave_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->decimal('beginning_balance', 8, 2)->default(0);
            $table->decimal('earned_credits', 8, 2)->default(0);
            $table->decimal('used_credits', 8, 2)->default(0);
            $table->decimal('adjustments', 8, 2)->default(0);
            $table->date('effective_date')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'leave_type_id']);
        });

        Schema::create('leave_credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_credit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_application_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 30);
            $table->decimal('amount', 8, 2);
            $table->decimal('balance_after', 8, 2);
            $table->date('effective_date');
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->index(['leave_credit_id', 'effective_date']);
        });

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->time('time_in')->nullable();
            $table->time('time_out')->nullable();
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->decimal('total_working_hours', 5, 2)->nullable();
            $table->unsignedSmallInteger('late_minutes')->default(0);
            $table->unsignedSmallInteger('undertime_minutes')->default(0);
            $table->string('status', 30)->default('Present');
            $table->string('source', 30)->default('manual');
            $table->text('correction_remarks')->nullable();
            $table->foreignId('corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'work_date']);
            $table->index(['work_date', 'status']);
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 100);
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('leave_credit_transactions');
        Schema::dropIfExists('leave_credits');
        Schema::dropIfExists('leave_approval_histories');
        Schema::dropIfExists('leave_application_documents');
        Schema::dropIfExists('leave_applications');
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('leave_types');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('positions');
        Schema::dropIfExists('departments');
    }
};
