<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#092c54">
    <link rel="icon" type="image/webp" href="{{ asset('image/hrislogo.webp') }}">
    <link rel="preload" as="image" href="{{ asset('image/backgroundlanding.jpg') }}" fetchpriority="high">
    <title>Sign in · Municipality of Camalaniugan HRMO</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="login-page login-page-glass" style="--login-image: url('{{ asset('image/backgroundlanding.jpg') }}')" x-data="{ showPassword: false, isSubmitting: false }">
    <main class="login-shell">
        <section class="login-identity" aria-label="Municipal Human Resource Management Office">
            <div class="login-identity-inner">
                <div class="login-identity-brand">
                    <img src="{{ asset('image/hrislogo.webp') }}" alt="Municipality of Camalaniugan Human Resource Management Office seal" width="72" height="72" decoding="async">
                    <div>
                        <strong>MUNICIPALITY OF CAMALANIUGAN</strong>
                        <span>HUMAN RESOURCE MANAGEMENT OFFICE</span>
                        <small>Province of Cagayan, Philippines</small>
                    </div>
                </div>

                <div class="login-identity-copy">
                    <span class="login-identity-kicker">Municipal workforce portal</span>
                    <h2>People at the Heart of <span>Public Service.</span></h2>
                    <p>A secure, centralized platform for managing municipal employee records, services, and workforce information.</p>
                </div>

                <p class="login-identity-footer">Municipality of Camalaniugan <span aria-hidden="true">·</span> Cagayan, Philippines</p>
            </div>
        </section>

        <section class="login-panel" aria-labelledby="login-title">
            <span class="login-panel-ribbon login-panel-ribbon-top" aria-hidden="true"></span>
            <span class="login-panel-ribbon login-panel-ribbon-bottom" aria-hidden="true"></span>
            <a href="{{ route('home') }}" class="login-back">Back to portal</a>

            <div class="login-brand login-form-brand">
                <img class="login-logo" src="{{ asset('image/hrislogo.webp') }}" alt="" width="56" height="56" decoding="async">
                <div>
                    <strong>MUNICIPALITY OF CAMALANIUGAN</strong>
                    <small>HUMAN RESOURCE INFORMATION SYSTEM</small>
                </div>
            </div>

            <div class="login-heading">
                <span class="eyebrow">Secure staff access</span>
                <h1 id="login-title">Welcome Back</h1>
                <p>Sign in to continue to your HRIS account.</p>
            </div>

            @include('shared.flash-messages', ['showValidationErrors' => false])
            @if($errors->any())
                <section class="login-auth-error" id="login-errors" role="alert" aria-live="assertive" x-data="{ visible: true }" x-show="visible" x-cloak>
                    <div>
                        <strong>Please review your login credentials.</strong>
                        <ul>
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                    <button type="button" @click="visible = false">Dismiss</button>
                </section>
            @endif

            <form method="POST" action="{{ route('login.store') }}" class="form-stack login-form" @submit="isSubmitting = true">
                @csrf
                <label for="login">Username or Email Address
                    <input id="login" type="text" name="login" value="{{ old('login', old('email')) }}" placeholder="Enter your username or email address" autocomplete="username" required autofocus @if($errors->has('login') || $errors->has('email')) aria-describedby="login-errors" aria-invalid="true" @endif>
                </label>
                <label for="password">Password
                    <span class="password-control">
                        <input id="password" :type="showPassword ? 'text' : 'password'" name="password" placeholder="Enter your password" autocomplete="current-password" required @if($errors->has('password')) aria-describedby="login-errors" aria-invalid="true" @endif>
                        <button type="button" class="password-toggle" @click="showPassword = !showPassword" :aria-label="showPassword ? 'Hide password' : 'Show password'" :aria-pressed="showPassword.toString()" x-text="showPassword ? 'Hide' : 'Show'"></button>
                    </span>
                </label>
                <div class="login-form-options">
                    <label class="checkbox-label login-remember"><input type="checkbox" name="remember" value="1" @checked(old('remember'))> <span>Keep me signed in</span></label>
                    <a href="{{ route('password.request') }}" class="text-link">Forgot password?</a>
                </div>
                <button type="submit" class="button button-primary button-wide login-submit" :disabled="isSubmitting">
                    <span class="login-spinner" x-show="isSubmitting" aria-hidden="true"></span>
                    <span x-text="isSubmitting ? 'Signing in…' : 'Sign In'">Sign In</span>
                </button>
            </form>

            <div class="login-privacy">
                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none">
                    <rect x="5" y="10" width="14" height="11" rx="2" stroke="currentColor" stroke-width="1.8"/>
                    <path d="M8 10V7a4 4 0 1 1 8 0v3M12 14v3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                </svg>
                <p>Authorized personnel only. Your access may be monitored for security and accountability.</p>
            </div>
        </section>
    </main>
</body>
</html>
