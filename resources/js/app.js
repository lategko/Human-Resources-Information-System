import './bootstrap';
import Alpine from 'alpinejs';
import {
    createIcons, Activity, ArrowRight, ArrowUpDown, Bell, BriefcaseBusiness, Building2,
    CalendarClock, CalendarDays, ChartNoAxesCombined, ChartNoAxesColumnIncreasing, Check,
    ChevronDown, ChevronRight, CircleAlert, CircleCheck, CircleX, ClipboardCheck, Clock3,
    ContactRound, CornerUpLeft, FileCheck2, FileClock, FilePlus2, FileSpreadsheet, Files, History,
    KeyRound, Landmark, LayoutDashboard, ListChecks, ListFilter, LogOut, Menu, Moon, PanelLeftClose,
    ReceiptText, Search, Settings2, ShieldCheck, Sun, UserRound, UserRoundCheck, UserRoundPen,
    UserRoundPlus, UserRoundX, Users, UsersRound, Wallet,
} from 'lucide';
import Chart from 'chart.js/auto';

window.Alpine = Alpine;
Chart.defaults.font.family = '"Inter Variable", Inter, "Segoe UI", Arial, sans-serif';
Chart.defaults.font.size = 12;
Chart.defaults.font.weight = 400;

window.applyHrisChartTheme = () => {
    const isDark = document.documentElement.dataset.theme === 'dark';
    const textColor = isDark ? '#cbd5e1' : '#536678';
    const gridColor = isDark ? '#334155' : '#edf1f4';

    Chart.defaults.color = textColor;
    document.querySelectorAll('canvas').forEach((canvas) => {
        const chart = Chart.getChart(canvas);
        if (!chart) return;

        Object.values(chart.options.scales ?? {}).forEach((scale) => {
            if (scale.ticks) scale.ticks.color = textColor;
            if (scale.grid && scale.grid.display !== false) scale.grid.color = gridColor;
        });
        chart.update('none');
    });
};

window.applyHrisChartTheme();

window.publicNavigation = () => ({
    menuOpen: false,
    activeSection: 'home',
    init() {
        if (!('IntersectionObserver' in window)) return;

        const observer = new IntersectionObserver((entries) => {
            const visibleSection = entries
                .filter((entry) => entry.isIntersecting)
                .sort((a, b) => Math.abs(a.boundingClientRect.top - 120) - Math.abs(b.boundingClientRect.top - 120))[0];

            if (visibleSection) this.activeSection = visibleSection.target.id;
        }, { rootMargin: '-120px 0px -65% 0px', threshold: 0 });

        document.querySelectorAll('.public-body main section[id]').forEach((section) => observer.observe(section));
    },
});

window.hrisWorkflow = () => ({
    activeStep: 0,
    steps: [
        {
            title: 'Secure Account Access',
            role: 'Employees and authorized municipal personnel',
            action: 'Sign in with a registered email address and password. The HRIS verifies credentials, account status, and assigned permissions.',
            next: 'Users enter only the system areas permitted for their account role.',
        },
        {
            title: 'Employee Information Management',
            role: 'HRMO and authorized administrators; employees for their own permitted information',
            action: 'Authorized personnel manage employee profiles, employment details, department assignments, and personnel records. Employees can view and update permitted personal information.',
            next: 'Employees can use available services, including submitting a leave application.',
        },
        {
            title: 'Leave Application Submission',
            role: 'Any eligible user with a linked employee record',
            action: 'Complete the leave application, provide the required information, attach applicable supporting documents, and submit the request.',
            next: 'For a standard employee request, the application is routed to the assigned Office Head.',
        },
        {
            title: 'Office Head Review',
            role: 'Assigned Office Head, or Mayor acting as an authorized alternate when unavailable',
            action: 'Review department staffing and application completeness, then endorse or return the application. An Office Head does not endorse their own application.',
            next: 'An Office Head endorsement moves the application to HRMO verification. An Office Head applying for leave goes directly to HRMO.',
        },
        {
            title: 'HRMO Verification',
            role: 'Admin / HRMO or an assigned alternate HR reviewer for an Admin applicant',
            action: 'Verify eligibility, applicable leave credits, working days, and supporting documents; record certification or return the application. Certification is not final approval.',
            next: 'After certification, the application proceeds to the Mayor for a separate final decision.',
        },
        {
            title: 'Final Approval',
            role: "Mayor for standard applications; a separately designated authority for the Mayor's own application",
            action: 'Review the certified application and record approval, disapproval, or a permitted return for clarification. No user may approve their own application.',
            next: 'The decision and application status are recorded, applicable leave credits are finalized once, and the employee is notified.',
        },
        {
            title: 'Application Status and Records',
            role: 'Employee and authorized HR personnel',
            action: 'Employees check their application status. Authorized HRMO personnel maintain application records and monitor leave transactions and related HR activity.',
            next: 'The application history remains available to permitted users for follow-up and accountability.',
        },
    ],
    selectStep(index) {
        if (index >= 0 && index < this.steps.length) this.activeStep = index;
    },
    previousStep() {
        this.selectStep(this.activeStep - 1);
    },
    nextStep() {
        this.selectStep(this.activeStep + 1);
    },
});

createIcons({
    icons: {
        Activity, ArrowRight, ArrowUpDown, Bell, BriefcaseBusiness, Building2, CalendarDays,
        ChartNoAxesCombined, ChartNoAxesColumnIncreasing, Check, ChevronDown, ChevronRight,
        CalendarClock, CircleAlert, CircleCheck, CircleX, ClipboardCheck, Clock3,
        ContactRound, CornerUpLeft, FileCheck2, FileClock, FilePlus2, FileSpreadsheet, Files, History,
        KeyRound, Landmark, LayoutDashboard, ListChecks, ListFilter, LogOut, Menu, Moon, PanelLeftClose,
        ReceiptText, Search, Settings2, ShieldCheck, Sun, UserRound, UserRoundCheck, UserRoundPen,
        UserRoundPlus, UserRoundX, Users, UsersRound, Wallet,
    },
});

const themeToggle = document.querySelector('[data-theme-toggle]');

if (themeToggle) {
    const updateThemeControl = () => {
        const isDark = document.documentElement.dataset.theme === 'dark';
        themeToggle.setAttribute('aria-pressed', String(isDark));
        themeToggle.setAttribute('aria-label', isDark ? 'Switch to light mode' : 'Switch to dark mode');
        themeToggle.querySelector('span').textContent = isDark ? 'Light mode' : 'Dark mode';
    };

    updateThemeControl();
    themeToggle.addEventListener('click', () => {
        const nextTheme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
        document.documentElement.dataset.theme = nextTheme;
        localStorage.setItem('hris-theme', nextTheme);
        updateThemeControl();
        window.applyHrisChartTheme();
    });
}

window.leaveApplicationForm = (types, selectedId, workingDaysUrl) => ({
    types,
    selectedId: selectedId ? String(selectedId) : '',
    selectedType: null,
    startDate: '',
    endDate: '',
    workingDays: null,
    documentUploads: [],
    dayMessage: 'The server calculates weekdays and excludes configured holidays when dates are selected.',
    init() {
        this.selectedType = this.types.find((type) => String(type.id) === this.selectedId) ?? null;
        this.startDate = this.$root.querySelector('[name="start_date"]')?.value ?? '';
        this.endDate = this.$root.querySelector('[name="end_date"]')?.value ?? '';
        if (this.startDate && this.endDate) this.refreshDays();
    },
    updateType() {
        this.selectedType = this.types.find((type) => String(type.id) === this.selectedId) ?? null;
    },
    setDocuments(event) {
        this.documentUploads = Array.from(event.target.files ?? []).map((file, index) => ({
            name: file.name,
            index,
        }));
    },
    async refreshDays() {
        if (!this.startDate || !this.endDate) {
            this.workingDays = null;
            return;
        }
        this.dayMessage = 'Calculating working days…';
        try {
            const query = new URLSearchParams({ start: this.startDate, end: this.endDate });
            const response = await fetch(`${workingDaysUrl}?${query}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!response.ok) throw new Error('Unable to calculate working days for these dates.');
            const result = await response.json();
            this.workingDays = result.working_days;
            this.dayMessage = 'Calculated by the HRIS using the current workweek and configured municipal holidays. HR may verify this value.';
        } catch (error) {
            this.workingDays = null;
            this.dayMessage = error.message;
        }
    },
});

document.addEventListener('alpine:init', () => {
    document.querySelectorAll('[data-lucide]').forEach((icon) => icon.setAttribute('aria-hidden', 'true'));
});

window.drawAttendanceChart = (data) => {
    const canvas = document.getElementById('attendanceTrend');
    if (!canvas) return;
    new Chart(canvas, {
        type: 'line',
        data: {
            labels: data.labels,
            datasets: [
                { label: 'Present', data: data.present, borderColor: '#1478b9', backgroundColor: '#1478b9', tension: .35 },
                { label: 'Late', data: data.late, borderColor: '#d5a500', backgroundColor: '#d5a500', tension: .35 },
                { label: 'Absent', data: data.absent, borderColor: '#c82027', backgroundColor: '#c82027', tension: .35 },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 7, font: { size: 10 } } } },
            scales: { y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#edf1f4' } }, x: { grid: { display: false } } },
        },
    });
    window.applyHrisChartTheme();
};

window.drawLeaveChart = (data) => {
    const canvas = document.getElementById('leaveApplicationsTrend');
    if (!canvas) return;
    new Chart(canvas, {
        type: 'bar',
        data: {
            labels: data.labels,
            datasets: [{
                label: 'Applications filed',
                data: data.applications,
                backgroundColor: '#1478b9',
                borderRadius: 4,
                maxBarThickness: 26,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#edf1f4' } },
                x: { grid: { display: false } },
            },
        },
    });
    window.applyHrisChartTheme();
};

Alpine.start();
