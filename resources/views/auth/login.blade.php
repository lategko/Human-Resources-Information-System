<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · Camalaniugan HRIS</title>
    @vite(['resources/js/app.js'])
</head>
<body class="login-page">
    <div class="login-panel">
        <a href="{{ route('home') }}" class="login-back">← Back to portal</a>
        <div class="login-brand"><span class="brand-mark">C</span><div><strong>Municipality of Camalaniugan</strong><small>Human Resource Information System</small></div></div>
        <div class="login-heading"><span class="eyebrow">EMPLOYEE PORTAL</span><h1>Welcome back</h1><p>Sign in with your municipal account to continue.</p></div>
        @if($errors->any())<div class="alert alert-error">{{ $errors->first() }}</div>@endif
        <form method="POST" action="{{ route('login.store') }}" class="form-stack">
            @csrf
            <label>Email address<input type="email" name="email" value="{{ old('email') }}" placeholder="name@camalaniugan.gov.ph" autocomplete="username" required autofocus></label>
            <label>Password<input type="password" name="password" placeholder="Enter your password" autocomplete="current-password" required></label>
            <label class="checkbox-label"><input type="checkbox" name="remember" value="1"> Keep me signed in</label>
            <button type="submit" class="button button-primary button-wide">Sign in <span>→</span></button>
        </form>
        <p class="login-privacy">Access is limited to authorized personnel. Your activity may be logged for security and accountability.</p>
    </div>
    <aside class="login-aside"><div class="login-aside-inner"><span class="mini-seal">C</span><span class="eyebrow">PUBLIC SERVICE, MADE SIMPLE</span><h2>One secure place for your HR services.</h2><p>Apply for leave, follow review progress, and access your employee information with confidence.</p><div class="login-aside-bottom">REPUBLIC OF THE PHILIPPINES<br>PROVINCE OF CAGAYAN</div></div></aside>
</body>
</html>
