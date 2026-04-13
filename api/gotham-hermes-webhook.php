<?php
/**
 * Gotham Hermes — Telegram bot command interface for Gotham
 *
 * Telegram webhook target. Handles slash commands sent to
 * @fleckfrei_bot:
 *
 *   /scan <name|email|phone|domain>
 *      Fires a vulture-core cascade depth=1 fast and replies with
 *      the synthetic narrative + key stats.
 *
 *   /verify <query>
 *      Fast multi-LLM lookup (Groq + Perplexity + SearXNG)
 *      and replies with the 3 short answers.
 *
 *   /watch <label> <query>
 *      Adds a watchlist entry, replies with the watch_id.
 *
 *   /stats
 *      Returns ontology totals + recent activity.
 *
 *   /help
 *      Lists commands.
 *
 * Webhook URL (set via Telegram API):
 *   https://app.fleckfrei.de/api/gotham-hermes-webhook.php?secret=<HERMES_WEBHOOK_SECRET>
 *
 * Auth: secret URL parameter must match HERMES_WEBHOOK_SECRET.
 * Sender chat_id is also pinned to HERMES_BOT_CHAT_ID so even
 * if the URL leaks, only Max can issue commands.
 */

ini_set('max_execution_time', 240);
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/ontology.php';
require_once __DIR__ . '/../includes/llm-helpers.php';

db_ping_reconnect();

// ============================================================
// Auth: webhook secret + sender chat_id pin
// ============================================================
$secret = $_GET['secret'] ?? '';
if (!defined('HERMES_WEBHOOK_SECRET') || $secret !== HERMES_WEBHOOK_SECRET) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
if (!defined('HERMES_BOT_TOKEN') || !defined('HERMES_BOT_CHAT_ID')) {
    http_response_code(503);
    echo json_encode(['error' => 'Hermes bot not configured']);
    exit;
}

// ============================================================
// Telegram helpers
// ============================================================
function tg_send(string $chatId, string $text, string $parseMode = 'HTML'): void {
    if (!defined('HERMES_BOT_TOKEN')) return;
    $url = 'https://api.telegram.org/bot' . HERMES_BOT_TOKEN . '/sendMessage';
    $payload = [
        'chat_id' => $chatId,
        'text' => mb_substr($text, 0, 4000),
        'parse_mode' => $parseMode,
        'disable_web_page_preview' => true,
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function esc_tg(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// ============================================================
// Parse incoming update
// ============================================================
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$message = $body['message'] ?? $body['edited_message'] ?? null;
if (!$message) {
    echo json_encode(['ok' => true, 'note' => 'no message']);
    exit;
}

$chatId = (string)($message['chat']['id'] ?? '');
$fromId = (string)($message['from']['id'] ?? '');
$text = trim($message['text'] ?? '');

// Pin: only Max can issue commands
if ($chatId !== HERMES_BOT_CHAT_ID && $fromId !== HERMES_BOT_CHAT_ID) {
    tg_send($chatId, '🚫 Unauthorized. Hermes is private.');
    echo json_encode(['ok' => true, 'rejected' => 'wrong chat']);
    exit;
}

// === Photo OCR handler (vor command-check) ===
if (!empty($message['photo']) || !empty($message['document'])) {
    $caption = trim($message['caption'] ?? '');
    $fileId = '';
    if (!empty($message['photo'])) {
        // Telegram sendet array of sizes — nimm die größte
        $photos = $message['photo'];
        usort($photos, fn($a,$b) => ($b['file_size']??0) - ($a['file_size']??0));
        $fileId = $photos[0]['file_id'] ?? '';
    } elseif (!empty($message['document']) && in_array($message['document']['mime_type'] ?? '', ['image/png','image/jpeg','image/jpg','image/webp','application/pdf'], true)) {
        $fileId = $message['document']['file_id'] ?? '';
    }

    if ($fileId) {
        tg_send($chatId, '🔍 Scanne Dokument...');
        // Get file path from Telegram
        $gf = json_decode(@file_get_contents('https://api.telegram.org/bot' . HERMES_BOT_TOKEN . '/getFile?file_id=' . urlencode($fileId)), true);
        $tgPath = $gf['result']['file_path'] ?? '';
        if ($tgPath) {
            $fileUrl = 'https://api.telegram.org/file/bot' . HERMES_BOT_TOKEN . '/' . $tgPath;
            $tmp = sys_get_temp_dir() . '/hermes_' . uniqid() . '_' . basename($tgPath);
            @file_put_contents($tmp, file_get_contents($fileUrl));
            $mime = mime_content_type($tmp) ?: 'image/jpeg';

            // Convert PDF to JPG if needed
            $jpgPath = $tmp;
            if ($mime === 'application/pdf' && extension_loaded('imagick')) {
                try {
                    $im = new Imagick();
                    $im->setResolution(200, 200);
                    $im->readImage($tmp . '[0]');
                    $im->setImageFormat('jpeg');
                    $im->setImageBackgroundColor('white');
                    $im->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                    if ($im->getImageWidth() > 1800) $im->resizeImage(1800, 0, Imagick::FILTER_LANCZOS, 1);
                    $jpgPath = $tmp . '.jpg';
                    $im->writeImage($jpgPath);
                    $im->clear();
                    $mime = 'image/jpeg';
                } catch (Exception $e) {
                    tg_send($chatId, '⚠ PDF-Konvertierung fehlgeschlagen: ' . $e->getMessage());
                    @unlink($tmp);
                    echo json_encode(['ok' => true]); exit;
                }
            }

            // Send to Groq Vision
            $dataUrl = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($jpgPath));
            $prompt = trim($caption ?: 'Extrahiere ALLEN sichtbaren Text aus diesem Dokument/Bild. Gib nur den Text zurück, gut formatiert. Wenn Tabellen vorhanden, formatiere als Liste.');

            $payload = json_encode([
                'model' => 'meta-llama/llama-4-scout-17b-16e-instruct',
                'temperature' => 0.1,
                'max_tokens' => 2000,
                'messages' => [
                    ['role' => 'user', 'content' => [
                        ['type' => 'text', 'text' => $prompt],
                        ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
                    ]],
                ],
            ], JSON_UNESCAPED_UNICODE);

            $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . GROQ_API_KEY]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            $resp = curl_exec($ch);
            curl_close($ch);

            @unlink($tmp);
            if ($jpgPath !== $tmp) @unlink($jpgPath);

            $ai = json_decode($resp, true);
            $extracted = $ai['choices'][0]['message']['content'] ?? '';
            if ($extracted) {
                // Telegram message limit ist 4096 chars
                if (strlen($extracted) > 3800) $extracted = substr($extracted, 0, 3800) . "

[...gekürzt]";
                tg_send($chatId, "📄 *Extrahierter Text:*

" . $extracted);
            } else {
                tg_send($chatId, '⚠ Kein Text erkannt. Versuch ein klareres Bild.');
            }
        } else {
            tg_send($chatId, '⚠ Konnte File nicht von Telegram laden.');
        }
        echo json_encode(['ok' => true]);
        exit;
    }
}

if ($text === '' || $text[0] !== '/') {
    echo json_encode(['ok' => true, 'note' => 'not a command']);
    exit;
}

// Parse command + args
$parts = preg_split('/\s+/', $text, 2);
$cmd = strtolower(ltrim($parts[0], '/'));
// Strip @botname suffix on group chats
$cmd = explode('@', $cmd)[0];
$arg = trim($parts[1] ?? '');

// ============================================================
// Command dispatch
// ============================================================
switch ($cmd) {

case 'help':
case 'start':
    tg_send($chatId,
        "🦅 <b>HERMES — Gotham Bot</b>\n\n" .
        "<b>Commands:</b>\n" .
        "/scan &lt;target&gt; — full VULTURE cascade (~30s)\n" .
        "/verify &lt;query&gt; — fast multi-LLM lookup (~6s)\n" .
        "/watch &lt;label&gt; &lt;query&gt; — add to watchlist\n" .
        "/stats — ontology totals\n" .
        "/lookup &lt;query&gt; — fast ontology search\n" .
        "/help — this message\n\n" .
        "Bot routes to <code>app.fleckfrei.de</code> Gotham backend."
    );
    break;

case 'stats':
    try {
        $obj = (int)valLocal("SELECT COUNT(*) FROM ontology_objects");
        $ver = (int)valLocal("SELECT COUNT(*) FROM ontology_objects WHERE verified=1");
        $lnk = (int)valLocal("SELECT COUNT(*) FROM ontology_links");
        $ev  = (int)valLocal("SELECT COUNT(*) FROM ontology_events");
        $sc  = (int)valLocal("SELECT COUNT(*) FROM osint_scans WHERE deep_scan_data IS NOT NULL");
        $watches = (int)valLocal("SELECT COUNT(*) FROM ontology_watchlist WHERE active=1");
        tg_send($chatId,
            "📊 <b>Gotham Stats</b>\n\n" .
            "Objects: <code>$obj</code> (<code>$ver</code> verified)\n" .
            "Links: <code>$lnk</code>\n" .
            "Events: <code>$ev</code>\n" .
            "Scans: <code>$sc</code>\n" .
            "Active watches: <code>$watches</code>"
        );
    } catch (Exception $e) {
        tg_send($chatId, '❌ ' . esc_tg($e->getMessage()));
    }
    break;

case 'lookup':
case 'search':
    if ($arg === '' || mb_strlen($arg) < 2) { tg_send($chatId, '❌ Usage: /lookup &lt;query&gt;'); break; }
    try {
        $rows = ontology_search($arg, null, 8);
        if (!$rows) { tg_send($chatId, "🔎 <b>$arg</b>\n<i>no matches</i>"); break; }
        $lines = ["🔎 <b>" . esc_tg($arg) . "</b> — " . count($rows) . " matches"];
        foreach ($rows as $r) {
            $v = $r['verified'] == 1 ? ' ✓' : '';
            $lines[] = "• <code>{$r['obj_type']}</code> " . esc_tg(mb_substr($r['display_name'], 0, 60)) . $v .
                       " <i>(" . round($r['confidence']*100) . "%, " . $r['source_count'] . " src)</i>";
        }
        tg_send($chatId, implode("\n", $lines));
    } catch (Exception $e) {
        tg_send($chatId, '❌ ' . esc_tg($e->getMessage()));
    }
    break;

case 'watch':
    $w = preg_split('/\s+/', $arg, 2);
    if (count($w) < 2) { tg_send($chatId, '❌ Usage: /watch &lt;label&gt; &lt;query&gt;'); break; }
    try {
        qLocal("INSERT INTO ontology_watchlist (label, query, query_type, created_by) VALUES (?,?,?,?)",
               [$w[0], $w[1], 'any', null]);
        global $dbLocal;
        $id = (int)$dbLocal->lastInsertId();
        tg_send($chatId, "✓ Watch added <code>#$id</code>: <b>" . esc_tg($w[0]) . "</b> · <code>" . esc_tg($w[1]) . "</code>");
    } catch (Exception $e) {
        tg_send($chatId, '❌ ' . esc_tg($e->getMessage()));
    }
    break;

case 'verify':
    if ($arg === '') { tg_send($chatId, '❌ Usage: /verify &lt;query&gt;'); break; }
    tg_send($chatId, "🤖 Verifying <b>" . esc_tg($arg) . "</b>… (Groq + Perplexity + SearXNG, ~6s)");
    $prompt = "Who is $arg? Short factual profile, verifiable sources, 4 sentences max. Honest if unknown.";
    $groq = groq_chat($prompt, 400);
    $pplx = vps_call('perplexity', ['query' => $prompt]);
    $sx   = vps_call('searxng', ['query' => $arg, 'categories' => 'general', 'limit' => 5]);
    $msg = "🤖 <b>KI Verify: " . esc_tg($arg) . "</b>\n\n";
    if ($groq && !empty($groq['content'])) {
        $msg .= "<b>⚡ Groq:</b>\n" . esc_tg(mb_substr($groq['content'], 0, 800)) . "\n\n";
    }
    if ($pplx && !empty($pplx['answer'])) {
        $msg .= "<b>🔮 Perplexity:</b>\n" . esc_tg(mb_substr($pplx['answer'], 0, 800)) . "\n\n";
    } elseif ($pplx && !empty($pplx['error'])) {
        $msg .= "<b>🔮 Perplexity:</b> error\n\n";
    }
    if ($sx && !empty($sx['results'])) {
        $msg .= "<b>🔎 SearXNG (top 3):</b>\n";
        foreach (array_slice($sx['results'], 0, 3) as $r) {
            $msg .= "• " . esc_tg(mb_substr($r['title'] ?? '', 0, 80)) . "\n";
        }
    }
    tg_send($chatId, $msg);
    break;

case 'scan':
    if ($arg === '') { tg_send($chatId, '❌ Usage: /scan &lt;name|email|phone|domain&gt;'); break; }
    tg_send($chatId, "🦅 Scanning <b>" . esc_tg($arg) . "</b>… (cascade depth=1 fast, ~30s)");
    // Smart seed interpretation
    $seed = ['depth' => 1, 'mode' => 'fast', 'context' => 'Hermes /scan from Telegram'];
    if (strpos($arg, '@') !== false) $seed['email'] = $arg;
    elseif (preg_match('/^\+?\d[\d\s\-]{7,}/', $arg)) $seed['phone'] = $arg;
    elseif (preg_match('/^[a-z0-9][a-z0-9-]{1,62}\.[a-z]{2,}$/i', $arg)) $seed['domain'] = $arg;
    else $seed['name'] = $arg;

    $url = 'https://app.' . SITE_DOMAIN . '/api/vulture-core.php';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($seed),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-API-Key: ' . API_KEY],
        CURLOPT_TIMEOUT => 220,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = $resp ? json_decode($resp, true) : null;
    if (!$j || empty($j['success'])) {
        tg_send($chatId, "❌ Scan failed (HTTP $code)");
        break;
    }
    $rep = $j['report'] ?? [];
    $msg = "🦅 <b>Cascade complete</b>\n\n" .
           "Target: <b>" . esc_tg($arg) . "</b>\n" .
           "Confidence: <code>" . round(($rep['confidence_overall'] ?? 0) * 100) . "%</code>\n" .
           "Risk: <code>" . esc_tg($rep['risk_assessment']['level'] ?? '—') . "</code>\n" .
           "New objects: <code>" . ($j['ontology']['objects_created'] ?? 0) . "</code>\n" .
           "Elapsed: <code>" . ($j['elapsed_seconds'] ?? '?') . "s</code>\n\n" .
           "<b>Narrative:</b>\n<i>" . esc_tg(mb_substr($rep['narrative'] ?? '(empty)', 0, 1500)) . "</i>\n\n" .
           "🔗 <a href=\"https://app." . SITE_DOMAIN . "/admin/scanner.php\">Open Scanner</a>";
    tg_send($chatId, $msg);
    break;

default:
    tg_send($chatId, "❓ Unknown command <code>/$cmd</code>. Try /help");
}

echo json_encode(['ok' => true, 'cmd' => $cmd]);
