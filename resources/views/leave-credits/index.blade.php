@extends('layouts.app')
@section('title', 'Leave credits')
@section('content')
    <div class="page-heading"><div><span class="eyebrow">{{ $isPersonalView ? 'MY HR RECORDS' : 'LEAVE CREDIT MANAGEMENT' }}</span><h1>{{ $isPersonalView ? 'My leave credits' : 'Leave credits' }}</h1><p>{{ $isPersonalView ? 'Your current leave balances as recorded by HR.' : 'Authorized Admin transactions and balances. Every change is recorded in the ledger.' }}</p></div></div>
    @if($isPersonalView)
        <div class="content-grid dashboard-content-grid">
            @foreach($credits->getCollection()->whereIn('leaveType.code', ['VACATION', 'SICK']) as $credit)
                <section class="panel">
                    <div class="panel-heading"><div><h2>{{ $credit->leaveType->name }}</h2><p>Leave credits in days · one credit equals one leave day</p></div></div>
                    <div class="credit-row"><span><b>Beginning balance</b></span><strong>{{ number_format((float) $credit->beginning_balance, 2) }}</strong></div>
                    <div class="credit-row"><span><b>Total credits earned</b><small>Includes recorded monthly accruals</small></span><strong>{{ number_format((float) $credit->earned_credits, 2) }}</strong></div>
                    <div class="credit-row"><span><b>Total credits used</b></span><strong>{{ number_format((float) $credit->used_credits, 2) }}</strong></div>
                    <div class="credit-row"><span><b>Adjustments</b></span><strong>{{ number_format((float) $credit->adjustments, 2) }}</strong></div>
                    <div class="credit-row"><span><b>Available balance</b></span><strong>{{ number_format($credit->balance, 2) }} <small>days</small></strong></div>
                    <details class="ledger-details"><summary class="table-link">Monthly accrual and transaction history</summary><div class="ledger-list">
                        @forelse($credit->transactions as $transaction)
                            <p><b>{{ ucfirst(str_replace('_', ' ', $transaction->type)) }} · {{ number_format((float) $transaction->amount, 2) }}</b><small>{{ $transaction->effective_date->format('M j, Y') }}{{ $transaction->accrual_month ? ' · Accrual month: '.$transaction->accrual_month->format('F Y') : '' }}</small><small>Balance {{ number_format((float) $transaction->previous_balance, 2) }} → {{ number_format((float) $transaction->balance_after, 2) }}</small><small>{{ $transaction->remarks }}</small></p>
                        @empty<p>No transactions have been recorded.</p>@endforelse
                    </div></details>
                </section>
            @endforeach
        </div>
        <section class="panel"><div class="panel-heading"><div><h2>Leave application history</h2><p>Your submitted requests and recorded decisions</p></div><a class="button button-primary" href="{{ route('leave.create') }}">Apply for leave</a></div>
            <div class="table-wrap"><table><thead><tr><th>LEAVE TYPE</th><th>DATES</th><th>DAYS</th><th>STATUS</th><th>FILED</th></tr></thead><tbody>
                @forelse($history as $application)<tr><td><a class="table-link" href="{{ route('leave.show', $application) }}">{{ $application->leaveType->name }}</a></td><td>{{ $application->start_date?->format('M j, Y') }} – {{ $application->end_date?->format('M j, Y') }}</td><td>{{ number_format((float) ($application->verified_working_days ?? $application->working_days ?? 0), 2) }}</td><td><span class="status-tag status-{{ \Illuminate\Support\Str::slug($application->status) }}">{{ $application->status }}</span></td><td>{{ $application->filed_at?->format('M j, Y') }}</td></tr>@empty<tr><td colspan="5" class="empty-note">No leave applications have been recorded.</td></tr>@endforelse
            </tbody></table></div>
        </section>
        @if($specialEntitlements->isNotEmpty())
            <section class="panel"><div class="panel-heading"><div><h2>Annual special leave entitlements</h2><p>Configured entitlements are shown separately and do not draw from VL or SL.</p></div></div>
                @foreach($specialEntitlements as $summary)
                    <div class="credit-row"><span><b>{{ $summary['type']->name }}</b><small>{{ $summary['application_count'] }} pending or approved application(s) counted for {{ $summary['year'] }}</small></span><strong>{{ number_format($summary['available'], 2) }} <small>of {{ number_format($summary['entitlement'], 2) }} days available</small></strong></div>
                @endforeach
            </section>
        @endif
        <section class="panel"><div class="table-wrap"><table><thead><tr><th>LEAVE TYPE</th><th>BEGINNING</th><th>EARNED</th><th>USED</th><th>ADJUSTMENTS</th><th>AVAILABLE</th></tr></thead><tbody>
            @forelse($credits as $credit)<tr><td><b>{{ $credit->leaveType->name }}</b></td><td>{{ number_format((float) $credit->beginning_balance, 2) }}</td><td>{{ number_format((float) $credit->earned_credits, 2) }}</td><td>{{ number_format((float) $credit->used_credits, 2) }}</td><td>{{ number_format((float) $credit->adjustments, 2) }}</td><td><b>{{ number_format($credit->balance, 2) }}</b></td></tr>@empty<tr><td colspan="6" class="empty-note">No leave credit balances are recorded yet.</td></tr>@endforelse
        </tbody></table></div><div class="pagination-wrap">{{ $credits->links() }}</div></section>
    @else
    <div class="content-grid">
        <section class="panel"><form class="filter-bar" method="GET">
            <label class="search-field"><span>⌕</span><input name="q" value="{{ request('q') }}" placeholder="Employee ID or last name"></label>
            <label>Department<select name="department_id"><option value="">All departments</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected((string)request('department_id') === (string)$department->id)>{{ $department->name }}</option>@endforeach</select></label>
            <label>Leave type<select name="leave_type_id"><option value="">All types</option>@foreach($leaveTypes as $type)<option value="{{ $type->id }}" @selected((string)request('leave_type_id') === (string)$type->id)>{{ $type->name }}</option>@endforeach</select></label>
            <label>Employment status<select name="employment_status"><option value="">All statuses</option>@foreach($employmentStatuses as $status)<option value="{{ $status }}" @selected(request('employment_status') === $status)>{{ $status }}</option>@endforeach</select></label>
            <button class="button button-outline">Apply filters</button>
        </form>
            <p class="panel-actions"><a class="text-link" href="{{ route('reports.leave-credit.csv', ['report' => 'balances']) }}">Export balance summary</a> · <a class="text-link" href="{{ route('reports.leave-credit.csv', ['report' => 'ledger']) }}">Export ledger</a> · <a class="text-link" href="{{ route('reports.leave-credit.csv', ['report' => 'accrual']) }}">Export monthly accruals</a></p>
            <div class="table-wrap"><table><thead><tr><th>EMPLOYEE</th><th>LEAVE TYPE</th><th>BEGINNING</th><th>EARNED</th><th>USED</th><th>ADJUSTMENTS</th><th>BALANCE</th><th></th></tr></thead><tbody>
                @forelse($credits as $credit)<tr><td><b>{{ $credit->employee->full_name }}</b><small class="table-sub">{{ $credit->employee->employee_no }}</small></td><td>{{ $credit->leaveType->name }}</td><td>{{ number_format((float)$credit->beginning_balance,2) }}</td><td>{{ number_format((float)$credit->earned_credits,2) }}</td><td>{{ number_format((float)$credit->used_credits,2) }}</td><td>{{ number_format((float)$credit->adjustments,2) }}</td><td><b>{{ number_format($credit->balance,2) }}</b></td><td><details><summary class="table-link">Ledger / adjust</summary><div class="ledger-list">@forelse($credit->transactions as $transaction)<p><b>{{ ucfirst(str_replace('_',' ',$transaction->type)) }} · {{ number_format((float)$transaction->amount,2) }}</b><small>{{ $transaction->effective_date->format('M j, Y') }} · {{ $transaction->createdBy?->name ?? 'System' }}</small><small>{{ $transaction->remarks }}</small></p>@empty<p>No transactions recorded.</p>@endforelse</div><form method="POST" action="{{ route('leave-credits.adjust', $credit) }}" class="correction-form" data-confirm-type="warning" data-confirm-title="Record Leave Credit Transaction?" data-confirm-message="Record this {{ $credit->leaveType->name }} leave credit transaction? This adjustment is written to the employee’s permanent leave credit ledger." data-confirm-text="Record Transaction">@csrf
                    <label>Transaction<select name="type"><option value="earned">Earned credits</option><option value="adjustment">Adjustment</option></select></label><label>Amount (negative allowed for correction)<input type="number" step=".01" name="amount" required></label><label>Effective date<input type="date" name="effective_date" value="{{ today()->format('Y-m-d') }}" required></label><label>Remarks *<textarea name="remarks" rows="2" required></textarea></label><button class="button button-primary" data-loading-text="Recording…">Record transaction</button>
                </form></details></td></tr>@empty<tr><td colspan="8" class="empty-note">No leave credit records found.</td></tr>@endforelse
            </tbody></table></div><div class="pagination-wrap">{{ $credits->links() }}</div>
        </section>
        <aside class="panel"><div class="panel-heading"><div><h2>Create a credit account</h2><p>HR must verify the beginning balance and policy basis.</p></div></div>
            <form method="POST" action="{{ route('leave-credits.store') }}" class="form-stack" data-confirm-type="warning" data-confirm-title="Create Leave Credit Account?" data-confirm-message="Create this leave credit account with the employee, leave type, beginning balance, and stated policy basis?" data-confirm-text="Create Account">@csrf
                <label>Employee *<select name="employee_id" required><option value="">Select employee</option>@foreach($employees as $employee)<option value="{{ $employee->id }}">{{ $employee->employee_no }} · {{ $employee->full_name }}</option>@endforeach</select></label>
                <label>Leave type *<select name="leave_type_id" required><option value="">Select leave type</option>@foreach($leaveTypes as $type)<option value="{{ $type->id }}">{{ $type->name }}</option>@endforeach</select></label>
                <label>Beginning balance (days) *<input type="number" min="0" step=".01" name="beginning_balance" required></label><label>Effective date *<input type="date" name="effective_date" required></label><label>Basis / remarks *<textarea name="remarks" rows="3" required></textarea></label><button class="button button-primary">Create credit account</button>
            </form>
        </aside>
    </div>
    @endif
@endsection
