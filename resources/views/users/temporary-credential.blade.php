@extends('layouts.app')
@section('title', 'Temporary employee credential')
@section('content')
    <div class="page-heading">
        <div>
            <span class="eyebrow">EMPLOYEE ACCOUNT SECURITY</span>
            <h1>One-time temporary credential</h1>
            <p>This password is displayed only in this response. It is not saved in the import history or audit log.</p>
        </div>
    </div>

    <section class="panel form-panel">
        <div class="alert alert-info">
            Share this credential with the employee only through an approved secure channel. The employee must choose a new password immediately after signing in.
        </div>
        <dl class="detail-list">
            <div><dt>Employee</dt><dd>{{ $user->employee->full_name }}</dd></div>
            <div><dt>Username</dt><dd>{{ $user->username }}</dd></div>
            <div><dt>Temporary password</dt><dd><code>{{ $temporaryPassword }}</code></dd></div>
        </dl>
        <a class="button button-primary" href="{{ route('users.edit', $user) }}">Return to account management</a>
    </section>
@endsection
