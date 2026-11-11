@extends('layouts.app')
@section('title', 'Super Admin · User management')
@section('content')
    <div class="page-heading">
        <div><span class="eyebrow">SUPER ADMINISTRATION</span><h1>User management</h1><p>Search accounts, administer access, and review role and account history.</p></div>
        <a class="button button-primary" href="{{ route('users.create') }}"><i data-lucide="user-plus" aria-hidden="true"></i> Create account</a>
    </div>

    <section class="panel">
        <form method="GET" action="{{ route('super-admin.users.index') }}" class="super-admin-filter-form">
            <label>Search accounts<input type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Name or email"></label>
            <label>Assigned role
                <select name="role">
                    <option value="">All roles</option>
                    @foreach(['super_admin'=>'Super Admin','admin'=>'ADMIN','hrmo'=>'HRMO','office_head'=>'Office Head','mayor'=>'Mayor','employee'=>'Employee / Staff'] as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['role'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label>Account status
                <select name="status">
                    <option value="">All statuses</option>
                    <option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option>
                    <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Inactive</option>
                </select>
            </label>
            <div class="form-actions"><button class="button button-primary" type="submit">Apply filters</button><a class="button button-outline" href="{{ route('super-admin.users.index') }}">Clear</a></div>
        </form>
        <div class="table-wrap">
            <table>
                <thead><tr><th>ACCOUNT</th><th>ROLE</th><th>OFFICE / EMPLOYEE</th><th>STATUS</th><th>CREATED</th><th>ACTIONS</th></tr></thead>
                <tbody>
                    @forelse($users as $account)
                        <tr>
                            <td><span class="account-user-cell">@include('shared.avatar', ['user' => $account])<span><strong>{{ $account->name }}</strong><small>{{ $account->email }}</small></span></span></td>
                            <td>{{ $account->isSuperAdmin() ? 'SUPER ADMIN' : ($account->isMayor() ? 'MAYOR / FINAL APPROVER' : str_replace('_', ' ', strtoupper($account->role))) }}</td>
                            <td>@if($account->departmentHead){{ $account->departmentHead->name }}@elseif($account->employee){{ $account->employee->employee_no }} · {{ $account->employee->department?->name ?? 'Department not assigned' }}@else—@endif</td>
                            <td><span class="status-tag {{ $account->is_active ? 'status-approved' : 'status-disapproved' }}">{{ $account->is_active ? 'Active' : 'Inactive' }}</span></td>
                            <td>{{ $account->created_at?->format('M j, Y') ?? '—' }}</td>
                            <td><a class="table-link" href="{{ route('super-admin.users.show', $account) }}">View / manage →</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="empty-note">No accounts match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="pagination-wrap">{{ $users->links() }}</div>
    </section>

    <section class="panel panel-spaced">
        <div class="panel-heading"><div><h2>Recent account administration</h2><p>Append-only audit events; credentials are never included.</p></div><a class="text-link" href="{{ route('audit.index') }}">Open audit logs <i data-lucide="arrow-right"></i></a></div>
        <div class="table-wrap"><table><thead><tr><th>ACTIVITY</th><th>ACTOR</th><th>ACCOUNT</th><th>DATE / TIME</th></tr></thead><tbody>
            @forelse($recentRoleChanges as $event)
                <tr><td>{{ str($event->action)->replace(['user.', '_'], ['', ' '])->headline() }}</td><td>{{ $event->user?->name ?? 'System' }}</td><td>{{ $event->subject_id ? 'Account #'.$event->subject_id : '—' }}</td><td>{{ $event->created_at->format('M j, Y g:i A') }}</td></tr>
            @empty<tr><td colspan="4" class="empty-note">No recent account events.</td></tr>@endforelse
        </tbody></table></div>
    </section>
@endsection
