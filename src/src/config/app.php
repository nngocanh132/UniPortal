<?php
/**
 * UniPortal – Application environment
 * Base-path aware URL generation and production runtime settings.
 *
 * Locally the app is served from /UniPortal/public; on a cloud host the
 * public/ directory is the document root. Every internal link is generated
 * through app_url()/app_link() so the same code works in both layouts.
 */

require_once __DIR__ . '/env.php';

if (!defined('UNIPORTAL_BOOTSTRAPPED')) {
    define('UNIPORTAL_BOOTSTRAPPED', true);

    $appEnv = strtolower(env_str('APP_ENV', 'local'));
    $debug  = env_bool('APP_DEBUG', $appEnv !== 'production');

    error_reporting(E_ALL);
    ini_set('display_errors', $debug ? '1' : '0');
    ini_set('display_startup_errors', $debug ? '1' : '0');
    ini_set('log_errors', '1');

    $logDir = dirname(__DIR__, 3) . '/storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    if (is_dir($logDir) && is_writable($logDir)) {
        ini_set('error_log', $logDir . '/php-error.log');
    }

    if (ini_get('date.timezone') === '' || ini_get('date.timezone') === false) {
        date_default_timezone_set(env_str('APP_TIMEZONE', 'Asia/Ho_Chi_Minh'));
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
}

/**
 * URL path of the application's public directory.
 *   XAMPP   → /UniPortal/public
 *   Cloud   → '' (public/ is the document root)
 */
function app_base_path(): string {
    static $base = null;
    if ($base !== null) return $base;

    $explicit = env_str('APP_BASE_PATH', '');
    if ($explicit !== '') {
        $explicit = '/' . trim($explicit, '/');
        return $base = ($explicit === '/' ? '' : $explicit);
    }

    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));

    // /UniPortal/public/assets/... → /UniPortal/public
    if (($pos = strpos($script, '/public/')) !== false) {
        return $base = substr($script, 0, $pos + 7);
    }

    // Legacy root front controller /UniPortal/index.php → /UniPortal
    if (preg_match('~^(/[^/]+)/index\.php$~', $script, $m) === 1) {
        return $base = $m[1];
    }

    // public/ is the document root.
    return $base = '';
}

/**
 * Absolute URL path for a file inside public/.
 * app_url('assets/student/dashboard.php') → /UniPortal/public/assets/student/dashboard.php
 */
function app_url(string $path = ''): string {
    if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $path) === 1) {
        return $path;
    }
    return app_base_path() . '/' . ltrim($path, '/');
}

/**
 * Scheme + host of the current request (respects the TLS-terminating proxy).
 * Returns '' when the Host header is missing or malformed.
 */
function app_origin(): string {
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '' || preg_match('~[^A-Za-z0-9.\-:\[\]]~', $host) === 1) {
        return '';
    }
    $proto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $https = $proto !== ''
        ? $proto === 'https'
        : (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off');
    return ($https ? 'https://' : 'http://') . $host;
}

function app_absolute_url(string $path = ''): string {
    return app_origin() . app_url($path);
}

/**
 * Normalise a link that was stored or hardcoded with a legacy prefix.
 * Accepts /UniPortal/public/..., /public/..., /assets/... and plain
 * page-relative paths. Absolute http(s) links are returned untouched.
 */
function app_link(string $target): string {
    $target = trim($target);
    if ($target === '') return app_url('/');

    if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $target) === 1 || str_starts_with($target, '//')) {
        return $target;
    }

    // Page-relative links are already valid wherever the page lives.
    if ($target[0] !== '/') return $target;

    // Reduce the target to a path relative to the public directory.
    if (($pos = strpos($target, '/public/')) !== false) {
        $target = substr($target, $pos + strlen('/public'));
    } elseif ($target === '/public' || str_ends_with($target, '/public')) {
        $target = '/';
    } else {
        $prefixes = array_unique(array_filter([app_base_path(), '/UniPortal']));
        usort($prefixes, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($prefixes as $prefix) {
            if ($target === $prefix || str_starts_with($target, $prefix . '/')) {
                $target = substr($target, strlen($prefix));
                break;
            }
        }
        $target = '/' . ltrim($target, '/');
    }

    return app_url($target);
}
