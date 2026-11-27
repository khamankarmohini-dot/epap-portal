# EPAP Production Conversion — Architectural Decisions

1. **PHP 8.0+ / 8.1+ Compatibility**:
   - Kept syntax compatible with PHP 8.0.30 (the local XAMPP runtime) as well as PHP 8.1+, while `setup.php` enforces PHP 8.0+ and `composer.json` specifies `>=8.0`.
2. **PHPMailer Installed Directly in `vendor/`**:
   - Installed `phpmailer/phpmailer` v6.9.3 into `vendor/phpmailer/phpmailer` with a Composer-compatible PSR-4 `vendor/autoload.php` and `composer.json`. `setup.php` fails loudly if `vendor/autoload.php` or `PHPMailer` is missing rather than silently falling back.
3. **OTP Channel Abstraction (`OtpChannel` / `EmailChannel`)**:
   - Introduced `App\Core\OtpChannel` interface with `App\Core\EmailChannel` delegating to `Mailer::sendOtp()`. Future channels (e.g., SMS) can implement `OtpChannel` without changing `AuthController`.
4. **Server-Side OTP Session State (No URL Query Exposure)**:
   - Removed `devOtp` and `maskedEmail` from URL query strings. `verify-otp.html` fetches `GET /api/auth/otp-session`, which returns `maskedEmail` and only returns `isLocal: true` + `devOtp` when `APP_ENV=local` on the server. `?devOtp=` in the URL is completely ignored.
5. **Fail-Closed Email Delivery**:
   - In `APP_ENV=production` (and whenever `SMTP_HOST` is set), if `EmailChannel::send()` fails, the newly created `otp_tokens` row is immediately marked `used = 1`, `pendingLogin` is not set, the sanitized SMTP error is logged to `storage/logs/app.log`, an `OTP_EMAIL_FAILED` audit row is recorded, and HTTP `503` is returned with a non-technical message.
6. **Persistent DB-Backed Rate Limiting**:
   - Added `rate_limits` table and `RateLimiter::checkDbRate()` so OTP requests, resends, password resets, and CSV imports are throttled across requests and cannot be bypassed by rotating session cookies.
7. **Separate Seeders (`seed_practice.php` & `seed_demo.php`)**:
   - Removed `database/seed.php`. Default `php setup.php` initializes an empty database with only the first administrator. Practice problems are opt-in via `--with-practice`; demo data is opt-in via `--demo` (refused in `APP_ENV=production` unless `--force-demo` is passed).
8. **Dynamic Portal Settings (`settings` table)**:
   - Stored institution name, department name, short logo text, academic year, current semester, support email, session timeout, upload max MB, and at-risk thresholds in `settings`, exposed via `/api/settings/public` and editable via `/api/admin/settings`.
9. **Web Server & Router Hardening**:
   - Blocked `/bin/` and `*.sql` / `*.log` in addition to `/.env`, `/app/`, `/storage/`, `/database/`, `/docs/`, `/vendor/`, `/tests/`, `/setup.php`, and `/router.php` in both `router.php` and `.htaccess`.
10. **Backup Utility (`bin/backup.php`)**:
    - Uses `mysqldump` when available and falls back to a pure-PDO SQL export if `mysqldump` is unavailable or blocked, writing timestamped backups to `storage/backups/` with retention pruning.

---

# EPAP Data-Driven UI & Single-Source-of-Truth Decisions

11. **Definition of Pending / Due Activities**:
    - A student activity is counted as **pending** if and only if:
      - `activities.year = student.year` AND `activities.division = student.division`
      - No row exists in `submissions` for `(activity_id, student_id)`
      - AND `(activities.deadline > NOW() OR activities.allow_late = 1)`
    - Both `GET /api/student/summary` and `GET /api/student/stats` use this exact SQL predicate so the sidebar badge (`N Due`), dashboard stat card, and `View All (N)` button always agree.
12. **Role-Specific Summary Endpoints & Shared Shell (`assets/js/shell.js`)**:
    - Added `GET /api/student/summary`, `GET /api/faculty/summary`, and `GET /api/admin/summary` to return all shell counters, user display name, initials, class/designation labels, active settings, and `server_now` in one request.
    - `assets/js/shell.js` renders the shared header, sidebar, avatar initials (derived from first + last word of `full_name`), and live refresh indicator across all role pages.
13. **Year / Semester Consistency Rule**:
    - Enforced server-side in `AdminController::createStudent()`, `importStudentsCsv()`, and `fixStudentYearSemester()`:
      - `FE-IT` → Semesters `1, 2`
      - `SE-IT` → Semesters `3, 4`
      - `TE-IT` → Semesters `5, 6`
      - `BE-IT` → Semesters `7, 8`
    - Mismatched records are rejected with HTTP `422`. Existing inconsistent records are surfaced on `admin-dashboard.html` via `GET /api/admin/students/inconsistent` with one-click remediation (`POST /api/admin/students/{id}/fix-year-semester`).
14. **No-Store Caching & Live Auto-Refresh**:
    - `Response::json()` sends `Cache-Control: no-store, no-cache, must-revalidate, max-age=0` and `Pragma: no-cache` on all `/api/*` responses; HTML pages include `<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">`.
    - `EPAP_API.epapFetch()` defaults to `cache: 'no-store'`.
    - `EPAP_SHELL` polls every 45s while visible, refreshes on `visibilitychange` (when visible) and `window.focus` (debounced), pauses when hidden, backs off exponentially on errors (with a subtle `"Couldn't refresh"` badge), and stops cleanly on `401`.
15. **Server-Time Countdowns & Accessible Dialogs**:
    - `shell.js` computes `EPAP_SERVER_OFFSET_MS = new Date(summary.server_now) - Date.now()` so `updateCountdowns()` in `assets/js/main.js` is immune to client clock skew.
    - Native `alert()`, `confirm()`, and `prompt()` were replaced with shared Bootstrap 5 modal helpers (`EPAP_UI.confirmDialog`, one-time secret modal, and edit-email modal).
16. **Allocation Modal Year/Division Options**:
    - The fixed class/division options (`TE-IT`, `SE-IT`, `BE-IT` × `A, B, C`) in `admin-management.html` are retained as structural enum choices matching the database schema.

---

# EPAP Render Deployment Decisions

17. **Real Environment Variables First (`Env::get`)**:
    - `Env::get()` checks `getenv()`, `$_ENV`, and `$_SERVER` before falling back to `.env` values, so container/Render environment variables always win and `.env` is optional.
18. **Production Logging to `stderr` with Error Reference IDs**:
    - In `APP_ENV=production` or on Render, `Logger` writes to `php://stderr` and uncaught exceptions emit an 8-hex-char `[ref=xxxxxxxx]` ID in both the log line and the generic `500` JSON response.
19. **External MySQL TLS & Hosted-DB Compatibility**:
    - `Database::getConnection()` supports `DB_SSL`, `DB_SSL_CA` (path or inline PEM written to temp file), `DB_SSL_VERIFY`, `ATTR_TIMEOUT=5`, `ATTR_PERSISTENT=false`, and one-time reconnect on `"server has gone away"`. `setup.php` strips `CREATE DATABASE` / `USE` statements when initializing `schema.sql` on pre-created hosted DBs.
20. **HTTPS Email Driver (`MAIL_DRIVER=brevo_api`)**:
    - Render free tier blocks outbound SMTP ports 25/465/587. Added `MailDriverInterface` and `MAIL_DRIVER` (`brevo_api`, `smtp`, `log`), with `brevo_api` posting over HTTPS with TLS verification and a 10s timeout.
21. **Durable Database File Storage (`STORAGE_DRIVER=db`)**:
    - Render free tier has an ephemeral filesystem. Added `submission_files` (`MEDIUMBLOB`, migration `002`) and `FileStorage` supporting `local` (dev) and `db` (Render). Trade-off: uploaded files count against external DB storage quota; an S3-compatible driver can be added later behind `FileStorage`.
22. **Trusted Proxy Headers & Cold-Start Retries**:
    - `Request` honors `X-Forwarded-Proto` and `X-Forwarded-For` only when `REMOTE_ADDR` matches `TRUSTED_PROXIES` (supporting IP/CIDR). `EPAP_API.epapFetch()` retries `502`/`503`/`504`/network timeouts up to ~60s with a waking-up notice.
