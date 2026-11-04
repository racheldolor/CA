<?php
/**
 * Centralized Supabase PostgreSQL connection.
 */

function loadEnvironmentFile(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if ($value !== '' && (($value[0] ?? '') === '"' || ($value[0] ?? '') === "'")) {
            $value = trim($value, "\"'");
        }

        if ($name !== '' && getenv($name) === false) {
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
        }
    }
}

loadEnvironmentFile(dirname(__DIR__) . '/.env');

define('DB_CONNECTION', getenv('DB_CONNECTION') ?: 'pgsql');
define('DB_HOST', getenv('DB_HOST') ?: 'db.zxjvyaogmjwgeubghomx.supabase.co');
define('DB_PORT', (int)(getenv('DB_PORT') ?: 5432));
define('DB_NAME', getenv('DB_DATABASE') ?: 'postgres');
define('DB_USER', getenv('DB_USERNAME') ?: 'postgres');
define('DB_PASS', getenv('DB_PASSWORD') ?: (getenv('SUPABASE_PASS') ?: ''));
define('DB_SSLMODE', getenv('DB_SSLMODE') ?: 'require');

function getDBConnection(): \PDO
{
    static $connection;

    if ($connection instanceof PDO) {
        return $connection;
    }

    if (DB_CONNECTION !== 'pgsql') {
        throw new RuntimeException('Only the PostgreSQL database connection is supported.');
    }

    if (DB_PASS === '') {
        throw new RuntimeException('DB_PASSWORD or SUPABASE_PASS is not configured.');
    }

    $dsn = sprintf(
        'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s;connect_timeout=10',
        DB_HOST,
        DB_PORT,
        DB_NAME,
        DB_SSLMODE
    );

    try {
        $connection = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $connection;
    } catch (PDOException $e) {
        error_log('Database connection failed: ' . $e->getMessage());
        throw new RuntimeException('Database connection failed.', 0, $e);
    }
}

function logAdminAction(PDO $pdo, int $admin_id, string $action, ?int $target_user_id = null, ?string $details = null): void
{
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO admin_logs (admin_id, action, target_user_id, details, ip_address) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $admin_id,
            $action,
            $target_user_id,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);
    } catch (Throwable $e) {
        error_log('Admin action logging failed: ' . $e->getMessage());
    }
}
