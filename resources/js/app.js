import './bootstrap';
import '../css/app.css';
import Alpine from 'alpinejs';
import { createIcons, ArrowRight, Bell, CalendarDays, Check, ChevronDown, Clock3, FileText, House, Menu, Search, Users } from 'lucide';
import Chart from 'chart.js/auto';

window.Alpine = Alpine;

createIcons({
    icons: { ArrowRight, Bell, CalendarDays, Check, ChevronDown, Clock3, FileText, House, Menu, Search, Users },
});

window.leaveApplicationForm = (types, selectedId, workingDaysUrl) => ({
    types,
    selectedId: selectedId ? String(selectedId) : '',
    selectedType: null,
    startDate: '',
    endDate: '',
    workingDays: null,
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
                { label: 'Present', data: data.present, borderColor: '#3579a8', backgroundColor: '#3579a8', tension: .35 },
                { label: 'Late', data: data.late, borderColor: '#d09a42', backgroundColor: '#d09a42', tension: .35 },
                { label: 'Absent', data: data.absent, borderColor: '#c95b5b', backgroundColor: '#c95b5b', tension: .35 },
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

Alpine.start();
