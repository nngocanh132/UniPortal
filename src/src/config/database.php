<?php
/**
 * UniPortal – Database Connection
 * Returns a PDO instance connected to the uniportal MySQL database.
 * Credentials come from the environment (Railway MySQL plugin, DATABASE_URL)
 * with XAMPP defaults as a local fallback.
 */

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/app.php';

function db_config(): array {
    foreach (['DATABASE_URL', 'MYSQL_URL', 'DB_URL'] as $key) {
        $url = env_str($key, '');
        if ($url === '') continue;
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) continue;
        return [
            'host'    => $parts['host'],
            'port'    => (string)($parts['port'] ?? 3306),
            'name'    => ltrim($parts['path'] ?? '', '/') ?: 'uniportal',
            'user'    => urldecode((string)($parts['user'] ?? '')),
            'pass'    => urldecode((string)($parts['pass'] ?? '')),
            'charset' => 'utf8mb4',
        ];
    }

    return [
        'host'    => env_str('DB_HOST', env_str('MYSQLHOST', '127.0.0.1')),
        'port'    => env_str('DB_PORT', env_str('MYSQLPORT', '3306')),
        'name'    => env_str('DB_NAME', env_str('MYSQLDATABASE', 'uniportal')),
        'user'    => env_str('DB_USER', env_str('MYSQLUSER', 'root')),
        'pass'    => env_str('DB_PASS', env_str('MYSQLPASSWORD', '')),
        'charset' => env_str('DB_CHARSET', 'utf8mb4'),
    ];
}

function get_db(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $cfg = db_config();
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $cfg['host'], $cfg['port'], $cfg['name'], $cfg['charset']
        );
        try {
            $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            // Keep NOW()/CURDATE() aligned with PHP's timezone so stored
            // timestamps and PHP date() comparisons agree.
            $pdo->exec("SET time_zone = '" . (new DateTimeImmutable())->format('P') . "'");
        } catch (PDOException $e) {
            // Details go to the error log only – never to the response.
            error_log('Database connection failed: ' . $e->getMessage());
            http_response_code(500);
            exit('<p style="font-family:sans-serif;color:#c00;">'
               . 'Không thể kết nối cơ sở dữ liệu. Vui lòng thử lại sau hoặc liên hệ quản trị viên.'
               . '</p>');
        }
    }

    return $pdo;
}
