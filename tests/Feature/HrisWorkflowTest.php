<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
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
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\AttendanceWorkHoursCalculator;
use App\Services\WorkingDayCalculator;
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
            'role' => 'admin',
            'is_active' => true,
        ]);
        User::create([
            'name' => 'Test Mayor',
            'email' => 'mayor@example.test',
            'password' => 'TestAccount123!',
            'role' => 'mayor',
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
        $vacationCredit = LeaveCredit::where('employee_id', $employee->id)
            ->whereHas('leaveType', fn ($query) => $query->where('code', 'VACATION'))
            ->firstOrFail();
        $vacationCredit->update(['beginning_balance' => 12]);
        LeaveCreditTransaction::create([
            'leave_credit_id' => $vacationCredit->id,
            'type' => 'beginning_balance',
            'amount' => 12,
            'previous_balance' => 0,
            'balance_after' => 12,
            'effective_date' => today(),
            'remarks' => 'Test fixture opening balance.',
        ]);
    }

    private function nextTwoWorkingDays(): array
    {
        $calculator = app(WorkingDayCalculator::class);
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
        $this->assertSame('Under HRMO Verification', $application->status);
        $this->assertSame(4, $application->working_days);
        $this->assertSame(0.0, (float) LeaveCredit::where('employee_id', $employeeUser->employee->id)
            ->where('leave_type_id', $application->leave_type_id)->firstOrFail()->used_credits);
        $this->assertDatabaseHas('leave_approval_histories', [
            'leave_application_id' => $application->id,
            'action' => 'queued_for_hrmo_verification',
        ]);
        $this->assertNull($application->office_head_reviewer_id);
    }

    public function test_all_four_roles_can_apply_for_personal_leave_through_separate_routes(): void
    {
        $adminApplicant = User::where('email', 'administrator@example.test')->firstOrFail();
        $alternateHrReviewer = User::where('email', 'hrmo@example.test')->firstOrFail();
        $mayorApplicant = User::where('role', 'mayor')->firstOrFail();
        $officeHead = User::where('role', 'office_head')->firstOrFail();
        $department = Department::where('code', 'TEST')->firstOrFail();
        $vacation = LeaveType::where('code', 'VACATION')->firstOrFail();

        foreach ([
            [$adminApplicant, 'ADMIN-SELF'],
            [$mayorApplicant, 'MAYOR-SELF'],
            [$officeHead, 'HEAD-SELF'],
        ] as [$user, $employeeNo]) {
            $employee = Employee::create([
                'user_id' => $user->id,
                'department_id' => $department->id,
                'position_id' => Position::where('department_id', $department->id)->value('id'),
                'employee_no' => $employeeNo,
                'first_name' => $user->first_name ?: $user->name,
                'last_name' => 'Applicant',
                'employment_status' => 'Active',
                'employment_type' => 'Permanent',
                'date_hired' => '2020-01-06',
            ]);
            LeaveCredit::updateOrCreate([
                'employee_id' => $employee->id,
                'leave_type_id' => $vacation->id,
            ], [
                'beginning_balance' => 10,
                'earned_credits' => 0,
                'used_credits' => 0,
                'adjustments' => 0,
                'effective_date' => today(),
            ]);
        }

        SystemSetting::updateOrCreate(['key' => 'leave_alternate_hr_reviewer_id'], [
            'value' => $alternateHrReviewer->id,
            'updated_by' => $adminApplicant->id,
        ]);
        SystemSetting::updateOrCreate(['key' => 'mayor_leave_approver_id'], [
            'value' => $adminApplicant->id,
            'updated_by' => $adminApplicant->id,
        ]);

        $applicants = [
            [$adminApplicant, 'ADMIN-SELF'],
            [$mayorApplicant, 'MAYOR-SELF'],
            [$officeHead, 'HEAD-SELF'],
            [Employee::where('employee_no', 'TEST-0001')->firstOrFail()->user, 'TEST-0001'],
        ];
        [$startDate, $endDate] = $this->nextTwoWorkingDays();
        $applications = [];

        foreach ($applicants as [$user, $employeeNo]) {
            $this->actingAs($user)
                ->get(route('leave.create'))
                ->assertOk();
            $this->get(route('leave-credits.index', ['mine' => 1]))->assertOk();
            $this->post(route('leave.store'), [
                'intent' => 'submit',
                'leave_type_id' => $vacation->id,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'commutation_requested' => '0',
            ])->assertRedirect();
            $applications[$employeeNo] = LeaveApplication::where('employee_id', Employee::where('employee_no', $employeeNo)->value('id'))
                ->latest('id')->firstOrFail();
        }

        $this->assertSame('Under HRMO Verification', $applications['ADMIN-SELF']->status);
        $this->assertSame($alternateHrReviewer->id, $applications['ADMIN-SELF']->assigned_hr_reviewer_id);
        $this->assertSame('Under HRMO Verification', $applications['MAYOR-SELF']->status);
        $this->assertSame($adminApplicant->id, $applications['MAYOR-SELF']->assigned_final_approver_id);
        $this->assertSame('Under HRMO Verification', $applications['HEAD-SELF']->status);
        $this->assertNull($applications['HEAD-SELF']->office_head_reviewer_id);
        $this->assertSame('Under HRMO Verification', $applications['TEST-0001']->status);
        $this->assertNull($applications['TEST-0001']->office_head_reviewer_id);

        $this->actingAs($adminApplicant)->post(route('leave.review', $applications['ADMIN-SELF']), [
            'action' => 'verify',
        ])->assertForbidden();
        $this->actingAs($mayorApplicant)->post(route('leave.review', $applications['MAYOR-SELF']), [
            'action' => 'approve',
            'days_with_pay' => 2,
            'days_without_pay' => 0,
        ])->assertForbidden();
        $this->actingAs($officeHead)->post(route('leave.review', $applications['HEAD-SELF']), [
            'action' => 'endorse',
            'recommendation' => 'For approval',
        ])->assertForbidden();

        $this->actingAs($alternateHrReviewer)->post(route('leave.review', $applications['ADMIN-SELF']), [
            'action' => 'verify',
            'verified_working_days' => 2,
            'certifier_position' => 'Human Resource Officer',
            'certifier_signature' => 'Alternate HR Reviewer',
        ])->assertRedirect();
        $this->assertSame('Under Office Head Review', $applications['ADMIN-SELF']->fresh()->status);
    }

    public function test_official_leave_sequence_requires_hrmo_then_office_head_then_mayor(): void
    {
        [$startDate, $endDate] = $this->nextTwoWorkingDays();
        $employeeUser = User::where('email', 'test-employee@example.test')->firstOrFail();
        $employee = $employeeUser->employee;
        $vacation = LeaveType::where('code', 'VACATION')->firstOrFail();

        $this->actingAs($employeeUser)->post(route('leave.store'), [
            'intent' => 'submit',
            'leave_type_id' => $vacation->id,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'commutation_requested' => '0',
        ])->assertRedirect();

        $application = LeaveApplication::where('employee_id', $employee->id)->latest('id')->firstOrFail();
        $this->assertSame('Under HRMO Verification', $application->status);
        $this->assertNull($application->office_head_reviewer_id);

        $officeHead = User::where('role', 'office_head')->firstOrFail();
        $this->actingAs($officeHead)->post(route('leave.review', $application), [
            'action' => 'endorse',
            'recommendation' => 'For approval',
        ])->assertForbidden();

        $hrmo = User::where('email', 'hrmo@example.test')->firstOrFail();
        $this->actingAs($hrmo)->post(route('leave.review', $application), [
            'action' => 'verify',
            'verified_working_days' => 2,
            'certifier_position' => 'Human Resource Officer',
            'certifier_signature' => 'HRMO Officer',
        ])->assertRedirect();

        $application->refresh();
        $this->assertSame('Under Office Head Review', $application->status);
        $this->assertSame($officeHead->id, $application->office_head_reviewer_id);
        $this->assertNotNull($application->certified_by);

        $mayor = User::where('role', 'mayor')->firstOrFail();
        $this->actingAs($mayor)->post(route('leave.review', $application), [
            'action' => 'approve',
            'days_with_pay' => 2,
            'days_without_pay' => 0,
        ])->assertForbidden();

        $this->actingAs($officeHead)->post(route('leave.review', $application), [
            'action' => 'endorse',
            'recommendation' => 'For approval',
        ])->assertRedirect();

        $application->refresh();
        $this->assertSame('Pending Final Approval', $application->status);
        $this->assertDatabaseMissing('leave_credit_transactions', [
            'leave_application_id' => $application->id,
        ]);

        $this->actingAs($mayor)->post(route('leave.review', $application), [
            'action' => 'approve',
            'days_with_pay' => 2,
            'days_without_pay' => 0,
        ])->assertRedirect();

        $application->refresh();
        $this->assertSame('Approved', $application->status);
        $credit = LeaveCredit::where('employee_id', $employee->id)
            ->where('leave_type_id', $vacation->id)
            ->firstOrFail();
        $this->assertSame(2.0, (float) $credit->used_credits);
    }

    public function test_employee_can_cancel_a_leave_application_during_hrmo_verification(): void
    {
        [$startDate, $endDate] = $this->nextTwoWorkingDays();
        $employee = Employee::where('employee_no', 'TEST-0001')->firstOrFail();

        $this->actingAs($employee->user)->post(route('leave.store'), [
            'intent' => 'submit',
            'leave_type_id' => LeaveType::where('code', 'VACATION')->value('id'),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'commutation_requested' => '0',
        ])->assertRedirect();

        $application = LeaveApplication::where('employee_id', $employee->id)->latest('id')->firstOrFail();
        $this->assertSame('Under HRMO Verification', $application->status);

        $this->actingAs($employee->user)
            ->post(route('leave.cancel', $application))
            ->assertRedirect(route('leave.index'));

        $application->refresh();
        $this->assertSame('Cancelled', $application->status);
        $this->assertDatabaseHas('leave_approval_histories', [
            'leave_application_id' => $application->id,
            'action' => 'cancelled',
            'from_status' => 'Under HRMO Verification',
            'to_status' => 'Cancelled',
        ]);
    }

    public function test_mayor_leave_without_a_valid_designated_approver_waits_for_assignment_after_office_head_review(): void
    {
        [$startDate, $endDate] = $this->nextTwoWorkingDays();
        $mayor = User::where('email', 'mayor@example.test')->firstOrFail();
        $admin = User::where('email', 'administrator@example.test')->firstOrFail();
        $department = Department::where('code', 'TEST')->firstOrFail();
        $position = Position::where('department_id', $department->id)->firstOrFail();
        $vacation = LeaveType::where('code', 'VACATION')->firstOrFail();
        $employee = Employee::create([
            'user_id' => $mayor->id,
            'department_id' => $department->id,
            'position_id' => $position->id,
            'employee_no' => 'MAYOR-UNASSIGNED',
            'first_name' => $mayor->name,
            'last_name' => 'Applicant',
            'employment_status' => 'Active',
            'employment_type' => 'Permanent',
            'date_hired' => '2020-01-06',
        ]);
        LeaveCredit::updateOrCreate([
            'employee_id' => $employee->id,
            'leave_type_id' => $vacation->id,
        ], [
            'beginning_balance' => 12,
            'earned_credits' => 0,
            'used_credits' => 0,
            'adjustments' => 0,
            'effective_date' => today(),
        ]);
        SystemSetting::updateOrCreate(['key' => 'mayor_leave_approver_id'], [
            'value' => null,
            'updated_by' => $admin->id,
        ]);

        $this->actingAs($mayor)->post(route('leave.store'), [
            'intent' => 'submit',
            'leave_type_id' => $vacation->id,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'commutation_requested' => '0',
        ])->assertRedirect();

        $application = LeaveApplication::where('employee_id', $employee->id)->latest('id')->firstOrFail();
        $this->assertNull($application->assigned_final_approver_id);

        $hrmo = User::where('email', 'hrmo@example.test')->firstOrFail();
        $this->actingAs($hrmo)->post(route('leave.review', $application), [
            'action' => 'verify',
            'verified_working_days' => $application->working_days,
            'certifier_position' => 'Human Resource Officer',
            'certifier_signature' => 'HRMO Officer',
        ])->assertRedirect();
        $this->assertSame('Under Office Head Review', $application->fresh()->status);

        $officeHead = User::where('role', 'office_head')->firstOrFail();
        $this->actingAs($officeHead)->post(route('leave.review', $application), [
            'action' => 'endorse',
            'recommendation' => 'For approval',
        ])->assertRedirect();

        $application->refresh();
        $this->assertSame('Awaiting Authorized Final Approver Assignment', $application->status);
        $this->assertNull($application->assigned_final_approver_id);
        $this->assertTrue($admin->notifications()->where('data->application_id', $application->id)->exists());
        $this->actingAs($admin)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Reviewer assignment required');

        $this->actingAs($admin)->get(route('leave.show', $application))
            ->assertOk()
            ->assertSee('Assign authorized final approver');
        $this->post(route('leave.review', $application), [
            'action' => 'assign_final_approver',
            'assigned_final_approver_id' => $admin->id,
        ])->assertRedirect();

        $application->refresh();
        $this->assertSame('Pending Authorized Final Approval', $application->status);
        $this->assertSame($admin->id, $application->assigned_final_approver_id);

        $this->post(route('leave.review', $application), [
            'action' => 'approve',
            'days_with_pay' => $application->verified_working_days,
            'days_without_pay' => 0,
        ])->assertRedirect();

        $this->assertSame('Approved', $application->fresh()->status);
        $this->assertDatabaseHas('leave_approval_histories', [
            'leave_application_id' => $application->id,
            'action' => 'mayor_leave_approver_assigned',
            'from_status' => 'Awaiting Authorized Final Approver Assignment',
            'to_status' => 'Pending Authorized Final Approval',
        ]);
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
            'policy_details' => ['protection_order_status' => 'issued'],
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

        $payload['document_types'] = ['Barangay Protection Order (BPO), Temporary Protection Order (TPO), or Permanent Protection Order (PPO)'];
        $this->post(route('leave.store'), $payload)->assertRedirect();
        $application = LeaveApplication::latest('id')->firstOrFail();
        $document = $application->documents()->firstOrFail();
        $this->assertSame('Barangay Protection Order (BPO), Temporary Protection Order (TPO), or Permanent Protection Order (PPO)', $document->document_type);
        Storage::disk('local')->assertExists($document->stored_path);
    }

    public function test_leave_document_failure_rolls_back_application_and_removes_stored_file(): void
    {
        Storage::fake('local');
        $employee = Employee::where('employee_no', 'TEST-0001')->firstOrFail();
        $type = LeaveType::where('code', 'VAWC')->firstOrFail();
        $storedType = 'Barangay Protection Order (BPO), Temporary Protection Order (TPO), or Permanent Protection Order (PPO)';
        LeaveApplicationDocument::creating(static function (): void {
            throw new \RuntimeException('Simulated document metadata failure.');
        });

        $this->actingAs($employee->user)->post(route('leave.store'), [
            'intent' => 'submit',
            'leave_type_id' => $type->id,
            'start_date' => '2026-12-10',
            'end_date' => '2026-12-10',
            'commutation_requested' => '0',
            'policy_details' => ['protection_order_status' => 'issued'],
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
        $approver = User::where('role', 'mayor')->firstOrFail();

        $this->actingAs($approver)->post(route('leave.review', $application), [
            'action' => 'approve',
            'days_with_pay' => 1,
            'days_without_pay' => 0,
        ])->assertSessionHasErrors('days_with_pay');

        $this->assertSame('Pending Final Approval', $application->fresh()->status);
        $this->assertSame(0.0, $credit->fresh()->balance);
        $this->assertDatabaseMissing('leave_credit_transactions', ['leave_application_id' => $application->id]);
    }

    public function test_final_approver_can_disapprove_a_leave_application_with_a_recorded_reason(): void
    {
        $employee = Employee::where('employee_no', 'TEST-0001')->firstOrFail();
        $application = LeaveApplication::create([
            'employee_id' => $employee->id,
            'leave_type_id' => LeaveType::where('code', 'VACATION')->value('id'),
            'status' => 'Pending Final Approval',
            'office_snapshot' => $employee->department->name,
            'last_name_snapshot' => $employee->last_name,
            'first_name_snapshot' => $employee->first_name,
            'working_days' => 1,
            'start_date' => today()->addDays(5),
            'end_date' => today()->addDays(5),
        ]);

        $this->actingAs(User::where('role', 'mayor')->firstOrFail())
            ->post(route('leave.review', $application), [
                'action' => 'disapprove',
                'reason' => 'The application does not meet the approved leave requirements.',
            ])
            ->assertRedirect(route('leave.show', $application));

        $this->assertSame('Disapproved', $application->fresh()->status);
        $this->assertSame(
            'The application does not meet the approved leave requirements.',
            $application->fresh()->disapproval_reason
        );
        $this->assertDatabaseHas('leave_approval_histories', [
            'leave_application_id' => $application->id,
            'to_status' => 'Disapproved',
        ]);
        $this->assertSame(0.0, (float) LeaveCredit::where('employee_id', $employee->id)
            ->where('leave_type_id', $application->leave_type_id)->firstOrFail()->used_credits);
    }

    public function test_category_specific_leave_fields_and_filing_rules_are_enforced(): void
    {
        $employee = Employee::where('employee_no', 'TEST-0001')->firstOrFail();
        $employeeUser = $employee->user;
        [$startDate, $endDate] = $this->nextTwoWorkingDays();

        $this->actingAs($employeeUser)->post(route('leave.store'), [
            'intent' => 'submit',
            'leave_type_id' => LeaveType::where('code', 'MATERNITY')->value('id'),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'commutation_requested' => '0',
        ])->assertSessionHasErrors('policy_details.expected_delivery_date');

        $this->actingAs($employeeUser)->post(route('leave.store'), [
            'intent' => 'submit',
            'leave_type_id' => LeaveType::where('code', 'SPECIAL_PRIVILEGE')->value('id'),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'policy_details' => ['travel_location' => 'within_philippines'],
            'commutation_requested' => '0',
        ])->assertSessionHasErrors('policy_details.emergency_case');

        $this->actingAs($employeeUser)->post(route('leave.store'), [
            'intent' => 'submit',
            'leave_type_id' => LeaveType::where('code', 'WELLNESS')->value('id'),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'commutation_requested' => '0',
        ])->assertSessionHasErrors('policy_details.eligibility_basis');
    }

    public function test_hrmo_can_export_employee_leave_balances_and_monthly_accrual_ledger(): void
    {
        $employee = Employee::where('employee_no', 'TEST-0001')->firstOrFail();
        $credit = LeaveCredit::where('employee_id', $employee->id)
            ->whereHas('leaveType', fn ($query) => $query->where('code', 'VACATION'))
            ->firstOrFail();
        LeaveCreditTransaction::create([
            'leave_credit_id' => $credit->id,
            'type' => 'monthly_accrual',
            'amount' => 1.25,
            'previous_balance' => 12,
            'balance_after' => 13.25,
            'effective_date' => today(),
            'accrual_month' => today()->startOfMonth(),
            'remarks' => 'Monthly accrual report test.',
        ]);

        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->get(route('reports.index'))
            ->assertOk()
            ->assertSee('Leave credit and special leave reports');

        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->get(route('reports.leave-credit.csv', ['report' => 'balances']))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $ledgerResponse = $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->get(route('reports.leave-credit.csv', ['report' => 'accrual']));
        $ledgerResponse->assertOk();
        $this->assertStringContainsString('Monthly accrual report test.', $ledgerResponse->streamedContent());
    }

    public function test_distinct_mayor_can_review_only_authorized_leave_records_without_sensitive_details(): void
    {
        $mayor = User::where('role', 'mayor')->firstOrFail();
        $employee = Employee::where('employee_no', 'TEST-0001')->firstOrFail();
        $sensitiveType = LeaveType::where('code', 'VAWC')->firstOrFail();
        $pending = LeaveApplication::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $sensitiveType->id,
            'status' => 'Pending Final Approval',
            'eligibility_verified_at' => now(),
            'eligibility_verified_by' => User::where('role', 'admin')->value('id'),
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
            ->assertDontSee('Alternate Office Head review');

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
            ->assertSee('Alternate Office Head review');
        $this->get(route('leave.show', $otherMayorRequest))->assertForbidden();
        $this->get(route('leave.show', $outsideScope))->assertForbidden();

        $this->post(route('leave.review', $pending), [
            'action' => 'approve',
            'days_with_pay' => 1,
            'days_without_pay' => 0,
        ])->assertRedirect();
        $this->assertSame('Approved', $pending->fresh()->status);
    }

    public function test_mayor_alternate_endorsement_is_separate_from_hr_verification_and_final_approval(): void
    {
        [$startDate, $endDate] = $this->nextTwoWorkingDays();
        $department = Department::where('code', 'TEST')->firstOrFail();
        $officeHead = User::where('role', 'office_head')->firstOrFail();
        $mayor = User::where('role', 'mayor')->firstOrFail();
        OfficeHeadAvailability::create([
            'department_id' => $department->id,
            'office_head_user_id' => $officeHead->id,
            'availability_status' => 'on_leave',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'reason' => 'Authorized test availability period',
            'updated_by' => User::where('role', 'admin')->value('id'),
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
        $this->assertSame('Under HRMO Verification', $application->status);
        $this->assertNull($application->office_head_reviewer_id);

        $this->actingAs(User::where('email', 'hrmo@example.test')->firstOrFail())
            ->post(route('leave.review', $application), [
                'action' => 'verify',
                'verified_working_days' => 2,
                'certifier_position' => 'Human Resource Officer',
                'certifier_signature' => 'HRMO Officer',
            ])->assertRedirect();

        $application->refresh();
        $this->assertSame('Under Alternate Office Head Review', $application->status);
        $this->assertSame($mayor->id, $application->office_head_reviewer_id);

        $this->actingAs($mayor)->post(route('leave.review', $application), [
            'action' => 'endorse',
            'recommendation' => 'For approval',
            'remarks' => 'Authorized alternate endorsement.',
        ])->assertRedirect();
        $this->assertSame('Pending Final Approval', $application->fresh()->status);
        $this->assertDatabaseHas('leave_approval_histories', [
            'leave_application_id' => $application->id,
            'action' => 'mayor_alternate_endorsement',
            'user_id' => $mayor->id,
        ]);
        $this->actingAs($mayor)->post(route('leave.review', $application), [
            'action' => 'approve',
            'days_with_pay' => 2,
            'days_without_pay' => 0,
            'remarks' => 'Final approval after HR certification.',
        ])->assertRedirect();

        $application->refresh();
        $this->assertSame('Approved', $application->status);
        $this->assertSame($mayor->id, $application->decided_by);
        $this->assertSame(1, $application->history()->where('action', 'mayor_alternate_endorsement')->count());
        $this->assertSame(10.0, LeaveCredit::where('employee_id', $employeeUser->employee->id)
            ->where('leave_type_id', $application->leave_type_id)->firstOrFail()->balance);
        $this->assertSame(1, LeaveCreditTransaction::where('leave_application_id', $application->id)->count());
    }

    public function test_unavailable_office_head_without_a_single_authorized_fallback_cannot_submit(): void
    {
        User::where('role', 'mayor')->update(['role' => 'employee']);
        $department = Department::where('code', 'TEST')->firstOrFail();
        $officeHead = User::where('role', 'office_head')->firstOrFail();
        OfficeHeadAvailability::create([
            'department_id' => $department->id,
            'office_head_user_id' => $officeHead->id,
            'availability_status' => 'official_business',
            'start_date' => today(),
            'end_date' => today()->addDays(3),
            'reason' => 'Authorized test availability period',
            'updated_by' => User::where('role', 'admin')->value('id'),
        ]);

        $employeeUser = Employee::where('employee_no', 'TEST-0001')->firstOrFail()->user;
        $this->actingAs($employeeUser)->post(route('leave.store'), [
            'intent' => 'submit',
            'leave_type_id' => LeaveType::where('code', 'VACATION')->value('id'),
            'start_date' => today()->addDays(2)->toDateString(),
            'end_date' => today()->addDays(3)->toDateString(),
            'commutation_requested' => '0',
        ])->assertRedirect();

        $application = LeaveApplication::where('employee_id', $employeeUser->employee->id)->latest('id')->firstOrFail();
        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->post(route('leave.review', $application), [
                'action' => 'verify',
                'verified_working_days' => 2,
                'certifier_position' => 'Human Resource Officer',
                'certifier_signature' => 'HRMO Officer',
            ])->assertSessionHasErrors('office_head');

        $this->assertDatabaseMissing('leave_applications', [
            'employee_id' => $employeeUser->employee->id,
            'status' => 'Under Alternate Office Head Review',
        ]);
    }

    public function test_hr_reviewer_can_return_an_application_and_resubmission_restarts_the_review_route(): void
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
            'updated_by' => User::where('role', 'admin')->value('id'),
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
        $hrmo = User::where('role', 'admin')->firstOrFail();
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
        $this->assertSame('Under HRMO Verification', $application->status);
        $this->assertNull($application->alternate_decision_by);
        $this->assertNull($application->alternate_decision_action);
        $this->assertSame(1, $application->working_days);
    }

    public function test_only_hrmo_and_administrator_can_manage_office_head_availability(): void
    {
        $this->actingAs(User::where('role', 'office_head')->firstOrFail())
            ->get(route('office-head-availability.index'))
            ->assertOk()
            ->assertSee('Record availability');

        $this->actingAs(User::where('role', 'admin')->firstOrFail())
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

        $this->actingAs(User::where('role', 'admin')->firstOrFail())
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

    public function test_office_head_hrmo_and_mayor_complete_approval_and_leave_credit_debit(): void
    {
        $employee = Employee::where('employee_no', 'TEST-0001')->firstOrFail();
        $vacation = LeaveType::where('code', 'VACATION')->firstOrFail();
        $mayor = User::where('role', 'mayor')->firstOrFail();
        $application = LeaveApplication::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $vacation->id,
            'status' => 'Under HRMO Verification',
            'office_head_reviewer_id' => User::where('role', 'office_head')->value('id'),
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

        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->post(route('leave.review', $application), [
                'action' => 'verify',
                'verified_working_days' => 2,
                'certifier_position' => 'Human Resource Officer',
                'certifier_signature' => 'HRMO Officer',
                'remarks' => 'Verified against leave record.',
            ])->assertRedirect();
        $this->assertDatabaseHas('leave_applications', [
            'id' => $application->id,
            'status' => 'Under Office Head Review',
            'certifier_position' => 'Human Resource Officer',
            'certifier_signature' => 'HRMO Officer',
        ]);

        $this->actingAs(User::where('role', 'office_head')->firstOrFail())
            ->post(route('leave.review', $application), [
                'action' => 'endorse',
                'recommendation' => 'For approval',
            ])->assertRedirect();
        $this->assertDatabaseHas('leave_applications', ['id' => $application->id, 'status' => 'Pending Final Approval']);

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
            'amount' => -2,
            'previous_balance' => 12,
            'balance_after' => 10,
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
        $requiredDocument = 'Barangay Protection Order (BPO), Temporary Protection Order (TPO), or Permanent Protection Order (PPO)';
        $makeApplication = fn (string $employeeNo) => LeaveApplication::create([
            'employee_id' => Employee::where('employee_no', $employeeNo)->value('id'),
            'leave_type_id' => $type->id,
            'policy_details' => ['protection_order_status' => 'issued'],
            'status' => 'Under HRMO Verification',
            'office_snapshot' => $employee->department->name,
            'last_name_snapshot' => $employee->last_name,
            'first_name_snapshot' => $employee->first_name,
            'working_days' => 1,
            'verified_working_days' => null,
            'start_date' => today()->addDays(10),
            'end_date' => today()->addDays(10),
        ]);
        $hrmo = User::where('role', 'admin')->firstOrFail();
        $verification = [
            'action' => 'verify',
            'verified_working_days' => 1,
            'certifier_position' => 'Human Resource Officer',
            'certifier_signature' => 'HRMO Officer',
        ];
        $waiverApplication = $makeApplication('TEST-0001');

        $this->actingAs($hrmo)->post(route('leave.review', $waiverApplication), $verification)
            ->assertSessionHasErrors('eligibility_verified');
        $verification['eligibility_verified'] = '1';
        $this->post(route('leave.review', $waiverApplication), $verification)
            ->assertSessionHasErrors('waived_documents');
        $this->post(route('leave.review', $waiverApplication), $verification + [
            'waived_documents' => ['Unconfigured document'],
            'waiver_justification' => 'This item is not in the approved checklist.',
        ])->assertSessionHasErrors('waived_documents.0');
        $this->post(route('leave.review', $waiverApplication), $verification + [
            'waived_documents' => [$requiredDocument],
            'waiver_justification' => 'Approved policy exception recorded for verification.',
        ])->assertRedirect();
        $this->assertSame('Under Office Head Review', $waiverApplication->fresh()->status);
        $this->assertSame([$requiredDocument], $waiverApplication->fresh()->document_waivers['documents']);
        $this->assertNotNull($waiverApplication->fresh()->eligibility_verified_at);
        $this->assertSame($hrmo->id, $waiverApplication->fresh()->eligibility_verified_by);

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
        $this->assertSame('Under Office Head Review', $attachedApplication->fresh()->status);
        $this->assertNull($attachedApplication->fresh()->document_waivers);
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
        $hrmo = User::where('role', 'admin')->firstOrFail();
        $effectiveFrom = today()->nextWeekday()->toDateString();
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
            'created_by' => User::where('role', 'admin')->firstOrFail()->id,
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
        $this->actingAs(User::where('role', 'admin')->firstOrFail())
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
            ->assertViewHas('role', 'administrator')
            ->assertViewHas('pageTitle', 'Administration overview')
            ->assertSee('data-role-theme="administrator"', false)
            ->assertSee('User & permissions', false)
            ->assertSee('Pending HR actions');

        $this->actingAs(User::where('role', 'mayor')->firstOrFail())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('role', 'mayor')
            ->assertSee('data-role-theme="mayor"', false)
            ->assertSee('Escalated requests')
            ->assertSee('Department staffing')
            ->assertDontSee('System settings');

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
            ->assertSee('Apply for leave')
            ->assertSee('My leave applications')
            ->assertDontSee('Audit logs');
    }

    public function test_admin_can_manage_accounts_and_system_while_mayor_is_restricted(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('users.index'))
            ->assertOk()
            ->assertSee('Manage →')
            ->assertSee(route('users.create'));

        $this->get(route('audit.index'))->assertOk();
        $this->get(route('users.create'))->assertOk();
        $this->get(route('settings.system'))->assertOk();

        $this->actingAs(User::where('role', 'mayor')->firstOrFail())
            ->get(route('users.create'))
            ->assertForbidden();
        $this->get(route('settings.system'))->assertForbidden();
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
        $otherCredit = LeaveCredit::where('employee_id', $otherEmployee->id)
            ->whereHas('leaveType', fn ($query) => $query->where('code', 'VACATION'))
            ->firstOrFail();
        $otherCredit->update(['beginning_balance' => 5]);

        $this->actingAs($employee->user)
            ->get(route('leave-credits.index'))
            ->assertOk()
            ->assertViewHas('credits', fn ($credits) => $credits->total() === 2)
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

    public function test_hrmo_dashboard_shows_only_unassigned_or_personally_assigned_verifications(): void
    {
        $employee = Employee::where('employee_no', 'TEST-0001')->firstOrFail();
        $hrmo = User::where('email', 'hrmo@example.test')->firstOrFail();
        $otherHrmo = User::where('email', 'administrator@example.test')->firstOrFail();
        $applicationData = [
            'employee_id' => $employee->id,
            'leave_type_id' => LeaveType::where('code', 'VACATION')->value('id'),
            'status' => 'Under HRMO Verification',
            'office_snapshot' => $employee->department->name,
            'last_name_snapshot' => $employee->last_name,
            'first_name_snapshot' => $employee->first_name,
            'working_days' => 1,
            'start_date' => today()->addDays(10),
            'end_date' => today()->addDays(10),
        ];
        $unassigned = collect();
        for ($index = 0; $index < 9; $index++) {
            $unassigned->push(LeaveApplication::create($applicationData));
        }
        $assignedToHrmo = LeaveApplication::create(array_merge($applicationData, [
            'assigned_hr_reviewer_id' => $hrmo->id,
        ]));
        LeaveApplication::create(array_merge($applicationData, [
            'assigned_hr_reviewer_id' => $otherHrmo->id,
        ]));

        $this->actingAs($hrmo)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('pendingCount', 10)
            ->assertViewHas('pendingTasks', fn ($tasks) => $tasks->count() === 8
                && $tasks->every(fn ($task) => $task->assigned_hr_reviewer_id === null
                    || $task->assigned_hr_reviewer_id === $hrmo->id));
    }

    public function test_dashboard_counts_distinct_employees_with_approved_leave_today(): void
    {
        $employee = Employee::where('employee_no', 'TEST-0001')->firstOrFail();
        $otherEmployee = Employee::create([
            'department_id' => $employee->department_id,
            'employee_no' => 'TEST-0002',
            'first_name' => 'Second',
            'last_name' => 'Employee',
            'employment_status' => 'Active',
        ]);
        $applicationData = [
            'leave_type_id' => LeaveType::where('code', 'VACATION')->value('id'),
            'status' => 'Approved',
            'office_snapshot' => $employee->department->name,
            'last_name_snapshot' => $employee->last_name,
            'first_name_snapshot' => $employee->first_name,
            'working_days' => 1,
            'start_date' => today(),
            'end_date' => today(),
        ];
        LeaveApplication::create(array_merge($applicationData, ['employee_id' => $employee->id]));
        LeaveApplication::create(array_merge($applicationData, ['employee_id' => $employee->id]));
        LeaveApplication::create(array_merge($applicationData, [
            'employee_id' => $otherEmployee->id,
            'last_name_snapshot' => $otherEmployee->last_name,
            'first_name_snapshot' => $otherEmployee->first_name,
        ]));

        $this->actingAs(User::where('role', 'admin')->firstOrFail())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('leaveTodayCount', 2);
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
        foreach (['admin', 'mayor', 'office_head'] as $role) {
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
