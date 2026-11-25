<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verify sign-in · Camalaniugan HRIS</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="login-page login-page-glass">
    <main class="login-panel">
        <a href="{{ route('login') }}" class="login-back">Back to sign in</a>
        <div class="login-heading">
            <span class="eyebrow">PRIVILEGED ACCOUNT VERIFICATION</span>
            <h1>Enter verification code</h1>
            <p>Enter the 6-digit code sent to the account email, or use one recovery code.</p>
        </div>
        @include('shared.flash-messages')
        <form method="POST" action="{{ route('mfa.verify.store') }}" class="form-stack login-form">
            @csrf
            <label for="code">Verification or recovery code
                <input id="code" type="text" name="code" value="" autocomplete="one-time-code" maxlength="255" required autofocus>
            </label>
            <button type="submit" class="button button-primary button-wide">Verify and sign in</button>
        </form>
    </main>
</body>
</html>
