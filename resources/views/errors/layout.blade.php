<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/webp" href="{{ asset('image/hrislogo.webp') }}">
    <title>@yield('title') · Camalaniugan Municipal HRMO</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="error-page">
    <main class="error-card">
        <a class="error-brand" href="{{ route('home') }}">Camalaniugan Municipal HRMO</a>
        <div class="error-code" aria-hidden="true">@yield('code')</div>
        <h1>@yield('heading')</h1>
        <p>@yield('message')</p>
        <nav class="error-actions" aria-label="Recovery options">
            <a class="error-primary" href="{{ route('home') }}">Return to home</a>
            <a class="error-secondary" href="{{ route('login') }}">Sign in</a>
        </nav>
    </main>
</body>
</html>
