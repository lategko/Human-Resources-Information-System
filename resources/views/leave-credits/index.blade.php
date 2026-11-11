@extends('layouts.app')
@section('title', 'Leave credits')
@section('content')
    <div class="page-heading"><div><span class="eyebrow">{{ auth()->user()->isEmployee() ? 'MY HR RECORDS' : 'LEAVE CREDIT MANAGEMENT' }}</span><h1>{{ auth()->user()->isEmployee() ? 'My leave credits' : 'Leave credits' }}</h1><p>{{ auth()->user()->isEmployee() ? 'Your current leave balances as recorded by HR.' : 'Authorized HRMO transactions and balances. Every change is recorded in the ledger.' }}</p></div></div>
    @if(auth()->user()->isEmployee())
        <section class="panel"><div class="table-wrap"><table><thead><tr><th>LEAVE TYPE</th><th>BEGINNING</th><th>EARNED</th><th>USED</th><th>ADJUSTMENTS</th><th>AVAILABLE</th></tr></thead><tbody>
            @forelse($credits as $credit)<tr><td><b>{{ $credit->leaveType->name }}</b></td><td>{{ number_format((float) $credit->beginning_balance, 2) }}</td><td>{{ number_format((float) $credit->earned_credits, 2) }}</td><td>{{ number_format((float) $credit->used_credits, 2) }}</td><td>{{ number_format((float) $credit->adjustments, 2) }}</td><td><b>{{ number_format($credit->balance, 2) }}</b></td></tr>@empty<tr><td colspan="6" class="empty-note">No leave credit balances are recorded yet.</td></tr>@endforelse
        </tbody></table></div><div class="pagination-wrap">{{ $credits->links() }}</div></section>
    @else
    <div class="content-grid">
        <section class="panel"><form class="filter-bar" method="GET"><label class="search-field"><span>⌕</span><input name="q" value="{{ request('q') }}" placeholder="Employee ID or last name"></label><button class="button button-outline">Search</button></form>
            <div class="table-wrap"><table><thead><tr><th>EMPLOYEE</th><th>LEAVE TYPE</th><th>BEGINNING</th><th>EARNED</th><th>USED</th><th>ADJUSTMENTS</th><th>BALANCE</th><th></th></tr></thead><tbody>
                @forelse($credits as $credit)<tr><td><b>{{ $credit->employee->full_name }}</b><small class="table-sub">{{ $credit->employee->employee_no }}</small></td><td>{{ $credit->leaveType->name }}</td><td>{{ number_format((float)$credit->beginning_balance,2) }}</td><td>{{ number_format((float)$credit->earned_credits,2) }}</td><td>{{ number_format((float)$credit->used_credits,2) }}</td><td>{{ number_format((float)$credit->adjustments,2) }}</td><td><b>{{ number_format($credit->balance,2) }}</b></td><td><details><summary class="table-link">Ledger / adjust</summary><div class="ledger-list">@forelse($credit->transactions as $transaction)<p><b>{{ ucfirst(str_replace('_',' ',$transaction->type)) }} · {{ number_format((float)$transaction->amount,2) }}</b><small>{{ $transaction->effective_date->format('M j, Y') }} · {{ $transaction->createdBy?->name ?? 'System' }}</small><small>{{ $transaction->remarks }}</small></p>@empty<p>No transactions recorded.</p>@endforelse</div><form method="POST" action="{{ route('leave-credits.adjust', $credit) }}" class="correction-form">@csrf
                    <label>Transaction<select name="type"><option value="earned">Earned credits</option><option value="adjustment">Adjustment</option></select></label><label>Amount (negative allowed for correction)<input type="number" step=".01" name="amount" required></label><label>Effective date<input type="date" name="effective_date" value="{{ today()->format('Y-m-d') }}" required></label><label>Remarks *<textarea name="remarks" rows="2" required></textarea></label><button class="button button-primary">Record transaction</button>
                </form></details></td></tr>@empty<tr><td colspan="8" class="empty-note">No leave credit records found.</td></tr>@endforelse
            </tbody></table></div><div class="pagination-wrap">{{ $credits->links() }}</div>
        </section>
        <aside class="panel"><div class="panel-heading"><div><h2>Create a credit account</h2><p>HR must verify the beginning balance and policy basis.</p></div></div>
            <form method="POST" action="{{ route('leave-credits.store') }}" class="form-stack">@csrf
                <label>Employee *<select name="employee_id" required><option value="">Select employee</option>@foreach($employees as $employee)<option value="{{ $employee->id }}">{{ $employee->employee_no }} · {{ $employee->full_name }}</option>@endforeach</select></label>
                <label>Leave type *<select name="leave_type_id" required><option value="">Select leave type</option>@foreach($leaveTypes as $type)<option value="{{ $type->id }}">{{ $type->name }}</option>@endforeach</select></label>
                <label>Beginning balance (days) *<input type="number" min="0" step=".01" name="beginning_balance" required></label><label>Effective date *<input type="date" name="effective_date" required></label><label>Basis / remarks *<textarea name="remarks" rows="3" required></textarea></label><button class="button button-primary">Create credit account</button>
            </form>
        </aside>
    </div>
    @endif
@endsection
