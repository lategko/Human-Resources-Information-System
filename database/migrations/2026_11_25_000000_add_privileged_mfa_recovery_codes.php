<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('mfa_recovery_codes')->nullable()->after('remember_token');
            $table->timestamp('mfa_recovery_codes_generated_at')->nullable()->after('mfa_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['mfa_recovery_codes', 'mfa_recovery_codes_generated_at']);
        });
    }
};
