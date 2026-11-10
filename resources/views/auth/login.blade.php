<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#1478B9">
    <link rel="icon" type="image/jpeg" href="{{ asset('image/hrislogo.jpg') }}">
    <title>Sign in · Camalaniugan Municipal HRMO</title>
    @vite(['resources/js/app.js'])
</head>
<body class="login-page" x-data="{ showPassword: false }">
    <div class="login-panel">
        <a href="{{ route('home') }}" class="login-back">← Back to portal</a>
        <div class="login-brand"><img class="login-logo" src="{{ asset('image/hrislogo.jpg') }}" alt="Official Municipality of Camalaniugan Human Resource Management Office seal"><div><strong>Municipality of Camalaniugan</strong><small>Human Resource Management Office · Province of Cagayan, Philippines</small></div></div>
        <div class="login-heading"><span class="eyebrow">HUMAN RESOURCE INFORMATION SYSTEM</span><h1>Welcome back</h1><p>Sign in with your authorized municipal account.</p></div>
        @if($errors->any())<div class="alert alert-error" role="alert">{{ $errors->first() }}</div>@endif
        <form method="POST" action="{{ route('login.store') }}" class="form-stack">
            @csrf
            <label>Email address<input type="email" name="email" value="{{ old('email') }}" placeholder="name@camalaniugan.gov.ph" autocomplete="username" required autofocus></label>
            <label>Password<span class="password-control"><input aria-label="Password" :type="showPassword ? 'text' : 'password'" name="password" placeholder="Enter your password" autocomplete="current-password" required><button type="button" class="password-toggle" @click="showPassword = !showPassword" :aria-label="showPassword ? 'Hide password' : 'Show password'" :aria-pressed="showPassword.toString()" x-text="showPassword ? 'Hide' : 'Show'"></button></span></label>
            <label class="checkbox-label"><input type="checkbox" name="remember" value="1"> Keep me signed in</label>
            <button type="submit" class="button button-primary button-wide">Sign in <span>→</span></button>
        </form>
        <p class="login-privacy">Access is limited to authorized personnel. Your activity may be logged for security and accountability.</p>
    </div>
    <aside class="login-aside"><div class="login-aside-inner"><img class="login-seal" src="{{ asset('image/hrislogo.jpg') }}" alt="Official Municipality of Camalaniugan Human Resource Management Office seal"><span class="eyebrow">MUNICIPALITY OF CAMALANIUGAN</span><h2>Human Resource Information System</h2><p>Human Resource Management Office<br>Province of Cagayan, Philippines</p><div class="login-aside-bottom">PUBLIC SERVICE · ACCOUNTABILITY · TRUST</div></div></aside>
</body>
</html>
