<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\LeaveCredit;
use App\Models\LeaveCreditTransaction;
use App\Models\LeaveType;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LeaveCreditController extends Controller
{
    public function index(Request $request): View
    {
        $query = LeaveCredit::with(['employee.department', 'leaveType', 'transactions.createdBy']);
        if ($request->filled('q')) {
            $search = $request->string('q')->toString();
            $query->whereHas('employee', fn ($employee) => $employee->where('employee_no', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"));
        }

        return view('leave-credits.index', [
            'credits' => $query->latest()->paginate(25)->withQueryString(),
            'employees' => Employee::whereNull('archived_at')->orderBy('last_name')->get(),
            'leaveTypes' => LeaveType::where('is_active', true)->orderBy('sort_order')->get(),
        ]);
    }

    public function store(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'leave_type_id' => ['required', 'exists:leave_types,id'],
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
            if ($data['type'] === 'earned') {
                $credit->earned_credits = (float) $credit->earned_credits + (float) $data['amount'];
            } else {
                $credit->adjustments = (float) $credit->adjustments + (float) $data['amount'];
            }
            if ($credit->balance < 0) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'amount' => 'This transaction would make the leave credit balance negative.',
                ]);
            }
            $credit->save();
            LeaveCreditTransaction::create([
                'leave_credit_id' => $credit->id,
                'created_by' => $request->user()->id,
                'type' => $data['type'],
                'amount' => $data['amount'],
                'balance_after' => $credit->balance,
                'effective_date' => $data['effective_date'],
                'remarks' => $data['remarks'],
            ]);
        });
        $auditLogger->record('leave_credit.adjusted', $credit, ['type' => $data['type'], 'amount' => $data['amount']]);

        return back()->with('success', 'Leave credit transaction recorded.');
    }
}
