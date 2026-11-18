<?php

use App\Models\Department;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_imports', function (Blueprint $table) {
            $table->unsignedInteger('plantilla_item_count')->default(0);
            $table->unsignedInteger('vacant_count')->default(0);
            $table->unsignedInteger('excluded_office_count')->default(0);
            $table->json('excluded_records')->nullable();
            $table->unsignedInteger('position_reference_count')->default(0);
            $table->json('position_reference_titles')->nullable();
        });

        DB::transaction(function (): void {
            $departmentAliases = config('hris.employee_import.department_aliases', []);
            foreach (config('hris.employee_import.official_departments', []) as $official) {
                $name = $official['name'];
                $code = $official['code'];
                $aliases = collect($departmentAliases)
                    ->filter(fn (string $target) => $this->normalize($target) === $this->normalize($name)
                        || $this->normalize($target) === $this->normalize($code))
                    ->keys()
                    ->all();
                $approvedAliases = array_values(array_unique([$name, $code, ...$aliases]));
                $acceptedNames = collect([$name, $code, ...$aliases])
                    ->map(fn (string $value) => $this->normalize($value))
                    ->filter()
                    ->unique()
                    ->all();

                $matches = Department::query()->get()->filter(function (Department $department) use ($acceptedNames) {
                    $existingNames = [
                        $department->name,
                        $department->code,
                        ...($department->aliases ?? []),
                    ];

                    return collect($existingNames)
                        ->filter()
                        ->contains(fn (string $value) => in_array($this->normalize($value), $acceptedNames, true));
                });

                if ($matches->count() > 1) {
                    throw new \RuntimeException("Multiple existing departments match the approved office '{$name}'. Resolve the duplicate departments before migrating.");
                }

                $department = $matches->first();
                if (! $department) {
                    Department::query()->create([
                        'name' => $name,
                        'code' => $code,
                        'aliases' => $approvedAliases,
                        'is_active' => true,
                    ]);

                    continue;
                }

                $existingAliases = $department->aliases ?? [];
                $department->update([
                    'aliases' => array_values(array_unique([...$existingAliases, ...$approvedAliases])),
                    'code' => $department->code ?: $code,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('employee_imports', function (Blueprint $table) {
            $table->dropColumn([
                'plantilla_item_count',
                'vacant_count',
                'excluded_office_count',
                'excluded_records',
                'position_reference_count',
                'position_reference_titles',
            ]);
        });
    }

    private function normalize(string $value): string
    {
        return mb_strtoupper((string) preg_replace('/[^[:alnum:]]+/u', '', trim($value)));
    }
};
