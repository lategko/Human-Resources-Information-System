<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#12365b">
    <link rel="icon" type="image/webp" href="{{ asset('image/hrislogo.webp') }}">
    <title>Sign in · Municipality of Camalaniugan HRMO</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="login-page" style="--login-image: url('{{ asset('image/login.background.jpg') }}')" x-data="{ showPassword: false, isSubmitting: false }">
    <main class="login-panel">
        <a href="{{ route('home') }}" class="login-back">Back to portal</a>
        <div class="login-brand">
            <img class="login-logo" src="{{ asset('image/hrislogo.webp') }}" alt="Municipality of Camalaniugan HRMO seal" width="64" height="64" decoding="async">
            <div>
                <strong>MUNICIPALITY OF CAMALANIUGAN</strong>
                <small>Human Resource Management Office<br>Province of Cagayan, Philippines</small>
            </div>
        </div>
        <div class="login-heading">
            <span class="eyebrow">HUMAN RESOURCE INFORMATION SYSTEM</span>
            <h1>Welcome Back</h1>
            <p>Sign in to access your municipal Human Resource Information System.</p>
        </div>
        @if($errors->any())
            <div id="login-error" class="alert alert-error" role="alert" aria-live="assertive">{{ $errors->first() }}</div>
        @endif
        <form method="POST" action="{{ route('login.store') }}" class="form-stack login-form" @submit="isSubmitting = true">
            @csrf
            <label for="email">Email Address
                <input id="email" type="email" name="email" value="{{ old('email') }}" placeholder="Enter your email address" autocomplete="username" required autofocus @if($errors->any()) aria-describedby="login-error" aria-invalid="true" @endif>
            </label>
            <label for="password">Password
                <span class="password-control">
                    <input id="password" :type="showPassword ? 'text' : 'password'" name="password" placeholder="Enter your password" autocomplete="current-password" required @if($errors->any()) aria-describedby="login-error" aria-invalid="true" @endif>
                    <button type="button" class="password-toggle" @click="showPassword = !showPassword" :aria-label="showPassword ? 'Hide password' : 'Show password'" :aria-pressed="showPassword.toString()" x-text="showPassword ? 'Hide' : 'Show'"></button>
                </span>
            </label>
            <label class="checkbox-label login-remember"><input type="checkbox" name="remember" value="1" @checked(old('remember'))> <span>Keep me signed in</span></label>
            <button type="submit" class="button button-primary button-wide login-submit" :disabled="isSubmitting">
                <span class="login-spinner" x-show="isSubmitting" aria-hidden="true"></span>
                <span x-text="isSubmitting ? 'Signing in…' : 'Sign In'">Sign In</span>
            </button>
        </form>
        <p class="login-privacy">Access is limited to authorized personnel. Your activity may be logged for security and accountability.</p>
    </main>
    <footer class="login-footer">© {{ date('Y') }} Municipality of Camalaniugan. All Rights Reserved.</footer>
</body>
</html>
