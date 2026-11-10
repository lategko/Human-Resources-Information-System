<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveApplication;
use App\Models\LeaveApplicationDocument;
use App\Models\LeaveCredit;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\User;
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

        $officeHead = User::where('role', 'office_head')->firstOrFail();
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
    }

    public function test_office_head_hrmo_and_administrator_complete_approval_and_credit_deduction(): void
    {
        $employee = Employee::where('employee_no', 'TEST-0001')->firstOrFail();
        $vacation = LeaveType::where('code', 'VACATION')->firstOrFail();
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

        $this->actingAs(User::where('role', 'admin')->firstOrFail())
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

    public function test_dashboards_are_tailored_to_each_role(): void
    {
        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('pageTitle', 'Executive overview')
            ->assertSee('Department staffing');

        $this->actingAs(User::where('role', 'hrmo')->firstOrFail())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('pageTitle', 'HR operations')
            ->assertSee('Pending HR actions');

        $this->actingAs(User::where('role', 'office_head')->firstOrFail())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('pageTitle', 'Department overview')
            ->assertSee('Leave applications for your review');

        $this->actingAs(User::where('role', 'employee')->firstOrFail())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('pageTitle', 'My workspace')
            ->assertSee('View leave credits');
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
            ->assertSee('DEMO Administrator')
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

    public function test_reseeding_demo_accounts_resets_only_their_passwords_and_does_not_create_employees(): void
    {
        $admin = User::where('email', 'hradmin@gmail.com')->firstOrFail();
        $admin->update([
            'name' => 'Configured Administrator',
            'password' => 'PreservedPassword123!',
        ]);
        $employeeCount = Employee::count();

        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $this->assertSame('Configured Administrator', $admin->fresh()->name);
        $this->assertTrue(Hash::check('password', $admin->fresh()->password));
        $this->assertSame($employeeCount, Employee::count());
    }

    public function test_demo_accounts_use_the_requested_password(): void
    {
        foreach (['admin', 'hrmo', 'office_head'] as $role) {
            $user = User::where('role', $role)->where('email', 'like', '%@gmail.com')->firstOrFail();
            $this->assertTrue(Hash::check('password', $user->password));
        }
    }

    public function test_official_seal_is_used_on_public_and_authenticated_pages_and_report_exports(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('image/hrislogo.jpg')
            ->assertSee('Human Resource Management Office');

        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('image/hrislogo.jpg')
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
