# EPAP — Examination & Predictive Analytics Portal

A production-ready academic management, continuous assessment, 2-step email OTP authentication, and explainable SPPU SGPA analytics portal built with **plain PHP 8.1+ (8.0+ compatible)**, **MySQL 8 / PDO**, **PHPMailer**, and **Bootstrap 5**.

## Key Features
- **Real 2-Step Email OTP**: 6-digit `random_int` OTPs hashed with bcrypt, 5-minute expiry, 5-attempt limit, 60s resend cooldown, DB-backed rate limiting, delivered via PHPMailer SMTP (`STARTTLS` / `SMTPS`).
- **Role-Based Portals**: Student, Faculty, and HOD/Administrator dashboards with server-side authorization and CSRF protection.
- **Onboarding & Security**: Strong temporary password generation, forced password change on first login (min 10 chars), audit-logged email updates, and non-enumerating password reset.
- **Configurable Settings**: Institution name, department, academic year, semester, upload limits, session timeout, and at-risk thresholds managed from the Admin Settings UI.
- **SPPU Grading & Predictive Analytics**: Automated grade/SGPA/CGPA calculations and explainable next-semester SGPA prediction.
- **Production Hardening**: Sensitive path blocking (`router.php` & `.htaccess`), HSTS/CSP/HttpOnly/SameSite cookies, log rotation, and automated SQL backups (`bin/backup.php`).

## Quick Start
See [docs/SETUP.md](docs/SETUP.md) for installation and [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) for production deployment.

```bash
composer install
cp .env.example .env
php setup.php
php bin/test-mail.php admin@yourdomain.edu
php setup.php --check
php -S localhost:3000 router.php
```
