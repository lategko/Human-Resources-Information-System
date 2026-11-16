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
            <a href="#how-it-works" :aria-current="activeSection === 'how-it-works' ? 'page' : null" @click="menuOpen = false">How It Works</a>
            <a href="#contact" :aria-current="activeSection === 'contact' ? 'page' : null" @click="menuOpen = false">Contact</a>
            <a class="button button-primary public-sign-in" href="{{ route('login') }}">Sign in</a>
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
                        <a class="button button-primary public-hero-primary" href="{{ route('login') }}">Access HRIS Portal</a>
                        <a class="button public-hero-secondary" href="#services">Explore Our Services</a>
                        <a class="button public-hero-secondary public-hero-workflow" href="#how-it-works">How It Works</a>
                    </div>
                    <p class="public-hero-note">Secure access for authorized municipal personnel</p>
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
            <a class="public-scroll-cue" href="#about">Discover the HRIS</a>
        </section>

        <section id="about" class="public-section public-about" aria-labelledby="about-title">
            <div class="public-section-inner public-about-layout">
                <div class="public-about-photo">
                    <img src="{{ asset('image/login.background.jpg') }}" alt="Municipal building of Camalaniugan, Cagayan" width="1195" height="800" loading="lazy" decoding="async">
                    <div class="public-photo-caption"><span>Municipality of Camalaniugan<small>Province of Cagayan, Philippines</small></span></div>
                </div>
                <div class="public-about-copy">
                    <span class="public-section-kicker">About the HRMO</span>
                    <h2 id="about-title">About Our Human Resource Information System</h2>
                    <p class="public-lead">A centralized digital platform supporting the Municipality of Camalaniugan's human resource operations.</p>
                    <p>The HRIS brings essential personnel processes together in one organized system. It helps the Human Resource Management Office manage employee information and supports municipal personnel through clearer, more efficient service workflows.</p>
                    <ul class="public-check-list">
                        <li>Organized employee records and employment information</li>
                        <li>Efficient leave applications and review workflows</li>
                        <li>Attendance monitoring and leave credit tracking</li>
                        <li>Transparent review, approval, and personnel administration</li>
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
                        <h3>Employee Management</h3>
                        <p>Centralized employee profiles, employment information, departments, and positions.</p>
                    </article>
                    <article class="public-service-card">
                        <h3>Leave Management</h3>
                        <p>Digital leave applications, supporting documents, review and approval, and application tracking.</p>
                    </article>
                    <article class="public-service-card">
                        <h3>Attendance Monitoring</h3>
                        <p>Attendance records, biometric CSV imports, attendance corrections, and monitoring.</p>
                    </article>
                    <article class="public-service-card">
                        <h3>Leave Credits</h3>
                        <p>Employee leave balances, credit adjustments, and transaction history.</p>
                    </article>
                    <article class="public-service-card">
                        <h3>Reports and Analytics</h3>
                        <p>Employee reports, attendance summaries, leave reports, and downloadable CSV reports.</p>
                    </article>
                    <article class="public-service-card">
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
                    <article class="public-feature"><div><h3>Centralized Employee Information</h3><p>Keep employee profiles and work information organized in one system.</p></div></article>
                    <article class="public-feature"><div><h3>Role-Based Access Control</h3><p>Access is assigned according to authorized account roles.</p></div></article>
                    <article class="public-feature"><div><h3>Digital Leave Application and Approval</h3><p>Submit applications and follow review and approval progress.</p></div></article>
                    <article class="public-feature"><div><h3>Attendance and Leave Monitoring</h3><p>Review attendance records and leave credit information.</p></div></article>
                    <article class="public-feature"><div><h3>Document Management</h3><p>Supporting documents stay within authorized HRIS workflows.</p></div></article>
                    <article class="public-feature"><div><h3>Audit Trail and Accountability</h3><p>Recorded activity supports accountability and review.</p></div></article>
                    <article class="public-feature"><div><h3>System Notifications</h3><p>System notifications help users follow relevant application updates.</p></div></article>
                    <article class="public-feature"><div><h3>Administrative Reporting</h3><p>Access employee, attendance, and leave reports with CSV exports.</p></div></article>
                </div>
            </div>
        </section>

        <section id="how-it-works" class="public-section public-workflow" aria-labelledby="workflow-title" x-data="hrisWorkflow()">
            <div class="public-section-inner">
                <div class="public-section-heading public-workflow-heading">
                    <span class="public-section-kicker">The employee service process</span>
                    <h2 id="workflow-title">How the HRIS Works</h2>
                    <p>Understand how the Municipality of Camalaniugan Human Resource Information System simplifies employee services, human resource operations, and administrative processes through a structured digital workflow.</p>
                </div>

                <div class="workflow-process" aria-label="Leave application process">
                    <ol class="workflow-steps" aria-label="Select a process step">
                        <template x-for="(step, index) in steps" :key="step.title">
                            <li>
                                <button
                                    type="button"
                                    class="workflow-step"
                                    :class="{ 'is-active': activeStep === index }"
                                    :aria-pressed="activeStep === index"
                                    @click="selectStep(index)"
                                >
                                    <span class="workflow-step-number" x-text="String(index + 1).padStart(2, '0')"></span>
                                    <span class="workflow-step-title" x-text="step.title"></span>
                                </button>
                            </li>
                        </template>
                    </ol>

                    <div class="workflow-detail" aria-live="polite" aria-atomic="true">
                        <div class="workflow-detail-heading">
                            <span class="workflow-detail-number" x-text="'Step ' + (activeStep + 1)"></span>
                            <h3 x-text="steps[activeStep].title">Secure Account Access</h3>
                        </div>
                        <dl class="workflow-detail-grid">
                            <div>
                                <dt>Responsible</dt>
                                <dd x-text="steps[activeStep].role">Employees and authorized personnel</dd>
                            </div>
                            <div>
                                <dt>Action</dt>
                                <dd x-text="steps[activeStep].action">Sign in with a registered email and password. The HRIS checks credentials and assigned permissions.</dd>
                            </div>
                            <div>
                                <dt>What happens next</dt>
                                <dd x-text="steps[activeStep].next">Users enter the system areas permitted for their account role.</dd>
                            </div>
                        </dl>
                        <div class="workflow-controls" aria-label="Workflow step controls">
                            <button type="button" class="workflow-control" @click="previousStep()" :disabled="activeStep === 0">Previous step</button>
                            <span x-text="(activeStep + 1) + ' of ' + steps.length"></span>
                            <button type="button" class="workflow-control" @click="nextStep()" :disabled="activeStep === steps.length - 1">Next step</button>
                        </div>
                    </div>
                </div>

                <p class="workflow-exception"><strong>Alternate review:</strong> If an assigned Office Head is unavailable, the application may be routed to an authorized alternate. The alternate records a decision before HRMO verification; HRMO then verifies the supporting documents and finalizes the recorded outcome.</p>

                <div class="workflow-services">
                    <div class="workflow-services-heading">
                        <span class="public-section-kicker">Other available HR services</span>
                        <h3>Additional personnel processes</h3>
                        <p>Authorized users work with these modules according to their assigned account permissions.</p>
                    </div>
                    <ul class="workflow-services-list">
                        <li><h4>Employee Profile Management</h4><p>HRMO and administrators manage personnel profiles; employees can view and update permitted personal information.</p></li>
                        <li><h4>Attendance Monitoring</h4><p>Authorized staff review attendance records, import biometric CSV data, and process permitted corrections.</p></li>
                        <li><h4>Leave Credits Monitoring</h4><p>Employees can review leave balances and transactions; authorized HR staff maintain and adjust credits.</p></li>
                        <li><h4>Document Management</h4><p>Employees submit leave supporting documents, which authorized reviewers access as part of application processing.</p></li>
                        <li><h4>Reports and Analytics</h4><p>Authorized roles review employee, attendance, and leave reports, including available CSV exports.</p></li>
                        <li><h4>Account and Access Management</h4><p>Authorized administrators manage user accounts and role-based access within their permissions.</p></li>
                    </ul>
                </div>

                <div class="workflow-cta">
                    <div>
                        <h3>Ready to Access Municipal HR Services?</h3>
                        <p>Access the Municipality of Camalaniugan Human Resource Information System through your authorized account.</p>
                    </div>
                    <a class="button button-primary workflow-cta-button" href="{{ route('login') }}">Access HRIS Portal</a>
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
                    <a class="button public-contact-button" href="{{ route('login') }}">Access the HRIS Portal</a>
                    <a class="public-top-link" href="#home">Back to top</a>
                </div>
            </div>
        </section>
    </main>

    <footer class="public-footer">
        <div class="public-footer-inner">
            <a class="public-footer-brand" href="#home"><img src="{{ asset('image/hrislogo.webp') }}" alt="" width="42" height="42" loading="lazy" decoding="async"><span><strong>Municipality of Camalaniugan</strong><small>Human Resource Management Office · Province of Cagayan, Philippines</small></span></a>
            <nav class="public-footer-links" aria-label="Footer navigation">
                <a href="#home">Home</a><a href="#about">About</a><a href="#services">Services</a><a href="#features">Features</a><a href="#how-it-works">How It Works</a><a href="#contact">Contact</a>
            </nav>
            <a class="public-footer-portal" href="{{ route('login') }}">HRIS Portal</a>
        </div>
        <div class="public-copyright">© {{ date('Y') }} Municipality of Camalaniugan. All Rights Reserved.</div>
    </footer>
</body>
</html>
