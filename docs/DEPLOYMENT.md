# EPAP Production Deployment Guide

This guide covers deploying EPAP in production. **Never use demo accounts or `APP_ENV=local` on a live server.**

---

## Option A: College LAN on One PC (Small / Internal Use Only)

> **Note:** PHP's built-in server is single-process/development-oriented and is suitable only for small internal lab/department networks, not public internet hosting.

1. Assign a static LAN IP to the host PC (e.g., `192.168.1.50`).
2. Configure `.env` with `APP_ENV=production`, `DB_*`, and `SMTP_*`.
3. Run `php setup.php` to initialize the database and create the first administrator.
4. Run `php bin/test-mail.php admin@yourdomain.edu` to confirm SMTP works.
5. Run `php setup.php --check` to verify readiness.
6. Allow inbound TCP on your chosen port (e.g., `3000`) in Windows Defender Firewall for Private/Domain networks.
7. Start the server:
   ```bash
   php -S 0.0.0.0:3000 router.php
   ```
8. Access from LAN devices at `http://192.168.1.50:3000/login.html`.

---

## Option B: Apache / Nginx + PHP-FPM + MySQL (Recommended Production)

### 1. Server Requirements
- PHP 8.1+ (8.0+ compatible) with extensions: `pdo_mysql`, `mbstring`, `fileinfo`, `openssl`, `json`, `curl`
- MySQL 8.0+ or MariaDB 10.6+
- Composer (with `phpmailer/phpmailer` installed in `vendor/`)
- HTTPS certificate (e.g. Let's Encrypt via `certbot`)

### 2. File Permissions (Linux)
```bash
sudo chown -R www-data:www-data /var/www/epap
chmod 640 /var/www/epap/.env
chmod -R 750 /var/www/epap/storage
```

### 3. Sample Apache VirtualHost (`.htaccess` Enabled)
```apache
<VirtualHost *:443>
    ServerName epap.yourcollege.edu
    DocumentRoot /var/www/epap

    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/epap.yourcollege.edu/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/epap.yourcollege.edu/privkey.pem

    <Directory /var/www/epap>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/epap-error.log
    CustomLog ${APACHE_LOG_DIR}/epap-access.log combined
</VirtualHost>
```

### 4. Sample Nginx Server Block
```nginx
server {
    listen 443 ssl http2;
    server_name epap.yourcollege.edu;
    root /var/www/epap;
    index index.html;

    ssl_certificate     /etc/letsencrypt/live/epap.yourcollege.edu/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/epap.yourcollege.edu/privkey.pem;

    # Block sensitive directories and files
    location ~* ^/(app|storage|database|docs|tests|vendor|dist|bin)(/|$) {
        deny all;
        return 403;
    }
    location ~* ^/(\.env|\.env\.example|composer\.(json|lock)|setup\.php|router\.php)$ {
        deny all;
        return 403;
    }
    location ~* \.(sql|log)$ {
        deny all;
        return 403;
    }

    location /api {
        try_files $uri /api/index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }
}
```

---

## Environment Variables Reference (`.env`)

| Variable | Purpose | Example |
|---|---|---|
| `APP_NAME` | Application display name | `"EPAP"` |
| `APP_ENV` | `local` or `production` (`production` disables `devOtp` & enforces SMTP) | `production` |
| `APP_DEBUG` | Keep `false` in production | `false` |
| `APP_URL` | Base URL of the portal | `https://epap.yourcollege.edu` |
| `DB_HOST` | MySQL host | `127.0.0.1` |
| `DB_PORT` | MySQL port | `3306` |
| `DB_NAME` | MySQL database name | `epap_db` |
| `DB_USER` | Dedicated MySQL user (avoid `root` in prod) | `epap_app` |
| `DB_PASS` | Strong database password | `StrongDbPass!23` |
| `SESSION_LIFETIME` | Idle session timeout in seconds | `7200` |
| `SESSION_SECURE` | Force `Secure` flag on session cookie (`true` on HTTPS) | `true` |
| `CSRF_ENABLED` | Keep `true` | `true` |
| `TRUSTED_PROXIES` | Comma-separated proxy IPs allowed to set `X-Forwarded-Proto` | `127.0.0.1` |
| `MAX_UPLOAD_MB` | Fallback max upload size in MB | `10` |
| `SMTP_HOST` | SMTP server hostname | `smtp.gmail.com` |
| `SMTP_PORT` | `587` (STARTTLS) or `465` (SMTPS) | `587` |
| `SMTP_SECURE` | `tls` (587) or `ssl` (465) | `tls` |
| `SMTP_USER` | SMTP username / email | `epap-noreply@yourcollege.edu` |
| `SMTP_PASS` | SMTP password or Gmail App Password | `xxxx xxxx xxxx xxxx` |
| `SMTP_FROM_EMAIL` | Sender email address | `epap-noreply@yourcollege.edu` |
| `SMTP_FROM_NAME` | Sender display name | `"EPAP Portal"` |
| `JUDGE0_URL` | Optional Judge0 CE endpoint | `` |
| `JUDGE0_API_KEY` | Optional Judge0 API key | `` |

---

## Gmail App Password Setup (for SMTP)

1. Sign in to the Google account that will send OTP emails.
2. Enable **2-Step Verification** in Google Account → Security (App Passwords are **only** available when 2-Step Verification is enabled).
3. Go to **Security → App passwords**, create a new App Password for "Mail / Other (EPAP)".
4. Copy the 16-character password into `.env` as `SMTP_PASS` with `SMTP_HOST=smtp.gmail.com`, `SMTP_PORT=587`, `SMTP_SECURE=tls`.
5. Verify with:
   ```bash
   php bin/test-mail.php admin@yourcollege.edu
   ```

---

## Scheduled Backups (`bin/backup.php`)

Backups are written to `storage/backups/` (blocked from web access) and keep the latest 14 snapshots by default (`--keep=14`).

- **Linux (`crontab -e`)** — daily at 02:00 AM:
  ```cron
  0 2 * * * /usr/bin/php /var/www/epap/bin/backup.php --keep=14 >> /var/www/epap/storage/logs/backup.log 2>&1
  ```
- **Windows Task Scheduler**:
  - Action: Start a program
  - Program/script: `php.exe`
  - Arguments: `C:\PROJECTS\epap\bin\backup.php --keep=14`
  - Trigger: Daily at 2:00 AM

---

## Go-Live Checklist

- [ ] `APP_ENV=production` and `APP_DEBUG=false` in `.env`
- [ ] HTTPS enabled and `SESSION_SECURE=true`
- [ ] `php bin/test-mail.php you@domain.edu` succeeds
- [ ] `php setup.php --check` reports 0 failures
- [ ] First admin created via `php setup.php`
- [ ] `/.env`, `/app/`, `/storage/`, `/database/`, `/bin/`, `/setup.php` return HTTP `403`
- [ ] `/api/health` returns `{"status":"ok"}`
- [ ] Daily `bin/backup.php` scheduled

---

## Troubleshooting

- **SMTP authentication failed**: Verify `SMTP_USER` and `SMTP_PASS`. For Gmail, ensure 2-Step Verification is on and you are using a 16-character App Password, not your regular account password.
- **SMTP connection refused / timeout**: Check firewall outbound rules for ports `587` or `465`.
- **Database connection refused**: Confirm MySQL/MariaDB service is running and `DB_HOST` / `DB_PORT` in `.env` match.
- **Missing PHP extensions**: Enable `pdo_mysql`, `mbstring`, `fileinfo`, `openssl`, `curl` in `php.ini`.
- **Port conflict (`php -S`)**: Change the port (e.g. `php -S 0.0.0.0:8080 router.php`).
