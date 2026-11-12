@extends('layouts.app')
@section('title', 'Manage user account')
@section('content')
    <div class="page-heading">
        <div><span class="eyebrow">SUPER ADMINISTRATION</span><h1>Account details</h1><p>Review access, account status, and recorded administrative changes.</p></div>
        <a class="button button-outline" href="{{ route('super-admin.users.index') }}">Back to accounts</a>
    </div>

    <section class="panel">
        <div class="panel-heading">
            <div class="account-user-heading">@include('shared.avatar', ['user' => $account, 'size' => 'profile-photo-large'])<span><h2>{{ $account->name }}</h2><p>{{ $account->email }}</p></span></div>
            <span class="status-tag {{ $account->is_active ? 'status-approved' : 'status-disapproved' }}">{{ $account->is_active ? 'Active' : 'Inactive' }}</span>
        </div>
        @unless($account->isSuperAdmin())
            <a class="button button-outline" href="{{ route('users.edit', $account) }}">Edit account details</a>
        @endunless
        <div class="detail-grid">
            <div><small>Assigned role</small><b>{{ $account->isSuperAdmin() ? 'SUPER ADMIN' : ($account->role === 'admin' && $account->can_final_approve ? 'ADMIN · FINAL APPROVER' : ($account->role === 'mayor' ? 'MAYOR / FINAL APPROVER' : str_replace('_', ' ', strtoupper($account->role)))) }}</b></div>
            <div><small>Account created</small><b>{{ $account->created_at?->format('F j, Y g:i A') ?? '—' }}</b></div>
            <div><small>Last updated</small><b>{{ $account->updated_at?->format('F j, Y g:i A') ?? '—' }}</b></div>
            <div><small>Department / office</small><b>{{ $account->departmentHead?->name ?? $account->employee?->department?->name ?? 'Not assigned' }}</b></div>
            @if($account->employee)<div><small>Employee record</small><b>{{ $account->employee->employee_no }} · {{ $account->employee->full_name }}</b></div><div><small>Position</small><b>{{ $account->employee->position?->title ?? 'Not assigned' }}</b></div>@endif
        </div>
        @if($account->id !== auth()->id() && !$account->isMayor())
            <div class="form-actions top-space">
                @if($account->role === 'employee')
                    <form method="POST" action="{{ route('super-admin.users.role', $account) }}" onsubmit="return confirm('Promote this Employee account to HRMO?')">
                        @csrf @method('PATCH')<input type="hidden" name="role" value="hrmo">
                        <button class="button button-primary" type="submit">Promote to HRMO</button>
                    </form>
                @elseif($account->role === 'hrmo')
                    <form method="POST" action="{{ route('super-admin.users.role', $account) }}" onsubmit="return confirm('Promote this HRMO account to ADMIN?')">
                        @csrf @method('PATCH')<input type="hidden" name="role" value="admin">
                        <button class="button button-primary" type="submit">Promote to ADMIN</button>
                    </form>
                    <form method="POST" action="{{ route('super-admin.users.role', $account) }}" onsubmit="return confirm('Demote this HRMO account to Employee?')">
                        @csrf @method('PATCH')<input type="hidden" name="role" value="employee"><input type="hidden" name="employee_id" value="{{ $account->employee?->id }}">
                        <button class="button button-outline" type="submit" @disabled(!$account->employee)>Demote to Employee</button>
                    </form>
                @elseif($account->role === 'admin')
                    <form method="POST" action="{{ route('super-admin.users.role', $account) }}" onsubmit="return confirm('Demote this ADMIN account to HRMO?')">
                        @csrf @method('PATCH')<input type="hidden" name="role" value="hrmo">
                        <button class="button button-outline" type="submit">Demote to HRMO</button>
                    </form>
                    <form method="POST" action="{{ route('super-admin.users.role', $account) }}" onsubmit="return confirm('Demote this ADMIN account to Employee?')">
                        @csrf @method('PATCH')<input type="hidden" name="role" value="employee">
                        <label>Linked employee record
                            <select name="employee_id" required>
                                <option value="">Select employee</option>
                                @foreach($availableEmployees as $employee)
                                    <option value="{{ $employee->id }}" @selected($account->employee?->id === $employee->id)>{{ $employee->employee_no }} · {{ $employee->full_name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <button class="button button-outline" type="submit">Demote to Employee</button>
                    </form>
                    <form method="POST" action="{{ route('super-admin.users.role', $account) }}" onsubmit="return confirm('Promote this ADMIN account to SUPER ADMIN? This grants system-wide authority.')">
                        @csrf @method('PATCH')<input type="hidden" name="role" value="super_admin">
                        <button class="button button-primary" type="submit">Promote to SUPER ADMIN</button>
                    </form>
                @elseif($account->isSuperAdmin())
                    <form method="POST" action="{{ route('super-admin.users.role', $account) }}" onsubmit="return confirm('Demote this SUPER ADMIN account to ADMIN?')">
                        @csrf @method('PATCH')<input type="hidden" name="role" value="admin">
                        <button class="button button-outline" type="submit">Demote to ADMIN</button>
                    </form>
                    <form method="POST" action="{{ route('super-admin.users.role', $account) }}" onsubmit="return confirm('Demote this SUPER ADMIN account to Employee?')">
                        @csrf @method('PATCH')<input type="hidden" name="role" value="employee">
                        <label>Linked employee record
                            <select name="employee_id" required>
                                <option value="">Select employee</option>
                                @foreach($availableEmployees as $employee)
                                    <option value="{{ $employee->id }}" @selected($account->employee?->id === $employee->id)>{{ $employee->employee_no }} · {{ $employee->full_name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <button class="button button-outline" type="submit">Demote to Employee</button>
                    </form>
                @endif
            </div>
        @endif
    </section>

    @if($account->id !== auth()->id())
        <div class="content-grid dashboard-content-grid">
            <section class="panel form-panel">
                <div class="panel-heading"><div><h2>Account access</h2><p>Deactivating an account blocks its next authenticated request.</p></div></div>
                <form method="POST" action="{{ route('super-admin.users.status', $account) }}" onsubmit="return confirm('{{ $account->is_active ? 'Deactivate' : 'Activate' }} this account?')">
                    @csrf @method('PATCH')
                    <button class="button {{ $account->is_active ? 'button-danger' : 'button-primary' }}" type="submit">{{ $account->is_active ? 'Deactivate account' : 'Activate account' }}</button>
                </form>
                @if($account->isSuperAdmin())<p class="form-intro">The last active Super Admin cannot be deactivated.</p>@endif
            </section>
            <section class="panel form-panel">
                <div class="panel-heading"><div><h2>Reset account password</h2><p>Set a temporary or user-coordinated password of at least 12 characters. The password is not written to the audit log.</p></div></div>
                <form method="POST" action="{{ route('super-admin.users.password', $account) }}" onsubmit="return confirm('Reset this account password? The user will need the new password to sign in.')">
                    @csrf @method('PUT')
                    <div class="form-grid"><label>New password<input type="password" name="password" minlength="12" autocomplete="new-password" required></label><label>Confirm password<input type="password" name="password_confirmation" minlength="12" autocomplete="new-password" required></label></div>
                    @error('password')<span class="field-error" role="alert">{{ $message }}</span>@enderror
                    <div class="form-actions"><button class="button button-primary" type="submit">Reset password</button></div>
                </form>
            </section>
        </div>
    @endif

    <section class="panel panel-spaced">
        <div class="panel-heading"><div><h2>Account history</h2><p>Creation, role, status, and credential events recorded for this account.</p></div></div>
        <div class="table-wrap"><table><thead><tr><th>EVENT</th><th>ACTOR</th><th>DETAILS</th><th>DATE / TIME</th></tr></thead><tbody>
            @forelse($history as $event)
                <tr><td>{{ str($event->action)->replace(['user.', '_'], ['', ' '])->headline() }}</td><td>{{ $event->user?->name ?? 'System' }}</td><td>
                    @if(isset($event->metadata['previous_role'], $event->metadata['new_role'])){{ strtoupper($event->metadata['previous_role']) }} → {{ strtoupper($event->metadata['new_role']) }}
                    @elseif(isset($event->metadata['previous_status'], $event->metadata['new_status'])){{ ucfirst($event->metadata['previous_status']) }} → {{ ucfirst($event->metadata['new_status']) }}
                    @elseif(isset($event->metadata['role'])){{ strtoupper($event->metadata['role']) }}
                    @else—@endif
                </td><td>{{ $event->created_at->format('M j, Y g:i A') }}</td></tr>
            @empty<tr><td colspan="4" class="empty-note">No account history has been recorded.</td></tr>@endforelse
        </tbody></table></div>
    </section>
@endsection
