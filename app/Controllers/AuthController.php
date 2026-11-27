<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\EmailChannel;
use App\Core\Env;
use App\Core\Logger;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

class AuthController
{
    public function csrf(Request $req): void
    {
        Response::json(['csrf_token' => Csrf::token()]);
    }

    public function otpSession(Request $req): void
    {
        $pending = $_SESSION['pendingLogin'] ?? null;
        if (!$pending || empty($pending['userId'])) {
            Response::json(['active' => false, 'isLocal' => Env::isLocal()]);
        }

        $elapsed = time() - (int) ($pending['lastSentAt'] ?? 0);
        $cooldown = max(0, 60 - $elapsed);

        $payload = [
            'active' => true,
            'maskedEmail' => (string) ($pending['maskedEmail'] ?? '***@***'),
            'role' => (string) ($pending['role'] ?? 'student'),
            'isLocal' => Env::isLocal(),
            'resendCooldownRemaining' => $cooldown,
            'resendsRemaining' => max(0, 3 - (int) ($pending['resendCount'] ?? 0)),
        ];

        if (Env::isLocal() && !empty($pending['devOtp'])) {
            $payload['devOtp'] = (string) $pending['devOtp'];
        }

        Response::json($payload);
    }

    public function login(Request $req): void
    {
        $data = Validator::validate($req->body(), [
            'identifier' => 'required|min:3|max:60',
            'password'   => 'required|min:3|max:128',
            'role'       => 'required|in:student,faculty,admin',
        ]);

        $identifier = trim((string) $data['identifier']);
        $password = (string) $data['password'];
        $role = (string) $data['role'];
        $ip = Request::clientIp();

        $pdo = Database::getConnection();
        RateLimiter::checkLoginThrottle($pdo, $identifier, $ip, 5, 15);

        $stmt = $pdo->prepare('SELECT * FROM users WHERE identifier = ? AND role = ? LIMIT 1');
        $stmt->execute([$identifier, $role]);
        $user = $stmt->fetch();

        if (!$user || !(bool) $user['is_active']) {
            RateLimiter::recordLoginAttempt($pdo, $identifier, $ip, false);
            Logger::audit($pdo, $user ? (int) $user['id'] : null, 'LOGIN_FAILED', "Unknown/inactive {$role}: {$identifier}", 'FAILURE');
            Response::error('Invalid credentials. Check your ID and role.', 401);
        }

        if (!password_verify($password, $user['password_hash'])) {
            RateLimiter::recordLoginAttempt($pdo, $identifier, $ip, false);
            Logger::audit($pdo, (int) $user['id'], 'LOGIN_FAILED', 'Incorrect password', 'FAILURE');
            Response::error('Incorrect password.', 401);
        }

        RateLimiter::recordLoginAttempt($pdo, $identifier, $ip, true);
        RateLimiter::checkDbRate($pdo, 'otp_req_user', (string) $user['id'], 10, 900, 'Too many OTP requests. Please wait before trying again.');
        RateLimiter::checkDbRate($pdo, 'otp_req_ip', $ip, 20, 900, 'Too many OTP requests from this IP. Please wait before trying again.');

        // Invalidate any previous unused login OTPs
        $pdo->prepare("UPDATE otp_tokens SET used = 1 WHERE user_id = ? AND purpose = 'login' AND used = 0")
            ->execute([(int) $user['id']]);

        $otp = (string) random_int(100000, 999999);
        $otpHash = password_hash($otp, PASSWORD_BCRYPT);

        $pdo->prepare(
            "INSERT INTO otp_tokens (user_id, otp_hash, purpose, ip, expires_at)
             VALUES (?, ?, 'login', ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE))"
        )->execute([(int) $user['id'], $otpHash, $ip]);
        $otpRowId = (int) $pdo->lastInsertId();

        $channel = new EmailChannel();
        if (!$channel->send((string) $user['email'], (string) $user['full_name'], $otp, 'login')) {
            $pdo->prepare('UPDATE otp_tokens SET used = 1 WHERE id = ?')->execute([$otpRowId]);
            $reason = $channel->getLastError() ?? 'Unknown SMTP error';
            Logger::audit($pdo, (int) $user['id'], 'OTP_EMAIL_FAILED', substr($reason, 0, 240), 'FAILURE');
            Response::error("We couldn't send your code. Please try again or contact the administrator.", 503);
        }

        $maskedEmail = self::maskEmail((string) $user['email']);

        session_regenerate_id(true);
        $pending = [
            'userId' => (int) $user['id'],
            'role' => $user['role'],
            'maskedEmail' => $maskedEmail,
            'resendCount' => 0,
            'lastSentAt' => time(),
        ];
        if (Env::isLocal()) {
            $pending['devOtp'] = $otp;
        }
        $_SESSION['pendingLogin'] = $pending;

        $payload = [
            'success' => true,
            'maskedEmail' => $maskedEmail,
            'role' => $user['role'],
            'csrf_token' => Csrf::token(),
        ];
        if (Env::isLocal()) {
            $payload['devOtp'] = $otp;
        }
        Response::json($payload);
    }

    public function verifyOtp(Request $req): void
    {
        $data = Validator::validate($req->body(), [
            'otp' => 'required|min:6|max:6',
        ]);

        $pending = $_SESSION['pendingLogin'] ?? null;
        if (!$pending || empty($pending['userId'])) {
            Response::error('Invalid session. Please login again.', 400);
        }

        $userId = (int) $pending['userId'];
        $otpInput = trim((string) $data['otp']);
        $pdo = Database::getConnection();

        RateLimiter::checkDbRate($pdo, 'otp_verify_ip', Request::clientIp(), 20, 300, 'Too many verification attempts. Please wait a few minutes.');

        $stmt = $pdo->prepare(
            "SELECT * FROM otp_tokens
             WHERE user_id = ? AND purpose = 'login' AND used = 0 AND expires_at > NOW()
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$userId]);
        $token = $stmt->fetch();

        if (!$token) {
            Response::error('OTP expired or not found. Please request a new code.', 401);
        }

        if ((int) $token['attempts'] >= 5) {
            $pdo->prepare('UPDATE otp_tokens SET used = 1 WHERE id = ?')->execute([(int) $token['id']]);
            Logger::audit($pdo, $userId, 'OTP_LOCKED', 'Max OTP attempts exceeded', 'FAILURE');
            Response::error('Maximum OTP attempts exceeded. Please login again.', 429);
        }

        $pdo->prepare('UPDATE otp_tokens SET attempts = attempts + 1 WHERE id = ?')->execute([(int) $token['id']]);

        if (!password_verify($otpInput, $token['otp_hash'])) {
            Logger::audit($pdo, $userId, 'OTP_FAILED', 'Invalid OTP entered', 'FAILURE');
            Response::error('Invalid OTP. Please try again.', 401);
        }

        $pdo->prepare('UPDATE otp_tokens SET used = 1 WHERE id = ?')->execute([(int) $token['id']]);

        $uStmt = $pdo->prepare('SELECT id, identifier, role, full_name, email, must_change_password FROM users WHERE id = ?');
        $uStmt->execute([$userId]);
        $user = $uStmt->fetch();
        if (!$user) {
            Response::error('User account not found.', 404);
        }

        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => (int) $user['id'],
            'identifier' => $user['identifier'],
            'role' => $user['role'],
            'full_name' => $user['full_name'],
            'email' => $user['email'],
            'must_change_password' => (bool) $user['must_change_password'],
        ];
        unset($_SESSION['pendingLogin']);
        Csrf::token();

        Logger::audit($pdo, (int) $user['id'], 'LOGIN', 'Role: ' . $user['role'], 'SUCCESS');

        Response::json([
            'success' => true,
            'role' => $user['role'],
            'must_change_password' => (bool) $user['must_change_password'],
            'redirectTo' => self::roleRedirect($user['role']),
        ]);
    }

    public function resendOtp(Request $req): void
    {
        $pending = $_SESSION['pendingLogin'] ?? null;
        if (!$pending || empty($pending['userId'])) {
            Response::error('No pending login session. Please sign in again.', 400);
        }

        $elapsed = time() - (int) ($pending['lastSentAt'] ?? 0);
        if ($elapsed < 60) {
            $wait = 60 - $elapsed;
            Response::error("Please wait {$wait} seconds before requesting a new OTP.", 429);
        }

        $count = (int) ($pending['resendCount'] ?? 0);
        if ($count >= 3) {
            Response::error('Maximum OTP resend limit reached for this session. Please login again.', 429);
        }

        $userId = (int) $pending['userId'];
        $ip = Request::clientIp();
        $pdo = Database::getConnection();

        RateLimiter::checkDbRate($pdo, 'otp_resend_user', (string) $userId, 5, 900, 'Too many OTP resend requests. Please wait.');
        RateLimiter::checkDbRate($pdo, 'otp_resend_ip', $ip, 15, 900, 'Too many OTP resend requests from this IP.');

        $uStmt = $pdo->prepare('SELECT id, full_name, email FROM users WHERE id = ?');
        $uStmt->execute([$userId]);
        $user = $uStmt->fetch();
        if (!$user) {
            Response::error('User not found.', 404);
        }

        $pdo->prepare("UPDATE otp_tokens SET used = 1 WHERE user_id = ? AND purpose = 'login' AND used = 0")
            ->execute([$userId]);

        $otp = (string) random_int(100000, 999999);
        $pdo->prepare(
            "INSERT INTO otp_tokens (user_id, otp_hash, purpose, ip, expires_at)
             VALUES (?, ?, 'login', ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE))"
        )->execute([$userId, password_hash($otp, PASSWORD_BCRYPT), $ip]);
        $otpRowId = (int) $pdo->lastInsertId();

        $channel = new EmailChannel();
        if (!$channel->send((string) $user['email'], (string) $user['full_name'], $otp, 'login')) {
            $pdo->prepare('UPDATE otp_tokens SET used = 1 WHERE id = ?')->execute([$otpRowId]);
            $reason = $channel->getLastError() ?? 'Unknown SMTP error';
            Logger::audit($pdo, $userId, 'OTP_EMAIL_FAILED', substr($reason, 0, 240), 'FAILURE');
            Response::error("We couldn't send your code. Please try again or contact the administrator.", 503);
        }

        $_SESSION['pendingLogin']['resendCount'] = $count + 1;
        $_SESSION['pendingLogin']['lastSentAt'] = time();
        if (Env::isLocal()) {
            $_SESSION['pendingLogin']['devOtp'] = $otp;
        }

        $payload = [
            'success' => true,
            'maskedEmail' => self::maskEmail((string) $user['email']),
            'resendsRemaining' => 3 - ($count + 1),
        ];
        if (Env::isLocal()) {
            $payload['devOtp'] = $otp;
        }
        Response::json($payload);
    }

    public function logout(Request $req): void
    {
        $uid = Auth::userId();
        if ($uid) {
            Logger::audit(Database::getConnection(), $uid, 'LOGOUT', 'User signed out');
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        Response::json(['success' => true]);
    }

    public function me(Request $req): void
    {
        $user = Auth::requireAuth();
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT id, identifier, role, full_name, email, must_change_password FROM users WHERE id = ?');
        $stmt->execute([(int) $user['id']]);
        $fresh = $stmt->fetch();
        if (!$fresh) {
            Response::error('Not authenticated.', 401);
        }
        $fresh['id'] = (int) $fresh['id'];
        $fresh['must_change_password'] = (bool) $fresh['must_change_password'];
        $fresh['csrf_token'] = Csrf::token();
        $_SESSION['user'] = array_merge($_SESSION['user'], $fresh);
        Response::json($fresh);
    }

    public function changePassword(Request $req): void
    {
        $user = Auth::requireAuth();
        $data = Validator::validate($req->body(), [
            'current_password' => 'required',
            'new_password'     => 'required|min:10|max:128',
        ]);

        $currentPw = (string) $data['current_password'];
        $newPw = (string) $data['new_password'];

        if (!preg_match('/[A-Za-z]/', $newPw) || !preg_match('/\d/', $newPw)) {
            Response::error('New password must be at least 10 characters and include both letters and numbers.', 422);
        }
        if (strcasecmp($newPw, (string) $user['identifier']) === 0) {
            Response::error('New password cannot be the same as your Login ID.', 422);
        }
        if ($newPw === $currentPw) {
            Response::error('New password must be different from your current/temporary password.', 422);
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([(int) $user['id']]);
        $hash = (string) $stmt->fetchColumn();

        if (!password_verify($currentPw, $hash)) {
            Response::error('Current password is incorrect.', 400);
        }

        $pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?')
            ->execute([password_hash($newPw, PASSWORD_BCRYPT), (int) $user['id']]);

        $_SESSION['user']['must_change_password'] = false;
        Logger::audit($pdo, (int) $user['id'], 'PASSWORD_CHANGED', 'User updated password');
        Response::json(['success' => true, 'message' => 'Password updated successfully.']);
    }

    public function forgotPassword(Request $req): void
    {
        $data = Validator::validate($req->body(), [
            'identifier' => 'required|min:3|max:60',
        ]);
        $pdo = Database::getConnection();
        $ip = Request::clientIp();
        $ident = trim((string) $data['identifier']);

        RateLimiter::checkDbRate($pdo, 'forgot_pw_ip', $ip, 5, 300, 'Too many password reset requests. Please wait a few minutes.');
        RateLimiter::checkDbRate($pdo, 'forgot_pw_id', strtolower($ident), 3, 300, 'Too many password reset requests. Please wait a few minutes.');

        $stmt = $pdo->prepare('SELECT id, full_name, email FROM users WHERE identifier = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$ident]);
        $user = $stmt->fetch();

        if ($user) {
            $pdo->prepare("UPDATE otp_tokens SET used = 1 WHERE user_id = ? AND purpose = 'password_reset' AND used = 0")
                ->execute([(int) $user['id']]);
            $otp = (string) random_int(100000, 999999);
            $pdo->prepare(
                "INSERT INTO otp_tokens (user_id, otp_hash, purpose, ip, expires_at)
                 VALUES (?, ?, 'password_reset', ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE))"
            )->execute([(int) $user['id'], password_hash($otp, PASSWORD_BCRYPT), $ip]);
            $otpRowId = (int) $pdo->lastInsertId();

            $channel = new EmailChannel();
            if (!$channel->send((string) $user['email'], (string) $user['full_name'], $otp, 'password_reset')) {
                $pdo->prepare('UPDATE otp_tokens SET used = 1 WHERE id = ?')->execute([$otpRowId]);
                $reason = $channel->getLastError() ?? 'Unknown SMTP error';
                Logger::audit($pdo, (int) $user['id'], 'OTP_EMAIL_FAILED', 'Password reset: ' . substr($reason, 0, 220), 'FAILURE');
            }
        } else {
            // Equalize timing for non-existing accounts (no user enumeration)
            password_hash('000000', PASSWORD_BCRYPT);
        }

        Response::json([
            'success' => true,
            'message' => 'If the account exists, a password reset OTP has been sent to its registered email.',
        ]);
    }

    public function resetPassword(Request $req): void
    {
        $data = Validator::validate($req->body(), [
            'identifier'   => 'required|min:3|max:60',
            'otp'          => 'required|min:6|max:6',
            'new_password' => 'required|min:10|max:128',
        ]);

        $ident = trim((string) $data['identifier']);
        $newPw = (string) $data['new_password'];
        if (!preg_match('/[A-Za-z]/', $newPw) || !preg_match('/\d/', $newPw)) {
            Response::error('New password must be at least 10 characters and include both letters and numbers.', 422);
        }
        if (strcasecmp($newPw, $ident) === 0) {
            Response::error('New password cannot be the same as your Login ID.', 422);
        }

        $pdo = Database::getConnection();
        RateLimiter::checkDbRate($pdo, 'reset_pw_ip', Request::clientIp(), 10, 300, 'Too many reset attempts. Please wait.');

        $uStmt = $pdo->prepare('SELECT id FROM users WHERE identifier = ? AND is_active = 1 LIMIT 1');
        $uStmt->execute([$ident]);
        $userId = (int) $uStmt->fetchColumn();
        if (!$userId) {
            password_verify('000000', '$2y$10$abcdefghijklmnopqrstuv123456789012345678901234567890');
            Response::error('Invalid or expired reset OTP.', 401);
        }

        $tStmt = $pdo->prepare(
            "SELECT * FROM otp_tokens
             WHERE user_id = ? AND purpose = 'password_reset' AND used = 0 AND expires_at > NOW()
             ORDER BY id DESC LIMIT 1"
        );
        $tStmt->execute([$userId]);
        $token = $tStmt->fetch();
        if (!$token || (int) $token['attempts'] >= 5 || !password_verify(trim((string) $data['otp']), $token['otp_hash'])) {
            if ($token) {
                $pdo->prepare('UPDATE otp_tokens SET attempts = attempts + 1 WHERE id = ?')->execute([(int) $token['id']]);
            }
            Response::error('Invalid or expired reset OTP.', 401);
        }

        $pdo->prepare('UPDATE otp_tokens SET used = 1 WHERE id = ?')->execute([(int) $token['id']]);
        $pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?')
            ->execute([password_hash($newPw, PASSWORD_BCRYPT), $userId]);

        Logger::audit($pdo, $userId, 'PASSWORD_RESET', 'Password reset via OTP');
        Response::json(['success' => true, 'message' => 'Password reset successful. You can now sign in.']);
    }

    private static function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return '***@***';
        }
        $u = $parts[0];
        $d = $parts[1];
        $prefix = mb_substr($u, 0, min(2, mb_strlen($u)));
        return $prefix . '****@' . $d;
    }

    private static function roleRedirect(string $role): string
    {
        return match ($role) {
            'student' => '/student-dashboard.html',
            'faculty' => '/faculty-dashboard.html',
            default   => '/admin-dashboard.html',
        };
    }
}
