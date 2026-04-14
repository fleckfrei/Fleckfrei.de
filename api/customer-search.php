<?php
/**
 * Admin-only Customer-Search für Prebook-Link-Erstellen — wenn Admin einen Namen/Email tippt,
 * zeigt Dropdown mit matching Kunden samt aller Felder zum Auto-Prefill.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/auth.php';
if (($_SESSION['utype'] ?? '') !== 'admin') { http_response_code(403); echo json_encode(['results'=>[]]); exit; }

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q) < 2) { echo json_encode(['results'=>[]]); exit; }
$like = '%' . $q . '%';

$rows = all("SELECT c.customer_id, c.name, c.surname, c.email, c.phone, c.customer_type, c.district, c.is_premium, c.personal_slug, c.travel_tickets, c.travel_ticket_price,
             (SELECT CONCAT_WS(' ', street, number) FROM customer_address ca WHERE ca.customer_id_fk=c.customer_id ORDER BY ca.ca_id DESC LIMIT 1) AS street,
             (SELECT postal_code FROM customer_address ca WHERE ca.customer_id_fk=c.customer_id ORDER BY ca.ca_id DESC LIMIT 1) AS plz,
             (SELECT city FROM customer_address ca WHERE ca.customer_id_fk=c.customer_id ORDER BY ca.ca_id DESC LIMIT 1) AS city
             FROM customer c
             WHERE c.status=1 AND (c.name LIKE ? OR c.surname LIKE ? OR c.email LIKE ? OR c.phone LIKE ?)
             ORDER BY c.name LIMIT 10", [$like, $like, $like, $like]);

echo json_encode(['results' => $rows], JSON_UNESCAPED_UNICODE);
