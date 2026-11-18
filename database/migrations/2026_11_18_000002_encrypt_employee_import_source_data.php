<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_import_rows', function (Blueprint $table) {
            $table->longText('source_data')->change();
        });

        DB::transaction(function (): void {
            DB::table('employee_import_rows')->orderBy('id')->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $sourceData = json_decode($row->source_data, true, flags: JSON_THROW_ON_ERROR);
                    if (! is_array($sourceData)) {
                        throw new RuntimeException("Employee import row {$row->id} has invalid source data.");
                    }

                    DB::table('employee_import_rows')->where('id', $row->id)->update([
                        'source_data' => Crypt::encryptString(json_encode($sourceData, JSON_THROW_ON_ERROR)),
                    ]);
                }
            });
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::table('employee_import_rows')->orderBy('id')->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $sourceData = json_decode(Crypt::decryptString($row->source_data), true, flags: JSON_THROW_ON_ERROR);

                    DB::table('employee_import_rows')->where('id', $row->id)->update([
                        'source_data' => json_encode($sourceData, JSON_THROW_ON_ERROR),
                    ]);
                }
            });
        });

        Schema::table('employee_import_rows', function (Blueprint $table) {
            $table->json('source_data')->change();
        });
    }
};
