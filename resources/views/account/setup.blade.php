@extends('layouts.app')
@section('title', 'Set up your account')
@section('content')
    <div class="page-heading">
        <div><span class="eyebrow">ACCOUNT SETUP</span><h1>Finish setting up your account</h1><p>Choose your username, secure your password, and add optional contact details.</p></div>
    </div>
    <form method="POST" action="{{ route('account.setup.update') }}" class="panel form-panel">
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
                <label class="field-span">Temporary password
                    <input type="password" name="current_password" autocomplete="current-password" required>
                    @error('current_password')<span class="field-error" role="alert">{{ $message }}</span>@enderror
                </label>
                <label>New password<input type="password" name="new_password" autocomplete="new-password" minlength="12" required></label>
                <label>Confirm new password<input type="password" name="new_password_confirmation" autocomplete="new-password" minlength="12" required></label>
                @error('new_password')<span class="field-error field-span" role="alert">{{ $message }}</span>@enderror
            @else
                <p class="field-span alert alert-info">Your password has been set using a secure activation link.</p>
            @endif
            <label>Contact number<input name="contact_number" value="{{ old('contact_number', $user->employee?->contact_number ?? $user->profile_contact_number) }}" autocomplete="tel"></label>
            <label class="field-span">Address<textarea name="address" rows="3" autocomplete="street-address">{{ old('address', $user->employee?->address ?? $user->profile_address) }}</textarea></label>
        </div>
        <div class="form-actions"><button class="button button-primary" type="submit">Complete account setup</button></div>
    </form>
@endsection
