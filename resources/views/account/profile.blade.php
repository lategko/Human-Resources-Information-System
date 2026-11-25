@extends('layouts.app')
@section('title', 'My profile')
@section('content')
    <div class="page-heading">
        <div><span class="eyebrow">ACCOUNT</span><h1>My profile</h1><p>Manage your personal information, profile photo, and account security.</p></div>
        <a class="button button-primary" href="{{ route('account.profile.edit') }}"><i data-lucide="user-round-pen" aria-hidden="true"></i> Edit profile</a>
    </div>

    <div class="profile-layout account-profile-layout account-settings-layout">
        <section class="panel profile-card account-profile-card">
            @include('shared.avatar', ['user' => $user, 'size' => 'profile-photo-large'])
            <h2>{{ $user->employee?->full_name ?? $user->name }}</h2>
            <span class="status-tag {{ $user->is_active ? 'status-active' : 'status-inactive' }}">{{ $user->is_active ? 'Active account' : 'Disabled account' }}</span>
            <div class="profile-meta">
                <span>Assigned role</span>
                <b>{{ str_replace('_', ' ', ucfirst($user->role)) }}{{ $user->canFinalApprove() ? ' · Final approver' : '' }}</b>
                @if($user->employee)
                    <span>Department</span><b>{{ $user->employee->department?->name ?? 'Not assigned' }}</b>
                    <span>Position</span><b>{{ $user->employee->position?->title ?? 'Not assigned' }}</b>
                @endif
            </div>
            <form class="profile-photo-form" method="POST" action="{{ route('account.profile.photo') }}" enctype="multipart/form-data" x-data="{ preview: null, previewPhoto(event) { const file = event.target.files?.[0]; this.preview = file ? URL.createObjectURL(file) : null; } }">
                @csrf
                <label for="profile-photo">Change profile picture</label>
                <div class="profile-photo-preview" x-show="preview" x-cloak>
                    <img :src="preview" alt="Selected profile picture preview">
                </div>
                <input id="profile-photo" type="file" name="photo" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" required @change="previewPhoto($event)">
                <small>JPG, PNG, or WEBP. Maximum 2 MB.</small>
                @error('photo')<span class="field-error" role="alert">{{ $message }}</span>@enderror
                <button class="button button-primary" type="submit">Save picture</button>
            </form>
            @if($user->profile_photo_path)
                <form method="POST" action="{{ route('account.profile.photo.delete') }}"
                    data-confirm-type="delete"
                    data-confirm-title="Remove Profile Picture?"
                    data-confirm-message="Your profile picture will be removed and your initials will be shown instead."
                    data-confirm-text="Remove Picture">
                    @csrf @method('DELETE')
                    <button class="button button-outline profile-remove-photo" type="submit" data-loading-text="Removing…">Remove picture</button>
                </form>
            @endif
        </section>

        <div class="account-profile-sections">
            <section class="panel profile-details">
                <div class="panel-heading"><div><h2>Account information</h2><p>Your sign-in details and assigned access. Role and account status are managed by authorized administrators.</p></div></div>
                <div class="detail-grid">
                    <div><small>Full name</small><b>{{ $user->employee?->full_name ?? $user->name }}</b></div>
                    @if($user->employee)<div><small>Employee number</small><b>{{ $user->employee->employee_no }}</b></div>@endif
                    <div><small>Username</small><b>{{ $user->username ?: 'Not set' }}</b></div>
                    <div><small>Email address</small><b>{{ $user->email ?: 'Not registered' }}</b></div>
                    <div><small>Email verification</small><b>{{ $user->hasVerifiedEmail() ? 'Verified' : 'Not verified' }}</b></div>
                    <div><small>Assigned role</small><b>{{ str_replace('_', ' ', ucfirst($user->role)) }}{{ $user->canFinalApprove() ? ' · Final approver' : '' }}</b></div>
                    <div><small>Account status</small><b>{{ $user->is_active ? 'Active' : 'Disabled' }}</b></div>
                </div>
                @if($user->email && ! $user->hasVerifiedEmail())
                    <form method="POST" action="{{ route('verification.send') }}" class="top-space">
                        @csrf
                        <button class="button button-outline" type="submit">Send email verification link</button>
                    </form>
                @endif
            </section>

            @if($user->employee)
                <section class="panel profile-details">
                    <div class="panel-heading"><div><h2>Personal information</h2><p>Personal details from your linked employee record.</p></div></div>
                    <div class="detail-grid">
                        <div><small>First name</small><b>{{ $user->employee->first_name }}</b></div>
                        <div><small>Middle name</small><b>{{ $user->employee->middle_name ?: '—' }}</b></div>
                        <div><small>Last name</small><b>{{ $user->employee->last_name }}</b></div>
                        <div><small>Suffix</small><b>{{ $user->employee->suffix ?: '—' }}</b></div>
                        <div><small>Gender</small><b>{{ $user->employee->sex ?: '—' }}</b></div>
                        <div><small>Birthdate</small><b>{{ $user->employee->date_of_birth?->format('F j, Y') ?? '—' }}</b></div>
                        <div><small>Contact number</small><b>{{ $user->employee->contact_number ?: '—' }}</b></div>
                        <div class="field-span"><small>Residential address</small><b>{{ $user->employee->address ?: '—' }}</b></div>
                    </div>
                </section>
            @else
                <section class="panel profile-details">
                    <div class="panel-heading"><div><h2>Personal information</h2><p>Name details currently recorded on your account.</p></div></div>
                    <div class="detail-grid">
                        <div><small>First name</small><b>{{ $user->first_name ?: '—' }}</b></div>
                        <div><small>Middle name</small><b>{{ $user->middle_name ?: '—' }}</b></div>
                        <div><small>Last name</small><b>{{ $user->last_name ?: '—' }}</b></div>
                        <div><small>Suffix</small><b>{{ $user->suffix ?: '—' }}</b></div>
                        <div><small>Gender</small><b>{{ $user->profile_gender ?: '—' }}</b></div>
                        <div><small>Birthdate</small><b>{{ $user->profile_birthdate?->format('F j, Y') ?? '—' }}</b></div>
                        <div><small>Contact number</small><b>{{ $user->profile_contact_number ?: '—' }}</b></div>
                        <div class="field-span"><small>Residential address</small><b>{{ $user->profile_address ?: '—' }}</b></div>
                    </div>
                </section>
            @endif

            <section id="change-password" class="panel form-panel account-password-panel">
                <div class="panel-heading"><div><h2>Change password</h2><p>Use your current password to set a new password of at least 12 characters.</p></div></div>
                <form method="POST" action="{{ route('account.profile.password') }}" data-confirm-type="warning" data-confirm-title="Change Account Password?" data-confirm-message="Your current password will be replaced with the new password entered here." data-confirm-text="Change Password">
                    @csrf @method('PUT')
                    <div class="form-grid">
                        <label class="field-span">Current password<input type="password" name="current_password" autocomplete="current-password" required></label>
                        <label>New password<input type="password" name="new_password" autocomplete="new-password" minlength="12" required></label>
                        <label>Confirm new password<input type="password" name="new_password_confirmation" autocomplete="new-password" minlength="12" required></label>
                    </div>
                    @error('current_password')<span class="field-error" role="alert">{{ $message }}</span>@enderror
                    @error('new_password')<span class="field-error" role="alert">{{ $message }}</span>@enderror
                    <div class="form-actions"><button class="button button-primary" type="submit" data-loading-text="Changing…">Change password</button></div>
                </form>
            </section>

            @if(in_array($user->role, ['admin', 'mayor', 'office_head'], true))
                <section class="panel form-panel">
                    <div class="panel-heading">
                        <div>
                            <h2>Sign-in recovery</h2>
                            <p>Generate one-time recovery codes for privileged sign-in recovery. Store these codes securely and offline.</p>
                        </div>
                    </div>
                    @if(session('mfa_recovery_codes'))
                        <div class="alert alert-info">
                            <strong>Save these recovery codes now.</strong>
                            <p>They will not be shown again. Each code can be used once.</p>
                            <ul>
                                @foreach(session('mfa_recovery_codes') as $recoveryCode)
                                    <li><code>{{ $recoveryCode }}</code></li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    <form method="POST" action="{{ route('account.profile.mfa.recovery-codes') }}"
                        data-confirm-type="warning"
                        data-confirm-title="Generate Recovery Codes?"
                        data-confirm-message="Generating new codes replaces all existing privileged sign-in recovery codes."
                        data-confirm-text="Generate Codes">
                        @csrf
                        <div class="form-actions">
                            <button class="button button-outline" type="submit">Generate recovery codes</button>
                        </div>
                    </form>
                </section>
            @endif
        </div>
    </div>
@endsection
