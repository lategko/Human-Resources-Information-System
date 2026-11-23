@extends('layouts.app')
@section('title', 'Departments')
@section('content')
    <div class="page-heading"><div><span class="eyebrow">ORGANIZATION</span><h1>Departments</h1><p>Maintain municipal offices and their assigned Office Heads.</p></div></div>
    <div class="content-grid">
        <section class="panel"><div class="panel-heading"><div><h2>Department directory</h2><p>{{ $departments->total() }} departments</p></div></div>
            <form class="filter-bar directory-filters" method="GET" action="{{ route('departments.index') }}">
                <label class="search-field"><i data-lucide="search"></i><input type="search" name="q" value="{{ request('q') }}" placeholder="Search name, code, or Office Head"></label>
                <select name="status" aria-label="Filter by department status"><option value="">All statuses</option><option value="active" @selected(request('status') === 'active')>Active</option><option value="inactive" @selected(request('status') === 'inactive')>Inactive</option></select>
                <button class="button button-outline" type="submit"><i data-lucide="list-filter"></i> Filter</button>
                @if(request()->hasAny(['q', 'status']))<a class="button button-outline" href="{{ route('departments.index') }}">Reset</a>@endif
            </form>
            <div class="table-wrap"><table><thead><tr><th>DEPARTMENT</th><th>OFFICE HEAD</th><th>EMPLOYEES</th><th>STATUS</th></tr></thead><tbody>
                @forelse($departments as $department)<tr><td><b>{{ $department->name }}</b><small class="table-sub">{{ $department->code ?? 'No code' }}</small></td><td>{{ $department->verifiedHead?->employee?->full_name ?? 'No Office Head' }}</td><td>{{ $department->employees_count }}</td><td><span class="status-tag {{ $department->is_active ? 'status-approved' : 'status-disapproved' }}">{{ $department->is_active ? 'Active' : 'Inactive' }}</span></td></tr>@empty<tr><td colspan="4" class="empty-note">No departments yet.</td></tr>@endforelse
            </tbody></table></div><div class="pagination-wrap">{{ $departments->links() }}</div>
        </section>
        @if(auth()->user()->isAdministrator())
            <aside class="panel"><div class="panel-heading"><div><h2>Add department</h2><p>Assign an Office Head after an employee in this department has a verified head designation.</p></div></div>
                <form method="POST" action="{{ route('departments.store') }}" class="form-stack" data-confirm-type="form" data-confirm-title="Add Department?" data-confirm-message="Add this department to the municipal organization directory?" data-confirm-text="Add Department">@csrf
                    <label>Department name *<input name="name" required></label><label>Department code<input name="code"></label>
                    <button class="button button-primary" type="submit" data-loading-text="Adding…">Add department</button>
                </form>
            </aside>
        @endif
    </div>
    @if(auth()->user()->isAdministrator() && $departments->count())
        <section class="panel panel-spaced"><div class="panel-heading"><div><h2>Update department assignments</h2><p>Changes to department scope affect Office Head record access.</p></div></div>
            <div class="department-edit-grid">@foreach($departments as $department)
                <form class="inline-edit-card" method="POST" action="{{ route('departments.update', $department) }}" data-confirm-type="form" data-confirm-title="Save Department Changes?" data-confirm-message="Save the department details, active status, and Office Head assignment for {{ $department->name }}?" data-confirm-text="Save Changes">@csrf @method('PUT')
                    <b>{{ $department->name }}</b><input name="name" value="{{ $department->name }}" required><input name="code" value="{{ $department->code }}" placeholder="Code">
                    <select name="head_user_id"><option value="">No Office Head</option>@foreach($department->eligibleOfficeHeads as $head)<option value="{{ $head->id }}" @selected((int) $department->head_user_id === (int) $head->id)>{{ $head->employee->full_name }}</option>@endforeach</select>
                    <input type="hidden" name="is_active" value="0"><label class="checkbox-label"><input type="checkbox" name="is_active" value="1" @checked($department->is_active)> Active</label>
                    <button class="button button-outline" type="submit" data-loading-text="Saving…">Save changes</button>
                </form>
            @endforeach</div>
        </section>
    @endif
@endsection
