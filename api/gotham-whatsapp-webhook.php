<?php
/**
 * Gotham WhatsApp Webhook — n8n → ontology ingest
 *
 * n8n's WhatsApp Trigger node POSTs every incoming message to this
 * endpoint. We extract sender phone + name + message text and:
 *   1. Upsert phone object
 *   2. Upsert person object (if name) and link has_phone
 *   3. Run ontology_extract_entities() on the message body and
 *      link any mentioned emails/phones/handles
 *   4. Add a wa_message timeline event
 *
 * POST body (flexible — n8n payload shapes vary):
 *   {
 *     "from":         "+4915757010977",
 *     "from_name":    "Adrian",
 *     "message":      "Hey, kannst du Rosa Gortner erreichen unter rosa@gmx.de?",
 *     "timestamp":    "2026-04-10T17:30:00Z",
 *     "chat_id":      "1234567890@s.whatsapp.net"   (optional)
 *   }
 *
 * Auth: ?secret=<GOTHAM_WHATSAPP_SECRET>
 */

ini_set('max_execution_time', 30);
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/ontology.php';

db_ping_reconnect();

$secret = $_GET['secret'] ?? '';
if (!defined('GOTHAM_WHATSAPP_SECRET') || $secret !== GOTHAM_WHATSAPP_SECRET) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];

// Flexible field extraction — try common n8n / WhatsApp field names
$from = trim(
    $body['from'] ??
    $body['sender'] ??
    $body['phone'] ??
    $body['waId'] ??
    ''
);
$fromName = trim(
    $body['from_name'] ??
    $body['name'] ??
    $body['pushname'] ??
    $body['notifyName'] ??
    ''
);
$message = trim(
    $body['message'] ??
    $body['text'] ??
    $body['body'] ??
    ''
);
$timestamp = $body['timestamp'] ?? $body['date'] ?? date('c');
$chatId = $body['chat_id'] ?? $body['chatId'] ?? null;

if ($from === '') {
    echo json_encode(['error' => 'from/sender/phone field required']);
    exit;
}

// Normalize phone to +E.164-ish
$from = preg_replace('/[^0-9+]/', '', $from);
if ($from && $from[0] !== '+') $from = '+' . $from;

$stats = ['objects_created' => 0, 'links_created' => 0, 'events_created' => 0];

try {
    // 1. Phone object (sender)
    $phoneId = ontology_upsert_object('phone', $from, [
        'last_wa_message' => mb_substr($message, 0, 200),
        'last_wa_timestamp' => $timestamp,
        'wa_chat_id' => $chatId,
    ], null, 0.85);
    $stats['objects_created']++;

    // 2. Person object linked to phone (if name provided)
    $personId = null;
    if ($fromName && $phoneId) {
        $personId = ontology_upsert_object('person', $fromName, [
            'wa_first_seen' => $timestamp,
        ], null, 0.7);
        if ($personId) {
            ontology_upsert_link($personId, $phoneId, 'has_phone', 'whatsapp', 0.85);
            $stats['objects_created']++;
            $stats['links_created']++;
        }
    }

    // 3. Extract entities from message body and link them
    if ($message && function_exists('ontology_extract_entities')) {
        $ent = ontology_extract_entities($message);
        $eventTarget = $personId ?: $phoneId;
        foreach (array_slice($ent['emails'] ?? [], 0, 5) as $em) {
            $eId = ontology_upsert_object('email', $em, [], null, 0.55);
            if ($eId && $eventTarget) {
                ontology_upsert_link($eventTarget, $eId, 'mentioned_in_wa', 'whatsapp', 0.55);
                $stats['objects_created']++;
                $stats['links_created']++;
            }
        }
        foreach (array_slice($ent['phones'] ?? [], 0, 3) as $ph) {
            if (preg_replace('/[^0-9+]/', '', $ph) === $from) continue; // skip self
            $pId = ontology_upsert_object('phone', $ph, [], null, 0.55);
            if ($pId && $eventTarget) {
                ontology_upsert_link($eventTarget, $pId, 'mentioned_in_wa', 'whatsapp', 0.55);
                $stats['objects_created']++;
                $stats['links_created']++;
            }
        }
        foreach (array_slice($ent['handles'] ?? [], 0, 3) as $h) {
            $hId = ontology_upsert_object('handle', $h, [], null, 0.5);
            if ($hId && $eventTarget) {
                ontology_upsert_link($eventTarget, $hId, 'mentioned_in_wa', 'whatsapp', 0.5);
                $stats['objects_created']++;
                $stats['links_created']++;
            }
        }
        foreach (array_slice($ent['urls'] ?? [], 0, 5) as $url) {
            // extract domain from url
            if (preg_match('#^https?://([^/]+)#i', $url, $m)) {
                $dom = strtolower($m[1]);
                $dId = ontology_upsert_object('domain', $dom, [], null, 0.5);
                if ($dId && $eventTarget) {
                    ontology_upsert_link($eventTarget, $dId, 'shared_link_in_wa', 'whatsapp', 0.5);
                    $stats['objects_created']++;
                    $stats['links_created']++;
                }
            }
        }
    }

    // 4. Timeline event on the person/phone
    $eventTarget = $personId ?: $phoneId;
    if ($eventTarget) {
        ontology_add_event(
            $eventTarget,
            'wa_message',
            substr($timestamp, 0, 10),
            'WhatsApp: ' . mb_substr($message, 0, 200),
            ['from' => $from, 'chat_id' => $chatId, 'message_full' => mb_substr($message, 0, 2000)],
            'whatsapp'
        );
        $stats['events_created']++;
    }

    echo json_encode(['success' => true, 'stats' => $stats, 'phone_id' => $phoneId, 'person_id' => $personId]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
