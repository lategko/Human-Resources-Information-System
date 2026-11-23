@extends('layouts.app')
@section('title', $user->must_change_password ? 'Change password' : 'Set up your account')
@section('content')
    <div class="page-heading">
        <div>
            <span class="eyebrow">{{ $user->must_change_password ? 'FIRST SIGN-IN' : 'ACCOUNT SETUP' }}</span>
            @if($user->must_change_password)
                <h1>Change your temporary password</h1>
                <p>Choose a new password to secure your account before continuing to the HRIS.</p>
            @else
                <h1>Finish setting up your account</h1>
                <p>Choose your username and add optional contact details.</p>
            @endif
        </div>
    </div>
    <form method="POST" action="{{ route('account.setup.update') }}" class="panel form-panel" x-data="{ showCurrentPassword: false, showNewPassword: false, showConfirmationPassword: false }">
        @csrf @method('PUT')
        <div class="panel-heading"><div><h2>{{ $user->name }}</h2><p>Employee number: {{ $user->employee?->employee_no ?? 'Not linked' }}</p></div></div>
        <div class="form-grid">
            <label>Username
                <input name="username" value="{{ old('username', $user->username) }}" autocomplete="username" minlength="3" maxlength="100" required>
                <small>Use letters and numbers; dots, underscores, and hyphens are allowed.</small>
                @error('username')<span class="field-error" role="alert">{{ $message }}</span>@enderror
            </label>
            <label>Email address (optional)
                <input type="email" name="email" value="{{ old('email', $user->email) }}" autocomplete="email">
                <small>A verification link will be sent when an email address is added or changed.</small>
                @error('email')<span class="field-error" role="alert">{{ $message }}</span>@enderror
            </label>
            @if($user->must_change_password)
                <label class="field-span">Current Password
                    <span class="account-password-control">
                        <input :type="showCurrentPassword ? 'text' : 'password'" name="current_password" autocomplete="current-password" required>
                        <button class="account-password-toggle" type="button" @click="showCurrentPassword = !showCurrentPassword" :aria-label="showCurrentPassword ? 'Hide current password' : 'Show current password'" x-text="showCurrentPassword ? 'Hide' : 'Show'"></button>
                    </span>
                    @error('current_password')<span class="field-error" role="alert">{{ $message }}</span>@enderror
                </label>
                <label>New Password
                    <span class="account-password-control">
                        <input :type="showNewPassword ? 'text' : 'password'" name="new_password" autocomplete="new-password" minlength="12" required>
                        <button class="account-password-toggle" type="button" @click="showNewPassword = !showNewPassword" :aria-label="showNewPassword ? 'Hide new password' : 'Show new password'" x-text="showNewPassword ? 'Hide' : 'Show'"></button>
                    </span>
                    @error('new_password')<span class="field-error" role="alert">{{ $message }}</span>@enderror
                </label>
                <label>Confirm New Password
                    <span class="account-password-control">
                        <input :type="showConfirmationPassword ? 'text' : 'password'" name="new_password_confirmation" autocomplete="new-password" minlength="12" required>
                        <button class="account-password-toggle" type="button" @click="showConfirmationPassword = !showConfirmationPassword" :aria-label="showConfirmationPassword ? 'Hide confirmation password' : 'Show confirmation password'" x-text="showConfirmationPassword ? 'Hide' : 'Show'"></button>
                    </span>
                </label>
                <p class="field-span account-password-guidance">Use at least 12 characters. Your current password must be different from the new password.</p>
            @else
                <p class="field-span alert alert-info">Your password has been set using a secure activation link.</p>
            @endif
            <label>Contact number<input name="contact_number" value="{{ old('contact_number', $user->employee?->contact_number ?? $user->profile_contact_number) }}" autocomplete="tel"></label>
            <label class="field-span">Address<textarea name="address" rows="3" autocomplete="street-address">{{ old('address', $user->employee?->address ?? $user->profile_address) }}</textarea></label>
        </div>
        <div class="form-actions"><button class="button button-primary" type="submit">{{ $user->must_change_password ? 'Change password and continue' : 'Complete account setup' }}</button></div>
    </form>
@endsection
