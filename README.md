# Municipality of Camalaniugan HRIS prototype

A Laravel 12, Blade, Tailwind CSS, Alpine.js, and MySQL-ready HRIS prototype for employee records, digital leave applications, attendance imports, and review workflows. Payroll processing and salary deductions are outside this system's scope.

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

`php artisan migrate --seed` applies pending migrations, synchronizes configured accounts, creates or updates linked employee records, and adds missing leave-type reference data. It never creates departments or positions.

Predefined accounts are optional and configured only with the `HRIS_INITIAL_*` variables below in the protected active `.env`. The operational roles are Admin (HRMO), Mayor, Office Head, and Employee. Only Admin, Mayor, and Employee have bootstrap slots. Office Head accounts must be linked to a verified employee position and assigned to a department through the reviewed employee-import or administrator workflow; the seeder does not infer Office Head status from a name or department. Super Admin and separate HRMO slots are not configured or created. Each bootstrapped account must have an existing department and employee number so the seeder can create its linked employee record.

```dotenv
HRIS_INITIAL_ADMIN_NAME="System Administrator"
HRIS_INITIAL_ADMIN_EMAIL=admin@example.com
HRIS_INITIAL_ADMIN_PASSWORD=replace-with-a-strong-secret
HRIS_INITIAL_ADMIN_EMPLOYEE_NO=ADMIN-001
HRIS_INITIAL_ADMIN_DEPARTMENT_ID=1
HRIS_INITIAL_MAYOR_NAME="Mayor"
HRIS_INITIAL_MAYOR_EMAIL=mayor@example.com
HRIS_INITIAL_MAYOR_PASSWORD=replace-with-a-strong-secret
HRIS_INITIAL_MAYOR_EMPLOYEE_NO=MAYOR-001
HRIS_INITIAL_MAYOR_DEPARTMENT_ID=1
HRIS_INITIAL_EMPLOYEE_NAME="Municipal Employee"
HRIS_INITIAL_EMPLOYEE_EMAIL=employee@example.com
HRIS_INITIAL_EMPLOYEE_PASSWORD=replace-with-a-strong-secret
HRIS_INITIAL_EMPLOYEE_EMPLOYEE_NO=EMP-001
HRIS_INITIAL_EMPLOYEE_DEPARTMENT_ID=1
```

Use real municipal employee numbers and existing department IDs; the example values above are placeholders. For compatibility, the Employee number also accepts the existing `HRIS_INITIAL_EMPLOYEE_NO` spelling. The Admin, Mayor, and Employee department IDs set their linked employee's department. The seeder does not create departments. Each bootstrap role is fixed by its slot; there are no role-override environment variables.

Remove `HRIS_INITIAL_SUPER_ADMIN_*`, `HRIS_INITIAL_HRMO_*`, and obsolete `HRIS_INITIAL_OFFICE_HEAD_*` values from `.env`; they are not bootstrap slots. `HRIS_INITIAL_ADMIN_*` is the only Admin bootstrap slot. Existing database roles `super_admin` and `hrmo` are normalized to `admin` by `db:seed`, without deleting accounts or changing their passwords. Bootstrapped roles have a stable `seed_key`. A configured slot with no name/email/password is skipped. For a configured account, seeding synchronizes its name, email, role, and linked employee's employee number, name, email, and department. Passwords are hashed and updated only when the configured password differs. Historical employee IDs and related leave, attendance, and audit records are retained. Email conflicts and employee-number conflicts stop safely with a clear error and transaction rollback.

Department IDs are looked up in the database; no ID is assumed or fabricated. A missing or invalid ID produces a warning and leaves that employee's department unassigned. Office Head permissions rely on a verified employee designation and the employee's actual department assignment. The `2026_11_16_000003_allow_unassigned_employee_departments` migration makes the employee department relationship nullable for this case. Run `php artisan migrate` before `php artisan db:seed` when that migration is pending; do not apply production migrations without an approved backup and authorization. The seeder does not create or assign unrelated departments.

After securely configuring `.env`, run `php artisan config:clear`, verify migration status, apply approved pending migrations, and then run `php artisan db:seed`. Seeding is transactional and repeatable: it does not create duplicate users or employee records or delete HR history. An unchanged configured password is not rewritten. A duplicate email assigned to a different account or an employee number already in use causes a clear error and rolls back the account seeding transaction.

When configuration is cached in production, deploy the updated environment and rebuild Laravel's config cache before running seeders, for example `php artisan config:cache` followed by `php artisan db:seed`. Protect `.env` and never commit real passwords. Seeding does not delete users, departments, or HR data.

For safe population, first verify `DB_CONNECTION` and `DB_DATABASE` in the active environment. In production, back up and test restoration before applying approved migrations. Migrations are additive; never use `migrate:fresh` or truncate tables on a database containing records. Create department and position records from the authorized HR screens, then enter verified municipal employee data. Employee accounts must be linked to one existing, non-archived employee record.

Supporting leave documents are written to Laravel's private `local` disk (`storage/app/private`), not to the public storage directory. Keep that directory access-controlled and include it in secure backups.

## Office Head availability and alternate routing

HRMO or an administrator can record a dated Office Head availability period from **Office Head availability**. Records include the reason and, when authorized, a temporary alternate approver. Office Heads cannot create their own unavailability records. Periods for the same department cannot overlap. Admin can edit or cancel an active period; each creation, change, and cancellation is retained in history and audit logs. Cancelled periods are not deleted and do not block later availability periods.

Standard employee applications are routed to the assigned Office Head. If that Office Head is unavailable, the configured alternate or Mayor acting as the authorized department-level alternate performs a separately recorded review; HRMO verification remains mandatory, followed by the Mayor's separate final decision. Editing or cancelling availability never automatically returns an already-rerouted application to the original Office Head; its current reviewer remains assigned and affected applicants and reviewers are notified. Office Head personal applications go directly to HRMO. Admin personal applications require an assigned alternate HR reviewer. Mayor personal applications require a separately designated final authority and remain awaiting assignment until one is configured. Missing reviewers never bypass verification or create automatic approval. Leave credits are deducted only at final approval.

## Leave credits and monthly accrual

Each employee has separate Vacation Leave and Sick Leave accounts. New employee records receive zero-balance VL and SL accounts; existing accounts and balances are preserved. The initial reference policy sets each type to accrue 1.25 leave days for an eligible completed month (15 days over 12 eligible months). Accrual eligibility, monthly amount, effective dates, entitlements, required documents, and application fields are controlled in **Leave rules & requirements** and must be confirmed by HRMO against current CSC and municipal policy.

The `leave:accrue-monthly` command accrues the previous completed month by default. It may also be run for a specific completed month, for example `php artisan leave:accrue-monthly --month=2026-10`. A unique employee-account/month ledger constraint makes reruns safe; every accrual records the month, date, amount, and before/after balance. The default eligibility policy includes active employees with a recorded hire date who completed the accrual month. HRMO can review individual ledgers and download balance, ledger, monthly accrual, application, utilization, special leave, Wellness, terminal leave-credit, and monetization-application CSV reports.

Categories configured for individual eligibility review require an HRMO confirmation before final approval; the verifier, date, and remarks are retained on the application. Special annual entitlement summaries are shown only when HRMO has configured an individual employee credit account, and the displayed entitlement is capped by both the category policy and that account. Wellness Leave is not automatically granted and does not debit regular VL or SL.

Enable Laravel's scheduler in production so the first-of-month accrual is invoked. On Linux, configure the standard once-per-minute `schedule:run` cron entry for the deployed application. On Windows, create a Task Scheduler job that runs `php artisan schedule:run` each minute from the application directory. For an operational rerun, use the command above; it will not duplicate credits. These are leave-day records only: this HRIS does not calculate leave monetization payouts or process salary deductions.

## Database backup and deployment

Admin / HRMO can create a manual database backup or restore an existing one from **System settings**. Backups run automatically every day at 2:00 AM when Laravel's scheduler is enabled. The settings page lists backup timestamps, source, and size and provides authorized download and restore actions.

The module backs up the MySQL database, including routines, triggers, and events. Backup files and their metadata are encrypted with Laravel's application encryption key and stored under `storage/app/private/hris-backups`, outside the public directory. Protect that directory and preserve the active `APP_KEY` (and any configured previous application keys) for as long as backups must remain restorable. The private employee-document files stored elsewhere under `storage/app/private` are not part of the database dump and require a separate protected file backup.

Restore requires an Admin / HRMO account, the account's current password, and typing `RESTORE`. The selected file is authenticated and checked against its stored hashes before import. The application enters maintenance mode, creates an encrypted pre-restore safety backup, and attempts recovery from it if import fails. Do not interrupt a restore operation. Test restoration in a separate, isolated database before relying on a backup.

Configure `MYSQLDUMP_BINARY` and `MYSQL_BINARY` if the MySQL client executables are not on the PHP process `PATH`; `HRIS_BACKUP_DIRECTORY` can change the private subdirectory and `HRIS_BACKUP_PROCESS_TIMEOUT` controls the client timeout. Keep these paths under protected storage. Enable Laravel's scheduler in production so both scheduled leave accrual and automatic backups run. On Linux, configure the standard once-per-minute `schedule:run` cron entry. On Windows, create a Task Scheduler job that runs `php artisan schedule:run` each minute from the application directory.

Before deploying schema changes or importing authorized personnel data:

1. Confirm the target database and environment without exposing credentials: `php artisan about` and `php artisan migrate:status`.
2. Create and verify a backup from **System settings**, then test restoration into a separate, isolated database before relying on it.
3. Deploy code, review pending migrations with `php artisan migrate:status` and `php artisan migrate --pretend`, and only apply them after an approved backup. This update includes `2026_11_16_000001_add_leave_accrual_and_policy_tracking.php` and `2026_11_16_000002_consolidate_hris_roles_and_leave_routes.php`. After their effects and the account-role mapping are reviewed and deployment is authorized, run `php artisan migrate --force` to apply pending migrations in timestamp order. Do not apply production migrations without explicit authorization. Never use `migrate:fresh` or truncate tables on a database containing records.
4. Keep backups and private documents access-controlled and retain them under municipal policy.

The supplied November 2026 workbook was inspected locally: it has 100 data rows and 27 columns. Columns H and S both have the header `OFFICE` but contain different values; column X has no header and contains one date-formatted value. HRMO directed that all three remain separate source fields, with the unnamed date source-only unless HRMO explicitly approves a mapping. The workbook has not been imported; complete the HRMO review, create only verified department/position reference records, and take a verified backup before applying the pending import migrations.

## Account setup and management

The application does not include hardcoded municipal account names, email addresses, or passwords. Admin manages user accounts, roles, system settings, and HR operations. Mayor has executive oversight and final approval authority, but cannot administer user accounts, roles, leave credits, or system settings. Office Head access is limited to the assigned department, and employees access their own records. All four roles can apply for personal leave through their linked employee record. Users manage their own permitted details, profile photo, and password through **My profile**.

The optional `HRIS_INITIAL_*` environment values are for first-install account bootstrapping only. Choose the actual account information and credentials through the municipality's authorized process; the project does not supply or presume personal details.

## Available modules

- Session authentication, role middleware, policies, account activation, and audit events
- Encrypted database backups with a daily schedule, backup history, administrator-confirmed restore, and a pre-restore recovery copy
- Role-aware dashboards, employee profiles, employee CRUD/archive, departments, positions, and user accounts
- Private XLSX employee-import staging and review, pre-import validation preview, duplicate detection, explicit department/position/name/status/date mapping approval, row-level HRMO name correction, import history, audit records, and retained position/appointment history
- Digital Application for Leave with profile snapshots, conditional leave details, private supporting files, working-day calculations, draft/submit, cancellation, approval history, and notification inbox
- Office Head review, HRMO eligibility/credit/document certification, and separate Mayor final decision
- Leave credit balances, adjustments, transaction ledger, and approval-time leave-credit debits for credit-tracked leave types
- Municipal holidays and configurable workweek used by the leave working-day calculator
- Attendance monitoring, authorized correction with remarks, and biometric CSV import with row-level duplicate/error reporting
- Effective-dated department workplace defaults and explicit employee-level overrides, with assignment history and admin-only assignment controls
- Filtered employee, attendance, and leave reports with CSV downloads (Excel-compatible) and browser print
- Two-page A4 print view for the leave application and configurable instructions/checklists

## Workflow and configuration

- The standard chain is Employee → assigned Office Head → Admin / HRMO verification and certification → Mayor final approval.
- All four roles can submit personal leave applications when linked to an employee record. An Office Head applicant skips self-endorsement and goes to HRMO. An Admin applicant requires an assigned alternate HR reviewer. A Mayor applicant requires a separately designated authorized final approver; otherwise it stays awaiting assignment.
- Assign Office Heads on the Departments page, configure alternate reviewers and the Mayor personal-leave authority in System settings, and record availability periods for Office Heads. When the Mayor acts as an alternate department reviewer, that review is separate from the Mayor's later final approval.
- Set the municipal workweek in System settings and maintain active holiday dates in Holiday calendar.
- HRMO / Administrator can edit leave guidance, document checklists, sensitivity flags, and credit requirements on Leave requirements.
- Requirements are examples based on the provided Civil Service Form No. 6 (Revised 2020) field description. Confirm current CSC rules and approved municipal policies before setting filing deadlines, entitlements, document requirements, or waivers.
- The transparent municipal seal/logo used by the interface is `public/image/hrislogo.webp`.
- Employee XLSX import is restricted to Admin. It retains the original workbook and per-cell source values in private storage/staging; duplicate employee reference numbers are skipped, while validated existing employee records can be updated, and departments/positions must map to existing records. New imported accounts receive generated usernames and the locally configured temporary password, stored as a hash and required to change at first sign-in before accessing HRIS modules. Re-importing a linked employee preserves the existing role, password, password-change state, and account permissions. HR sends password setup links to available email addresses. Both `OFFICE` source columns remain distinct. The unnamed date remains unmapped by default. HRMO must review the name format, verified employment status, source-to-department/position mappings, suggested and confirmed system roles, pre-import row issues, and import preview, then explicitly confirm valid rows. Only the exact `Municipal Treasurer` position in the MTO or an HR-approved position designation suggests Office Head; the official Municipal Mayor position in the Mayor's Office suggests Mayor. Elevated role suggestions require explicit HR Admin review, existing Mayor account conflicts are blocked for reconciliation, and role changes are audited. Rows needing review stay staged and can be corrected without changing their original source values.
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

Review current law and municipal policy with HR/legal, configure MySQL backups and private-file retention, set production session/cookie settings and HTTPS, establish an approved attendance schedule, and perform a privacy/security review. Confirm the supplied form's current wording and required fields with HRMO before official use.
