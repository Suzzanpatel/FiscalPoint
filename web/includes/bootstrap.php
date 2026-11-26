<?php
/**
 * FiscalPoint application bootstrap.
 *
 * Every page includes this file first. It:
 *   - loads configuration from the project-level .env file
 *   - starts a hardened session (HttpOnly, SameSite=Lax, strict mode)
 *   - opens the MySQL connection ($conn)
 *   - provides helpers for output escaping, CSRF protection, auth guards,
 *     redirects and building URLs that work from any sub-folder.
 */

declare(strict_types=1);

/**
 * Readable error page instead of a blank "HTTP ERROR 500". Details (message, file, line) are
 * shown only to requests from the same machine (local development) or when APP_DEBUG=true;
 * everyone else gets a generic message. Registered first so it also covers setup problems
 * such as a missing extension or an unreadable .env file.
 */
function fiscalpoint_error_page(string $message, string $file, int $line): void
{
    $local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true) || PHP_SAPI === 'cli';
    $debug = (($_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG')) === 'true');
    error_log("FiscalPoint fatal error: $message in $file:$line");
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<div style="font-family:sans-serif;max-width:900px;margin:40px auto;padding:20px;border:1px solid #d9534f;border-radius:8px;">'
        . '<h2 style="color:#d9534f;margin-top:0;">FiscalPoint could not load this page</h2>';
    if ($local || $debug) {
        echo '<p><strong>Error:</strong> ' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><strong>Where:</strong> ' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . ' line ' . $line . '</p>'
            . '<p style="color:#666;">Shown because you are on the same machine as the server. See docs/SETUP.md, "Troubleshooting".</p>';
    } else {
        echo '<p>Please try again later.</p>';
    }
    echo '</div>';
}

set_exception_handler(static function (Throwable $e): void {
    fiscalpoint_error_page(get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine());
});

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        fiscalpoint_error_page($error['message'], $error['file'], $error['line']);
    }
});

define('WEB_ROOT', dirname(__DIR__));          // .../FiscalPoint/web
define('PROJECT_ROOT', dirname(WEB_ROOT));     // .../FiscalPoint

$autoload = PROJECT_ROOT . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    exit('Missing PHP dependencies. Run "composer install" in the project root.');
}
require_once $autoload;

Dotenv\Dotenv::createImmutable(PROJECT_ROOT)->safeLoad();

/** Read a configuration value from the environment with a default. */
function env(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    return ($value === false || $value === null) ? $default : (string) $value;
}

// One timezone for PHP *and* the MySQL session (set in db.php). Mixing PHP's clock with the
// database's NOW()/CURDATE() made fresh password-reset links look expired and could put
// expenses in the wrong day or month around midnight.
if (!@date_default_timezone_set(env('APP_TIMEZONE', 'Asia/Kolkata'))) {
    date_default_timezone_set('UTC');
}

$isDebug = env('APP_DEBUG', 'false') === 'true';
error_reporting(E_ALL);
ini_set('display_errors', $isDebug ? '1' : '0');
ini_set('log_errors', '1');

// ---------------------------------------------------------------- session
if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// --------------------------------------------------------------- database
require_once __DIR__ . '/db.php';

// ---------------------------------------------------------------- helpers

/** HTML-escape a value for safe output. */
function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** JSON-encode a value so it can be embedded safely inside a <script> tag. */
function js($value): string
{
    return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
}

/** Format an amount as Indian rupees. */
function money($amount): string
{
    return '₹' . number_format((float) $amount, 2);
}

/** Relative prefix from the current script back to the web root (e.g. "../" inside admin/). */
function base_prefix(): string
{
    static $prefix = null;
    if ($prefix === null) {
        $scriptDir = realpath(dirname($_SERVER['SCRIPT_FILENAME'] ?? WEB_ROOT)) ?: WEB_ROOT;
        $relative = trim(substr($scriptDir, strlen(realpath(WEB_ROOT) ?: WEB_ROOT)), DIRECTORY_SEPARATOR);
        $depth = $relative === '' ? 0 : substr_count($relative, DIRECTORY_SEPARATOR) + 1;
        $prefix = str_repeat('../', $depth);
    }
    return $prefix;
}

/** URL of a page relative to the web root, usable from any folder. */
function url(string $path): string
{
    return base_prefix() . ltrim($path, '/');
}

/** URL of a static asset (assets/css, assets/js, assets/img). */
function asset(string $path): string
{
    return base_prefix() . 'assets/' . ltrim($path, '/');
}

/** Show a browser alert and redirect. Message and target are escaped for JS. */
function alert_redirect(string $message, ?string $target = null): void
{
    $targetJs = $target === null ? 'window.history.back();' : 'window.location.href = ' . js($target) . ';';
    echo '<script>alert(' . js($message) . '); ' . $targetJs . '</script>';
    exit();
}

/** Plain HTTP redirect. */
function redirect(string $target): void
{
    header('Location: ' . $target);
    exit();
}

// ------------------------------------------------------------------- CSRF

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Hidden input carrying the CSRF token, for use inside every POST form. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Verify the CSRF token of the current POST request (form field or X-CSRF-Token header). */
function csrf_verify(?string $token = null): void
{
    $token = $token ?? ($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if (!is_string($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        exit('Invalid or expired form token. Please reload the page and try again.');
    }
}

/** True when the request is a POST; verifies CSRF automatically. */
function is_post(): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return false;
    }
    csrf_verify();
    return true;
}

// ------------------------------------------------------------ auth guards

function current_user_id(): ?int
{
    return isset($_SESSION['Uid']) ? (int) $_SESSION['Uid'] : null;
}

function is_admin(): bool
{
    return ($_SESSION['Role'] ?? '') === 'admin';
}

/** Require a logged-in user; returns the user id. */
function require_login(): int
{
    $uid = current_user_id();
    if ($uid === null) {
        redirect(url('login.php'));
    }
    return $uid;
}

/** Require a logged-in administrator; returns the admin's user id. */
function require_admin(): int
{
    $uid = require_login();
    if (!is_admin()) {
        http_response_code(403);
        redirect(url('dashboard.php'));
    }
    return $uid;
}

/** Store the authenticated user in a fresh session. */
function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['Uid'] = (int) $user['Uid'];
    $_SESSION['Uname'] = $user['Uname'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['Role'] = $user['Role'];
    unset($_SESSION['csrf_token']);
}

/** Validate a YYYY-MM month string. */
function valid_month($month): bool
{
    return is_string($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1;
}

/** Server-side password policy (mirrors assets/js/signup.js). */
function password_problem(string $password): ?string
{
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters long.';
    }
    if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/\d/', $password)) {
        return 'Password must contain an uppercase letter, a lowercase letter and a number.';
    }
    return null;
}

/** Expense categories offered in the UI (also the label set used by the ML categorizer). */
const EXPENSE_CATEGORIES = [
    'Housing', 'Food', 'Transportation', 'Healthcare', 'Education', 'Entertainment',
    'Personal Care', 'Debt Repayment', 'Savings & Investments', 'Insurance',
    'Childcare/Dependents', 'Gifts & Donations', 'Miscellaneous',
];

const PAYMENT_METHODS = ['Cash', 'Credit / Debit card', 'UPI Payment'];
