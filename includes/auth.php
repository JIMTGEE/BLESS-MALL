<?php
/**
 * auth.php – Modern Authentication & Authorization System
 * Bless Mall – Quality Chinese Products | Ghana
 *
 * Features:
 *   - Class‑based organization (Auth, CSRFToken, RateLimiter)
 *   - Argon2id password hashing with Bcrypt fallback
 *   - Email/Phone login + registration with optional email verification
 *   - Remember‑Me (token stored in DB)
 *   - Login brute‑force protection (rate limiter)
 *   - Session fingerprinting to prevent hijacking
 *   - Password reset workflow (token + email)
 *   - Delivery user support with proper session handling
 *   - Follows PHP 8.1+ best practices, fully commented
 */

// -----------------------------------------------
// 1. LOAD CONFIG ONLY ONCE – solve “constant already defined”
// -----------------------------------------------
if (!defined('BLESSMALL_CONFIG_INCLUDED')) {
    require_once __DIR__ . '/config.php';
}

// -----------------------------------------------
// 3. GET PDO CONNECTION (prepared statements via PDO for clarity)
// -----------------------------------------------
function getAuthPDO(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            DB_HOST,
            DB_NAME
        );
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

// -----------------------------------------------
// 4. CSRF TOKEN CLASS
// -----------------------------------------------
class CSRFToken
{
    public static function generate(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function field(): string
    {
        return sprintf(
            '<input type="hidden" name="csrf_token" value="%s">',
            htmlspecialchars(self::generate(), ENT_QUOTES, 'UTF-8')
        );
    }

    public static function validate(#[\SensitiveParameter] ?string $token = null): bool
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return true;
        }
        $token ??= $_POST['csrf_token'] ?? '';
        if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
            unset($_SESSION['csrf_token']);
            return false;
        }
        // One‑time token
        unset($_SESSION['csrf_token']);
        return true;
    }
}

// -----------------------------------------------
// 5. RATE LIMITER (brute force protection)
// -----------------------------------------------
class RateLimiter
{
    public static function recordAttempt(string $identifier, ?int $userId, bool $success): void
    {
        $pdo = getAuthPDO();
        $stmt = $pdo->prepare(
            "INSERT INTO login_attempts (identifier, user_id, status, ip_address)
             VALUES (:identifier, :uid, :status, :ip)"
        );
        $stmt->execute([
            ':identifier' => $identifier,
            ':uid'        => $userId,
            ':status'     => $success ? 'success' : 'failed',
            ':ip'         => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
        ]);
    }

    public static function isLockedOut(string $identifier): bool
    {
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . LOGIN_LOCKOUT_MINUTES . ' minutes'));
        $pdo = getAuthPDO();
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE identifier = :id AND status = 'failed' AND attempted_at > :cutoff"
        );
        $stmt->execute([':id' => $identifier, ':cutoff' => $cutoff]);
        return $stmt->fetchColumn() >= MAX_LOGIN_ATTEMPTS;
    }

    public static function cleanOldAttempts(): void
    {
        $cutoff = date('Y-m-d H:i:s', strtotime('-48 hours'));
        $pdo = getAuthPDO();
        $pdo->prepare("DELETE FROM login_attempts WHERE attempted_at < :cutoff")
            ->execute([':cutoff' => $cutoff]);
    }
}

// -----------------------------------------------
// 6. MAIN AUTH CLASS
// -----------------------------------------------
class Auth
{
    private static ?PDO $pdo = null;

    private static function pdo(): PDO
    {
        return self::$pdo ??= getAuthPDO();
    }

    // ----- Password Helpers -----
    public static function hashPassword(#[\SensitiveParameter] string $password): string
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash($password, PASSWORD_ARGON2ID);
        }
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => PASSWORD_COST]);
    }

    public static function verifyPassword(#[\SensitiveParameter] string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        $options = $algo === PASSWORD_BCRYPT ? ['cost' => PASSWORD_COST] : [];
        return password_needs_rehash($hash, $algo, $options);
    }

    public static function validatePasswordStrength(#[\SensitiveParameter] string $password): array
    {
        if (strlen($password) < 8) {
            return ['valid' => false, 'message' => 'Password must be at least 8 characters.'];
        }
        if (!preg_match('/[A-Z]/', $password)) {
            return ['valid' => false, 'message' => 'Must contain at least one uppercase letter.'];
        }
        if (!preg_match('/[a-z]/', $password)) {
            return ['valid' => false, 'message' => 'Must contain at least one lowercase letter.'];
        }
        if (!preg_match('/[0-9]/', $password)) {
            return ['valid' => false, 'message' => 'Must contain at least one number.'];
        }
        return ['valid' => true, 'message' => 'Strong password.'];
    }

    // ----- Session Management -----
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public static function regenerateSession(): void
    {
        session_regenerate_id(true);
    }

    public static function isSessionValid(): bool
    {
        return isset($_SESSION['user_ip'], $_SESSION['user_agent'])
            && $_SESSION['user_ip'] === ($_SERVER['REMOTE_ADDR'] ?? '')
            && $_SESSION['user_agent'] === ($_SERVER['HTTP_USER_AGENT'] ?? '');
    }

    // ----- Helper to load deliverer data into session -----
    private static function loadDelivererData(int $userId): void
    {
        try {
            $pdo = self::pdo();
            $stmt = $pdo->prepare(
                "SELECT id, availability_status, vehicle_type, license_number 
                 FROM deliverers 
                 WHERE user_id = :uid 
                 LIMIT 1"
            );
            $stmt->execute([':uid' => $userId]);
            $deliverer = $stmt->fetch();
            
            if ($deliverer) {
                $_SESSION['deliverer_id'] = (int)$deliverer['id'];
                $_SESSION['deliverer_status'] = $deliverer['availability_status'];
                $_SESSION['deliverer_vehicle_type'] = $deliverer['vehicle_type'] ?? null;
            } else {
                // Clear any existing deliverer session data
                unset($_SESSION['deliverer_id'], $_SESSION['deliverer_status'], $_SESSION['deliverer_vehicle_type']);
            }
        } catch (PDOException $e) {
            // Deliverer table might not exist - just continue
            error_log("Deliverer data load failed: " . $e->getMessage());
            unset($_SESSION['deliverer_id'], $_SESSION['deliverer_status'], $_SESSION['deliverer_vehicle_type']);
        }
    }

    // ----- Helper to load role name -----
    private static function loadRoleName(int $roleId): ?string
    {
        try {
            $pdo = self::pdo();
            $stmt = $pdo->prepare(
                "SELECT role_name FROM roles WHERE id = :id LIMIT 1"
            );
            $stmt->execute([':id' => $roleId]);
            $role = $stmt->fetch();
            return $role ? $role['role_name'] : null;
        } catch (PDOException $e) {
            error_log("Role name load failed: " . $e->getMessage());
            return null;
        }
    }

    // ----- Login -----
    public static function login(
        string $identifier,
        #[\SensitiveParameter] string $password,
        bool $remember = false
    ): array {
        if (RateLimiter::isLockedOut($identifier)) {
            return [
                'success' => false,
                'message' => 'Too many failed attempts. Try again in ' . LOGIN_LOCKOUT_MINUTES . ' minutes.'
            ];
        }

        $identifier = trim($identifier);
        $pdo = self::pdo();
        $stmt = $pdo->prepare(
            "SELECT id, role_id, name, email, phone, password, is_active, account_type
             FROM users
             WHERE (email = :id OR phone = :id2) AND deleted_at IS NULL
             LIMIT 1"
        );
        $stmt->execute([':id' => $identifier, ':id2' => $identifier]);
        $user = $stmt->fetch();

        if (!$user) {
            RateLimiter::recordAttempt($identifier, null, false);
            return ['success' => false, 'message' => 'Invalid email/phone or password.'];
        }

        if (!$user['is_active']) {
            return ['success' => false, 'message' => 'Your account has been deactivated. Contact support.'];
        }

        if (!self::verifyPassword($password, $user['password'])) {
            RateLimiter::recordAttempt($identifier, $user['id'], false);
            return ['success' => false, 'message' => 'Invalid email/phone or password.'];
        }

        // Rehash if needed
        if (self::needsRehash($user['password'])) {
            $newHash = self::hashPassword($password);
            $pdo->prepare("UPDATE users SET password = :pw WHERE id = :uid")
                ->execute([':pw' => $newHash, ':uid' => $user['id']]);
        }

        // Success - record attempt and setup session
        RateLimiter::recordAttempt($identifier, $user['id'], true);
        self::regenerateSession();

        // Get role name
        $role_name = self::loadRoleName((int)$user['role_id']);

        // Set basic session data
        $_SESSION['user_id']      = (int)$user['id'];
        $_SESSION['user_name']    = $user['name'];
        $_SESSION['user_email']   = $user['email'];
        $_SESSION['user_phone']   = $user['phone'];
        $_SESSION['role_id']      = (int)$user['role_id'];
        $_SESSION['role_name']    = $role_name ?? 'buyer'; // Added: role name from roles table
        $_SESSION['account_type'] = $user['account_type'];
        $_SESSION['logged_in']    = true;
        $_SESSION['login_time']   = time();
        $_SESSION['user_ip']      = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $_SESSION['user_agent']   = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // Update last_login
        $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = :uid")
            ->execute([':uid' => $user['id']]);

        // Load vendor data for non‑buyers
        if (in_array((int)$user['role_id'], [ROLE_ADMIN, ROLE_SELLER], true)) {
            $stmt = $pdo->prepare(
                "SELECT id, business_name, is_express FROM vendors WHERE user_id = :uid LIMIT 1"
            );
            $stmt->execute([':uid' => $user['id']]);
            $vendor = $stmt->fetch();
            if ($vendor) {
                $_SESSION['vendor_id']     = $vendor['id'];
                $_SESSION['business_name'] = $vendor['business_name'];
                $_SESSION['is_express']    = (int)$vendor['is_express'];
            }
        }

        // Load deliverer data if user is a deliverer
        self::loadDelivererData($user['id']);

        // Remember me
        if ($remember) {
            self::setRememberMe($user['id']);
        }

        // Return success with redirect info (login.php will make final redirect decision)
        return [
            'success'  => true,
            'message'  => 'Welcome back, ' . htmlspecialchars($user['name']) . '!',
            'user_id'  => $user['id'],
            'role_id'  => (int)$user['role_id'],
            'role_name' => $role_name ?? 'buyer', // Added: include role_name in return
            'is_deliverer' => isset($_SESSION['deliverer_id'])
        ];
    }

    // ----- Remember Me -----
    private static function setRememberMe(int $userId): void
    {
        $pdo = self::pdo();
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expires = date('Y-m-d H:i:s', strtotime('+' . REMEMBER_ME_DAYS . ' days'));

        // Store hash in DB
        $stmt = $pdo->prepare(
            "INSERT INTO remember_tokens (user_id, token_hash, expires_at) VALUES (:uid, :hash, :exp)"
        );
        $stmt->execute([':uid' => $userId, ':hash' => $tokenHash, ':exp' => $expires]);

        // Set cookie (user_id:raw_token)
        $cookieValue = $userId . ':' . $rawToken;
        setcookie('remember_token', $cookieValue, [
            'expires'  => strtotime('+' . REMEMBER_ME_DAYS . ' days'),
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function checkRememberMe(): void
    {
        if (self::isLoggedIn() || !isset($_COOKIE['remember_token'])) {
            return;
        }

        $parts = explode(':', $_COOKIE['remember_token'], 2);
        if (count($parts) !== 2) {
            return;
        }
        [$userId, $rawToken] = $parts;
        $userId = (int)$userId;
        $tokenHash = hash('sha256', $rawToken);

        $pdo = self::pdo();
        $stmt = $pdo->prepare(
            "SELECT * FROM remember_tokens
             WHERE user_id = :uid AND token_hash = :hash AND expires_at > NOW()
             ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute([':uid' => $userId, ':hash' => $tokenHash]);
        $row = $stmt->fetch();

        if (!$row) {
            // Invalid or expired – delete cookie
            setcookie('remember_token', '', time() - 3600, '/');
            return;
        }

        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :uid AND is_active = 1 AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([':uid' => $userId]);
        $user = $stmt->fetch();

        if ($user) {
            // Get role name
            $role_name = self::loadRoleName((int)$user['role_id']);
            
            self::regenerateSession();
            $_SESSION['user_id']      = (int)$user['id'];
            $_SESSION['user_name']    = $user['name'];
            $_SESSION['user_email']   = $user['email'];
            $_SESSION['user_phone']   = $user['phone'];
            $_SESSION['role_id']      = (int)$user['role_id'];
            $_SESSION['role_name']    = $role_name ?? 'buyer'; // Added: role name from roles table
            $_SESSION['account_type'] = $user['account_type'];
            $_SESSION['logged_in']    = true;
            $_SESSION['login_time']   = time();
            $_SESSION['user_ip']      = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $_SESSION['user_agent']   = $_SERVER['HTTP_USER_AGENT'] ?? '';

            $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = :uid")
                ->execute([':uid' => $user['id']]);
            
            // Load deliverer data
            self::loadDelivererData($user['id']);
        }
    }

    // ----- Registration -----
    public static function register(array $data): array
    {
        $pdo = self::pdo();
        $name     = trim($data['name'] ?? '');
        $email    = trim($data['email'] ?? '');
        $phone    = trim($data['phone'] ?? '');
        $password = $data['password'] ?? '';

        // Basic validation
        if ($name === '' || $phone === '' || $password === '') {
            return ['success' => false, 'message' => 'Name, phone and password are required.'];
        }

        // Check existing email (if provided)
        if ($email !== '') {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => $email]);
            if ($stmt->fetch()) {
                return ['success' => false, 'message' => 'Email already registered.'];
            }
        }

        // Check existing phone
        $stmt = $pdo->prepare("SELECT id FROM users WHERE phone = :phone LIMIT 1");
        $stmt->execute([':phone' => $phone]);
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => 'Phone number already registered.'];
        }

        $hashed = self::hashPassword($password);

        $stmt = $pdo->prepare(
            "INSERT INTO users (role_id, name, email, phone, password, is_active, created_at, account_type)
             VALUES (:role, :name, :email, :phone, :pw, 1, NOW(), 'regular')"
        );
        $stmt->execute([
            ':role'  => ROLE_BUYER,
            ':name'  => $name,
            ':email' => $email,
            ':phone' => $phone,
            ':pw'    => $hashed,
        ]);

        $userId = (int)$pdo->lastInsertId();

        // Get role name for new user (buyer)
        $role_name = self::loadRoleName(ROLE_BUYER);

        // Auto login after registration
        self::regenerateSession();
        $_SESSION['user_id']      = $userId;
        $_SESSION['user_name']    = $name;
        $_SESSION['user_email']   = $email;
        $_SESSION['user_phone']   = $phone;
        $_SESSION['role_id']      = ROLE_BUYER;
        $_SESSION['role_name']    = $role_name ?? 'buyer'; // Added: role name from roles table
        $_SESSION['account_type'] = 'regular';
        $_SESSION['logged_in']    = true;
        $_SESSION['login_time']   = time();
        $_SESSION['user_ip']      = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $_SESSION['user_agent']   = $_SERVER['HTTP_USER_AGENT'] ?? '';

        return [
            'success'  => true,
            'message'  => 'Account created successfully! Welcome to Bless Mall.',
            'user_id'  => $userId,
            'redirect' => BASE_URL . 'account.php'
        ];
    }

    // ----- Logout -----
    public static function logout(): void
    {
        // Delete remember token if present
        if (isset($_COOKIE['remember_token'])) {
            $parts = explode(':', $_COOKIE['remember_token'], 2);
            if (count($parts) === 2) {
                $userId = (int)$parts[0];
                $pdo = self::pdo();
                $pdo->prepare("DELETE FROM remember_tokens WHERE user_id = :uid")
                    ->execute([':uid' => $userId]);
            }
            setcookie('remember_token', '', time() - 3600, '/');
        }

        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
        // Restart a clean session for guest cart etc.
        session_start();
    }

    // ----- Password Reset -----
    public static function sendPasswordResetLink(string $identifier): array
    {
        $pdo = self::pdo();
        $stmt = $pdo->prepare(
            "SELECT id, name, email FROM users WHERE (email = :id OR phone = :id2) AND deleted_at IS NULL LIMIT 1"
        );
        $stmt->execute([':id' => $identifier, ':id2' => $identifier]);
        $user = $stmt->fetch();

        if (!$user) {
            // Don't reveal if account exists
            return ['success' => true, 'message' => 'If that account exists, a reset link has been sent.'];
        }

        // Generate token
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

        $pdo->prepare("UPDATE password_resets SET used = 1 WHERE user_id = :uid AND used = 0")
            ->execute([':uid' => $user['id']]);
        $pdo->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (:uid, :hash, :exp)")
            ->execute([':uid' => $user['id'], ':hash' => $tokenHash, ':exp' => $expires]);

        $resetLink = BASE_URL . 'reset-password.php?token=' . urlencode($rawToken);
        if (!empty($user['email']) && filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
            $subject = SITE_NAME . ' password reset';
            $message = "Hello {$user['name']},\n\nUse the link below to reset your password. This link expires in one hour.\n\n{$resetLink}\n\nIf you did not request this, you can ignore this email.";
            $headers = [
                'From: ' . SITE_NAME . ' <' . SITE_EMAIL . '>',
                'Content-Type: text/plain; charset=UTF-8',
            ];
            if (!mail($user['email'], $subject, $message, implode("\r\n", $headers))) {
                error_log('Password reset email could not be sent for user id ' . (int)$user['id']);
            }
        }

        return ['success' => true, 'message' => 'If that email/phone exists, a reset link has been sent.'];
    }

    public static function resetPassword(
        #[\SensitiveParameter] string $token,
        #[\SensitiveParameter] string $newPassword
    ): array {
        $pdo = self::pdo();
        $tokenHash = hash('sha256', $token);

        $passwordStrength = self::validatePasswordStrength($newPassword);
        if (!$passwordStrength['valid']) {
            return ['success' => false, 'message' => $passwordStrength['message']];
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "SELECT id, user_id FROM password_resets
                 WHERE token_hash = :hash AND expires_at > NOW() AND used = 0
                 LIMIT 1 FOR UPDATE"
            );
            $stmt->execute([':hash' => $tokenHash]);
            $row = $stmt->fetch();

            if (!$row) {
                $pdo->rollBack();
                return ['success' => false, 'message' => 'Invalid or expired reset token.'];
            }

            $newHash = self::hashPassword($newPassword);
            $pdo->prepare("UPDATE users SET password = :pw WHERE id = :uid")
                ->execute([':pw' => $newHash, ':uid' => $row['user_id']]);
            $pdo->prepare("UPDATE password_resets SET used = 1 WHERE user_id = :uid AND used = 0")
                ->execute([':uid' => $row['user_id']]);
            $pdo->prepare("DELETE FROM remember_tokens WHERE user_id = :uid")
                ->execute([':uid' => $row['user_id']]);
            $pdo->commit();

            return ['success' => true, 'message' => 'Password has been reset. You can now log in.'];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

    }

    // ----- Authorization Checks -----
    public static function isLoggedIn(): bool
    {
        return !empty($_SESSION['logged_in']) && !empty($_SESSION['user_id']);
    }

    public static function isAdmin(): bool
    {
        return self::isLoggedIn() && isset($_SESSION['role_id']) && (int)$_SESSION['role_id'] === ROLE_ADMIN;
    }

    public static function isSeller(): bool
    {
        return self::isLoggedIn() && isset($_SESSION['role_id']) && (int)$_SESSION['role_id'] === ROLE_SELLER;
    }

    public static function isBuyer(): bool
    {
        return self::isLoggedIn() && isset($_SESSION['role_id']) && (int)$_SESSION['role_id'] === ROLE_BUYER;
    }

    public static function isDeliverer(): bool
    {
        return self::isLoggedIn()
            && ($_SESSION['role_name'] ?? '') === 'deliverer'
            && isset($_SESSION['deliverer_id']);
    }

    public static function getDelivererStatus(): ?string
    {
        return $_SESSION['deliverer_status'] ?? null;
    }

    public static function requireLogin(string $redirect = ''): void
    {
        if (!self::isLoggedIn()) {
            $_SESSION['redirect_after_login'] = $redirect ?: $_SERVER['REQUEST_URI'];
            header('Location: ' . BASE_URL . 'login.php');
            exit;
        }
    }

    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (!self::isAdmin()) {
            header('Location: ' . BASE_URL . 'account.php?error=unauthorized');
            exit;
        }
    }

    public static function requireSeller(): void
    {
        self::requireLogin();
        if (!self::isSeller() && !self::isAdmin()) {
            header('Location: ' . BASE_URL . 'account.php?error=unauthorized');
            exit;
        }
    }

    public static function requireDeliverer(): void
    {
        self::requireLogin();
        if (!self::isDeliverer()) {
            header('Location: ' . BASE_URL . 'login.php?error=delivery_access_denied');
            exit;
        }
    }
}

// -----------------------------------------------
// 7. INITIALIZE ON EVERY PAGE LOAD
// -----------------------------------------------
Auth::startSession();

// Auto‑login via remember me (if not already logged in)
Auth::checkRememberMe();

// Check session validity – force logout if hijack suspected
if (Auth::isLoggedIn() && !Auth::isSessionValid()) {
    Auth::logout();
}

// Generate CSRF token for all forms
CSRFToken::generate();

// Optional: clean old login attempts
RateLimiter::cleanOldAttempts();