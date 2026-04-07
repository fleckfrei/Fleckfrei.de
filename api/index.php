<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/email.php';
require_once __DIR__ . '/../includes/openbanking.php';

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

        // Create job (with recurring support)
        $action === 'jobs' && $method === 'POST' => (function() use ($body) {
            $d = $body;
            foreach (['customer_id_fk','j_date','j_time','j_hours'] as $r) { if (empty($d[$r])) throw new Exception("Missing: $r"); }
            global $db;
            $jobFor = $d['job_for'] ?? '';
            $recurGroup = $jobFor ? uniqid('rec_') : null;

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
            return ['j_id' => $ids[0], 'total_created' => count($ids), 'recurring' => $jobFor ? true : false, 'dates_until' => end($dates)];
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
            $allowed = ['j_date','j_time','j_hours','customer_id_fk','s_id_fk','address','code_door','platform','job_for','emp_message','job_note','job_status','no_people'];
            if (!in_array($body['field'], $allowed)) throw new Exception('Field not editable: '.$body['field']);
            $val = $body['value'] ?: null;
            q("UPDATE jobs SET {$body['field']}=? WHERE j_id=?", [$val, $jid]);
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
            if ($body['status'] === 'COMPLETED') notifyJobCompleted($body['j_id']);

            audit('status_change', 'job', $body['j_id'], 'Status: '.$body['status']);
            return ['j_id'=>$body['j_id'], 'new_status'=>$body['status']];
        })(),

        // Auto-generate invoice from completed jobs
        $action === 'invoice/generate' && $method === 'POST' => (function() use ($body) {
            global $db;
            $custId = $body['customer_id'] ?? null;
            $month = $body['month'] ?? date('Y-m'); // e.g. "2026-04"
            if (!$custId) throw new Exception('Need customer_id');

            // Get completed jobs without invoice for this customer in this month
            $jobs = all("SELECT j.*, s.total_price as sprice FROM jobs j LEFT JOIN services s ON j.s_id_fk=s.s_id
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

        // CSV Export
        $action === 'export/workhours' && $method === 'GET' => (function() {
            $custId = $_GET['customer_id'] ?? '';
            $month = $_GET['month'] ?? date('Y-m');
            $sql = "SELECT j.j_date, j.j_time, j.j_hours, j.total_hours, j.job_status,
                    c.name as cname, e.name as ename, e.surname as esurname, s.title as stitle, s.total_price as sprice
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
            $allowed = ['issue_date','invoice_paid','remaining_price','total_price','start_date','end_date','invoice_number'];
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

        // Employee status update
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

        // Bank Export: CSV download of bank transactions
        $action === 'bank/export' && $method === 'GET' => (function() {
            $month = $_GET['month'] ?? date('Y-m');
            $start = $month . '-01';
            $end = date('Y-m-t', strtotime($start));
            $payments = all("SELECT ip.*, i.invoice_number, c.name as cname
                FROM invoice_payments ip
                LEFT JOIN invoices i ON ip.invoice_id_fk=i.inv_id
                LEFT JOIN customer c ON i.customer_id_fk=c.customer_id
                WHERE ip.payment_date BETWEEN ? AND ?
                ORDER BY ip.payment_date", [$start, $end]);
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

        default => throw new Exception("Unknown: $action")
    };
    echo json_encode(['success'=>true, 'data'=>$result]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success'=>false, 'error'=>$e->getMessage()]);
}
