<?php
/**
 * Public calendar availability — gibt pro Tag einen Status zurück.
 * Filter: optional district (matched gegen Partner-Kapazität in dem Bezirk) + voucher_code (Block-Until).
 * Returns: {days: [{date, status: 'free'|'limited'|'busy'|'past', slots_free, slots_total}, ...]}
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/config.php';

$from = $_GET['from'] ?? date('Y-m-d');
$days = max(7, min(62, (int)($_GET['days'] ?? 30)));
$duration = max(1, min(8, (int)($_GET['duration'] ?? 2)));
$district = trim($_GET['district'] ?? '');
$voucher  = strtoupper(trim($_GET['voucher_code'] ?? ''));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d');

$startHour = 6; $endHour = 22;
$totalSlots = max(0, $endHour - $startHour - $duration + 1);

// Voucher-Block
$voucherBlockH = null;
if ($voucher) {
    try {
        $v = one("SELECT block_until_time FROM vouchers WHERE code=? AND active=1", [$voucher]);
        if ($v && !empty($v['block_until_time'])) {
            $voucherBlockH = (float) substr($v['block_until_time'],0,2) + (float) substr($v['block_until_time'],3,2)/60;
        }
    } catch (Exception $e) {}
}

// Partner-Kapazität pro Bezirk (einfache Schätzung: Anzahl aktive Partner minus bereits zugewiesene Jobs)
$activePartners = (int) val("SELECT COUNT(*) FROM employee WHERE status=1") ?: 1;

// Jobs im Fenster
$to = date('Y-m-d', strtotime("$from +$days days"));
$jobs = all("SELECT j.j_date, j.j_time, j.j_hours, j.travel_block_until, j.emp_id_fk, c.district AS cust_district
             FROM jobs j LEFT JOIN customer c ON j.customer_id_fk=c.customer_id
             WHERE j.j_date BETWEEN ? AND ? AND j.status=1
             AND (j.job_status IS NULL OR UPPER(j.job_status) NOT IN ('CANCELLED','REJECTED','DELETED'))", [$from, $to]);

// Index jobs by date
$jobsByDate = [];
foreach ($jobs as $j) $jobsByDate[$j['j_date']][] = $j;

$today = date('Y-m-d');
$nowH  = (float) date('H') + (float) date('i')/60;

// Admin-Sperren Map
$blockedMap = [];
try {
    $email = strtolower(trim($_GET['email'] ?? ''));
    $isPremium = 0; $cid = 0;
    if ($email) {
        $c = one("SELECT customer_id, is_premium FROM customer WHERE LOWER(email)=? LIMIT 1", [$email]);
        if ($c) { $cid = (int)$c['customer_id']; $isPremium = (int)$c['is_premium']; }
    }
    $pbTokenReq = trim($_GET['prebook_token'] ?? $_GET['voucher_code'] ?? '');
    $blocks = all("SELECT date_from, date_to, applies_to, customer_id_fk, prebook_token, weekday_mask FROM admin_blocked_days WHERE date_to >= ? AND date_from <= ?", [$from, $to]);
    foreach ($blocks as $bl) {
        if (!empty($bl['prebook_token']) && $bl['prebook_token'] !== $pbTokenReq) continue;
        $allowedDays = !empty($bl['weekday_mask']) ? array_map('intval', explode(',', $bl['weekday_mask'])) : null;
        $d = $bl['date_from'];
        while ($d <= $bl['date_to']) {
            $wdIso = (int) date('N', strtotime($d));
            if ($allowedDays !== null && !in_array($wdIso, $allowedDays, true)) { $d = date('Y-m-d', strtotime("$d +1 day")); continue; }
            $matchCustomer = $bl['customer_id_fk'] && (int)$bl['customer_id_fk'] === $cid;
            $matchRole = false;
            if ($bl['applies_to'] === 'all') $matchRole = true;
            elseif ($bl['applies_to'] === 'premium_only' && $isPremium) $matchRole = true;
            elseif ($bl['applies_to'] === 'non_premium' && !$isPremium) $matchRole = true;
            elseif ($bl['applies_to'] === 'prebook_only' && $pbTokenReq) $matchRole = true;
            $match = $bl['customer_id_fk'] ? $matchCustomer : $matchRole;
            if ($match) $blockedMap[$d] = true;
            $d = date('Y-m-d', strtotime("$d +1 day"));
        }
    }
} catch (Exception $e) {}

$out = [];
for ($i = 0; $i < $days; $i++) {
    $d = date('Y-m-d', strtotime("$from +$i days"));
    $dayJobs = $jobsByDate[$d] ?? [];

    // Past?
    if ($d < $today) { $out[] = ['date'=>$d, 'status'=>'past', 'slots_free'=>0, 'slots_total'=>$totalSlots]; continue; }

    // Admin-Blocked?
    if (!empty($blockedMap[$d])) { $out[] = ['date'=>$d, 'status'=>'busy', 'slots_free'=>0, 'slots_total'=>$totalSlots, 'admin_blocked'=>true]; continue; }

    // Busy-Ranges berechnen
    $busy = [];
    $premiumBlock = false;
    foreach ($dayJobs as $j) {
        $sh = (float) substr($j['j_time'],0,2) + (float) substr($j['j_time'],3,2)/60;
        $eh = $sh + (float)$j['j_hours'];
        if (!empty($j['travel_block_until'])) {
            $eh = max($eh, (float) substr($j['travel_block_until'],0,2) + (float) substr($j['travel_block_until'],3,2)/60);
            $sh = min($sh, 0);
            $premiumBlock = true;
        }
        $busy[] = [$sh, $eh];
    }

    // Slots zählen
    $free = 0;
    for ($h = $startHour; $h <= $endHour - $duration; $h++) {
        $ss = $h; $se = $h + $duration;
        if ($d === $today && $ss < $nowH + 1) continue;
        $conflict = false;
        foreach ($busy as [$bs, $be]) {
            if ($ss < $be && $se > $bs) { $conflict = true; break; }
        }
        if ($voucherBlockH !== null && $se > $voucherBlockH) continue; // Premium-Buchung → nur Morgen-Slots
        if (!$conflict) $free++;
    }

    // Bezirks-Bonus: wenn Kunde einen Bezirk hat, und an diesem Tag bereits ein anderer Kunde im GLEICHEN Bezirk gebucht ist → Route-Effizienz → als "frei" markieren
    $districtBonus = 0;
    if ($district) {
        foreach ($dayJobs as $j) if (($j['cust_district'] ?? '') === $district) { $districtBonus++; break; }
    }

    // Strenger-Check: Kern-Arbeitsfenster 08:00-15:00 (7h) MUSS komplett frei sein
    // damit ein Partner den Job übernehmen kann.
    $coreStart = 8.0; $coreEnd = 15.0;
    $coreOverlap = false;
    foreach ($busy as [$bs, $be]) {
        if ($bs < $coreEnd && $be > $coreStart) { $coreOverlap = true; break; }
    }

    // Partner-Verfügbarkeit: Partner-Kapazität = activePartners × 8h Arbeitszeit pro Tag
    // Dividierend durch ∑ Arbeitsstunden der Jobs heute
    $jobHoursToday = 0.0;
    foreach ($dayJobs as $j) $jobHoursToday += (float)$j['j_hours'];
    $partnerCapacityHours = $activePartners * 8.0;
    $partnerFree = $jobHoursToday < $partnerCapacityHours;                   // genug Kapazität für weitere Jobs?
    $partnerComfortable = $jobHoursToday < ($partnerCapacityHours * 0.7);    // komfortabel (unter 70% Auslastung)

    // Status-Mapping — Fleckfrei-Regel:
    // GREEN = 08-15 Kern frei + Partner komfortabel verfügbar
    // AMBER = Partner voll belegt im Kern ODER Rand-Zeiten frei aber Kern belegt
    // GRAY  = keine Partner-Kapazität mehr
    if ($premiumBlock && $voucherBlockH === null) $status = 'busy';
    elseif (!$partnerFree || $free <= 0) $status = 'busy';
    elseif (!$coreOverlap && $partnerComfortable) $status = 'free';    // Kern frei + entspannte Auslastung
    else $status = 'limited';                                           // machbar aber knapp

    // Wochenende markieren
    $wd = (int) date('N', strtotime($d)); // 1=Mo..7=So
    $isWeekend = $wd >= 6;

    $out[] = [
        'date'           => $d,
        'status'         => $status,
        'slots_free'     => $free,
        'slots_total'    => $totalSlots,
        'weekend'        => $isWeekend,
        'district_bonus' => $districtBonus,
        'has_jobs'       => count($dayJobs),
    ];
}

echo json_encode([
    'from'     => $from,
    'days'     => $days,
    'district' => $district,
    'voucher'  => $voucher,
    'summary'  => [
        'free'    => count(array_filter($out, fn($x) => $x['status']==='free')),
        'limited' => count(array_filter($out, fn($x) => $x['status']==='limited')),
        'busy'    => count(array_filter($out, fn($x) => $x['status']==='busy')),
    ],
    'days_data' => $out,
], JSON_UNESCAPED_UNICODE);
