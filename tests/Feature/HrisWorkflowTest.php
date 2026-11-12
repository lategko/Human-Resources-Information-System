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
use App\Models\WorkSchedule;
use App\Services\AttendanceWorkHoursCalculator;
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

    private function nextTwoWorkingDays(): array
    {
        $calculator = app(\App\Services\WorkingDayCalculator::class);
        $date = today()->addDay();
        $lastDate = today()->addYear();
        $workingDates = [];

        while ($date->lte($lastDate) && count($workingDates) < 2) {
            $dateString = $date->toDateString();
            if ($calculator->count($dateString, $dateString) === 1) {
                $workingDates[] = $dateString;
            }
            $date->addDay();
        }

        $this->assertCount(2, $workingDates, 'The configured calendar must provide two working days within the next year.');

        return $workingDates;
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

    public function test_leave_uploads_require_a_configured_document_type(): void
    {
        Storage::fake('local');
        $employee = Employee::where('employee_no', 'TEST-0001')->firstOrFail();
        $sensitiveType = LeaveType::where('code', 'VAWC')->firstOrFail();
        $payload = [
            'intent' => 'submit',
            'leave_type_id' => $sensitiveType->id,
            'start_date' => '2026-12-01',
            'end_date' => '2026-12-01',
            'commutation_requested' => '0',
            'documents' => [UploadedFile::fake()->create('proof.pdf', 100, 'application/pdf')],
        ];

        $this->actingAs($employee->user)
            ->post(route('leave.store'), $payload)
            ->assertSessionHasErrors('document_types');
        $this->assertDatabaseMissing('leave_applications', ['employee_id' => $employee->id]);

        $singleFilePayload = $payload;
        $singleFilePayload['documents'] = UploadedFile::fake()->create('single.pdf', 100, 'application/pdf');
        $this->post(route('leave.store'), $singleFilePayload)
            ->assertSessionHasErrors(['documents', 'document_types']);
        $this->assertDatabaseMissing('leave_applications', ['employee_id' => $employee->id]);

        $payload['document_types'] = ['Protection order or appropriate barangay/court certification'];
        $this->post(route('leave.store'), $payload)->assertRedirect();
        $application = LeaveApplication::latest('id')->firstOrFail();
        $document = $application->documents()->firstOrFail();
        $this->assertSame('Protection order or appropriate barangay/court certification', $document->document_type);
        Storage::disk('local')->assertExists($document->stored_path);
    }

    public function test_leave_document_failure_rolls_back_application_and_removes_stored_file(): void
    {
        Storage::fake('local');
        $employee = Employee::where('employee_no', 'TEST-0001')->firstOrFail();
        $type = LeaveType::where('code', 'VAWC')->firstOrFail();
        $storedType = $type->required_documents[0];
        LeaveApplicationDocument::creating(static function (): void {
            throw new \RuntimeException('Simulated document metadata failure.');
        });

        $this->actingAs($employee->user)->post(route('leave.store'), [
                'intent' => 'submit',
                'leave_type_id' => $type->id,
                'start_date' => '2026-12-10',
                'end_date' => '2026-12-10',
                'commutation_requested' => '0',
                'documents' => [UploadedFile::fake()->create('proof.pdf', 100, 'application/pdf')],
                'document_types' => [$storedType],
            ])->assertServerError();

        $this->assertDatabaseMissing('leave_applications', ['employee_id' => $employee->id]);
        $this->assertDatabaseCount('leave_approval_histories', 0);
        $this->assertDatabaseCount('leave_application_documents', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('leave-documents'));
    }

    public function test_employee_cannot_submit_overlapping_active_leave_applications(): void
    {
        [$startDate, $endDate] = $this->nextTwoWorkingDays();
        $employee = Employee::where('employee_no', 'TEST-0001')->firstOrFail();
        $payload = [
            'intent' => 'submit',
            'leave_type_id' => LeaveType::where('code', 'VACATION')->value('id'),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'commutation_requested' => '0',
        ];

        $this->actingAs($employee->user)->post(route('leave.store'), $payload)->assertRedirect();
        $this->post(route('leave.store'), $payload)->assertStatus(422);
        $this->assertDatabaseCount('leave_applications', 1);
    }

    public function test_final_approval_cannot_use_more_credits_than_available(): void
    {
        $employee = Employee::where('employee_no', 'TEST-0001')->firstOrFail();
        $vacation = LeaveType::where('code', 'VACATION')->firstOrFail();
        $credit = LeaveCredit::where('employee_id', $employee->id)
            ->where('leave_type_id', $vacation->id)
            ->firstOrFail();
        $credit->update(['used_credits' => (float) $credit->beginning_balance + (float) $credit->earned_credits]);
        $application = LeaveApplication::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $vacation->id,
            'status' => 'Pending Final Approval',
            'office_snapshot' => $employee->department->name,
            'last_name_snapshot' => $employee->last_name,
            'first_name_snapshot' => $employee->first_name,
            'working_days' => 1,
            'verified_working_days' => 1,
            'start_date' => '2026-12-15',
            'end_date' => '2026-12-15',
        ]);
        $approver = User::where('role', 'admin')->firstOrFail();

        $this->actingAs($approver)->post(route('leave.review', $application), [
            'action' => 'approve',
            'days_with_pay' => 1,
            'days_without_pay' => 0,
        ])->assertSessionHasErrors('days_with_pay');

        $this->assertSame('Pending Final Approval', $application->fresh()->status);
        $this->assertSame(0.0, $credit->fresh()->balance);
        $this->assertDatabaseMissing('leave_credit_transactions', ['leave_application_id' => $application->id]);
    }

    public function test_distinct_mayor_can_review_only_authorized_leave_records_without_sensitive_details(): void
    {
        $mayor = User::create([
            'name' => 'Test Mayor',
            'email' => 'mayor@example.test',
            'password' => 'TestAccount123!',
            'role' => 'mayor',
            'is_active' => true,
        ]);
        $employee = Employee::where('employee_no', 'TEST-0001')->firstOrFail();
        $sensitiveType = LeaveType::where('code', 'VAWC')->firstOrFail();
        $pending = LeaveApplication::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $sensitiveType->id,
            'status' => 'Pending Final Approval',
            'office_snapshot' => $employee->department->name,
            'last_name_snapshot' => $employee->last_name,
            'first_name_snapshot' => $employee->first_name,
            'leave_detail' => 'Sensitive medical detail',
            'working_days' => 1,
            'start_date' => today()->addDays(5),
            'end_date' => today()->addDays(5),
        ]);
        $otherMayor = User::create([
            'name' => 'Other Mayor',
            'email' => 'other-mayor@example.test',
            'password' => 'TestAccount123!',
            'role' => 'mayor',
            'is_active' => true,
        ]);
        $assignedAlternate = LeaveApplication::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $sensitiveType->id,
            'status' => 'Under Alternate Office Head Review',
            'office_head_reviewer_id' => $mayor->id,
            'office_snapshot' => $employee->department->name,
            'last_name_snapshot' => $employee->last_name,
            'first_name_snapshot' => $employee->first_name,
            'working_days' => 1,
            'start_date' => today()->addDays(6),
            'end_date' => today()->addDays(6),
        ]);
        $otherMayorRequest = LeaveApplication::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $sensitiveType->id,
            'status' => 'Under Alternate Office Head Review',
            'office_head_reviewer_id' => $otherMayor->id,
            'office_snapshot' => $employee->department->name,
            'last_name_snapshot' => $employee->last_name,
            'first_name_snapshot' => $employee->first_name,
            'working_days' => 1,
            'start_date' => today()->addDays(7),
            'end_date' => today()->addDays(7),
        ]);
        $outsideScopeEmployee = Employee::create([
            'department_id' => $employee->department_id,
            'employee_no' => 'TEST-0002',
            'first_name' => 'Outside',
            'last_name' => 'Scope',
        ]);
        $outsideScope = LeaveApplication::create([
            'employee_id' => $outsideScopeEmployee->id,
            'leave_type_id' => $sensitiveType->id,
            'status' => 'Under HRMO Verification',
            'office_snapshot' => $outsideScopeEmployee->department->name,
            'last_name_snapshot' => $outsideScopeEmployee->last_name,
            'first_name_snapshot' => $outsideScopeEmployee->first_name,
            'working_days' => 1,
            'start_date' => today()->addDays(5),
            'end_date' => today()->addDays(5),
        ]);

        $this->actingAs($mayor)
            ->get(route('leave.index'))
            ->assertOk()
            ->assertSee($employee->full_name)
            ->assertDontSee($outsideScopeEmployee->full_name)
            ->assertDontSee('Alternate Office Head decision');

        $this->get(route('leave.show', $pending))
            ->assertOk()
            ->assertSee('Final decision')
            ->assertSee('Restricted details')
            ->assertDontSee('Sensitive medical detail');
        $this->get(route('leave.print', $pending))
            ->assertOk()
            ->assertDontSee('Sensitive medical detail');
        $this->get(route('leave.show', $assignedAlternate))
            ->assertOk()
            ->assertSee('Alternate Office Head decision');
        $this->get(route('leave.show', $otherMayorRequest))->assertForbidden();
        $this->get(route('leave.show', $outsideScope))->assertForbidden();

        $this->post(route('leave.review', $pending), [
            'action' => 'approve',
            'days_with_pay' => 1,
            'days_without_pay' => 0,
        ])->assertRedirect();
        $this->assertSame('Approved', $pending->fresh()->status);
    }

    public function test_unavailable_office_head_uses_authorized_mayor_fallback_then_hrmo_finalizes_without_duplicate_decision(): void
    {
        [$startDate, $endDate] = $this->nextTwoWorkingDays();
        $department = Department::where('code', 'TEST')->firstOrFail();
        $officeHead = User::where('role', 'office_head')->firstOrFail();
        $mayor = User::where('role', 'admin')->firstOrFail();
        $mayor->update(['role' => 'mayor', 'can_final_approve' => false]);
        OfficeHeadAvailability::create([
            'department_id' => $department->id,
            'office_head_user_id' => $officeHead->id,
            'availability_status' => 'on_leave',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'reason' => 'Authorized test availability period',
            'updated_by' => User::where('role', 'hrmo')->value('id'),
        ]);

        $employeeUser = Employee::where('employee_no', 'TEST-0001')->firstOrFail()->user;
        $this->actingAs($employeeUser)->post(route('leave.store'), [
            'intent' => 'submit',
            'leave_type_id' => LeaveType::where('code', 'VACATION')->value('id'),
            'start_date' => $startDate,
            'end_date' => $endDate,
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
                'waived_documents' => ['Travel destination, if applicable'],
                'waiver_justification' => 'The employee is not travelling abroad for this leave.',
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
        [$startDate, $endDate] = $this->nextTwoWorkingDays();
        $department = Department::where('code', 'TEST')->firstOrFail();
        $officeHead = User::where('role', 'office_head')->firstOrFail();
        OfficeHeadAvailability::create([
            'department_id' => $department->id,
            'office_head_user_id' => $officeHead->id,
            'availability_status' => 'on_leave',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'reason' => 'Authorized test availability period',
            'updated_by' => User::where('role', 'hrmo')->value('id'),
        ]);
        $employeeUser = Employee::where('employee_no', 'TEST-0001')->firstOrFail()->user;

        $this->actingAs($employeeUser)->post(route('leave.store'), [
            'intent' => 'submit',
            'leave_type_id' => LeaveType::where('code', 'VACATION')->value('id'),
            'start_date' => $startDate,
            'end_date' => $endDate,
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
                'waived_documents' => ['Travel destination, if applicable'],
                'waiver_justification' => 'The employee is not travelling abroad for this leave.',
        ])->assertSessionHasErrors('verified_working_days');

        $this->actingAs($hrmo)->post(route('leave.review', $application), [
            'action' => 'return',
            'remarks' => 'Please correct the dates to match the verified workdays.',
        ])->assertRedirect();
        $this->assertSame('Returned for Correction', $application->fresh()->status);

        $this->actingAs($employeeUser)->put(route('leave.update', $application), [
            'intent' => 'submit',
            'leave_type_id' => $application->leave_type_id,
            'start_date' => $startDate,
            'end_date' => $startDate,
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
                'waived_documents' => ['Travel destination, if applicable'],
                'waiver_justification' => 'The employee is not travelling abroad for this leave.',
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

    public function test_hrmo_verification_requires_every_document_to_be_attached_or_validly_waived(): void
    {
        Storage::fake('local');
        $employee = Employee::where('employee_no', 'TEST-0001')->firstOrFail();
        $type = LeaveType::where('code', 'VAWC')->firstOrFail();
        $requiredDocument = $type->required_documents[0];
        $makeApplication = fn (string $employeeNo) => LeaveApplication::create([
            'employee_id' => Employee::where('employee_no', $employeeNo)->value('id'),
            'leave_type_id' => $type->id,
            'status' => 'Under HRMO Verification',
            'office_snapshot' => $employee->department->name,
            'last_name_snapshot' => $employee->last_name,
            'first_name_snapshot' => $employee->first_name,
            'working_days' => 1,
            'verified_working_days' => null,
            'start_date' => today()->addDays(10),
            'end_date' => today()->addDays(10),
        ]);
        $hrmo = User::where('role', 'hrmo')->firstOrFail();
        $verification = [
            'action' => 'verify',
            'verified_working_days' => 1,
            'certifier_position' => 'Human Resource Officer',
            'certifier_signature' => 'HRMO Officer',
        ];
        $waiverApplication = $makeApplication('TEST-0001');

        $this->actingAs($hrmo)->post(route('leave.review', $waiverApplication), $verification)
            ->assertSessionHasErrors('waived_documents');
        $this->post(route('leave.review', $waiverApplication), $verification + [
            'waived_documents' => ['Unconfigured document'],
            'waiver_justification' => 'This item is not in the approved checklist.',
        ])->assertSessionHasErrors('waived_documents.0');
        $this->post(route('leave.review', $waiverApplication), $verification + [
            'waived_documents' => [$requiredDocument],
            'waiver_justification' => 'Approved policy exception recorded for verification.',
        ])->assertRedirect();
        $this->assertSame('Pending Final Approval', $waiverApplication->fresh()->status);
        $this->assertSame([$requiredDocument], $waiverApplication->fresh()->document_waivers['documents']);

        $attachedApplication = $makeApplication('TEST-0001');
        $storedPath = Storage::disk('local')->put('leave-documents/'.$attachedApplication->id.'/proof.pdf', 'private');
        LeaveApplicationDocument::create([
            'leave_application_id' => $attachedApplication->id,
            'uploaded_by' => $employee->user_id,
            'document_type' => $requiredDocument,
            'original_name' => 'proof.pdf',
            'stored_path' => $storedPath,
            'mime_type' => 'application/pdf',
            'size_bytes' => 7,
            'is_sensitive' => true,
        ]);

        $this->post(route('leave.review', $attachedApplication), $verification)->assertRedirect();
        $this->assertSame('Pending Final Approval', $attachedApplication->fresh()->status);
        $this->assertNull($attachedApplication->fresh()->document_waivers);
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

    public function test_attendance_calculator_applies_lunch_and_reports_late_undertime_and_unapproved_extra_time(): void
    {
        $calculator = new AttendanceWorkHoursCalculator;
        $schedule = [
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'lunch_start' => '12:00:00',
            'lunch_end' => '13:00:00',
            'grace_period_minutes' => 0,
        ];

        $normal = $calculator->calculateMetrics('08:00:00', '17:00:00', $schedule, 'regular_working_day');
        $this->assertSame(8.0, $normal['total_working_hours']);
        $this->assertSame(0, $normal['late_minutes']);
        $this->assertSame(0, $normal['undertime_minutes']);

        $lateAndUndertime = $calculator->calculateMetrics('08:10:00', '16:50:00', $schedule, 'regular_working_day');
        $this->assertSame(7.67, $lateAndUndertime['total_working_hours']);
        $this->assertSame(10, $lateAndUndertime['late_minutes']);
        $this->assertSame(10, $lateAndUndertime['undertime_minutes']);

        $extra = $calculator->calculateMetrics('08:00:00', '17:30:00', $schedule, 'regular_working_day');
        $this->assertSame(30, $extra['unapproved_extra_minutes']);
        $this->assertSame(8.5, $extra['total_working_hours']);
        $this->assertSame(0, $calculator->calculateMetrics('08:00:00', '17:00:00', $schedule, 'regular_holiday')['late_minutes']);
        $outsideSchedule = $calculator->calculateMetrics('22:00:00', '06:00:00', $schedule, 'regular_working_day');
        $this->assertNotNull($outsideSchedule['calculation_warning']);
        $this->assertSame(0, $outsideSchedule['unapproved_extra_minutes']);
        $this->assertNotNull($calculator->calculateMetrics(null, '17:00:00', $schedule, 'regular_working_day')['calculation_warning']);
    }

    public function test_hrmo_can_assign_effective_office_and_employee_schedules_without_overlapping_periods(): void
    {
        $employee = Employee::where('employee_no', 'TEST-0001')->firstOrFail();
        $hrmo = User::where('role', 'hrmo')->firstOrFail();
        $effectiveFrom = today()->toDateString();
        $officeSchedule = [
            'working_days' => [1, 2, 3, 4, 5],
            'start_time' => '08:00',
            'end_time' => '17:00',
            'lunch_start' => '12:00',
            'lunch_end' => '13:00',
            'grace_period_minutes' => 0,
            'effective_from' => $effectiveFrom,
            'reason' => 'Approved municipal office schedule.',
        ];

        $this->actingAs($hrmo)->get(route('attendance.index'))
            ->assertOk()
            ->assertSee('Work schedules')
            ->assertSee('Default schedule');

        $this->post(route('attendance.schedules.store'), $officeSchedule)
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $officeRecord = WorkSchedule::query()->whereNull('employee_id')->firstOrFail();

        $this->post(route('attendance.schedules.store'), array_merge($officeSchedule, [
            'employee_id' => $employee->id,
            'start_time' => '09:00',
            'end_time' => '18:00',
            'reason' => 'Approved employee-specific schedule.',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $resolved = WorkSchedule::resolveFor($employee, $effectiveFrom);
        $this->assertSame('09:00:00', $resolved['schedule']['start_time']);
        $this->assertSame('regular_working_day', $resolved['calendar_classification']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'attendance.schedule_assigned']);
        $this->get(route('attendance.index'))
            ->assertOk()
            ->assertSee($employee->employee_no)
            ->assertSee('Approved employee-specific schedule')
            ->assertSee('09:00–18:00');

        $this->post(route('attendance.schedules.store'), array_merge($officeSchedule, [
            'employee_id' => $employee->id,
            'reason' => 'Overlapping schedule must fail.',
        ]))->assertSessionHasErrors('effective_from');

        $this->post(route('attendance.schedules.store'), array_merge($officeSchedule, [
            'effective_from' => today()->subDay()->toDateString(),
            'reason' => 'Past-dated schedule must fail.',
        ]))->assertSessionHasErrors('effective_from');
        $this->post(route('attendance.schedules.store'), array_merge($officeSchedule, [
            'lunch_start' => '07:00',
            'lunch_end' => '07:30',
            'reason' => 'Lunch outside the shift must fail.',
        ]))->assertSessionHasErrors('lunch_start');
        $this->assertSame(2, WorkSchedule::count());

        $this->actingAs($employee->user)->post(route('attendance.schedules.store'), $officeSchedule)
            ->assertForbidden();
        $this->actingAs(User::where('role', 'office_head')->firstOrFail())
            ->post(route('attendance.schedules.store'), $officeSchedule)
            ->assertForbidden();

        $this->actingAs($hrmo)->patch(route('attendance.schedules.end', $officeRecord), [
            'effective_to' => $effectiveFrom,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($effectiveFrom, $officeRecord->fresh()->effective_to->toDateString());
        $this->assertDatabaseHas('audit_logs', ['action' => 'attendance.schedule_ended']);
    }

    public function test_biometric_import_classifies_worked_holidays_and_rest_days_without_pay_calculations(): void
    {
        [$holidayDate, $regularDate] = $this->nextTwoWorkingDays();
        $restDate = today()->next('Sunday')->toDateString();
        $regularHolidayDate = today()->addWeeks(2)->next('Monday')->toDateString();
        Holiday::create([
            'holiday_date' => $holidayDate,
            'name' => 'Configured special non-working day',
            'holiday_type' => 'special_non_working',
        ]);
        Holiday::create([
            'holiday_date' => $regularHolidayDate,
            'name' => 'Configured regular holiday',
            'holiday_type' => 'regular',
        ]);
        $csv = UploadedFile::fake()->createWithContent(
            'calendar-attendance.csv',
            "biometric_id,date,time_in,time_out\nTEST-BIO-0001,{$holidayDate},08:00,18:00\nTEST-BIO-0001,{$regularDate},08:10,17:00\nTEST-BIO-0001,{$restDate},08:00,17:30\nTEST-BIO-0001,{$regularHolidayDate},08:00,17:00\n"
        );

        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->post(route('attendance.import'), ['file' => $csv])
            ->assertRedirect();
        $this->assertSame(4, BiometricImport::where('original_name', 'calendar-attendance.csv')->value('imported_count'));

        $holidayRecord = AttendanceRecord::whereDate('work_date', $holidayDate)->firstOrFail();
        $regularRecord = AttendanceRecord::whereDate('work_date', $regularDate)->firstOrFail();
        $restRecord = AttendanceRecord::whereDate('work_date', $restDate)->firstOrFail();
        $regularHolidayRecord = AttendanceRecord::whereDate('work_date', $regularHolidayDate)->firstOrFail();
        $this->assertSame('special_non_working_holiday', $holidayRecord->calendar_classification);
        $this->assertSame('Present', $holidayRecord->status);
        $this->assertSame(60, $holidayRecord->unapproved_extra_minutes);
        $this->assertSame('Late', $regularRecord->status);
        $this->assertSame(10, $regularRecord->late_minutes);
        $this->assertSame('rest_day', $restRecord->calendar_classification);
        $this->assertSame('Present', $restRecord->status);
        $this->assertSame('Present - Worked on Rest Day', $restRecord->display_status);
        $this->assertSame(30, $restRecord->unapproved_extra_minutes);
        $this->assertSame(0, $restRecord->late_minutes);
        $this->assertSame('regular_holiday', $regularHolidayRecord->calendar_classification);
        $this->assertSame('Present - Worked on Regular Holiday', $regularHolidayRecord->display_status);
        $this->assertSame(0, app(\App\Services\WorkingDayCalculator::class)->count($holidayDate, $holidayDate));
        $this->assertSame(
            4,
            AttendanceRecord::whereDate('work_date', '>=', $holidayDate)
                ->whereDate('work_date', '<=', $regularHolidayDate)
                ->count(),
            'Expected '.$holidayDate.', '.$regularDate.', '.$restDate.', '.$regularHolidayDate.'; stored '.collect([$holidayRecord, $regularRecord, $restRecord, $regularHolidayRecord])->map(fn ($record) => $record->work_date->toDateString())->implode(', ')
        );

        $admin = User::where('role', 'admin')->firstOrFail();
        $this->actingAs($admin)->get(route('reports.index', [
            'date_from' => $holidayDate,
            'date_to' => $regularHolidayDate,
        ]))->assertOk()
            ->assertViewHas('attendanceCount', 4)
            ->assertSee('UNAPPROVED EXTRA')
            ->assertSee('special non working holiday')
            ->assertSee('rest day');

        $csv = $this->get(route('reports.csv', [
            'type' => 'attendance',
            'date_from' => $holidayDate,
            'date_to' => $regularHolidayDate,
        ]))->assertOk();
        $this->assertStringContainsString('Unapproved extra minutes', $csv->streamedContent());
        $this->assertStringContainsString('special_non_working_holiday', $csv->streamedContent());
    }

    public function test_employee_cannot_correct_an_attendance_record(): void
    {
        $employee = Employee::where('employee_no', 'TEST-0001')->firstOrFail();
        $record = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'work_date' => today()->toDateString(),
            'time_in' => '08:00:00',
            'time_out' => '17:00:00',
            'total_working_hours' => 8,
            'status' => 'Present',
            'source' => 'manual',
        ]);

        $this->actingAs($employee->user)->post(route('attendance.correct', $record), [
            'status' => 'Absent',
            'time_in' => '',
            'time_out' => '',
            'correction_remarks' => 'Unauthorized correction attempt.',
        ])->assertForbidden();

        $this->assertSame('Present', $record->fresh()->status);
        $this->assertSame('08:00:00', $record->fresh()->time_in);
    }

    public function test_attendance_correction_uses_the_recorded_schedule_snapshot(): void
    {
        $employee = Employee::where('employee_no', 'TEST-0001')->firstOrFail();
        $scheduleSnapshot = [
            'id' => null,
            'employee_id' => null,
            'working_days' => [1, 2, 3, 4, 5],
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'lunch_start' => '12:00:00',
            'lunch_end' => '13:00:00',
            'grace_period_minutes' => 0,
        ];
        $record = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'work_date' => today()->toDateString(),
            'time_in' => '09:00:00',
            'time_out' => '18:00:00',
            'total_working_hours' => 8,
            'status' => 'Present',
            'calendar_classification' => 'regular_working_day',
            'schedule_snapshot' => $scheduleSnapshot,
            'source' => 'biometric_import',
        ]);
        WorkSchedule::create([
            'working_days' => [1, 2, 3, 4, 5],
            'start_time' => '10:00:00',
            'end_time' => '19:00:00',
            'lunch_start' => '12:00:00',
            'lunch_end' => '13:00:00',
            'grace_period_minutes' => 0,
            'effective_from' => today()->toDateString(),
            'reason' => 'Later schedule for new calculations.',
            'created_by' => User::where('role', 'hrmo')->firstOrFail()->id,
        ]);

        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->post(route('attendance.correct', $record), [
                'status' => 'Present',
                'time_in' => '09:00',
                'time_out' => '18:00',
                'correction_remarks' => 'Preserve the schedule used by the original calculation.',
            ])->assertRedirect();

        $this->assertSame(0, $record->fresh()->late_minutes);
        $this->assertSame('09:00:00', $record->fresh()->schedule_snapshot['start_time']);
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
