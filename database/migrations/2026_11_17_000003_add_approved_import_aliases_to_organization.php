<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->json('aliases')->nullable();
        });

        Schema::table('positions', function (Blueprint $table) {
            $table->json('aliases')->nullable();
            $table->boolean('is_office_head_designation')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn(['aliases', 'is_office_head_designation']);
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn('aliases');
        });
    }
};
