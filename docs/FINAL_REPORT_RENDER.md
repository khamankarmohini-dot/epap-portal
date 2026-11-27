# EPAP Render Deployment Readiness — Final Report
_Date: 2026-11-27_

---

## 1. What Changed

1. **Environment & Error Diagnostics**
   - `app/Core/Env.php`: Real environment variables (`getenv()`, `$_ENV`, `$_SERVER`) now take priority over `.env`; `.env` is an optional local fallback.
   - `app/Core/Logger.php`: In production / on Render (`APP_ENV=production`, `RENDER=true`), logs emit to `php://stderr` (visible in Render Logs) with optional best-effort file logging.
   - `app/bootstrap.php`: Uncaught exceptions log full details with an 8-hex-char `[ref=xxxxxxxx]` ID and return a generic `500` JSON with `error_ref`.
   - `bin/diagnose.php`: New CLI-only diagnostic tool reporting PHP version, extensions, env var presence (never values), DB connection/error classification, migration status, admin presence, mail driver status, and storage durability.

2. **External MySQL with TLS & Migration Compatibility**
   - `app/Core/Database.php`: Added `DB_SSL`, `DB_SSL_CA` (file path or inline PEM), `DB_SSL_VERIFY` (defaults to `true` + system CA fallback), `ATTR_TIMEOUT=5`, `ATTR_PERSISTENT=false`, one-time retry on `"server has gone away"`, and `classifyError()`.
   - `setup.php`: Uses TLS-aware `Database::getConnection()`, strips `CREATE DATABASE` / `USE` when initializing `schema.sql` on pre-created hosted DBs, runs `--migrate` idempotently on empty DBs, and supports `ADMIN_*` non-interactive env vars.

3. **Email Over HTTPS (`MAIL_DRIVER`)**
   - `app/Core/MailDriverInterface.php` & `app/Core/Mailer.php`: Added `MAIL_DRIVER` (`brevo_api` | `smtp` | `log`). `brevo_api` posts via cURL over HTTPS with TLS verification and 10s timeout using `BREVO_API_KEY`, `MAIL_FROM_ADDRESS`, and `MAIL_FROM_NAME`.
   - `bin/test-mail.php`: Updated to work with `brevo_api`, `smtp`, and `log` and print provider status/errors without leaking secrets.
   - `setup.php --check` & `bin/diagnose.php`: Warn when `MAIL_DRIVER=smtp` is used on Render.

4. **Durable File Storage (`STORAGE_DRIVER`)**
   - `database/migrations/002_submission_files.sql`: Added `submission_files` (`MEDIUMBLOB`) table.
   - `app/Core/FileStorage.php`: Supports `STORAGE_DRIVER=local` (local dev) and `STORAGE_DRIVER=db` (Render), keeping extension, `finfo` MIME, size validation, and download authorization intact.
   - Updated `StudentController.php` and `FacultyController.php` to use `FileStorage`.

5. **Proxy, HTTPS, Health & Cold-Start Handling**
   - `app/Core/Request.php`: Honors `X-Forwarded-Proto` and `X-Forwarded-For` only when `REMOTE_ADDR` matches `TRUSTED_PROXIES` (supports exact IPs and CIDRs).
   - `api/index.php`: Added `GET /api/health/deep` protected by `HEALTH_KEY` (`X-Health-Key` or `?key=`), while `GET /api/health` returns only `{"status":"ok"}`.
   - `assets/js/api.js` & `login.html`: Added cold-start retry with *"The server is waking up, please wait up to a minute..."* banner/message on `502`/`503`/`504`/timeout.

6. **Docker & Render Blueprint**
   - Added `Dockerfile`, `.dockerignore`, `docker/entrypoint.sh`, and `render.yaml`; hardened `.htaccess` and `router.php` to block `/docker/`, `Dockerfile`, and `render.yaml`.

---

## 2. Files Added / Modified

- **Added**: `app/Core/MailDriverInterface.php`, `app/Core/FileStorage.php`, `bin/diagnose.php`, `database/migrations/002_submission_files.sql`, `docker/entrypoint.sh`, `.dockerignore`, `render.yaml`, `docs/DEPLOY_RENDER.md`, `docs/FINAL_REPORT_RENDER.md`.
- **Modified**: `app/Core/Env.php`, `app/Core/Logger.php`, `app/Core/Database.php`, `app/Core/Mailer.php`, `app/Core/Request.php`, `app/bootstrap.php`, `app/Controllers/StudentController.php`, `app/Controllers/FacultyController.php`, `api/index.php`, `setup.php`, `bin/test-mail.php`, `assets/js/api.js`, `login.html`, `Dockerfile`, `.htaccess`, `router.php`, `.env.example`, `tests/smoke.php`, `docs/DECISIONS.md`, `docs/TESTING.md`.

---

## 3. Exact Render Environment Variables

- **Fixed / Blueprint Defaults**: `APP_ENV=production`, `APP_DEBUG=false`, `APP_TIMEZONE=Asia/Kolkata`, `SESSION_SECURE=true`, `CSRF_ENABLED=true`, `TRUSTED_PROXIES=10.0.0.0/8,172.16.0.0/12,192.168.0.0/16`, `STORAGE_DRIVER=db`, `MAX_UPLOAD_MB=10`, `DB_PORT=3306`, `DB_SSL=true`, `DB_SSL_VERIFY=true`, `MAIL_DRIVER=brevo_api`, `MAIL_FROM_NAME="EPAP Portal"`, `HEALTH_KEY` (auto-generated).
- **You Must Fill In**: `APP_URL`, `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_SSL_CA` (if required by DB host), `BREVO_API_KEY`, `MAIL_FROM_ADDRESS`, and for first boot only: `ADMIN_ID`, `ADMIN_NAME`, `ADMIN_EMAIL`, `ADMIN_PASSWORD` (remove `ADMIN_PASSWORD` after first login).

---

## 4. Manual Steps in Order

1. Create an external MySQL database (Aiven / TiDB Cloud / Clever Cloud) and note host, port, DB name, user, password, and CA cert.
2. Create a Brevo account, verify your sender email, and generate a Brevo API v3 key.
3. Push this repository (excluding `.env` and `epap_backup.sql`) to a private GitHub repo.
4. In Render, create a **Blueprint** from that repo, enter the secret/config env vars, and deploy.
5. Watch Render **Logs** to confirm migrations and first admin creation succeed.
6. Test `/api/health`, `/login.html` + OTP email, and file upload; then remove `ADMIN_PASSWORD` from Render env vars.

---

## 5. What Was Not Verified Here

- **Docker image build & container run**: `docker` CLI is not installed in this Windows environment (`DOCKER_AVAILABLE=False`), so `Dockerfile` and `docker/entrypoint.sh` were statically reviewed rather than built/executed in a container.
- **Real Brevo API delivery**: Verified against a local PHP mock HTTP server (`127.0.0.1:3097`), not against live `api.brevo.com`.
- **Real remote TLS MySQL & live Render deployment**: Not tested against an external cloud MySQL instance or live Render container from this environment.

---

## 6. Known Free-Tier Limitations

- Render free services sleep after ~15 minutes idle (30–60s cold start on first request).
- Local filesystem is ephemeral; `STORAGE_DRIVER=db` is required on Render free tier and shares your external MySQL free storage quota.
- Outbound SMTP ports `25`, `465`, `587` are blocked on Render free tier (`MAIL_DRIVER=brevo_api` required).
