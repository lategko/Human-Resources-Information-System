<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_imports', function (Blueprint $table) {
            $table->unsignedInteger('updated_count')->default(0)->after('imported_count');
            $table->unsignedInteger('accounts_created_count')->default(0)->after('updated_count');
        });
    }

    public function down(): void
    {
        Schema::table('employee_imports', function (Blueprint $table) {
            $table->dropColumn(['updated_count', 'accounts_created_count']);
        });
    }
};
