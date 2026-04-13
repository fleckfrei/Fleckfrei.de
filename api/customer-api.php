<?php
/**
 * Kunden-API (token-basiert) — pro Kunde eigener API-Key.
 * Kunde kann eigene Daten abrufen/buchen/ändern.
 *
 * Usage: GET /api/customer-api.php?token=XXX&action=jobs
 *        POST /api/customer-api.php?token=XXX&action=book   (JSON body)
 *
 * Actions:
 *   jobs         — Liste eigener Jobs (optional ?from=YYYY-MM-DD&to=YYYY-MM-DD)
 *   job/{id}     — Detail eines Jobs (nur eigene)
 *   services     — Liste eigener Services/Properties
 *   invoices     — Liste eigener Rechnungen
 *   me           — Kundendaten (Name, Typ, Consent-Status)
 *   book         — Neue Buchung (POST mit JSON: service_id, date, time, hours, notes)
 *   cancel/{id}  — Job stornieren
 */
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-Customer-Token, Content-Type');

try {
    // Token aus Header ODER Query-Param
    $token = $_SERVER['HTTP_X_CUSTOMER_TOKEN'] ?? $_GET['token'] ?? $_POST['token'] ?? '';
    if (!$token) throw new Exception('Need token (X-Customer-Token header or ?token=)');

    $cust = one("SELECT customer_id, name, customer_type, api_access_blocked FROM customer WHERE api_token=? AND status=1 LIMIT 1", [$token]);
    if (!$cust) { http_response_code(401); throw new Exception('Invalid token'); }
    if (!empty($cust['api_access_blocked'])) { http_response_code(403); throw new Exception('API-Zugriff vom Admin gesperrt'); }

    $cid = (int)$cust['customer_id'];
    // Metrics
    q("UPDATE customer SET api_last_used=NOW(), api_requests_count=api_requests_count+1 WHERE customer_id=?", [$cid]);

    $action = $_GET['action'] ?? 'me';
    $method = $_SERVER['REQUEST_METHOD'];
    $body = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

    // Parse /action/id
    $parts = explode('/', $action);
    $action0 = $parts[0];
    $actionId = isset($parts[1]) ? (int)$parts[1] : 0;

    $result = match(true) {
        $action0 === 'me' => (function() use ($cust, $cid) {
            $stats = one("SELECT COUNT(*) as total_jobs, SUM(job_status='COMPLETED') as done, SUM(job_status='PENDING') as pending FROM jobs WHERE customer_id_fk=? AND status=1", [$cid]);
            return ['customer' => $cust, 'stats' => $stats];
        })(),

        $action0 === 'jobs' && $method === 'GET' => (function() use ($cid) {
            $from = $_GET['from'] ?? date('Y-m-01');
            $to = $_GET['to'] ?? date('Y-m-t', strtotime('+1 month'));
            $jobs = all("SELECT j.j_id, j.j_date, j.j_time, j.j_hours, j.job_status, j.address, j.no_people, j.no_children, j.no_pets, s.title as service_title
                FROM jobs j LEFT JOIN services s ON j.s_id_fk=s.s_id
                WHERE j.customer_id_fk=? AND j.status=1 AND j.j_date BETWEEN ? AND ?
                ORDER BY j.j_date DESC, j.j_time", [$cid, $from, $to]);
            return ['jobs' => $jobs, 'count' => count($jobs), 'range' => ['from' => $from, 'to' => $to]];
        })(),

        $action0 === 'job' && $actionId > 0 => (function() use ($cid, $actionId) {
            $job = one("SELECT j.*, s.title as service_title, e.display_name as partner_name
                FROM jobs j LEFT JOIN services s ON j.s_id_fk=s.s_id LEFT JOIN employee e ON j.emp_id_fk=e.emp_id
                WHERE j.j_id=? AND j.customer_id_fk=? AND j.status=1", [$actionId, $cid]);
            if (!$job) throw new Exception('Job not found or not yours');
            return ['job' => $job];
        })(),

        $action0 === 'services' && $method === 'GET' => (function() use ($cid) {
            return ['services' => all("SELECT s_id, title, street, number, postal_code, city, price, total_price, max_guests, wa_keyword FROM services WHERE customer_id_fk=? AND status=1", [$cid])];
        })(),

        $action0 === 'invoices' && $method === 'GET' => (function() use ($cid) {
            return ['invoices' => all("SELECT inv_id, invoice_number, issue_date, total_price, remaining_price, invoice_paid FROM invoices WHERE customer_id_fk=? ORDER BY issue_date DESC LIMIT 50", [$cid])];
        })(),

        $action0 === 'book' && $method === 'POST' => (function() use ($cid, $body) {
            global $db;
            foreach (['service_id','date','time','hours'] as $f) { if (empty($body[$f])) throw new Exception("Missing: $f"); }
            // Service gehört Kunde?
            $svc = one("SELECT s_id, customer_id_fk FROM services WHERE s_id=? AND status=1", [(int)$body['service_id']]);
            if (!$svc || ($svc['customer_id_fk'] && $svc['customer_id_fk'] != $cid)) throw new Exception('Service not yours');
            q("INSERT INTO jobs (customer_id_fk, s_id_fk, j_date, j_time, j_hours, job_status, status, platform, job_note, no_people) VALUES (?,?,?,?,?,'PENDING',1,'api',?,?)",
                [$cid, (int)$body['service_id'], $body['date'], $body['time'], max(2, (float)$body['hours']),
                 trim($body['notes'] ?? ''), (int)($body['no_people'] ?? 1)]);
            $newId = $db->lastInsertId();
            audit('api_book', 'job', $newId, "API-Booking durch Kunde #$cid");
            return ['success' => true, 'job_id' => (int)$newId, 'message' => 'Gebucht'];
        })(),

        $action0 === 'cancel' && $actionId > 0 && $method === 'POST' => (function() use ($cid, $actionId, $body) {
            $job = one("SELECT j_id, job_status FROM jobs WHERE j_id=? AND customer_id_fk=? AND status=1", [$actionId, $cid]);
            if (!$job) throw new Exception('Job not yours');
            if (in_array($job['job_status'], ['COMPLETED','CANCELLED'])) throw new Exception('Already finished');
            q("UPDATE jobs SET job_status='CANCELLED', cancel_date=NOW(), cancelled_role='customer', cancelled_by=?, j_c_val=? WHERE j_id=?",
              [$cid, $body['reason'] ?? 'API', $actionId]);
            audit('api_cancel', 'job', $actionId, "Cancel via API · " . ($body['reason'] ?? 'no reason'));
            return ['cancelled' => $actionId];
        })(),

        default => throw new Exception('Unknown action: ' . $action0)
    };

    echo json_encode(['success' => true, 'data' => $result]);
} catch (Exception $e) {
    if (http_response_code() < 400) http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
