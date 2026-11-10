@extends('layouts.app')
@section('title', $user->exists ? 'Edit user account' : 'Create user account')
@section('content')
    <div class="page-heading"><div><span class="eyebrow">USER ACCESS</span><h1>{{ $user->exists ? 'Manage account' : 'Create account' }}</h1><p>Assign only the access required for the user’s municipal duties.</p></div><a class="button button-outline" href="{{ route('users.index') }}">Back to users</a></div>
    <form x-data="{ role: @js(old('role', $user->role)) }" method="POST" action="{{ $user->exists ? route('users.update', $user) : route('users.store') }}" class="panel form-panel">@csrf @if($user->exists)@method('PUT')@endif
        <div class="form-grid"><label>Full name *<input name="name" value="{{ old('name', $user->name) }}" required></label><label>Email *<input type="email" name="email" value="{{ old('email', $user->email) }}" required></label>
            <label>Role *<select name="role" x-model="role" required>@foreach(['admin'=>'Mayor / Administrator','hrmo'=>'HRMO','office_head'=>'Office Head','employee'=>'Staff / Employee'] as $value=>$label)<option value="{{ $value }}" @selected(old('role', $user->role) === $value)>{{ $label }}</option>@endforeach</select></label>
            <label>Linked employee record<select name="employee_id"><option value="">No linked employee</option>@foreach($employees as $employee)<option value="{{ $employee->id }}" @selected(old('employee_id', $user->employee?->id) == $employee->id)>{{ $employee->employee_no }} · {{ $employee->full_name }}</option>@endforeach</select></label>
            @if(!$user->exists)<label>Password *<input type="password" name="password" minlength="12" required autocomplete="new-password"></label><label>Confirm password *<input type="password" name="password_confirmation" minlength="12" required autocomplete="new-password"></label>
            @else<label>New password <small>Leave blank to keep the current password.</small><input type="password" name="password" minlength="12" autocomplete="new-password"></label><label>Confirm new password<input type="password" name="password_confirmation" minlength="12" autocomplete="new-password"></label>@endif
        </div>
        <input type="hidden" name="is_active" value="0"><label class="checkbox-label top-space"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $user->is_active))> Account is active and may sign in</label>
        <input type="hidden" name="can_final_approve" value="0"><label class="checkbox-label top-space" x-show="role === 'admin'" x-cloak><input type="checkbox" name="can_final_approve" value="1" @checked(old('can_final_approve', $user->can_final_approve))> Authorized final approver for leave applications</label>
        <div class="form-actions"><a class="button button-outline" href="{{ route('users.index') }}">Cancel</a><button type="submit" class="button button-primary">Save account</button></div>
    </form>
@endsection
