# EPAP Testing & Verification Report

## 1. PHP Syntax Lint (`php -l`)
- Ran `php -l` across all `.php` files in the repository (excluding `vendor/`).
- **Result**: `0` syntax errors.

## 2. Production Setup & Readiness Check
- Ran `php setup.php --reset --admin-id=TEST-HOD-ADMIN --admin-name="Test Admin" --admin-email=admin@epap.test --admin-password=TestAdminPass123` with `APP_ENV=production` against MySQL on `127.0.0.1:3307`.
- **Result**: Database dropped after typing `epap_db` confirmation, schema initialized, migration `001_production_tables.sql` applied, first admin created.
- Ran `php setup.php --check` with `APP_ENV=production` and local fake SMTP configured.
- **Result**: `Check finished: 0 failure(s), 0 warning(s).`

## 3. SMTP Delivery Test (`bin/test-mail.php`)
- Ran `php bin/test-mail.php admin@epap.test` against local PHP fake SMTP sink (`tests/fake_smtp.php` on `127.0.0.1:2525`).
- **Result**: `[SUCCESS] Test email sent successfully to admin@epap.test.`
- **Note**: Real external inbox delivery (e.g. Gmail SMTP) was **not** tested because no live SMTP credentials were present in `.env`.

## 4. Automated Production & Security Smoke Suite (`tests/smoke.php`)
Ran `php tests/smoke.php http://127.0.0.1:3099 TEST-HOD-ADMIN TestAdminPass123 <smtp_capture>` with `APP_ENV=production`:
- **Result**: **63 passed, 0 failed**.
- Verified checks:
  - `GET /api/health` returns `{"status":"ok"}` only.
  - Sensitive paths return `403`: `/.env`, `/app/bootstrap.php`, `/storage/logs/app.log`, `/database/schema.sql`, `/docs/SETUP.md`, `/vendor/autoload.php`, `/tests/smoke.php`, `/setup.php`, `/bin/backup.php`, `/epap_backup.sql`.
  - All HTML pages load with HTTP `200` on a clean database.
  - Unauthenticated `/api/student/profile` returns `401`; missing CSRF returns `403`.
  - Production login response and headers never expose `devOtp`; `GET /api/auth/otp-session?devOtp=999999` returns `isLocal: false` and no `devOtp`.
  - Wrong OTP (`401`), reused OTP (`400`/`401`), and login throttling (`429` after 5 failures).
  - End-to-end flow: Admin creates Subject, Faculty, Student (with generated temp passwords), and Allocation -> Faculty & Student complete forced password change -> Faculty creates Activity -> Student uploads `.txt` (and `.php` rejected with `422`) -> Empty-DB analytics returns `enough_data: false` -> Faculty grades -> Admin creates Exam, imports marks, publishes -> Student sees published result.

## 5. Production Startup Check & Backup Utility
- Verified `bootstrap.php` production readiness check returns HTTP `503` (`Portal sign-in is unavailable: Database is unreachable`) when MySQL is unreachable.
- Ran `php bin/backup.php --keep=2`:
  - **Result**: Wrote `storage/backups/epap_epap_db_20261126_145016.sql` via PDO fallback.

---

## 6. Data-Driven UI Refactor Verification (2026-11-26)
- **PHP Syntax Lint (`php -l`)**:
  - Ran `php -l` across all `.php` files (`app/`, `api/`, `tests/`, `bin/`, `setup.php`, `router.php`) and re-checked all edited PHP files (`AdminController.php`, `FacultyController.php`, `StudentController.php`, `Response.php`, `Settings.php`, `api/index.php`, `tests/smoke.php`).
  - **Result**: `0` syntax errors.
- **Hardcoded Demo Value Scan (`Select-String`)**:
  - Scanned `student-*.html`, `faculty-*.html`, `admin-*.html` for `2 Due`, `View All (5)`, `100+ Qs`, `>RS<`, `>SP<`, `>AD<`, `T23104921`, `8.92`, `8.64`, `9.08`, `91.4%`, `85.7%`, `Zeal IT Department`, `SPPU IT Curriculum`, `alert(`, `confirm(`, `prompt(`.
  - **Result**: `0` matches remaining after fixing the final `confirm()` in `admin-management.html`.
- **Setup & Live Server Probe**:
  - Ran `C:\xampp\php\php.exe setup.php --check` against live MySQL (`APP_ENV=production`): `Check finished: 0 failure(s), 0 warning(s).`
  - Started `C:\xampp\php\php.exe -S 127.0.0.1:3098 router.php`:
    - All 13 HTML pages (`index.html`, `login.html`, `student-*.html`, `faculty-*.html`, `admin-*.html`, `profile.html`) returned HTTP `200`.
    - `GET /api/settings/public` returned HTTP `200` with `Cache-Control: no-store, no-cache, must-revalidate, max-age=0` and new `benchmark_label` / `curriculum_label` keys.
    - Unauthenticated `GET /api/student/summary` returned HTTP `401` with `Cache-Control: no-store, no-cache, must-revalidate, max-age=0`.
- **Live DB Year/Semester Inconsistency Check**:
  - Queried `students` table directly: found `1` inconsistent record (`id=1`, `zprn=126UIT2017`, `Mohini Khamankar`, `year=SE-IT`, `semester=5`).
- **Smoke Suite (`tests/smoke.php`)**:
  - Added assertions for `Cache-Control: no-store`, `POST /api/admin/students` 422 rejection on `SE-IT + Sem 5`, `/api/admin/summary`, `/api/admin/students/inconsistent`, `/api/faculty/summary`, and `/api/student/summary` vs `/api/student/stats` counter agreement before and after submission.
  - *Note*: Full end-to-end `tests/smoke.php` execution against the live production DB was not run destructively in order to preserve existing real user/student data (`APP_ENV=production` with `smtp.gmail.com:587`); interactive browser console/360px visual testing was not run in a headless browser.

---

## 7. Render Deployment Readiness Verification (2026-11-27)
- **PHP Syntax Lint (`php -l`)**:
  - Ran `C:\xampp\php\php.exe -l` on all PHP files in `app/`, `api/`, `bin/`, `tests/`, `setup.php`, and `router.php`: `0` syntax errors.
- **Diagnostics & Migration (`epap_db` & `epap_render_test`)**:
  - Applied `002_submission_files.sql` cleanly via `php setup.php --migrate`.
  - Ran `php setup.php --check` (`0` failures, `0` warnings) and `php bin/diagnose.php`.
  - Tested empty-DB initialization (`epap_render_test` with `setup.php --migrate` + `setup.php` using `ADMIN_*` env vars): `001` & `002` applied, `RENDER-ADM` created non-interactively, and `Env::get()` prioritized real env vars over `.env`.
- **Unit & Integration Checks**:
  - `Request::isHttpsRequest()` & `Request::clientIp()` honored `X-Forwarded-Proto` / `X-Forwarded-For` from trusted CIDR `10.0.0.0/8` and ignored them from untrusted IPs.
  - `FileStorage::save()` with `STORAGE_DRIVER=db` stored exact bytes in `submission_files` (`db:<id>`).
  - `Mailer::sendOtp()` with `MAIL_DRIVER=brevo_api` against a local PHP mock HTTP endpoint (`127.0.0.1:3097`) sent `api-key` + sender payload on 201 and redacted the key on 401 failure.
  - Live Apache (`http://localhost:8080`): `/api/health` returned `200 {"status":"ok"}`; `/api/health/deep` (without key), `/.env`, `/app/bootstrap.php`, `/docker/entrypoint.sh`, `/Dockerfile`, `/render.yaml`, and `/setup.php` all returned `403`.
- **Not Verified in This Environment**:
  - Docker image build/run (`docker` CLI not installed locally).
  - Live `api.brevo.com` delivery, live remote TLS MySQL connection, and live Render container deployment.
