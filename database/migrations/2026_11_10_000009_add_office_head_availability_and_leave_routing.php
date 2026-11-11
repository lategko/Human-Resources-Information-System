<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_head_availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->foreignId('office_head_user_id')->constrained('users')->restrictOnDelete();
            $table->string('availability_status', 40);
            $table->date('start_date');
            $table->date('end_date');
            $table->text('reason');
            $table->foreignId('alternate_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['department_id', 'office_head_user_id', 'start_date', 'end_date'], 'office_head_availability_period_idx');
        });

        Schema::table('leave_applications', function (Blueprint $table) {
            $table->foreignId('office_head_reviewer_id')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->text('office_head_routing_reason')->nullable()->after('office_head_reviewer_id');
            $table->foreignId('alternate_decision_by')->nullable()->after('office_head_routing_reason')->constrained('users')->nullOnDelete();
            $table->string('alternate_decision_action', 20)->nullable()->after('alternate_decision_by');
            $table->decimal('alternate_decision_days_with_pay', 6, 2)->nullable()->after('alternate_decision_action');
            $table->decimal('alternate_decision_days_without_pay', 6, 2)->nullable()->after('alternate_decision_days_with_pay');
            $table->string('alternate_decision_others')->nullable()->after('alternate_decision_days_without_pay');
            $table->text('alternate_decision_reason')->nullable()->after('alternate_decision_days_without_pay');
            $table->text('alternate_decision_remarks')->nullable()->after('alternate_decision_reason');
            $table->timestamp('alternate_decision_at')->nullable()->after('alternate_decision_remarks');
        });
    }

    public function down(): void
    {
        Schema::table('leave_applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('office_head_reviewer_id');
            $table->dropConstrainedForeignId('alternate_decision_by');
            $table->dropColumn([
                'office_head_routing_reason',
                'alternate_decision_action',
                'alternate_decision_days_with_pay',
                'alternate_decision_days_without_pay',
                'alternate_decision_others',
                'alternate_decision_reason',
                'alternate_decision_remarks',
                'alternate_decision_at',
            ]);
        });

        Schema::dropIfExists('office_head_availabilities');
    }
};
