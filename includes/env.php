<?php
// Simple .env loader — populates $_ENV / getenv() from project-root .env file.
// Must be required before includes/config.php.
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'env.php') { http_response_code(403); exit; }

if (!function_exists('loadEnv')) {
    function loadEnv(string $path): bool {
        if (!is_readable($path)) return false;
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            $eq = strpos($line, '=');
            if ($eq === false) continue;
            $name = trim(substr($line, 0, $eq));
            $value = trim(substr($line, $eq + 1));
            if (strlen($value) >= 2) {
                $f = $value[0]; $l = $value[strlen($value) - 1];
                if (($f === '"' && $l === '"') || ($f === "'" && $l === "'")) {
                    $value = substr($value, 1, -1);
                }
            }
            if ($name === '') continue;
            $_ENV[$name] = $value;
            putenv("$name=$value");
        }
        return true;
    }
}

if (!function_exists('env')) {
    function env(string $key, string $default = ''): string {
        if (array_key_exists($key, $_ENV)) return (string) $_ENV[$key];
        $v = getenv($key);
        return $v === false ? $default : (string) $v;
    }
}

loadEnv(__DIR__ . '/../.env');
