<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\BiometricImport;
use App\Models\BiometricImportRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveApplication;
use App\Models\LeaveApplicationDocument;
use App\Models\LeaveCredit;
use App\Models\LeaveCreditTransaction;
use App\Models\LeaveType;
use App\Models\OfficeHeadAvailability;
use App\Models\Position;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HrisWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        User::create([
            'name' => 'Test Administrator',
            'email' => 'administrator@example.test',
            'password' => 'TestAccount123!',
            'role' => 'admin',
            'is_active' => true,
            'can_final_approve' => true,
        ]);
        User::create([
            'name' => 'Test HRMO User',
            'email' => 'hrmo@example.test',
            'password' => 'TestAccount123!',
            'role' => 'hrmo',
            'is_active' => true,
        ]);
        $officeHead = User::create([
            'name' => 'Test Office Head',
            'email' => 'office-head@example.test',
            'password' => 'TestAccount123!',
            'role' => 'office_head',
            'is_active' => true,
        ]);
        $department = Department::create([
            'name' => 'TEST Department',
            'code' => 'TEST',
            'head_user_id' => $officeHead->id,
        ]);
        $position = Position::create([
            'department_id' => $department->id,
            'title' => 'TEST Position',
        ]);
        $employeeUser = User::create([
            'name' => 'TEST Employee Account',
            'email' => 'test-employee@example.test',
            'password' => 'TestAccount123!',
            'role' => 'employee',
            'is_active' => true,
        ]);
        $employee = Employee::create([
            'user_id' => $employeeUser->id,
            'department_id' => $department->id,
            'position_id' => $position->id,
            'employee_no' => 'TEST-0001',
            'biometric_id' => 'TEST-BIO-0001',
            'first_name' => 'Test',
            'last_name' => 'Person',
            'employment_status' => 'Active',
            'employment_type' => 'Permanent',
            'date_hired' => '2020-01-06',
        ]);
        LeaveCredit::create([
            'employee_id' => $employee->id,
            'leave_type_id' => LeaveType::where('code', 'VACATION')->value('id'),
            'earned_credits' => 12,
            'effective_date' => today(),
        ]);
    }

    public function test_employee_can_login_and_submit_a_leave_application_using_workdays(): void
    {
        $employeeUser = User::where('role', 'employee')->firstOrFail();
        Holiday::create(['holiday_date' => '2026-01-20', 'name' => 'Configured municipal holiday']);

        $this->post('/login', [
            'email' => $employeeUser->email,
            'password' => 'TestAccount123!',
        ])->assertRedirect(route('dashboard'));

        $this->post(route('leave.store'), [
            'intent' => 'submit',
            'leave_type_id' => LeaveType::where('code', 'VACATION')->value('id'),
            'start_date' => '2026-01-19',
            'end_date' => '2026-01-23',
            'commutation_requested' => '0',
        ])->assertRedirect();

        $application = LeaveApplication::latest('id')->firstOrFail();
        $this->assertSame('Under Office Head Review', $application->status);
        $this->assertSame(4, $application->working_days);
        $this->assertDatabaseHas('leave_approval_histories', [
            'leave_application_id' => $application->id,
            'action' => 'queued_for_office_head_review',
        ]);
        $this->assertSame(
            User::where('role', 'office_head')->value('id'),
            $application->office_head_reviewer_id
        );
    }

    public function test_unavailable_office_head_uses_authorized_mayor_fallback_then_hrmo_finalizes_without_duplicate_decision(): void
    {
        $department = Department::where('code', 'TEST')->firstOrFail();
        $officeHead = User::where('role', 'office_head')->firstOrFail();
        $mayor = User::where('role', 'admin')->firstOrFail();
        $mayor->update(['role' => 'mayor', 'can_final_approve' => false]);
        OfficeHeadAvailability::create([
            'department_id' => $department->id,
            'office_head_user_id' => $officeHead->id,
            'availability_status' => 'on_leave',
            'start_date' => today(),
            'end_date' => today()->addDays(2),
            'reason' => 'Authorized test availability period',
            'updated_by' => User::where('role', 'hrmo')->value('id'),
        ]);

        $employeeUser = Employee::where('employee_no', 'TEST-0001')->firstOrFail()->user;
        $this->actingAs($employeeUser)->post(route('leave.store'), [
            'intent' => 'submit',
            'leave_type_id' => LeaveType::where('code', 'VACATION')->value('id'),
            'start_date' => today()->addDays(2)->toDateString(),
            'end_date' => today()->addDays(3)->toDateString(),
            'commutation_requested' => '0',
        ])->assertRedirect();

        $application = LeaveApplication::latest('id')->firstOrFail();
        $this->assertSame('Under Alternate Office Head Review', $application->status);
        $this->assertSame($mayor->id, $application->office_head_reviewer_id);

        $this->actingAs($mayor)->post(route('leave.review', $application), [
            'action' => 'approve',
            'days_with_pay' => 2,
            'days_without_pay' => 0,
            'remarks' => 'Authorized alternate decision.',
        ])->assertRedirect();
        $this->assertSame('Under HRMO Verification', $application->fresh()->status);
        $this->assertDatabaseHas('leave_approval_histories', [
            'leave_application_id' => $application->id,
            'action' => 'alternate_final_approve_recorded',
            'user_id' => $mayor->id,
        ]);

        $this->actingAs(User::where('role', 'hrmo')->firstOrFail())
            ->post(route('leave.review', $application), [
                'action' => 'verify',
                'verified_working_days' => 2,
                'certifier_position' => 'Human Resource Officer',
                'certifier_signature' => 'HRMO Officer',
            ])->assertRedirect();

        $application->refresh();
        $this->assertSame('Approved', $application->status);
        $this->assertSame($mayor->id, $application->decided_by);
        $this->assertSame(1, $application->history()->where('action', 'alternate_final_approve_recorded')->count());
        $this->assertSame(1, $application->history()->where('action', 'verified_and_finalized_alternate_decision')->count());
        $this->assertSame(10.0, LeaveCredit::where('employee_id', $employeeUser->employee->id)
            ->where('leave_type_id', $application->leave_type_id)->firstOrFail()->balance);
        $this->assertSame(1, LeaveCreditTransaction::where('leave_application_id', $application->id)->count());
    }

    public function test_unavailable_office_head_without_a_single_authorized_fallback_cannot_submit(): void
    {
        User::where('role', 'admin')->update(['can_final_approve' => false]);
        $department = Department::where('code', 'TEST')->firstOrFail();
        $officeHead = User::where('role', 'office_head')->firstOrFail();
        OfficeHeadAvailability::create([
            'department_id' => $department->id,
            'office_head_user_id' => $officeHead->id,
            'availability_status' => 'official_business',
            'start_date' => today(),
            'end_date' => today()->addDays(3),
            'reason' => 'Authorized test availability period',
            'updated_by' => User::where('role', 'hrmo')->value('id'),
        ]);

        $employeeUser = Employee::where('employee_no', 'TEST-0001')->firstOrFail()->user;
        $this->actingAs($employeeUser)->post(route('leave.store'), [
            'intent' => 'submit',
            'leave_type_id' => LeaveType::where('code', 'VACATION')->value('id'),
            'start_date' => today()->addDays(2)->toDateString(),
            'end_date' => today()->addDays(3)->toDateString(),
            'commutation_requested' => '0',
        ])->assertSessionHasErrors('availability');

        $this->assertDatabaseMissing('leave_applications', [
            'employee_id' => $employeeUser->employee->id,
            'status' => 'Under Alternate Office Head Review',
        ]);
    }

    public function test_hrmo_can_return_a_mismatched_alternate_decision_and_resubmission_clears_it(): void
    {
        $department = Department::where('code', 'TEST')->firstOrFail();
        $officeHead = User::where('role', 'office_head')->firstOrFail();
        OfficeHeadAvailability::create([
            'department_id' => $department->id,
            'office_head_user_id' => $officeHead->id,
            'availability_status' => 'on_leave',
            'start_date' => today(),
            'end_date' => today()->addDays(3),
            'reason' => 'Authorized test availability period',
            'updated_by' => User::where('role', 'hrmo')->value('id'),
        ]);
        $employeeUser = Employee::where('employee_no', 'TEST-0001')->firstOrFail()->user;

        $this->actingAs($employeeUser)->post(route('leave.store'), [
            'intent' => 'submit',
            'leave_type_id' => LeaveType::where('code', 'VACATION')->value('id'),
            'start_date' => today()->addDays(2)->toDateString(),
            'end_date' => today()->addDays(3)->toDateString(),
            'commutation_requested' => '0',
        ])->assertRedirect();
        $application = LeaveApplication::latest('id')->firstOrFail();
        $admin = User::where('role', 'admin')->firstOrFail();

        $this->actingAs($admin)->post(route('leave.review', $application), [
            'action' => 'approve',
            'days_with_pay' => 2,
            'days_without_pay' => 0,
        ])->assertRedirect();

        $hrmo = User::where('role', 'hrmo')->firstOrFail();
        $this->actingAs($hrmo)->post(route('leave.review', $application), [
            'action' => 'verify',
            'verified_working_days' => 1,
            'certifier_position' => 'Human Resource Officer',
            'certifier_signature' => 'HRMO Officer',
        ])->assertSessionHasErrors('verified_working_days');

        $this->actingAs($hrmo)->post(route('leave.review', $application), [
            'action' => 'return',
            'remarks' => 'Please correct the dates to match the verified workdays.',
        ])->assertRedirect();
        $this->assertSame('Returned for Correction', $application->fresh()->status);

        $this->actingAs($employeeUser)->put(route('leave.update', $application), [
            'intent' => 'submit',
            'leave_type_id' => $application->leave_type_id,
            'start_date' => today()->addDays(2)->toDateString(),
            'end_date' => today()->addDays(2)->toDateString(),
            'commutation_requested' => '0',
        ])->assertRedirect();

        $application->refresh();
        $this->assertSame('Under Alternate Office Head Review', $application->status);
        $this->assertNull($application->alternate_decision_by);
        $this->assertNull($application->alternate_decision_action);
        $this->assertSame(1, $application->working_days);
    }

    public function test_only_hrmo_and_administrator_can_manage_office_head_availability(): void
    {
        $this->actingAs(User::where('role', 'office_head')->firstOrFail())
            ->get(route('office-head-availability.index'))
            ->assertForbidden();

        $this->actingAs(User::where('role', 'hrmo')->firstOrFail())
            ->get(route('office-head-availability.index'))
            ->assertOk()
            ->assertSee('Office Head availability');
    }

    public function test_availability_period_is_audited_and_overlapping_periods_are_rejected(): void
    {
        $department = Department::where('code', 'TEST')->firstOrFail();
        $data = [
            'department_id' => $department->id,
            'availability_status' => 'on_leave',
            'start_date' => today()->addDays(5)->toDateString(),
            'end_date' => today()->addDays(7)->toDateString(),
            'reason' => 'Authorized test availability period',
        ];

        $this->actingAs(User::where('role', 'hrmo')->firstOrFail())
            ->post(route('office-head-availability.store'), $data)
            ->assertRedirect();

        $this->assertDatabaseHas('office_head_availabilities', [
            'department_id' => $department->id,
            'office_head_user_id' => $department->head_user_id,
            'availability_status' => 'on_leave',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'office_head.availability_recorded',
        ]);

        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->post(route('office-head-availability.store'), $data)
            ->assertSessionHasErrors('start_date');

        $this->assertDatabaseCount('office_head_availabilities', 1);
    }

    public function test_office_head_hrmo_and_mayor_complete_approval_and_credit_deduction(): void
    {
        $employee = Employee::where('employee_no', 'TEST-0001')->firstOrFail();
        $vacation = LeaveType::where('code', 'VACATION')->firstOrFail();
        $mayor = User::where('role', 'admin')->firstOrFail();
        $mayor->update(['role' => 'mayor', 'can_final_approve' => false]);
        $application = LeaveApplication::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $vacation->id,
            'status' => 'Under Office Head Review',
            'office_snapshot' => $employee->department->name,
            'position_snapshot' => $employee->position->title,
            'salary_snapshot' => $employee->monthly_salary,
            'last_name_snapshot' => $employee->last_name,
            'first_name_snapshot' => $employee->first_name,
            'middle_name_snapshot' => $employee->middle_name,
            'filed_at' => today(),
            'working_days' => 2,
            'start_date' => '2026-12-01',
            'end_date' => '2026-12-02',
            'commutation_requested' => false,
        ]);

        $this->actingAs(User::where('role', 'office_head')->firstOrFail())
            ->post(route('leave.review', $application), [
                'action' => 'endorse',
                'recommendation' => 'For approval',
            ])->assertRedirect();
        $this->assertDatabaseHas('leave_applications', ['id' => $application->id, 'status' => 'Under HRMO Verification']);

        $this->actingAs(User::where('role', 'hrmo')->firstOrFail())
            ->post(route('leave.review', $application), [
                'action' => 'verify',
                'verified_working_days' => 2,
                'certifier_position' => 'Human Resource Officer',
                'certifier_signature' => 'HRMO Officer',
                'remarks' => 'Verified against leave record.',
            ])->assertRedirect();
        $this->assertDatabaseHas('leave_applications', [
            'id' => $application->id,
            'status' => 'Pending Final Approval',
            'certifier_position' => 'Human Resource Officer',
            'certifier_signature' => 'HRMO Officer',
        ]);

        $this->actingAs($mayor)
            ->post(route('leave.review', $application), [
                'action' => 'approve',
                'days_with_pay' => 2,
                'days_without_pay' => 0,
            ])->assertRedirect();

        $this->assertDatabaseHas('leave_applications', ['id' => $application->id, 'status' => 'Approved']);
        $credit = LeaveCredit::where('employee_id', $employee->id)->where('leave_type_id', $vacation->id)->firstOrFail();
        $this->assertSame(10.0, $credit->balance);
        $this->assertDatabaseHas('leave_credit_transactions', [
            'leave_application_id' => $application->id,
            'type' => 'used',
            'amount' => 2,
        ]);
    }

    public function test_office_head_cannot_download_sensitive_leave_documents(): void
    {
        Storage::fake('local');
        $employee = Employee::where('employee_no', 'TEST-0001')->firstOrFail();
        $type = LeaveType::where('code', 'VAWC')->firstOrFail();
        $application = LeaveApplication::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'status' => 'Under Office Head Review',
            'office_snapshot' => $employee->department->name,
            'last_name_snapshot' => $employee->last_name,
            'first_name_snapshot' => $employee->first_name,
            'start_date' => '2026-12-01',
            'end_date' => '2026-12-01',
            'working_days' => 1,
        ]);
        Storage::disk('local')->put('leave-documents/'.$application->id.'/proof.pdf', 'private');
        $document = LeaveApplicationDocument::create([
            'leave_application_id' => $application->id,
            'uploaded_by' => $employee->user_id,
            'original_name' => 'proof.pdf',
            'stored_path' => 'leave-documents/'.$application->id.'/proof.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 7,
            'is_sensitive' => true,
        ]);

        $this->actingAs(User::where('role', 'office_head')->firstOrFail())
            ->get(route('leave.documents', [$application, $document]))
            ->assertForbidden();
    }

    public function test_biometric_csv_import_reports_duplicates_without_overwriting_records(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $csv = UploadedFile::fake()->createWithContent(
            'daily.csv',
            "biometric_id,date,time_in,time_out\nTEST-BIO-0001,2026-11-02,08:00,17:00\nTEST-BIO-0001,2026-11-02,08:01,17:01\n"
        );

        $this->actingAs($admin)->post(route('attendance.import'), ['file' => $csv])->assertRedirect();

        $this->assertSame(1, AttendanceRecord::whereDate('work_date', '2026-11-02')->where('source', 'biometric_import')->count());
        $this->assertDatabaseHas('biometric_imports', [
            'row_count' => 2,
            'imported_count' => 1,
            'error_count' => 1,
        ]);
        $this->assertSame(1, AttendanceRecord::whereDate('work_date', '2026-11-02')->count());
    }

    public function test_biometric_import_calculates_overnight_hours_and_rejects_missing_times(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $csv = UploadedFile::fake()->createWithContent(
            'overnight.csv',
            "biometric_id,date,time_in,time_out\nTEST-BIO-0001,2026-11-03,22:00,06:00\nTEST-BIO-0001,2026-11-04,,06:00\nTEST-BIO-0001,2026-11-05,22:00,\n"
        );

        $this->actingAs($admin)->post(route('attendance.import'), ['file' => $csv])->assertRedirect();

        $overnight = AttendanceRecord::whereDate('work_date', '2026-11-03')->firstOrFail();
        $this->assertSame(8.0, (float) $overnight->total_working_hours);
        $this->assertSame(2, BiometricImportRecord::whereHas('import', fn ($query) => $query->where('original_name', 'overnight.csv'))->where('status', 'error')->count());
        $this->assertSame(1, BiometricImport::where('original_name', 'overnight.csv')->value('imported_count'));
    }

    public function test_attendance_correction_recalculates_overnight_work_hours(): void
    {
        $employee = Employee::where('employee_no', 'TEST-0001')->firstOrFail();
        $record = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'work_date' => '2026-11-06',
            'time_in' => '22:00:00',
            'time_out' => '23:00:00',
            'total_working_hours' => 1,
            'status' => 'Present',
            'source' => 'manual',
        ]);

        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->post(route('attendance.correct', $record), [
                'status' => 'Present',
                'time_in' => '22:00',
                'time_out' => '06:00',
                'late_minutes' => 0,
                'undertime_minutes' => 0,
                'correction_remarks' => 'Verified overnight shift.',
            ])
            ->assertRedirect();

        $this->assertSame(8.0, (float) $record->fresh()->total_working_hours);
    }

    public function test_leave_credit_creation_rejects_duplicate_employee_and_type_pair(): void
    {
        $employee = Employee::where('employee_no', 'TEST-0001')->firstOrFail();
        $vacation = LeaveType::where('code', 'VACATION')->firstOrFail();
        $this->actingAs(User::where('role', 'hrmo')->firstOrFail())
            ->post(route('leave-credits.store'), [
                'employee_id' => $employee->id,
                'leave_type_id' => $vacation->id,
                'beginning_balance' => 5,
                'effective_date' => today()->toDateString(),
                'remarks' => 'Duplicate prevention test.',
            ])
            ->assertSessionHasErrors('leave_type_id');

        $this->assertSame(1, LeaveCredit::where('employee_id', $employee->id)
            ->where('leave_type_id', $vacation->id)->count());
    }

    public function test_dashboards_are_tailored_to_each_role(): void
    {
        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('role', 'mayor')
            ->assertViewHas('pageTitle', 'Mayor’s executive overview')
            ->assertSee('data-role-theme="mayor"', false)
            ->assertSee('Escalated requests')
            ->assertSee('Department staffing');

        $this->actingAs(User::where('role', 'hrmo')->firstOrFail())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('pageTitle', 'HR operations')
            ->assertSee('data-role-theme="hrmo"', false)
            ->assertSee('User management · view only')
            ->assertSee('Audit logs · view only')
            ->assertSee('Pending HR actions');

        $this->actingAs(User::where('role', 'office_head')->firstOrFail())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('pageTitle', 'Department overview')
            ->assertSee('data-role-theme="office_head"', false)
            ->assertSee('My employees')
            ->assertDontSee('Employee import')
            ->assertSee('Leave applications for your review');

        $this->actingAs(User::where('role', 'employee')->firstOrFail())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('pageTitle', 'My workspace')
            ->assertSee('data-role-theme="employee"', false)
            ->assertSee('File leave application')
            ->assertSee('My leave applications')
            ->assertDontSee('Audit logs');
    }

    public function test_hrmo_user_and_audit_sections_are_read_only_and_system_settings_remain_restricted(): void
    {
        $hrmo = User::where('role', 'hrmo')->firstOrFail();

        $this->actingAs($hrmo)
            ->get(route('users.index'))
            ->assertOk()
            ->assertSee('Read-only account directory')
            ->assertDontSee('Manage →')
            ->assertDontSee(route('users.create'));

        $this->actingAs($hrmo)->get(route('audit.index'))->assertOk();
        $this->actingAs($hrmo)->get(route('users.create'))->assertForbidden();
        $this->actingAs($hrmo)->get(route('settings.system'))->assertForbidden();
        $this->actingAs($hrmo)->put(route('users.update', User::where('role', 'office_head')->firstOrFail()), [])
            ->assertForbidden();
    }

    public function test_employee_leave_credit_page_only_contains_the_signed_in_employees_balances(): void
    {
        $employee = Employee::where('employee_no', 'TEST-0001')->firstOrFail();
        $otherEmployee = Employee::create([
            'department_id' => $employee->department_id,
            'position_id' => $employee->position_id,
            'employee_no' => 'TEST-0002',
            'biometric_id' => 'TEST-BIO-0002',
            'first_name' => 'Other',
            'last_name' => 'Person',
            'employment_status' => 'Active',
            'employment_type' => 'Permanent',
            'date_hired' => '2021-01-04',
        ]);
        LeaveCredit::create([
            'employee_id' => $otherEmployee->id,
            'leave_type_id' => LeaveType::where('code', 'VACATION')->value('id'),
            'beginning_balance' => 5,
            'effective_date' => today(),
        ]);

        $this->actingAs($employee->user)
            ->get(route('leave-credits.index'))
            ->assertOk()
            ->assertViewHas('credits', fn ($credits) => $credits->total() === 1)
            ->assertSee('My leave credits')
            ->assertDontSee('Create a credit account')
            ->assertDontSee('Record transaction');
    }

    public function test_office_head_without_a_department_cannot_see_organization_wide_dashboard_data(): void
    {
        $unassignedHead = User::create([
            'name' => 'Unassigned Office Head',
            'email' => 'unassigned-head@example.test',
            'password' => 'test-password',
            'role' => 'office_head',
            'is_active' => true,
        ]);

        $this->actingAs($unassignedHead)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('employeeCount', 0)
            ->assertViewHas('departmentCount', 0)
            ->assertViewHas('pendingTasks', fn ($tasks) => $tasks->isEmpty())
            ->assertDontSee('Test Person');
    }

    public function test_employee_directory_displays_employment_details_and_uses_allowlisted_sorting(): void
    {
        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->get(route('employees.index', [
                'sort' => 'employment_type',
                'direction' => 'desc',
            ]))
            ->assertOk()
            ->assertSee('EMPLOYMENT TYPE')
            ->assertSee('DATE HIRED')
            ->assertSee('Permanent');
    }

    public function test_users_and_employee_directory_display_database_records(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('users.index'))
            ->assertOk()
            ->assertSee($admin->name)
            ->assertSee('Test Person');

        $this->actingAs($admin)
            ->get(route('employees.index'))
            ->assertOk()
            ->assertSee('Test Person')
            ->assertSee('TEST-0001');
    }

    public function test_employee_account_can_be_linked_without_overwriting_existing_records(): void
    {
        $employee = Employee::create([
            'department_id' => Department::where('code', 'TEST')->value('id'),
            'employee_no' => 'TEST-0002',
            'first_name' => 'Unlinked',
            'last_name' => 'Record',
            'employment_status' => 'Active',
        ]);

        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->post(route('users.store'), [
                'name' => 'Linked TEST Account',
                'email' => 'linked-test@example.test',
                'role' => 'employee',
                'employee_id' => $employee->id,
                'password' => 'TestAccount123!',
                'password_confirmation' => 'TestAccount123!',
            ])
            ->assertRedirect(route('users.index'));

        $user = User::where('email', 'linked-test@example.test')->firstOrFail();
        $this->assertSame($user->id, $employee->fresh()->user_id);
        $this->assertSame($employee->id, $user->employee->id);
        $this->assertSame('Test', Employee::where('employee_no', 'TEST-0001')->value('first_name'));
    }

    public function test_administrator_can_create_named_office_head_and_assign_a_department(): void
    {
        $department = Department::create(['name' => 'Configured Office', 'code' => 'CFG']);
        $admin = User::where('role', 'admin')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('users.create'))
            ->assertOk()
            ->assertSee('First name')
            ->assertSee('Assigned office / department');

        $this->actingAs($admin)
            ->post(route('users.store'), [
                'first_name' => 'Actual',
                'middle_name' => 'Municipal',
                'last_name' => 'Office Head',
                'suffix' => 'Jr.',
                'name' => 'Actual Municipal Office Head Jr.',
                'email' => 'office-head-created@example.test',
                'role' => 'office_head',
                'department_id' => $department->id,
                'is_active' => '1',
                'password' => 'SecureInitialPassword123!',
                'password_confirmation' => 'SecureInitialPassword123!',
            ])
            ->assertRedirect(route('users.index'));

        $officeHead = User::where('email', 'office-head-created@example.test')->firstOrFail();
        $this->assertSame('Actual Municipal Office Head Jr.', $officeHead->name);
        $this->assertSame('office_head', $officeHead->role);
        $this->assertSame('Actual', $officeHead->first_name);
        $this->assertSame($officeHead->id, $department->fresh()->head_user_id);
    }

    public function test_archived_and_already_linked_employee_records_cannot_be_linked_again(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $linkedEmployee = Employee::where('employee_no', 'TEST-0001')->firstOrFail();
        $archivedEmployee = Employee::create([
            'department_id' => Department::where('code', 'TEST')->value('id'),
            'employee_no' => 'TEST-0004',
            'first_name' => 'Archived',
            'last_name' => 'Record',
            'employment_status' => 'Active',
            'archived_at' => today(),
        ]);

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Duplicate Link Account',
            'email' => 'duplicate-link@example.test',
            'role' => 'employee',
            'employee_id' => $linkedEmployee->id,
            'password' => 'TestAccount123!',
            'password_confirmation' => 'TestAccount123!',
        ])->assertSessionHasErrors('employee_id');

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Archived Link Account',
            'email' => 'archived-link@example.test',
            'role' => 'employee',
            'employee_id' => $archivedEmployee->id,
            'password' => 'TestAccount123!',
            'password_confirmation' => 'TestAccount123!',
        ])->assertSessionHasErrors('employee_id');

        $this->assertDatabaseMissing('users', ['email' => 'duplicate-link@example.test']);
        $this->assertDatabaseMissing('users', ['email' => 'archived-link@example.test']);
        $this->assertSame($linkedEmployee->user_id, $linkedEmployee->fresh()->user_id);
    }

    public function test_office_head_directory_is_limited_to_their_department(): void
    {
        $otherHead = User::create([
            'name' => 'TEST Other Office Head',
            'email' => 'test-other-head@example.test',
            'password' => 'TestAccount123!',
            'role' => 'office_head',
            'is_active' => true,
        ]);
        $otherDepartment = Department::create([
            'name' => 'TEST Other Department',
            'code' => 'TEST-OTHER',
            'head_user_id' => $otherHead->id,
        ]);
        Employee::create([
            'department_id' => $otherDepartment->id,
            'employee_no' => 'TEST-0003',
            'first_name' => 'Other',
            'last_name' => 'Person',
            'employment_status' => 'Active',
        ]);

        $this->actingAs(User::where('role', 'office_head')->firstOrFail())
            ->get(route('employees.index'))
            ->assertOk()
            ->assertSee('Test Person')
            ->assertDontSee('Other Person');
    }

    public function test_reseeding_does_not_create_or_overwrite_accounts_or_employee_records(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $admin->update([
            'name' => 'Configured Administrator',
            'password' => 'PreservedPassword123!',
        ]);
        $employeeCount = Employee::count();
        $userCount = User::count();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame('Configured Administrator', $admin->fresh()->name);
        $this->assertTrue(Hash::check('PreservedPassword123!', $admin->fresh()->password));
        $this->assertSame($employeeCount, Employee::count());
        $this->assertSame($userCount, User::count());
    }

    public function test_role_account_passwords_remain_the_values_set_at_creation(): void
    {
        foreach (['admin', 'hrmo', 'office_head'] as $role) {
            $user = User::where('role', $role)->firstOrFail();
            $this->assertTrue(Hash::check('TestAccount123!', $user->password));
        }
    }

    public function test_official_seal_is_used_on_public_and_authenticated_pages_and_report_exports(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('image/hrislogo.webp')
            ->assertSee('Human Resource Management Office');

        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('image/hrislogo.webp')
            ->assertSee('Municipality of Camalaniugan');

        $response = $this->get(route('reports.csv', ['type' => 'attendance']));
        $response->assertOk();
        $export = $response->streamedContent();
        $this->assertStringContainsString('Municipality of Camalaniugan', $export);
        $this->assertStringContainsString('Human Resource Management Office', $export);
    }

    public function test_inactive_accounts_cannot_access_authenticated_pages(): void
    {
        $user = User::where('role', 'admin')->firstOrFail();
        $user->update(['is_active' => false]);

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('login'));
    }
}
