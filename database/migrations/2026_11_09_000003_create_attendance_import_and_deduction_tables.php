<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biometric_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('original_name');
            $table->string('status', 30)->default('processing');
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->timestamps();
        });

        Schema::create('biometric_import_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('biometric_import_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('raw_data');
            $table->string('status', 20);
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->index(['biometric_import_id', 'status']);
        });

        Schema::create('deduction_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 40)->unique();
            $table->text('description')->nullable();
            $table->json('calculation_config')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        Schema::create('deduction_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_record_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('deduction_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->date('effective_date');
            $table->string('reason', 60);
            $table->decimal('estimated_amount', 12, 2)->nullable();
            $table->json('calculation_breakdown')->nullable();
            $table->string('review_status', 30)->default('Needs review');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->decimal('approved_amount', 12, 2)->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->index(['effective_date', 'review_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deduction_records');
        Schema::dropIfExists('deduction_rules');
        Schema::dropIfExists('biometric_import_records');
        Schema::dropIfExists('biometric_imports');
    }
};
