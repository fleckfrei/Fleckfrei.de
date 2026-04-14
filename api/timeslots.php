<?php
/**
 * Public timeslots endpoint — GET ?date=YYYY-MM-DD&duration=3
 * Returns available hourly slots between 07:00 and 20:00.
 * A slot is "busy" if ANY job overlaps on that date.
 */
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json; charset=utf-8');

$date = $_GET['date'] ?? '';
$duration = max(1, min(8, (int)($_GET['duration'] ?? 2)));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['ok' => false, 'error' => 'Bad date']); exit;
}
$today = date('Y-m-d');
if ($date < $today) {
    echo json_encode(['ok' => false, 'error' => 'Past date']); exit;
}

// Admin-Sperren check
try {
    $email = strtolower(trim($_GET['email'] ?? ''));
    $isPremium = 0; $cid = 0;
    if ($email) {
        $c = one("SELECT customer_id, is_premium FROM customer WHERE LOWER(email)=? LIMIT 1", [$email]);
        if ($c) { $cid = (int)$c['customer_id']; $isPremium = (int)$c['is_premium']; }
    }
    $pbTokenReq = trim($_GET['voucher_code'] ?? $_GET['prebook_token'] ?? ''); // nutzen wir als Link-Kontext
    $blocks = all("SELECT applies_to, customer_id_fk, prebook_token, weekday_mask, reason FROM admin_blocked_days WHERE ? BETWEEN date_from AND date_to", [$date]);
    $weekdayIso = (int) date('N', strtotime($date)); // Mo=1..So=7
    foreach ($blocks as $bl) {
        if (!empty($bl['weekday_mask'])) {
            $allowed = array_map('intval', explode(',', $bl['weekday_mask']));
            if (!in_array($weekdayIso, $allowed, true)) continue;
        }
        if (!empty($bl['prebook_token']) && $bl['prebook_token'] !== $pbTokenReq) continue;

        // OR-Logic: customer_id_fk OR applies_to match
        $matchCustomer = $bl['customer_id_fk'] && (int)$bl['customer_id_fk'] === $cid;
        $matchRole = false;
        if ($bl['applies_to'] === 'all') $matchRole = true;
        elseif ($bl['applies_to'] === 'premium_only' && $isPremium) $matchRole = true;
        elseif ($bl['applies_to'] === 'non_premium' && !$isPremium) $matchRole = true;
        elseif ($bl['applies_to'] === 'prebook_only' && $pbTokenReq) $matchRole = true;

        // Wenn customer_id_fk gesetzt → MUSS match sein. Wenn nicht gesetzt → Role entscheidet.
        $match = $bl['customer_id_fk'] ? $matchCustomer : $matchRole;
        if ($match) {
            echo json_encode(['ok' => false, 'error' => 'blocked', 'reason' => $bl['reason'] ?: 'Dieser Tag ist gesperrt.', 'slots' => []]);
            exit;
        }
    }
} catch (Exception $e) {}

// Fetch all active (non-cancelled) jobs on that date — incl. premium-block info
$jobs = all("SELECT j_id, j_time, j_hours, job_status, travel_block_until FROM jobs
             WHERE j_date=? AND status=1
             AND (job_status IS NULL OR UPPER(job_status) NOT IN ('CANCELLED','CANCELED','REJECTED','DELETED'))",
            [$date]);

// Premium-Kontext aus Voucher oder Customer
$requestedVoucher = strtoupper(trim($_GET['voucher_code'] ?? ''));
$requestedEmail   = strtolower(trim($_GET['email'] ?? ''));
$requestedBlockUntil = null;

if ($requestedVoucher) {
    try {
        $v = one("SELECT block_until_time FROM vouchers WHERE code=? AND active=1", [$requestedVoucher]);
        if ($v && !empty($v['block_until_time'])) $requestedBlockUntil = $v['block_until_time'];
    } catch (Exception $e) {}
}
if (!$requestedBlockUntil && $requestedEmail) {
    try {
        $c = one("SELECT is_premium, travel_block_until FROM customer WHERE LOWER(email)=? AND status=1 LIMIT 1", [$requestedEmail]);
        if ($c && (int)$c['is_premium'] === 1 && !empty($c['travel_block_until'])) {
            $requestedBlockUntil = $c['travel_block_until'];
        }
    } catch (Exception $e) {}
}
$busyRanges = [];
$premiumExists = false; // any premium job already blocks the day
foreach ($jobs as $j) {
    $startH = (float) substr($j['j_time'], 0, 2) + (float) substr($j['j_time'], 3, 2) / 60;
    $endH   = $startH + (float)$j['j_hours'];

    // Existing premium jobs: block until their travel_block_until
    if (!empty($j['travel_block_until'])) {
        $blockEnd = (float) substr($j['travel_block_until'], 0, 2) + (float) substr($j['travel_block_until'], 3, 2) / 60;
        $endH = max($endH, $blockEnd);
        $startH = min($startH, 0); // lock from start of day
        $premiumExists = true;
    }
    $busyRanges[] = [$startH, $endH];
}

// Build slot list 06:00 – 22:00 (1h steps) — matches Main-Calendar working window
$startHour = 6;
$endHour = 22;
$slots = [];
$nowHour = (float) date('H') + (float) date('i') / 60;
$isToday = ($date === $today);

// If the request is itself a premium booking: only allow slots where the END is ≤ block_until
$reqBlockH = null;
if ($requestedBlockUntil) {
    $reqBlockH = (float) substr($requestedBlockUntil, 0, 2) + (float) substr($requestedBlockUntil, 3, 2) / 60;
}

for ($h = $startHour; $h <= ($endHour - $duration); $h++) {
    $slotStart = $h;
    $slotEnd   = $h + $duration;
    $status = 'free';

    // Past slot today?
    if ($isToday && $slotStart < $nowHour + 1) $status = 'past';

    // Overlap with any existing job?
    if ($status === 'free') {
        foreach ($busyRanges as [$bs, $be]) {
            if ($slotStart < $be && $slotEnd > $bs) { $status = 'busy'; break; }
        }
    }

    // Premium-booking: only allow slot if it ends BY the block-until time
    if ($status === 'free' && $reqBlockH !== null && $slotEnd > $reqBlockH) {
        $status = 'premium_locked';
    }

    $slots[] = [
        'time'   => sprintf('%02d:00', $h),
        'end'    => sprintf('%02d:00', min(24, $h + $duration)),
        'status' => $status,
    ];
}

echo json_encode([
    'ok'              => true,
    'date'            => $date,
    'duration'        => $duration,
    'slots'           => $slots,
    'booked'          => count($busyRanges),
    'premium_exists'  => $premiumExists,
    'request_block'   => $requestedBlockUntil,
]);
