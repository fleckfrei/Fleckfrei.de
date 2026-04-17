<?php
header('Content-Type: application/json');
$allowedOrigins = ['https://app.fleckfrei.de', 'https://fleckfrei.de', 'https://app.la-renting.de'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins)) { header('Access-Control-Allow-Origin: ' . $origin); }
elseif (php_sapi_name() === 'cli' || ($_SERVER['HTTP_X_API_KEY'] ?? '') === (defined('API_KEY') ? API_KEY : '')) { header('Access-Control-Allow-Origin: *'); }
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/email.php';
require_once __DIR__ . '/../includes/openbanking.php';

// Smoobu webhook — no auth needed (before auth check)
$action = $_GET['action'] ?? '';
if ($action === 'smoobu/webhook' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);
    if ($payload && !empty($payload['action'])) {
        $booking = $payload['data'] ?? $payload;
        $smoobuId = (int)($booking['id'] ?? 0);
        $guestName = $booking['guest-name'] ?? $booking['guestName'] ?? '';
        $arrival = $booking['arrival'] ?? null;
        $departure = $booking['departure'] ?? null;
        $channel = $booking['channel']['name'] ?? $booking['channel'] ?? 'direct';
        $apartment = $booking['apartment']['name'] ?? $booking['apartmentName'] ?? '';
        $apartmentId = (int)($booking['apartment']['id'] ?? $booking['apartmentId'] ?? 0);
        $price = (float)($booking['price'] ?? 0);
        $email = $booking['email'] ?? '';
        $phone = $booking['phone'] ?? '';
        $adults = (int)($booking['adults'] ?? 1);
        $children = (int)($booking['children'] ?? 0);
        $notice = $booking['notice'] ?? '';

        if ($smoobuId && $arrival) {
            global $dbLocal;
            $existing = $dbLocal->prepare("SELECT cb_id FROM channel_bookings WHERE smoobu_id=?");
            $existing->execute([$smoobuId]);
            $ex = $existing->fetch();

            if ($payload['action'] === 'delete' || ($booking['status'] ?? '') === 'cancelled') {
                if ($ex) { $dbLocal->prepare("UPDATE channel_bookings SET status='cancelled', synced_at=NOW() WHERE smoobu_id=?")->execute([$smoobuId]); }
            } elseif ($ex) {
                $dbLocal->prepare("UPDATE channel_bookings SET guest_name=?, guest_email=?, guest_phone=?, property_name=?, property_id=?, channel=?, check_in=?, check_out=?, adults=?, children=?, price=?, notes=?, status='confirmed', synced_at=NOW() WHERE smoobu_id=?")
                    ->execute([$guestName, $email, $phone, $apartment, $apartmentId, $channel, $arrival, $departure, $adults, $children, $price, $notice, $smoobuId]);
            } else {
                $dbLocal->prepare("INSERT INTO channel_bookings (smoobu_id, guest_name, guest_email, guest_phone, property_name, property_id, channel, check_in, check_out, adults, children, price, notes, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'confirmed')")
                    ->execute([$smoobuId, $guestName, $email, $phone, $apartment, $apartmentId, $channel, $arrival, $departure, $adults, $children, $price, $notice]);
            }
            telegramNotify("🏠 <b>Smoobu " . ucfirst($payload['action']) . "</b>\n\n👤 $guestName\n🏨 $apartment ($channel)\n📅 $arrival → $departure\n💶 $price €");
        }
    }
    echo json_encode(['success' => true]);
    exit;
}

// Stripe webhook — no auth needed (before auth check)
if ($action === 'stripe/webhook' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = file_get_contents('php://input');
    // Verify Stripe signature
    if (defined('STRIPE_WEBHOOK_SECRET') && !empty($_SERVER['HTTP_STRIPE_SIGNATURE'])) {
        $sig = $_SERVER['HTTP_STRIPE_SIGNATURE'];
        $parts = []; foreach (explode(',', $sig) as $p) { $kv = explode('=', $p, 2); $parts[$kv[0]] = $kv[1] ?? ''; }
        $expected = hash_hmac('sha256', ($parts['t'] ?? '') . '.' . $payload, STRIPE_WEBHOOK_SECRET);
        if (!hash_equals($expected, $parts['v1'] ?? '')) { http_response_code(400); echo json_encode(['error'=>'Invalid signature']); exit; }
    }
    $event = json_decode($payload, true);
    if ($event && ($event['type'] ?? '') === 'checkout.session.completed') {
        $session = $event['data']['object'] ?? [];
        $invId = (int)($session['metadata']['inv_id'] ?? 0);
        if ($invId) {
            $amount = ($session['amount_total'] ?? 0) / 100;
            $inv = one("SELECT * FROM invoices WHERE inv_id=?", [$invId]);
            if ($inv) {
                $newRemaining = max(0, $inv['remaining_price'] - $amount);
                $paid = $newRemaining <= 0 ? 'yes' : 'no';
                q("UPDATE invoices SET remaining_price=?, invoice_paid=? WHERE inv_id=?", [$newRemaining, $paid, $invId]);
                try { q("INSERT INTO invoice_payments (invoice_id_fk, amount, payment_date, payment_method, note) VALUES (?,?,?,?,?)",
                    [$invId, $amount, date('Y-m-d'), 'Stripe', 'Online: ' . ($session['payment_intent'] ?? '')]); } catch (Exception $e) {}
                audit('stripe_paid', 'invoice', $invId, "Stripe: $amount EUR");
                telegramNotify("Zahlung eingegangen! Rechnung #{$inv['invoice_number']}: " . money($amount) . " via Stripe");
            }
        }
    }
    echo json_encode(['success' => true]);
    exit;
}

// Auth: session OR API key
session_start();
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['key'] ?? '';
if (empty($_SESSION['uid']) && $apiKey !== API_KEY) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = trim($_GET['action'] ?? '', '/');
$body = json_decode(file_get_contents('php://input'), true) ?? [];

try {
    $result = match(true) {
        // Customer services (own + used in jobs)
        $action === 'customer/services' && $method === 'GET' => (function() {
            $cid = (int)($_GET['customer_id'] ?? 0);
            if (!$cid) throw new Exception('Need customer_id');
            // Get services assigned directly to customer + services used in their jobs
            return all("SELECT DISTINCT s.s_id, s.title, s.street, s.number, s.postal_code, s.city, s.country, s.price, s.total_price, s.is_address
                FROM services s
                WHERE s.status=1 AND (
                    s.customer_id_fk = ?
                    OR s.s_id IN (SELECT DISTINCT s_id_fk FROM jobs WHERE customer_id_fk = ? AND status=1)
                )
                ORDER BY s.title", [$cid, $cid]);
        })(),

        // Stats
        $action === 'stats' => [
            'customers' => val("SELECT COUNT(*) FROM customer WHERE status=1"),
            'employees' => val("SELECT COUNT(*) FROM employee WHERE status=1"),
            'services' => val("SELECT COUNT(*) FROM services WHERE status=1"),
            'jobs_today' => val("SELECT COUNT(*) FROM jobs WHERE j_date=? AND status=1", [date('Y-m-d')]),
            'jobs_pending' => val("SELECT COUNT(*) FROM jobs WHERE job_status='PENDING' AND status=1"),
            'total_jobs' => val("SELECT COUNT(*) FROM jobs"),
        ],

        // Jobs list (for calendar)
        $action === 'jobs' && $method === 'GET' => (function() {
            $start = $_GET['start'] ?? date('Y-m-01');
            $end = $_GET['end'] ?? date('Y-m-t');
            $sql = "SELECT j.j_id, j.j_date, j.j_time, j.j_hours, j.job_status, j.job_for,
                    j.customer_id_fk, j.s_id_fk, j.emp_id_fk, j.address, j.platform, j.total_hours, j.code_door, j.job_note,
                    j.start_time, j.end_time, j.start_location, j.end_location, j.cancel_date,
                    c.name as customer_name, c.customer_type,
                    e.name as emp_name, e.surname as emp_surname,
                    s.title as service_title
                    FROM jobs j
                    LEFT JOIN customer c ON j.customer_id_fk=c.customer_id
                    LEFT JOIN employee e ON j.emp_id_fk=e.emp_id
                    LEFT JOIN services s ON j.s_id_fk=s.s_id
                    WHERE j.j_date BETWEEN ? AND ? AND j.status=1";
            $p = [$start, $end];
            if (!empty($_GET['emp_id'])) { $sql .= " AND j.emp_id_fk=?"; $p[] = $_GET['emp_id']; }
            if (!empty($_GET['status'])) { $sql .= " AND j.job_status=?"; $p[] = $_GET['status']; }
            $sql .= " ORDER BY j.j_date, j.j_time";
            return all($sql, $p);
        })(),

        // Distance / partners-in-range check: given customer lat+lng, return nearest partner
        // and distance (km). Used by customer booking to block out-of-area submissions.
        $action === 'partners-in-range' && $method === 'GET' => (function() {
            $lat = (float)($_GET['lat'] ?? 0);
            $lng = (float)($_GET['lng'] ?? 0);
            if ($lat === 0.0 || $lng === 0.0) throw new Exception('Need lat+lng');
            $maxKm = (int) (val("SELECT max_distance_km FROM settings LIMIT 1") ?: 30);
            // Haversine in SQL: 6371 km * acos(...)
            $rows = all("
                SELECT e.emp_id, e.name AS ename, e.surname,
                       ea.lat, ea.lng,
                       (6371 * acos(
                         cos(radians(?)) * cos(radians(ea.lat)) *
                         cos(radians(ea.lng) - radians(?)) +
                         sin(radians(?)) * sin(radians(ea.lat))
                       )) AS distance_km
                FROM employee e
                JOIN employee_address ea ON ea.emp_id_fk = e.emp_id
                WHERE e.status = 1
                  AND ea.lat IS NOT NULL AND ea.lng IS NOT NULL
                HAVING distance_km <= ?
                ORDER BY distance_km ASC
            ", [$lat, $lng, $lat, $maxKm]);
            return [
                'max_distance_km' => $maxKm,
                'partners_in_range' => count($rows),
                'nearest_km' => $rows ? round((float)$rows[0]['distance_km'], 1) : null,
                'partners' => array_map(fn($r) => [
                    'emp_id' => (int)$r['emp_id'],
                    'name' => trim(($r['ename'] ?? '') . ' ' . ($r['surname'] ?? '')),
                    'distance_km' => round((float)$r['distance_km'], 1),
                ], $rows),
            ];
        })(),

        // Customer vacations in date range (for calendar overlay)
        $action === 'vacations' && $method === 'GET' => (function() {
            $start = $_GET['start'] ?? date('Y-m-01');
            $end   = $_GET['end']   ?? date('Y-m-t');
            return all("
                SELECT cv.cv_id, cv.customer_id_fk, cv.from_date, cv.to_date,
                       cv.reason, cv.auto_skip_jobs,
                       c.name AS customer_name, c.customer_type
                FROM customer_vacations cv
                LEFT JOIN customer c ON cv.customer_id_fk = c.customer_id
                WHERE cv.status = 'active'
                  AND cv.to_date   >= ?
                  AND cv.from_date <= ?
                ORDER BY cv.from_date
            ", [$start, $end]);
        })(),

        // Create job (with recurring support)
        $action === 'jobs' && $method === 'POST' => (function() use ($body) {
            $d = $body;
            foreach (['customer_id_fk','j_date','j_time','j_hours'] as $r) { if (empty($d[$r])) throw new Exception("Missing: $r"); }
            global $db;
            $jobFor = $d['job_for'] ?? '';
            $recurGroup = $jobFor ? 'rec_' . bin2hex(random_bytes(8)) : null;

            // Calculate dates: single or recurring until user-chosen end date
            $dates = [$d['j_date']];
            if ($jobFor) {
                $intervalDays = match($jobFor) {
                    'daily' => 1, 'weekly' => 7, 'weekly2' => 14, 'weekly3' => 21, 'weekly4' => 28, default => 0
                };
                if ($intervalDays > 0) {
                    $endDate = $d['recur_end'] ?? date('Y-12-31');
                    $cur = new DateTime($d['j_date']);
                    $end = new DateTime($endDate);
                    $cur->modify("+{$intervalDays} days");
                    while ($cur <= $end) {
                        $dates[] = $cur->format('Y-m-d');
                        $cur->modify("+{$intervalDays} days");
                    }
                }
            }

            // Insert all jobs
            $ids = [];
            foreach ($dates as $date) {
                q("INSERT INTO jobs (customer_id_fk, s_id_fk, j_date, j_time, j_hours, job_for, address, emp_id_fk, no_people, code_door, optional_products, emp_message, status, job_status, platform, recurring_group)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,'PENDING',?,?)",
                  [$d['customer_id_fk'], $d['s_id_fk']??0, $date, $d['j_time'], $d['j_hours'],
                   $jobFor, $d['address']??'', $d['emp_id_fk']??null, $d['no_people']??1,
                   $d['code_door']??'', $d['optional_products']??'', $d['emp_message']??'', $d['platform']??'admin', $recurGroup]);
                $ids[] = $db->lastInsertId();
            }
            // Telegram notification
            $cust = one("SELECT name FROM customer WHERE customer_id=?", [$d['customer_id_fk']]);
            $svc = one("SELECT title FROM services WHERE s_id=?", [$d['s_id_fk']??0]);
            $custName = $cust['name'] ?? 'Unbekannt';
            $svcTitle = $svc['title'] ?? '';
            $msg = "📋 <b>Neuer Job</b>\n\n👤 {$custName}\n🏠 {$svcTitle}\n📅 {$d['j_date']} um {$d['j_time']}\n⏱ {$d['j_hours']}h";
            if (count($ids) > 1) $msg .= "\n🔄 " . count($ids) . " Jobs erstellt (bis " . end($dates) . ")";
            if (!empty($d['address'])) $msg .= "\n📍 {$d['address']}";
            telegramNotify($msg);
            return ['j_id' => $ids[0], 'total_created' => count($ids), 'recurring' => $jobFor ? true : false, 'dates_until' => end($dates)];
        })(),

        // Delete single job (soft delete: status=0)
        $action === 'jobs/delete' && $method === 'POST' => (function() use ($body) {
            if (empty($body['j_id'])) throw new Exception('Need j_id');
            q("UPDATE jobs SET status=0 WHERE j_id=?", [(int)$body['j_id']]);
            return ['deleted' => 1, 'j_id' => (int)$body['j_id']];
        })(),

        // Delete jobs in a recurring series (optional date range: from, to)
        $action === 'jobs/delete-recurring' && $method === 'POST' => (function() use ($body) {
            if (empty($body['j_id'])) throw new Exception('Need j_id');
            $job = one("SELECT recurring_group, customer_id_fk, s_id_fk, j_time, job_for FROM jobs WHERE j_id=?", [(int)$body['j_id']]);
            if (!$job) throw new Exception('Job not found');
            $from = $body['from'] ?? null;
            $to = $body['to'] ?? null;
            $count = 0;
            // Build date conditions
            $dateWhere = ''; $dateParams = [];
            if ($from) { $dateWhere .= ' AND j_date >= ?'; $dateParams[] = $from; }
            if ($to) { $dateWhere .= ' AND j_date <= ?'; $dateParams[] = $to; }
            if (!empty($job['recurring_group'])) {
                $r = q("UPDATE jobs SET status=0 WHERE recurring_group=? AND status=1" . $dateWhere,
                    array_merge([$job['recurring_group']], $dateParams));
                $count = $r->rowCount();
            } elseif (!empty($job['job_for'])) {
                $r = q("UPDATE jobs SET status=0 WHERE customer_id_fk=? AND s_id_fk=? AND j_time=? AND job_for=? AND status=1" . $dateWhere,
                    array_merge([$job['customer_id_fk'], $job['s_id_fk'], $job['j_time'], $job['job_for']], $dateParams));
                $count = $r->rowCount();
            } else {
                q("UPDATE jobs SET status=0 WHERE j_id=?", [(int)$body['j_id']]);
                $count = 1;
            }
            return ['deleted' => $count];
        })(),

        // Cancel recurring: just this one or all future
        $action === 'jobs/cancel-recurring' && $method === 'POST' => (function() use ($body) {
            if (empty($body['j_id'])) throw new Exception('Need j_id');
            $body['j_id'] = (int)$body['j_id'];
            $job = one("SELECT * FROM jobs WHERE j_id=?", [$body['j_id']]);
            if (!$job) throw new Exception('Job not found');
            $mode = $body['mode'] ?? 'single'; // 'single' or 'future'
            $cancelled = 0;
            if ($mode === 'future' && $job['recurring_group']) {
                // Cancel all future jobs in this recurring group
                $result = q("UPDATE jobs SET job_status='CANCELLED', cancel_date=NOW() WHERE recurring_group=? AND j_date>=? AND status=1 AND job_status!='CANCELLED'",
                    [$job['recurring_group'], $job['j_date']]);
                $cancelled = $result->rowCount();
            } elseif ($mode === 'future' && $job['job_for']) {
                // Legacy: no recurring_group but has job_for — cancel matching pattern
                $result = q("UPDATE jobs SET job_status='CANCELLED', cancel_date=NOW() WHERE customer_id_fk=? AND s_id_fk=? AND j_time=? AND job_for=? AND j_date>=? AND status=1 AND job_status!='CANCELLED'",
                    [$job['customer_id_fk'], $job['s_id_fk'], $job['j_time'], $job['job_for'], $job['j_date']]);
                $cancelled = $result->rowCount();
            } else {
                // Single cancel
                q("UPDATE jobs SET job_status='CANCELLED', cancel_date=NOW() WHERE j_id=?", [$body['j_id']]);
                $cancelled = 1;
            }
            return ['cancelled' => $cancelled, 'mode' => $mode];
        })(),

        // Update single job field
        $action === 'jobs/update' && $method === 'POST' => (function() use ($body) {
            $jid = (int)($body['j_id'] ?? 0);
            if (!$jid || empty($body['field'])) throw new Exception('Need j_id + field');
            $allowed = ['j_date','j_time','stop_times','check_in_date','check_in_time','j_hours','customer_id_fk','s_id_fk','address','code_door','platform','job_for','emp_message','job_note','job_status','no_people','optional_products','start_time','end_time','total_hours'];
            if (!in_array($body['field'], $allowed)) throw new Exception('Field not editable: '.$body['field']);
            $val = $body['value'] ?: null;
            q("UPDATE jobs SET {$body['field']}=? WHERE j_id=?", [$val, $jid]);
            // Telegram notification for job changes
            $job = one("SELECT j.*, c.name as cname, s.title as stitle FROM jobs j LEFT JOIN customer c ON j.customer_id_fk=c.customer_id LEFT JOIN services s ON j.s_id_fk=s.s_id WHERE j.j_id=?", [$jid]);
            if ($job) {
                $fieldLabels = ['j_date'=>'Datum','j_time'=>'Uhrzeit','j_hours'=>'Stunden','address'=>'Adresse','job_status'=>'Status','emp_id_fk'=>'Partner','code_door'=>'Türcode','job_note'=>'Notiz'];
                $label = $fieldLabels[$body['field']] ?? $body['field'];
                telegramNotify("✏️ <b>Job aktualisiert</b>\n\n📋 #{$jid} — {$job['cname']}\n🏠 {$job['stitle']}\n📅 {$job['j_date']} {$job['j_time']}\n\n🔄 {$label}: {$val}");
            }
            // Granulare Benachrichtigung
            $actorType = $_SESSION['utype'] ?? 'admin';
            $actorId = (int)($_SESSION['uid'] ?? 0);
            $humanMsg = "Job #{$jid} — {$body['field']} geändert zu: " . substr((string)$val, 0, 80);
            notifyEvent($actorType, $actorId, 'job_edited', 'jobs', $jid, $humanMsg, ['field' => $body['field'], 'value' => $val]);
            return ['updated' => $jid, 'field' => $body['field']];
        })(),

        // Assign employee (or un-assign with null)
        $action === 'jobs/assign' => (function() use ($body) {
            if (empty($body['j_id'])) throw new Exception('Need j_id');
            $body['j_id'] = (int)$body['j_id'];
            $empId = $body['emp_id_fk'] ?? null;
            if ($empId) {
                q("UPDATE jobs SET emp_id_fk=?, job_status=CASE WHEN job_status='PENDING' THEN 'CONFIRMED' ELSE job_status END WHERE j_id=?", [$empId, $body['j_id']]);
            } else {
                q("UPDATE jobs SET emp_id_fk=NULL WHERE j_id=?", [$body['j_id']]);
            }
            // Notify on assignment
            $job = one("SELECT j.*, c.name as cname, e.name as ename, e.surname as esurname, s.title as stitle FROM jobs j LEFT JOIN customer c ON j.customer_id_fk=c.customer_id LEFT JOIN employee e ON j.emp_id_fk=e.emp_id LEFT JOIN services s ON j.s_id_fk=s.s_id WHERE j.j_id=?", [$body['j_id']]);
            if ($job && $empId) {
                telegramNotify("👤 <b>Partner zugewiesen</b>\n\n📋 #{$body['j_id']} — {$job['cname']}\n🏠 {$job['stitle']}\n📅 {$job['j_date']} {$job['j_time']}\n👷 {$job['ename']} {$job['esurname']}");
            }
            return ['assigned' => $body['j_id'], 'emp_id' => $empId];
        })(),

        // Update job status + n8n webhook notification
        $action === 'jobs/status' => (function() use ($body) {
            if (empty($body['j_id']) || empty($body['status'])) throw new Exception('Need j_id + status');
            $body['j_id'] = (int)$body['j_id'];
            $valid = ['PENDING','CONFIRMED','RUNNING','STARTED','COMPLETED','CANCELLED'];
            if (!in_array($body['status'], $valid)) throw new Exception('Invalid status');
            $extra = ''; $p = [$body['status']];
            if ($body['status']==='RUNNING') { $extra=", start_time=?, start_location=?"; $p[]=date('H:i:s'); $p[]=$body['location']??''; }
            if ($body['status']==='COMPLETED') { $extra=", end_time=?, end_location=?"; $p[]=date('H:i:s'); $p[]=$body['location']??''; }
            if ($body['status']==='CANCELLED') { $extra=", cancel_date=?"; $p[]=date('Y-m-d H:i:s'); }
            $p[] = $body['j_id'];
            q("UPDATE jobs SET job_status=?$extra WHERE j_id=?", $p);

            // n8n webhook notification (fire & forget)
            $job = one("SELECT j.*, c.name as cname, c.email as cemail, c.phone as cphone, e.name as ename, s.title as stitle
                FROM jobs j LEFT JOIN customer c ON j.customer_id_fk=c.customer_id LEFT JOIN employee e ON j.emp_id_fk=e.emp_id LEFT JOIN services s ON j.s_id_fk=s.s_id
                WHERE j.j_id=?", [$body['j_id']]);
            if ($job) {
                $webhook = 'https://n8n.la-renting.com/webhook/fleckfrei-v2-job-status';
                $payload = json_encode([
                    'event' => 'status_change',
                    'job_id' => $body['j_id'],
                    'old_status' => $body['old_status'] ?? '',
                    'new_status' => $body['status'],
                    'job_date' => $job['j_date'],
                    'job_time' => $job['j_time'],
                    'customer_name' => $job['cname'],
                    'customer_email' => $job['cemail'],
                    'customer_phone' => $job['cphone'],
                    'employee_name' => $job['ename'],
                    'service' => $job['stitle'],
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
                @file_get_contents($webhook, false, stream_context_create([
                    'http' => ['method'=>'POST', 'header'=>"Content-Type: application/json\r\n", 'content'=>$payload, 'timeout'=>3]
                ]));
            }

            // Email notifications based on status
            if ($body['status'] === 'RUNNING') notifyJobStarted($body['j_id']);
            if ($body['status'] === 'COMPLETED') {
                // B: Auto-mark all checklist items as completed (if not yet)
                $jobInfo = one("SELECT s_id_fk FROM jobs WHERE j_id=?", [(int)$body['j_id']]);
                if ($jobInfo) {
                    $unchecked = all("SELECT cl.checklist_id FROM service_checklists cl
                        LEFT JOIN checklist_completions cc ON cc.checklist_id_fk=cl.checklist_id AND cc.job_id_fk=?
                        WHERE cl.s_id_fk=? AND cl.is_active=1 AND (cc.completed IS NULL OR cc.completed=0)",
                        [(int)$body['j_id'], $jobInfo['s_id_fk']]);
                    foreach ($unchecked as $u) {
                        q("INSERT INTO checklist_completions (job_id_fk, checklist_id_fk, completed, auto_completed, completed_at)
                            VALUES (?,?,1,1,NOW()) ON DUPLICATE KEY UPDATE completed=1, auto_completed=1, completed_at=NOW()",
                          [(int)$body['j_id'], (int)$u['checklist_id']]);
                    }
                }
                notifyJobCompleted($body['j_id']);
            }

            // Auto-Invoicing: Job erledigt → Rechnung sofort erstellen
            // BUG-FIX 2026-04-17: per-job invoices create one invoice per stop.
            // Customers expect ONE invoice per month aggregating all their jobs.
            // Gate on FEATURE_AUTO_INVOICE_PER_JOB (default false). Monthly
            // aggregation is done via POST /api/invoice/generate with {customer_id, month}.
            $autoInvoiceId = null;
            if ($body['status'] === 'COMPLETED' && $job && defined('FEATURE_AUTO_INVOICE_PER_JOB') && FEATURE_AUTO_INVOICE_PER_JOB) {
                try {
                    // Only auto-invoice if job has no invoice yet
                    $hasInvoice = one("SELECT invoice_id FROM jobs WHERE j_id=? AND invoice_id IS NOT NULL", [$body['j_id']]);
                    if (!$hasInvoice) {
                        global $db;
                        $svc = one("SELECT price, total_price FROM services WHERE s_id=?", [$job['s_id_fk'] ?? 0]);
                        $hours = max(MIN_HOURS, ($job['total_hours'] ?? 0) ?: ($job['j_hours'] ?? 2));
                        $nettoRate = (float)($svc['price'] ?? 0);
                        $netto = round($nettoRate * $hours, 2);
                        if ($netto > 0) {
                            $tax = round($netto * TAX_RATE, 2);
                            $brutto = round($netto + $tax, 2);
                            // Sequential invoice number
                            $lastNum = val("SELECT invoice_number FROM invoices WHERE invoice_number LIKE 'FF-%' ORDER BY inv_id DESC LIMIT 1");
                            $nextSeq = 1;
                            if ($lastNum && preg_match('/FF-(\d+)$/', $lastNum, $m)) { $nextSeq = (int)$m[1] + 1; }
                            else { $nextSeq = (int)val("SELECT COUNT(*)+1 FROM invoices"); }
                            $invNum = 'FF-' . str_pad($nextSeq, 4, '0', STR_PAD_LEFT);
                            q("INSERT INTO invoices (customer_id_fk, invoice_number, issue_date, price, tax, total_price, remaining_price, invoice_paid, start_date, end_date)
                               VALUES (?,?,?,?,?,?,?,'no',?,?)",
                              [$job['customer_id_fk'], $invNum, date('Y-m-d'), $netto, $tax, $brutto, $brutto, $job['j_date'], $job['j_date']]);
                            $autoInvoiceId = $db->lastInsertId();
                            q("UPDATE jobs SET invoice_id=? WHERE j_id=?", [$autoInvoiceId, $body['j_id']]);
                            audit('auto_create', 'invoice', $autoInvoiceId, "Auto: $invNum, $brutto €, Job #{$body['j_id']}");
                            notifyInvoiceCreated($autoInvoiceId);
                            telegramNotify("💰 <b>Auto-Rechnung</b>\n\n📄 $invNum\n👤 {$job['cname']}\n🏠 {$job['stitle']}\n💶 " . number_format($brutto,2,',','.') . " €\n⏱ {$hours}h × " . number_format($nettoRate,2,',','.') . " €/h");
                        }
                    }
                } catch (Exception $e) {
                    // Auto-invoice failed — log but don't block status change
                    audit('error', 'auto_invoice', $body['j_id'], 'Auto-invoice failed: ' . $e->getMessage());
                }
            }

            audit('status_change', 'job', $body['j_id'], 'Status: '.$body['status']);
            // Granulare Benachrichtigung
            $actorType = $_SESSION['utype'] ?? 'admin';
            $actorId = (int)($_SESSION['uid'] ?? 0);
            $eventMap = ['COMPLETED'=>'job_completed','CANCELLED'=>'job_cancelled','RUNNING'=>'job_started','CONFIRMED'=>'job_confirmed','PENDING'=>'job_reopened'];
            $eventType = $eventMap[$body['status']] ?? 'job_status_change';
            $humanMsg = "Job #{$body['j_id']}" . (isset($job['cname']) ? " (Kunde: {$job['cname']})" : '') . " → {$body['status']}";
            notifyEvent($actorType, $actorId, $eventType, 'jobs', (int)$body['j_id'], $humanMsg, ['new_status' => $body['status'], 'old_status' => $body['old_status'] ?? '']);
            return ['j_id'=>$body['j_id'], 'new_status'=>$body['status'], 'auto_invoice_id'=>$autoInvoiceId];
        })(),

        // Auto-generate invoice from completed jobs
        $action === 'invoice/generate' && $method === 'POST' => (function() use ($body) {
            global $db;
            $custId = $body['customer_id'] ?? null;
            $month = $body['month'] ?? date('Y-m'); // e.g. "2026-04"
            if (!$custId) throw new Exception('Need customer_id');

            // Get completed jobs without invoice for this customer in this month
            $jobs = all("SELECT j.*, s.price as sprice FROM jobs j LEFT JOIN services s ON j.s_id_fk=s.s_id
                WHERE j.customer_id_fk=? AND j.job_status='COMPLETED' AND j.status=1 AND j.j_date LIKE ? AND j.invoice_id IS NULL",
                [$custId, "$month%"]);
            if (empty($jobs)) throw new Exception('Keine unbefakturierten Jobs für diesen Monat');

            // Calculate total (min 2h rule)
            $totalPrice = 0; $totalHours = 0; $lines = [];
            foreach ($jobs as $j) {
                $realH = $j['total_hours'] ?: $j['j_hours'];
                $custH = max(2, $realH);
                $price = $j['sprice'] ?: 0;
                $lineTotal = round($custH * $price, 2);
                $totalPrice += $lineTotal;
                $totalHours += $custH;
                $lines[] = ['job_id'=>$j['j_id'], 'date'=>$j['j_date'], 'hours'=>$custH, 'price'=>$price, 'total'=>$lineTotal];
            }

            // Get customer info for invoice
            $cust = one("SELECT * FROM customer WHERE customer_id=?", [$custId]);
            $invNum = 'FF-' . date('Ym', strtotime("$month-01")) . '-' . $custId;

            // Calculate tax
            $tax = round($totalPrice * TAX_RATE, 2);
            $brutto = round($totalPrice + $tax, 2);

            // Determine period from job dates
            $startDate = min(array_column($jobs, 'j_date'));
            $endDate = max(array_column($jobs, 'j_date'));

            // Create invoice with full details
            q("INSERT INTO invoices (customer_id_fk, invoice_number, issue_date, price, tax, total_price, remaining_price, invoice_paid, start_date, end_date)
               VALUES (?,?,?,?,?,?,?,'no',?,?)",
              [$custId, $invNum, date('Y-m-d'), $totalPrice, $tax, $brutto, $brutto, $startDate, $endDate]);
            $invId = $db->lastInsertId();
            audit('create', 'invoice', $invId, "Auto-Gen: $invNum, $brutto €, " . count($jobs) . " Jobs");

            // Link jobs to invoice
            foreach ($jobs as $j) {
                q("UPDATE jobs SET invoice_id=? WHERE j_id=?", [$invId, $j['j_id']]);
            }

            // Email + Telegram notification
            notifyInvoiceCreated($invId);
            $custName = $cust['name'] ?? '';
            telegramNotify("💰 <b>Rechnung erstellt</b>\n\n📄 $invNum\n👤 $custName\n💶 " . number_format($brutto,2,',','.') . " €\n📋 " . count($jobs) . " Jobs, {$totalHours}h\n📅 $startDate — $endDate");

            return ['invoice_id'=>$invId, 'invoice_number'=>$invNum, 'netto'=>$totalPrice, 'tax'=>$tax, 'total'=>$brutto, 'jobs_count'=>count($jobs), 'hours'=>$totalHours, 'period'=>"$startDate — $endDate"];
        })(),

        // Manual invoice creation
        $action === 'invoice/create' && $method === 'POST' => (function() use ($body) {
            global $db;
            $custId = (int)($body['customer_id'] ?? 0);
            if (!$custId) throw new Exception('Kunde erforderlich');
            $cust = one("SELECT * FROM customer WHERE customer_id=?", [$custId]);
            if (!$cust) throw new Exception('Kunde nicht gefunden');

            $netto = round((float)($body['netto'] ?? 0), 2);
            if ($netto <= 0) throw new Exception('Betrag muss > 0 sein');

            $issueDate = $body['issue_date'] ?? date('Y-m-d');
            $description = $body['description'] ?? '';

            // Generate sequential invoice number
            $lastNum = val("SELECT invoice_number FROM invoices WHERE invoice_number LIKE 'FF-%' ORDER BY inv_id DESC LIMIT 1");
            if ($lastNum && preg_match('/FF-(\d+)$/', $lastNum, $m)) {
                $nextSeq = (int)$m[1] + 1;
            } else {
                $nextSeq = (int)val("SELECT COUNT(*)+1 FROM invoices");
            }
            $invNum = 'FF-' . str_pad($nextSeq, 4, '0', STR_PAD_LEFT);

            $tax = round($netto * TAX_RATE, 2);
            $brutto = round($netto + $tax, 2);

            $startDate = $body['start_date'] ?? $issueDate;
            $endDate = $body['end_date'] ?? $issueDate;

            q("INSERT INTO invoices (customer_id_fk, invoice_number, issue_date, price, tax, total_price, remaining_price, invoice_paid, start_date, end_date)
               VALUES (?,?,?,?,?,?,?,'no',?,?)",
              [$custId, $invNum, $issueDate, $netto, $tax, $brutto, $brutto, $startDate, $endDate]);
            $invId = $db->lastInsertId();
            audit('create', 'invoice', $invId, "Manuell: $invNum, $brutto €" . ($description ? " — $description" : ''));

            $custName = $cust['name'] ?? '';
            telegramNotify("💰 <b>Rechnung manuell erstellt</b>\n\n📄 $invNum\n👤 $custName\n💶 " . number_format($brutto,2,',','.') . " €\n📝 " . ($description ?: 'Keine Beschreibung'));

            return ['invoice_id'=>$invId, 'invoice_number'=>$invNum, 'netto'=>$netto, 'tax'=>$tax, 'total'=>$brutto];
        })(),

        // Fetch customer jobs for invoice creation (unbilled jobs in date range)
        $action === 'invoice/jobs' && $method === 'GET' => (function() {
            $custId = (int)($_GET['customer_id'] ?? 0);
            $start = $_GET['start'] ?? date('Y-m-01');
            $end = $_GET['end'] ?? date('Y-m-t');
            if (!$custId) throw new Exception('Need customer_id');
            $jobs = all("SELECT j.j_id, j.j_date, j.j_time, j.j_hours, j.total_hours, j.job_status, j.invoice_id,
                s.title as service, s.price as netto_rate, s.total_price as brutto_rate,
                e.name as partner
                FROM jobs j
                LEFT JOIN services s ON j.s_id_fk=s.s_id
                LEFT JOIN employee e ON j.emp_id_fk=e.emp_id
                WHERE j.customer_id_fk=? AND j.j_date BETWEEN ? AND ? AND j.status=1 AND j.job_status='COMPLETED'
                ORDER BY j.j_date", [$custId, $start, $end]);
            $lines = [];
            foreach ($jobs as $j) {
                $hrs = max(MIN_HOURS, $j['total_hours'] ?: $j['j_hours']);
                $nettoRate = (float)($j['netto_rate'] ?? 0);
                $bruttoRate = (float)($j['brutto_rate'] ?? 0);
                $lines[] = [
                    'j_id' => $j['j_id'],
                    'date' => $j['j_date'],
                    'service' => $j['service'] ?: 'Service',
                    'hours' => $hrs,
                    'netto_price' => $nettoRate,
                    'brutto_price' => $bruttoRate,
                    'netto_total' => round($nettoRate * $hrs, 2),
                    'partner' => $j['partner'],
                    'invoiced' => !empty($j['invoice_id']),
                ];
            }
            return ['jobs' => $lines, 'count' => count($lines)];
        })(),

        // CSV Export
        $action === 'export/workhours' && $method === 'GET' => (function() {
            $custId = $_GET['customer_id'] ?? '';
            $month = $_GET['month'] ?? date('Y-m');
            $sql = "SELECT j.j_date, j.j_time, j.j_hours, j.total_hours, j.job_status,
                    c.name as cname, e.name as ename, e.surname as esurname, s.title as stitle, s.price as sprice
                    FROM jobs j LEFT JOIN customer c ON j.customer_id_fk=c.customer_id LEFT JOIN employee e ON j.emp_id_fk=e.emp_id LEFT JOIN services s ON j.s_id_fk=s.s_id
                    WHERE j.status=1 AND j.job_status='COMPLETED' AND j.j_date LIKE ?";
            $p = ["$month%"];
            if ($custId) { $sql .= " AND j.customer_id_fk=?"; $p[] = $custId; }
            $sql .= " ORDER BY j.j_date, j.j_time";
            $rows = all($sql, $p);

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=arbeitsstunden-'.$month.'.csv');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Datum','Zeit','Kunde','Service','Partner','Std (real)','Std (Kunde)','€/h','Umsatz'], ';');
            foreach ($rows as $r) {
                $realH = $r['total_hours'] ?: $r['j_hours'];
                $custH = max(2, $realH);
                $price = $r['sprice'] ?: 0;
                fputcsv($out, [
                    date('d.m.Y', strtotime($r['j_date'])), substr($r['j_time'],0,5),
                    $r['cname'], $r['stitle'], $r['ename'].' '.$r['esurname'],
                    round($realH,1), round($custH,1), number_format($price,2,',','.'),
                    number_format($custH*$price,2,',','.')
                ], ';');
            }
            fclose($out);
            exit;
        })(),

        $action === 'export/invoices' && $method === 'GET' => (function() {
            $month = $_GET['month'] ?? '';
            $sql = "SELECT i.*, c.name as cname FROM invoices i LEFT JOIN customer c ON i.customer_id_fk=c.customer_id";
            $p = [];
            if ($month) { $sql .= " WHERE i.issue_date LIKE ?"; $p[] = "$month%"; }
            $sql .= " ORDER BY i.issue_date DESC";
            $rows = all($sql, $p);

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=rechnungen'.($month?'-'.$month:'').'.csv');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Rechnungsnr','Datum','Kunde','Betrag','Bezahlt','Offen','Status'], ';');
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['invoice_number'], $r['issue_date'] ? date('d.m.Y', strtotime($r['issue_date'])) : '',
                    $r['cname'], number_format($r['total_price'],2,',','.'),
                    number_format($r['total_price']-$r['remaining_price'],2,',','.'),
                    number_format($r['remaining_price'],2,',','.'),
                    $r['invoice_paid']==='yes' ? 'Bezahlt' : 'Offen'
                ], ';');
            }
            fclose($out);
            exit;
        })(),

        // Customer details with address (for service form auto-fill)
        $action === 'customer/details' && $method === 'GET' => (function() {
            $cid = $_GET['id'] ?? '';
            if (!$cid) throw new Exception('Need id');
            $c = one("SELECT customer_id, name, surname, email, phone, customer_type FROM customer WHERE customer_id=?", [$cid]);
            if (!$c) throw new Exception('Customer not found');
            $addr = one("SELECT street, number, postal_code, city, country FROM customer_address WHERE customer_id_fk=? ORDER BY ca_id DESC LIMIT 1", [$cid]);
            $c['address'] = $addr ?: ['street'=>'','number'=>'','postal_code'=>'','city'=>'','country'=>'Deutschland'];
            return $c;
        })(),

        // WhatsApp OSINT check
        $action === 'osint/whatsapp' && $method === 'GET' => (function() {
            $phone = preg_replace('/[^0-9]/', '', $_GET['phone'] ?? '');
            if (strlen($phone) < 8) throw new Exception('Invalid phone');
            $result = ['phone' => $phone, 'exists' => null, 'profile_pic' => null, 'status' => null];
            // Try wa.me check (redirects if account exists)
            $ch = curl_init("https://wa.me/$phone");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>8, CURLOPT_FOLLOWLOCATION=>0, CURLOPT_HEADER=>1, CURLOPT_NOBODY=>1, CURLOPT_SSL_VERIFYPEER=>0]);
            $headers = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $result['http_code'] = $code;
            $result['exists'] = ($code >= 200 && $code < 400);
            // Check if phone appears in our DB
            $dbMatch = one("SELECT c.customer_id, c.name, c.email FROM customer c WHERE c.phone LIKE ? LIMIT 1", ['%'.substr($phone,-8).'%']);
            $result['db_match'] = $dbMatch ?: null;
            return $result;
        })(),

        // OSINT scan save
        $action === 'osint/save' && $method === 'POST' => (function() use ($body) {
            global $db;
            $custId = $body['customer_id'] ?? null;
            $scanData = json_encode($body['scan_data'] ?? []);
            $deepData = $body['deep_data'] ? json_encode($body['deep_data']) : null;
            q("INSERT INTO osint_scans (customer_id_fk, scan_name, scan_email, scan_phone, scan_address, scan_data, deep_scan_data, scanned_by)
               VALUES (?,?,?,?,?,?,?,?)",
              [$custId, $body['name']??'', $body['email']??'', $body['phone']??'', $body['address']??'', $scanData, $deepData, me()['id']??null]);
            return ['saved' => $db->lastInsertId()];
        })(),

        // OSINT scan history
        $action === 'osint/history' && $method === 'GET' => (function() {
            $custId = $_GET['customer_id'] ?? '';
            $email = $_GET['email'] ?? '';
            $where = '1=1'; $p = [];
            if ($custId) { $where .= ' AND customer_id_fk=?'; $p[] = $custId; }
            if ($email) { $where .= ' AND scan_email=?'; $p[] = $email; }
            return all("SELECT scan_id, scan_name, scan_email, scan_phone, created_at FROM osint_scans WHERE $where ORDER BY created_at DESC LIMIT 20", $p);
        })(),

        // Customer field update
        $action === 'customer/update' && $method === 'POST' => (function() use ($body) {
            if (empty($body['customer_id']) || empty($body['field'])) throw new Exception('Need customer_id + field');
            $allowed = ['customer_type','name','surname','email','phone','notes'];
            if (!in_array($body['field'], $allowed)) throw new Exception('Field not editable');
            q("UPDATE customer SET {$body['field']}=? WHERE customer_id=?", [$body['value'], $body['customer_id']]);
            return ['updated' => $body['customer_id']];
        })(),

        // Customer status update
        $action === 'customer/status' && $method === 'POST' => (function() use ($body) {
            if (empty($body['customer_id'])) throw new Exception('Need customer_id');
            $status = isset($body['status']) ? (int)$body['status'] : 1;
            q("UPDATE customer SET status=? WHERE customer_id=?", [$status, $body['customer_id']]);
            return ['customer_id' => $body['customer_id'], 'status' => $status];
        })(),

        // Customers
        $action === 'customers' => all("SELECT customer_id, name, surname, email, phone, customer_type, status FROM customer WHERE status=1 ORDER BY name"),

        // Invoice field update
        $action === 'invoice/update' && $method === 'POST' => (function() use ($body) {
            if (empty($body['inv_id']) || empty($body['field'])) throw new Exception('Need inv_id + field');
            $allowed = ['issue_date','invoice_paid','remaining_price','total_price','start_date','end_date','invoice_number','custom_note'];
            if (!in_array($body['field'], $allowed)) throw new Exception('Field not editable');
            $val = $body['value'] ?? null;
            if ($body['field'] === 'invoice_paid' && $val === 'yes') {
                q("UPDATE invoices SET invoice_paid='yes', remaining_price=0 WHERE inv_id=?", [$body['inv_id']]);
            } else {
                q("UPDATE invoices SET {$body['field']}=? WHERE inv_id=?", [$val, $body['inv_id']]);
            }
            audit('update', 'invoice', $body['inv_id'], $body['field'].': '.$val);
            return ['updated' => $body['inv_id']];
        })(),

        // Save custom invoice lines (for manual editing)
        $action === 'invoice/save-lines' && $method === 'POST' => (function() use ($body) {
            $invId = (int)($body['inv_id'] ?? 0);
            if (!$invId) throw new Exception('Need inv_id');
            $lines = $body['lines'] ?? [];
            $note = $body['note'] ?? null;
            $netto = 0;
            foreach ($lines as $l) {
                $netto += round(($l['hours'] ?? 0) * ($l['price'] ?? 0), 2);
            }
            $tax = round($netto * TAX_RATE, 2);
            $brutto = round($netto + $tax, 2);
            q("UPDATE invoices SET custom_lines=?, custom_note=?, price=?, tax=?, total_price=?, remaining_price=? WHERE inv_id=?",
              [json_encode($lines), $note, $netto, $tax, $brutto, $brutto, $invId]);
            audit('update', 'invoice', $invId, "Custom lines saved: $brutto €");
            return ['saved' => $invId, 'netto' => $netto, 'tax' => $tax, 'total' => $brutto];
        })(),

        // XRechnung XML export
        $action === 'invoice/xrechnung' && $method === 'GET' => (function() {
            $invId = (int)($_GET['inv_id'] ?? 0);
            if (!$invId) throw new Exception('Need inv_id');
            $inv = one("SELECT i.*, c.name as cname, c.email as cemail, c.phone as cphone FROM invoices i LEFT JOIN customer c ON i.customer_id_fk=c.customer_id WHERE i.inv_id=?", [$invId]);
            if (!$inv) throw new Exception('Invoice not found');
            $addr = one("SELECT * FROM customer_address WHERE customer_id_fk=? ORDER BY ca_id DESC LIMIT 1", [$inv['customer_id_fk']]);
            try { $settings = one("SELECT * FROM settings LIMIT 1"); } catch (Exception $e) { $settings = []; }
            if (!$settings) $settings = [];
            $customLines = $inv['custom_lines'] ? json_decode($inv['custom_lines'], true) : null;

            // Build line items
            $xmlLines = '';
            if ($customLines) {
                foreach ($customLines as $i => $l) {
                    $lineTotal = round(($l['hours']??0)*($l['price']??0), 2);
                    $lineTax = round($lineTotal * TAX_RATE, 2);
                    $xmlLines .= '<cac:InvoiceLine>
  <cbc:ID>'.($i+1).'</cbc:ID>
  <cbc:InvoicedQuantity unitCode="HUR">'.number_format($l['hours']??0, 2, '.', '').'</cbc:InvoicedQuantity>
  <cbc:LineExtensionAmount currencyID="EUR">'.number_format($lineTotal, 2, '.', '').'</cbc:LineExtensionAmount>
  <cac:Item><cbc:Name>'.htmlspecialchars($l['service']??'Service').'</cbc:Name>
    <cac:ClassifiedTaxCategory><cbc:ID>S</cbc:ID><cbc:Percent>19</cbc:Percent>
      <cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme>
    </cac:ClassifiedTaxCategory>
  </cac:Item>
  <cac:Price><cbc:PriceAmount currencyID="EUR">'.number_format($l['price']??0, 2, '.', '').'</cbc:PriceAmount></cac:Price>
</cac:InvoiceLine>';
                }
            } else {
                $xmlLines = '<cac:InvoiceLine>
  <cbc:ID>1</cbc:ID>
  <cbc:InvoicedQuantity unitCode="HUR">1</cbc:InvoicedQuantity>
  <cbc:LineExtensionAmount currencyID="EUR">'.number_format($inv['price'], 2, '.', '').'</cbc:LineExtensionAmount>
  <cac:Item><cbc:Name>Dienstleistung</cbc:Name>
    <cac:ClassifiedTaxCategory><cbc:ID>S</cbc:ID><cbc:Percent>19</cbc:Percent>
      <cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme>
    </cac:ClassifiedTaxCategory>
  </cac:Item>
  <cac:Price><cbc:PriceAmount currencyID="EUR">'.number_format($inv['price'], 2, '.', '').'</cbc:PriceAmount></cac:Price>
</cac:InvoiceLine>';
            }

            $netto = number_format($inv['price'], 2, '.', '');
            $tax = number_format($inv['tax'], 2, '.', '');
            $total = number_format($inv['total_price'], 2, '.', '');
            $issueDate = $inv['issue_date'] ?: date('Y-m-d');
            $dueDate = date('Y-m-d', strtotime($issueDate . ' +14 days'));

            $xml = '<?xml version="1.0" encoding="UTF-8"?>
<ubl:Invoice xmlns:ubl="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
  xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"
  xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
<cbc:CustomizationID>urn:cen.eu:en16931:2017#compliant#urn:xoev-de:kosit:standard:xrechnung_3.0</cbc:CustomizationID>
<cbc:ProfileID>urn:fdc:peppol.eu:2017:poacc:billing:01:1.0</cbc:ProfileID>
<cbc:ID>'.htmlspecialchars($inv['invoice_number']).'</cbc:ID>
<cbc:IssueDate>'.$issueDate.'</cbc:IssueDate>
<cbc:DueDate>'.$dueDate.'</cbc:DueDate>
<cbc:InvoiceTypeCode>380</cbc:InvoiceTypeCode>
<cbc:DocumentCurrencyCode>EUR</cbc:DocumentCurrencyCode>
<cbc:BuyerReference>'.htmlspecialchars($inv['cname']).'</cbc:BuyerReference>
<cac:AccountingSupplierParty><cac:Party>
  <cac:PartyName><cbc:Name>'.htmlspecialchars($settings['company'] ?? SITE).'</cbc:Name></cac:PartyName>
  <cac:PostalAddress>
    <cbc:StreetName>'.htmlspecialchars(($settings['street']??'').' '.($settings['number']??'')).'</cbc:StreetName>
    <cbc:CityName>'.htmlspecialchars($settings['city']??'Berlin').'</cbc:CityName>
    <cbc:PostalZone>'.htmlspecialchars($settings['postal_code']??'').'</cbc:PostalZone>
    <cac:Country><cbc:IdentificationCode>DE</cbc:IdentificationCode></cac:Country>
  </cac:PostalAddress>
  <cac:PartyTaxScheme>
    <cbc:CompanyID>'.htmlspecialchars($settings['USt_IdNr']??'').'</cbc:CompanyID>
    <cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme>
  </cac:PartyTaxScheme>
  <cac:Contact><cbc:ElectronicMail>'.CONTACT_EMAIL.'</cbc:ElectronicMail></cac:Contact>
</cac:Party></cac:AccountingSupplierParty>
<cac:AccountingCustomerParty><cac:Party>
  <cac:PartyName><cbc:Name>'.htmlspecialchars($inv['cname']).'</cbc:Name></cac:PartyName>
  <cac:PostalAddress>
    <cbc:StreetName>'.htmlspecialchars(($addr['street']??'').' '.($addr['number']??'')).'</cbc:StreetName>
    <cbc:CityName>'.htmlspecialchars($addr['city']??'').'</cbc:CityName>
    <cbc:PostalZone>'.htmlspecialchars($addr['postal_code']??'').'</cbc:PostalZone>
    <cac:Country><cbc:IdentificationCode>DE</cbc:IdentificationCode></cac:Country>
  </cac:PostalAddress>
  <cac:Contact><cbc:ElectronicMail>'.htmlspecialchars($inv['cemail']??'').'</cbc:ElectronicMail></cac:Contact>
</cac:Party></cac:AccountingCustomerParty>
<cac:PaymentMeans>
  <cbc:PaymentMeansCode>58</cbc:PaymentMeansCode>
  <cac:PayeeFinancialAccount><cbc:ID>'.htmlspecialchars($settings['iban']??'').'</cbc:ID>
    <cbc:Name>'.htmlspecialchars($settings['company']??SITE).'</cbc:Name>
    <cac:FinancialInstitutionBranch><cbc:ID>'.htmlspecialchars($settings['bic']??'').'</cbc:ID></cac:FinancialInstitutionBranch>
  </cac:PayeeFinancialAccount>
</cac:PaymentMeans>
<cac:TaxTotal>
  <cbc:TaxAmount currencyID="EUR">'.$tax.'</cbc:TaxAmount>
  <cac:TaxSubtotal>
    <cbc:TaxableAmount currencyID="EUR">'.$netto.'</cbc:TaxableAmount>
    <cbc:TaxAmount currencyID="EUR">'.$tax.'</cbc:TaxAmount>
    <cac:TaxCategory><cbc:ID>S</cbc:ID><cbc:Percent>19</cbc:Percent>
      <cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme>
    </cac:TaxCategory>
  </cac:TaxSubtotal>
</cac:TaxTotal>
<cac:LegalMonetaryTotal>
  <cbc:LineExtensionAmount currencyID="EUR">'.$netto.'</cbc:LineExtensionAmount>
  <cbc:TaxExclusiveAmount currencyID="EUR">'.$netto.'</cbc:TaxExclusiveAmount>
  <cbc:TaxInclusiveAmount currencyID="EUR">'.$total.'</cbc:TaxInclusiveAmount>
  <cbc:PayableAmount currencyID="EUR">'.$total.'</cbc:PayableAmount>
</cac:LegalMonetaryTotal>
'.$xmlLines.'
</ubl:Invoice>';

            header('Content-Type: application/xml; charset=utf-8');
            header('Content-Disposition: attachment; filename="XRechnung_'.$inv['invoice_number'].'.xml"');
            echo $xml;
            exit;
        })(),

        // Employee status update
        // Update single employee field (inline edit)
        $action === 'employee/update' && $method === 'POST' => (function() use ($body) {
            if (empty($body['emp_id']) || empty($body['field'])) throw new Exception('Need emp_id + field');
            $allowed = ['name','surname','email','phone','tariff','location','nationality','notes'];
            if (!in_array($body['field'], $allowed)) throw new Exception('Field not editable');
            q("UPDATE employee SET {$body['field']}=? WHERE emp_id=?", [$body['value'] ?? '', (int)$body['emp_id']]);
            audit('inline_edit', 'employee', $body['emp_id'], $body['field'] . ' updated');
            return ['updated' => (int)$body['emp_id'], 'field' => $body['field']];
        })(),

        $action === 'employee/status' && $method === 'POST' => (function() use ($body) {
            if (empty($body['emp_id'])) throw new Exception('Need emp_id');
            $status = isset($body['status']) ? (int)$body['status'] : 1;
            q("UPDATE employee SET status=? WHERE emp_id=?", [$status, $body['emp_id']]);
            audit('status_change', 'employee', $body['emp_id'], 'Status: '.($status?'Aktiv':'Inaktiv'));
            return ['emp_id' => $body['emp_id'], 'status' => $status];
        })(),

        // Employees
        $action === 'employees' => all("SELECT emp_id, name, surname, email, phone, status, tariff FROM employee ORDER BY name"),

        // Services
        $action === 'services' => all("SELECT s.*, c.name as customer_name FROM services s LEFT JOIN customer c ON s.customer_id_fk=c.customer_id WHERE s.status=1 ORDER BY s.title"),

        // Create service (admin only)
        $action === 'services/create' && $method === 'POST' => (function() use ($body) {
            if (($_SESSION['utype'] ?? '') !== 'admin') throw new Exception('Admin only');
            if (empty($body['title'])) throw new Exception('Need title');
            global $db;
            $price = (float)($body['price'] ?? $body['total_price'] ?? 30);
            $taxPct = (float)($body['tax_percentage'] ?? 19);
            $tax = round($price * $taxPct / 100, 2);
            $total = $price;
            q("INSERT INTO services (title, price, total_price, tax, tax_percentage, coin, street, number, postal_code, city, country, qm, room, box_code, client_code, deposit_code, customer_id_fk, status)
               VALUES (?,?,?,?,?,'€',?,?,?,?,'Deutschland',?,?,?,?,?,?,1)",
              [$body['title'], $price, $total, $tax, $taxPct,
               $body['street']??'', $body['number']??'', $body['postal_code']??'', $body['city']??'Berlin',
               $body['qm']??null, $body['room']??null, $body['box_code']??'', $body['client_code']??'', $body['deposit_code']??'',
               $body['customer_id_fk']??null]);
            $newId = $db->lastInsertId();
            audit('create', 'service', $newId, $body['title']);
            return one("SELECT * FROM services WHERE s_id=?", [$newId]);
        })(),

        // Update service (admin only)
        $action === 'services/update' && $method === 'POST' => (function() use ($body) {
            if (($_SESSION['utype'] ?? '') !== 'admin') throw new Exception('Admin only');
            $sid = (int)($body['s_id'] ?? 0);
            if (!$sid) throw new Exception('Need s_id');
            $allowed = ['title','total_price','price','street','number','postal_code','city','qm','room','box_code','client_code','deposit_code','wifi_name','wifi_password'];
            $sets = []; $params = [];
            foreach ($body as $k => $v) {
                if ($k === 's_id') continue;
                if (in_array($k, $allowed)) { $sets[] = "$k=?"; $params[] = $v; }
            }
            if (empty($sets)) throw new Exception('Nothing to update');
            $params[] = $sid;
            q("UPDATE services SET " . implode(',', $sets) . " WHERE s_id=?", $params);
            audit('update', 'service', $sid, implode(', ', array_keys(array_diff_key($body, ['s_id'=>1]))));
            return one("SELECT * FROM services WHERE s_id=?", [$sid]);
        })(),

        // Invoices
        $action === 'invoices' => all("SELECT i.*, c.name as customer_name FROM invoices i LEFT JOIN customer c ON i.customer_id_fk=c.customer_id ORDER BY i.issue_date DESC LIMIT 200"),

        // Webhook: booking (handles both flat AND nested website format)
        $action === 'webhook/booking' && $method === 'POST' => (function() use ($body) {
            global $db;
            $d = $body;

            // Handle nested format from fleckfrei.de website
            $name = $d['name'] ?? $d['customer']['name'] ?? 'Website Kunde';
            $email = $d['email'] ?? $d['customer']['email'] ?? '';
            $phone = $d['phone'] ?? $d['customer']['phone'] ?? '';
            $address = $d['address'] ?? $d['customer']['address'] ?? '';
            $date = $d['date'] ?? $d['schedule']['date'] ?? date('Y-m-d', strtotime('+1 day'));
            $time = $d['time'] ?? $d['schedule']['time'] ?? '09:00';
            $frequency = $d['frequency'] ?? $d['schedule']['frequency'] ?? '';
            $service = $d['service'] ?? $d['job_for'] ?? '';
            $sqm = $d['sqm'] ?? $d['property']['sqm'] ?? 60;
            $notes = $d['notes'] ?? '';
            $platform = $d['platform'] ?? $d['source'] ?? 'website';

            // Map service type → customer_type + hours
            $typeMap = ['privathaushalt'=>'Private Person','ferienwohnung'=>'Airbnb','buero'=>'Company'];
            $customerType = $typeMap[$service] ?? ($d['type'] ?? 'Private Person');
            $hours = $d['hours'] ?? ($sqm > 100 ? 4 : 3);

            // Map frequency → job_for
            $freqMap = ['einmalig'=>'','woechentlich'=>'weekly','weekly'=>'weekly','2wochen'=>'weekly2','monatlich'=>'weekly4'];
            $jobFor = $freqMap[$frequency] ?? $frequency;

            // Handle extras (array or comma string)
            $extras = $d['extras'] ?? [];
            if (is_array($extras)) $extras = implode(', ', $extras);

            // Parse address into parts (street, PLZ, city)
            $street = $address; $postal = ''; $city = '';
            if (preg_match('/^(.+?),\s*(\d{4,5})\s+(.+)$/', $address, $am)) {
                $street = trim($am[1]); $postal = $am[2]; $city = trim($am[3]);
            } elseif (preg_match('/^(.+?),\s*(.+)$/', $address, $am)) {
                $street = trim($am[1]); $city = trim($am[2]);
            }

            // Guest info (for Airbnb bookings)
            $guestName = $d['guest_name'] ?? $d['customer']['guest_name'] ?? '';
            $guestPhone = $d['guest_phone'] ?? $d['customer']['guest_phone'] ?? '';
            $guestEmail = $d['guest_email'] ?? $d['customer']['guest_email'] ?? '';
            $checkInDate = $d['check_in_date'] ?? $d['schedule']['check_in_date'] ?? null;
            $checkInTime = $d['check_in_time'] ?? $d['schedule']['check_in_time'] ?? null;
            $checkoutDate = $d['guest_checkout_date'] ?? null;
            $checkoutTime = $d['guest_checkout_time'] ?? null;

            // Stop time (calculated from start + hours)
            $stopTimes = $d['stop_times'] ?? date('H:i:s', strtotime($time) + ($hours * 3600));

            // Payment method
            $paymentMethod = $d['payment_method'] ?? $d['payment']['method'] ?? '';

            // Find or create customer
            $cust = $email ? one("SELECT * FROM customer WHERE email=?", [$email]) : null;
            if (!$cust && $phone) $cust = one("SELECT * FROM customer WHERE phone=?", [$phone]);
            if (!$cust) {
                q("INSERT INTO customer (name,email,phone,customer_type,password,status,email_permissions,notes) VALUES (?,?,?,?,'0000',1,'all',?)",
                  [$name, $email, $phone, $customerType, $notes]);
                $cid = $db->lastInsertId();
                if ($email) q("INSERT INTO users (email,type) VALUES (?,'customer')", [$email]);
                // Save address in customer_address table
                if ($address) {
                    q("INSERT INTO customer_address (street,number,postal_code,city,country,address_for,customer_id_fk) VALUES (?,'',?,?,'Deutschland','location',?)",
                      [$street, $postal, $city, $cid]);
                }
            } else {
                $cid = $cust['customer_id'];
                if ($customerType && $cust['customer_type'] !== $customerType) {
                    q("UPDATE customer SET customer_type=? WHERE customer_id=?", [$customerType, $cid]);
                }
            }

            // Match service: first by address, then by service type
            $sid = 0;
            if ($address) {
                $svc = one("SELECT s_id FROM services WHERE status=1 AND CONCAT(street,' ',number,', ',postal_code,' ',city) LIKE ? LIMIT 1", ["%$street%"]);
                if ($svc) $sid = $svc['s_id'];
            }
            if (!$sid) {
                // Map to default Fleckfrei service by type
                $svcMap = ['privathaushalt'=>'Home Care','ferienwohnung'=>'Short-Term','buero'=>'Office'];
                $svcSearch = $svcMap[$service] ?? '';
                if ($svcSearch) {
                    $svc = one("SELECT s_id FROM services WHERE title LIKE ? AND status=1 LIMIT 1", ["%Fleckfrei:%$svcSearch%"]);
                    if ($svc) $sid = $svc['s_id'];
                }
            }

            // Create job with ALL fields
            q("INSERT INTO jobs (customer_id_fk, s_id_fk, j_date, j_time, stop_times, check_in_date, check_in_time,
                j_hours, job_for, address, optional_products, emp_message, no_people, code_door,
                guest_checkout_time, guest_checkout_date, guest_name, guest_phone, guest_email,
                status, job_status, platform, j_c_val)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,'PENDING',?,?)",
              [$cid, $sid, $date, $time, $stopTimes, $checkInDate, $checkInTime,
               $hours, $jobFor, $address, $extras, $notes, $d['people']??1, $d['door_code']??'',
               $checkoutTime, $checkoutDate, $guestName, $guestPhone, $guestEmail,
               $platform, $paymentMethod]);
            $jid = $db->lastInsertId();

            // Send booking confirmation email + Telegram
            notifyBookingConfirmation($jid);
            telegramNotify("📩 <b>Neue Buchung</b>\n\n👤 $name ($customerType)\n📋 $service\n📅 $date um $time\n⏱ {$hours}h" . ($frequency ? "\n🔄 $frequency" : '') . "\n📍 $address\n🌐 $platform");

            return [
                'booking_id' => 'FF-'.date('Ymd').'-'.$jid,
                'job_id' => $jid,
                'customer_id' => $cid,
                'customer_type' => $customerType,
                'service' => $service,
                'status' => 'PENDING',
                'message' => "Booking FF-".date('Ymd')."-$jid created for $name ($customerType)"
            ];
        })(),

        // Messages: send (from n8n / external)
        $action === 'messages/send' && $method === 'POST' => (function() use ($body) {
            foreach (['sender_type','recipient_type','message'] as $r) { if (empty($body[$r])) throw new Exception("Missing: $r"); }
            global $db;
            q("INSERT INTO messages (sender_type,sender_id,sender_name,recipient_type,recipient_id,message,translated_message,job_id,channel) VALUES (?,?,?,?,?,?,?,?,?)",
              [$body['sender_type'], $body['sender_id']??null, $body['sender_name']??null,
               $body['recipient_type'], $body['recipient_id']??null,
               $body['message'], $body['translated_message']??null,
               $body['job_id']??null, $body['channel']??'system']);
            return ['msg_id' => $db->lastInsertId()];
        })(),

        // Messages: list (for n8n / external polling)
        $action === 'messages' && $method === 'GET' => (function() {
            $type = $_GET['type'] ?? '';
            $id = $_GET['id'] ?? '';
            $sql = "SELECT * FROM messages";
            $p = [];
            if ($type && $id) {
                $sql .= " WHERE (sender_type=? AND sender_id=?) OR (recipient_type=? AND recipient_id=?)";
                $p = [$type, $id, $type, $id];
            }
            $sql .= " ORDER BY created_at DESC LIMIT 50";
            return all($sql, $p);
        })(),

        // Messages: AI translate (n8n calls this after translation)
        $action === 'messages/translate' && $method === 'POST' => (function() use ($body) {
            if (empty($body['msg_id']) || empty($body['translated_message'])) throw new Exception('Need msg_id + translated_message');
            q("UPDATE messages SET translated_message=? WHERE msg_id=?", [$body['translated_message'], $body['msg_id']]);
            return ['updated' => $body['msg_id']];
        })(),

        // Email: send reminder for tomorrow's jobs (called by n8n cron)
        $action === 'email/reminders' && $method === 'POST' => (function() {
            $tomorrow = date('Y-m-d', strtotime('+1 day'));
            $jobs = all("SELECT j_id FROM jobs WHERE j_date=? AND status=1 AND job_status IN ('PENDING','CONFIRMED')", [$tomorrow]);
            $sent = 0;
            foreach ($jobs as $j) {
                if (notifyJobReminder($j['j_id'])) $sent++;
            }
            return ['date' => $tomorrow, 'jobs' => count($jobs), 'emails_sent' => $sent];
        })(),

        // DB Migration: create messages table (run once via API)
        $action === 'migrate/messages' && $method === 'POST' => (function() {
            $results = [];
            try {
                q("CREATE TABLE IF NOT EXISTS messages (
                    msg_id INT AUTO_INCREMENT PRIMARY KEY,
                    job_id INT DEFAULT NULL,
                    sender_type ENUM('admin','customer','employee','system','ai') NOT NULL,
                    sender_id INT DEFAULT NULL,
                    sender_name VARCHAR(100) DEFAULT NULL,
                    recipient_type ENUM('admin','customer','employee') NOT NULL,
                    recipient_id INT DEFAULT NULL,
                    message TEXT NOT NULL,
                    translated_message TEXT DEFAULT NULL,
                    channel ENUM('portal','whatsapp','email','system') DEFAULT 'portal',
                    read_at DATETIME DEFAULT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_recipient (recipient_type, recipient_id),
                    INDEX idx_sender (sender_type, sender_id),
                    INDEX idx_job (job_id),
                    INDEX idx_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $results[] = 'messages: OK';
            } catch (Exception $e) { $results[] = 'messages: ' . $e->getMessage(); }
            try {
                $cols = all("SHOW COLUMNS FROM customer LIKE 'email_notifications'");
                if (empty($cols)) { q("ALTER TABLE customer ADD COLUMN email_notifications TINYINT(1) DEFAULT 1"); $results[] = 'customer.email_notifications: ADDED'; }
                else $results[] = 'customer.email_notifications: exists';
            } catch (Exception $e) { $results[] = 'customer col: ' . $e->getMessage(); }
            try {
                $cols = all("SHOW COLUMNS FROM jobs LIKE 'job_photos'");
                if (empty($cols)) { q("ALTER TABLE jobs ADD COLUMN job_photos TEXT DEFAULT NULL"); $results[] = 'jobs.job_photos: ADDED'; }
                else $results[] = 'jobs.job_photos: exists';
            } catch (Exception $e) { $results[] = 'jobs.job_photos: ' . $e->getMessage(); }
            $count = val("SELECT COUNT(*) FROM messages");
            $results[] = "messages count: $count";
            return $results;
        })(),

        // Open Banking: Auto fetch + match bank transactions
        $action === 'bank/auto-sync' && $method === 'POST' => (function() {
            if (!FEATURE_AUTO_BANK) throw new Exception('Open Banking not enabled');
            $ob = new OpenBanking();
            if (!$ob->isConfigured()) throw new Exception('Missing API credentials');

            // Read account UIDs from file
            $accountFile = __DIR__ . '/../includes/openbanking_account.txt';
            if (!file_exists($accountFile)) throw new Exception('No bank accounts linked');
            $accountIds = array_filter(array_map('trim', explode("\n", file_get_contents($accountFile))));
            if (empty($accountIds)) throw new Exception('No valid account IDs');

            // Use Main Account (4th = index 3, or first available)
            $mainAccount = $accountIds[3] ?? $accountIds[0];

            // Fetch last 7 days
            $dateFrom = date('Y-m-d', strtotime('-7 days'));
            $txResp = $ob->getTransactions($mainAccount, $dateFrom);
            if (!$txResp) throw new Exception('API error');

            // Handle both formats: {transactions: [...]} or {transactions: {booked: [...]}}
            $transactions = [];
            if (isset($txResp['transactions'])) {
                if (isset($txResp['transactions']['booked'])) {
                    $transactions = array_merge($txResp['transactions']['booked'] ?? [], $txResp['transactions']['pending'] ?? []);
                } else {
                    $transactions = $txResp['transactions'];
                }
            }

            // Only incoming (credit indicator or positive amount)
            $incoming = array_filter($transactions, function($tx) {
                $indicator = $tx['credit_debit_indicator'] ?? '';
                $amount = (float)($tx['transaction_amount']['amount'] ?? 0);
                return $indicator === 'CRDT' || ($indicator !== 'DBIT' && $amount > 0);
            });

            $results = matchTransactionsWithInvoices($incoming);

            $applied = 0;
            if (!empty($results['matched'])) {
                $applied = autoApplyMatches($results['matched']);
                $matchedTotal = array_sum(array_column(array_column($results['matched'], 'tx'), 'amount'));
                telegramNotify("🏦 <b>Auto Bank-Import</b>\n\n✅ $applied Zahlungen gematcht (" . number_format($matchedTotal, 2) . " €)\n❓ " . count($results['unmatched']) . " nicht zugeordnet");
            }

            // Get balance
            $balResp = $ob->getBalances($mainAccount);
            $balance = $balResp['balances'][0]['balance_amount']['amount'] ?? null;

            return [
                'matched' => count($results['matched']),
                'unmatched' => count($results['unmatched']),
                'applied' => $applied,
                'balance' => $balance,
                'transactions_total' => count($transactions),
                'transactions_incoming' => count($incoming),
                'account' => $mainAccount
            ];
        })(),

        // Open Banking: Start bank connection flow
        $action === 'bank/connect' && $method === 'POST' => (function() use ($body) {
            $ob = new OpenBanking();
            if (!$ob->isConfigured()) throw new Exception('Missing API credentials');
            $bankName = $body['bank_name'] ?? 'N26';
            $psuType = $body['psu_type'] ?? 'business';
            $result = $ob->startAuth($bankName, 'DE', $psuType);
            if (!empty($result['url'])) {
                return ['url' => $result['url'], 'authorization_id' => $result['authorization_id'] ?? ''];
            }
            throw new Exception($result['message'] ?? 'Auth failed');
        })(),

        // Email: Send specific email templates
        $action === 'email/send' && $method === 'POST' => (function() use ($body) {
            $type = $body['type'] ?? '';
            $id = (int)($body['id'] ?? 0);
            if (!$id) throw new Exception('Missing id');
            $result = match($type) {
                'welcome' => notifyWelcome($id),
                'review' => notifyReviewRequest($id),
                'reminder' => notifyJobReminder($id),
                'payment_reminder' => notifyPaymentReminder($id),
                'invoice' => notifyInvoiceCreated($id),
                'booking' => notifyBookingConfirmation($id),
                'started' => notifyJobStarted($id),
                'completed' => notifyJobCompleted($id),
                default => throw new Exception("Unknown type: $type")
            };
            audit('email', $type, $id, "Email gesendet: $type");
            return ['sent' => (bool)$result, 'type' => $type];
        })(),

        // Settings: Update (admin API)
        $action === 'settings/update' && $method === 'POST' => (function() use ($body) {
            $allowed = ['first_name','last_name','company','phone','email','website','invoice_prefix','invoice_number','bank','bic','iban','USt_IdNr','business_number','fiscal_number','invoice_text','street','number','postal_code','city','country','note_for_email'];
            $sets = []; $params = [];
            foreach ($allowed as $f) {
                if (isset($body[$f])) { $sets[] = "$f=?"; $params[] = $body[$f]; }
            }
            if (empty($sets)) throw new Exception('No fields to update');
            q("UPDATE settings SET " . implode(',', $sets), $params);
            return ['updated' => count($sets)];
        })(),

        // Open Banking: List available banks
        $action === 'bank/list' && $method === 'GET' => (function() {
            $ob = new OpenBanking();
            if (!$ob->isConfigured()) throw new Exception('Missing API credentials');
            return $ob->getBanks('DE');
        })(),

        // Stripe: Create Checkout Session for invoice payment
        $action === 'stripe/checkout' && $method === 'POST' => (function() use ($body) {
            if (!FEATURE_STRIPE) throw new Exception('Stripe not enabled');
            $invId = (int)($body['inv_id'] ?? 0);
            if (!$invId) throw new Exception('Missing inv_id');
            $inv = one("SELECT i.*, c.name as cname, c.email as cemail FROM invoices i LEFT JOIN customer c ON i.customer_id_fk=c.customer_id WHERE i.inv_id=?", [$invId]);
            if (!$inv) throw new Exception('Invoice not found');
            if ($inv['invoice_paid'] === 'yes') throw new Exception('Already paid');
            $amount = (int)round(($inv['remaining_price'] ?: $inv['total_price']) * 100); // cents
            if ($amount <= 0) throw new Exception('Invalid amount');

            $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_USERPWD => STRIPE_SK . ':',
                CURLOPT_POSTFIELDS => http_build_query([
                    'payment_method_types[]' => 'card',
                    'line_items[0][price_data][currency]' => 'eur',
                    'line_items[0][price_data][product_data][name]' => 'Rechnung ' . $inv['invoice_number'],
                    'line_items[0][price_data][product_data][description]' => SITE . ' — ' . ($inv['cname'] ?? ''),
                    'line_items[0][price_data][unit_amount]' => $amount,
                    'line_items[0][quantity]' => 1,
                    'mode' => 'payment',
                    'customer_email' => $inv['cemail'] ?? '',
                    'metadata[inv_id]' => $invId,
                    'metadata[invoice_number]' => $inv['invoice_number'],
                    'success_url' => 'https://app.' . SITE_DOMAIN . '/customer/invoices.php?paid=1',
                    'cancel_url' => 'https://app.' . SITE_DOMAIN . '/customer/invoices.php?cancelled=1',
                ]),
            ]);
            $resp = json_decode(curl_exec($ch), true);
            curl_close($ch);
            if (!empty($resp['error'])) throw new Exception($resp['error']['message'] ?? 'Stripe error');
            audit('stripe_checkout', 'invoice', $invId, 'Checkout: ' . money($amount / 100));
            return ['checkout_url' => $resp['url'], 'session_id' => $resp['id']];
        })(),

        // Stripe: Webhook (payment completed)
        $action === 'stripe/webhook' && $method === 'POST' => (function() {
            $payload = file_get_contents('php://input');
            $event = json_decode($payload, true);
            if (!$event || ($event['type'] ?? '') !== 'checkout.session.completed') return ['ignored' => true];
            $session = $event['data']['object'] ?? [];
            $invId = (int)($session['metadata']['inv_id'] ?? 0);
            if (!$invId) return ['no_invoice' => true];
            $amount = ($session['amount_total'] ?? 0) / 100;
            // Mark invoice as paid
            $inv = one("SELECT * FROM invoices WHERE inv_id=?", [$invId]);
            if ($inv) {
                $newRemaining = max(0, $inv['remaining_price'] - $amount);
                $paid = $newRemaining <= 0 ? 'yes' : 'no';
                q("UPDATE invoices SET remaining_price=?, invoice_paid=? WHERE inv_id=?", [$newRemaining, $paid, $invId]);
                try { q("INSERT INTO invoice_payments (invoice_id_fk, amount, payment_date, payment_method, note) VALUES (?,?,?,?,?)",
                    [$invId, $amount, date('Y-m-d'), 'Stripe', 'Online: ' . ($session['payment_intent'] ?? '')]); } catch (Exception $e) {}
                audit('stripe_paid', 'invoice', $invId, "Stripe: $amount EUR");
                telegramNotify("Zahlung eingegangen! Rechnung #{$inv['invoice_number']}: " . money($amount) . " via Stripe");
            }
            return ['processed' => true, 'inv_id' => $invId, 'amount' => $amount];
        })(),

        // Bank Export: CSV download of bank transactions
        $action === 'bank/export' && $method === 'GET' => (function() {
            $month = $_GET['month'] ?? date('Y-m');
            $start = $month . '-01';
            $end = date('Y-m-t', strtotime($start));
            try {
                $payments = all("SELECT ip.*, i.invoice_number, c.name as cname
                    FROM invoice_payments ip
                    LEFT JOIN invoices i ON ip.invoice_id_fk=i.inv_id
                    LEFT JOIN customer c ON i.customer_id_fk=c.customer_id
                    WHERE ip.payment_date BETWEEN ? AND ?
                    ORDER BY ip.payment_date", [$start, $end]);
            } catch (Exception $e) {
                // Fallback: try with created_at if payment_date doesn't exist
                try {
                    $payments = all("SELECT ip.*, i.invoice_number, c.name as cname
                        FROM invoice_payments ip
                        LEFT JOIN invoices i ON ip.invoice_id_fk=i.inv_id
                        LEFT JOIN customer c ON i.customer_id_fk=c.customer_id
                        WHERE ip.created_at BETWEEN ? AND ?
                        ORDER BY ip.created_at", [$start, $end . ' 23:59:59']);
                } catch (Exception $e2) { $payments = []; }
            }
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="kontoauszug-' . $month . '.csv"');
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($out, ['Datum', 'Betrag', 'Methode', 'Rechnung', 'Kunde', 'Notiz'], ';');
            foreach ($payments as $p) {
                fputcsv($out, [
                    $p['payment_date'],
                    number_format($p['amount'], 2, ',', '.'),
                    $p['payment_method'] ?? '',
                    $p['invoice_number'] ?? '-',
                    $p['cname'] ?? '-',
                    $p['note'] ?? ''
                ], ';');
            }
            fclose($out);
            exit;
        })(),

        // Security: Bulk hash all plaintext passwords
        $action === 'security/hash-passwords' && $method === 'POST' => (function() {
            $results = ['customer' => 0, 'employee' => 0];
            // Hash customer passwords
            $customers = all("SELECT customer_id, password FROM customer WHERE password != '' AND password IS NOT NULL AND password NOT LIKE '\$2y\$%'");
            foreach ($customers as $c) {
                q("UPDATE customer SET password=? WHERE customer_id=?", [password_hash($c['password'], PASSWORD_BCRYPT, ['cost' => 12]), $c['customer_id']]);
                $results['customer']++;
            }
            // Hash employee passwords
            $employees = all("SELECT emp_id, password FROM employee WHERE password != '' AND password IS NOT NULL AND password NOT LIKE '\$2y\$%'");
            foreach ($employees as $e) {
                q("UPDATE employee SET password=? WHERE emp_id=?", [password_hash($e['password'], PASSWORD_BCRYPT, ['cost' => 12]), $e['emp_id']]);
                $results['employee']++;
            }
            audit('security', 'system', 0, "Bulk password hash: {$results['customer']} customers, {$results['employee']} employees");
            return $results;
        })(),

        // Email: send single notification (generic, called by n8n)
        $action === 'email/send' && $method === 'POST' => (function() use ($body) {
            if (empty($body['to']) || empty($body['subject']) || empty($body['body'])) throw new Exception('Need to, subject, body');
            $html = emailTemplate($body['subject'], $body['body'], $body['cta_text']??'', $body['cta_url']??'');
            $ok = sendEmail($body['to'], SITE . ' — ' . $body['subject'], $html);
            return ['sent' => $ok];
        })(),

        // Distance calculation — ALL transport modes + cost comparison
        $action === 'distance' && $method === 'GET' => (function() {
            $origin = $_GET['origin'] ?? '';
            $dest = $_GET['destination'] ?? '';
            if (!$origin || !$dest) throw new Exception('Need origin + destination');

            // Geocode address if needed
            if (!preg_match('/^[\d.-]+,[\d.-]+$/', $dest)) {
                $ctx = stream_context_create(['http' => ['header' => "User-Agent: Fleckfrei/1.0\r\n", 'timeout' => 5]]);
                $geoResp = @file_get_contents("https://nominatim.openstreetmap.org/search?q=" . urlencode($dest) . "&format=json&limit=1", false, $ctx);
                if ($geoResp) {
                    $geo = json_decode($geoResp, true);
                    if (!empty($geo[0])) $dest = $geo[0]['lat'] . ',' . $geo[0]['lon'];
                    else throw new Exception('Address not found');
                }
            }

            $oParts = explode(',', $origin);
            $dParts = explode(',', $dest);
            if (count($oParts) !== 2 || count($dParts) !== 2) throw new Exception('Invalid coordinates');

            $fmtDist = function($m) { return $m < 1000 ? ($m . ' m') : (round($m/1000, 1) . ' km'); };
            $fmtTime = function($s) { $m = round($s/60); return $m < 60 ? ($m . ' Min.') : (floor($m/60) . 'h ' . ($m%60) . 'min'); };
            $modes = [];

            // 1. Car (OSRM)
            $url = "https://router.project-osrm.org/route/v1/driving/{$oParts[1]},{$oParts[0]};{$dParts[1]},{$dParts[0]}?overview=false";
            $resp = @file_get_contents($url);
            if ($resp) {
                $data = json_decode($resp, true);
                if (($data['code']??'') === 'Ok' && !empty($data['routes'])) {
                    $r = $data['routes'][0];
                    $km = $r['distance'] / 1000;
                    // Cost: 0.30€/km (Benzin+Verschleiß) or 0.52€/km (ADAC Vollkosten)
                    $modes['car'] = [
                        'mode' => 'Auto', 'icon' => '🚗',
                        'distance' => $fmtDist($r['distance']), 'distance_meters' => (int)$r['distance'],
                        'duration' => $fmtTime($r['duration']), 'duration_seconds' => (int)$r['duration'],
                        'cost' => round($km * 0.30, 2), 'cost_full' => round($km * 0.52, 2)
                    ];
                }
            }

            // 2. Bicycle (OSRM)
            $url = "https://router.project-osrm.org/route/v1/bike/{$oParts[1]},{$oParts[0]};{$dParts[1]},{$dParts[0]}?overview=false";
            $resp = @file_get_contents($url);
            if ($resp) {
                $data = json_decode($resp, true);
                if (($data['code']??'') === 'Ok' && !empty($data['routes'])) {
                    $r = $data['routes'][0];
                    $modes['bike'] = [
                        'mode' => 'Fahrrad', 'icon' => '🚲',
                        'distance' => $fmtDist($r['distance']), 'distance_meters' => (int)$r['distance'],
                        'duration' => $fmtTime($r['duration']), 'duration_seconds' => (int)$r['duration'],
                        'cost' => 0, 'cost_full' => 0
                    ];
                }
            }

            // 3. Walking (OSRM)
            $url = "https://router.project-osrm.org/route/v1/foot/{$oParts[1]},{$oParts[0]};{$dParts[1]},{$dParts[0]}?overview=false";
            $resp = @file_get_contents($url);
            if ($resp) {
                $data = json_decode($resp, true);
                if (($data['code']??'') === 'Ok' && !empty($data['routes'])) {
                    $r = $data['routes'][0];
                    $modes['walk'] = [
                        'mode' => 'Zu Fuß', 'icon' => '🚶',
                        'distance' => $fmtDist($r['distance']), 'distance_meters' => (int)$r['distance'],
                        'duration' => $fmtTime($r['duration']), 'duration_seconds' => (int)$r['duration'],
                        'cost' => 0, 'cost_full' => 0
                    ];
                }
            }

            // 4. BVG/ÖPNV estimate (Berlin: 2.40€ Kurzstrecke, 3.50€ Einzelfahrt)
            $carDist = $modes['car']['distance_meters'] ?? 0;
            $bvgPrice = $carDist <= 3000 ? 2.40 : 3.50;
            $transitMins = round(($carDist / 1000) * 4.5); // ~4.5 min/km average with transfers
            $modes['transit'] = [
                'mode' => 'BVG (Bus/U-Bahn)', 'icon' => '🚌',
                'distance' => $modes['car']['distance'] ?? '-',
                'distance_meters' => $carDist,
                'duration' => $transitMins . ' Min.', 'duration_seconds' => $transitMins * 60,
                'cost' => $bvgPrice, 'cost_full' => $bvgPrice
            ];

            // 5. Bolt/Taxi estimate (Berlin: 1.65€ Grundgebühr + 1.28€/km + 0.30€/min)
            $boltKm = ($carDist / 1000);
            $boltMins = ($modes['car']['duration_seconds'] ?? 300) / 60;
            $boltPrice = round(1.65 + ($boltKm * 1.28) + ($boltMins * 0.30), 2);
            $modes['bolt'] = [
                'mode' => 'Bolt/Taxi', 'icon' => '🚕',
                'distance' => $modes['car']['distance'] ?? '-',
                'distance_meters' => $carDist,
                'duration' => $modes['car']['duration'] ?? '-',
                'duration_seconds' => $modes['car']['duration_seconds'] ?? 0,
                'cost' => $boltPrice, 'cost_full' => $boltPrice
            ];

            // Best option (cheapest that's < 30 min)
            $viable = array_filter($modes, fn($m) => ($m['duration_seconds'] ?? 9999) < 1800);
            if (empty($viable)) $viable = $modes;
            usort($viable, fn($a, $b) => $a['cost'] <=> $b['cost']);
            $best = $viable[0] ?? null;

            return [
                'modes' => $modes,
                'best' => $best,
                'distance_meters' => $carDist,
                'fraud_warning' => $carDist > 2000,
                'origin' => $origin,
                'destination' => $dest,
                'maps_url' => "https://www.google.com/maps/dir/{$origin}/{$dParts[0]},{$dParts[1]}"
            ];
        })(),

        // GPS: Partner sends live position (called from Employee Portal every 30s during RUNNING job)
        // Job: Upload photos during running job
        $action === 'job/photos' && $method === 'POST' => (function() {
            $jid = (int)($_POST['j_id'] ?? 0);
            if (!$jid) throw new Exception('j_id required');
            $job = one("SELECT * FROM jobs WHERE j_id=?", [$jid]);
            if (!$job) throw new Exception('Job not found');
            $uploadDir = __DIR__ . '/../uploads/jobs/' . $jid . '/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $uploaded = [];
            if (!empty($_FILES['photos']['name'][0])) {
                foreach ($_FILES['photos']['tmp_name'] as $i => $tmp) {
                    if ($_FILES['photos']['error'][$i] !== UPLOAD_ERR_OK) continue;
                    if ($_FILES['photos']['size'][$i] > 10 * 1024 * 1024) continue;
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime = $finfo->file($tmp);
                    $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
                    if (!isset($allowed[$mime])) continue;
                    $fname = bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
                    if (move_uploaded_file($tmp, $uploadDir . $fname)) $uploaded[] = $fname;
                }
            }
            // Append to existing photos
            $existing = !empty($job['job_photos']) ? json_decode($job['job_photos'], true) : [];
            $all = array_merge($existing ?: [], $uploaded);
            q("UPDATE jobs SET job_photos=? WHERE j_id=?", [json_encode($all), $jid]);
            return ['count' => count($uploaded), 'total' => count($all), 'files' => $uploaded];
        })(),

        $action === 'gps/update' && $method === 'POST' => (function() use ($body) {
            if (empty($body['emp_id']) || empty($body['lat']) || empty($body['lng'])) throw new Exception('Need emp_id, lat, lng');
            $location = $body['lat'] . ',' . $body['lng'];
            // Store in local DB (fast, no remote latency)
            global $dbLocal;
            try {
                $dbLocal->exec("CREATE TABLE IF NOT EXISTS gps_tracking (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    emp_id INT NOT NULL,
                    j_id INT DEFAULT NULL,
                    lat DECIMAL(10,7) NOT NULL,
                    lng DECIMAL(10,7) NOT NULL,
                    accuracy FLOAT DEFAULT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_emp (emp_id),
                    INDEX idx_time (created_at)
                ) ENGINE=InnoDB");
            } catch (Exception $e) {}
            $stmt = $dbLocal->prepare("INSERT INTO gps_tracking (emp_id, j_id, lat, lng, accuracy) VALUES (?,?,?,?,?)");
            $stmt->execute([$body['emp_id'], $body['j_id']??null, $body['lat'], $body['lng'], $body['accuracy']??null]);
            return ['tracked' => true, 'location' => $location];
        })(),

        // GPS: Get latest positions of all active partners
        $action === 'gps/live' && $method === 'GET' => (function() {
            global $dbLocal;
            try {
                $positions = $dbLocal->query("SELECT g.emp_id, g.j_id, g.lat, g.lng, g.accuracy, g.created_at,
                    e.name as emp_name, e.surname as emp_surname
                    FROM gps_tracking g
                    JOIN (SELECT emp_id, MAX(id) as max_id FROM gps_tracking WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) GROUP BY emp_id) latest ON g.id = latest.max_id
                    LEFT JOIN employee e ON g.emp_id = e.emp_id
                    ORDER BY g.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
                // Note: employee table might be on remote DB, this query uses local
                // Fallback: get names from remote DB
                global $db;
                foreach ($positions as &$p) {
                    if (empty($p['emp_name'])) {
                        $emp = $db->prepare("SELECT name, surname FROM employee WHERE emp_id=?");
                        $emp->execute([$p['emp_id']]);
                        $e = $emp->fetch(PDO::FETCH_ASSOC);
                        if ($e) { $p['emp_name'] = $e['name']; $p['emp_surname'] = $e['surname']; }
                    }
                }
                return $positions;
            } catch (Exception $e) {
                return [];
            }
        })(),

        // Sync: FULL sync from La-Renting — all fields, every minute
        $action === 'sync/jobs' && $method === 'POST' => (function() use ($body) {
            if (empty($body['jobs']) || !is_array($body['jobs'])) throw new Exception('Need jobs array');
            $updated = 0; $skipped = 0;
            $syncFields = ['job_status','j_date','j_time','j_hours','total_hours','start_time','end_time',
                'start_location','end_location','is_start_location','is_end_location',
                'emp_id_fk','customer_id_fk','s_id_fk','cancel_date','cancelled_role','cancelled_by',
                'job_note','job_for','address','code_door','platform','emp_message','optional_products',
                'no_people','guest_name','guest_phone','guest_email','guest_checkout_date','guest_checkout_time',
                'check_in_date','check_in_time','invoice_id','recurring_group','status'];

            foreach ($body['jobs'] as $j) {
                if (empty($j['j_id'])) { $skipped++; continue; }
                $current = one("SELECT job_status, start_time, end_time, total_hours, emp_id_fk, status FROM jobs WHERE j_id=?", [$j['j_id']]);
                if (!$current) { $skipped++; continue; }

                // Check if anything changed (quick check on key fields)
                $changed = $current['job_status'] !== ($j['job_status']??'')
                    || ($current['start_time']??'') !== ($j['start_time']??'')
                    || ($current['end_time']??'') !== ($j['end_time']??'')
                    || ($current['total_hours']??'') != ($j['total_hours']??'')
                    || ($current['emp_id_fk']??'') != ($j['emp_id_fk']??'')
                    || ($current['status']??'') != ($j['status']??'');
                if (!$changed) { $skipped++; continue; }

                $sets = []; $params = [];
                foreach ($syncFields as $f) {
                    if (array_key_exists($f, $j)) {
                        $sets[] = "$f=?";
                        $params[] = $j[$f];
                    }
                }
                if (empty($sets)) { $skipped++; continue; }
                $params[] = $j['j_id'];
                q("UPDATE jobs SET " . implode(',', $sets) . " WHERE j_id=?", $params);
                $updated++;
            }
            return ['updated' => $updated, 'skipped' => $skipped, 'total' => count($body['jobs'])];
        })(),

        // iCal Feeds: List all
        $action === 'ical/feeds' && $method === 'GET' => all(
            "SELECT f.*, c.name as customer_name FROM ical_feeds f LEFT JOIN customer c ON f.customer_id_fk=c.customer_id ORDER BY f.created_at DESC"
        ),

        // iCal Feeds: Add new feed
        $action === 'ical/feeds' && $method === 'POST' => (function() use ($body) {
            foreach (['customer_id_fk','label','url','platform'] as $r) {
                if (empty($body[$r])) throw new Exception("Missing: $r");
            }
            if (!filter_var($body['url'], FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $body['url'])) {
                throw new Exception('Invalid URL — must be HTTP(S)');
            }
            q("INSERT INTO ical_feeds (customer_id_fk, label, url, platform, active, created_at) VALUES (?,?,?,?,1,NOW())",
                [$body['customer_id_fk'], $body['label'], $body['url'], $body['platform']]);
            global $db;
            $id = $db->lastInsertId();
            audit('create', 'ical_feed', $id, "Feed: {$body['label']} ({$body['platform']})");
            return one("SELECT f.*, c.name as customer_name FROM ical_feeds f LEFT JOIN customer c ON f.customer_id_fk=c.customer_id WHERE f.id=?", [$id]);
        })(),

        // iCal Feeds: Delete
        $action === 'ical/feeds/delete' && $method === 'POST' => (function() use ($body) {
            $id = (int)($body['id'] ?? 0);
            if (!$id) throw new Exception('Missing id');
            $feed = one("SELECT * FROM ical_feeds WHERE id=?", [$id]);
            if (!$feed) throw new Exception('Feed not found');
            q("DELETE FROM ical_feeds WHERE id=?", [$id]);
            audit('delete', 'ical_feed', $id, "Feed: {$feed['label']}");
            return ['deleted' => $id];
        })(),

        // iCal Sync: Sync one or all feeds
        $action === 'ical/sync' && $method === 'POST' => (function() use ($body) {
            $feedId = (int)($body['feed_id'] ?? 0);

            if ($feedId) {
                $feeds = all("SELECT * FROM ical_feeds WHERE id=? AND active=1", [$feedId]);
            } else {
                $feeds = all("SELECT * FROM ical_feeds WHERE active=1");
            }

            if (empty($feeds)) throw new Exception('No active feeds found');

            $totalCreated = 0;
            $totalUpdated = 0;
            $totalSkipped = 0;
            $feedResults = [];

            foreach ($feeds as $feed) {
                $created = 0; $updated = 0; $skipped = 0; $errors = [];

                // Fetch iCal URL
                $ch = curl_init($feed['url']);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_USERAGENT => 'Fleckfrei/1.0 iCal-Sync',
                    CURLOPT_SSL_VERIFYPEER => true,
                ]);
                $icalData = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlErr = curl_error($ch);
                curl_close($ch);

                if (!$icalData || $httpCode !== 200) {
                    $feedResults[] = ['feed_id' => $feed['id'], 'label' => $feed['label'], 'error' => $curlErr ?: "HTTP $httpCode"];
                    continue;
                }

                // Parse VCALENDAR — split into VEVENT blocks
                $events = [];
                if (preg_match_all('/BEGIN:VEVENT(.+?)END:VEVENT/s', $icalData, $matches)) {
                    foreach ($matches[1] as $block) {
                        $ev = [];
                        // Extract fields line by line (handle folded lines)
                        $unfolded = preg_replace('/\r?\n[ \t]/', '', $block);
                        $lines = preg_split('/\r?\n/', $unfolded);
                        foreach ($lines as $line) {
                            $line = trim($line);
                            if (!$line || strpos($line, ':') === false) continue;
                            [$key, $val] = explode(':', $line, 2);
                            // Strip params like DTSTART;VALUE=DATE
                            $keyBase = explode(';', $key)[0];
                            $ev[strtoupper($keyBase)] = $val;
                        }
                        if (!empty($ev['DTSTART'])) {
                            $events[] = $ev;
                        }
                    }
                }

                foreach ($events as $ev) {
                    $uid = $ev['UID'] ?? '';
                    $summary = $ev['SUMMARY'] ?? '';
                    $description = $ev['DESCRIPTION'] ?? '';
                    $dtStart = $ev['DTSTART'] ?? '';
                    $dtEnd = $ev['DTEND'] ?? '';

                    // Parse DTSTART into date + time
                    $parsedStart = icalParseDate($dtStart);
                    if (!$parsedStart) { $skipped++; continue; }
                    $jDate = $parsedStart['date'];
                    $jTime = $parsedStart['time'];

                    // Parse DTEND for duration info (optional)
                    $parsedEnd = icalParseDate($dtEnd);
                    $jHours = 2; // default
                    if ($parsedEnd) {
                        $startTs = strtotime($parsedStart['date'] . ' ' . $parsedStart['time']);
                        $endTs = strtotime($parsedEnd['date'] . ' ' . $parsedEnd['time']);
                        if ($endTs > $startTs) {
                            $jHours = round(($endTs - $startTs) / 3600, 1);
                        }
                    }

                    // Check for existing job by ical_uid
                    if ($uid) {
                        $existing = one("SELECT j_id, j_date, j_time FROM jobs WHERE ical_uid=?", [$uid]);
                    } else {
                        $existing = null;
                    }

                    if ($existing) {
                        // Update if date/time changed
                        if ($existing['j_date'] !== $jDate || substr($existing['j_time'] ?? '', 0, 5) !== substr($jTime, 0, 5)) {
                            q("UPDATE jobs SET j_date=?, j_time=?, j_hours=? WHERE j_id=?",
                                [$jDate, $jTime, $jHours, $existing['j_id']]);
                            $updated++;
                        } else {
                            $skipped++;
                        }
                    } else {
                        // Create new job
                        q("INSERT INTO jobs (customer_id_fk, j_date, j_time, j_hours, job_status, platform, ical_uid, job_note, address, status) VALUES (?,?,?,?,?,?,?,?,?,1)",
                            [
                                $feed['customer_id_fk'],
                                $jDate,
                                $jTime,
                                $jHours,
                                'PENDING',
                                $feed['platform'],
                                $uid,
                                trim($summary . ($description ? "\n" . str_replace('\\n', "\n", $description) : '')),
                                $ev['LOCATION'] ?? '',
                            ]);
                        $created++;
                    }
                }

                // Update feed stats
                q("UPDATE ical_feeds SET last_sync=NOW(), jobs_created=jobs_created+? WHERE id=?", [$created, $feed['id']]);

                $totalCreated += $created;
                $totalUpdated += $updated;
                $totalSkipped += $skipped;

                $feedResults[] = [
                    'feed_id' => $feed['id'],
                    'label' => $feed['label'],
                    'events_found' => count($events),
                    'created' => $created,
                    'updated' => $updated,
                    'skipped' => $skipped,
                ];
            }

            audit('sync', 'ical', 0, "iCal sync: {$totalCreated} created, {$totalUpdated} updated");
            if ($totalCreated > 0) {
                telegramNotify("iCal Sync: {$totalCreated} neue Jobs importiert, {$totalUpdated} aktualisiert");
            }

            return [
                'feeds_synced' => count($feedResults),
                'total_created' => $totalCreated,
                'total_updated' => $totalUpdated,
                'total_skipped' => $totalSkipped,
                'feeds' => $feedResults,
            ];
        })(),

        // Deep OSINT scan — calls free public APIs
        $action === 'osint/deep' && $method === 'POST' => (function() use ($body) {
            $email = $body['email'] ?? '';
            $name = $body['name'] ?? '';
            $phone = $body['phone'] ?? '';
            $address = $body['address'] ?? '';
            $results = [];

            // 1. Email domain deep check
            if ($email && strpos($email,'@')!==false) {
                $domain = substr($email, strpos($email,'@')+1);

                // crt.sh — SSL certificate transparency (find subdomains)
                $ch = curl_init("https://crt.sh/?q=%25.".$domain."&output=json");
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>10, CURLOPT_SSL_VERIFYPEER=>0]);
                $crtRaw = curl_exec($ch); curl_close($ch);
                $certs = json_decode($crtRaw, true);
                if (is_array($certs)) {
                    $subdomains = array_unique(array_column(array_slice($certs,0,50), 'common_name'));
                    $results['subdomains'] = ['source'=>'crt.sh', 'count'=>count($subdomains), 'data'=>array_values(array_slice($subdomains,0,20))];
                }

                // DNS deep: SPF, DMARC, DKIM
                $spf = @dns_get_record($domain, DNS_TXT);
                $spfRecords = [];
                if ($spf) foreach ($spf as $r) { if (isset($r['txt']) && stripos($r['txt'],'spf')!==false) $spfRecords[] = $r['txt']; }
                $dmarc = @dns_get_record('_dmarc.'.$domain, DNS_TXT);
                $dmarcTxt = $dmarc ? ($dmarc[0]['txt']??'') : '';
                $results['email_security'] = ['spf'=>$spfRecords, 'dmarc'=>$dmarcTxt, 'has_spf'=>!empty($spfRecords), 'has_dmarc'=>!empty($dmarcTxt)];

                // HackerTarget reverse IP (what else is hosted on same server)
                $a = @dns_get_record($domain, DNS_A);
                if (!empty($a[0]['ip'])) {
                    $ip = $a[0]['ip'];
                    $ch = curl_init("https://api.hackertarget.com/reverseiplookup/?q=".$ip);
                    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>8]);
                    $revIp = curl_exec($ch); curl_close($ch);
                    if ($revIp && !str_contains($revIp, 'error')) {
                        $hosts = array_filter(explode("\n", trim($revIp)));
                        $results['reverse_ip'] = ['ip'=>$ip, 'count'=>count($hosts), 'hosts'=>array_slice($hosts,0,15)];
                    }
                }

                // Wayback Machine — how old is domain
                $ch = curl_init("https://archive.org/wayback/available?url=".$domain);
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>8]);
                $wb = json_decode(curl_exec($ch), true); curl_close($ch);
                if (!empty($wb['archived_snapshots']['closest'])) {
                    $results['wayback'] = $wb['archived_snapshots']['closest'];
                }

                // WHOIS via free API
                $ch = curl_init("https://api.hackertarget.com/whois/?q=".$domain);
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>8]);
                $whois = curl_exec($ch); curl_close($ch);
                if ($whois && !str_contains($whois, 'error') && strlen($whois) > 50) {
                    // Extract key fields
                    $w = [];
                    if (preg_match('/Registrar:\s*(.+)/i', $whois, $m)) $w['registrar'] = trim($m[1]);
                    if (preg_match('/Creation Date:\s*(.+)/i', $whois, $m)) $w['created'] = trim($m[1]);
                    if (preg_match('/Updated Date:\s*(.+)/i', $whois, $m)) $w['updated'] = trim($m[1]);
                    if (preg_match('/Registrant Organization:\s*(.+)/i', $whois, $m)) $w['org'] = trim($m[1]);
                    if (preg_match('/Registrant Country:\s*(.+)/i', $whois, $m)) $w['country'] = trim($m[1]);
                    if (preg_match('/Registrant Name:\s*(.+)/i', $whois, $m)) $w['registrant'] = trim($m[1]);
                    $results['whois'] = $w;
                }
            }

            // 2. Social media profile check (HTTP HEAD — does profile exist?)
            if ($name) {
                $slug = strtolower(preg_replace('/[^a-z0-9]/i', '', $name));
                $slugDot = strtolower(str_replace(' ', '.', trim($name)));
                $slugDash = strtolower(str_replace(' ', '-', trim($name)));
                $checks = [
                    'instagram' => 'https://www.instagram.com/'.$slug.'/',
                    'tiktok' => 'https://www.tiktok.com/@'.$slug,
                    'github' => 'https://github.com/'.$slug,
                    'twitter' => 'https://x.com/'.$slug,
                    'linkedin' => 'https://www.linkedin.com/in/'.$slugDash,
                ];
                $profiles = [];
                foreach ($checks as $platform => $url) {
                    $ch = curl_init($url);
                    curl_setopt_array($ch, [CURLOPT_NOBODY=>1, CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>5, CURLOPT_FOLLOWLOCATION=>1, CURLOPT_SSL_VERIFYPEER=>0,
                        CURLOPT_USERAGENT=>'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36']);
                    curl_exec($ch);
                    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
                    curl_close($ch);
                    $exists = ($code >= 200 && $code < 400 && !str_contains($finalUrl, 'login') && !str_contains($finalUrl, '404'));
                    $profiles[$platform] = ['url'=>$url, 'status'=>$code, 'exists'=>$exists];
                }
                $results['profiles'] = $profiles;
            }

            // 3. Data breach check (Have I Been Pwned style — uses free API)
            if ($email) {
                $ch = curl_init("https://api.hackertarget.com/emailsearch/?q=".urlencode(substr($email, strpos($email,'@')+1)));
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>8]);
                $emailSearch = curl_exec($ch); curl_close($ch);
                if ($emailSearch && !str_contains($emailSearch, 'error')) {
                    $found = array_filter(explode("\n", trim($emailSearch)));
                    $results['email_exposure'] = ['count'=>count($found), 'emails'=>array_slice($found,0,10)];
                }
            }

            return $results;
        })(),

        // ============ SMOOBU CHANNEL MANAGER ============

        // Smoobu: List bookings
        $action === 'smoobu/bookings' && $method === 'GET' => (function() {
            if (!FEATURE_SMOOBU) throw new Exception('Smoobu nicht konfiguriert');
            $from = $_GET['from'] ?? date('Y-m-d');
            $to = $_GET['to'] ?? date('Y-m-d', strtotime('+30 days'));
            $page = (int)($_GET['page'] ?? 1);
            return smoobuApi("/booking?from=$from&to=$to&page=$page&pageSize=50");
        })(),

        // Smoobu: List apartments/properties
        $action === 'smoobu/apartments' && $method === 'GET' => (function() {
            if (!FEATURE_SMOOBU) throw new Exception('Smoobu nicht konfiguriert');
            return smoobuApi('/apartment');
        })(),

        // Smoobu: Get rates
        $action === 'smoobu/rates' && $method === 'GET' => (function() {
            if (!FEATURE_SMOOBU) throw new Exception('Smoobu nicht konfiguriert');
            $aptId = $_GET['apartment_id'] ?? '';
            $start = $_GET['start'] ?? date('Y-m-d');
            $end = $_GET['end'] ?? date('Y-m-d', strtotime('+30 days'));
            if (!$aptId) throw new Exception('apartment_id required');
            return smoobuApi("/rates?apartments[]=$aptId&start_date=$start&end_date=$end");
        })(),

        // Smoobu: Get availability
        $action === 'smoobu/availability' && $method === 'GET' => (function() {
            if (!FEATURE_SMOOBU) throw new Exception('Smoobu nicht konfiguriert');
            $aptId = $_GET['apartment_id'] ?? '';
            $start = $_GET['start'] ?? date('Y-m-d');
            $end = $_GET['end'] ?? date('Y-m-d', strtotime('+30 days'));
            if (!$aptId) throw new Exception('apartment_id required');
            return smoobuApi("/availability?apartments[]=$aptId&start_date=$start&end_date=$end");
        })(),

        // Smoobu: Full sync — fetch all bookings and cache locally
        $action === 'smoobu/sync' && $method === 'POST' => (function() {
            if (!FEATURE_SMOOBU) throw new Exception('Smoobu nicht konfiguriert');
            global $dbLocal;
            $from = date('Y-m-d', strtotime('-7 days'));
            $to = date('Y-m-d', strtotime('+90 days'));
            $created = 0; $updated = 0; $page = 1;

            do {
                $resp = smoobuApi("/booking?from=$from&to=$to&page=$page&pageSize=100");
                $bookings = $resp['bookings'] ?? [];

                foreach ($bookings as $b) {
                    $smoobuId = (int)($b['id'] ?? 0);
                    if (!$smoobuId) continue;
                    $data = [
                        $b['guest-name'] ?? '', $b['email'] ?? '', $b['phone'] ?? '',
                        $b['apartment']['name'] ?? '', (int)($b['apartment']['id'] ?? 0),
                        $b['channel']['name'] ?? 'direct',
                        $b['arrival'] ?? null, $b['departure'] ?? null,
                        (int)($b['adults'] ?? 1), (int)($b['children'] ?? 0),
                        (float)($b['price'] ?? 0), $b['notice'] ?? '',
                    ];
                    $ex = $dbLocal->prepare("SELECT cb_id FROM channel_bookings WHERE smoobu_id=?");
                    $ex->execute([$smoobuId]);
                    if ($ex->fetch()) {
                        $dbLocal->prepare("UPDATE channel_bookings SET guest_name=?, guest_email=?, guest_phone=?, property_name=?, property_id=?, channel=?, check_in=?, check_out=?, adults=?, children=?, price=?, notes=?, synced_at=NOW() WHERE smoobu_id=?")
                            ->execute([...$data, $smoobuId]);
                        $updated++;
                    } else {
                        $dbLocal->prepare("INSERT INTO channel_bookings (guest_name, guest_email, guest_phone, property_name, property_id, channel, check_in, check_out, adults, children, price, notes, smoobu_id, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'confirmed')")
                            ->execute([...$data, $smoobuId]);
                        $created++;
                    }
                }
                $page++;
            } while (count($bookings) >= 100 && $page <= 10);

            audit('sync', 'smoobu', 0, "Sync: $created new, $updated updated");
            if ($created > 0) {
                telegramNotify("🏠 Smoobu Sync: $created neue Buchungen, $updated aktualisiert");
            }
            return ['created' => $created, 'updated' => $updated, 'pages_fetched' => $page - 1];
        })(),

        // Channel bookings: local cache list
        $action === 'channel/bookings' && $method === 'GET' => (function() {
            global $dbLocal;
            $from = $_GET['from'] ?? date('Y-m-d');
            $to = $_GET['to'] ?? date('Y-m-d', strtotime('+30 days'));
            $channel = $_GET['channel'] ?? '';
            $sql = "SELECT * FROM channel_bookings WHERE check_in BETWEEN ? AND ? AND status != 'cancelled'";
            $p = [$from, $to];
            if ($channel) { $sql .= " AND channel=?"; $p[] = $channel; }
            $sql .= " ORDER BY check_in";
            $stmt = $dbLocal->prepare($sql);
            $stmt->execute($p);
            return $stmt->fetchAll();
        })(),

        // ============================================================
        // RATINGS — Customer rates partner after job
        // ============================================================
        $action === 'ratings/submit' && $method === 'POST' => (function() use ($body) {
            $jid = (int)($body['j_id'] ?? 0);
            $stars = max(1, min(5, (int)($body['stars'] ?? 5)));
            $comment = trim($body['comment'] ?? '');
            $job = one("SELECT j.*, e.name as ename, c.name as cname FROM jobs j LEFT JOIN employee e ON j.emp_id_fk=e.emp_id LEFT JOIN customer c ON j.customer_id_fk=c.customer_id WHERE j.j_id=?", [$jid]);
            if (!$job) throw new Exception('Job not found');
            if ($job['job_status'] !== 'COMPLETED') throw new Exception('Job not completed');
            global $dbLocal;
            $dbLocal->exec("CREATE TABLE IF NOT EXISTS job_ratings (
                id INT AUTO_INCREMENT PRIMARY KEY, j_id INT NOT NULL, emp_id INT,
                customer_id INT, stars INT NOT NULL, comment TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uniq_job (j_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $dbLocal->prepare("REPLACE INTO job_ratings (j_id, emp_id, customer_id, stars, comment) VALUES (?,?,?,?,?)")
                ->execute([$jid, $job['emp_id_fk'], $job['customer_id_fk'], $stars, $comment]);
            $starEmoji = str_repeat('*', $stars) . str_repeat('.', 5-$stars);
            telegramNotify("⭐ <b>Bewertung</b>\n\n👤 {$job['cname']} → 👷 {$job['ename']}\n⭐ {$starEmoji} ({$stars}/5)\n💬 " . ($comment ?: '—'));
            return ['rated' => true, 'stars' => $stars];
        })(),

        $action === 'ratings/partner' && $method === 'GET' => (function() {
            $empId = (int)($_GET['emp_id'] ?? 0);
            if (!$empId) throw new Exception('emp_id required');
            global $dbLocal;
            try {
                $avg = $dbLocal->prepare("SELECT AVG(stars) as avg_stars, COUNT(*) as total FROM job_ratings WHERE emp_id=?");
                $avg->execute([$empId]);
                $stats = $avg->fetch(PDO::FETCH_ASSOC);
                $recent = $dbLocal->prepare("SELECT r.*, c.name as customer_name FROM job_ratings r LEFT JOIN customer c ON r.customer_id=c.customer_id WHERE r.emp_id=? ORDER BY r.created_at DESC LIMIT 10");
                $recent->execute([$empId]);
                return ['emp_id'=>$empId, 'avg_stars'=>round($stats['avg_stars']??0, 1), 'total_ratings'=>(int)($stats['total']??0), 'recent'=>$recent->fetchAll(PDO::FETCH_ASSOC)];
            } catch (Exception $e) { return ['emp_id'=>$empId, 'avg_stars'=>0, 'total_ratings'=>0, 'recent'=>[]]; }
        })(),

        // ============================================================
        // RECURRING JOBS — Auto-create from templates
        // ============================================================
        $action === 'recurring/process' && $method === 'POST' => (function() {
            $today = date('Y-m-d');
            $horizon = date('Y-m-d', strtotime('+7 days')); // Look ahead 7 days
            $created = 0; $details = [];
            // Find recurring groups — latest job per group
            $templates = all("SELECT j.*, j.recurring_group as rg, s.title as stitle, c.name as cname
                FROM jobs j LEFT JOIN services s ON j.s_id_fk=s.s_id LEFT JOIN customer c ON j.customer_id_fk=c.customer_id
                WHERE j.recurring_group IS NOT NULL AND j.recurring_group != ''
                AND j.job_for IS NOT NULL AND j.job_for != '' AND j.status=1
                AND j.job_status != 'CANCELLED'
                AND j.j_date = (SELECT MAX(j2.j_date) FROM jobs j2 WHERE j2.recurring_group=j.recurring_group AND j2.status=1 AND j2.job_status != 'CANCELLED')
                GROUP BY j.recurring_group");
            foreach ($templates as $t) {
                $lastDate = $t['j_date'];
                $freq = strtolower($t['job_for']);
                // Exact match first, then fuzzy — order matters
                $intervalDays = match(true) {
                    $freq === 'daily' => 1,
                    $freq === 'weekly4' || str_contains($freq, 'monat') || str_contains($freq, 'month') => 28,
                    $freq === 'weekly3' => 21,
                    $freq === 'weekly2' || str_contains($freq, '2 woch') || str_contains($freq, '2 week') || str_contains($freq, '14') => 14,
                    $freq === 'weekly' || str_contains($freq, 'woche') || str_contains($freq, 'week') => 7,
                    default => 0
                };
                if ($intervalDays === 0) continue;
                // Generate all missing jobs up to horizon
                $cur = new DateTime($lastDate);
                $end = new DateTime($horizon);
                $cur->modify("+{$intervalDays} days");
                while ($cur <= $end) {
                    $nextDate = $cur->format('Y-m-d');
                    $exists = one("SELECT j_id FROM jobs WHERE recurring_group=? AND j_date=? AND status=1", [$t['rg'], $nextDate]);
                    if (!$exists) {
                        q("INSERT INTO jobs (customer_id_fk, s_id_fk, j_date, j_time, j_hours, job_for, address, emp_id_fk, no_people, code_door, status, job_status, platform, recurring_group) VALUES (?,?,?,?,?,?,?,?,?,?,1,'PENDING',?,?)",
                            [$t['customer_id_fk'], $t['s_id_fk'], $nextDate, $t['j_time'], $t['j_hours'], $t['job_for'], $t['address'], $t['emp_id_fk'], $t['no_people'], $t['code_door'], $t['platform'], $t['rg']]);
                        $created++;
                        $details[] = ($t['cname'] ?? '?') . ' ' . $nextDate;
                    }
                    $cur->modify("+{$intervalDays} days");
                }
            }
            if ($created > 0) {
                $list = implode("\n", array_slice($details, 0, 10));
                telegramNotify("🔄 <b>Recurring Jobs</b>\n\n$created Job(s) erstellt (7-Tage Vorschau):\n$list");
                audit('auto_create', 'recurring', 0, "$created jobs created (horizon: $horizon)");
            }
            return ['created' => $created, 'templates_checked' => count($templates), 'horizon' => $horizon, 'details' => $details];
        })(),

        // ============================================================
        // EMAIL SYNC — Trigger from cron
        // ============================================================
        $action === 'email/sync' && $method === 'POST' => (function() {
            // Redirect to email-inbox sync logic
            $_POST['action'] = 'sync';
            $_POST['csrf_token'] = ''; // Skip CSRF for API call
            $url = 'https://app.' . SITE_DOMAIN . '/admin/email-inbox.php';
            return ['synced' => 0, 'message' => 'Use admin/email-inbox.php directly'];
        })(),

        // ============================================================
        // WHATSAPP AUTO-BOOKING — Parse natural language → create job
        // Called by n8n when customer sends WhatsApp message
        // Input: { "phone": "+49...", "message": "Morgen 14 Uhr", "name": "Max" }
        // ============================================================
        $action === 'whatsapp/auto-book' && $method === 'POST' => (function() use ($body) {
            global $db;
            $phone = trim($body['phone'] ?? '');
            $msg = trim($body['message'] ?? '');
            $senderName = trim($body['name'] ?? '');
            if (!$phone || !$msg) throw new Exception('Need phone + message');

            // Find customer by phone (strip +, spaces, leading 0)
            $cleanPhone = preg_replace('/[\s\-\+]/', '', $phone);
            $phoneLike = '%' . substr($cleanPhone, -9) . '%'; // last 9 digits
            $customer = one("SELECT * FROM customer WHERE REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+','') LIKE ? AND status=1", [$phoneLike]);

            // Parse date from message (German NLP)
            $dateStr = null; $timeStr = null;
            $lower = mb_strtolower($msg);

            // Date patterns
            if (preg_match('/(\d{1,2})\.(\d{1,2})\.?(\d{2,4})?/', $msg, $dm)) {
                $y = !empty($dm[3]) ? (strlen($dm[3])===2 ? '20'.$dm[3] : $dm[3]) : date('Y');
                $dateStr = "$y-" . str_pad($dm[2],2,'0',STR_PAD_LEFT) . "-" . str_pad($dm[1],2,'0',STR_PAD_LEFT);
            } elseif (str_contains($lower, 'heute')) {
                $dateStr = date('Y-m-d');
            } elseif (str_contains($lower, 'morgen')) {
                $dateStr = date('Y-m-d', strtotime('+1 day'));
            } elseif (str_contains($lower, 'übermorgen')) {
                $dateStr = date('Y-m-d', strtotime('+2 days'));
            } elseif (preg_match('/montag|dienstag|mittwoch|donnerstag|freitag|samstag|sonntag/i', $lower, $dayM)) {
                $dayMap = ['montag'=>'monday','dienstag'=>'tuesday','mittwoch'=>'wednesday','donnerstag'=>'thursday','freitag'=>'friday','samstag'=>'saturday','sonntag'=>'sunday'];
                $dateStr = date('Y-m-d', strtotime('next ' . $dayMap[mb_strtolower($dayM[0])]));
            }

            // Time patterns
            if (preg_match('/(\d{1,2})[:\.](\d{2})\s*uhr/i', $msg, $tm)) {
                $timeStr = str_pad($tm[1],2,'0',STR_PAD_LEFT) . ':' . $tm[2];
            } elseif (preg_match('/(\d{1,2})\s*uhr/i', $msg, $tm)) {
                $timeStr = str_pad($tm[1],2,'0',STR_PAD_LEFT) . ':00';
            } elseif (preg_match('/(\d{1,2})[:\.](\d{2})/', $msg, $tm)) {
                $h = (int)$tm[1]; $m = $tm[2];
                if ($h >= 6 && $h <= 22) $timeStr = str_pad($h,2,'0',STR_PAD_LEFT) . ':' . $m;
            }

            // Hours parsing
            $hours = MIN_HOURS;
            if (preg_match('/(\d+(?:[.,]\d+)?)\s*(?:stunden?|std|h)\b/i', $msg, $hm)) {
                $hours = max(MIN_HOURS, (float)str_replace(',', '.', $hm[1]));
            }

            if (!$dateStr) throw new Exception('Kein Datum erkannt in: ' . $msg);
            if (!$timeStr) $timeStr = '10:00'; // Default 10 Uhr

            // Create or find customer
            $custId = null;
            if ($customer) {
                $custId = $customer['customer_id'];
            } else {
                // New customer from WhatsApp
                q("INSERT INTO customer (name, phone, customer_type, status) VALUES (?,?,'Private Person',1)",
                    [$senderName ?: 'WhatsApp ' . substr($phone, -4), $phone]);
                $custId = $db->lastInsertId();
                audit('auto_create', 'customer', $custId, "WhatsApp Auto-Booking: $phone");
            }

            // Find default service for customer (or first active)
            $svc = one("SELECT s_id FROM services WHERE customer_id_fk=? AND status=1 ORDER BY s_id DESC LIMIT 1", [$custId])
                ?: one("SELECT s_id FROM services WHERE status=1 ORDER BY s_id LIMIT 1");
            $svcId = $svc['s_id'] ?? 0;

            // Address from customer's service
            $addr = '';
            if ($svcId) {
                $svcData = one("SELECT street, city FROM services WHERE s_id=?", [$svcId]);
                $addr = trim(($svcData['street'] ?? '') . ' ' . ($svcData['city'] ?? ''));
            }

            // Create job
            q("INSERT INTO jobs (customer_id_fk, s_id_fk, j_date, j_time, j_hours, address, status, job_status, platform) VALUES (?,?,?,?,?,?,1,'PENDING','whatsapp')",
                [$custId, $svcId, $dateStr, $timeStr, $hours, $addr]);
            $jobId = $db->lastInsertId();
            audit('auto_create', 'job', $jobId, "WhatsApp: $phone → $dateStr $timeStr");

            $custName = $customer['name'] ?? $senderName ?: 'Neukunde';
            telegramNotify("📱 <b>WhatsApp Auto-Booking</b>\n\n👤 $custName\n📞 $phone\n📅 $dateStr um $timeStr\n⏱ {$hours}h\n💬 <i>" . htmlspecialchars(mb_substr($msg, 0, 80)) . "</i>\n\n" . ($customer ? '✅ Bestandskunde' : '🆕 Neukunde angelegt'));

            return [
                'job_id' => $jobId,
                'customer_id' => $custId,
                'is_new_customer' => !$customer,
                'parsed' => ['date' => $dateStr, 'time' => $timeStr, 'hours' => $hours],
                'original_message' => $msg,
                'confirmation' => "Termin am " . date('d.m.Y', strtotime($dateStr)) . " um $timeStr bestätigt."
            ];
        })(),

        // ============================================================
        // TIMESLOTS — Hourly availability for a given date
        // GET /api/index.php?action=timeslots&date=2026-04-15&hours=3
        // ============================================================
        $action === 'timeslots' && $method === 'GET' => (function() {
            $date = $_GET['date'] ?? date('Y-m-d');
            $reqHours = max(MIN_HOURS, (float)($_GET['hours'] ?? 2));
            $targetAddress = trim($_GET['address'] ?? ''); // optional: für geo-check
            $customerId = (int)($_GET['customer_id'] ?? 0);
            $basePrice = (float)($_GET['base_price'] ?? 0);
            $MAX_TRAVEL_KM = (int) (val("SELECT max_distance_km FROM settings LIMIT 1") ?: 30);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new Exception('Invalid date format');
            if ($date < date('Y-m-d')) throw new Exception('Date in the past');

            // Stammkunden-Check + Host/Airbnb-Check (beide = Festpreis, keine Rabatte)
            $isStammkunde = false; $isHostType = false;
            if ($customerId) {
                $cust = one("SELECT legacy_pricing, customer_type FROM customer WHERE customer_id=?", [$customerId]);
                $completed = (int)val("SELECT COUNT(*) FROM jobs WHERE customer_id_fk=? AND status=1 AND job_status='COMPLETED'", [$customerId]);
                $isStammkunde = ($completed >= 5) || !empty($cust['legacy_pricing']);
                $isHostType = in_array($cust['customer_type'] ?? '', ['Airbnb','Host','Co-Host','Short-Term Rental','Booking','Company','B2B','Firma','GmbH','Business']);
            }
            // Für Preisberechnung: Host/Business wie Stammkunde behandeln (keine dynamischen Multiplikatoren)
            $fixedPriceMode = $isStammkunde || $isHostType;

            $TRAVEL_BUFFER_H = 0.5; // 30min Puffer zwischen Jobs für Anfahrt

            // 1. Active partners (mit location)
            $partners = all("SELECT emp_id, name, location FROM employee WHERE status=1");
            $totalPartners = count($partners);
            if ($totalPartners === 0) return ['date' => $date, 'slots' => [], 'message' => 'Keine Partner verfügbar'];

            // 2. Partner absences (Urlaub/Krank/Privat) — full-day blocks
            $absentPartners = [];
            try {
                $absences = all("SELECT emp_id_fk FROM employee_availability WHERE ? BETWEEN date_from AND date_to", [$date]);
                foreach ($absences as $a) $absentPartners[(int)$a['emp_id_fk']] = true;
            } catch (Exception $e) { /* table missing */ }

            // 2b. Geographic filter: partners too far from target address are excluded
            $farPartners = [];
            $geoDebug = ['target' => null, 'partner_distances' => []];
            if ($targetAddress !== '') {
                global $dbLocal;
                $dbLocal->exec("CREATE TABLE IF NOT EXISTS geocode_cache (
                    address VARCHAR(500) PRIMARY KEY, lat DOUBLE, lng DOUBLE, cached_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $geocodeLocal = function(string $addr) use ($dbLocal) {
                    if (empty(trim($addr))) return null;
                    $cached = $dbLocal->prepare("SELECT lat, lng FROM geocode_cache WHERE address=?");
                    $cached->execute([$addr]);
                    $row = $cached->fetch(PDO::FETCH_ASSOC);
                    if ($row) return ['lat' => (float)$row['lat'], 'lng' => (float)$row['lng']];
                    // Only append ", Berlin, Germany" if no PLZ and no city already in address
                    $hasPlz = preg_match('/\b\d{5}\b/', $addr);
                    $hasCity = preg_match('/\b(Berlin|Potsdam|Brandenburg|Hamburg|München|Köln|Frankfurt|Deutschland|Germany)\b/i', $addr);
                    $query = $addr . ($hasPlz || $hasCity ? ', Germany' : ', Berlin, Germany');
                    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
                        'q' => $query, 'format' => 'json', 'limit' => 1
                    ]);
                    $ctx = stream_context_create(['http' => ['header' => 'User-Agent: Fleckfrei-App/1.0', 'timeout' => 5]]);
                    $resp = @file_get_contents($url, false, $ctx);
                    if (!$resp) return null;
                    $data = json_decode($resp, true);
                    if (empty($data[0])) return null;
                    $lat = (float)$data[0]['lat']; $lng = (float)$data[0]['lon'];
                    $dbLocal->prepare("INSERT INTO geocode_cache (address, lat, lng) VALUES (?,?,?) ON DUPLICATE KEY UPDATE lat=?, lng=?, cached_at=NOW()")
                        ->execute([$addr, $lat, $lng, $lat, $lng]);
                    return ['lat' => $lat, 'lng' => $lng];
                };
                $haversine = function(float $lat1, float $lon1, float $lat2, float $lon2): float {
                    $R = 6371; $dLat = deg2rad($lat2 - $lat1); $dLon = deg2rad($lon2 - $lon1);
                    $a = sin($dLat/2)**2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2)**2;
                    return $R * 2 * atan2(sqrt($a), sqrt(1-$a));
                };
                $targetGeo = $geocodeLocal($targetAddress);
                $geoDebug['target'] = $targetGeo ? $targetAddress : 'geocoding failed';
                if ($targetGeo) {
                    foreach ($partners as $p) {
                        if (empty($p['location'])) continue;
                        $pGeo = $geocodeLocal($p['location']);
                        if (!$pGeo) continue;
                        $dist = $haversine($targetGeo['lat'], $targetGeo['lng'], $pGeo['lat'], $pGeo['lng']);
                        $geoDebug['partner_distances'][] = ['emp_id' => $p['emp_id'], 'name' => $p['name'], 'distance_km' => round($dist, 1)];
                        if ($dist > $MAX_TRAVEL_KM) {
                            $farPartners[(int)$p['emp_id']] = round($dist, 1);
                        }
                    }
                }
            }

            $effectivePartners = $totalPartners - count($absentPartners) - count($farPartners);

            // 3. All jobs for this date — ECHTZEIT-Tracking mit Partner Start/Stop
            $booked = all("SELECT emp_id_fk, j_time, j_hours, start_time, end_time, total_hours, job_status FROM jobs WHERE j_date=? AND status=1 AND job_status NOT IN ('CANCELLED')", [$date]);

            $jobsByPartner = []; // emp_id => array of [start, end]
            $unassignedJobs = [];
            $timeToDecimal = function($t) { return $t ? (int)substr($t,0,2) + ((int)substr($t,3,2)/60) : null; };

            foreach ($booked as $b) {
                // === REAL-TIME Verfügbarkeits-Berechnung ===
                // COMPLETED mit end_time: Partner ist SEIT end_time frei → Slot nach end_time+buffer buchbar
                // RUNNING mit start_time: nutze tatsächliche Start + ursprüngl. j_hours (aktuelle Schätzung)
                // Sonst: planmäßig j_time + j_hours
                $jobStart = null; $jobEnd = null;
                if ($b['job_status'] === 'COMPLETED' && !empty($b['end_time'])) {
                    // Tatsächlich erledigt: Partner frei seit end_time
                    $jobStart = $timeToDecimal($b['start_time'] ?: $b['j_time']);
                    $jobEnd = $timeToDecimal($b['end_time']);
                } elseif (in_array($b['job_status'], ['RUNNING','STARTED']) && !empty($b['start_time'])) {
                    // Läuft gerade: tatsächlicher Start + geplante Dauer (oder total_hours wenn gesetzt)
                    $jobStart = $timeToDecimal($b['start_time']);
                    $duration = $b['total_hours'] ?: ($b['j_hours'] ?: 2);
                    $jobEnd = $jobStart + (float)$duration;
                } else {
                    // PENDING/CONFIRMED: planmäßig
                    $jobStart = $timeToDecimal($b['j_time']);
                    $jobEnd = $jobStart + (float)($b['j_hours'] ?: 2);
                }
                if ($jobStart === null) continue;
                $jobEnd += $TRAVEL_BUFFER_H; // Fahrt zum nächsten Job
                $range = ['start' => $jobStart, 'end' => $jobEnd, 'status' => $b['job_status']];
                if (!empty($b['emp_id_fk'])) $jobsByPartner[(int)$b['emp_id_fk']][] = $range;
                else $unassignedJobs[] = $range;
            }

            // 4. Build slot availability
            $slots = [];
            for ($h = 7; $h <= 20; $h++) {
                $slotStart = $h;
                $slotEnd = $h + $reqHours;
                if ($slotEnd > 21) continue;

                // Busy partners in this slot (skip far-away partners — bereits abgezogen)
                $busyAssigned = [];
                foreach ($jobsByPartner as $empId => $ranges) {
                    if (isset($farPartners[$empId]) || isset($absentPartners[$empId])) continue;
                    foreach ($ranges as $r) {
                        if ($r['start'] < $slotEnd && $r['end'] > $slotStart) {
                            $busyAssigned[$empId] = true;
                            break;
                        }
                    }
                }

                // Unassigned jobs overlapping this slot consume 1 partner each
                $unassignedConsumed = 0;
                foreach ($unassignedJobs as $r) {
                    if ($r['start'] < $slotEnd && $r['end'] > $slotStart) $unassignedConsumed++;
                }

                $free = max(0, $effectivePartners - count($busyAssigned) - $unassignedConsumed);
                $status = $free <= 0 ? 'full' : ($free <= 1 ? 'limited' : 'available');

                // Dynamic pricing — basePrice ist NETTO (€/h)
                $slotNetto = null; $slotMwst = null; $slotBrutto = null;
                $taxRate = TAX_RATE; // 0.19
                if ($basePrice > 0) {
                    if ($fixedPriceMode) {
                        // Stammkunde + Host/Business: Festpreis, keine Rabatte/Aufschläge
                        $slotNetto = round($basePrice * $reqHours, 2);
                    } elseif ($free > 0) {
                        $mult = 1.0;
                        $wd = (int)date('w', strtotime($date));
                        if ($wd === 0 || $wd === 6) $mult *= 1.15;
                        $mon = (int)date('n', strtotime($date));
                        if ($mon >= 6 && $mon <= 8) $mult *= 1.10;
                        if ($mon === 12) $mult *= 1.25;
                        $occupancy = $effectivePartners > 0 ? (1 - $free / $effectivePartners) : 1;
                        if ($occupancy >= 0.8) $mult *= 1.20;
                        elseif ($occupancy < 0.3) $mult *= 0.90;
                        $mult *= 0.95; // Neukunden-Rabatt
                        $slotNetto = round($basePrice * $reqHours * $mult, 2);
                    }
                    if ($slotNetto !== null) {
                        $slotMwst = round($slotNetto * $taxRate, 2);
                        $slotBrutto = round($slotNetto + $slotMwst, 2);
                    }
                }

                $slots[] = [
                    'time' => sprintf('%02d:00', $h),
                    'end' => sprintf('%02d:00', (int)$slotEnd) . ($slotEnd != (int)$slotEnd ? ':30' : ''),
                    'free_partners' => $free,
                    'total_partners' => $effectivePartners,
                    'absent_today' => count($absentPartners),
                    'pending_assignment' => $unassignedConsumed,
                    'status' => $status,
                    'bookable' => $free > 0,
                    'price' => $slotNetto,       // Legacy — = netto
                    'price_netto' => $slotNetto,
                    'price_mwst' => $slotMwst,
                    'price_brutto' => $slotBrutto,
                    'tax_rate' => $taxRate
                ];
            }
            return [
                'date' => $date,
                'hours' => $reqHours,
                'is_stammkunde' => $isStammkunde,
                'is_host_type' => $isHostType,
                'fixed_price_mode' => $fixedPriceMode,
                'slots' => $slots,
                'stats' => [
                    'total_partners' => $totalPartners,
                    'effective_partners' => $effectivePartners,
                    'absent_today' => count($absentPartners),
                    'too_far' => count($farPartners),
                    'max_travel_km' => $MAX_TRAVEL_KM,
                    'travel_buffer_minutes' => $TRAVEL_BUFFER_H * 60,
                    'geo_check_active' => $targetAddress !== '',
                ],
                'geo_debug' => $targetAddress !== '' ? $geoDebug : null
            ];
        })(),

        // ============================================================
        // PARTNER-STATUS — wer ist gerade online / aktiv / frei
        // GET /api/index.php?action=partners/status
        // ============================================================
        // ============================================================
        // SMART BOOKING — Priorisiert STR-Kunden, schiebt Privatkunden
        // POST { customer_id, date, time, hours, s_id }
        // Returns: { booked: job_id } OR { conflict: true, proposals: [...] }
        // ============================================================
        $action === 'booking/smart' && $method === 'POST' => (function() use ($body) {
            global $db;
            $custId = (int)($body['customer_id'] ?? 0);
            $date = $body['date'] ?? '';
            $time = $body['time'] ?? '';
            $hours = (float)($body['hours'] ?? 2);
            $sId = (int)($body['s_id'] ?? 0);
            if (!$custId || !$date || !$time) throw new Exception('Need customer_id + date + time');

            // Kundentyp ermitteln
            $cust = one("SELECT customer_id, customer_type, name FROM customer WHERE customer_id=?", [$custId]);
            if (!$cust) throw new Exception('Customer not found');
            $cType = $cust['customer_type'] ?? 'Private Person';

            // Aktive Priority-Windows laden
            $windows = all("SELECT * FROM booking_priority_windows WHERE active=1");
            $isPrio = false; $activeWindow = null;
            foreach ($windows as $w) {
                $prioTypes = json_decode($w['priority_customer_types'], true) ?: [];
                if (in_array($cType, $prioTypes)) {
                    // Ist die Buchungszeit im Prio-Fenster?
                    if ($time >= $w['start_time'] && $time < $w['end_time']) {
                        $isPrio = true;
                        $activeWindow = $w;
                        break;
                    }
                }
            }

            // Prüfen ob Slot frei
            $slotStart = (int)substr($time, 0, 2) + ((int)substr($time, 3, 2) / 60);
            $slotEnd = $slotStart + $hours;
            $TRAVEL_BUFFER = 0.5;

            $existingJobs = all("SELECT j.j_id, j.j_time, j.j_hours, j.emp_id_fk, j.customer_id_fk, c.customer_type, c.name as cname
                FROM jobs j LEFT JOIN customer c ON j.customer_id_fk=c.customer_id
                WHERE j.j_date=? AND j.status=1 AND j.job_status NOT IN ('CANCELLED')", [$date]);

            $totalPartners = (int)val("SELECT COUNT(*) FROM employee WHERE status=1");
            $conflictingJobs = []; // jobs that overlap
            foreach ($existingJobs as $ej) {
                $jobStart = (int)substr($ej['j_time'], 0, 2) + ((int)substr($ej['j_time'], 3, 2) / 60);
                $jobEnd = $jobStart + (float)($ej['j_hours'] ?: 2) + $TRAVEL_BUFFER;
                if ($jobStart < $slotEnd && $jobEnd > $slotStart) {
                    $conflictingJobs[] = $ej;
                }
            }
            $freeCount = $totalPartners - count($conflictingJobs);

            // Einfacher Fall: Slot ist frei → direkt buchen
            if ($freeCount > 0) {
                q("INSERT INTO jobs (customer_id_fk, s_id_fk, j_date, j_time, j_hours, status, job_status, platform, created_at) VALUES (?,?,?,?,?,1,'PENDING','admin',NOW())",
                    [$custId, $sId, $date, $time, $hours]);
                $newJobId = $db->lastInsertId();
                audit('smart_book', 'job', $newJobId, "Direkt gebucht: {$cust['name']} {$date} {$time}");
                notifyEvent('customer', $custId, 'job_created', 'jobs', (int)$newJobId, "Neue Buchung #{$newJobId} für {$cust['name']} am {$date} um {$time}");
                return ['booked' => true, 'job_id' => (int)$newJobId, 'priority' => $isPrio, 'message' => 'Direkt gebucht'];
            }

            // Slot voll — ist Kunde Prio-Kunde?
            if (!$isPrio) {
                // Normaler Kunde + Slot voll → keine Magie, einfach ablehnen
                return ['booked' => false, 'conflict' => true, 'message' => 'Slot ausgebucht. Bitte andere Uhrzeit wählen.', 'free_partners' => 0];
            }

            // STR-Kunde + Slot voll → Shifting-Logik
            $shiftableTypes = json_decode($activeWindow['shiftable_customer_types'], true) ?: [];
            $proposals = [];
            foreach ($conflictingJobs as $ej) {
                if (!in_array($ej['customer_type'], $shiftableTypes)) continue; // Nicht-shiftbar (z.B. anderer STR-Kunde)

                // Versuche: ±30min, nächster Tag
                $shiftOptions = [];
                $origStart = (int)substr($ej['j_time'], 0, 2) + ((int)substr($ej['j_time'], 3, 2) / 60);
                $candidates = [];
                // +30min bis +2h schieben
                for ($m = 30; $m <= 120; $m += 30) {
                    $newStart = $origStart + ($m / 60);
                    $newEnd = $newStart + (float)$ej['j_hours'];
                    if ($newEnd > 20) continue; // Nicht nach 20 Uhr
                    // Check: im Prio-Fenster? Dann nicht verschieben
                    if ($newStart < (float)substr($activeWindow['end_time'], 0, 2)) {
                        $candidates[] = ['type' => 'same_day', 'time' => sprintf('%02d:%02d', floor($newStart), round(($newStart - floor($newStart)) * 60)), 'date' => $date, 'shift_min' => $m];
                    }
                }
                // -30min (vor Prio-Fenster)
                for ($m = 30; $m <= 120; $m += 30) {
                    $newStart = $origStart - ($m / 60);
                    if ($newStart < 7) continue;
                    if ($newStart + (float)$ej['j_hours'] > (float)substr($activeWindow['start_time'], 0, 2)) continue;
                    $candidates[] = ['type' => 'same_day_earlier', 'time' => sprintf('%02d:%02d', floor($newStart), round(($newStart - floor($newStart)) * 60)), 'date' => $date, 'shift_min' => -$m];
                }
                // Nächster Tag
                if ($activeWindow['allow_next_day']) {
                    $candidates[] = ['type' => 'next_day', 'time' => substr($ej['j_time'], 0, 5), 'date' => date('Y-m-d', strtotime($date . ' +1 day')), 'shift_min' => 1440];
                }

                $proposals[] = [
                    'conflict_job_id' => (int)$ej['j_id'],
                    'conflict_customer_id' => (int)$ej['customer_id_fk'],
                    'conflict_customer_name' => $ej['cname'],
                    'conflict_customer_type' => $ej['customer_type'],
                    'original_time' => substr($ej['j_time'], 0, 5),
                    'options' => $candidates
                ];
            }

            if (empty($proposals)) {
                // Alle Konflikte sind auch Prio-Kunden → keine Verschiebung möglich
                return [
                    'booked' => false,
                    'conflict' => true,
                    'blocked_by_priority' => true,
                    'message' => 'Alle belegten Slots sind andere STR-Kunden. Keine Verschiebung möglich. Admin kontaktieren.',
                    'fallback' => $activeWindow['fallback_mode']
                ];
            }

            // Auto-Shift: Erste Option jedes Konflikts als "pending_shifts" vorschlagen
            $pendingIds = [];
            foreach ($proposals as $p) {
                $best = $p['options'][0] ?? null;
                if (!$best) continue;
                q("INSERT INTO pending_shifts (job_id_fk, requested_by_customer_id, reason, original_date, original_time, proposed_date, proposed_time, shift_minutes, customer_response, expires_at)
                   VALUES (?,?,?,?,?,?,?,?,'pending', DATE_ADD(NOW(), INTERVAL 4 HOUR))",
                  [$p['conflict_job_id'], $custId, 'STR_PRIORITY', $date, $p['original_time'] . ':00',
                   $best['date'], $best['time'] . ':00', $best['shift_min']]);
                $pendingIds[] = $db->lastInsertId();

                // Notify betroffenen Kunden
                notifyEvent('admin', 0, 'shift_proposed', 'jobs', (int)$p['conflict_job_id'],
                    "Verschiebungs-Vorschlag für {$p['conflict_customer_name']}: {$date} {$p['original_time']} → {$best['date']} {$best['time']}");
            }

            // STR-Buchung als PENDING mit Flag "awaiting_shifts" anlegen
            q("INSERT INTO jobs (customer_id_fk, s_id_fk, j_date, j_time, j_hours, status, job_status, platform, job_note, created_at)
               VALUES (?,?,?,?,?,1,'PENDING','admin','⏳ Wartet auf Verschiebungs-Bestätigung anderer Kunden', NOW())",
              [$custId, $sId, $date, $time, $hours]);
            $strJobId = $db->lastInsertId();
            audit('smart_book_pending', 'job', $strJobId, "STR-Prio-Buchung pending, {count($pendingIds)} Shifts angefragt");

            telegramNotify("🔴 <b>STR-Priorität benötigt Verschiebung</b>\n\n👤 {$cust['name']} ({$cType})\n📅 {$date} um {$time}\n⏱ {$hours}h\n\n" .
                count($pendingIds) . " andere Kunden müssen verschoben werden.\n" .
                "Admin: /admin/pending-shifts.php");

            return [
                'booked' => false,
                'pending' => true,
                'str_job_id' => (int)$strJobId,
                'pending_shift_ids' => $pendingIds,
                'proposals' => $proposals,
                'message' => 'STR-Buchung registriert. ' . count($pendingIds) . ' andere Kunden werden um Verschiebung gebeten. Admin entscheidet bei Ablehnung.'
            ];
        })(),

        // ============================================================
        // PENDING SHIFTS — Admin/Customer Response
        // POST { ps_id, response: "accept"|"reject", admin_override?: bool }
        // ============================================================
        $action === 'shifts/respond' && $method === 'POST' => (function() use ($body) {
            global $db;
            $psId = (int)($body['ps_id'] ?? 0);
            $response = $body['response'] ?? '';
            $isAdmin = !empty($body['admin_override']);
            if (!$psId || !in_array($response, ['accept','reject'])) throw new Exception('Need ps_id + response');

            $ps = one("SELECT * FROM pending_shifts WHERE ps_id=?", [$psId]);
            if (!$ps) throw new Exception('Shift not found');
            if ($ps['customer_response'] !== 'pending' && !$isAdmin) throw new Exception('Already decided');

            q("UPDATE pending_shifts SET customer_response=?, admin_override=?, responded_at=NOW() WHERE ps_id=?",
              [$response === 'accept' ? 'accepted' : 'rejected', $isAdmin ? 1 : 0, $psId]);

            if ($response === 'accept') {
                // Job verschieben
                q("UPDATE jobs SET j_date=?, j_time=?, job_note=CONCAT(IFNULL(job_note,''), '\n[verschoben STR-Prio ', NOW(), ']') WHERE j_id=?",
                  [$ps['proposed_date'], $ps['proposed_time'], $ps['job_id_fk']]);
                audit('shift_accepted', 'job', $ps['job_id_fk'], "→ {$ps['proposed_date']} {$ps['proposed_time']}");
                notifyEvent('customer', 0, 'job_rescheduled', 'jobs', (int)$ps['job_id_fk'], "Termin verschoben auf {$ps['proposed_date']} {$ps['proposed_time']}");
            } else {
                // Admin kriegt Eskalation
                telegramNotify("🚨 <b>STR-Shift ABGELEHNT</b>\n\nJob #{$ps['job_id_fk']} sollte auf {$ps['proposed_date']} {$ps['proposed_time']} verschoben werden — Kunde lehnt ab.\n\nFallback: MANUELLE AKTION nötig.");
                audit('shift_rejected', 'job', $ps['job_id_fk'], "Kunde lehnt ab");
            }

            return ['ps_id' => $psId, 'response' => $response];
        })(),

        $action === 'partners/status' && $method === 'GET' => (function() {
            $today = date('Y-m-d');
            $nowH = (int)date('H') + ((int)date('i') / 60);
            $partners = all("SELECT e.emp_id, e.name, e.surname, e.phone FROM employee e WHERE e.status=1 ORDER BY e.name");
            $out = [];
            foreach ($partners as $p) {
                $running = one("SELECT j_id, j_time, start_time, j_hours, total_hours, address FROM jobs WHERE emp_id_fk=? AND j_date=? AND status=1 AND job_status IN ('RUNNING','STARTED') LIMIT 1", [$p['emp_id'], $today]);
                $next = one("SELECT j_id, j_time, address FROM jobs WHERE emp_id_fk=? AND j_date=? AND status=1 AND job_status IN ('PENDING','CONFIRMED') ORDER BY j_time LIMIT 1", [$p['emp_id'], $today]);
                $lastDone = one("SELECT j_id, end_time, total_hours FROM jobs WHERE emp_id_fk=? AND j_date=? AND status=1 AND job_status='COMPLETED' AND end_time IS NOT NULL ORDER BY end_time DESC LIMIT 1", [$p['emp_id'], $today]);

                $status = 'offline'; $statusText = 'Kein Job heute';
                $freeAt = null; // Zeit ab wann frei

                if ($running) {
                    $status = 'working';
                    $startH = (int)substr($running['start_time'] ?: $running['j_time'], 0, 2) + ((int)substr($running['start_time'] ?: $running['j_time'], 3, 2) / 60);
                    $duration = $running['total_hours'] ?: $running['j_hours'] ?: 2;
                    $expectedEnd = $startH + $duration;
                    $statusText = 'Arbeitet seit ' . substr($running['start_time'] ?: $running['j_time'], 0, 5) . ' — Ende ca. ' . sprintf('%02d:%02d', floor($expectedEnd), round(($expectedEnd - floor($expectedEnd)) * 60));
                    $freeAt = sprintf('%02d:%02d', floor($expectedEnd + 0.5), round((($expectedEnd + 0.5) - floor($expectedEnd + 0.5)) * 60));
                } elseif ($next) {
                    $nextH = (int)substr($next['j_time'], 0, 2) + ((int)substr($next['j_time'], 3, 2) / 60);
                    $diff = $nextH - $nowH;
                    if ($diff < 0) {
                        // Job liegt in der Vergangenheit, wurde aber nicht gestartet → no-show / verpasst
                        $status = 'overdue';
                        $statusText = 'Verpasst: ' . substr($next['j_time'], 0, 5) . ' — nicht gestartet';
                    } elseif ($diff <= 0.5) {
                        $status = 'starting_soon';
                        $statusText = 'Startet gleich: ' . substr($next['j_time'], 0, 5);
                    } else {
                        $status = 'available';
                        $statusText = 'Frei bis ' . substr($next['j_time'], 0, 5);
                        $freeAt = date('H:i');
                    }
                } elseif ($lastDone) {
                    $status = 'available';
                    $statusText = 'Letzter Job erledigt ' . substr($lastDone['end_time'], 0, 5);
                    $freeAt = substr($lastDone['end_time'], 0, 5);
                } else {
                    $status = 'available';
                    $statusText = 'Frei, kein Job geplant';
                    $freeAt = date('H:i');
                }

                $out[] = [
                    'emp_id' => (int)$p['emp_id'],
                    'name' => trim($p['name'] . ' ' . ($p['surname'] ?? '')),
                    'phone' => $p['phone'],
                    'status' => $status,
                    'status_text' => $statusText,
                    'free_at' => $freeAt,
                    'current_job_id' => $running['j_id'] ?? null,
                    'next_job_id' => $next['j_id'] ?? null,
                    'last_completed_id' => $lastDone['j_id'] ?? null,
                    'current_address' => $running['address'] ?? null,
                    'next_address' => $next['address'] ?? null,
                ];
            }
            // Zusammenfassung
            $counts = ['working'=>0, 'starting_soon'=>0, 'available'=>0, 'offline'=>0, 'overdue'=>0];
            foreach ($out as $p) { if (isset($counts[$p['status']])) $counts[$p['status']]++; }
            return [
                'date' => $today,
                'now' => date('H:i'),
                'partners' => $out,
                'summary' => $counts,
                'total' => count($out)
            ];
        })(),

        // ============================================================
        // TIMESLOTS RANGE — 7/14 day overview (for calendar heatmap)
        // GET /api/index.php?action=timeslots/range&from=2026-04-15&days=14&hours=3&address=...
        // Returns compact per-day availability: max_free, full_count, status
        // ============================================================
        $action === 'timeslots/range' && $method === 'GET' => (function() {
            $from = $_GET['from'] ?? date('Y-m-d');
            $days = min(31, max(1, (int)($_GET['days'] ?? 14)));
            $reqHours = max(MIN_HOURS, (float)($_GET['hours'] ?? 2));
            $targetAddress = trim($_GET['address'] ?? '');
            $customerId = (int)($_GET['customer_id'] ?? 0);
            $basePrice = (float)($_GET['base_price'] ?? 0); // Stundenpreis €/h
            $MAX_TRAVEL_KM = (int) (val("SELECT max_distance_km FROM settings LIMIT 1") ?: 30);
            $TRAVEL_BUFFER_H = 0.5;

            // Stammkunden-Check + Host/Airbnb = beide Festpreis
            $isStammkunde = false; $isHostType = false;
            if ($customerId) {
                $cust = one("SELECT legacy_pricing, customer_type FROM customer WHERE customer_id=?", [$customerId]);
                $completed = (int)val("SELECT COUNT(*) FROM jobs WHERE customer_id_fk=? AND status=1 AND job_status='COMPLETED'", [$customerId]);
                $isStammkunde = ($completed >= 5) || !empty($cust['legacy_pricing']);
                $isHostType = in_array($cust['customer_type'] ?? '', ['Airbnb','Host','Co-Host','Short-Term Rental','Booking','Company','B2B','Firma','GmbH','Business']);
            }
            $fixedPriceMode = $isStammkunde || $isHostType;

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) throw new Exception('Invalid from date');

            $partners = all("SELECT emp_id, name, location FROM employee WHERE status=1");
            $totalPartners = count($partners);

            // Shared geo check (once for all days)
            $farPartners = [];
            if ($targetAddress !== '' && $totalPartners > 0) {
                global $dbLocal;
                $dbLocal->exec("CREATE TABLE IF NOT EXISTS geocode_cache (address VARCHAR(500) PRIMARY KEY, lat DOUBLE, lng DOUBLE, cached_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $geocodeLocal = function(string $addr) use ($dbLocal) {
                    if (empty(trim($addr))) return null;
                    $cached = $dbLocal->prepare("SELECT lat, lng FROM geocode_cache WHERE address=?");
                    $cached->execute([$addr]);
                    $row = $cached->fetch(PDO::FETCH_ASSOC);
                    if ($row) return ['lat' => (float)$row['lat'], 'lng' => (float)$row['lng']];
                    $hasPlz = preg_match('/\b\d{5}\b/', $addr);
                    $hasCity = preg_match('/\b(Berlin|Potsdam|Brandenburg|Hamburg|München|Köln|Frankfurt|Deutschland|Germany)\b/i', $addr);
                    $query = $addr . ($hasPlz || $hasCity ? ', Germany' : ', Berlin, Germany');
                    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query(['q' => $query, 'format' => 'json', 'limit' => 1]);
                    $ctx = stream_context_create(['http' => ['header' => 'User-Agent: Fleckfrei-App/1.0', 'timeout' => 5]]);
                    $resp = @file_get_contents($url, false, $ctx);
                    if (!$resp) return null;
                    $data = json_decode($resp, true);
                    if (empty($data[0])) return null;
                    $lat = (float)$data[0]['lat']; $lng = (float)$data[0]['lon'];
                    $dbLocal->prepare("INSERT INTO geocode_cache (address, lat, lng) VALUES (?,?,?) ON DUPLICATE KEY UPDATE lat=?, lng=?, cached_at=NOW()")->execute([$addr, $lat, $lng, $lat, $lng]);
                    return ['lat' => $lat, 'lng' => $lng];
                };
                $hav = function(float $lat1, float $lon1, float $lat2, float $lon2): float {
                    $R = 6371; $dLat = deg2rad($lat2 - $lat1); $dLon = deg2rad($lon2 - $lon1);
                    $a = sin($dLat/2)**2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2)**2;
                    return $R * 2 * atan2(sqrt($a), sqrt(1-$a));
                };
                $targetGeo = $geocodeLocal($targetAddress);
                if ($targetGeo) {
                    foreach ($partners as $p) {
                        if (empty($p['location'])) continue;
                        $pGeo = $geocodeLocal($p['location']);
                        if (!$pGeo) continue;
                        $dist = $hav($targetGeo['lat'], $targetGeo['lng'], $pGeo['lat'], $pGeo['lng']);
                        if ($dist > $MAX_TRAVEL_KM) $farPartners[(int)$p['emp_id']] = round($dist, 1);
                    }
                }
            }

            // Absences over the whole range (bulk fetch)
            $toDate = date('Y-m-d', strtotime($from . " +{$days} days"));
            $absencesRaw = [];
            try {
                $absencesRaw = all("SELECT emp_id_fk, date_from, date_to FROM employee_availability WHERE date_to >= ? AND date_from <= ?", [$from, $toDate]);
            } catch (Exception $e) {}

            // Bulk fetch jobs in range
            $jobsRaw = all("SELECT j_date, emp_id_fk, j_time, COALESCE(j_hours, 2) as j_hours FROM jobs WHERE j_date BETWEEN ? AND ? AND status=1 AND job_status NOT IN ('CANCELLED')", [$from, $toDate]);
            $jobsByDate = [];
            foreach ($jobsRaw as $j) $jobsByDate[$j['j_date']][] = $j;

            $today = date('Y-m-d');
            $out = [];
            for ($i = 0; $i < $days; $i++) {
                $d = date('Y-m-d', strtotime("$from +$i days"));
                if ($d < $today) { continue; }

                // Absent partners for this specific day
                $absentToday = [];
                foreach ($absencesRaw as $a) {
                    if ($d >= $a['date_from'] && $d <= $a['date_to']) $absentToday[(int)$a['emp_id_fk']] = true;
                }
                $effective = $totalPartners - count($absentToday) - count(array_diff_key($farPartners, $absentToday));

                if ($effective <= 0) {
                    $out[] = ['date' => $d, 'weekday' => date('w', strtotime($d)), 'max_free' => 0, 'avg_free' => 0, 'full_hours' => 14, 'status' => 'full'];
                    continue;
                }

                // Build busy ranges for this day
                $jobsByPartnerDay = []; $unassignedDay = [];
                foreach ($jobsByDate[$d] ?? [] as $b) {
                    $s = (int)substr($b['j_time'], 0, 2) + ((int)substr($b['j_time'], 3, 2) / 60);
                    $e = $s + (float)$b['j_hours'] + $TRAVEL_BUFFER_H;
                    $range = ['start' => $s, 'end' => $e];
                    if (!empty($b['emp_id_fk'])) $jobsByPartnerDay[(int)$b['emp_id_fk']][] = $range;
                    else $unassignedDay[] = $range;
                }

                // Compute free count per hour slot, track max/avg + available_slots list
                $hourly = []; $maxFree = 0; $fullHours = 0; $sumFree = 0; $countSlots = 0;
                $slotsAvailable = []; // [{time, free, price}]
                for ($h = 7; $h <= 20; $h++) {
                    if ($h + $reqHours > 21) continue;
                    $busy = [];
                    foreach ($jobsByPartnerDay as $eid => $ranges) {
                        if (isset($farPartners[$eid]) || isset($absentToday[$eid])) continue;
                        foreach ($ranges as $r) { if ($r['start'] < $h + $reqHours && $r['end'] > $h) { $busy[$eid] = true; break; } }
                    }
                    $unassigned = 0;
                    foreach ($unassignedDay as $r) { if ($r['start'] < $h + $reqHours && $r['end'] > $h) $unassigned++; }
                    $free = max(0, $effective - count($busy) - $unassigned);
                    $hourly[$h] = $free;
                    $maxFree = max($maxFree, $free);
                    $sumFree += $free;
                    $countSlots++;
                    if ($free === 0) $fullHours++;
                    if ($free > 0) {
                        // Dynamic pricing calculation
                        $dayPrice = null;
                        if ($basePrice > 0) {
                            if ($fixedPriceMode) {
                                // Stammkunde + Host/Business: Festpreis
                                $dayPrice = round($basePrice * $reqHours, 2);
                            } else {
                                // Neukunde: dynamische Preise basierend auf Auslastung
                                $mult = 1.0;
                                $wd = (int)date('w', strtotime($d));
                                // Wochenend-Aufschlag
                                if ($wd === 0 || $wd === 6) $mult *= 1.15;
                                // Sommer-Aufschlag (Jun-Aug)
                                $mon = (int)date('n', strtotime($d));
                                if ($mon >= 6 && $mon <= 8) $mult *= 1.10;
                                if ($mon === 12) $mult *= 1.25;
                                // Auslastungs-basiert: wenige Partner frei = mehr Preis
                                $occupancy = $effective > 0 ? (1 - $free / $effective) : 1;
                                if ($occupancy >= 0.8) $mult *= 1.20; // hohe Auslastung
                                elseif ($occupancy < 0.3) $mult *= 0.90; // niedrige Auslastung = Rabatt
                                // Neukunden-Rabatt
                                $mult *= 0.95;
                                $dayPrice = round($basePrice * $reqHours * $mult, 2);
                            }
                        }
                        $slotsAvailable[] = [
                            'time' => sprintf('%02d:00', $h),
                            'free' => $free,
                            'price' => $dayPrice
                        ];
                    }
                }
                $avgFree = $countSlots > 0 ? round($sumFree / $countSlots, 1) : 0;
                $status = $maxFree <= 0 ? 'full' : ($maxFree <= 1 ? 'limited' : ($avgFree >= 2 ? 'great' : 'available'));

                // Top 3 beste Slots des Tages (die mit meisten free partners)
                usort($slotsAvailable, fn($a, $b) => $b['free'] <=> $a['free']);
                $topSlots = array_slice($slotsAvailable, 0, 4);
                // Zurück-sortieren chronologisch
                usort($topSlots, fn($a, $b) => strcmp($a['time'], $b['time']));

                $minPrice = null; $maxPrice = null;
                if (!empty($slotsAvailable) && $basePrice > 0) {
                    $prices = array_filter(array_column($slotsAvailable, 'price'));
                    if (!empty($prices)) { $minPrice = min($prices); $maxPrice = max($prices); }
                }

                $out[] = [
                    'date' => $d,
                    'weekday' => (int)date('w', strtotime($d)),
                    'max_free' => $maxFree,
                    'avg_free' => $avgFree,
                    'full_hours' => $fullHours,
                    'total_hours' => $countSlots,
                    'status' => $status,
                    'top_slots' => $topSlots,
                    'all_slots' => $slotsAvailable,
                    'min_price' => $minPrice,
                    'max_price' => $maxPrice
                ];
            }
            return [
                'from' => $from,
                'days' => $days,
                'hours' => $reqHours,
                'total_partners' => $totalPartners,
                'too_far' => count($farPartners),
                'is_stammkunde' => $isStammkunde,
                'base_price' => $basePrice,
                'daily' => $out
            ];
        })(),

        // ============================================================
        // ROUTE OPTIMIZATION — Best order for employee's daily jobs
        // GET /api/index.php?action=route/optimize&emp_id=5&date=2026-04-15
        // Uses simple nearest-neighbor with cached geocoding
        // ============================================================
        $action === 'route/optimize' && $method === 'GET' => (function() {
            $empId = (int)($_GET['emp_id'] ?? 0);
            $date = $_GET['date'] ?? date('Y-m-d');
            if (!$empId) throw new Exception('Need emp_id');

            $jobs = all("SELECT j.j_id, j.j_date, j.j_time, j.j_hours, j.address, j.job_status,
                         s.street, s.city, s.postal_code, c.name as cname, s.title as stitle
                         FROM jobs j LEFT JOIN services s ON j.s_id_fk=s.s_id LEFT JOIN customer c ON j.customer_id_fk=c.customer_id
                         WHERE j.emp_id_fk=? AND j.j_date=? AND j.status=1 AND j.job_status NOT IN ('CANCELLED')
                         ORDER BY j.j_time", [$empId, $date]);

            if (count($jobs) <= 1) {
                return ['emp_id' => $empId, 'date' => $date, 'jobs' => $jobs, 'optimized' => false, 'reason' => 'Nur 0-1 Jobs, keine Optimierung nötig'];
            }

            // Resolve addresses
            foreach ($jobs as &$j) {
                $j['full_address'] = $j['address'] ?: trim(($j['street'] ?? '') . ', ' . ($j['postal_code'] ?? '') . ' ' . ($j['city'] ?? ''));
            }
            unset($j);

            // Geocode via Nominatim (cached in DB)
            global $dbLocal;
            $dbLocal->exec("CREATE TABLE IF NOT EXISTS geocode_cache (
                address VARCHAR(500) PRIMARY KEY, lat DOUBLE, lng DOUBLE, cached_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            function geocodeAddress(string $addr): ?array {
                global $dbLocal;
                if (empty(trim($addr))) return null;
                $cached = $dbLocal->prepare("SELECT lat, lng FROM geocode_cache WHERE address=?");
                $cached->execute([$addr]);
                $row = $cached->fetch(PDO::FETCH_ASSOC);
                if ($row) return ['lat' => (float)$row['lat'], 'lng' => (float)$row['lng']];
                // Geocode via Nominatim
                $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
                    'q' => $addr . ', Berlin, Germany', 'format' => 'json', 'limit' => 1
                ]);
                $ctx = stream_context_create(['http' => ['header' => 'User-Agent: Fleckfrei-App/1.0', 'timeout' => 5]]);
                $resp = @file_get_contents($url, false, $ctx);
                if (!$resp) return null;
                $data = json_decode($resp, true);
                if (empty($data[0])) return null;
                $lat = (float)$data[0]['lat'];
                $lng = (float)$data[0]['lon'];
                $dbLocal->prepare("INSERT INTO geocode_cache (address, lat, lng) VALUES (?,?,?) ON DUPLICATE KEY UPDATE lat=?, lng=?, cached_at=NOW()")
                    ->execute([$addr, $lat, $lng, $lat, $lng]);
                return ['lat' => $lat, 'lng' => $lng];
            }

            // Geocode all jobs
            $coords = [];
            foreach ($jobs as $i => $j) {
                $geo = geocodeAddress($j['full_address']);
                $coords[$i] = $geo;
                $jobs[$i]['lat'] = $geo['lat'] ?? null;
                $jobs[$i]['lng'] = $geo['lng'] ?? null;
            }

            // Haversine distance
            function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float {
                $R = 6371;
                $dLat = deg2rad($lat2 - $lat1);
                $dLon = deg2rad($lon2 - $lon1);
                $a = sin($dLat/2)**2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2)**2;
                return $R * 2 * atan2(sqrt($a), sqrt(1-$a));
            }

            // Nearest-neighbor optimization
            $n = count($jobs);
            $allGeo = array_filter($coords, fn($c) => $c !== null);
            if (count($allGeo) < 2) {
                return ['emp_id' => $empId, 'date' => $date, 'jobs' => $jobs, 'optimized' => false, 'reason' => 'Nicht genug Geo-Daten'];
            }

            $visited = [0 => true]; // start with first job
            $order = [0];
            $totalDist = 0;
            $current = 0;
            for ($step = 1; $step < $n; $step++) {
                $bestDist = PHP_FLOAT_MAX;
                $bestIdx = -1;
                for ($j = 0; $j < $n; $j++) {
                    if (isset($visited[$j]) || !$coords[$current] || !$coords[$j]) continue;
                    $d = haversine($coords[$current]['lat'], $coords[$current]['lng'], $coords[$j]['lat'], $coords[$j]['lng']);
                    if ($d < $bestDist) { $bestDist = $d; $bestIdx = $j; }
                }
                if ($bestIdx === -1) break;
                $visited[$bestIdx] = true;
                $order[] = $bestIdx;
                $totalDist += $bestDist;
                $current = $bestIdx;
            }

            // Build optimized list
            $optimized = [];
            $suggestedTimes = [];
            $currentTime = null;
            foreach ($order as $seq => $idx) {
                $j = $jobs[$idx];
                if ($seq === 0) {
                    $currentTime = strtotime($j['j_time']);
                } else {
                    // Add travel buffer (15min per job transition)
                    $prevEnd = $currentTime + ($jobs[$order[$seq-1]]['j_hours'] * 3600);
                    $currentTime = $prevEnd + 900; // 15min buffer
                }
                $j['suggested_time'] = date('H:i', $currentTime);
                $j['sequence'] = $seq + 1;
                $optimized[] = $j;
            }

            // Build Google Maps route link
            $waypoints = [];
            foreach ($order as $idx) {
                if ($jobs[$idx]['lat'] && $jobs[$idx]['lng']) {
                    $waypoints[] = $jobs[$idx]['lat'] . ',' . $jobs[$idx]['lng'];
                }
            }
            $mapsUrl = '';
            if (count($waypoints) >= 2) {
                $origin = array_shift($waypoints);
                $dest = array_pop($waypoints);
                $mapsUrl = 'https://www.google.com/maps/dir/' . $origin . '/' . implode('/', $waypoints) . '/' . $dest;
            }

            return [
                'emp_id' => $empId,
                'date' => $date,
                'total_jobs' => $n,
                'total_distance_km' => round($totalDist, 1),
                'estimated_travel_min' => round($totalDist * 3), // ~20km/h Berlin average
                'optimized_order' => $optimized,
                'maps_url' => $mapsUrl,
                'optimized' => true
            ];
        })(),

        // Checkliste für Job (Kunden-View: titel + completion status)
        $action === 'checklist/for-job' && $method === 'GET' => (function() {
            $jid = (int)($_GET['j_id'] ?? 0);
            if (!$jid) throw new Exception('Need j_id');
            $job = one("SELECT j.j_id, j.s_id_fk, j.customer_id_fk FROM jobs j WHERE j.j_id=?", [$jid]);
            if (!$job) throw new Exception('Job not found');
            $items = [];
            try {
                $items = all("SELECT cl.checklist_id, cl.title, cl.room, cl.priority,
                    COALESCE(cc.completed, 0) AS completed, cc.photo
                    FROM service_checklists cl
                    LEFT JOIN checklist_completions cc ON cc.checklist_id_fk=cl.checklist_id AND cc.job_id_fk=?
                    WHERE cl.s_id_fk=? AND cl.is_active=1
                    ORDER BY cl.position, cl.checklist_id", [$jid, $job['s_id_fk']]);
            } catch (Exception $e) {}
            return ['job_id' => $jid, 'items' => $items, 'total' => count($items), 'completed' => count(array_filter($items, fn($i) => $i['completed']))];
        })(),

        default => throw new Exception("Unknown: $action")
    };
    echo json_encode(['success'=>true, 'data'=>$result]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success'=>false, 'error'=>$e->getMessage()]);
}

/**
 * Smoobu API helper
 */
function smoobuApi(string $endpoint, string $method = 'GET', ?array $data = null): array {
    $url = SMOOBU_BASE . $endpoint;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Api-Key: ' . SMOOBU_API_KEY,
            'Content-Type: application/json',
            'Cache-Control: no-cache',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($httpCode < 200 || $httpCode >= 300) {
        throw new Exception("Smoobu API Error ($httpCode): " . ($err ?: substr($resp, 0, 200)));
    }
    return json_decode($resp, true) ?: [];
}

/**
 * Parse iCal date string into ['date' => 'Y-m-d', 'time' => 'H:i']
 * Handles: 20260415T140000Z, 20260415T140000, 20260415
 */
function icalParseDate(string $dt): ?array {
    $dt = trim($dt);
    if (!$dt) return null;
    // Full datetime: 20260415T140000Z or 20260415T140000
    if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z?$/', $dt, $m)) {
        $ts = gmmktime((int)$m[4], (int)$m[5], (int)$m[6], (int)$m[2], (int)$m[3], (int)$m[1]);
        // If Z (UTC), convert to Berlin
        if (str_ends_with(trim($dt), 'Z')) {
            $d = new DateTime('@' . $ts);
            $d->setTimezone(new DateTimeZone('Europe/Berlin'));
            return ['date' => $d->format('Y-m-d'), 'time' => $d->format('H:i')];
        }
        return ['date' => "{$m[1]}-{$m[2]}-{$m[3]}", 'time' => "{$m[4]}:{$m[5]}"];
    }
    // Date only: 20260415
    if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $dt, $m)) {
        return ['date' => "{$m[1]}-{$m[2]}-{$m[3]}", 'time' => '00:00'];
    }
    return null;
}
