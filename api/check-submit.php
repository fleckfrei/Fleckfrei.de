<?php
/**
 * UNIFIED endpoint: Lead + Analyse + Email-Report — single submit.
 * Speichert Lead, läuft Analyse, sendet HTML-Report-Email, zeigt Dossier im Browser.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/llm-helpers.php';
header('Content-Type: application/json; charset=utf-8');

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$name = trim($body['name'] ?? '');
$email = trim($body['email'] ?? '');
$phone = trim($body['phone'] ?? '');
$url = trim($body['url'] ?? '');
$pastedText = trim($body['text'] ?? '');
$consentContact = !empty($body['consent_contact']);
$consentPrivacy = !empty($body['consent_privacy']);
$consentMarketing = !empty($body['consent_marketing']);

if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success'=>false, 'error'=>'Gültige Email erforderlich']);
    exit;
}
if (!$consentContact || !$consentPrivacy) {
    http_response_code(400);
    echo json_encode(['success'=>false, 'error'=>'Kontakt- und Datenschutz-Einwilligung erforderlich']);
    exit;
}
if (!$url && !$pastedText) {
    http_response_code(400);
    echo json_encode(['success'=>false, 'error'=>'URL oder Text erforderlich']);
    exit;
}

// Rate-limit per IP
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$cacheRoot = sys_get_temp_dir() . '/airbnb_check_v2';
if (!is_dir($cacheRoot)) @mkdir($cacheRoot, 0700, true);
$rlFile = $cacheRoot . '/rl_' . md5($ip) . '.json';
$now = time();
$entries = file_exists($rlFile) ? (json_decode(@file_get_contents($rlFile), true) ?: []) : [];
$entries = array_values(array_filter($entries, fn($t) => $t > $now - 3600));
if (count($entries) >= 5) {
    http_response_code(429);
    echo json_encode(['success'=>false, 'error'=>'Zu viele Anfragen — max. 5/Stunde']);
    exit;
}

// Delegate analysis to shared logic — re-invoke the analyze endpoint in-process
$analysisPayload = $url ? ['url'=>$url] : ['text'=>$pastedText];
$ch = curl_init('https://app.fleckfrei.de/api/airbnb-check-public.php');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
    CURLOPT_POSTFIELDS=>json_encode($analysisPayload),
    CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
    CURLOPT_TIMEOUT=>60,
]);
$resp = curl_exec($ch);
curl_close($ch);
$analysis = json_decode($resp, true);
if (!$analysis || empty($analysis['success'])) {
    http_response_code(502);
    echo json_encode(['success'=>false, 'error'=>'Analyse fehlgeschlagen: ' . ($analysis['error'] ?? 'unbekannt')]);
    exit;
}

$entries[] = $now;
@file_put_contents($rlFile, json_encode($entries));

$ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

// Store lead
try {
    q("CREATE TABLE IF NOT EXISTS airbnb_leads (
        al_id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255), email VARCHAR(255) NOT NULL, phone VARCHAR(50) NULL,
        analysis_json LONGTEXT,
        consent_contact TINYINT(1) DEFAULT 0, consent_privacy TINYINT(1) DEFAULT 0, consent_marketing TINYINT(1) DEFAULT 0,
        ip VARCHAR(45), user_agent VARCHAR(255),
        status ENUM('new','contacted','booked','rejected') DEFAULT 'new',
        email_sent TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_email (email), INDEX idx_status (status), INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach (['phone VARCHAR(50) NULL','consent_contact TINYINT(1) DEFAULT 0','consent_privacy TINYINT(1) DEFAULT 0','consent_marketing TINYINT(1) DEFAULT 0','email_sent TINYINT(1) DEFAULT 0'] as $col) {
        try { q("ALTER TABLE airbnb_leads ADD COLUMN $col"); } catch (Exception $e) {}
    }
    q("INSERT INTO airbnb_leads (name, email, phone, analysis_json, consent_contact, consent_privacy, consent_marketing, ip, user_agent) VALUES (?,?,?,?,?,?,?,?,?)",
      [$name, $email, $phone, json_encode($analysis), (int)$consentContact, (int)$consentPrivacy, (int)$consentMarketing, $ip, $ua]);
    $leadId = (int) lastInsertId();
} catch (Exception $e) {
    $leadId = 0;
}

// Build HTML Report Email
$ds = $analysis['dossier'] ?? [];
$la = $ds['listing_audit'] ?? [];
$mp = $ds['market_position'] ?? [];
$rf = $ds['review_forensics'] ?? [];
$ri = $ds['revenue_impact'] ?? [];
$bc = $ds['business_case'] ?? [];
$ap = $ds['action_plan'] ?? [];
$cp = $ds['cleaning_plan'] ?? [];
$market = $analysis['market'] ?? [];
$title = $analysis['meta']['title'] ?? 'Deine Unterkunft';
$riskScore = (int)($ds['risk_score'] ?? 5);
$lostYear = (int)($ri['lost_revenue_eur_per_year'] ?? 0);
$gain12mo = (int)($bc['12_month_net_gain_eur'] ?? 0);
$roi = htmlspecialchars($ri['fleckfrei_roi_ratio'] ?? '—');

$emailHtml = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#f8fafc;padding:0;margin:0;color:#0f172a}
.wrap{max-width:640px;margin:0 auto;background:#fff;padding:0}
.hdr{background:linear-gradient(135deg,#2E7D6B,#1e5a4c);color:#fff;padding:30px 24px;text-align:center}
.hdr h1{margin:0;font-size:22px}
.sec{padding:20px 24px;border-bottom:1px solid #e2e8f0}
.sec h2{font-size:16px;margin:0 0 12px 0;color:#2E7D6B}
.hero{background:linear-gradient(135deg,#fef2f2,#fef3c7);padding:30px 24px;text-align:center}
.hero .big{font-size:48px;font-weight:900;color:#dc2626;margin:8px 0}
.grid{display:table;width:100%;border-spacing:8px}
.cell{display:table-cell;background:#f1f5f9;border-radius:8px;padding:12px;text-align:center;width:25%}
.cell .v{font-weight:700;font-size:18px}
.cell .l{font-size:11px;color:#64748b}
.cta{display:block;background:#2E7D6B;color:#fff!important;padding:16px 24px;text-align:center;text-decoration:none;border-radius:10px;font-weight:700;margin:20px 24px}
ul{padding-left:18px;margin:8px 0}
.risk-'.($riskScore>=7?'high':($riskScore>=4?'mid':'low')).'{background:'.($riskScore>=7?'#fef2f2':($riskScore>=4?'#fef3c7':'#ecfdf5')).';border-left:4px solid '.($riskScore>=7?'#dc2626':($riskScore>=4?'#f59e0b':'#10b981')).';padding:12px;margin:12px 0;border-radius:6px}
</style></head><body><div class="wrap">
<div class="hdr"><h1>Dein Fleckfrei Revenue-Report</h1><p style="margin:8px 0 0 0;opacity:.9">'.htmlspecialchars($title).'</p></div>
<div class="hero"><div style="color:#b91c1c;font-size:13px;font-weight:600">💸 Dein geschätzter jährlicher Verlust:</div><div class="big">'.number_format($lostYear,0,',','.').'€</div><div style="color:#64748b;font-size:13px">Fleckfrei-ROI: '.$roi.'</div></div>
<div class="sec"><h2>🎯 Executive Summary</h2><p>'.htmlspecialchars($ds['summary_de'] ?? '').'</p><div class="risk-'.($riskScore>=7?'high':($riskScore>=4?'mid':'low')).'"><strong>Risk-Score: '.$riskScore.'/10</strong> — '.($riskScore>=7?'Kritisch, sofortiges Handeln nötig':($riskScore>=4?'Handlungsbedarf':'Stabil')).'</div></div>
<div class="sec"><h2>🏠 Deine Wohnung</h2><div class="grid"><div class="cell"><div class="v">'.htmlspecialchars($la['apartment_type'] ?? '-').'</div><div class="l">Typ</div></div><div class="cell"><div class="v">'.htmlspecialchars((string)($la['estimated_sqm'] ?? '?')).'</div><div class="l">qm</div></div><div class="cell"><div class="v">'.htmlspecialchars((string)($la['guests'] ?? '?')).'</div><div class="l">Gäste</div></div><div class="cell"><div class="v">'.htmlspecialchars((string)($la['estimated_price_per_night_eur'] ?? '?')).'€</div><div class="l">€/Nacht est.</div></div></div></div>';

if (!empty($market['avg_rating'])) {
    $emailHtml .= '<div class="sec"><h2>📊 Berlin-Markt</h2><div class="grid"><div class="cell"><div class="v">'.$market['avg_rating'].'/5</div><div class="l">Ø Rating</div></div><div class="cell"><div class="v">€'.($market['avg_price_eur'] ?? '?').'</div><div class="l">Ø Preis/N</div></div><div class="cell"><div class="v">'.($market['median_reviews'] ?? '?').'</div><div class="l">Median Reviews</div></div><div class="cell"><div class="v">'.htmlspecialchars($mp['rating_benchmark_needed'] ?? '?').'</div><div class="l">Top-20% braucht</div></div></div></div>';
}

$emailHtml .= '<div class="sec"><h2>🔍 Review-Forensik</h2><p><strong>SOFORT FIXEN:</strong> '.htmlspecialchars($rf['top_priority_fix'] ?? '-').'</p>';
if (!empty($rf['identified_complaints'])) {
    $emailHtml .= '<p><strong>Beschwerden aus Reviews:</strong></p><ul>';
    foreach ($rf['identified_complaints'] as $c) $emailHtml .= '<li>'.htmlspecialchars($c).'</li>';
    $emailHtml .= '</ul>';
}
$emailHtml .= '</div>';

$emailHtml .= '<div class="sec"><h2>💼 Dein Business-Case</h2><div class="grid"><div class="cell"><div class="v">'.($bc['fleckfrei_cost_per_turnover_eur'] ?? '?').'€</div><div class="l">pro Turnover</div></div><div class="cell"><div class="v">'.($bc['fleckfrei_cost_per_month_estimate_eur'] ?? '?').'€</div><div class="l">pro Monat</div></div><div class="cell"><div class="v">'.($bc['break_even_bookings_per_month'] ?? '?').'</div><div class="l">Break-even/Mo</div></div><div class="cell"><div class="v" style="color:#10b981">+'.number_format($gain12mo,0,',','.').'€</div><div class="l">12-Monats-Netto-Gewinn</div></div></div><p>'.htmlspecialchars($bc['summary_de'] ?? '').'</p></div>';

if (!empty($ap['immediate'])) {
    $emailHtml .= '<div class="sec"><h2>📋 Action-Plan: SOFORT</h2><ul>';
    foreach ($ap['immediate'] as $a) $emailHtml .= '<li>'.htmlspecialchars($a).'</li>';
    $emailHtml .= '</ul></div>';
}

$emailHtml .= '<a class="cta" href="mailto:info@fleckfrei.de?subject=Angebot%20anfordern%20('.urlencode($email).')">💬 Jetzt unverbindliches Angebot anfordern →</a>';
$emailHtml .= '<div style="padding:20px 24px;font-size:11px;color:#64748b;text-align:center">© Fleckfrei · <a href="https://fleckfrei.de">fleckfrei.de</a> · <a href="https://fleckfrei.de/impressum.html">Impressum</a> · <a href="https://fleckfrei.de/datenschutz.html">Datenschutz</a></div></div></body></html>';

// Send email
$subject = "📊 Dein Fleckfrei Revenue-Report — " . mb_substr($title, 0, 60);
$headers = "MIME-Version: 1.0\r\n"
         . "Content-Type: text/html; charset=UTF-8\r\n"
         . "From: Fleckfrei <no-reply@fleckfrei.de>\r\n"
         . "Reply-To: info@fleckfrei.de\r\n"
         . "Bcc: info@fleckfrei.de\r\n";
$mailSent = @mail($email, $subject, $emailHtml, $headers);

if ($leadId) {
    try { q("UPDATE airbnb_leads SET email_sent=? WHERE al_id=?", [(int)$mailSent, $leadId]); } catch (Exception $e) {}
}

// Telegram notify
if (function_exists('telegramNotify')) {
    $tmsg = "🆕 <b>Check-Lead #$leadId</b>\n\n"
          . "👤 " . htmlspecialchars($name ?: '(ohne Name)') . "\n"
          . "📧 " . htmlspecialchars($email) . "\n"
          . ($phone ? "📱 " . htmlspecialchars($phone) . "\n" : '')
          . "🏠 " . htmlspecialchars($title) . "\n"
          . "📐 " . ($la['apartment_type'] ?? '?') . " · " . ($la['estimated_sqm'] ?? '?') . "qm · €" . ($la['estimated_price_per_night_eur'] ?? '?') . "/N\n"
          . "⚠️ Risk " . $riskScore . "/10 · Verlust " . number_format($lostYear,0,',','.') . "€/J\n"
          . "✉️ Marketing: " . ($consentMarketing ? 'JA' : 'nein') . " · Email: " . ($mailSent ? 'gesendet ✓' : 'FEHLER')
          . "\n\n→ <a href=\"https://app.fleckfrei.de/admin/airbnb-analyzer.php\">Admin</a>";
    telegramNotify($tmsg);
}

echo json_encode([
    'success' => true,
    'lead_id' => $leadId,
    'email_sent' => (bool)$mailSent,
    'dossier' => $analysis,
]);
