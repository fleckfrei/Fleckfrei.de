<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';

// Admin-only — use session auth
if (($_SESSION['utype'] ?? '') !== 'admin') { http_response_code(403); echo json_encode(['results'=>[]]); exit; }

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q) < 2) { echo json_encode(['results'=>[]]); exit; }

$like = '%' . $q . '%';
$results = [];

// Customers — name, email, phone
try {
    $rows = all("SELECT customer_id, name, email, phone, customer_type FROM customer
                 WHERE status=1 AND (name LIKE ? OR email LIKE ? OR phone LIKE ?)
                 ORDER BY name LIMIT 5", [$like, $like, $like]);
    foreach ($rows as $r) {
        $results[] = [
            'type'     => 'customer',
            'id'       => (int)$r['customer_id'],
            'title'    => $r['name'],
            'subtitle' => trim(($r['email'] ?: '—') . ' · ' . ($r['phone'] ?: '—') . ' · ' . ($r['customer_type'] ?: 'Privat')),
            'url'      => '/admin/view-customer.php?id=' . (int)$r['customer_id'],
        ];
    }
} catch (Exception $e) {}

// Jobs — by id or customer name
try {
    if (ctype_digit($q)) {
        $rows = all("SELECT j.j_id, j.j_date, j.j_time, j.job_status, COALESCE(c.name,'') AS cname
                     FROM jobs j LEFT JOIN customer c ON j.customer_id_fk=c.customer_id
                     WHERE j.j_id = ? LIMIT 3", [(int)$q]);
    } else {
        $rows = all("SELECT j.j_id, j.j_date, j.j_time, j.job_status, COALESCE(c.name,'') AS cname
                     FROM jobs j LEFT JOIN customer c ON j.customer_id_fk=c.customer_id
                     WHERE j.status=1 AND c.name LIKE ?
                     ORDER BY j.j_date DESC LIMIT 5", [$like]);
    }
    foreach ($rows as $r) {
        $results[] = [
            'type'     => 'job',
            'id'       => (int)$r['j_id'],
            'title'    => 'Job #' . $r['j_id'] . ' — ' . ($r['cname'] ?: '—'),
            'subtitle' => date('d.m.Y', strtotime($r['j_date'])) . ' ' . substr($r['j_time'] ?? '',0,5) . ' · ' . $r['job_status'],
            'url'      => '/admin/jobs.php?j=' . (int)$r['j_id'],
        ];
    }
} catch (Exception $e) {}

// Invoices — by invoice number or customer
try {
    $rows = all("SELECT i.invoice_id, i.invoice_number, i.total_price, i.invoice_paid, COALESCE(c.name,'') AS cname
                 FROM invoices i LEFT JOIN customer c ON i.customer_id_fk=c.customer_id
                 WHERE i.invoice_number LIKE ? OR c.name LIKE ?
                 ORDER BY i.invoice_id DESC LIMIT 5", [$like, $like]);
    foreach ($rows as $r) {
        $results[] = [
            'type'     => 'invoice',
            'id'       => (int)$r['invoice_id'],
            'title'    => ($r['invoice_number'] ?: '#'.$r['invoice_id']) . ' — ' . ($r['cname'] ?: '—'),
            'subtitle' => number_format($r['total_price'],2,',','.') . ' € · ' . ($r['invoice_paid']==='yes' ? 'Bezahlt' : 'Offen'),
            'url'      => '/admin/invoices.php?id=' . (int)$r['invoice_id'],
        ];
    }
} catch (Exception $e) {}

// Leads
try {
    $rows = all("SELECT lead_id, name, email, phone, source, status FROM leads
                 WHERE name LIKE ? OR email LIKE ? OR phone LIKE ?
                 ORDER BY lead_id DESC LIMIT 3", [$like, $like, $like]);
    foreach ($rows as $r) {
        $results[] = [
            'type'     => 'lead',
            'id'       => (int)$r['lead_id'],
            'title'    => $r['name'] ?: ($r['email'] ?: 'Lead #'.$r['lead_id']),
            'subtitle' => trim(($r['source'] ?: '—') . ' · ' . ($r['status'] ?: '—') . ' · ' . ($r['email'] ?: $r['phone'])),
            'url'      => '/admin/leads.php?id=' . (int)$r['lead_id'],
        ];
    }
} catch (Exception $e) {}

// Vouchers
try {
    $rows = all("SELECT v_id, code, type, value, active FROM vouchers
                 WHERE code LIKE ? OR description LIKE ?
                 ORDER BY v_id DESC LIMIT 3", [$like, $like]);
    foreach ($rows as $r) {
        $valueTxt = $r['type']==='percent' ? ((float)$r['value']).' %' : number_format($r['value'],2,',','.').' €';
        $results[] = [
            'type'     => 'voucher',
            'id'       => (int)$r['v_id'],
            'title'    => $r['code'],
            'subtitle' => $valueTxt . ' · ' . ($r['active'] ? 'Aktiv' : 'Archiviert'),
            'url'      => '/admin/gutscheine.php',
        ];
    }
} catch (Exception $e) {}

echo json_encode(['q' => $q, 'results' => $results, 'count' => count($results)]);
