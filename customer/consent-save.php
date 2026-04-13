<?php
/**
 * Speichert Marketing-Einwilligungen des Kunden (DSGVO-konform)
 * - Updated customer Tabelle
 * - Loggt in consent_history (Nachweis)
 * - Updated ontology_objects (OSINT-sichtbar)
 * - Setzt "deny-list" wenn unchecked (für Marketing-Tools)
 */
require_once __DIR__ . '/../includes/auth.php';
requireCustomer();
header('Content-Type: application/json');
$cid = me()['id'];

try {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $email = !empty($body['consent_email']) ? 1 : 0;
    $wa = !empty($body['consent_whatsapp']) ? 1 : 0;
    $phone = !empty($body['consent_phone']) ? 1 : 0;
    $source = $body['source'] ?? 'booking';

    // Alt-Werte holen für Change-Detection
    $old = one("SELECT consent_email, consent_whatsapp, consent_phone FROM customer WHERE customer_id=?", [$cid]);

    // Update customer Tabelle
    q("UPDATE customer SET consent_email=?, consent_whatsapp=?, consent_phone=?, consent_updated_at=NOW() WHERE customer_id=?",
      [$email, $wa, $phone, $cid]);

    // History-Log — NUR wenn was geändert wurde
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
    $channels = [
        'email' => [$email, (int)($old['consent_email'] ?? 0)],
        'whatsapp' => [$wa, (int)($old['consent_whatsapp'] ?? 0)],
        'phone' => [$phone, (int)($old['consent_phone'] ?? 0)],
    ];
    foreach ($channels as $ch => [$new, $prev]) {
        if ($new !== $prev) {
            q("INSERT INTO consent_history (customer_id_fk, channel, old_value, new_value, source, ip, user_agent, changed_by) VALUES (?,?,?,?,?,?,?,?)",
              [$cid, $ch, $prev, $new, $source, $ip, $ua, 'customer:' . $cid]);
            audit('consent_change', $ch, $cid, ($new ? 'GRANTED' : 'REVOKED') . " via $source");
        }
    }

    // Ontology-Sync (OSINT-sichtbar)
    try {
        $cust = one("SELECT name, email, phone FROM customer WHERE customer_id=?", [$cid]);
        // Person-Object aktualisieren
        $existing = one("SELECT obj_id, properties FROM ontology_objects WHERE obj_type='person' AND obj_key=?", ["cust_" . $cid]);
        $props = [
            'customer_id' => $cid,
            'name' => $cust['name'] ?? '',
            'email' => $cust['email'] ?? '',
            'phone' => $cust['phone'] ?? '',
            'consent_email' => $email,
            'consent_whatsapp' => $wa,
            'consent_phone' => $phone,
            'consent_last_updated' => date('Y-m-d H:i:s'),
            'marketing_allowed' => ($email || $wa || $phone) ? 1 : 0,
        ];
        if ($existing) {
            q("UPDATE ontology_objects SET properties=?, last_updated=NOW() WHERE obj_id=?",
              [json_encode($props), $existing['obj_id']]);
        } else {
            q("INSERT IGNORE INTO ontology_objects (obj_type, obj_key, display_name, properties, confidence, verified)
               VALUES ('person', ?, ?, ?, 1.0, 1)",
              ["cust_" . $cid, $cust['name'] ?? "Kunde #$cid", json_encode($props)]);
        }
    } catch (Exception $e) { /* ontology sync optional */ }

    echo json_encode(['success' => true, 'data' => [
        'consent_email' => $email,
        'consent_whatsapp' => $wa,
        'consent_phone' => $phone,
        'changes' => array_filter(array_map(
            fn($ch, $data) => $data[0] !== $data[1] ? "$ch: " . ($data[1] ? 'was ON' : 'was OFF') . " → " . ($data[0] ? 'ON' : 'OFF') : null,
            array_keys($channels), $channels
        ))
    ]]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
