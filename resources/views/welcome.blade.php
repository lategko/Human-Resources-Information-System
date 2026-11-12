<!DOCTYPE html>
<html lang="en" class="public-document">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#12365b">
    <meta name="description" content="The Municipality of Camalaniugan Human Resource Information System public portal.">
    <link rel="icon" type="image/webp" href="{{ asset('image/hrislogo.webp') }}">
    <link rel="preload" as="image" href="{{ asset('image/backgroundlanding.jpg') }}" fetchpriority="high">
    <title>Municipality of Camalaniugan · Human Resource Management Office</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="public-body" style="--landing-image: url('{{ asset('image/backgroundlanding.jpg') }}')" x-data="publicNavigation()">
    <header class="public-nav">
        <a class="brand public-brand" href="#home" aria-label="Municipality of Camalaniugan HRMO home">
            <img class="brand-logo public-logo" src="{{ asset('image/hrislogo.webp') }}" alt="" width="48" height="48" decoding="async">
            <span><strong>Municipality of Camalaniugan</strong><small>Human Resource Management Office</small></span>
        </a>
        <button class="public-menu-toggle" type="button" aria-controls="public-navigation" :aria-expanded="menuOpen" @click="menuOpen = !menuOpen">
            <span x-text="menuOpen ? 'Close menu' : 'Menu'"></span>
        </button>
        <nav id="public-navigation" class="public-nav-links" :class="{ 'is-open': menuOpen }" aria-label="Main navigation">
            <a href="#home" :aria-current="activeSection === 'home' ? 'page' : null" @click="menuOpen = false">Home</a>
            <a href="#about" :aria-current="activeSection === 'about' ? 'page' : null" @click="menuOpen = false">About</a>
            <a href="#services" :aria-current="activeSection === 'services' ? 'page' : null" @click="menuOpen = false">Services</a>
            <a href="#features" :aria-current="activeSection === 'features' ? 'page' : null" @click="menuOpen = false">Features</a>
            <a href="#contact" :aria-current="activeSection === 'contact' ? 'page' : null" @click="menuOpen = false">Contact</a>
            <a class="button button-primary public-sign-in" href="{{ route('login') }}">Sign in <span aria-hidden="true">→</span></a>
        </nav>
    </header>

    <main>
        <section id="home" class="public-hero" aria-labelledby="hero-title">
            <div class="public-hero-inner">
                <div class="public-hero-copy">
                    <span class="public-eyebrow"><span class="public-eyebrow-mark" aria-hidden="true"></span> Municipality of Camalaniugan · HRMO</span>
                    <h1 id="hero-title">Municipal Service.<br><span>People First.</span></h1>
                    <p>A unified Human Resource Information System designed to improve employee services, personnel management, and administrative efficiency in the Municipality of Camalaniugan.</p>
                    <div class="public-hero-actions">
                        <a class="button button-primary public-hero-primary" href="{{ route('login') }}">Access HRIS Portal <span aria-hidden="true">→</span></a>
                        <a class="button public-hero-secondary" href="#services">Explore Our Services <span aria-hidden="true">↓</span></a>
                    </div>
                    <div class="public-hero-note"><i data-lucide="shield-check" aria-hidden="true"></i> Secure access for authorized municipal personnel</div>
                </div>
                <div class="public-hero-identity">
                    <img src="{{ asset('image/hrislogo.webp') }}" alt="Municipality of Camalaniugan HRMO seal" width="72" height="72" decoding="async">
                    <div>
                        <span>PUBLIC SERVICE · CAGAYAN</span>
                        <strong>Human Resource<br>Information System</strong>
                        <small>Province of Cagayan, Philippines</small>
                    </div>
                </div>
            </div>
            <a class="public-scroll-cue" href="#about"><span aria-hidden="true"></span> Discover the HRIS</a>
        </section>

        <section id="about" class="public-section public-about" aria-labelledby="about-title">
            <div class="public-section-inner public-about-layout">
                <div class="public-about-photo">
                    <img src="{{ asset('image/login.background.jpg') }}" alt="Municipal building of Camalaniugan, Cagayan" width="1195" height="800" loading="lazy" decoding="async">
                    <div class="public-photo-caption"><i data-lucide="building-2" aria-hidden="true"></i><span>Municipality of Camalaniugan<small>Province of Cagayan, Philippines</small></span></div>
                </div>
                <div class="public-about-copy">
                    <span class="public-section-kicker">About the HRMO</span>
                    <h2 id="about-title">About Our Human Resource Information System</h2>
                    <p class="public-lead">A centralized digital platform supporting the Municipality of Camalaniugan's human resource operations.</p>
                    <p>The HRIS brings essential personnel processes together in one organized system. It helps the Human Resource Management Office manage employee information and supports municipal personnel through clearer, more efficient service workflows.</p>
                    <ul class="public-check-list">
                        <li><i data-lucide="check" aria-hidden="true"></i> Organized employee records and employment information</li>
                        <li><i data-lucide="check" aria-hidden="true"></i> Efficient leave applications and review workflows</li>
                        <li><i data-lucide="check" aria-hidden="true"></i> Attendance monitoring and leave credit tracking</li>
                        <li><i data-lucide="check" aria-hidden="true"></i> Transparent review, approval, and personnel administration</li>
                    </ul>
                </div>
            </div>
        </section>

        <section id="services" class="public-section public-services" aria-labelledby="services-title">
            <div class="public-section-inner">
                <div class="public-section-heading">
                    <span class="public-section-kicker">HRMO services</span>
                    <h2 id="services-title">Services for municipal personnel</h2>
                    <p>Practical tools for everyday personnel records, employee services, and HR operations.</p>
                </div>
                <div class="public-service-grid">
                    <article class="public-service-card">
                        <span class="public-icon"><i data-lucide="users-round" aria-hidden="true"></i></span>
                        <h3>Employee Management</h3>
                        <p>Centralized employee profiles, employment information, departments, and positions.</p>
                    </article>
                    <article class="public-service-card">
                        <span class="public-icon"><i data-lucide="calendar-days" aria-hidden="true"></i></span>
                        <h3>Leave Management</h3>
                        <p>Digital leave applications, supporting documents, review and approval, and application tracking.</p>
                    </article>
                    <article class="public-service-card">
                        <span class="public-icon"><i data-lucide="clock-3" aria-hidden="true"></i></span>
                        <h3>Attendance Monitoring</h3>
                        <p>Attendance records, biometric CSV imports, attendance corrections, and monitoring.</p>
                    </article>
                    <article class="public-service-card">
                        <span class="public-icon"><i data-lucide="wallet" aria-hidden="true"></i></span>
                        <h3>Leave Credits</h3>
                        <p>Employee leave balances, credit adjustments, and transaction history.</p>
                    </article>
                    <article class="public-service-card">
                        <span class="public-icon"><i data-lucide="chart-no-axes-combined" aria-hidden="true"></i></span>
                        <h3>Reports and Analytics</h3>
                        <p>Employee reports, attendance summaries, leave reports, and downloadable CSV reports.</p>
                    </article>
                    <article class="public-service-card">
                        <span class="public-icon"><i data-lucide="shield-check" aria-hidden="true"></i></span>
                        <h3>Account and Access Management</h3>
                        <p>Role-based access and account administration for authorized municipal users.</p>
                    </article>
                </div>
            </div>
        </section>

        <section id="features" class="public-section public-features" aria-labelledby="features-title">
            <div class="public-section-inner">
                <div class="public-section-heading">
                    <span class="public-section-kicker">Built for public service</span>
                    <h2 id="features-title">A clearer way to manage HR operations</h2>
                    <p>Tools that support secure access, accountable processes, and dependable personnel information.</p>
                </div>
                <div class="public-feature-grid">
                    <article class="public-feature"><i data-lucide="contact-round" aria-hidden="true"></i><div><h3>Centralized information</h3><p>Keep employee profiles and work information organized in one system.</p></div></article>
                    <article class="public-feature"><i data-lucide="shield-check" aria-hidden="true"></i><div><h3>Role-based security</h3><p>Access is assigned according to authorized account roles.</p></div></article>
                    <article class="public-feature"><i data-lucide="calendar-days" aria-hidden="true"></i><div><h3>Digital leave workflow</h3><p>Submit applications and follow review and approval progress.</p></div></article>
                    <article class="public-feature"><i data-lucide="activity" aria-hidden="true"></i><div><h3>Attendance and leave monitoring</h3><p>Review attendance records and leave credit information.</p></div></article>
                    <article class="public-feature"><i data-lucide="files" aria-hidden="true"></i><div><h3>Document protection</h3><p>Supporting documents stay within authorized HRIS workflows.</p></div></article>
                    <article class="public-feature"><i data-lucide="history" aria-hidden="true"></i><div><h3>Audit trail</h3><p>Recorded activity supports accountability and review.</p></div></article>
                    <article class="public-feature"><i data-lucide="bell" aria-hidden="true"></i><div><h3>Notifications</h3><p>System notifications help users follow relevant application updates.</p></div></article>
                    <article class="public-feature"><i data-lucide="chart-no-axes-combined" aria-hidden="true"></i><div><h3>Administrative reporting</h3><p>Access employee, attendance, and leave reports with CSV exports.</p></div></article>
                </div>
            </div>
        </section>

        <section id="contact" class="public-contact" aria-labelledby="contact-title">
            <div class="public-section-inner public-contact-inner">
                <div>
                    <span class="public-section-kicker">Municipal HRMO</span>
                    <h2 id="contact-title">Human Resource Management Office</h2>
                    <p>Municipality of Camalaniugan<br>Province of Cagayan, Philippines</p>
                    <small>For municipal personnel and authorized system users.</small>
                </div>
                <div class="public-contact-actions">
                    <a class="button public-contact-button" href="{{ route('login') }}">Access the HRIS Portal <span aria-hidden="true">→</span></a>
                    <a class="public-top-link" href="#home">Back to top ↑</a>
                </div>
            </div>
        </section>
    </main>

    <footer class="public-footer">
        <div class="public-footer-inner">
            <a class="public-footer-brand" href="#home"><img src="{{ asset('image/hrislogo.webp') }}" alt="" width="42" height="42" loading="lazy" decoding="async"><span><strong>Municipality of Camalaniugan</strong><small>Human Resource Management Office · Province of Cagayan, Philippines</small></span></a>
            <nav class="public-footer-links" aria-label="Footer navigation">
                <a href="#home">Home</a><a href="#about">About</a><a href="#services">Services</a><a href="#features">Features</a><a href="#contact">Contact</a>
            </nav>
            <a class="public-footer-portal" href="{{ route('login') }}">HRIS Portal <span aria-hidden="true">→</span></a>
        </div>
        <div class="public-copyright">© {{ date('Y') }} Municipality of Camalaniugan. All Rights Reserved.</div>
    </footer>
</body>
</html>
