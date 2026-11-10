@extends('layouts.app')
@section('title', 'Attendance')
@section('content')
    @php($scopeRecords = $records->getCollection())
    <div class="page-heading"><div><span class="eyebrow">TIME AND ATTENDANCE</span><h1>{{ auth()->user()->isEmployee() ? 'My attendance' : 'Attendance monitoring' }}</h1><p>Review timestamp records. Late/undertime calculations require approved municipal work schedules.</p></div></div>
    <section class="panel">
        <form class="filter-bar" method="GET"><label>Work date<input type="date" name="date" value="{{ request('date') }}"></label>
            @if(!auth()->user()->isOfficeHead() && !auth()->user()->isEmployee())<select name="department_id"><option value="">All departments</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected(request('department_id') == $department->id)>{{ $department->name }}</option>@endforeach</select>@endif
            <label class="search-field"><span>⌕</span><input name="q" value="{{ request('q') }}" placeholder="Employee ID or last name"></label>
            <select name="status"><option value="">All attendance statuses</option>@foreach(['Present','Late','Absent','On Leave','Half Day','Rest Day','Holiday'] as $status)<option @selected(request('status') === $status)>{{ $status }}</option>@endforeach</select>
            <button type="submit" class="button button-outline">Filter</button>
        </form>
        @if($scopeRecords->isNotEmpty())
            <div class="attendance-summary"><div><small>RECORDS IN THIS PAGE</small><b>{{ $scopeRecords->count() }}</b></div><div><small>LATE MINUTES</small><b>{{ $scopeRecords->sum('late_minutes') }}</b></div><div><small>UNDERTIME MINUTES</small><b>{{ $scopeRecords->sum('undertime_minutes') }}</b></div><div><small>ABSENCES RECORDED</small><b>{{ $scopeRecords->where('status','Absent')->count() }}</b></div></div>
        @endif
        <div class="table-wrap"><table><thead><tr>@if(!auth()->user()->isEmployee())<th>EMPLOYEE</th>@endif<th>DATE</th><th>TIME IN</th><th>TIME OUT</th><th>HOURS</th><th>LATE</th><th>UNDERTIME</th><th>STATUS</th>@if(auth()->user()->canManageHr() || auth()->user()->isAdministrator())<th>REVIEW</th>@endif</tr></thead><tbody>
        @forelse($records as $record)
            <tr>=
                @if(!auth()->user()->isEmployee())<td><a class="employee-cell" href="{{ route('employees.show', $record->employee) }}"><span class="avatar avatar-small">{{ strtoupper(substr($record->employee->first_name,0,1)) }}</span><span><b>{{ $record->employee->full_name }}</b><small>{{ $record->employee->department->name }}</small></span></a></td>@endif
                <td>{{ $record->work_date->format('M j, Y') }}</td><td>{{ $record->time_in ? substr($record->time_in,0,5) : '—' }}</td><td>{{ $record->time_out ? substr($record->time_out,0,5) : '—' }}</td><td>{{ $record->total_working_hours ?? '—' }}</td><td>{{ $record->late_minutes }} min</td><td>{{ $record->undertime_minutes }} min</td><td><span class="status-tag status-{{ \Illuminate\Support\Str::slug($record->status) }} {{ $record->corrected_by ? 'status-corrected' : '' }}" @if($record->corrected_by) title="Corrected by an authorized HR user; audit record retained" @endif>{{ $record->corrected_by ? 'Corrected · ' : '' }}{{ $record->status }}</span></td>
                @if(auth()->user()->canManageHr() || auth()->user()->isAdministrator())<td><details class="correction-details"><summary>Correct</summary><form method="POST" action="{{ route('attendance.correct', $record) }}" class="correction-form">@csrf
                    <label>Status<select name="status">@foreach(['Present','Late','Absent','On Leave','Half Day','Rest Day','Holiday'] as $status)<option @selected($record->status === $status)>{{ $status }}</option>@endforeach</select></label>
                    <label>Time in<input type="time" name="time_in" value="{{ $record->time_in ? substr($record->time_in,0,5) : '' }}"></label><label>Time out<input type="time" name="time_out" value="{{ $record->time_out ? substr($record->time_out,0,5) : '' }}"></label>
                    <label>Late minutes<input type="number" min="0" name="late_minutes" value="{{ $record->late_minutes }}" required></label><label>Undertime minutes<input type="number" min="0" name="undertime_minutes" value="{{ $record->undertime_minutes }}" required></label>
                    <label>Correction remarks *<textarea name="correction_remarks" required rows="2"></textarea></label><button class="button button-primary">Save correction</button>
                </form></details>@endif
            </tr>
        @empty<tr><td colspan="9" class="empty-note">No attendance records match the selected date and filters.</td></tr>@endforelse
        </tbody></table></div><div class="pagination-wrap">{{ $records->links() }}</div>
    </section>
    @if(auth()->user()->canManageHr() || auth()->user()->isAdministrator())
        <div class="content-grid">
            <section class="panel"><div class="panel-heading"><div><h2>Import biometric CSV</h2><p>Import timestamps only; this prototype does not connect to a biometric device.</p></div></div>
                <div class="info-note">Required CSV headers: <code>biometric_id,date,time_in,time_out</code>. Date uses YYYY-MM-DD. Existing and duplicate employee/date rows are reported without overwriting records.</div>
                <form method="POST" action="{{ route('attendance.import') }}" enctype="multipart/form-data" class="form-stack top-space">@csrf
                    <label>CSV file *<input type="file" name="file" accept=".csv,text/csv" required></label>
                    <div class="form-actions"><a class="button button-outline" href="{{ route('attendance.template') }}">Download CSV template</a><button type="submit" class="button button-primary">Validate and import</button></div>
                </form>
            </section>
            <section class="panel"><div class="panel-heading"><div><h2>Recent imports</h2><p>Import summary and row-level validation issues.</p></div></div>
                @forelse($imports as $import)<a class="application-row" href="{{ route('attendance.imports.show', $import) }}"><span class="row-date">#{{ $import->id }}</span><span class="row-main"><b>{{ $import->original_name }}</b><small>{{ $import->imported_count }} imported · {{ $import->error_count }} errors</small></span><span class="status-tag">{{ str_replace('_',' ',$import->status) }}</span></a>@empty<p class="empty-note">No biometric imports yet.</p>@endforelse
            </section>
        </div>
    @endif
@endsection
