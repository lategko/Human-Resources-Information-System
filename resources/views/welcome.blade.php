<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Camalaniugan HRIS</title>
    @vite(['resources/js/app.js'])
</head>
<body class="public-body">
    <header class="public-nav">
        <a class="brand" href="{{ route('home') }}"><span class="brand-mark">C</span><span><strong>Municipality of Camalaniugan</strong><small>Human Resource Information System</small></span></a>
        <a class="button button-primary" href="{{ route('login') }}">Sign in <span>→</span></a>
    </header>
    <main class="hero">
        <div class="hero-copy">
            <span class="eyebrow"><span class="eyebrow-dot"></span> MUNICIPAL EMPLOYEE SERVICES</span>
            <h1>People first.<br><em>Service always.</em></h1>
            <p>A secure workspace for employee records, leave applications, and human resource services of the Municipality of Camalaniugan.</p>
            <div class="hero-actions"><a class="button button-primary" href="{{ route('login') }}">Access your account <span>→</span></a><span class="hero-note">For authorized municipal personnel</span></div>
        </div>
        <div class="hero-card">
            <div class="hero-card-top"><span class="mini-seal">C</span><span>CAMALANIUGAN<br><b>HUMAN RESOURCES</b></span><span class="status-pill">SECURE PORTAL</span></div>
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
