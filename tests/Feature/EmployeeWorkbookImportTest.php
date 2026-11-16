<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeEmploymentHistory;
use App\Models\EmployeeImport;
use App\Models\EmployeeServiceProfile;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class EmployeeWorkbookImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        User::create([
            'name' => 'Test HRMO User',
            'email' => 'hrmo@example.test',
            'password' => 'TestAccount123!',
            'role' => 'admin',
            'is_active' => true,
        ]);
        Storage::fake('local');
    }

    public function test_hrmo_can_stage_all_source_columns_without_mapping_ambiguous_values(): void
    {
        $file = $this->workbookUpload();
        $hrmo = User::where('role', 'admin')->firstOrFail();

        $this->actingAs($hrmo)
            ->post(route('employee-imports.store'), ['file' => $file])
            ->assertRedirect();

        $import = EmployeeImport::firstOrFail();
        $row = $import->rows()->firstOrFail();
        $this->assertSame('pending_review', $import->status);
        $this->assertCount(27, $import->source_headers);
        $this->assertSame('OFFICE', $import->source_headers[7]['label']);
        $this->assertSame('OFFICE', $import->source_headers[18]['label']);
        $this->assertNull($import->source_headers[23]['label']);
        $this->assertSame('Office H source', $row->source_data[7]['display_value']);
        $this->assertSame('Office S source', $row->source_data[18]['display_value']);
        $this->assertSame('01/02/2024', $row->source_data[23]['display_value']);
        $this->assertDatabaseCount('employees', 0);

        $this->actingAs($hrmo)->get(route('employee-imports.show', $import))
            ->assertOk()
            ->assertSee('Column H — duplicate OFFICE header')
            ->assertSee('Column S — duplicate OFFICE header')
            ->assertSee('Column X — Unnamed column X')
            ->assertSee('source-only by default')
            ->assertSee('Pending HRMO review')
            ->assertSee('Select an OFFICE source column above to load its values before saving mappings.');
        $this->actingAs($hrmo)->get(route('employee-imports.show', [$import, 'office_column' => 8]))
            ->assertOk()
            ->assertSee('name="office_source_column" value="8"', false)
            ->assertSee('Add existing active departments and positions before approving mappings.')
            ->assertSee('Save and approve mappings')
            ->assertSee('disabled', false);
        $this->actingAs($hrmo)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Employee workbook review')
            ->assertSee('employee-test.xlsx');
    }

    public function test_hrmo_must_approve_explicit_mappings_before_import_and_source_only_date_stays_unmapped(): void
    {
        $department = Department::create(['name' => 'Import Test Office', 'code' => 'IMP']);
        $position = Position::create([
            'department_id' => $department->id,
            'title' => 'Import Test Position',
        ]);
        $import = $this->stage($this->workbookUpload());
        $officeKey = hash('sha256', 'Office H source');
        $positionKey = hash('sha256', "Office H source\0Import Test Position");

        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->post(route('employee-imports.confirm', $import), ['confirm_import' => '1'])
            ->assertSessionHasErrors('mapping');

        $this->putMapping($import, [
            'office_source_column' => 8,
            'name_format' => 'given_middle_surname',
            'employment_status' => 'Active',
            'date_source_column' => 24,
            'date_target' => 'source_only',
            'ambiguous_field_notes' => [8 => 'Unconfirmed source field', 19 => 'Unconfirmed source field', 24 => 'Meaning unknown'],
            'department_map' => [$officeKey => $department->id],
            'position_map' => [$positionKey => $position->id],
        ])->assertRedirect();

        $import->refresh();
        $this->assertSame('mapping_approved', $import->status);
        $this->assertNotNull($import->previewed_at);
        $this->assertSame(1, $import->review_count);
        $this->assertSame('needs_review', $import->rows()->firstOrFail()->status);
        $this->assertSame('source_only', $import->field_mappings['date_target']);
        $this->assertSame([8, 19, 24], array_map('intval', array_keys($import->field_mappings['ambiguous_field_notes'])));
        $this->assertDatabaseCount('employee_import_mapping_events', 1);
        $this->assertDatabaseCount('employees', 0);
        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->get(route('employee-imports.show', $import))
            ->assertOk()
            ->assertSee('Incomplete — HRMO review required')
            ->assertSee('Employee name does not match the HRMO-approved name format.');

        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->post(route('employee-imports.confirm', $import), ['confirm_import' => '1'])
            ->assertRedirect();

        $import->refresh();
        $this->assertSame('review_required', $import->status);
        $this->assertSame(1, $import->review_count);
        $this->assertDatabaseCount('employees', 0);

        $this->putMapping($import, [
            'office_source_column' => 8,
            'name_format' => 'surname_comma_given',
            'employment_status' => 'Active',
            'date_source_column' => 24,
            'date_target' => 'source_only',
            'ambiguous_field_notes' => [8 => 'Unconfirmed source field', 19 => 'Unconfirmed source field', 24 => 'Meaning unknown'],
            'department_map' => [$officeKey => $department->id],
            'position_map' => [$positionKey => $position->id],
        ])->assertRedirect();

        $import->refresh();
        $this->assertSame('mapping_approved', $import->status);
        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->get(route('employee-imports.show', $import))
            ->assertOk()
            ->assertSee('Ready for import · Valid');

        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->post(route('employee-imports.confirm', $import), ['confirm_import' => '1'])
            ->assertRedirect();

        $import->refresh();
        $employee = Employee::where('employee_no', 'IMP-0001')->firstOrFail();
        $this->assertSame('completed', $import->status);
        $this->assertSame(1, $import->imported_count);
        $this->assertDatabaseCount('employee_import_mapping_events', 2);
        $this->assertSame('Test', $employee->first_name);
        $this->assertSame('Person', $employee->last_name);
        $this->assertSame('2020-01-02', $employee->date_hired->toDateString());
        $this->assertNull(EmployeeServiceProfile::firstOrFail()->license_expiration_date);
        $this->assertSame(2, EmployeeEmploymentHistory::where('employee_id', $employee->id)->count());
        $this->assertNull($employee->user_id);
        $row = $import->rows()->firstOrFail();
        $this->assertSame('01/02/2024', $row->source_data[23]['display_value']);
        $this->assertSame('imported', $row->status);
        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->get(route('employee-imports.show', $import))
            ->assertOk()
            ->assertSee('Successfully imported');
        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->post(route('employee-imports.confirm', $import), ['confirm_import' => '1'])
            ->assertSessionHasErrors('mapping');
        $this->assertDatabaseCount('employees', 1);
    }

    public function test_import_does_not_overwrite_existing_employees_or_allow_non_hr_roles(): void
    {
        $department = Department::create(['name' => 'Existing Office', 'code' => 'EX']);
        $position = Position::create(['department_id' => $department->id, 'title' => 'Existing Position']);
        $existing = Employee::create([
            'department_id' => $department->id,
            'position_id' => $position->id,
            'employee_no' => 'IMP-0001',
            'first_name' => 'Existing',
            'last_name' => 'Record',
            'employment_status' => 'Active',
        ]);
        $import = $this->stage($this->workbookUpload());

        $employeeUser = User::create([
            'name' => 'TEST Employee',
            'email' => 'import-test-employee@example.test',
            'password' => 'TestPassword123!',
            'role' => 'employee',
            'is_active' => true,
        ]);
        $this->actingAs($employeeUser)
            ->get(route('employee-imports.index'))
            ->assertForbidden();
        $this->actingAs($employeeUser)
            ->put(route('employee-imports.mapping', $import), [])
            ->assertForbidden();
        $this->actingAs($employeeUser)
            ->post(route('employee-imports.confirm', $import), ['confirm_import' => '1'])
            ->assertForbidden();

        $this->assertSame('duplicate', $import->rows()->firstOrFail()->status);
        $this->assertSame('Existing', $existing->fresh()->first_name);
        $this->assertDatabaseCount('employees', 1);
        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->get(route('employee-imports.show', $import))
            ->assertOk()
            ->assertSee('Duplicate')
            ->assertSee('Employee reference number already exists; existing records will not be overwritten.');
    }

    public function test_hrmo_can_review_an_individual_name_without_changing_original_source_data(): void
    {
        $department = Department::create(['name' => 'Manual Review Office', 'code' => 'MR']);
        $position = Position::create(['department_id' => $department->id, 'title' => 'Import Test Position']);
        $import = $this->stage($this->workbookUpload('Unusual Name Format'));
        $officeKey = hash('sha256', 'Office H source');
        $positionKey = hash('sha256', "Office H source\0Import Test Position");

        $this->putMapping($import, [
            'office_source_column' => 8,
            'name_format' => 'surname_comma_given',
            'employment_status' => 'Active',
            'date_target' => 'source_only',
            'department_map' => [$officeKey => $department->id],
            'position_map' => [$positionKey => $position->id],
        ])->assertRedirect();

        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->post(route('employee-imports.confirm', $import), ['confirm_import' => '1'])
            ->assertRedirect();
        $import->refresh();
        $row = $import->rows()->firstOrFail();
        $this->assertSame('review_required', $import->status);
        $this->assertSame('Unusual Name Format', $row->source_data[1]['display_value']);

        $reviewer = User::where('role', 'admin')->firstOrFail();
        $this->actingAs($reviewer)->put(route('employee-imports.rows.review', [$import, $row]), [
            'first_name' => 'Reviewed',
            'middle_name' => 'By HRMO',
            'last_name' => 'Officer',
            'suffix' => '',
        ])->assertRedirect();

        $row->refresh();
        $this->assertSame('Reviewed', $row->review_data['name']['first_name']);
        $this->assertSame($reviewer->id, $row->reviewed_by);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'employee_import.row_reviewed',
            'subject_id' => $row->id,
        ]);
        $this->assertSame('Unusual Name Format', $row->source_data[1]['display_value']);

        $this->actingAs($reviewer)->post(route('employee-imports.confirm', $import), ['confirm_import' => '1'])
            ->assertRedirect();
        $employee = Employee::where('employee_no', 'IMP-0001')->firstOrFail();
        $this->assertSame('Reviewed', $employee->first_name);
        $this->assertSame('Officer', $employee->last_name);
        $this->assertSame('completed', $import->fresh()->status);
    }

    public function test_original_workbook_download_is_limited_to_hr_users_and_audited(): void
    {
        $import = $this->stage($this->workbookUpload());
        $employeeUser = User::create([
            'name' => 'TEST Employee',
            'email' => 'import-download-test@example.test',
            'password' => 'TestPassword123!',
            'role' => 'employee',
            'is_active' => true,
        ]);
        $this->actingAs($employeeUser)
            ->get(route('employee-imports.source', $import))
            ->assertForbidden();

        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->get(route('employee-imports.source', $import))
            ->assertOk();
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'employee_import.source_downloaded',
            'subject_id' => $import->id,
        ]);
    }

    private function putMapping(EmployeeImport $import, array $data)
    {
        return $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->put(route('employee-imports.mapping', $import), $data);
    }

    private function stage(UploadedFile $file): EmployeeImport
    {
        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->post(route('employee-imports.store'), ['file' => $file])
            ->assertRedirect();

        return EmployeeImport::latest('id')->firstOrFail();
    }

    private function workbookUpload(string $name = 'Person, Test'): UploadedFile
    {
        $headers = [
            'NO.', 'NAME', 'POSITION LEVEL', 'BDATE', 'CAT', 'GENDER',
            'NATURE OF APPOINTMENT', 'OFFICE', 'LATEST POSITION', '1st POSITION',
            '2nd POSITION', '3rd POSITION', '4th POSITION', 'DATE APPOINTED (1st)',
            'DATE APPOINTED (2nd)', 'DATE APPOINTED (3rd)', 'DATE APPOINTED (4th)',
            'LATEST DATE APPOINTED', 'OFFICE', 'ELIGIBILITY/PERCENT',
            'LICENSE- DATE EXPIRATION', 'EDUCATIONAL ATTAINMENT', 'YEARS IN SERVICE',
            null, 'EXPECTED DATE OF MANDATORY RETIREMENT', 'AGE', 'YEARS IN SERVICE',
        ];
        $values = [
            'IMP-0001', $name, 'Level 1', '1990-01-01', 'CAT-A', 'Female',
            'Permanent', 'Office H source', 'Import Test Position', 'Earlier Position',
            null, null, null, '2020-01-02', null, null, null,
            '2024-01-02', 'Office S source', 'Eligibility A', 'License source',
            'Bachelor', '4', '01/02/2024', '2050-01-02', '36', '4',
        ];
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($headers as $index => $header) {
            $sheet->setCellValue([$index + 1, 1], $header);
            $sheet->setCellValue([$index + 1, 2], $values[$index]);
        }
        foreach ([
            4 => '1990-01-01',
            14 => '2020-01-02',
            18 => '2024-01-02',
            24 => '2024-01-02',
            25 => '2050-01-02',
        ] as $column => $date) {
            $sheet->setCellValue([$column, 2], ExcelDate::PHPToExcel(new \DateTimeImmutable($date)));
            $sheet->getStyle([$column, 2])->getNumberFormat()->setFormatCode('mm/dd/yyyy');
        }
        $temporaryPath = tempnam(sys_get_temp_dir(), 'hris-import-test-');
        (new Xlsx($spreadsheet))->save($temporaryPath);
        $spreadsheet->disconnectWorksheets();
        $content = file_get_contents($temporaryPath);
        @unlink($temporaryPath);

        return UploadedFile::fake()->createWithContent('employee-test.xlsx', $content);
    }
}
