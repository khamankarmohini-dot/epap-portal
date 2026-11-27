# EPAP Setup Guide

## 1. Install Dependencies
Ensure PHP 8.1+ (8.0+ compatible) and MySQL 8+ are installed, then install PHPMailer:
```bash
composer install
```

## 2. Configure `.env`
```bash
cp .env.example .env
```
Edit `.env` with your `DB_*` and `SMTP_*` credentials.

## 3. Initialize Database & First Admin
```bash
php setup.php
```
This creates the database schema, runs pending migrations, and prompts you to create the first Administrator (HOD) account.

### Useful Setup Commands
- **Non-interactive admin creation**:
  ```bash
  php setup.php --admin-id=HOD-IT --admin-name="Department HOD" --admin-email=hod@example.edu --admin-password="StrongPassword123"
  ```
- **Load Coding Practice problems** (optional content):
  ```bash
  php setup.php --with-practice
  ```
- **Apply schema migrations without wiping data**:
  ```bash
  php setup.php --migrate
  ```
- **Test SMTP delivery**:
  ```bash
  php bin/test-mail.php admin@example.edu
  ```
- **Run production readiness check**:
  ```bash
  php setup.php --check
  ```
- **Reset database** (prompts for DB name confirmation):
  ```bash
  php setup.php --reset
  ```
- **Optional demo dataset** (local development only; refused in production unless `--force-demo` is passed):
  ```bash
  php setup.php --demo
  ```
