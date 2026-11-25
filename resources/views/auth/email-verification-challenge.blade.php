<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Verify your email · Camalaniugan HRIS</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="email-verification-page">
    @php
        $emailParts = explode('@', $email, 2);
        $maskedEmail = count($emailParts) === 2 && $emailParts[0] !== ''
            ? substr($emailParts[0], 0, 1).str_repeat('*', max(3, strlen($emailParts[0]) - 1)).'@'.$emailParts[1]
            : $email;
    @endphp

    <main class="email-verification-shell">
        <section class="verification-card" aria-labelledby="verification-title">
            <header class="verification-brand">
                <span class="verification-brand-accent" aria-hidden="true"></span>
                <div>
                    <p class="verification-municipality">Municipality of Camalaniugan</p>
                    <p class="verification-system">Human Resource Information System</p>
                </div>
            </header>

            <div class="verification-rule" aria-hidden="true"></div>
            <p class="verification-eyebrow">Admin Email Verification</p>
            <h1 id="verification-title">Verify Your Email Address</h1>
            <p class="verification-intro">Please confirm your registered email address to secure your HRIS administrator account.</p>

            <div class="verification-address" aria-label="Registered email address">
                <span>Registered email address</span>
                <strong>{{ $maskedEmail }}</strong>
            </div>

            @if(session('status'))
                <div class="verification-feedback verification-feedback-success" role="status" aria-live="polite">
                    {{ session('status') }}
                </div>
            @endif

            @if($errors->has('code'))
                <div class="verification-feedback verification-feedback-error" role="alert">
                    @foreach($errors->get('code') as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('email.verify.otp.store') }}" class="verification-form" data-email-verification-form>
                @csrf
                <p class="verification-field-label" id="verification-code-label">Enter your six-digit verification code</p>

                <div class="verification-otp-grid" data-otp-inputs role="group" aria-labelledby="verification-code-label" aria-describedby="verification-code-help" hidden>
                    @for($digit = 1; $digit <= 6; $digit++)
                        <input
                            class="verification-otp-digit"
                            type="text"
                            inputmode="numeric"
                            pattern="[0-9]*"
                            maxlength="{{ $digit === 1 ? 6 : 1 }}"
                            autocomplete="{{ $digit === 1 ? 'one-time-code' : 'off' }}"
                            aria-label="Digit {{ $digit }} of 6"
                            aria-required="true"
                            data-otp-digit
                        >
                    @endfor
                </div>

                <label class="verification-fallback-label" for="verification-code">Six-digit verification code</label>
                <input
                    class="verification-code-fallback"
                    id="verification-code"
                    type="text"
                    name="code"
                    value=""
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    maxlength="6"
                    pattern="[0-9]{6}"
                    required
                    data-otp-code
                >
                <p class="verification-code-help" id="verification-code-help">Use the code from the email sent to your registered address.</p>
                <p class="verification-client-error" data-otp-client-error role="alert" hidden></p>

                <button type="submit" class="verification-primary-button">Verify Email Address</button>
            </form>

            <form method="POST" action="{{ route('email.verify.otp.resend') }}" class="verification-resend-form" data-email-otp-resend data-cooldown-seconds="{{ max(0, (int) $resendCooldownSeconds) }}">
                @csrf
                <button type="submit" class="verification-resend-button" data-resend-button>
                    Resend Verification Code
                </button>
                <p class="verification-countdown" data-resend-countdown role="timer" aria-live="off"></p>
            </form>

            <aside class="verification-security-note">
                <strong>Keep your account secure</strong>
                <p>Your code expires in 10 minutes and can only be used once. You will complete a separate sign-in verification before accessing the dashboard.</p>
            </aside>

            <a class="verification-back-link" href="{{ route('login') }}">Back to Sign In</a>
        </section>

        <footer class="verification-footer">
            Municipality of Camalaniugan <span aria-hidden="true">·</span> Human Resource Information System
        </footer>
    </main>
</body>
</html>
