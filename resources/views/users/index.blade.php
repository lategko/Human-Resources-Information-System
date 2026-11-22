@extends('layouts.app')
@section('title', 'User management')
@section('content')
    <div class="page-heading"><div><span class="eyebrow">{{ auth()->user()->isAdministrator() ? 'SYSTEM ADMINISTRATION' : 'HR OPERATIONS' }}</span><h1>User accounts</h1><p>{{ auth()->user()->isAdministrator() ? 'Manage sign-in access, activation, and role assignments.' : 'Read-only account directory for HR operational review.' }}</p></div>@if(auth()->user()->isAdministrator())<a class="button button-primary" href="{{ route('users.create') }}">＋ Create user</a>@endif</div>
    @if(auth()->user()->isAdministrator())<div class="alert alert-info"><b>Security reminder.</b> Use unique strong passwords and disable accounts promptly when access is no longer authorized.</div>@endif
    <form method="GET" action="{{ route('users.index') }}" class="panel form-panel account-search-panel top-space">
        <label>Search employee name, username, or employee number<input name="search" value="{{ $search }}" maxlength="100" placeholder="Search accounts and unlinked employees"></label>
        <div class="form-actions"><button class="button button-primary" type="submit">Search</button><a class="button button-outline" href="{{ route('users.index') }}">Clear</a></div>
    </form>
    <section class="panel user-directory-panel top-space"><div class="table-wrap"><table><thead><tr><th>USER / LOGIN</th><th>ROLE</th><th>DEPARTMENT / POSITION</th><th>ACCOUNT</th><th></th></tr></thead><tbody>
        @forelse($users as $user)<tr>
            <td><b>{{ $user->employee?->full_name ?? $user->name }}</b><small class="table-sub">Username: {{ $user->username ?: 'Not set' }}</small><small class="table-sub">Email: {{ $user->email ?: 'Not registered' }} · {{ $user->hasVerifiedEmail() ? 'Verified' : 'Not verified' }}</small></td>
            <td>{{ $user->role === 'admin' && $user->can_final_approve ? 'ADMIN · DESIGNATED MAYOR-LEAVE APPROVER' : ($user->role === 'mayor' ? 'MAYOR · FINAL APPROVER' : str_replace('_',' ',ucfirst($user->role))) }}</td>
            <td>@if($user->employee){{ $user->employee->department?->name ?? 'Department not assigned' }}<small class="table-sub">{{ $user->employee->position?->title ?? 'Position not assigned' }}</small>@elseif($user->departmentHead){{ $user->departmentHead->name }}@else Not assigned @endif</td>
            <td><span class="status-tag {{ $user->is_active ? 'status-approved' : 'status-disapproved' }}">{{ $user->is_active ? 'Active' : 'Disabled' }}</span><small class="table-sub">{{ $user->isAdministrator() ? 'Setup not required' : ($user->must_change_password || ! $user->account_setup_completed ? 'Setup pending' : 'Setup complete') }}</small></td>
            <td>@if(auth()->user()->isAdministrator())<a class="table-link" href="{{ route('users.edit', $user) }}">Manage →</a>@else<span class="muted">Read only</span>@endif</td>
        </tr>@empty<tr><td colspan="5" class="empty-note">No accounts found.</td></tr>@endforelse
    </tbody></table></div><div class="pagination-wrap">{{ $users->links() }}</div></section>
    @if(auth()->user()->isAdministrator())
        <section class="panel top-space">
            <div class="panel-heading"><div><h2>Employees without linked accounts</h2><p>Create a secure employee login after confirming the employee record.</p></div></div>
            <div class="table-wrap"><table><thead><tr><th>EMPLOYEE</th><th>DEPARTMENT</th><th>POSITION</th><th></th></tr></thead><tbody>
                @forelse($unlinkedEmployees as $employee)<tr><td><b>{{ $employee->full_name }}</b><small class="table-sub">{{ $employee->employee_no }}</small></td><td>{{ $employee->department?->name ?? 'Not assigned' }}</td><td>{{ $employee->position?->title ?? 'Not assigned' }}</td><td><a class="table-link" href="{{ route('users.create', ['employee_id' => $employee->id]) }}">Create account →</a></td></tr>@empty<tr><td colspan="4" class="empty-note">All active employees in this search have linked accounts.</td></tr>@endforelse
            </tbody></table></div><div class="pagination-wrap">{{ $unlinkedEmployees->links() }}</div>
        </section>
    @endif
@endsection
