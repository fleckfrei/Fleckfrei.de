<?php
/**
 * Health Check API — app.fleckfrei.de
 * Checks: PHP syntax, page load, DB connection, disk space
 * Usage: GET /api/health.php?key=HEALTH_KEY
 * Returns JSON with status per check
 */

// No session needed
define('HEALTH_KEY', 'flk_health_2026_c9a3f1e7d4b2');

header('Content-Type: application/json; charset=utf-8');

if (($_GET['key'] ?? '') !== HEALTH_KEY) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$results = ['timestamp' => date('c'), 'checks' => [], 'errors' => []];

// 1. DB connection
try {
    require_once __DIR__ . '/../includes/config.php';
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5
    ]);
    $pdo->query('SELECT 1');
    $results['checks']['database'] = 'ok';
} catch (Exception $e) {
    $results['checks']['database'] = 'error';
    $results['errors'][] = 'DB: ' . $e->getMessage();
}

// 2. PHP syntax check on all files
$baseDir = dirname(__DIR__);
$phpFiles = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseDir, RecursiveDirectoryIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file->getExtension() === 'php' && strpos($file->getPathname(), '/vendor/') === false) {
        $phpFiles[] = $file->getPathname();
    }
}

$syntaxErrors = [];
foreach ($phpFiles as $f) {
    $output = [];
    exec("php -l " . escapeshellarg($f) . " 2>&1", $output, $code);
    if ($code !== 0) {
        $relPath = str_replace($baseDir, '', $f);
        $syntaxErrors[] = $relPath . ': ' . implode(' ', $output);
    }
}

$results['checks']['syntax'] = empty($syntaxErrors) ? 'ok' : 'error';
$results['checks']['syntax_files'] = count($phpFiles);
if (!empty($syntaxErrors)) {
    $results['errors'] = array_merge($results['errors'], $syntaxErrors);
}

// 3. Disk space
$free = disk_free_space($baseDir);
$total = disk_total_space($baseDir);
$usedPct = round((1 - $free / $total) * 100, 1);
$results['checks']['disk'] = $usedPct < 90 ? 'ok' : 'warning';
$results['checks']['disk_used_pct'] = $usedPct;
$results['checks']['disk_free_mb'] = round($free / 1024 / 1024);

// 4. Key pages HTTP check (internal include test)
$keyPages = [
    'admin/index.php', 'admin/jobs.php', 'admin/customers.php',
    'admin/invoices.php', 'admin/employees.php', 'admin/work-hours.php',
    'admin/messages.php', 'admin/live-map.php', 'admin/settings.php',
    'customer/index.php', 'employee/index.php', 'login.php'
];

$pageResults = [];
foreach ($keyPages as $page) {
    $fullPath = $baseDir . '/' . $page;
    if (!file_exists($fullPath)) {
        $pageResults[$page] = 'missing';
        $results['errors'][] = "Missing: $page";
        continue;
    }
    $pageResults[$page] = 'exists';
}
$results['checks']['pages'] = $pageResults;

// 5. Error log (last 10 lines)
$logFiles = [
    '/home/u860899303/domains/app.fleckfrei.de/logs/error.log',
    '/home/u860899303/logs/app.fleckfrei.de.error.log',
];
foreach ($logFiles as $lf) {
    if (file_exists($lf)) {
        $lines = array_slice(file($lf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -10);
        $results['checks']['recent_errors'] = $lines;
        break;
    }
}

// Overall status
$hasErrors = !empty($results['errors']);
$results['status'] = $hasErrors ? 'unhealthy' : 'healthy';
http_response_code($hasErrors ? 503 : 200);

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
