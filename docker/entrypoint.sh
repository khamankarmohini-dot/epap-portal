#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

# Materialize inline PEM CA certificate if provided in DB_SSL_CA
if [ -n "${DB_SSL_CA:-}" ] && [[ "${DB_SSL_CA}" == *"-----BEGIN"* ]]; then
    printf '%b\n' "${DB_SSL_CA}" > /tmp/epap-db-ca.pem
    chmod 600 /tmp/epap-db-ca.pem
    export DB_SSL_CA="/tmp/epap-db-ca.pem"
fi

# Wait for external MySQL database with retries
echo "[entrypoint] Waiting for database connection..."
DB_READY=0
for i in $(seq 1 15); do
    if php -r '
        require "app/bootstrap.php";
        try {
            App\Core\Database::getConnection()->query("SELECT 1");
            exit(0);
        } catch (Throwable $e) {
            fwrite(STDERR, "[entrypoint] DB attempt failed: " . App\Core\Database::classifyError($e) . "\n");
            exit(1);
        }
    '; then
        DB_READY=1
        break
    fi
    echo "[entrypoint] Retrying database connection ($i/15) in 2s..."
    sleep 2
done

if [ "$DB_READY" -ne 1 ]; then
    echo "[entrypoint] ERROR: Could not connect to database after 15 attempts. Exiting." >&2
    exit 1
fi

# Run base schema (if empty) + numbered migrations idempotently
echo "[entrypoint] Running database migrations..."
php setup.php --migrate

# Create first admin non-interactively once (never overwrites existing admin, never logs password)
if [ -n "${ADMIN_ID:-}" ] && [ -n "${ADMIN_NAME:-}" ] && [ -n "${ADMIN_EMAIL:-}" ] && [ -n "${ADMIN_PASSWORD:-}" ]; then
    php -r '
        require "app/bootstrap.php";
        $pdo = App\Core\Database::getConnection();
        $cnt = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = '\''admin'\''")->fetchColumn();
        if ($cnt > 0) {
            echo "[entrypoint] Existing admin account preserved ($cnt present).\n";
            exit(0);
        }
        $id    = trim((string) getenv("ADMIN_ID"));
        $name  = trim((string) getenv("ADMIN_NAME"));
        $email = trim((string) getenv("ADMIN_EMAIL"));
        $pass  = (string) getenv("ADMIN_PASSWORD");
        if (strlen($pass) < 10 || !preg_match("/[A-Za-z]/", $pass) || !preg_match("/\d/", $pass)) {
            fwrite(STDERR, "[entrypoint] ERROR: ADMIN_PASSWORD must be >= 10 chars with letters and numbers.\n");
            exit(1);
        }
        $stmt = $pdo->prepare("INSERT INTO users (identifier, password_hash, role, full_name, email, is_active, must_change_password) VALUES (?, ?, '\''admin'\'', ?, ?, 1, 0)");
        $stmt->execute([$id, password_hash($pass, PASSWORD_BCRYPT), $name, $email]);
        echo "[entrypoint] First administrator '\''" . $id . "'\'' created.\n";
    '
fi

# Bind Apache to Render's PORT (default 10000)
LISTEN_PORT="${PORT:-10000}"

sed -i "s/Listen 80/Listen ${LISTEN_PORT}/" /etc/apache2/ports.conf
sed -i "s/:80/:${LISTEN_PORT}/g" /etc/apache2/sites-available/000-default.conf

# sed -i "s/Listen [0-9]\+/Listen ${LISTEN_PORT}/" /etc/apache2/ports.conf
# sed -i "s/:[0-9]\+>/:${LISTEN_PORT}>/" /etc/apache2/sites-available/000-default.conf

echo "[entrypoint] Starting Apache on port ${LISTEN_PORT}..."
exec apache2-foreground
