# EPAP Production Conversion — Final Report

## 1. Summary of Changes

### Added Files
- `composer.json` and `vendor/autoload.php` + `vendor/phpmailer/phpmailer/` (PHPMailer v6.9.3)
- `app/Core/OtpChannel.php` & `app/Core/EmailChannel.php` (channel abstraction for OTP delivery)
- `app/Core/Settings.php` (DB-backed settings with safe defaults and caching)
- `database/migrations/001_production_tables.sql` (numbered migration for `settings`, `rate_limits`, `schema_migrations`)
- `database/seed_practice.php` (practice problems content seeder, opt-in via `--with-practice`)
- `database/seed_demo.php` (optional demo dataset, opt-in via `--demo`, blocked in production unless `--force-demo`)
- `bin/test-mail.php` (CLI SMTP test command)
- `bin/backup.php` (mysqldump + PDO fallback SQL backup with retention)
- `tests/fake_smtp.php` (local fake SMTP sink for automated testing)
- `docs/SETUP.md`, `docs/DEPLOYMENT.md`, `docs/DECISIONS.md`, `docs/TESTING.md`, `docs/FINAL_REPORT.md`

### Modified Files
- `app/Core/Env.php`: added `Env::isProduction()`.
- `app/Core/Mailer.php`: PHPMailer SMTP with `Timeout=10`, `CharSet=UTF-8`, TLS verification, HTML + plain-text OTP & password-reset templates, `sendAccountReady()`, `sendTestEmail()`, no OTP logging in production.
- `app/Core/RateLimiter.php`: DB-backed `checkDbRate()` across requests.
- `app/Core/Logger.php`: 5 MB log rotation (up to 3 rotated files).
- `app/Core/Response.php`: HSTS header when `EPAP_IS_HTTPS` is true.
- `app/bootstrap.php`: `TRUSTED_PROXIES`-gated `X-Forwarded-Proto`, production readiness check on `/auth/login`, settings-backed session timeout.
- `app/Controllers/AuthController.php`: `EmailChannel` integration, fail-closed `503` + `OTP_EMAIL_FAILED` audit on mail failure, `otpSession()` endpoint, 10-char password policy, non-enumerating `forgotPassword()`.
- `app/Controllers/AdminController.php`: temp-password onboarding for students/faculty/CSV import, `createFaculty`, `createSubject`, `createExam`, `resetUserPassword`, `updateUserEmail`, `getSettings`, `updateSettings`, `publicSettings`, clean CSV template.
- `app/Controllers/StudentController.php`: upload limit from `Settings`.
- `app/Controllers/AnalyticsController.php`: `at_risk_sgpa_threshold` from `Settings`.
- `api/index.php`: minimal `/api/health`, `/api/auth/otp-session`, `/api/settings/public`, and new admin routes.
- `database/schema.sql`: added `uq_users_email`, `settings`, `rate_limits`, `schema_migrations`.
- `setup.php`: PHPMailer check, `--reset` DB-name confirmation, `--migrate`, `--with-practice`, `--demo`, `--check`, `--test-mail`, interactive/non-interactive first admin creation.
- `router.php` & `.htaccess`: blocked `/bin/`, `*.sql`, `*.log`.
- `.env.example` & `README.md`: updated for production, removed demo credentials.
- `login.html`, `verify-otp.html`, `profile.html`, `admin-dashboard.html`, `admin-management.html`, `faculty-*.html`, `student-*.html`, `assets/js/api.js`: removed demo identities/URL `devOtp`, added dynamic settings, empty states, and admin management/settings UI.
- `tests/smoke.php`: rewritten as self-contained production & security test suite.

### Removed Files
- `database/seed.php` (replaced by `database/seed_practice.php` and `database/seed_demo.php`).

---

## 2. Exact Commands for Fresh Install & Production

```bash
# 1. Install PHP dependencies (already included in vendor/, or run composer install)
composer install

# 2. Configure environment
cp .env.example .env
# Edit .env: set APP_ENV=production, DB_*, SMTP_*

# 3. Verify SMTP delivery
php bin/test-mail.php admin@yourdomain.edu

# 4. Initialize database and create first administrator
php setup.php

# 5. Optional: load coding practice problems
php setup.php --with-practice

# 6. Run production readiness check
php setup.php --check

# 7. Run server (for internal LAN use; use Apache/Nginx + PHP-FPM for public hosting)
php -S 0.0.0.0:3000 router.php
```

---

## 3. MANUAL STEPS Checklist (Requires Human Operator)

1. **Install PHP 8.1+ & MySQL 8+** on the target server (ensure `pdo_mysql`, `mbstring`, `fileinfo`, `openssl`, `curl` are enabled).
2. **Fill `.env`** with production `DB_*` credentials, `APP_ENV=production`, `APP_URL`, and `SMTP_*` settings.
3. **Create Gmail App Password** (enable 2-Step Verification → Security → App passwords) or configure your college SMTP relay.
4. **Run the mail test**: `php bin/test-mail.php your-email@domain.edu` and confirm the email arrives in your inbox.
5. **Run setup**: `php setup.php` to initialize the schema and create the first HOD/Admin account.
6. **Enable HTTPS** (Let's Encrypt / SSL certificate in Apache or Nginx) and set `SESSION_SECURE=true` in `.env`.
7. **Schedule daily backups**: configure cron or Windows Task Scheduler to run `php bin/backup.php --keep=14`.
8. **Populate portal data in order**: sign in as Admin → configure **Portal Settings** → add **Subjects → Faculty → Students → Allocations**.
9. **Inform users**: newly created faculty and students receive a one-time temporary password and will be forced to set a new 10+ character password on first login.

---

## 4. What Could Not Be Verified

- **Real external inbox delivery** (e.g. Gmail / college SMTP): not verified because no live SMTP credentials were present in `.env`; delivery was verified against a local PHP fake SMTP server (`tests/fake_smtp.php`).
- **Real HTTPS / TLS termination & Apache `.htaccess`**: verified under PHP built-in server (`router.php`) over HTTP; Apache/Nginx HTTPS configs in `docs/DEPLOYMENT.md` were not tested against a live Apache/Nginx instance.
- **Browser console rendering**: verified HTTP `200` page loads and API responses; a headless browser was not run to inspect live DOM console output.

---

## 5. Known Limitations & Future Work

- **Judge0 Code Execution**: Practice Arena runs in non-executing mode unless `JUDGE0_URL` is configured in `.env`.
- **SMS OTP Channel**: `OtpChannel` interface is in place; an `SmsChannel` implementation can be added in the future if SMS OTP is desired.
- **Automated Email Failure Test**: Add a dedicated automated assertion for `OTP_EMAIL_FAILED` in `tests/smoke.php` when running with a controllable SMTP mock.
