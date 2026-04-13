<?php
/**
 * Admin — Job-Bericht (Versicherungs-Nachweis)
 * Druckbares Dokument: Nachweis der Reinigungsleistung für Versicherung / Airbnb / Booking.com.
 *
 * Usage: /admin/job-report.php?j_id=12345 [&print=1]
 *
 * Zeigt: Datum/Uhrzeit, Service, Kunde, Adresse, geplante vs. tatsächliche Zeiten,
 *        GPS-Nachweis, Partner-Signatur (anonym), Checklist-Erfüllung mit Foto-Beweisen,
 *        Review-Status, QR-Code für Online-Verifikation.
 */
require_once __DIR__ . '/../includes/auth.php';

// Dual-access: Admin ODER Customer (eigener Job) ODER Token-basiert
$authMode = null;
$currentUser = null;

if (!empty($_GET['token'])) {
    // Öffentlicher Verifikations-Link (token-basiert, nur eigener Job)
    $tok = trim($_GET['token']);
    $cust = one("SELECT customer_id, name, customer_type FROM customer WHERE api_token=? AND status=1 AND COALESCE(api_access_blocked,0)=0 LIMIT 1", [$tok]);
    if (!$cust) { http_response_code(403); exit('Invalid or blocked token'); }
    $authMode = 'token'; $currentUser = $cust;
} elseif (isset($_SESSION['uid']) && ($_SESSION['role'] ?? '') === 'admin') {
    $authMode = 'admin';
} elseif (isset($_SESSION['uid']) && ($_SESSION['role'] ?? '') === 'customer') {
    $authMode = 'customer';
    $currentUser = ['customer_id' => me()['id']];
} else {
    header('Location: /login.php'); exit;
}

$jid = (int)($_GET['j_id'] ?? 0);
if (!$jid) { http_response_code(400); exit('Missing j_id'); }

$j = one("
    SELECT j.*, c.customer_id, c.name AS cname, c.email AS cemail, c.customer_type AS ctype,
           s.title AS stitle, s.street, s.number AS s_number, s.postal_code AS s_plz, s.city AS s_city,
           e.emp_id, e.name AS ename, e.surname AS esurname
    FROM jobs j
    LEFT JOIN customer c ON j.customer_id_fk=c.customer_id
    LEFT JOIN services s ON j.s_id_fk=s.s_id
    LEFT JOIN employee e ON j.emp_id_fk=e.emp_id
    WHERE j.j_id=? AND j.status=1", [$jid]);

if (!$j) { http_response_code(404); exit('Job not found'); }

// Customer/Token scoping — kann nur eigene Jobs sehen
if ($authMode === 'customer' && (int)$j['customer_id'] !== (int)$currentUser['customer_id']) {
    http_response_code(403); exit('Not your job');
}
if ($authMode === 'token' && (int)$j['customer_id'] !== (int)$currentUser['customer_id']) {
    http_response_code(403); exit('Not your job');
}

// Checklist-Erfüllung
$checklist = all("
    SELECT cl.checklist_id, cl.title, cl.priority, cl.room, cl.description,
           cc.completed, cc.photo, cc.completed_at
    FROM service_checklists cl
    LEFT JOIN checklist_completions cc ON cc.checklist_id_fk=cl.checklist_id AND cc.job_id_fk=?
    WHERE cl.s_id_fk=? AND cl.is_active=1
    ORDER BY cl.position, cl.checklist_id", [$jid, $j['s_id_fk']]);

$totalItems   = count($checklist);
$doneItems    = count(array_filter($checklist, fn($c) => (int)$c['completed'] === 1));
$photoItems   = count(array_filter($checklist, fn($c) => (int)$c['completed'] === 1 && !empty($c['photo'])));
$completionPct = $totalItems > 0 ? round($doneItems / $totalItems * 100) : 0;

// GPS-Nachweis (erste + letzte Position)
global $dbLocal;
$gpsStart = null; $gpsEnd = null;
try {
    $gpsStart = $dbLocal->prepare("SELECT lat, lng, created_at FROM gps_tracking WHERE j_id=? ORDER BY id ASC LIMIT 1");
    $gpsStart->execute([$jid]);
    $gpsStart = $gpsStart->fetch(PDO::FETCH_ASSOC);
    $gpsEnd = $dbLocal->prepare("SELECT lat, lng, created_at FROM gps_tracking WHERE j_id=? ORDER BY id DESC LIMIT 1");
    $gpsEnd->execute([$jid]);
    $gpsEnd = $gpsEnd->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Rating (Kunde → Partner)
$rating = null;
try {
    $r = $dbLocal->prepare("SELECT stars, comment, created_at FROM job_ratings WHERE j_id=?");
    $r->execute([$jid]);
    $rating = $r->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Report-ID (eindeutiger Nachweis-Code)
$reportId = 'FF-' . date('Ymd', strtotime($j['j_date'])) . '-' . str_pad($jid, 6, '0', STR_PAD_LEFT);
$verifyUrl = 'https://app.fleckfrei.de/api/verify.php?id=' . $reportId;

// Audit log (admin hat Bericht erstellt)
if ($authMode === 'admin') {
    try { audit('report_generate', 'job', $jid, "Bericht $reportId erstellt"); } catch (Exception $e) {}
}

$autoPrint = !empty($_GET['print']);
$serviceAddress = trim(($j['street'] ?? '') . ' ' . ($j['s_number'] ?? '') . ', ' . ($j['s_plz'] ?? '') . ' ' . ($j['s_city'] ?? ''));
if (!empty($j['address'])) $serviceAddress = $j['address'];
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8"/>
<title>Reinigungs-Nachweis <?= e($reportId) ?></title>
<meta name="robots" content="noindex,nofollow"/>
<style>
  * { box-sizing: border-box; }
  body { font-family: -apple-system, Helvetica, Arial, sans-serif; font-size: 12pt; color: #222; margin: 0; padding: 24px; max-width: 900px; margin: 0 auto; }
  h1 { font-size: 22pt; margin: 0 0 4px 0; color: #2E7D6B; }
  h2 { font-size: 14pt; margin: 24px 0 10px 0; border-bottom: 2px solid #2E7D6B; padding-bottom: 4px; color: #2E7D6B; }
  h3 { font-size: 11pt; margin: 12px 0 6px 0; text-transform: uppercase; color: #666; letter-spacing: 0.5px; }
  .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #2E7D6B; padding-bottom: 16px; margin-bottom: 20px; }
  .brand { font-weight: bold; }
  .report-id { font-family: monospace; font-size: 10pt; color: #666; background: #f3f4f6; padding: 4px 8px; border-radius: 4px; }
  .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
  .grid3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
  dl { margin: 0; }
  dl.kv div { display: flex; justify-content: space-between; padding: 4px 0; border-bottom: 1px solid #eee; font-size: 11pt; }
  dl.kv dt { color: #666; }
  dl.kv dd { margin: 0; font-weight: 600; }
  .stat-box { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; text-align: center; }
  .stat-big { font-size: 22pt; font-weight: bold; color: #2E7D6B; }
  .stat-lbl { font-size: 9pt; color: #666; text-transform: uppercase; }
  .check-table { width: 100%; border-collapse: collapse; font-size: 10pt; }
  .check-table th { text-align: left; padding: 6px 8px; background: #f3f4f6; border-bottom: 2px solid #ddd; font-weight: 600; font-size: 9pt; text-transform: uppercase; color: #555; }
  .check-table td { padding: 6px 8px; border-bottom: 1px solid #eee; vertical-align: top; }
  .chk-done { color: #2E7D6B; font-weight: bold; }
  .chk-miss { color: #dc2626; }
  .prio-critical { color: #dc2626; font-size: 9pt; }
  .prio-high { color: #ea580c; font-size: 9pt; }
  .photos-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-top: 10px; }
  .photo-item { text-align: center; }
  .photo-item img { width: 100%; height: 120px; object-fit: cover; border: 1px solid #ddd; border-radius: 6px; }
  .photo-item .cap { font-size: 9pt; color: #666; margin-top: 4px; line-height: 1.2; }
  .signature-box { border: 2px solid #2E7D6B; border-radius: 8px; padding: 16px; background: #f0fdf8; margin-top: 12px; }
  .footer-note { margin-top: 30px; padding-top: 16px; border-top: 1px solid #ddd; font-size: 9pt; color: #666; line-height: 1.5; }
  .print-btn { position: fixed; top: 16px; right: 16px; background: #2E7D6B; color: white; padding: 10px 20px; border-radius: 8px; font-weight: bold; font-size: 11pt; text-decoration: none; border: none; cursor: pointer; }
  .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 9pt; font-weight: 600; }
  .badge-ok { background: #d1fae5; color: #065f46; }
  .badge-warn { background: #fef3c7; color: #92400e; }
  .badge-err { background: #fecaca; color: #991b1b; }
  @media print {
    body { padding: 0; }
    .print-btn, .no-print { display: none !important; }
    h2 { break-after: avoid; }
    .check-table tr { break-inside: avoid; }
  }
</style>
</head>
<body>

<button class="print-btn no-print" onclick="window.print()">📄 PDF drucken / speichern</button>

<div class="header">
  <div>
    <h1>Reinigungs-Nachweis</h1>
    <div style="color:#666;font-size:11pt;">Offizielles Leistungs-Protokoll · <span class="brand">Fleckfrei.de</span></div>
  </div>
  <div style="text-align:right; display:flex; gap:12px; align-items:flex-start;">
    <div>
      <div class="report-id">Nachweis-Nr.<br><strong><?= e($reportId) ?></strong></div>
      <div style="font-size:9pt; color:#666; margin-top:6px;">Ausgestellt: <?= date('d.m.Y H:i') ?></div>
      <div style="font-size:8pt; color:#999; margin-top:4px; max-width:140px;">QR scannen zur Online-Verifikation →</div>
    </div>
    <div style="flex-shrink:0;">
      <img src="https://api.qrserver.com/v1/create-qr-code/?size=120x120&margin=0&data=<?= urlencode($verifyUrl) ?>"
           alt="Verifikations-QR-Code"
           width="110" height="110"
           style="display:block; border:1px solid #ddd; border-radius:6px;"/>
      <div style="font-size:7pt; color:#999; text-align:center; margin-top:2px;">Echtheits-Check</div>
    </div>
  </div>
</div>

<h2>1. Auftrags-Details</h2>
<div class="grid2">
  <dl class="kv">
    <div><dt>Leistungs-Datum</dt><dd><?= date('d.m.Y', strtotime($j['j_date'])) ?></dd></div>
    <div><dt>Leistungs-Typ</dt><dd><?= e($j['stitle']) ?></dd></div>
    <div><dt>Objekt-Adresse</dt><dd style="text-align:right;"><?= e($serviceAddress) ?></dd></div>
    <div><dt>Job-Kennung</dt><dd style="font-family:monospace;">#<?= (int)$j['j_id'] ?></dd></div>
    <div><dt>Plattform</dt><dd><?= e($j['platform'] ?: 'direkt') ?></dd></div>
  </dl>
  <dl class="kv">
    <div><dt>Geplante Zeit</dt><dd><?= substr($j['j_time'],0,5) ?> Uhr · <?= (float)$j['j_hours'] ?> h</dd></div>
    <div><dt>Tatsächlicher Beginn</dt><dd><?= $j['start_time'] ? e(substr($j['start_time'],0,5)) . ' Uhr' : '—' ?></dd></div>
    <div><dt>Tatsächliches Ende</dt><dd><?= $j['end_time'] ? e(substr($j['end_time'],0,5)) . ' Uhr' : '—' ?></dd></div>
    <div><dt>Tatsächliche Dauer</dt><dd><?= $j['total_hours'] ? round((float)$j['total_hours'],2) . ' h' : '—' ?></dd></div>
    <div><dt>Status</dt><dd>
      <?php
        $stBadge = match($j['job_status']) {
          'COMPLETED' => ['badge-ok', 'Erledigt'],
          'RUNNING' => ['badge-warn', 'Laufend'],
          'CANCELLED' => ['badge-err', 'Storniert'],
          'PENDING' => ['badge-warn', 'Offen'],
          default => ['badge-warn', $j['job_status']],
        };
      ?>
      <span class="badge <?= $stBadge[0] ?>"><?= e($stBadge[1]) ?></span>
    </dd></div>
  </dl>
</div>

<h2>2. Auftraggeber</h2>
<dl class="kv" style="max-width:500px;">
  <div><dt>Name/Firma</dt><dd><?= e($j['cname']) ?></dd></div>
  <div><dt>Kundentyp</dt><dd><?= e($j['ctype']) ?></dd></div>
  <?php if ($authMode === 'admin' && $j['cemail']): ?>
  <div><dt>E-Mail</dt><dd><?= e($j['cemail']) ?></dd></div>
  <?php endif; ?>
</dl>

<h2>3. Durchführung &amp; Ergebnis</h2>
<div class="grid3">
  <div class="stat-box">
    <div class="stat-big"><?= $completionPct ?>%</div>
    <div class="stat-lbl">Erfüllungsgrad</div>
    <div style="font-size:9pt;color:#666;margin-top:4px;"><?= $doneItems ?>/<?= $totalItems ?> Aufgaben</div>
  </div>
  <div class="stat-box">
    <div class="stat-big"><?= $photoItems ?></div>
    <div class="stat-lbl">Foto-Beweise</div>
    <div style="font-size:9pt;color:#666;margin-top:4px;">mit Bild dokumentiert</div>
  </div>
  <div class="stat-box">
    <?php if ($rating && $rating['stars']): ?>
    <div class="stat-big"><?= (int)$rating['stars'] ?>/5</div>
    <div class="stat-lbl">Kundenbewertung</div>
    <div style="font-size:9pt;color:#666;margin-top:4px;"><?= str_repeat('★', (int)$rating['stars']) . str_repeat('☆', 5-(int)$rating['stars']) ?></div>
    <?php else: ?>
    <div class="stat-big" style="color:#ccc;">—</div>
    <div class="stat-lbl">Keine Bewertung</div>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($checklist)): ?>
<h3>Aufgaben-Protokoll</h3>
<table class="check-table">
  <thead>
    <tr><th style="width:28px;">✓</th><th>Aufgabe</th><th style="width:80px;">Raum</th><th style="width:80px;">Priorität</th><th style="width:120px;">Erledigt am</th><th style="width:60px;">Foto</th></tr>
  </thead>
  <tbody>
    <?php foreach ($checklist as $ck): ?>
    <tr>
      <td><?= $ck['completed'] ? '<span class="chk-done">✓</span>' : '<span class="chk-miss">✗</span>' ?></td>
      <td>
        <strong><?= e($ck['title']) ?></strong>
        <?php if ($ck['description']): ?><div style="font-size:9pt;color:#666;"><?= e($ck['description']) ?></div><?php endif; ?>
      </td>
      <td><?= e($ck['room'] ?: '—') ?></td>
      <td>
        <?php if ($ck['priority'] === 'critical'): ?><span class="prio-critical">● kritisch</span>
        <?php elseif ($ck['priority'] === 'high'): ?><span class="prio-high">● wichtig</span>
        <?php else: ?><span style="color:#9ca3af;">normal</span><?php endif; ?>
      </td>
      <td style="font-size:10pt;"><?= $ck['completed_at'] ? date('d.m.Y H:i', strtotime($ck['completed_at'])) : '—' ?></td>
      <td><?= !empty($ck['photo']) ? '📷' : '—' ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<?php
$photos = array_values(array_filter($checklist, fn($c) => !empty($c['photo'])));
if (!empty($photos)):
?>
<h3>Foto-Dokumentation</h3>
<div class="photos-grid">
  <?php foreach ($photos as $ck): ?>
  <div class="photo-item">
    <img src="<?= e($ck['photo']) ?>" alt="<?= e($ck['title']) ?>"/>
    <div class="cap"><?= e($ck['title']) ?></div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<h2>4. GPS-Verifikation (Partner vor Ort)</h2>
<?php if ($gpsStart || $gpsEnd): ?>
<dl class="kv" style="max-width:700px;">
  <?php if ($gpsStart): ?>
  <div><dt>Erste GPS-Position</dt><dd>
    <?= number_format((float)$gpsStart['lat'], 6) ?>, <?= number_format((float)$gpsStart['lng'], 6) ?>
    &nbsp;·&nbsp; <?= date('d.m.Y H:i:s', strtotime($gpsStart['created_at'])) ?>
  </dd></div>
  <?php endif; ?>
  <?php if ($gpsEnd && $gpsEnd['created_at'] !== ($gpsStart['created_at'] ?? null)): ?>
  <div><dt>Letzte GPS-Position</dt><dd>
    <?= number_format((float)$gpsEnd['lat'], 6) ?>, <?= number_format((float)$gpsEnd['lng'], 6) ?>
    &nbsp;·&nbsp; <?= date('d.m.Y H:i:s', strtotime($gpsEnd['created_at'])) ?>
  </dd></div>
  <?php endif; ?>
</dl>
<p style="font-size:10pt; color:#666; margin-top:8px;">Anwesenheit durch GPS-Tracking des ausführenden Partners am Objekt nachgewiesen.</p>
<?php else: ?>
<p style="font-size:10pt; color:#999;">Keine GPS-Positionsdaten für diesen Auftrag verfügbar.</p>
<?php endif; ?>

<h2>5. Partner-Bestätigung</h2>
<div class="signature-box">
  <div style="font-size:10pt; color:#555;">Ausführung durch beauftragten und verifizierten Partner:</div>
  <div style="font-size:13pt; font-weight:bold; margin-top:4px;">
    <?= $authMode === 'admin' ? e(trim(($j['ename'] ?? '') . ' ' . ($j['esurname'] ?? ''))) : 'Fleckfrei-Partner (Kennung: P-' . (int)$j['emp_id_fk'] . ')' ?>
  </div>
  <div style="font-size:9pt; color:#666; margin-top:8px;">Alle Partner durchlaufen ein DSGVO-konformes Onboarding mit Identitäts- und Qualifikations-Prüfung.</div>
</div>

<?php if ($rating && !empty($rating['comment'])): ?>
<h2>6. Kunden-Kommentar</h2>
<blockquote style="border-left: 4px solid #2E7D6B; padding: 8px 16px; margin: 0; background:#f9fafb; font-style: italic;">
  "<?= e($rating['comment']) ?>"
  <div style="font-style:normal; font-size:9pt; color:#666; margin-top:4px;">— Bewertung vom <?= date('d.m.Y', strtotime($rating['created_at'])) ?></div>
</blockquote>
<?php endif; ?>

<div class="footer-note">
  <strong>Rechtlicher Hinweis:</strong> Dieses Dokument wurde automatisch aus dem Fleckfrei-Einsatzsystem generiert und dient als Nachweis
  der erbrachten Reinigungsleistung. Es enthält Protokolldaten (GPS, Zeitstempel, Foto-Beweise), die zum Zeitpunkt der Ausführung erhoben wurden.
  Zur Verifikation bei Versicherungsfällen, Plattform-Garantien (Airbnb, Booking.com) oder Streitigkeiten kontaktieren Sie bitte
  <a href="mailto:info@fleckfrei.de">info@fleckfrei.de</a> unter Angabe der Nachweis-Nr. <strong><?= e($reportId) ?></strong>.
  <br><br>
  Fleckfrei &mdash; Max Adrian · Berlin · <a href="https://fleckfrei.de">fleckfrei.de</a> · Verifikations-URL: <code><?= e($verifyUrl) ?></code>
</div>

<?php if ($autoPrint): ?>
<script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
<?php endif; ?>
</body>
</html>
