# Municipality of Camalaniugan HRIS prototype

A Laravel 12, Blade, Tailwind CSS, Alpine.js, and MySQL-ready HRIS prototype for employee records, digital leave applications, attendance imports, and review workflows. It is an operational starting point, not a certified payroll or legal-entitlement system.

## Requirements

- PHP 8.2+ with `pdo_mysql`, `pdo_sqlite`, `mbstring`, `openssl`, `fileinfo`, and `xml`
- Composer 2
- Node.js and npm
- MySQL 8+ (or MariaDB supported by Laravel)

## Local installation

1. Create a MySQL database, for example `camalaniugan_hris`.
2. Copy `.env.example` to `.env`.
3. Set `APP_URL`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` in `.env`.
4. Install and initialize the application:

   ```powershell
   composer install
   php artisan key:generate
   npm install
   php artisan migrate --seed
   npm run build
   php artisan serve
   ```

5. Open the URL printed by `php artisan serve`.

The checked-in local `.env` in this workspace uses SQLite for development. Fresh installations use MySQL from `.env.example`. `php artisan migrate --seed` creates the demo roles, department, employee, leave types, and sample credits. Seeded credit balances are demonstration data only.

Supporting leave documents are written to Laravel's private `local` disk (`storage/app/private`), not to the public storage directory. Keep that directory access-controlled and include it in secure backups.

## Demo accounts

All demo accounts use **`ChangeMe123!`**:

| Role | Email |
|---|---|
| Mayor / Administrator and designated final approver | `admin@camalaniugan.gov.ph` |
| HRMO | `hrmo@camalaniugan.gov.ph` |
| Office Head | `officehead@camalaniugan.gov.ph` |
| Staff / Employee | `employee@camalaniugan.gov.ph` |

Change demo passwords and remove or disable sample accounts before any shared or production deployment.

## Available modules

- Session authentication, role middleware, policies, account activation, and audit events
- Role-aware dashboards, employee profiles, employee CRUD/archive, departments, positions, and user accounts
- Digital Application for Leave with profile snapshots, conditional leave details, private supporting files, working-day calculations, draft/submit, cancellation, approval history, and notification inbox
- Office Head recommendation, HRMO credit/day certification and document waiver with justification, and designated Administrator final decision
- Leave credit balances, adjustments, transaction ledger, and approval-time deductions for credit-tracked leave types
- Municipal holidays and configurable workweek used by the leave working-day calculator
- Attendance monitoring, authorized correction with remarks, and biometric CSV import with row-level duplicate/error reporting
- Attendance-related deduction review records that keep estimates separate from payroll; the app does not calculate an unsupported payroll formula or deduct salary
- Filtered employee, attendance, and leave reports with CSV downloads (Excel-compatible) and browser print
- Two-page A4 print view for the leave application and configurable instructions/checklists

## Workflow and configuration

- The configured chain is Employee → the Office Head assigned to the employee's department → HRMO → an Administrator account explicitly marked as a final approver.
- Assign Office Heads on the Departments page. Designate final approvers on User accounts.
- Set the municipal workweek in System settings and maintain active holiday dates in Holiday calendar.
- HRMO / Administrator can edit leave guidance, document checklists, sensitivity flags, and credit requirements on Leave requirements.
- Requirements are examples based on the provided Civil Service Form No. 6 (Revised 2020) field description. Confirm current CSC rules and approved municipal policies before setting filing deadlines, entitlements, document requirements, or waivers.
- Only the employee, HRMO, and authorized Administrator can access documents marked sensitive. Office Heads are denied access to those downloads and do not see restricted application details.

## Leave form printing

Open an application and choose **Print / Save PDF**. The dedicated print page is formatted for A4 portrait and contains the application and instruction pages. **Download PDF** generates a two-page PDF in the browser; this PDF embeds page images, so its text is not searchable. **Print** opens the browser print dialog, where you can save a browser-rendered PDF or send the form to a printer. An official municipal seal/logo was not present in the workspace; the prototype does not imitate one.

## Biometric CSV format

Use the downloadable template. Required column headers:

```csv
biometric_id,date,time_in,time_out
BIO-0001,2026-01-15,08:00,17:00
```

Dates must be `YYYY-MM-DD`; times may be `HH:MM` or `HH:MM:SS`. Map each device ID to an employee's Biometric ID first. Duplicate employee/date rows and unmapped IDs are recorded as import errors and do not overwrite existing attendance. No device API/SDK integration is claimed.

## Validation

```powershell
php artisan test
npm run build
```

## Before municipal use

Review current law and municipal policy with HR/legal/payroll, replace seeded accounts and sample balances, configure MySQL backups and private-file retention, set production session/cookie settings and HTTPS, establish an approved attendance schedule and deduction methodology, and perform a privacy/security review. The supplied prompt contained the form's fields and instructions but no reference image or authorized logo asset was available in the workspace, so the print layout is a structured text-based reproduction rather than a pixel-exact copy.
