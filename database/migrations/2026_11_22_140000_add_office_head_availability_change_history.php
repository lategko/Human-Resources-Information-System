<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('office_head_availabilities', 'cancelled_at')
            || ! Schema::hasColumn('office_head_availabilities', 'cancelled_by')
            || ! Schema::hasColumn('office_head_availabilities', 'cancellation_reason')) {
            Schema::table('office_head_availabilities', function (Blueprint $table) {
                if (! Schema::hasColumn('office_head_availabilities', 'cancelled_at')) {
                    $table->timestamp('cancelled_at')->nullable();
                }
                if (! Schema::hasColumn('office_head_availabilities', 'cancelled_by')) {
                    $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('office_head_availabilities', 'cancellation_reason')) {
                    $table->text('cancellation_reason')->nullable();
                }
            });
        }

        if (! Schema::hasTable('office_head_availability_histories')) {
            Schema::create('office_head_availability_histories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('availability_id')->constrained('office_head_availabilities')->restrictOnDelete();
                $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('action', 20);
                $table->json('before_state')->nullable();
                $table->json('after_state')->nullable();
                $table->text('note')->nullable();
                $table->timestamps();
                $table->index(['availability_id', 'created_at'], 'office_head_availability_history_idx');
            });
        }

        DB::table('office_head_availabilities')
            ->orderBy('id')
            ->chunkById(500, function ($records) {
                foreach ($records as $record) {
                    if (DB::table('office_head_availability_histories')
                        ->where('availability_id', $record->id)
                        ->exists()) {
                        continue;
                    }
                    DB::table('office_head_availability_histories')->insert([
                        'availability_id' => $record->id,
                        'actor_id' => $record->updated_by,
                        'action' => 'created',
                        'after_state' => json_encode([
                            'availability_status' => $record->availability_status,
                            'start_date' => $record->start_date,
                            'end_date' => $record->end_date,
                            'reason' => $record->reason,
                            'alternate_user_id' => $record->alternate_user_id,
                        ], JSON_THROW_ON_ERROR),
                        'note' => 'Existing availability period retained when change history was introduced.',
                        'created_at' => $record->created_at,
                        'updated_at' => $record->created_at,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('office_head_availability_histories');
        Schema::table('office_head_availabilities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancellation_reason']);
        });
    }
};
