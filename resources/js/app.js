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

const modal = document.querySelector('[data-hris-modal]');
const modalTitle = modal?.querySelector('[data-hris-modal-title]');
const modalKind = modal?.querySelector('[data-hris-modal-kind]');
const modalDescription = modal?.querySelector('[data-hris-modal-description]');
const modalIndicator = modal?.querySelector('[data-hris-modal-indicator]');
const modalCancel = modal?.querySelector('[data-hris-modal-cancel]');
const modalConfirm = modal?.querySelector('[data-hris-modal-confirm]');
let modalResolver = null;

const modalTypes = {
    confirmation: { label: 'Confirmation', tone: 'info', role: 'alertdialog' },
    form: { label: 'Confirm changes', tone: 'info', role: 'alertdialog' },
    delete: { label: 'Delete confirmation', tone: 'danger', role: 'alertdialog' },
    approval: { label: 'Approval confirmation', tone: 'success', role: 'alertdialog' },
    rejection: { label: 'Rejection confirmation', tone: 'danger', role: 'alertdialog' },
    import: { label: 'Import confirmation', tone: 'info', role: 'alertdialog' },
    success: { label: 'Success', tone: 'success', role: 'dialog' },
    warning: { label: 'Warning', tone: 'warning', role: 'alertdialog' },
    error: { label: 'Error', tone: 'danger', role: 'alertdialog' },
    info: { label: 'Information', tone: 'info', role: 'dialog' },
};

const finishModal = (confirmed) => {
    if (!modal || !modal.open) return;
    const resolve = modalResolver;
    modalResolver = null;
    modal.close();
    resolve?.(confirmed);
};

const openModal = (options = {}, confirmation = false) => {
    if (!modal || !modal.showModal || !modalTitle || !modalDescription || !modalConfirm) {
        window.hrisNotify?.(
            'error',
            'Confirmation unavailable',
            'The dialog could not be opened, so the requested action was not submitted.'
        );
        return Promise.resolve(false);
    }

    if (modal.open) finishModal(false);

    const type = modalTypes[options.type] ? options.type : 'confirmation';
    const typeInfo = modalTypes[type];
    modal.dataset.tone = typeInfo.tone;
    modal.setAttribute('role', typeInfo.role);
    modalKind.textContent = options.kind || typeInfo.label;
    modalTitle.textContent = options.title || (confirmation ? 'Confirm action' : typeInfo.label);
    modalDescription.textContent = options.message || 'Please review this action before continuing.';
    modalConfirm.textContent = options.confirmText || (confirmation ? 'Confirm' : 'Close');
    modalConfirm.classList.toggle('button-danger', typeInfo.tone === 'danger');
    modalConfirm.classList.toggle('button-primary', typeInfo.tone !== 'danger');
    modalCancel.hidden = !confirmation;
    modalCancel.textContent = options.cancelText || 'Cancel';
    modalIndicator?.setAttribute('aria-label', typeInfo.label);

    return new Promise((resolve) => {
        modalResolver = resolve;
        modal.showModal();
        modalConfirm.focus();
    });
};

window.HrisModal = {
    confirm: (options) => openModal(options, true),
    show: (options) => openModal(options, false),
};

window.hrisNotify = (type, title, message) => {
    const region = document.getElementById('hris-toast-region');
    if (!region) {
        console.error(`[HRIS ${type}] ${title}: ${message}`);
        return;
    }

    const notice = document.createElement('section');
    notice.className = `hris-toast hris-toast-${modalTypes[type]?.tone ?? 'info'}`;
    notice.setAttribute('role', type === 'error' ? 'alert' : 'status');
    notice.dataset.notificationKey = `${type}\u0000${title}\u0000${message}`;

    const copy = document.createElement('div');
    copy.className = 'hris-toast-copy';
    const heading = document.createElement('strong');
    heading.textContent = title;
    const description = document.createElement('p');
    description.textContent = message;
    copy.append(heading, description);

    const dismiss = document.createElement('button');
    dismiss.type = 'button';
    dismiss.className = 'hris-notice-dismiss';
    dismiss.textContent = 'Dismiss';
    dismiss.setAttribute('aria-label', 'Dismiss notification');
    dismiss.addEventListener('click', () => notice.remove());
    notice.append(copy, dismiss);

    const duplicate = [...region.children].find((item) => item.dataset.notificationKey === notice.dataset.notificationKey);
    duplicate?.remove();
    region.append(notice);

    if (type !== 'error') {
        window.setTimeout(() => notice.remove(), type === 'warning' ? 10000 : 7000);
    }
};

document.addEventListener('click', (event) => {
    if (!(event.target instanceof Element)) return;
    event.target.closest('[data-dismiss-notice]')?.closest('.hris-notice')?.remove();
});

modalCancel?.addEventListener('click', () => finishModal(false));
modalConfirm?.addEventListener('click', () => finishModal(true));
modal?.addEventListener('cancel', (event) => {
    event.preventDefault();
    finishModal(false);
});
modal?.addEventListener('click', (event) => {
    if (event.target === modal) finishModal(false);
});
modal?.addEventListener('close', () => {
    const resolve = modalResolver;
    modalResolver = null;
    resolve?.(false);
});

document.addEventListener('submit', (event) => {
    if (event.defaultPrevented || !(event.target instanceof HTMLFormElement)) return;

    const form = event.target;
    if (form.method.toUpperCase() === 'GET') return;

    const submitter = event.submitter;
    const confirmSource = submitter?.hasAttribute('data-confirm-title') ? submitter : form;
    const confirmTitle = confirmSource.dataset.confirmTitle;

    if (confirmTitle && form.dataset.hrisConfirmed !== 'true') {
        event.preventDefault();
        if (form.dataset.hrisConfirmPending === 'true' || form.dataset.hrisSubmitting === 'true') return;

        const statusChoice = form.querySelector('[data-confirm-status]');
        let message = confirmSource.dataset.confirmMessage || 'Please confirm that you want to continue.';
        let title = confirmTitle;
        let type = confirmSource.dataset.confirmType || 'form';
        let confirmText = confirmSource.dataset.confirmText || 'Continue';

        if (statusChoice?.value === 'Approved') {
            title = 'Approve Attendance Exception?';
            message = 'Confirm that this attendance exception should be approved for HR review.';
            type = 'approval';
            confirmText = 'Approve Exception';
        } else if (statusChoice?.value === 'Rejected') {
            title = 'Reject Attendance Exception?';
            message = 'Confirm that this attendance exception should be rejected.';
            type = 'rejection';
            confirmText = 'Reject Exception';
        }

        form.dataset.hrisConfirmPending = 'true';
        window.HrisModal.confirm({
            type,
            title,
            message,
            confirmText,
            cancelText: confirmSource.dataset.cancelText || 'Cancel',
        }).then((confirmed) => {
            delete form.dataset.hrisConfirmPending;
            if (!confirmed) return;

            form.dataset.hrisConfirmed = 'true';
            if (submitter) form.requestSubmit(submitter);
            else form.requestSubmit();
        }).catch((error) => {
            delete form.dataset.hrisConfirmPending;
            window.hrisNotify('error', 'Confirmation failed', error.message || 'The action was not submitted.');
        });

        return;
    }

    if (form.dataset.hrisConfirmed === 'true') delete form.dataset.hrisConfirmed;
    if (form.dataset.hrisSubmitting === 'true') {
        event.preventDefault();
        return;
    }

    form.dataset.hrisSubmitting = 'true';
    form.setAttribute('aria-busy', 'true');
    form.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"]').forEach((button) => {
        const loadingText = button === submitter ? button.dataset.loadingText : null;
        if (button === submitter && button instanceof HTMLButtonElement && !button.querySelector('[x-text]')) {
            button.textContent = loadingText || 'Processing…';
        }
        button.disabled = true;
    });
});

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
            action: 'Sign in using a registered username or email address and password. The HRIS verifies credentials, account status, and assigned permissions.',
            next: 'Users enter only the system areas permitted for their account role.',
        },
        {
            title: 'Employee Information Management',
            role: 'HRMO and authorized administrators; employees for their own permitted information',
            action: 'Authorized personnel manage employee profiles, employment details, department assignments, and personnel records. Employees can view and update permitted personal information.',
            next: 'Employees with a linked employee record can use available services, including submitting a leave application.',
        },
        {
            title: 'Leave Application Submission',
            role: 'Any eligible user with a linked employee record',
            action: 'Complete the leave application, provide the required information, attach applicable supporting documents, and submit the request.',
            next: 'Submitted applications enter HRMO verification first. Drafts remain drafts until submitted.',
        },
        {
            title: 'HRMO Verification and Processing',
            role: 'Admin / HRMO or a separately assigned alternate HR reviewer when required',
            action: 'Verify eligibility, applicable leave credits, working days, and supporting documents; record certification or return the application. HRMO certification is not final approval.',
            next: 'After verification, the application is routed for Office Head review. Required alternate reviewers are explicitly assigned.',
        },
        {
            title: 'Office Head Review',
            role: 'Assigned Office Head or an authorized alternate when the Office Head is unavailable',
            action: 'Review the department application and endorse it for a decision or return it for correction. The assigned Office Head cannot review their own application.',
            next: 'The Mayor makes the separate final decision. A Mayor’s personal application requires an explicitly designated authorized final approver.',
        },
        {
            title: 'Attendance and Leave Monitoring',
            role: 'Employees and authorized municipal personnel',
            action: 'Employees use GPS checks at configured workplaces to record attendance. Authorized HRMO personnel monitor records, process permitted corrections, and manage leave credits.',
            next: 'Configured workweeks and holidays inform applicable personnel processes; monthly leave accrual is managed by the system.',
        },
        {
            title: 'Reports and Administrative Management',
            role: 'Authorized administrators, HRMO, and other permitted municipal roles',
            action: 'Review available employee, attendance, and leave reports. Admin / HRMO personnel manage organization records, accounts, notifications, and system settings within their permissions.',
            next: 'Access to reports and administrative functions follows each account’s designated role permissions.',
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

window.gpsAttendance = (timeInUrl, timeOutUrl) => ({
    busy: false,
    message: '',
    async submit(action) {
        if (!navigator.geolocation) {
            this.message = 'Unable to retrieve your current location. Please try again.';
            return;
        }
        this.busy = true;
        this.message = 'Retrieving your verified location…';
        navigator.geolocation.getCurrentPosition(async (position) => {
            try {
                const response = await fetch(action === 'time-in' ? timeInUrl : timeOutUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: new URLSearchParams({
                        latitude: String(position.coords.latitude),
                        longitude: String(position.coords.longitude),
                        accuracy_meters: String(position.coords.accuracy),
                    }),
                });
                const contentType = response.headers.get('content-type') || '';
                if (response.ok) {
                    window.location.reload();
                    return;
                }
                const result = contentType.includes('application/json')
                    ? await response.json()
                    : null;
                this.message = Object.values(result?.errors ?? {})[0]?.[0]
                    ?? 'Unable to record attendance. Please try again.';
            } catch (error) {
                this.message = 'Unable to retrieve your current location. Please try again.';
            } finally {
                this.busy = false;
            }
        }, (error) => {
            this.busy = false;
            this.message = error.code === error.PERMISSION_DENIED
                ? 'Location access is required to record your attendance. Please enable location services and allow location access.'
                : 'Unable to retrieve your current location. Please try again.';
        }, {
            enableHighAccuracy: true,
            timeout: 15000,
            maximumAge: 0,
        });
    },
});

window.workplaceMapPicker = (options) => {
    let L;
    let map;
    let marker;
    let geofence;

    return {
        searchUrl: options.searchUrl,
        reverseUrl: options.reverseUrl,
        latitude: options.latitude ?? '',
        longitude: options.longitude ?? '',
        address: options.address ?? '',
        radius: Number(options.radius ?? 100),
        searchTerm: '',
        searchResults: [],
        busy: false,
        message: '',
        async init() {
            try {
                const [leafletModule] = await Promise.all([
                    import('leaflet'),
                    import('leaflet/dist/leaflet.css'),
                ]);
                L = leafletModule.default;
                map = L.map(this.$refs.map).setView(
                    this.hasCoordinates() ? [Number(this.latitude), Number(this.longitude)] : [18.2, 121.65],
                    this.hasCoordinates() ? 17 : 12,
                );
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                }).addTo(map);
                map.on('click', (event) => this.updatePosition(event.latlng.lat, event.latlng.lng));
                if (this.hasCoordinates()) this.setMarker(Number(this.latitude), Number(this.longitude));
                new ResizeObserver(() => this.invalidateMap()).observe(this.$refs.map);
            } catch {
                this.message = 'The map could not be loaded. Check your connection and try again.';
            }
        },
        hasCoordinates() {
            return this.latitude !== '' && this.longitude !== '';
        },
        invalidateMap() {
            map?.invalidateSize();
        },
        setMarker(latitude, longitude) {
            const point = [latitude, longitude];
            if (!marker) {
                marker = L.marker(point, {
                    draggable: true,
                    icon: L.divIcon({
                        className: 'workplace-map-marker',
                        html: '<span></span>',
                        iconSize: [22, 22],
                        iconAnchor: [11, 11],
                    }),
                }).addTo(map);
                marker.on('dragend', (event) => {
                    const position = event.target.getLatLng();
                    this.updatePosition(position.lat, position.lng);
                });
            } else {
                marker.setLatLng(point);
            }

            if (!geofence) {
                geofence = L.circle(point, {
                    radius: this.radius,
                    color: '#17689c',
                    weight: 2,
                    fillColor: '#3b82f6',
                    fillOpacity: 0.12,
                }).addTo(map);
            } else {
                geofence.setLatLng(point);
                geofence.setRadius(this.radius);
            }
        },
        async updatePosition(latitude, longitude, reverse = true) {
            this.latitude = Number(latitude).toFixed(7);
            this.longitude = Number(longitude).toFixed(7);
            this.setMarker(Number(this.latitude), Number(this.longitude));
            map.setView([Number(this.latitude), Number(this.longitude)], Math.max(map.getZoom(), 17));
            this.message = '';
            if (reverse) await this.reverseGeocode();
        },
        updateRadius() {
            if (geofence) geofence.setRadius(Number(this.radius));
        },
        async search() {
            const query = this.searchTerm.trim();
            if (!query) {
                this.message = 'Enter a workplace name or address to search.';
                this.searchResults = [];
                return;
            }
            this.busy = true;
            this.message = 'Searching OpenStreetMap…';
            this.searchResults = [];
            try {
                const response = await fetch(`${this.searchUrl}?q=${encodeURIComponent(query)}`, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                const results = await response.json();
                if (!response.ok) throw new Error(results.message || 'Location search is temporarily unavailable.');
                this.searchResults = results;
                this.message = results.length ? '' : 'No matching places found. Try a more specific name or address.';
            } catch (error) {
                this.message = error.message || 'Location search is temporarily unavailable.';
            } finally {
                this.busy = false;
            }
        },
        selectResult(result) {
            this.searchResults = [];
            this.searchTerm = result.display_name;
            this.address = result.display_name.slice(0, 255);
            this.updatePosition(result.latitude, result.longitude, false);
        },
        async reverseGeocode() {
            try {
                const params = new URLSearchParams({ latitude: this.latitude, longitude: this.longitude });
                const response = await fetch(`${this.reverseUrl}?${params}`, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                const result = await response.json();
                if (response.ok && result.display_name) {
                    this.address = result.display_name.slice(0, 255);
                    this.searchTerm = result.display_name;
                }
            } catch {
                this.message = 'Coordinates were updated, but the address could not be retrieved.';
            }
        },
        useCurrentLocation() {
            this.message = '';
            if (!navigator.geolocation) {
                this.message = 'Location services are not available in this browser.';
                return;
            }
            this.busy = true;
            navigator.geolocation.getCurrentPosition(
                async (position) => {
                    await this.updatePosition(position.coords.latitude, position.coords.longitude);
                    this.busy = false;
                },
                (error) => {
                    this.busy = false;
                    this.message = error.code === error.PERMISSION_DENIED
                        ? 'Location access was denied. Allow location access in your browser and try again.'
                        : 'Unable to retrieve your current location. Please try again.';
                },
                { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
            );
        },
    };
};

window.workplaceAssignments = (options) => ({
    activeTab: options.activeTab,
    departmentEmployees: options.departmentEmployees,
    departmentPreviewUrl: options.departmentPreviewUrl,
    employees: options.employees,
    visibleEmployeeIds: options.visibleEmployeeIds.map(String),
    selectedEmployeeIds: [],
    selectedDepartment: options.selectedDepartment ? String(options.selectedDepartment) : '',
    selectedEffectiveDate: options.selectedEffectiveDate,
    previewBusy: false,
    previewError: '',
    previewRequestId: 0,
    selectedEmployee: options.selectedEmployee ? String(options.selectedEmployee) : '',
    employeeSearch: '',
    async init() {
        if (this.selectedDepartment) await this.refreshDepartmentRoster();
    },
    departmentRoster() {
        return this.departmentEmployees[this.selectedDepartment] ?? [];
    },
    individualOverrideCount() {
        return this.departmentRoster().filter((employee) => ['individual', 'bulk'].includes(employee.assignment_type)).length;
    },
    filteredEmployees() {
        const query = this.employeeSearch.trim().toLowerCase();
        return Object.entries(this.employees)
            .filter(([id, employee]) => !query || `${employee.employee_no} ${employee.name}`.toLowerCase().includes(query))
            .map(([id, employee]) => ({ id, ...employee }));
    },
    selectedEmployeeDetails() {
        return this.employees[this.selectedEmployee] ?? null;
    },
    async refreshDepartmentRoster() {
        const requestId = ++this.previewRequestId;
        if (!this.selectedDepartment || !this.selectedEffectiveDate) {
            this.departmentEmployees = { ...this.departmentEmployees, [this.selectedDepartment]: [] };
            this.previewBusy = false;
            this.previewError = '';
            return;
        }
        this.previewBusy = true;
        this.previewError = '';
        try {
            const query = new URLSearchParams({
                department_id: this.selectedDepartment,
                effective_from: this.selectedEffectiveDate,
            });
            const response = await fetch(`${this.departmentPreviewUrl}?${query}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.message || 'Unable to load affected employees.');
            if (requestId !== this.previewRequestId) return;
            this.departmentEmployees = {
                ...this.departmentEmployees,
                [this.selectedDepartment]: result.employees,
            };
        } catch (error) {
            if (requestId !== this.previewRequestId) return;
            this.previewError = error.message || 'Unable to load affected employees.';
        } finally {
            if (requestId === this.previewRequestId) this.previewBusy = false;
        }
    },
    selectVisibleEmployees(event) {
        const visible = new Set(this.visibleEmployeeIds);
        this.selectedEmployeeIds = event.target.checked
            ? [...new Set([...this.selectedEmployeeIds, ...this.visibleEmployeeIds])]
            : this.selectedEmployeeIds.filter((id) => !visible.has(id));
    },
    async confirmDepartmentAssignment(event) {
        const roster = this.departmentRoster();
        if (this.previewBusy || this.previewError) {
            window.hrisNotify('error', 'Workplace assignment unavailable', this.previewError || 'Wait for the affected employee list to finish loading.');
            return;
        }
        if (!roster.length) {
            window.hrisNotify('warning', 'No employees to update', 'There are no active employees in the selected department.');
            return;
        }
        const form = event.currentTarget;
        if (form.dataset.hrisSubmitting === 'true') return;
        const replaced = form.querySelector('[name="replace_individual_assignments"]')?.checked
            ? this.individualOverrideCount()
            : 0;
        let confirmed;
        try {
            confirmed = await window.HrisModal.confirm({
                type: 'form',
                title: 'Apply Department Workplace?',
                message: `This will assign the selected workplace to ${roster.length} employees.${replaced ? ` It will replace ${replaced} individual assignments.` : ''}`,
                confirmText: 'Apply Assignment',
            });
        } catch (error) {
            window.hrisNotify('error', 'Confirmation failed', error.message || 'The assignment was not submitted.');
            return;
        }
        if (!confirmed) return;
        form.dataset.hrisSubmitting = 'true';
        const submitButton = form.querySelector('[type="submit"]');
        if (submitButton instanceof HTMLButtonElement) {
            submitButton.disabled = true;
            submitButton.textContent = 'Assigning…';
        }
        form.submit();
    },
    async confirmBulkAssignment(event) {
        if (!this.selectedEmployeeIds.length) {
            window.hrisNotify('warning', 'No employees selected', 'Select at least one employee before assigning a workplace.');
            return;
        }
        const form = event.currentTarget;
        if (form.dataset.hrisSubmitting === 'true') return;
        let confirmed;
        try {
            confirmed = await window.HrisModal.confirm({
                type: 'form',
                title: 'Assign Workplace to Employees?',
                message: `The selected workplace will be assigned to ${this.selectedEmployeeIds.length} employee(s).`,
                confirmText: 'Assign Workplace',
            });
        } catch (error) {
            window.hrisNotify('error', 'Confirmation failed', error.message || 'The assignment was not submitted.');
            return;
        }
        if (!confirmed) return;
        form.dataset.hrisSubmitting = 'true';
        const submitButton = form.querySelector('[type="submit"]');
        if (submitButton instanceof HTMLButtonElement) {
            submitButton.disabled = true;
            submitButton.textContent = 'Assigning…';
        }
        form.submit();
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
