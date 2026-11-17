<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $normalizedEmails = DB::table('users')
            ->whereNotNull('email')
            ->pluck('email')
            ->map(fn (string $email) => mb_strtolower(trim($email)));

        if ($normalizedEmails->count() !== $normalizedEmails->unique()->count()) {
            throw new RuntimeException('User email addresses must be unique after normalization before account setup can be enabled.');
        }

        DB::table('users')->whereNotNull('email')->orderBy('id')->get(['id', 'email'])
            ->each(fn (object $user) => DB::table('users')->where('id', $user->id)
                ->update(['email' => mb_strtolower(trim($user->email))]));

        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->unique()->after('name');
            $table->boolean('must_change_password')->default(false)->after('password');
            $table->boolean('account_setup_completed')->default(true)->after('must_change_password');
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('users')->whereNull('email')->exists()) {
            throw new RuntimeException('Cannot remove optional employee emails while email-less accounts still exist.');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn(['username', 'must_change_password', 'account_setup_completed']);
            $table->string('email')->nullable(false)->change();
        });
    }
};
