<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeEmploymentHistory;
use App\Models\EmployeeImport;
use App\Models\EmployeeServiceProfile;
use App\Models\Position;
use App\Models\User;
use App\Services\EmployeeWorkbookImportService;
use App\Services\EmployeeUsernameGenerator;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
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
        $this->assertSame('mapping_required', $import->status);
        $this->assertCount(28, $import->source_headers);
        $this->assertSame('OFFICE', $import->source_headers[7]['label']);
        $this->assertSame('OFFICE', $import->source_headers[18]['label']);
        $this->assertNull($import->source_headers[23]['label']);
        $this->assertSame('Office H source', $row->source_data[7]['display_value']);
        $this->assertSame('Office S source', $row->source_data[18]['display_value']);
        $this->assertSame('01/02/2024', $row->source_data[23]['display_value']);
        $this->assertDatabaseCount('employees', 0);

        $this->actingAs($hrmo)->get(route('employee-imports.index'))
            ->assertOk()
            ->assertSee('Mapping Required')
            ->assertSee('1 total')
            ->assertSee('0 duplicates skipped');
        $this->actingAs($hrmo)->get(route('employee-imports.show', $import))
            ->assertOk()
            ->assertSee('OFFICE column')
            ->assertSee('Confirm automatic matches')
            ->assertSee('Column H — OFFICE')
            ->assertSee('Column S — OFFICE')
            ->assertSee('Office H source')
            ->assertSee('Needs department assignment');
        $this->actingAs($hrmo)->get(route('employee-imports.show', [$import, 'office_column' => 8]))
            ->assertOk()
            ->assertSee('name="office_source_column" value="8"', false)
            ->assertSee('Save matches and validate employees');
        $this->actingAs($hrmo)->get(route('employee-imports.show', [$import, 'office_column' => 19]))
            ->assertOk()
            ->assertSee('name="office_source_column" value="19"', false)
            ->assertSee('name="department_map['.hash('sha256', 'Office S source').']"', false);
        $this->actingAs($hrmo)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Employee workbook imports')
            ->assertSee('employee-test.xlsx');
    }

    public function test_admin_approves_mappings_and_imports_valid_names_without_individual_review(): void
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
            'employment_status' => 'Active',
            'date_source_column' => 24,
            'date_target' => 'source_only',
            'department_map' => [$officeKey => $department->id],
            'position_map' => [$positionKey => $position->id],
        ])->assertRedirect();

        $import->refresh();
        $this->assertSame('ready_for_import', $import->status);
        $this->assertNotNull($import->previewed_at);
        $this->assertSame(0, $import->review_count);
        $this->assertSame('ready', $import->rows()->firstOrFail()->status);
        $this->assertSame('source_only', $import->field_mappings['date_target']);
        $this->assertDatabaseCount('employee_import_mapping_events', 1);
        $this->assertDatabaseCount('employees', 0);
        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->get(route('employee-imports.show', $import))
            ->assertOk()
            ->assertSee('Ready to import')
            ->assertSee('Test')
            ->assertSee('Person')
            ->assertDontSee('Pending HRMO Review');

        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->post(route('employee-imports.confirm', $import), ['confirm_import' => '1'])
            ->assertRedirect();

        $import->refresh();
        $employee = Employee::where('employee_no', 'IMP-0001')->firstOrFail();
        $this->assertSame('completed', $import->status);
        $this->assertSame(1, $import->imported_count);
        $this->assertDatabaseCount('employee_import_mapping_events', 1);
        $this->assertSame('Test', $employee->first_name);
        $this->assertSame('Person', $employee->last_name);
        $this->assertSame('2020-01-02', $employee->date_hired->toDateString());
        $this->assertNull(EmployeeServiceProfile::firstOrFail()->license_expiration_date);
        $this->assertSame(2, EmployeeEmploymentHistory::where('employee_id', $employee->id)->count());
        $this->assertNotNull($employee->user_id);
        $this->assertSame('employee', $employee->user->role);
        $this->assertSame($employee->email, $employee->user->email);
        $this->assertSame('test.person', $employee->user->username);
        $this->assertTrue($employee->user->must_change_password);
        $this->assertFalse($employee->user->account_setup_completed);
        $this->assertFalse(Hash::check('password', $employee->user->password));
        $this->assertFalse(Hash::check('P@ssword!', $employee->user->password));
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

    public function test_exact_department_position_and_hr_confirmed_office_head_designation_are_matched_automatically(): void
    {
        $department = Department::create(['name' => 'Office H source', 'code' => 'OHS']);
        Position::create(['department_id' => $department->id, 'title' => 'Import Test Position']);
        config(['hris.employee_import.office_head_designations' => ['Import Test Position']]);
        $import = $this->stage($this->workbookUpload());
        Notification::fake();

        $this->putMapping($import, ['office_source_column' => 8])->assertRedirect();
        $import->refresh();
        $row = $import->rows()->firstOrFail();
        $this->assertSame('ready', $row->status);
        $this->assertSame('office_head', $row->review_data['classification']['role']);

        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->post(route('employee-imports.confirm', $import), ['confirm_import' => '1'])
            ->assertRedirect();

        $employee = Employee::where('employee_no', 'IMP-0001')->firstOrFail();
        $this->assertSame('office_head', $employee->user->role);
        $this->assertSame($employee->user_id, $department->fresh()->head_user_id);
        Notification::assertSentTo($employee->user, ResetPassword::class);
    }

    public function test_hr_approved_department_and_position_aliases_are_reused_for_later_batches(): void
    {
        $department = Department::create(['name' => 'Human Resources Office', 'code' => 'HRO']);
        $position = Position::create(['department_id' => $department->id, 'title' => 'Personnel Officer']);
        $office = 'Office H source';
        $sourcePosition = 'Import Test Position';
        $import = $this->stage($this->workbookUpload());
        $this->putMapping($import, [
            'office_source_column' => 8,
            'department_map' => [hash('sha256', $office) => $department->id],
            'position_map' => [hash('sha256', $office."\0".$sourcePosition) => $position->id],
        ])->assertRedirect();

        $this->assertSame([$office], $department->fresh()->aliases);
        $this->assertSame([$sourcePosition], $position->fresh()->aliases);

        $nextImport = $this->stage($this->workbookUpload());
        $importer = app(EmployeeWorkbookImportService::class);
        $departmentMap = $importer->suggestedDepartmentMap($nextImport, 8);
        $positionMap = $importer->suggestedPositionMap($nextImport, 8, $departmentMap);
        $this->assertSame($department->id, $departmentMap[hash('sha256', $office)]);
        $this->assertSame($position->id, $positionMap[hash('sha256', $office."\0".$sourcePosition)]);
        $summary = $importer->mappingSummary($nextImport, 8);
        $this->assertCount(0, $summary['departments']);
        $this->assertCount(0, $summary['positions']);
    }

    public function test_mapping_screen_suggests_close_existing_department_and_position_matches(): void
    {
        Department::create(['name' => 'Office H source', 'code' => 'OHS']);
        Position::create(['title' => 'Import Test Position']);
        $import = $this->stage($this->workbookUpload(overrides: [
            7 => 'Office H sorce',
            8 => 'Import Test Positon',
        ]));
        $summary = app(EmployeeWorkbookImportService::class)->mappingSummary($import, 8);
        $this->assertNotEmpty($summary['department_suggestions'][hash('sha256', 'Office H sorce')]);
        $this->assertNotEmpty($summary['position_suggestions'][hash('sha256', "Office H sorce\0Import Test Positon")]);

        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->get(route('employee-imports.show', [$import, 'office_column' => 8]))
            ->assertOk()
            ->assertSee('Suggested matches')
            ->assertSee('Office H source')
            ->assertSee('Import Test Position');
    }

    public function test_position_directory_can_mark_an_hr_approved_office_head_designation(): void
    {
        config(['hris.employee_import.office_head_designations' => []]);
        $department = Department::create(['name' => 'Office H source', 'code' => 'OHS']);
        $position = Position::create([
            'department_id' => $department->id,
            'title' => 'Office Director',
            'is_office_head_designation' => true,
        ]);
        $import = $this->stage($this->workbookUpload(overrides: [8 => $position->title]));
        $this->putMapping($import, ['office_source_column' => 8])->assertRedirect();

        $this->assertSame('office_head', $import->rows()->firstOrFail()->review_data['classification']['role']);
        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->post(route('employee-imports.confirm', $import), ['confirm_import' => '1'])
            ->assertRedirect();

        $employee = Employee::where('employee_no', 'IMP-0001')->firstOrFail();
        $this->assertSame('office_head', $employee->user->role);
        $this->assertSame($employee->user_id, $department->fresh()->head_user_id);
    }

    public function test_normalized_name_matches_require_confirmation_before_creating_a_second_employee(): void
    {
        $department = Department::create(['name' => 'Office H source', 'code' => 'OHS']);
        Position::create(['department_id' => $department->id, 'title' => 'Import Test Position']);
        Employee::create([
            'department_id' => $department->id,
            'employee_no' => 'EXIST-0001',
            'first_name' => 'Test',
            'last_name' => 'Person',
            'employment_status' => 'Active',
        ]);
        $import = $this->stage($this->workbookUpload());
        $this->putMapping($import, ['office_source_column' => 8])->assertRedirect();
        $row = $import->rows()->firstOrFail();
        $this->assertSame('needs_review', $row->status);
        $this->assertStringContainsString('same normalized name', implode(' ', $row->issues));

        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->put(route('employee-imports.rows.review', [$import, $row]), [
                'first_name' => 'Test',
                'last_name' => 'Person',
                'employee_no' => 'IMP-0001',
                'sex' => 'Female',
                'date_of_birth' => '1990-01-01',
                'position_level' => 'Level 1',
                'source_classification' => 'CAT-A',
                'employment_type' => 'Permanent',
                'email' => 'imp-0001@example.test',
                'confirm_distinct_employee' => '1',
            ])->assertRedirect();

        $this->assertSame('ready', $row->fresh()->status);
        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->post(route('employee-imports.confirm', $import), ['confirm_import' => '1'])
            ->assertRedirect();
        $this->assertDatabaseCount('employees', 2);
    }

    public function test_failed_account_creation_rolls_back_employee_and_account_inserts(): void
    {
        $department = Department::create(['name' => 'Office H source', 'code' => 'OHS']);
        Position::create(['department_id' => $department->id, 'title' => 'Import Test Position']);
        User::create([
            'name' => 'Existing Username',
            'username' => 'test.person',
            'email' => 'existing.username@example.test',
            'password' => 'ExistingPassword123!',
            'role' => 'employee',
            'is_active' => true,
        ]);
        $import = $this->stage($this->workbookUpload());
        $this->putMapping($import, ['office_source_column' => 8])->assertRedirect();
        $import->refresh();
        $initialUserCount = User::count();
        $usernameGenerator = \Mockery::mock(EmployeeUsernameGenerator::class);
        $usernameGenerator->shouldReceive('generate')->once()->andReturn('test.person');
        $this->app->instance(EmployeeUsernameGenerator::class, $usernameGenerator);

        try {
            app(EmployeeWorkbookImportService::class)->confirm(
                $import,
                User::where('role', 'admin')->firstOrFail()->id
            );
            $this->fail('The username conflict should abort the import transaction.');
        } catch (\Throwable $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        $this->assertDatabaseCount('employees', 0);
        $this->assertSame($initialUserCount, User::count());
        $this->assertSame('failed', $import->fresh()->status);
        $this->assertSame('failed', $import->rows()->firstOrFail()->status);
    }

    public function test_plantilla_worksheet_detects_row_two_headers_excludes_vacancies_and_reimports_idempotently(): void
    {
        $department = Department::create(['name' => 'Human Resources Office', 'code' => 'HRO']);
        Position::create(['department_id' => $department->id, 'title' => 'HR Officer']);
        $file = $this->plantillaWorkbookUpload();
        $import = $this->stage($file);

        $this->assertSame('PLANTILLA', $import->worksheet_name);
        $this->assertSame(2, $import->header_row);
        $this->assertCount(8, $import->worksheet_names);
        $this->assertCount(35, $import->source_headers);
        $this->assertSame(2, $import->row_count);
        $this->assertSame(4, $import->source_row_count);
        $this->assertSame(2, $import->excluded_rows_count);
        $this->assertSame([4, 6], $import->rows()->orderBy('source_row')->pluck('source_row')->all());
        $this->assertSame(['JUAN', 'M.', 'DELA CRUZ', null], array_values($this->app
            ->make(EmployeeWorkbookImportService::class)
            ->parsedName($import->rows()->firstOrFail())));
        $this->assertSame('P', $import->rows()->firstOrFail()->source_data[20]['display_value']);
        $this->assertSame('f', $import->rows()->firstOrFail()->source_data[17]['data_type']);
        $this->assertNotEmpty($import->rows()->firstOrFail()->source_data[17]['display_value']);
        $this->assertDatabaseCount('employees', 0);

        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->get(route('employee-imports.show', [$import, 'office_column' => 1]))
            ->assertOk()
            ->assertSee('Plantilla Guidelines')
            ->assertSee('ORGANIZATIONAL UNIT (1)')
            ->assertSee('headers on row 2');

        $this->putMapping($import, ['office_source_column' => 1])->assertRedirect();
        $import->refresh();
        $this->assertSame(['ready', 'ready'], $import->rows()->orderBy('source_row')->pluck('status')->all());
        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->post(route('employee-imports.confirm', $import), ['confirm_import' => '1'])
            ->assertRedirect();

        $employees = Employee::orderBy('first_name')->get();
        $this->assertCount(2, $employees);
        $this->assertSame(2, $import->fresh()->imported_count);
        $this->assertSame(2, $import->fresh()->accounts_created_count);
        $this->assertSame(['E', 'P'], EmployeeServiceProfile::orderBy('source_classification')->pluck('source_classification')->all());
        $this->assertSame(2, EmployeeServiceProfile::whereNotNull('source_identity_hash')->count());
        $this->assertSame(2, EmployeeServiceProfile::whereNotNull('plantilla_item_no')->count());
        foreach ($employees as $employee) {
            $this->assertStringStartsWith('HRIS-', $employee->employee_no);
            $this->assertSame($department->id, $employee->department_id);
            $this->assertSame('employee', $employee->user->role);
            $this->assertTrue($employee->user->must_change_password);
        }
        $passwordHashes = $employees->pluck('user.password', 'id')->all();

        $reimport = $this->stage($this->plantillaWorkbookUpload());
        $this->assertSame(['existing_to_update', 'existing_to_update'], $reimport->rows()->orderBy('source_row')->pluck('status')->all());
        $this->putMapping($reimport, ['office_source_column' => 1])->assertRedirect();
        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->post(route('employee-imports.confirm', $reimport), ['confirm_import' => '1'])
            ->assertRedirect();

        $this->assertDatabaseCount('employees', 2);
        $this->assertSame(2, $reimport->fresh()->updated_count);
        $this->assertSame(0, $reimport->fresh()->accounts_created_count);
        foreach (Employee::with('user')->get() as $employee) {
            $this->assertSame($passwordHashes[$employee->id], $employee->user->password);
        }
    }

    public function test_missing_source_email_does_not_block_employee_or_account_creation(): void
    {
        $department = Department::create(['name' => 'Office H source', 'code' => 'OHS']);
        $position = Position::create(['department_id' => $department->id, 'title' => 'Import Test Position']);
        $import = $this->stage($this->workbookUpload(includeEmail: false));
        $this->putMapping($import, [
            'office_source_column' => 8,
            'department_map' => [hash('sha256', 'Office H source') => $department->id],
            'position_map' => [hash('sha256', "Office H source\0Import Test Position") => $position->id],
        ])->assertRedirect();

        $row = $import->rows()->firstOrFail();
        $this->assertSame('ready', $row->status);
        $this->assertDatabaseCount('employees', 0);

        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->post(route('employee-imports.confirm', $import), ['confirm_import' => '1'])
            ->assertRedirect();
        $employee = Employee::where('employee_no', 'IMP-0001')->firstOrFail();
        $this->assertNull($employee->user->email);
        $this->assertSame('test.person', $employee->user->username);
        $this->assertTrue($employee->user->must_change_password);
        $this->assertFalse($employee->user->account_setup_completed);
        $this->assertSame('employee', $employee->user->role);
    }

    public function test_legacy_xls_workbooks_can_be_uploaded_and_analyzed(): void
    {
        $import = $this->stage($this->workbookUpload(extension: 'xls'));

        $this->assertSame('mapping_required', $import->status);
        $this->assertCount(28, $import->source_headers);
        $this->assertSame('Office H source', $import->rows()->firstOrFail()->source_data[7]['display_value']);
    }

    public function test_imported_account_can_set_its_password_from_a_single_use_reset_link(): void
    {
        $user = User::create([
            'name' => 'Imported Employee',
            'email' => 'setup.employee@example.test',
            'password' => 'RandomUnsharedPassword123!',
            'role' => 'employee',
            'is_active' => true,
            'must_change_password' => true,
            'account_setup_completed' => false,
        ]);
        $token = Password::createToken($user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'EmployeeSecurePass123!',
            'password_confirmation' => 'EmployeeSecurePass123!',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('EmployeeSecurePass123!', $user->fresh()->password));
        $this->assertFalse(Hash::check('RandomUnsharedPassword123!', $user->fresh()->password));
        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertFalse($user->fresh()->account_setup_completed);
        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'EmployeeSecurePass123!',
        ])->assertRedirect(route('account.setup'));
    }

    public function test_common_name_formats_keep_middle_initials_and_compound_surnames(): void
    {
        $examples = [
            'ABECILLA, DOMINADOR P.' => ['DOMINADOR', 'P.', 'ABECILLA', null],
            'AGPALZA, FLORYNETH P.' => ['FLORYNETH', 'P.', 'AGPALZA', null],
            'ALARIO, JOENA C.' => ['JOENA', 'C.', 'ALARIO', null],
            'DE LA CRUZ, MARIA ELENA' => ['MARIA', 'ELENA', 'DE LA CRUZ', null],
            'Maria Elena de la Cruz' => ['Maria', 'Elena', 'de la Cruz', null],
            'SMITH JR., JOHN P.' => ['JOHN', 'P.', 'SMITH', 'Jr.'],
        ];
        $importer = app(EmployeeWorkbookImportService::class);

        foreach ($examples as $sourceName => [$firstName, $middleName, $lastName, $suffix]) {
            $import = $this->stage($this->workbookUpload($sourceName));
            $row = $import->rows()->firstOrFail();
            $parsed = $importer->parsedName($row);

            $this->assertSame($sourceName, $row->source_data[1]['display_value']);
            $this->assertSame($firstName, $parsed['first_name']);
            $this->assertSame($middleName, $parsed['middle_name']);
            $this->assertSame($lastName, $parsed['last_name']);
            $this->assertSame($suffix, $parsed['suffix']);
        }
    }

    public function test_valid_rows_import_while_problematic_rows_can_be_corrected_and_resumed(): void
    {
        $department = Department::create(['name' => 'Mixed Import Office', 'code' => 'MIX']);
        $position = Position::create(['department_id' => $department->id, 'title' => 'Import Test Position']);
        $import = $this->stage($this->workbookUpload('Good, Employee', [], [
            [0 => 'IMP-0002', 1 => 'Needs, Correction', 3 => '', 5 => ''],
        ]));
        $this->assertSame('', $import->rows()->where('source_row', 3)->firstOrFail()->source_data[5]['display_value']);
        $officeKey = hash('sha256', 'Office H source');
        $positionKey = hash('sha256', "Office H source\0Import Test Position");

        $this->putMapping($import, [
            'office_source_column' => 8,
            'employment_status' => 'Active',
            'date_target' => 'source_only',
            'department_map' => [$officeKey => $department->id],
            'position_map' => [$positionKey => $position->id],
        ])->assertRedirect();

        $import->refresh();
        $this->assertSame(1, $import->review_count);
        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->post(route('employee-imports.confirm', $import), ['confirm_import' => '1'])
            ->assertRedirect();
        $import->refresh();
        $this->assertSame('completed_with_errors', $import->status);
        $this->assertSame(1, $import->imported_count);
        $this->assertDatabaseHas('employees', ['employee_no' => 'IMP-0001']);
        $this->assertDatabaseMissing('employees', ['employee_no' => 'IMP-0002']);

        $problemRow = $import->rows()->where('source_row', 3)->firstOrFail();
        $this->assertContains('Birth date is missing, invalid, or not in the past.', $problemRow->issues);
        $this->assertContains('Gender is missing or has an unsupported value.', $problemRow->issues);
        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->put(route('employee-imports.rows.review', [$import, $problemRow]), [
                'first_name' => 'Needs',
                'middle_name' => '',
                'last_name' => 'Correction',
                'suffix' => '',
                'sex' => 'F',
                'date_of_birth' => '1990-01-01',
                'email' => 'imp-0002@example.test',
            ])->assertRedirect();
        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->post(route('employee-imports.confirm', $import), ['confirm_import' => '1'])
            ->assertRedirect();

        $this->assertSame('completed', $import->fresh()->status);
        $this->assertSame(2, $import->fresh()->imported_count);
        $this->assertDatabaseCount('employees', 2);
        $correctedEmployee = Employee::where('employee_no', 'IMP-0002')->firstOrFail();
        $this->assertSame('F', $correctedEmployee->sex);
        $this->assertSame('1990-01-01', $correctedEmployee->date_of_birth->toDateString());
        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->post(route('employee-imports.confirm', $import), ['confirm_import' => '1'])
            ->assertSessionHasErrors('mapping');
        $this->assertDatabaseCount('employees', 2);
    }

    public function test_existing_employees_are_updated_and_non_hr_roles_cannot_run_imports(): void
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

        $this->assertSame('existing_to_update', $import->rows()->firstOrFail()->status);
        $this->assertSame('Existing', $existing->fresh()->first_name);
        $this->assertDatabaseCount('employees', 1);
        $this->putMapping($import, [
            'office_source_column' => 8,
            'department_map' => [hash('sha256', 'Office H source') => $department->id],
            'position_map' => [hash('sha256', "Office H source\0Import Test Position") => $position->id],
        ])->assertRedirect();
        $this->assertSame('ready_to_update', $import->rows()->firstOrFail()->status);
        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->post(route('employee-imports.confirm', $import), ['confirm_import' => '1'])
            ->assertRedirect();

        $existing->refresh();
        $this->assertSame('Test', $existing->first_name);
        $this->assertNotNull($existing->user_id);
        $this->assertSame('employee', $existing->user->role);
        $this->assertSame(1, $import->fresh()->updated_count);
        $this->assertSame(1, $import->fresh()->accounts_created_count);
        $this->assertDatabaseCount('employees', 1);
        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->get(route('employee-imports.show', $import))
            ->assertOk()
            ->assertSee('Employee records updated')
            ->assertSee('Updated');

        $reimport = $this->stage($this->workbookUpload());
        $this->putMapping($reimport, [
            'office_source_column' => 8,
            'department_map' => [hash('sha256', 'Office H source') => $department->id],
            'position_map' => [hash('sha256', "Office H source\0Import Test Position") => $position->id],
        ])->assertRedirect();
        $reimport->refresh();
        $this->assertSame('ready_to_update', $reimport->rows()->firstOrFail()->status);
        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->post(route('employee-imports.confirm', $reimport), ['confirm_import' => '1'])
            ->assertRedirect();
        $this->assertSame(1, Employee::count());
        $this->assertSame(1, $reimport->fresh()->updated_count);
        $this->assertSame(0, $reimport->fresh()->accounts_created_count);
    }

    public function test_import_skips_protected_admin_accounts_without_modifying_employee_or_login_data(): void
    {
        $department = Department::create(['name' => 'Office H source', 'code' => 'OHS']);
        $position = Position::create(['department_id' => $department->id, 'title' => 'Import Test Position']);
        $user = User::create([
            'name' => 'Protected Administrator',
            'email' => 'protected.admin@example.test',
            'password' => 'PreservedPassword123!',
            'role' => 'admin',
            'is_active' => true,
            'can_final_approve' => true,
        ]);
        $employee = Employee::create([
            'user_id' => $user->id,
            'department_id' => $department->id,
            'position_id' => $position->id,
            'employee_no' => 'IMP-0001',
            'first_name' => 'Old',
            'last_name' => 'Name',
            'email' => $user->email,
            'employment_status' => 'Separated',
        ]);
        $import = $this->stage($this->workbookUpload());
        Notification::fake();
        $this->putMapping($import, ['office_source_column' => 8])->assertRedirect();
        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->post(route('employee-imports.confirm', $import), ['confirm_import' => '1'])
            ->assertRedirect();

        $employee->refresh();
        $user->refresh();
        $this->assertSame('Old', $employee->first_name);
        $this->assertSame('Separated', $employee->employment_status);
        $this->assertSame('protected.admin@example.test', $user->email);
        $this->assertSame('admin', $user->role);
        $this->assertTrue(Hash::check('PreservedPassword123!', $user->password));
        $this->assertTrue($user->can_final_approve);
        $this->assertSame('duplicate', $import->rows()->firstOrFail()->status);
        $this->assertSame(0, $import->fresh()->updated_count);
        $this->assertSame(0, $import->fresh()->accounts_created_count);
        $this->assertSame(1, $import->fresh()->skipped_count);
        Notification::assertNothingSent();
    }

    public function test_workbook_employment_status_is_normalized_and_unsupported_values_need_correction(): void
    {
        $department = Department::create(['name' => 'Office H source', 'code' => 'OHS']);
        Position::create(['department_id' => $department->id, 'title' => 'Import Test Position']);
        $import = $this->stage($this->workbookUpload(
            extraRows: [[0 => 'IMP-0002', 28 => 'Employment Pending']],
            employmentStatus: 'On Leave'
        ));
        $this->putMapping($import, ['office_source_column' => 8])->assertRedirect();

        $rows = $import->rows()->orderBy('source_row')->get();
        $this->assertSame('ready', $rows[0]->status);
        $this->assertSame('needs_review', $rows[1]->status);
        $this->assertContains('Employment status is not a supported value.', $rows[1]->issues);

        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->post(route('employee-imports.confirm', $import), ['confirm_import' => '1'])
            ->assertRedirect();
        $this->assertSame('On Leave', Employee::where('employee_no', 'IMP-0001')->firstOrFail()->employment_status);
        $this->assertSame('completed_with_errors', $import->fresh()->status);
    }

    public function test_duplicate_employee_numbers_inside_a_workbook_are_skipped_once(): void
    {
        $department = Department::create(['name' => 'Office H source', 'code' => 'OHS']);
        Position::create(['department_id' => $department->id, 'title' => 'Import Test Position']);
        $import = $this->stage($this->workbookUpload(extraRows: [[1 => 'Second, Person']]));
        $this->assertSame(
            ['unvalidated', 'duplicate'],
            $import->rows()->orderBy('source_row')->pluck('status')->all()
        );
        $this->putMapping($import, ['office_source_column' => 8])->assertRedirect();
        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->post(route('employee-imports.confirm', $import), ['confirm_import' => '1'])
            ->assertRedirect();

        $this->assertSame(1, Employee::count());
        $this->assertSame(1, $import->fresh()->imported_count);
        $this->assertSame(1, $import->fresh()->skipped_count);
        $this->assertSame('duplicate', $import->rows()->where('source_row', 3)->value('status'));
    }

    public function test_hrmo_can_review_an_individual_name_without_changing_original_source_data(): void
    {
        $department = Department::create(['name' => 'Manual Review Office', 'code' => 'MR']);
        $position = Position::create(['department_id' => $department->id, 'title' => 'Import Test Position']);
        $import = $this->stage($this->workbookUpload('Unparseable'));
        $officeKey = hash('sha256', 'Office H source');
        $positionKey = hash('sha256', "Office H source\0Import Test Position");

        $this->putMapping($import, [
            'office_source_column' => 8,
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
        $this->assertSame('completed_with_errors', $import->status);
        $this->assertSame('Unparseable', $row->source_data[1]['display_value']);

        $reviewer = User::where('role', 'admin')->firstOrFail();
        $this->actingAs($reviewer)->put(route('employee-imports.rows.review', [$import, $row]), [
            'first_name' => 'Reviewed',
            'middle_name' => 'By HRMO',
            'last_name' => 'Officer',
            'suffix' => '',
            'email' => 'imp-0001@example.test',
        ])->assertRedirect();

        $row->refresh();
        $this->assertSame('Reviewed', $row->review_data['name']['first_name']);
        $this->assertSame($reviewer->id, $row->reviewed_by);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'employee_import.row_reviewed',
            'subject_id' => $row->id,
        ]);
        $this->assertSame('Unparseable', $row->source_data[1]['display_value']);

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
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        return EmployeeImport::latest('id')->firstOrFail();
    }

    private function workbookUpload(
        string $name = 'Person, Test',
        array $overrides = [],
        array $extraRows = [],
        bool $includeEmail = true,
        string $extension = 'xlsx',
        ?string $employmentStatus = null
    ): UploadedFile {
        $headers = [
            'NO.', 'NAME', 'POSITION LEVEL', 'BDATE', 'CAT', 'GENDER',
            'NATURE OF APPOINTMENT', 'OFFICE', 'LATEST POSITION', '1st POSITION',
            '2nd POSITION', '3rd POSITION', '4th POSITION', 'DATE APPOINTED (1st)',
            'DATE APPOINTED (2nd)', 'DATE APPOINTED (3rd)', 'DATE APPOINTED (4th)',
            'LATEST DATE APPOINTED', 'OFFICE', 'ELIGIBILITY/PERCENT',
            'LICENSE- DATE EXPIRATION', 'EDUCATIONAL ATTAINMENT', 'YEARS IN SERVICE',
            null, 'EXPECTED DATE OF MANDATORY RETIREMENT', 'AGE', 'YEARS IN SERVICE',
        ];
        if ($includeEmail) {
            $headers[] = 'EMAIL';
        }
        if ($employmentStatus !== null) {
            $headers[] = 'EMPLOYMENT STATUS';
        }
        $baseValues = [
            'IMP-0001', $name, 'Level 1', '1990-01-01', 'CAT-A', 'Female',
            'Permanent', 'Office H source', 'Import Test Position', 'Earlier Position',
            null, null, null, '2020-01-02', null, null, null,
            '2024-01-02', 'Office S source', 'Eligibility A', 'License source',
            'Bachelor', '4', '01/02/2024', '2050-01-02', '36', '4',
        ];
        if ($includeEmail) {
            $baseValues[] = 'imp-0001@example.test';
        }
        if ($employmentStatus !== null) {
            $baseValues[] = $employmentStatus;
        }
        $values = array_replace($baseValues, $overrides);
        $rows = [$values];
        foreach ($extraRows as $extraRow) {
            $row = array_replace($baseValues, $extraRow);
            if ($includeEmail && ! array_key_exists(27, $extraRow)) {
                $row[27] = strtolower(str_replace([' ', ','], '', (string) $row[0])).'@example.test';
            }
            $rows[] = $row;
        }
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($headers as $index => $header) {
            $sheet->setCellValue([$index + 1, 1], $header);
            foreach ($rows as $rowIndex => $rowValues) {
                $sheet->setCellValue([$index + 1, $rowIndex + 2], $rowValues[$index]);
            }
        }
        $dateColumns = [
            4 => '1990-01-01',
            14 => '2020-01-02',
            18 => '2024-01-02',
            24 => '2024-01-02',
            25 => '2050-01-02',
        ];
        foreach ($rows as $rowIndex => $rowValues) {
            foreach ($dateColumns as $column => $date) {
                if ($rowValues[$column - 1] === null || $rowValues[$column - 1] === '') {
                    continue;
                }
                $sheet->setCellValue([$column, $rowIndex + 2], ExcelDate::PHPToExcel(new \DateTimeImmutable($date)));
                $sheet->getStyle([$column, $rowIndex + 2])->getNumberFormat()->setFormatCode('mm/dd/yyyy');
            }
        }
        $temporaryPath = tempnam(sys_get_temp_dir(), 'hris-import-test-');
        $writer = $extension === 'xls' ? new Xls($spreadsheet) : new Xlsx($spreadsheet);
        $writer->save($temporaryPath);
        $spreadsheet->disconnectWorksheets();
        $content = file_get_contents($temporaryPath);
        @unlink($temporaryPath);

        return UploadedFile::fake()->createWithContent("employee-test.{$extension}", $content);
    }

    private function plantillaWorkbookUpload(): UploadedFile
    {
        $headers = [
            'ORGANIZATIONAL UNIT (1)', 'ITEM NUMBER (2)', 'POSITION TITLE (3)',
            'SALARY GRADE (4)', 'AUTHORIZED SALARY (5)', 'ACTUAL SALARY (6)',
            'STEP (7)', 'TIN (8)', 'DATE OF ORIGINAL APPOINTMENT (9)',
            'DATE OF LAST PROMOTION (10)', 'DATE OF LAST SALARY INCREASE (11)',
            'LEVEL (12)', 'LAST NAME (13)', 'FIRST NAME (14)', 'MIDDLE NAME (15)',
            'GENDER (16)', 'BIRTHDATE (17)', 'AGE (18)', 'ELIGIBILITY/IES (19)',
            'EDUCATIONAL LEVEL (20)', 'STATUS (21)', 'DATE OF LAST APPOINTMENT (22)',
            'PREVIOUS POSITION (23)', 'DATE OF PREVIOUS APPOINTMENT (24)',
            'PLANTILLA STATUS (25)', 'REMARKS (26)', 'CONTACT NUMBER (27)',
            'EMAIL ADDRESS (28)', 'ADDRESS (29)', 'GSIS BP NUMBER (30)',
            'PAG-IBIG NUMBER (31)', 'PHILHEALTH NUMBER (32)', 'SSS NUMBER (33)',
            'CIVIL STATUS (34)', 'RELIGION (35)',
        ];
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setTitle('HRMO');
        $spreadsheet->getActiveSheet()->setCellValue('A1', 'HRMO instructions');
        $plantilla = $spreadsheet->createSheet();
        $plantilla->setTitle('PLANTILLA');
        $plantilla->mergeCells('A1:AI1');
        $plantilla->setCellValue('A1', 'Municipal Plantilla of Personnel');
        foreach ($headers as $index => $header) {
            $plantilla->setCellValue([$index + 1, 2], $header);
        }

        $sectionRow = array_fill(0, count($headers), null);
        $sectionRow[0] = 'OFFICE OF THE MUNICIPAL MAYOR';
        $vacantRow = array_fill(0, count($headers), null);
        $vacantRow[0] = 'Human Resources Office';
        $vacantRow[1] = 'VACANT-003';
        $vacantRow[2] = 'Vacant Position';
        $employeeRows = [
            [
                'Human Resources Office', 'HR-001', 'HR Officer', '10', '1000', '1000', '1',
                'TIN-001', '2018-01-02', null, null, '1', 'DELA CRUZ', 'JUAN', 'M.',
                'M', '1985-03-04', null, null, 'Bachelor', 'P', '2020-06-07',
                null, null, 'Active', null, null, null, null, 'GSIS-001',
            ],
            [
                'Human Resources Office', 'HR-002', 'HR Officer', '10', '1000', '1000', '1',
                null, '2019-02-03', null, null, '1', 'SANTOS', 'ANA', null,
                'F', '1990-04-05', null, null, 'Bachelor', 'E', '2021-07-08',
                null, null, 'Active', null, null, null, null, null,
            ],
        ];

        foreach ([3 => $sectionRow, 4 => $employeeRows[0], 5 => $vacantRow, 6 => $employeeRows[1]] as $rowNumber => $values) {
            foreach ($values as $index => $value) {
                if ($value !== null && $value !== '') {
                    $plantilla->setCellValue([$index + 1, $rowNumber], $value);
                }
            }
        }
        foreach ([4 => '1985-03-04', 6 => '1990-04-05'] as $rowNumber => $date) {
            $plantilla->setCellValue("Q{$rowNumber}", ExcelDate::PHPToExcel(new \DateTimeImmutable($date)));
            $plantilla->getStyle("Q{$rowNumber}")->getNumberFormat()->setFormatCode('mm/dd/yyyy');
            $plantilla->setCellValue("R{$rowNumber}", '=DATEDIF(Q'.$rowNumber.',TODAY(),"Y")');
        }
        $plantilla->getCell('P4')->getDataValidation()->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
        $plantilla->getCell('P4')->getDataValidation()->setFormula1('"M,F"');

        foreach ([
            'Plantilla Guidelines',
            'Positions',
            'Educational Level',
            'JOCOSC6 and 1st level Career',
            'CS Eligibility for Latest Appt.',
            'Eligibilities',
        ] as $title) {
            $reference = $spreadsheet->createSheet();
            $reference->setTitle($title);
            $reference->setCellValue('A1', 'Reference information');
        }
        $spreadsheet->getSheetByName('Eligibilities')->setSheetState(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_HIDDEN);
        $spreadsheet->setActiveSheetIndex(0);

        $temporaryPath = tempnam(sys_get_temp_dir(), 'hris-plantilla-test-');
        (new Xlsx($spreadsheet))->save($temporaryPath);
        $spreadsheet->disconnectWorksheets();
        $content = file_get_contents($temporaryPath);
        @unlink($temporaryPath);

        return UploadedFile::fake()->createWithContent('REVISED-TEMPLATE_HRMO-AND-PLANTILLA-with-guide-in-excel.xlsx', $content);
    }
}
