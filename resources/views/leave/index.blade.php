@extends('layouts.app')
@section('title', 'Leave applications')
@section('content')
    <div class="page-heading"><div><span class="eyebrow">LEAVE MANAGEMENT</span><h1>Leave applications</h1><p>Review application status and open an application to view its record.</p></div>
        @if(auth()->user()->employee)<a class="button button-primary" href="{{ route('leave.create') }}">＋ New application</a>@endif
    </div>
    <section class="panel">
        <form class="filter-bar" method="GET"><label class="search-field"><span>⌕</span><input name="q" value="{{ request('q') }}" placeholder="Employee ID or last name"></label>
            <select name="status"><option value="">All statuses</option>@foreach(['Draft','Submitted','Under Office Head Review','Under Alternate Office Head Review','Endorsed','Returned for Correction','Under HRMO Verification','Pending Final Approval','Pending Authorized Final Approval','Awaiting Alternate HR Reviewer Assignment','Awaiting Authorized Final Approver Assignment','Approved','Disapproved','Cancelled'] as $status)<option @selected(request('status') === $status)>{{ $status }}</option>@endforeach</select>
            <button type="submit" class="button button-outline">Filter</button>
        </form>
        <div class="table-wrap"><table><thead><tr>@if(!request()->boolean('mine') && !auth()->user()->isEmployee())<th>EMPLOYEE</th>@endif<th>LEAVE TYPE</th><th>DATES</th><th>DAYS</th><th>FILED</th><th>STATUS</th><th></th></tr></thead><tbody>
        @forelse($applications as $application)
            <tr>
                @if(!request()->boolean('mine') && !auth()->user()->isEmployee())<td><a class="employee-cell" href="{{ route('leave.show', $application) }}"><span class="avatar avatar-small">{{ strtoupper(substr($application->employee->first_name,0,1)) }}</span><span><b>{{ $application->employee->full_name }}</b><small>{{ $application->employee->employee_no }}</small></span></a></td>@endif
                <td>{{ $application->leaveType->name }}{{ $application->other_leave_type ? ' · '.$application->other_leave_type : '' }}</td>
                <td>{{ $application->start_date?->format('M j, Y') ?? 'Not set' }}{{ $application->end_date?->ne($application->start_date) ? ' – '.$application->end_date?->format('M j, Y') : '' }}</td>
                <td>{{ $application->verified_working_days ?? $application->working_days ?? '—' }}</td>
                <td>{{ $application->filed_at?->format('M j, Y') ?? $application->created_at->format('M j, Y') }}</td>
                <td><span class="status-tag status-{{ \Illuminate\Support\Str::slug($application->status) }}">{{ $application->status }}</span></td>
                <td><a class="table-link" href="{{ route('leave.show', $application) }}">Open →</a></td>
            </tr>
        @empty<tr><td colspan="7"><div class="empty-note">No applications found.</div></td></tr>@endforelse
        </tbody></table></div><div class="pagination-wrap">{{ $applications->links() }}</div>
    </section>
@endsection
