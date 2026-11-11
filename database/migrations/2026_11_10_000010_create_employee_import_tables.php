<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('mapping_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('previewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_name');
            $table->string('source_file_path');
            $table->char('source_sha256', 64);
            $table->string('status', 30)->default('pending_review')->index();
            $table->json('source_headers');
            $table->json('field_mappings')->nullable();
            $table->timestamp('mapping_approved_at')->nullable();
            $table->timestamp('previewed_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('review_count')->default(0);
            $table->timestamps();
        });

        Schema::create('employee_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_import_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('source_row');
            $table->json('source_data');
            $table->json('review_data')->nullable();
            $table->json('issues')->nullable();
            $table->string('status', 30)->default('pending_review')->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['employee_import_id', 'source_row']);
        });

        Schema::create('employee_import_mapping_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_import_id')->constrained()->cascadeOnDelete();
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->json('mapping');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('employee_employment_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('position_title');
            $table->date('appointment_date')->nullable();
            $table->unsignedTinyInteger('sequence');
            $table->foreignId('employee_import_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('source_row')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'sequence']);
        });

        Schema::create('employee_service_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('position_level')->nullable();
            $table->string('source_classification')->nullable();
            $table->text('eligibility_rating')->nullable();
            $table->text('professional_license')->nullable();
            $table->date('license_expiration_date')->nullable();
            $table->text('educational_attainment')->nullable();
            $table->string('years_in_service', 80)->nullable();
            $table->date('expected_retirement_date')->nullable();
            $table->string('age_at_source', 20)->nullable();
            $table->string('additional_service_years', 80)->nullable();
            $table->foreignId('employee_import_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('source_row')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_service_profiles');
        Schema::dropIfExists('employee_employment_histories');
        Schema::dropIfExists('employee_import_mapping_events');
        Schema::dropIfExists('employee_import_rows');
        Schema::dropIfExists('employee_imports');
    }
};
