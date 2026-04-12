<?php
/**
 * Auth Debug — diagnose login issues for any user type
 * GET /api/auth-debug.php?email=partner@example.com&cron=flk_scrape_2026
 * Admin-only or cron-secret.
 */
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json; charset=utf-8');

session_start();
$isAdmin = (($_SESSION['utype'] ?? '') === 'admin');
$isCron = ($_GET['cron'] ?? '') === (defined('CRON_SECRET') ? CRON_SECRET : 'flk_scrape_2026');
if (!$isAdmin && !$isCron) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$email = trim($_GET['email'] ?? '');
if (!$email) {
    echo json_encode(['error' => 'email parameter required']);
    exit;
}

$result = ['email' => $email, 'checks' => []];

// 1. Check users table
$userRow = one("SELECT id, email, type, created_at FROM users WHERE email=? LIMIT 1", [$email]);
$result['checks']['users_table'] = $userRow
    ? ['status' => 'ok', 'type' => $userRow['type'], 'id' => $userRow['id']]
    : ['status' => 'FAIL', 'error' => 'Email not found in users table. User cannot login.', 'fix' => "INSERT INTO users (email, type) VALUES ('$email', 'employee')"];

// 2. Check each possible table
foreach (['admin' => 'admin_id', 'customer' => 'customer_id', 'employee' => 'emp_id'] as $table => $idCol) {
    $row = one("SELECT $idCol as id, email, name, status, password FROM `$table` WHERE email=? LIMIT 1", [$email]);
    if ($row) {
        $passLen = strlen($row['password'] ?? '');
        $isBcrypt = str_starts_with($row['password'] ?? '', '$2');
        $result['checks'][$table . '_table'] = [
            'status' => 'found',
            'id' => $row['id'],
            'name' => $row['name'],
            'active' => (int)$row['status'] === 1,
            'password_set' => $passLen > 0,
            'password_length' => $passLen,
            'password_bcrypt' => $isBcrypt,
            'issues' => array_filter([
                (int)$row['status'] !== 1 ? "FAIL: status={$row['status']} (must be 1)" : null,
                $passLen === 0 ? "FAIL: password is empty" : null,
                (!$isBcrypt && $passLen > 0) ? "WARN: plaintext password (will auto-migrate on next login)" : null,
            ]),
        ];
        if ((int)$row['status'] !== 1) {
            $result['checks'][$table . '_table']['fix'] = "UPDATE `$table` SET status=1 WHERE $idCol={$row['id']}";
        }
    }
}

// 3. Check if user type matches table
if ($userRow) {
    $type = $userRow['type'];
    $idCol = match($type) { 'admin' => 'admin_id', 'customer' => 'customer_id', 'employee' => 'emp_id', default => null };
    if ($idCol) {
        $matchRow = one("SELECT $idCol as id, status FROM `$type` WHERE email=? AND status=1 LIMIT 1", [$email]);
        $result['checks']['type_match'] = $matchRow
            ? ['status' => 'ok', 'type' => $type, 'id' => $matchRow['id'], 'active' => true]
            : ['status' => 'FAIL', 'error' => "users.type='$type' but no active row in `$type` table", 'fix' => "Check $type table for status=1"];
    }
}

// 4. Rate-limiting check
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$lockFile = sys_get_temp_dir() . '/fleckfrei_login_' . md5($ip);
if (file_exists($lockFile)) {
    $data = json_decode(file_get_contents($lockFile), true);
    $result['checks']['rate_limit'] = [
        'attempts' => $data['count'] ?? 0,
        'locked' => ($data['count'] ?? 0) >= 5 && (time() - ($data['time'] ?? 0)) < 900,
        'expires_in_sec' => max(0, 900 - (time() - ($data['time'] ?? 0))),
    ];
} else {
    $result['checks']['rate_limit'] = ['status' => 'ok', 'attempts' => 0];
}

// 5. Quick fix suggestions
$fixes = [];
if (!$userRow) {
    $fixes[] = "INSERT INTO users (email, type) VALUES ('" . addslashes($email) . "', 'employee');";
}
foreach ($result['checks'] as $check) {
    if (!empty($check['fix'])) $fixes[] = $check['fix'];
}
$result['suggested_fixes'] = $fixes;

// 6. All employees overview (for debugging)
$allEmps = all("SELECT emp_id, name, email, status, employee_type FROM employee WHERE status=1 ORDER BY name");
$result['active_employees'] = count($allEmps);
$result['employee_list'] = array_map(fn($e) => $e['name'] . ' <' . $e['email'] . '> (' . $e['employee_type'] . ')', $allEmps);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
