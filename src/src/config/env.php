<?php
/**
 * UniPortal – Environment loader
 * Reads the project-root .env file once. Real process environment variables
 * always win over file values so production secrets can be injected by the
 * web server without shipping a file.
 *
 * The .env file is denied by the root .htaccess and ignored by git.
 */

function uni_env(): void {
    static $loaded = false;
    if ($loaded) return;
    $loaded = true;

    $file = dirname(__DIR__, 3) . '/.env';
    if (!is_file($file) || !is_readable($file)) return;

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (str_starts_with($line, 'export ')) $line = ltrim(substr($line, 7));
        $eq = strpos($line, '=');
        if ($eq === false) continue;
        $key = trim(substr($line, 0, $eq));
        if ($key === '' || !preg_match('/\A[A-Z0-9_]+\z/i', $key)) continue;
        $value = trim(substr($line, $eq + 1));
        if ($value !== '' && strlen($value) >= 2) {
            $first = $value[0];
            $last  = substr($value, -1);
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }
        if (getenv($key) !== false) continue; // process env takes precedence
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

function env_str(string $key, string $default = ''): string {
    uni_env();
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

function env_bool(string $key, bool $default = false): bool {
    $value = strtolower(env_str($key, $default ? '1' : '0'));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}
