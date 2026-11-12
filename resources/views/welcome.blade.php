<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#102b48">
    <link rel="icon" type="image/webp" href="{{ asset('image/hrislogo.webp') }}">
    <link rel="preload" as="image" href="{{ asset('image/backgroundlanding.jpg') }}" fetchpriority="high">
    <title>Municipality of Camalaniugan · Human Resource Management Office</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="public-body" style="--landing-image: url('{{ asset('image/backgroundlanding.jpg') }}')">
    <header class="public-nav">
        <a class="brand" href="{{ route('home') }}"><img class="brand-logo public-logo" src="{{ asset('image/hrislogo.webp') }}" alt="Official Municipality of Camalaniugan Human Resource Management Office seal" width="48" height="48" decoding="async"><span><strong>Municipality of Camalaniugan</strong><small>Human Resource Management Office</small></span></a>
        <a class="button button-primary" href="{{ route('login') }}">Sign in <span>→</span></a>
    </header>
    <main class="hero">
        <div class="hero-copy">
            <span class="eyebrow"><span class="eyebrow-dot"></span> HUMAN RESOURCE INFORMATION SYSTEM</span>
            <h1>Municipal service.<br><em>People first.</em></h1>
            <p>A secure workspace for employee records, leave applications, and human resource services of the Municipality of Camalaniugan.</p>
            <div class="hero-actions"><a class="button button-primary" href="{{ route('login') }}">Access your account <span>→</span></a><span class="hero-note">For authorized municipal personnel</span></div>
        </div>
        <div class="hero-card">
            <div class="hero-card-top"><img class="hero-seal" src="{{ asset('image/hrislogo.webp') }}" alt="Official Municipality of Camalaniugan HRMO seal" width="54" height="54" decoding="async"><span>MUNICIPALITY OF CAMALANIUGAN<br><b>HUMAN RESOURCE MANAGEMENT OFFICE</b></span><span class="status-pill">SECURE PORTAL</span></div>
            <div class="hero-card-title">Employee services,<br>in one place.</div>
            <div class="service-list">
                <div><span class="service-icon">▤</span><span><b>Digital leave applications</b><small>Submit and follow each review step</small></span><span class="service-arrow">↗</span></div>
                <div><span class="service-icon">♙</span><span><b>Employee information</b><small>Access role-appropriate records</small></span><span class="service-arrow">↗</span></div>
                <div><span class="service-icon">◷</span><span><b>Attendance and credits</b><small>Review your personal summaries</small></span><span class="service-arrow">↗</span></div>
            </div>
            <div class="hero-card-foot"><span>Designed for public service</span><span>PH · CAGAYAN</span></div>
        </div>
    </main>
    <footer class="public-footer"><span>© {{ date('Y') }} Municipality of Camalaniugan, Cagayan</span><span>Human Resource Management Office</span></footer>
</body>
</html>
