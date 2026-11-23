<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_workplaces', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('address')->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedInteger('radius_meters')->default(100);
            $table->unsignedInteger('required_accuracy_meters')->default(50);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['is_active', 'name']);
        });

        Schema::create('attendance_workplace_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_workplace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedInteger('radius_meters');
            $table->unsignedInteger('required_accuracy_meters');
            $table->boolean('is_active');
            $table->timestamps();
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('attendance_workplace_id')->nullable()->after('department_id')->constrained('attendance_workplaces')->nullOnDelete();
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->decimal('time_in_latitude', 10, 7)->nullable();
            $table->decimal('time_in_longitude', 10, 7)->nullable();
            $table->decimal('time_in_accuracy_meters', 8, 2)->nullable();
            $table->decimal('time_in_distance_meters', 8, 2)->nullable();
            $table->foreignId('time_in_workplace_id')->nullable()->constrained('attendance_workplaces')->nullOnDelete();
            $table->timestamp('time_in_verified_at')->nullable();
            $table->decimal('time_out_latitude', 10, 7)->nullable();
            $table->decimal('time_out_longitude', 10, 7)->nullable();
            $table->decimal('time_out_accuracy_meters', 8, 2)->nullable();
            $table->decimal('time_out_distance_meters', 8, 2)->nullable();
            $table->foreignId('time_out_workplace_id')->nullable()->constrained('attendance_workplaces')->nullOnDelete();
            $table->timestamp('time_out_verified_at')->nullable();
            $table->string('gps_verification_status', 30)->nullable()->index();
        });

        Schema::create('attendance_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_record_id')->nullable()->constrained()->nullOnDelete();
            $table->date('attendance_date');
            $table->enum('requested_action', ['time_in', 'time_out', 'correction']);
            $table->text('explanation');
            $table->string('supporting_document_path')->nullable();
            $table->string('status', 20)->default('Pending')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_remarks')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'attendance_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_exceptions');
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('time_in_workplace_id');
            $table->dropConstrainedForeignId('time_out_workplace_id');
            $table->dropColumn([
                'time_in_latitude', 'time_in_longitude', 'time_in_accuracy_meters', 'time_in_distance_meters',
                'time_in_verified_at', 'time_out_latitude', 'time_out_longitude',
                'time_out_accuracy_meters', 'time_out_distance_meters', 'time_out_verified_at',
                'gps_verification_status',
            ]);
        });
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('attendance_workplace_id');
        });
        Schema::dropIfExists('attendance_workplace_history');
        Schema::dropIfExists('attendance_workplaces');
    }
};
