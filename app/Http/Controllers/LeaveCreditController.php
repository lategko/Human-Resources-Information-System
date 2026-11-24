<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\LeaveCredit;
use App\Models\LeaveCreditTransaction;
use App\Models\Department;
use App\Models\LeaveType;
use App\Services\AuditLogger;
use App\Services\LeaveCreditAccountService;
use App\Services\EmployeeLeaveEntitlementSummary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LeaveCreditController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'leave_type_id' => ['nullable', 'integer', Rule::exists('leave_types', 'id')],
            'employment_status' => ['nullable', 'string', 'max:40'],
        ]);
        $user = $request->user();
        $mine = $request->boolean('mine') || ! $user->canManageHr();
        $relations = ['employee.department', 'leaveType'];
        if (! $mine) {
            $relations[] = 'transactions.createdBy';
        } else {
            $relations[] = 'transactions';
        }
        $query = LeaveCredit::with($relations);
        if ($mine) {
            $employee = $user->employee;
            abort_unless($employee, 403, 'Your account is not linked to an employee record.');
            app(LeaveCreditAccountService::class)->ensureRegularAccounts($employee);
            $query->where('employee_id', $employee->id);
        }
        if (isset($filters['q'])) {
            $search = $filters['q'];
            $query->whereHas('employee', fn ($employee) => $employee->where('employee_no', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"));
        }
        if (isset($filters['department_id'])) {
            $query->whereHas('employee', fn ($employee) => $employee->where('department_id', $filters['department_id']));
        }
        if (isset($filters['leave_type_id'])) {
            $query->where('leave_type_id', $filters['leave_type_id']);
        }
        if (isset($filters['employment_status'])) {
            $query->whereHas('employee', fn ($employee) => $employee->where('employment_status', $filters['employment_status']));
        }

        return view('leave-credits.index', [
            'credits' => $query->latest()->paginate(25)->withQueryString(),
            'isPersonalView' => $mine,
            'history' => $mine
                ? $user->employee->leaveApplications()->with('leaveType')->latest()->limit(50)->get()
                : collect(),
            'specialEntitlements' => $mine
                ? app(EmployeeLeaveEntitlementSummary::class)->forYear($user->employee)
                : collect(),
            'selectedEmployee' => ! $mine && $user->canManageHr() && old('employee_id')
                ? Employee::whereNull('archived_at')->find((int) old('employee_id'))
                : null,
            'leaveTypes' => ! $mine && $user->canManageHr()
                ? LeaveType::where('is_active', true)->orderBy('sort_order')->get()
                : collect(),
            'departments' => ! $mine && $user->canManageHr()
                ? Department::where('is_active', true)->orderBy('name')->get()
                : collect(),
            'employmentStatuses' => ! $mine && $user->canManageHr()
                ? Employee::query()->whereNull('archived_at')->distinct()->orderBy('employment_status')->pluck('employment_status')
                : collect(),
        ]);
    }

    public function store(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->whereNull('archived_at')->whereNull('deleted_at')],
            'leave_type_id' => [
                'required',
                'integer',
                Rule::exists('leave_types', 'id')->where('is_active', true),
                Rule::unique('leave_credits', 'leave_type_id')->where('employee_id', $request->input('employee_id')),
            ],
            'beginning_balance' => ['required', 'numeric', 'min:0', 'max:999999'],
            'effective_date' => ['required', 'date'],
            'remarks' => ['required', 'string', 'max:2000'],
        ]);
        $credit = DB::transaction(function () use ($data, $request) {
            $credit = LeaveCredit::create([
                'employee_id' => $data['employee_id'],
                'leave_type_id' => $data['leave_type_id'],
                'beginning_balance' => $data['beginning_balance'],
                'effective_date' => $data['effective_date'],
            ]);
            LeaveCreditTransaction::create([
                'leave_credit_id' => $credit->id,
                'created_by' => $request->user()->id,
                'type' => 'beginning_balance',
                'amount' => $data['beginning_balance'],
                'previous_balance' => 0,
                'balance_after' => $credit->balance,
                'effective_date' => $data['effective_date'],
                'remarks' => $data['remarks'],
            ]);

            return $credit;
        });
        $auditLogger->record('leave_credit.created', $credit);

        return back()->with('success', 'Leave credit record created.');
    }

    public function adjust(Request $request, LeaveCredit $credit, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['earned', 'adjustment'])],
            'amount' => ['required', 'numeric', 'between:-999999,999999', 'not_in:0'],
            'effective_date' => ['required', 'date'],
            'remarks' => ['required', 'string', 'max:2000'],
        ]);
        abort_if($data['type'] === 'earned' && (float) $data['amount'] < 0, 422, 'Earned credits cannot be negative.');

        DB::transaction(function () use ($credit, $data, $request) {
            $credit = LeaveCredit::query()->lockForUpdate()->findOrFail($credit->id);
            $previousBalance = $credit->balance;
            if ($data['type'] === 'earned') {
                $credit->earned_credits = (float) $credit->earned_credits + (float) $data['amount'];
            } else {
                $credit->adjustments = (float) $credit->adjustments + (float) $data['amount'];
            }
            if ($credit->balance < 0) {
                throw ValidationException::withMessages([
                    'amount' => 'This transaction would make the leave credit balance negative.',
                ]);
            }
            $credit->save();
            LeaveCreditTransaction::create([
                'leave_credit_id' => $credit->id,
                'created_by' => $request->user()->id,
                'type' => $data['type'],
                'amount' => $data['amount'],
                'previous_balance' => $previousBalance,
                'balance_after' => $credit->balance,
                'effective_date' => $data['effective_date'],
                'remarks' => $data['remarks'],
            ]);
        });
        $auditLogger->record('leave_credit.adjusted', $credit, ['type' => $data['type'], 'amount' => $data['amount']]);

        return back()->with('success', 'Leave credit transaction recorded.');
    }
}
