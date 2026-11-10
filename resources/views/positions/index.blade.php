@extends('layouts.app')
@section('title', 'Positions')
@section('content')
    <div class="page-heading"><div><span class="eyebrow">ORGANIZATION</span><h1>Positions</h1><p>Maintain municipal position titles, department associations, and salary grades.</p></div></div>
    <div class="content-grid">
        <section class="panel"><div class="panel-heading"><div><h2>Position directory</h2><p>{{ $positions->total() }} position records</p></div></div>
            <div class="table-wrap"><table><thead><tr><th>POSITION</th><th>DEPARTMENT</th><th>SALARY GRADE</th><th>EMPLOYEES</th><th>STATUS</th></tr></thead><tbody>
            @forelse($positions as $position)<tr><td><b>{{ $position->title }}</b></td><td>{{ $position->department?->name ?? 'Any department' }}</td><td>{{ $position->salary_grade ?? '—' }}</td><td>{{ $position->employees_count }}</td><td><span class="status-tag {{ $position->is_active ? 'status-approved' : 'status-disapproved' }}">{{ $position->is_active ? 'Active' : 'Inactive' }}</span></td></tr>@empty<tr><td colspan="5" class="empty-note">No positions yet.</td></tr>@endforelse
            </tbody></table></div><div class="pagination-wrap">{{ $positions->links() }}</div>
        </section>
        <aside class="panel"><div class="panel-heading"><div><h2>Add position</h2><p>Position titles can be department-specific.</p></div></div>
            <form method="POST" action="{{ route('positions.store') }}" class="form-stack">@csrf
                <label>Position title *<input name="title" required></label><label>Department<select name="department_id"><option value="">Any department</option>@foreach($departments as $department)<option value="{{ $department->id }}">{{ $department->name }}</option>@endforeach</select></label><label>Salary grade<input name="salary_grade"></label>
                <button class="button button-primary">Add position</button>
            </form>
        </aside>
    </div>
    <section class="panel panel-spaced"><div class="panel-heading"><div><h2>Edit positions</h2><p>Existing assignments are retained when a position is deactivated.</p></div></div>
        <div class="department-edit-grid">@foreach($positions as $position)
            <form class="inline-edit-card" method="POST" action="{{ route('positions.update', $position) }}">@csrf @method('PUT')
                <b>{{ $position->title }}</b><input name="title" value="{{ $position->title }}" required>
                <select name="department_id"><option value="">Any department</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected($position->department_id === $department->id)>{{ $department->name }}</option>@endforeach</select>
                <input name="salary_grade" value="{{ $position->salary_grade }}" placeholder="Salary grade">
                <input type="hidden" name="is_active" value="0"><label class="checkbox-label"><input type="checkbox" name="is_active" value="1" @checked($position->is_active)> Active</label><button class="button button-outline">Save changes</button>
            </form>
        @endforeach</div>
    </section>
@endsection
