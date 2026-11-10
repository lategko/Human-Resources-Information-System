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
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HrisWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_employee_can_login_and_submit_a_leave_application_using_workdays(): void
    {
        $employeeUser = User::where('role', 'employee')->firstOrFail();
        Holiday::create(['holiday_date' => '2026-01-20', 'name' => 'Configured municipal holiday']);

        $this->post('/login', [
            'email' => $employeeUser->email,
            'password' => 'ChangeMe123!',
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
        $employee = Employee::where('employee_no', 'CAM-0001')->firstOrFail();
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
        $employee = Employee::where('employee_no', 'CAM-0001')->firstOrFail();
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
            "biometric_id,date,time_in,time_out\nBIO-0001,2026-11-02,08:00,17:00\nBIO-0001,2026-11-02,08:01,17:01\n"
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

    public function test_inactive_accounts_cannot_access_authenticated_pages(): void
    {
        $user = User::where('role', 'admin')->firstOrFail();
        $user->update(['is_active' => false]);

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('login'));
    }
}
