@extends('layouts.app')
@section('title', 'Reports')
@section('content')
    <div class="page-heading"><div><span class="eyebrow">REPORTS & ANALYTICS</span><h1>Reports</h1><p>Filter attendance and leave activity within your authorized access scope.</p></div><button class="button button-outline" onclick="window.print()">Print this report</button></div>
    <header class="report-print-header">
        <img src="{{ asset('image/hrislogo.jpg') }}" alt="Official Municipality of Camalaniugan Human Resource Management Office seal">
        <div><strong>Municipality of Camalaniugan</strong><span>Human Resource Management Office</span><span>Province of Cagayan, Philippines</span></div>
        <div class="report-print-meta"><strong>Human Resources Report</strong><span>Reporting period: {{ \Carbon\Carbon::parse($from)->format('M j, Y') }} – {{ \Carbon\Carbon::parse($to)->format('M j, Y') }}</span><span>Generated: {{ now()->format('M j, Y g:i A') }}</span></div>
    </header>
    <section class="panel">
        <form class="filter-bar report-filters" method="GET">
            <label>From<input type="date" name="date_from" value="{{ request('date_from', $from) }}"></label><label>To<input type="date" name="date_to" value="{{ request('date_to', $to) }}"></label>
            @if(!auth()->user()->isOfficeHead())<select name="department_id"><option value="">All departments</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected(request('department_id') == $department->id)>{{ $department->name }}</option>@endforeach</select>@endif
            <select name="employee_id"><option value="">All employees</option>@foreach($employees as $employee)<option value="{{ $employee->id }}" @selected(request('employee_id') == $employee->id)>{{ $employee->employee_no }} · {{ $employee->full_name }}</option>@endforeach</select>
            <select name="attendance_status"><option value="">All attendance statuses</option>@foreach(['Present','Late','Absent','On Leave','Half Day','Rest Day','Holiday'] as $status)<option @selected(request('attendance_status') === $status)>{{ $status }}</option>@endforeach</select>
            <select name="application_status"><option value="">All leave statuses</option>@foreach(['Submitted','Endorsed','Returned for Correction','Pending Final Approval','Approved','Disapproved'] as $status)<option @selected(request('application_status') === $status)>{{ $status }}</option>@endforeach</select>
            <button type="submit" class="button button-primary">Apply filters</button>
        </form>
        <div class="report-summary"><div><small>ATTENDANCE RECORDS</small><b>{{ $attendanceCount }}</b></div><div><small>LEAVE APPLICATIONS</small><b>{{ $leaveCount }}</b></div><div><small>REPORTING PERIOD</small><b>{{ \Carbon\Carbon::parse($from)->format('M j') }} – {{ \Carbon\Carbon::parse($to)->format('M j, Y') }}</b></div></div>
    </section>
    <section class="panel panel-spaced report-panel"><div class="panel-heading"><div><h2>Attendance report</h2><p>Present, late, absence, and time records.</p></div><a class="button button-outline" href="{{ route('reports.csv', ['type'=>'attendance'] + request()->query()) }}">Download CSV / Excel</a></div>
        <div class="table-wrap"><table><thead><tr><th>EMPLOYEE</th><th>DEPARTMENT</th><th>DATE</th><th>TIME IN</th><th>TIME OUT</th><th>LATE</th><th>UNDERTIME</th><th>STATUS</th></tr></thead><tbody>@forelse($attendance as $record)<tr><td>{{ $record->employee->full_name }}</td><td>{{ $record->employee->department->name }}</td><td>{{ $record->work_date->format('M j, Y') }}</td><td>{{ $record->time_in ?? '—' }}</td><td>{{ $record->time_out ?? '—' }}</td><td>{{ $record->late_minutes }}</td><td>{{ $record->undertime_minutes }}</td><td>{{ $record->status }}</td></tr>@empty<tr><td colspan="8" class="empty-note">No attendance records for this report.</td></tr>@endforelse</tbody></table></div><div class="pagination-wrap">{{ $attendance->links() }}</div>
    </section>
    <section class="panel panel-spaced report-panel"><div class="panel-heading"><div><h2>Leave application report</h2><p>Application status and inclusive dates.</p></div><a class="button button-outline" href="{{ route('reports.csv', ['type'=>'leave'] + request()->query()) }}">Download CSV / Excel</a></div>
        <div class="table-wrap"><table><thead><tr><th>EMPLOYEE</th><th>DEPARTMENT</th><th>LEAVE TYPE</th><th>DATES</th><th>DAYS</th><th>STATUS</th></tr></thead><tbody>@forelse($leaves as $leave)<tr><td>{{ $leave->employee->full_name }}</td><td>{{ $leave->employee->department->name }}</td><td>{{ $leave->leaveType->name }}</td><td>{{ $leave->start_date?->format('M j') }}{{ $leave->end_date?->ne($leave->start_date) ? ' – '.$leave->end_date?->format('M j, Y') : '' }}</td><td>{{ $leave->verified_working_days ?? $leave->working_days }}</td><td><span class="status-tag">{{ $leave->status }}</span></td></tr>@empty<tr><td colspan="6" class="empty-note">No leave applications for this report.</td></tr>@endforelse</tbody></table></div><div class="pagination-wrap">{{ $leaves->links() }}</div>
    </section>
    @can('create', App\Models\Employee::class)<div class="panel panel-spaced"><div class="panel-heading"><div><h2>Employee master list</h2><p>Export an employee directory without salary or identification references.</p></div><a class="button button-outline" href="{{ route('reports.csv', ['type'=>'employees'] + request()->query()) }}">Download employee CSV</a></div></div>@endcan
@endsection
