@extends('layouts.app')
@section('title', 'Deduction monitoring')
@section('content')
    <div class="page-heading"><div><span class="eyebrow">PAYROLL ATTENDANCE MONITORING</span><h1>Estimated deductions</h1><p>Attendance-related flags require HR review. No salary is automatically deducted by this prototype.</p></div></div>
    <div class="alert alert-info"><b>No payroll formula is assumed.</b> Enter an estimate and its approved calculation basis only after checking current government and municipal payroll policy. A final approval here is a monitoring record only; it does not transmit or deduct from payroll.</div>
    <section class="panel">
        <form class="filter-bar" method="GET"><label>Month<input type="month" name="month" value="{{ request('month', now()->format('Y-m')) }}"></label>@if(!auth()->user()->isOfficeHead())<select name="department_id"><option value="">All departments</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected(request('department_id') == $department->id)>{{ $department->name }}</option>@endforeach</select>@endif<button class="button button-outline">Filter</button></form>
        <div class="table-wrap"><table><thead><tr><th>EMPLOYEE</th><th>DATE</th><th>ATTENDANCE</th><th>LATE</th><th>UNDERTIME</th><th>ESTIMATE</th><th>REVIEW</th><th></th></tr></thead><tbody>
        @forelse($records as $record)
            <tr><td><b>{{ $record->employee->full_name }}</b><small class="table-sub">{{ $record->employee->department->name }}</small></td><td>{{ $record->work_date->format('M j, Y') }}</td><td>{{ $record->display_status }}</td><td>{{ $record->late_minutes }} min</td><td>{{ $record->undertime_minutes }} min</td><td>{{ $record->deductionRecord?->estimated_amount !== null ? '₱'.number_format((float)$record->deductionRecord->estimated_amount, 2) : 'Not calculated' }}</td><td><span class="status-tag">{{ $record->deductionRecord?->review_status ?? 'Needs review' }}</span></td><td>
                @if(auth()->user()->canManageHr() || auth()->user()->isAdministrator())
                    <details><summary class="table-link">Review</summary><form method="POST" action="{{ route('deductions.review', $record) }}" class="correction-form">@csrf
                        <label>Estimated amount (optional)<input type="number" step=".01" min="0" name="estimated_amount" value="{{ $record->deductionRecord?->estimated_amount }}"></label>
                        <label>Calculation breakdown / policy basis *<textarea rows="3" name="calculation_breakdown" required>{{ $record->deductionRecord?->calculation_breakdown['reviewer_entered_breakdown'] ?? '' }}</textarea></label>
                        <label>Remarks *<textarea rows="2" name="remarks" required>{{ $record->deductionRecord?->remarks }}</textarea></label><button class="button button-primary">Save HR review</button>
                    </form></details>
                @endif
            </td></tr>
            @if(auth()->user()->isAdministrator() && $record->deductionRecord?->review_status === 'Reviewed estimate')
                <tr><td colspan="8"><form method="POST" action="{{ route('deductions.approve', $record->deductionRecord) }}" class="approval-inline">@csrf<label>Amount approved for payroll review<input type="number" min="0" step=".01" name="approved_amount" value="{{ $record->deductionRecord->estimated_amount }}" required></label><label>Approval remarks<input name="remarks" required></label><button class="button button-outline">Approve for payroll review</button></form></td></tr>
            @endif
        @empty<tr><td colspan="8" class="empty-note">No late, undertime, or absent attendance flags in this period.</td></tr>@endforelse
        </tbody></table></div><div class="pagination-wrap">{{ $records->links() }}</div>
    </section>
@endsection
