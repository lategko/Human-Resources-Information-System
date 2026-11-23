<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Set up or reset password · Camalaniugan HRIS</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="login-page">
    <main class="login-panel">
        <a href="{{ route('login') }}" class="login-back">Back to sign in</a>
        <div class="login-heading">
            <span class="eyebrow">ACCOUNT ACCESS</span>
            <h1>Set up or reset password</h1>
            <p>Enter your registered email address. We will send a time-limited password setup link if an account matches.</p>
        </div>
        @include('shared.flash-messages')
        <form method="POST" action="{{ route('password.email') }}" class="form-stack login-form">
            @csrf
            <label for="email">Email address<input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="email" required autofocus></label>
            <button type="submit" class="button button-primary button-wide">Send password setup link</button>
        </form>
    </main>
</body>
</html>
