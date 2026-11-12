import './bootstrap';
import Alpine from 'alpinejs';
import {
    createIcons, Activity, ArrowRight, ArrowUpDown, Bell, BriefcaseBusiness, Building2,
    CalendarDays, ChartNoAxesCombined, ChartNoAxesColumnIncreasing, Check, ChevronDown,
    ChevronRight, CircleAlert, CircleCheck, ClipboardCheck, Clock3, ContactRound, FileClock,
    FilePlus2, Files, History, LayoutDashboard, ListChecks, ListFilter, LogOut, Menu,
    ReceiptText, Search, Settings2, ShieldCheck, UserRound, UserRoundPlus, Users, UsersRound,
    Wallet,
} from 'lucide';
import Chart from 'chart.js/auto';

window.Alpine = Alpine;
Chart.defaults.font.family = '"Inter Variable", Inter, "Segoe UI", Arial, sans-serif';
Chart.defaults.font.size = 12;
Chart.defaults.font.weight = 400;
Chart.defaults.color = '#536678';

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

createIcons({
    icons: {
        Activity, ArrowRight, ArrowUpDown, Bell, BriefcaseBusiness, Building2, CalendarDays,
        ChartNoAxesCombined, ChartNoAxesColumnIncreasing, Check, ChevronDown, ChevronRight,
        CircleAlert, CircleCheck, ClipboardCheck, Clock3, ContactRound, FileClock, FilePlus2,
        Files, History, LayoutDashboard, ListChecks, ListFilter, LogOut, Menu, ReceiptText,
        Search, Settings2, ShieldCheck, UserRound, UserRoundPlus, Users, UsersRound, Wallet,
    },
});

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
};

Alpine.start();
