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

`php artisan migrate --seed` applies pending migrations, synchronizes configured accounts, and adds missing leave-type reference data. It never creates employee, department, or position records.

Predefined accounts are optional and configured in the protected active environment file. Existing `HRIS_INITIAL_*` names are supported, as are the shorter `INITIAL_*` aliases. The supported slots are Super Admin, Admin, HRMO, Mayor, and Office Head. For example:

```dotenv
HRIS_INITIAL_SUPER_ADMIN_NAME="Super Administrator"
HRIS_INITIAL_SUPER_ADMIN_EMAIL=superadmin@example.com
HRIS_INITIAL_SUPER_ADMIN_PASSWORD=replace-with-a-strong-secret
HRIS_INITIAL_ADMIN_NAME="System Administrator"
HRIS_INITIAL_ADMIN_EMAIL=admin@example.com
HRIS_INITIAL_ADMIN_PASSWORD=replace-with-a-strong-secret
HRIS_INITIAL_HRMO_NAME="HRMO"
HRIS_INITIAL_HRMO_EMAIL=hrmo@example.com
HRIS_INITIAL_HRMO_PASSWORD=replace-with-a-strong-secret
HRIS_INITIAL_MAYOR_NAME="Mayor"
HRIS_INITIAL_MAYOR_EMAIL=mayor@example.com
HRIS_INITIAL_MAYOR_PASSWORD=replace-with-a-strong-secret
HRIS_INITIAL_OFFICE_HEAD_NAME="Office Head"
HRIS_INITIAL_OFFICE_HEAD_EMAIL=officehead@example.com
HRIS_INITIAL_OFFICE_HEAD_PASSWORD=replace-with-a-strong-secret
HRIS_INITIAL_OFFICE_HEAD_DEPARTMENT_ID=1
```

Each configured slot has a stable `seed_key` in the users table, independent of its email. The default stored roles are `super_admin`, `admin`, `hrmo`, `mayor`, and `office_head`; optional `HRIS_INITIAL_<SLOT>_ROLE` values can change a slot's role to another supported role. Optional `HRIS_INITIAL_<SLOT>_IS_ACTIVE` values synchronize status, and `HRIS_INITIAL_ADMIN_FINAL_APPROVER` controls the Admin final-approver flag. Office Heads require an existing department ID (find it in the Departments screen/database); the seeder does not create departments. Changing their configured department moves the assignment. Empty optional values are ignored; removing a slot's name/email/password configuration skips that slot without deleting its account or HR records. Passwords are hashed, and an unchanged configured password is not rewritten.

After changing `.env`, run `php artisan config:clear` followed by `php artisan db:seed`. The first deployment of the `seed_key` migration must run `php artisan migrate` before seeding. To safely associate existing untagged bootstrap accounts, run the seeder once while their currently configured emails still match; after the stable keys are stored, future email changes are synchronized automatically. Legacy matching never claims an account with the Employee role or an employee record, and a conflicting unrelated email causes a clear error instead of overwriting that user. A configured account is updated by its stable key, including its name, email, role, active status, supported permissions, and password when supplied. Super Admin defaults to `super_admin`; it is never implicitly made an Admin.

When configuration is cached in production, deploy the updated environment and rebuild Laravel's config cache before running seeders, for example `php artisan config:cache` followed by `php artisan db:seed`. Protect `.env` and never commit real passwords. Seeding does not delete users, departments, or HR data.

For safe population, first verify `DB_CONNECTION` and `DB_DATABASE` in the active environment. In production, back up and test restoration before applying approved migrations. Migrations are additive; never use `migrate:fresh` or truncate tables on a database containing records. Create department and position records from the authorized HR screens, then enter verified municipal employee data. Employee accounts must be linked to one existing, non-archived employee record.

Supporting leave documents are written to Laravel's private `local` disk (`storage/app/private`), not to the public storage directory. Keep that directory access-controlled and include it in secure backups.

## Office Head availability and alternate routing

HRMO or an administrator can record a dated Office Head availability period from **Office Head availability**. Records include the reason and, when authorized, a temporary alternate approver. Office Heads cannot create their own unavailability records. Periods for the same department cannot overlap.

Leave applications are routed to the assigned Office Head when available. If an unavailable period overlaps the requested leave dates, the application is routed to its assigned alternate or, when no alternate is assigned, to the single active designated final approver. If the route is ambiguous, submission is blocked until an authorized alternate is configured. A final approver who acts as the substitute records one decision; HRMO verifies it, and that same decision is finalized without a duplicate final-approval step. Leave credits are deducted only at finalization.

## Database backup and deployment

Before deploying schema changes or importing authorized personnel data:

1. Confirm the target database and environment without exposing credentials: `php artisan about` and `php artisan migrate:status`.
2. Take a consistent MySQL backup to an access-restricted location. From `cmd.exe`, use the MySQL client with a password prompt (do not put the password in the command line):

   ```text
   mysqldump --single-transaction --routines --triggers --user=<database-user> --password --result-file="C:\secure-backups\hris-before-deploy.sql" <database-name>
   ```

3. Verify that the backup is non-empty and test restoration into a separate, isolated database before relying on it. From `cmd.exe`, a restore is:

   ```text
   mysql --user=<database-user> --password <isolated-database-name> < "C:\secure-backups\hris-before-deploy.sql"
   ```

4. Deploy code, review pending migrations, and only then apply approved additive migrations with `php artisan migrate --force`. Never use `migrate:fresh` or truncate tables on a database containing records.
5. Keep backups encrypted or otherwise access-controlled, retain them under municipal policy, and include `storage/app/private` in the protected backup plan.

The supplied November 2026 workbook was inspected locally: it has 100 data rows and 27 columns. Columns H and S both have the header `OFFICE` but contain different values; column X has no header and contains one date-formatted value. HRMO directed that all three remain separate source fields, with the unnamed date source-only unless HRMO explicitly approves a mapping. The workbook has not been imported; complete the HRMO review, create only verified department/position reference records, and take a verified backup before applying the pending import migrations.

## Account setup and management

The application does not include hardcoded municipal account names or email addresses. A Super Admin can manage all user roles, create ADMIN accounts, promote HRMO accounts, and demote ADMIN accounts; regular ADMIN accounts can manage only HRMO, Office Head, and Employee accounts. HRMO access to the account directory remains read-only. Employee accounts must link to an existing verified employee record; Office Head accounts must be assigned to an existing department. New Mayor accounts have a distinct `mayor` role; existing `admin` accounts with final-approver permission are preserved and continue to act as Mayor without changing their stored role. Users manage their own permitted details, profile photo, and password through **My profile**.

The optional `HRIS_INITIAL_*` environment values are for first-install account bootstrapping only. Choose the actual account information and credentials through the municipality's authorized process; the project does not supply or presume personal details.

## Available modules

- Session authentication, role middleware, policies, account activation, and audit events
- Role-aware dashboards, employee profiles, employee CRUD/archive, departments, positions, and user accounts
- Private XLSX employee-import staging and review, pre-import validation preview, duplicate detection, explicit department/position/name/status/date mapping approval, row-level HRMO name correction, import history, audit records, and retained position/appointment history
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
- The transparent municipal seal/logo used by the interface is `public/image/hrislogo.webp`.
- Employee XLSX import is restricted to HRMO and administrators. It retains the original workbook and per-cell source values in private storage/staging; duplicate or existing employee reference numbers are skipped, departments/positions must map to existing records, and no login accounts are created. Both `OFFICE` source columns remain distinct. The unnamed date remains unmapped by default. HRMO must review the name format, verified employment status, source-to-department/position mappings, pre-import row issues, and import preview, then explicitly confirm valid rows. Rows needing review stay staged and can be corrected without changing their original source values.
- Only the employee, HRMO, and authorized Administrator can access documents marked sensitive. Office Heads are denied access to those downloads and do not see restricted application details.

## Leave form printing

Open an application and choose **Print / Save PDF**. The dedicated print page is formatted for A4 portrait and contains the application and instruction pages. **Download PDF** generates a two-page PDF in the browser; this PDF embeds page images, so its text is not searchable. **Print** opens the browser print dialog, where you can save a browser-rendered PDF or send the form to a printer. Confirm the form's current wording and required fields with HRMO before official use.

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

Review current law and municipal policy with HR/legal/payroll, configure MySQL backups and private-file retention, set production session/cookie settings and HTTPS, establish an approved attendance schedule and deduction methodology, and perform a privacy/security review. Confirm the supplied form's current wording and required fields with HRMO before official use.
