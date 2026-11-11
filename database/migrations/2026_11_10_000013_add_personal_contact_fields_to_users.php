<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('profile_gender', 20)->nullable();
            $table->date('profile_birthdate')->nullable();
            $table->string('profile_contact_number', 40)->nullable();
            $table->text('profile_address')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'profile_gender',
                'profile_birthdate',
                'profile_contact_number',
                'profile_address',
            ]);
        });
    }
};
