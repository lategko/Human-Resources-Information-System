<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('employees')->whereNull('department_id')->exists()) {
            throw new \RuntimeException(
                'Cannot make employees.department_id required while unassigned employee records exist.'
            );
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable(false)->change();
        });
    }
};
