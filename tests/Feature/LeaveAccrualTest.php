<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveCredit;
use App\Models\LeaveCreditTransaction;
use App\Models\LeaveApplication;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\EmployeeLeaveEntitlementSummary;
use App\Services\LeaveWorkflowService;
use App\Services\MonthlyLeaveAccrualService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LeaveAccrualTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->department = Department::create(['name' => 'Accrual Test Office', 'code' => 'ACCRUAL']);
    }

    public function test_monthly_vacation_and_sick_accrual_is_idempotent_and_totals_fifteen_days_per_year(): void
    {
        $employee = $this->employee('ACC-0001', '2020-01-01');
        $service = app(MonthlyLeaveAccrualService::class);
        $totalTransactions = 0;
        for ($month = 1; $month <= 12; $month++) {
            $summary = $service->accrue(Carbon::create(2024, $month, 1));
            $this->assertSame(1, $summary['employees']);
            $this->assertSame(2, $summary['transactions']);
            $this->assertSame(2.5, $summary['credits']);
            $totalTransactions += $summary['transactions'];
        }

        $duplicate = $service->accrue(Carbon::create(2024, 12, 1));
        $this->assertSame(0, $duplicate['transactions']);
        $this->assertSame(0.0, $duplicate['credits']);
        $this->assertSame(24, $totalTransactions);

        foreach (['VACATION', 'SICK'] as $code) {
            $credit = LeaveCredit::query()
                ->where('employee_id', $employee->id)
                ->whereHas('leaveType', fn ($query) => $query->where('code', $code))
                ->firstOrFail();
            $this->assertSame(15.0, (float) $credit->earned_credits);
            $this->assertSame(15.0, $credit->balance);
            $this->assertSame(12, $credit->transactions()->where('type', 'monthly_accrual')->count());
        }
    }

    public function test_monthly_accrual_skips_inactive_employees_and_employees_without_a_completed_month(): void
    {
        $eligible = $this->employee('ACC-0002', '2020-01-01');
        $inactive = $this->employee('ACC-0003', '2020-01-01', 'Separated');
        $hired_during_month = $this->employee('ACC-0004', '2024-01-15');
        $no_hire_date = $this->employee('ACC-0005', null);

        $summary = app(MonthlyLeaveAccrualService::class)->accrue(Carbon::create(2024, 1, 1));

        $this->assertSame(1, $summary['employees']);
        $this->assertSame(2, $summary['transactions']);
        foreach ([$eligible] as $employee) {
            $this->assertSame(1.25, (float) $employee->leaveCredits()->whereHas('leaveType', fn ($query) => $query->where('code', 'VACATION'))->firstOrFail()->earned_credits);
            $this->assertSame(1.25, (float) $employee->leaveCredits()->whereHas('leaveType', fn ($query) => $query->where('code', 'SICK'))->firstOrFail()->earned_credits);
        }
        foreach ([$inactive, $hired_during_month, $no_hire_date] as $employee) {
            $this->assertSame(0, $employee->leaveCredits()->whereHas('leaveType', fn ($query) => $query->whereIn('code', ['VACATION', 'SICK']))->sum('earned_credits'));
        }
        $this->assertSame(2, LeaveCreditTransaction::where('type', 'monthly_accrual')->count());
    }

    public function test_accrual_eligibility_can_be_configured_per_leave_type(): void
    {
        $employee = $this->employee('ACC-0006', '2020-01-01', 'On Leave');
        $sick = LeaveType::where('code', 'SICK')->firstOrFail();
        $sick->rules = array_replace_recursive($sick->rules, [
            'accrual_eligibility' => ['employment_statuses' => ['Active', 'On Leave']],
        ]);
        $sick->save();

        $summary = app(MonthlyLeaveAccrualService::class)->accrue(Carbon::create(2024, 1, 1));

        $this->assertSame(1, $summary['transactions']);
        $this->assertSame(0.0, (float) $employee->leaveCredits()
            ->whereHas('leaveType', fn ($query) => $query->where('code', 'VACATION'))
            ->firstOrFail()->earned_credits);
        $this->assertSame(1.25, (float) $employee->leaveCredits()
            ->whereHas('leaveType', fn ($query) => $query->where('code', 'SICK'))
            ->firstOrFail()->earned_credits);
    }

    public function test_wellness_leave_is_configured_separately_and_is_not_automatically_granted(): void
    {
        $wellness = LeaveType::where('code', 'WELLNESS')->firstOrFail();

        $this->assertFalse($wellness->requires_credit);
        $this->assertFalse($wellness->rules['automatic_grant']);
        $this->assertFalse($wellness->rules['consumes_regular_credit']);
        $this->assertSame(5, $wellness->rules['entitlement_days']);
    }

    public function test_special_entitlements_are_not_shown_without_an_employee_specific_credit_account(): void
    {
        $employee = $this->employee('ACC-0008', '2020-01-01');

        $this->assertTrue(app(EmployeeLeaveEntitlementSummary::class)->forYear($employee)->isEmpty());

        $wellness = LeaveType::where('code', 'WELLNESS')->firstOrFail();
        LeaveCredit::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $wellness->id,
            'beginning_balance' => 2,
            'earned_credits' => 0,
            'used_credits' => 0,
            'adjustments' => 0,
            'effective_date' => today(),
        ]);

        $summary = app(EmployeeLeaveEntitlementSummary::class)->forYear($employee)
            ->firstWhere('type.code', 'WELLNESS');
        $this->assertSame(2.0, $summary['available']);
        $this->assertSame(2.0, $summary['entitlement']);
    }

    public function test_wellness_approval_does_not_consume_vacation_or_sick_credits(): void
    {
        $employee = $this->employee('ACC-0007', '2020-01-01');
        $wellness = LeaveType::where('code', 'WELLNESS')->firstOrFail();
        LeaveCredit::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $wellness->id,
            'beginning_balance' => 5,
            'earned_credits' => 0,
            'used_credits' => 0,
            'adjustments' => 0,
            'effective_date' => today(),
        ]);
        $application = LeaveApplication::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $wellness->id,
            'status' => 'Under HRMO Verification',
            'office_snapshot' => $this->department->name,
            'last_name_snapshot' => $employee->last_name,
            'first_name_snapshot' => $employee->first_name,
            'policy_details' => ['eligibility_basis' => 'Approved local wellness policy'],
            'working_days' => 1,
            'start_date' => today()->addDays(10),
            'end_date' => today()->addDays(10),
        ]);
        $approver = User::create([
            'name' => 'Leave Approver',
            'email' => 'leave-approver@example.test',
            'password' => 'test-password',
            'role' => 'mayor',
            'is_active' => true,
            'can_final_approve' => false,
        ]);

        $hrmo = User::create([
            'name' => 'Test HRMO',
            'email' => 'test-hrmo@example.test',
            'password' => 'test-password',
            'role' => 'admin',
            'is_active' => true,
        ]);
        $officeHead = User::create([
            'name' => 'Accrual Office Head',
            'email' => 'accrual-office-head@example.test',
            'password' => 'test-password',
            'role' => 'office_head',
            'is_active' => true,
        ]);
        $this->department->update(['head_user_id' => $officeHead->id]);
        app(LeaveWorkflowService::class)->act($application, $hrmo, [
            'action' => 'verify',
            'verified_working_days' => 1,
            'certifier_position' => 'Human Resource Officer',
            'certifier_signature' => 'HRMO Officer',
            'eligibility_verified' => true,
        ]);
        $verified = $application->fresh();
        if ($verified->status === 'Under Alternate Office Head Review'
            && $verified->office_head_reviewer_id === $approver->id) {
            app(LeaveWorkflowService::class)->act($verified, $approver, [
                'action' => 'endorse',
                'recommendation' => 'For approval',
            ]);
        } else {
            $officeHead = User::where('role', 'office_head')->first();
            if ($officeHead) {
                app(LeaveWorkflowService::class)->act($verified, $officeHead, [
                    'action' => 'endorse',
                    'recommendation' => 'For approval',
                ]);
            }
        }
        app(LeaveWorkflowService::class)->act($application->fresh(), $approver, [
            'action' => 'approve',
            'days_with_pay' => 1,
            'days_without_pay' => 0,
        ]);

        $this->assertSame('Approved', $application->fresh()->status);
        $this->assertSame(0, LeaveCreditTransaction::where('leave_application_id', $application->id)->count());
        $this->assertSame(0.0, (float) $employee->leaveCredits()->whereHas('leaveType', fn ($query) => $query->where('code', 'VACATION'))->firstOrFail()->used_credits);
        $this->assertSame(0.0, (float) $employee->leaveCredits()->whereHas('leaveType', fn ($query) => $query->where('code', 'SICK'))->firstOrFail()->used_credits);
        $this->assertSame(4.0, app(EmployeeLeaveEntitlementSummary::class)->forYear($employee)
            ->firstWhere('type.code', 'WELLNESS')['available']);
    }

    private function employee(string $employeeNo, ?string $dateHired, string $status = 'Active'): Employee
    {
        return Employee::create([
            'department_id' => $this->department->id,
            'employee_no' => $employeeNo,
            'first_name' => 'Accrual',
            'last_name' => 'Test',
            'employment_status' => $status,
            'employment_type' => 'Permanent',
            'date_hired' => $dateHired,
        ]);
    }
}
