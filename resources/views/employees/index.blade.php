@extends('layouts.app')
@section('title', 'Employees')
@section('content')
    <div class="page-heading"><div><span class="eyebrow">EMPLOYEE DIRECTORY</span><h1>Employees</h1><p>Search and review employee records within your access scope.</p></div>
        @can('create', App\Models\Employee::class)<a class="button button-primary" href="{{ route('employees.create') }}">＋ Add employee</a>@endcan
    </div>
    <section class="panel">
        <form class="filter-bar" method="GET">
            <label class="search-field"><span>⌕</span><input type="search" name="q" value="{{ request('q') }}" placeholder="Search name or employee ID"></label>
            @if(!auth()->user()->isOfficeHead())
                <select name="department_id" aria-label="Filter by department"><option value="">All departments</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected(request('department_id') == $department->id)>{{ $department->name }}</option>@endforeach</select>
            @endif
            <select name="employment_status" aria-label="Filter by employment status"><option value="">All statuses</option>@foreach(['Active','On Leave','Separated','Retired'] as $status)<option @selected(request('employment_status') === $status)>{{ $status }}</option>@endforeach</select>
            <button class="button button-outline" type="submit">Filter</button>
        </form>
        <div class="table-wrap"><table><thead><tr><th>EMPLOYEE</th><th>DEPARTMENT</th><th>POSITION</th><th>STATUS</th><th></th></tr></thead><tbody>
        @forelse($employees as $employee)
            <tr><td><a class="employee-cell" href="{{ route('employees.show', $employee) }}"><span class="avatar avatar-small">{{ strtoupper(substr($employee->first_name,0,1)) }}</span><span><b>{{ $employee->full_name }}</b><small>{{ $employee->employee_no }}</small></span></a></td><td>{{ $employee->department->name }}</td><td>{{ $employee->position?->title ?? '—' }}</td><td><span class="status-tag status-{{ \Illuminate\Support\Str::slug($employee->employment_status) }}">{{ $employee->employment_status }}</span></td><td><a class="table-link" href="{{ route('employees.show', $employee) }}">View →</a></td></tr>
        @empty<tr><td colspan="5"><div class="empty-note">No employee records match these filters.</div></td></tr>@endforelse
        </tbody></table></div>
        <div class="pagination-wrap">{{ $employees->links() }}</div>
    </section>
@endsection
