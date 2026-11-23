@extends('layouts.app')
@section('title', 'Positions')
@section('content')
    <div class="page-heading"><div><span class="eyebrow">ORGANIZATION</span><h1>Positions</h1><p>Maintain municipal position titles, department associations, and salary grades.</p></div></div>
    <div class="content-grid">
        <section class="panel"><div class="panel-heading"><div><h2>Position directory</h2><p>{{ $positions->total() }} position records</p></div></div>
            <form class="filter-bar directory-filters" method="GET" action="{{ route('positions.index') }}">
                <label class="search-field"><i data-lucide="search"></i><input type="search" name="q" value="{{ request('q') }}" placeholder="Search position or department"></label>
                <select name="department_id" aria-label="Filter by department"><option value="">All departments</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected(request('department_id') == $department->id)>{{ $department->name }}{{ $department->is_active ? '' : ' (Inactive)' }}</option>@endforeach</select>
                <select name="salary_grade" aria-label="Filter by salary grade"><option value="">All salary grades</option>@foreach($salaryGrades as $salaryGrade)<option value="{{ $salaryGrade }}" @selected(request('salary_grade') === $salaryGrade)>{{ $salaryGrade }}</option>@endforeach</select>
                <select name="status" aria-label="Filter by position status"><option value="">All statuses</option><option value="active" @selected(request('status') === 'active')>Active</option><option value="inactive" @selected(request('status') === 'inactive')>Inactive</option></select>
                <button class="button button-outline" type="submit"><i data-lucide="list-filter"></i> Filter</button>
                @if(request()->hasAny(['q', 'department_id', 'salary_grade', 'status']))<a class="button button-outline" href="{{ route('positions.index') }}">Reset</a>@endif
            </form>
            <div class="table-wrap"><table><thead><tr><th>POSITION</th><th>DEPARTMENT</th><th>SALARY GRADE</th><th>EMPLOYEES</th><th>STATUS</th></tr></thead><tbody>
            @forelse($positions as $position)<tr><td><b>{{ $position->title }}</b></td><td>{{ $position->department?->name ?? 'Any department' }}</td><td>{{ $position->salary_grade ?? '—' }}</td><td>{{ $position->employees_count }}</td><td><span class="status-tag {{ $position->is_active ? 'status-approved' : 'status-disapproved' }}">{{ $position->is_active ? 'Active' : 'Inactive' }}</span></td></tr>@empty<tr><td colspan="5" class="empty-note">No positions yet.</td></tr>@endforelse
            </tbody></table></div><div class="pagination-wrap">{{ $positions->links() }}</div>
        </section>
        <aside class="panel"><div class="panel-heading"><div><h2>Add position</h2><p>Position titles can be department-specific.</p></div></div>
            <form method="POST" action="{{ route('positions.store') }}" class="form-stack">@csrf
                <label>Position title *<input name="title" required></label><label>Department<select name="department_id"><option value="">Any department</option>@foreach($activeDepartments as $department)<option value="{{ $department->id }}">{{ $department->name }}</option>@endforeach</select></label><label>Salary grade<input name="salary_grade"></label>
                <input type="hidden" name="is_office_head_designation" value="0"><label class="checkbox-label"><input type="checkbox" name="is_office_head_designation" value="1"> HR-approved Office Head designation</label>
                <button class="button button-primary">Add position</button>
            </form>
        </aside>
    </div>
    <section class="panel panel-spaced"><div class="panel-heading"><div><h2>Edit positions</h2><p>Existing assignments are retained when a position is deactivated.</p></div></div>
        <div class="department-edit-grid">@foreach($positions as $position)
            <form class="inline-edit-card" method="POST" action="{{ route('positions.update', $position) }}">@csrf @method('PUT')
                <b>{{ $position->title }}</b><input name="title" value="{{ $position->title }}" required>
                <select name="department_id"><option value="">Any department</option>@foreach($departments as $department)@if($department->is_active || $position->department_id === $department->id)<option value="{{ $department->id }}" @selected($position->department_id === $department->id)>{{ $department->name }}{{ $department->is_active ? '' : ' (Inactive - current assignment)' }}</option>@endif @endforeach</select>
                <input name="salary_grade" value="{{ $position->salary_grade }}" placeholder="Salary grade">
                <input type="hidden" name="is_office_head_designation" value="0"><label class="checkbox-label"><input type="checkbox" name="is_office_head_designation" value="1" @checked($position->is_office_head_designation)> HR-approved Office Head designation</label>
                <input type="hidden" name="is_active" value="0"><label class="checkbox-label"><input type="checkbox" name="is_active" value="1" @checked($position->is_active)> Active</label><button class="button button-outline">Save changes</button>
            </form>
        @endforeach</div>
    </section>
@endsection
