<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Choose a password · Camalaniugan HRIS</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="login-page">
    <main class="login-panel">
        <a href="{{ route('login') }}" class="login-back">Back to sign in</a>
        <div class="login-heading">
            <span class="eyebrow">ACCOUNT ACCESS</span>
            <h1>Choose a password</h1>
            <p>Use at least 12 characters. This link can only be used once and expires shortly.</p>
        </div>
        @include('shared.flash-messages')
        <form method="POST" action="{{ route('password.update') }}" class="form-stack login-form">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <label for="email">Email address<input id="email" type="email" name="email" value="{{ old('email', $email) }}" autocomplete="email" required autofocus></label>
            <label for="password">New password<input id="password" type="password" name="password" autocomplete="new-password" minlength="12" required></label>
            <label for="password_confirmation">Confirm new password<input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" minlength="12" required></label>
            <button type="submit" class="button button-primary button-wide">Save password</button>
        </form>
    </main>
</body>
</html>
