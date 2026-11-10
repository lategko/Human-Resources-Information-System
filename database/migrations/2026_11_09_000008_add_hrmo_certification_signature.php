<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_applications', function (Blueprint $table) {
            $table->string('certifier_position', 120)->nullable()->after('certified_at');
            $table->string('certifier_signature', 120)->nullable()->after('certifier_position');
        });
    }

    public function down(): void
    {
        Schema::table('leave_applications', function (Blueprint $table) {
            $table->dropColumn(['certifier_position', 'certifier_signature']);
        });
    }
};
