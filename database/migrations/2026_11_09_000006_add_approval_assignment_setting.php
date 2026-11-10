<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('system_settings')->insertOrIgnore([
            'key' => 'workweek_days',
            'value' => json_encode([1, 2, 3, 4, 5]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('system_settings')->where('key', 'workweek_days')->delete();
    }
};
