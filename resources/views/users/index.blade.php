@extends('layouts.app')
@section('title', 'User management')
@section('content')
    <div class="page-heading"><div><span class="eyebrow">ADMINISTRATION</span><h1>User accounts</h1><p>Manage sign-in access and role assignments.</p></div><a class="button button-primary" href="{{ route('users.create') }}">＋ Create user</a></div>
    <div class="alert alert-info"><b>Security reminder.</b> Use unique strong passwords and disable accounts promptly when access is no longer authorized.</div>
    <section class="panel"><div class="table-wrap"><table><thead><tr><th>USER</th><th>ROLE</th><th>EMPLOYEE RECORD</th><th>STATUS</th><th></th></tr></thead><tbody>
        @forelse($users as $user)<tr><td><b>{{ $user->name }}</b><small class="table-sub">{{ $user->email }}</small></td><td>{{ str_replace('_',' ',ucfirst($user->role)) }} @if($user->canFinalApprove())<small class="table-sub">Final approver</small>@endif</td><td>{{ $user->employee?->employee_no ? $user->employee->employee_no.' · '.$user->employee->full_name : 'Not linked' }}</td><td><span class="status-tag {{ $user->is_active ? 'status-approved' : 'status-disapproved' }}">{{ $user->is_active ? 'Active' : 'Disabled' }}</span></td><td><a class="table-link" href="{{ route('users.edit', $user) }}">Manage →</a></td></tr>@empty<tr><td colspan="5" class="empty-note">No accounts found.</td></tr>@endforelse
    </tbody></table></div><div class="pagination-wrap">{{ $users->links() }}</div></section>
@endsection
