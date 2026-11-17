<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_imports', function (Blueprint $table) {
            $table->string('worksheet_name')->nullable();
            $table->unsignedSmallInteger('header_row')->nullable();
            $table->json('worksheet_names')->nullable();
            $table->unsignedInteger('source_row_count')->default(0);
            $table->unsignedInteger('excluded_rows_count')->default(0);
        });

        Schema::table('employee_service_profiles', function (Blueprint $table) {
            $table->char('source_identity_hash', 64)->nullable()->unique();
            $table->string('source_identity_type', 40)->nullable();
            $table->string('plantilla_item_no', 80)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('employee_service_profiles', function (Blueprint $table) {
            $table->dropUnique(['source_identity_hash']);
            $table->dropIndex(['plantilla_item_no']);
            $table->dropColumn(['source_identity_hash', 'source_identity_type', 'plantilla_item_no']);
        });

        Schema::table('employee_imports', function (Blueprint $table) {
            $table->dropColumn([
                'worksheet_name',
                'header_row',
                'worksheet_names',
                'source_row_count',
                'excluded_rows_count',
            ]);
        });
    }
};
