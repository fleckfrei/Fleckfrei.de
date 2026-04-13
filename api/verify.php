<?php
/**
 * Public Insurance-Report Verification — KEIN Login nötig.
 * URL: /api/verify.php?id=FF-YYYYMMDD-NNNNNN
 * Wird vom QR-Code im Insurance-Report (admin/job-report.php) aufgerufen.
 * Bestätigt Echtheit + zeigt anonymisierte Eckdaten. Audit-Log in report_verifications.
 */
require_once __DIR__ . '/../includes/config.php';

$rawId = trim($_GET['id'] ?? '');
$reportId = strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', $rawId));

$status = 'invalid';
$job = null;
$ratingStars = 0;
$photoCount = 0;

if (preg_match('/^FF-(\d{8})-(\d{6})$/', $reportId, $m)) {
    $datePart = $m[1];
    $jid = (int)ltrim($m[2], '0');
    if ($jid > 0) {
        $job = one("
            SELECT j.j_id, j.j_date, j.j_time, j.j_hours, j.emp_id_fk, j.job_status,
                   s.title AS stitle, s.postal_code AS s_plz, s.city AS s_city
            FROM jobs j
            LEFT JOIN services s ON j.s_id_fk=s.s_id
            WHERE j.j_id=? AND j.status=1", [$jid]);
        if ($job && date('Ymd', strtotime($job['j_date'])) === $datePart) {
            $status = 'valid';
            try {
                $cnt = one("SELECT COUNT(*) AS c FROM checklist_completions WHERE job_id_fk=? AND photo IS NOT NULL AND photo<>''", [$jid]);
                $photoCount = (int)($cnt['c'] ?? 0);
            } catch (Exception $e) {}
            try {
                global $dbLocal;
                $r = $dbLocal->prepare("SELECT stars FROM job_ratings WHERE j_id=?");
                $r->execute([$jid]);
                $ratingStars = (int)($r->fetchColumn() ?: 0);
            } catch (Exception $e) {}
        } else {
            $status = 'not_found';
        }
    }
}

// Audit-Log (lazy create)
try {
    q("CREATE TABLE IF NOT EXISTS report_verifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_id VARCHAR(32) NOT NULL,
        j_id INT DEFAULT NULL,
        ip VARCHAR(45) DEFAULT NULL,
        user_agent VARCHAR(255) DEFAULT NULL,
        result ENUM('valid','invalid','not_found') NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_report (report_id),
        INDEX idx_jid (j_id),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    q("INSERT INTO report_verifications (report_id, j_id, ip, user_agent, result) VALUES (?,?,?,?,?)", [
        substr($reportId, 0, 32),
        $job['j_id'] ?? null,
        substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        $status,
    ]);
} catch (Exception $e) {}

$cityShort = $job ? trim(($job['s_plz'] ?? '') . ' ' . ($job['s_city'] ?? '')) : '';
$dateFmt   = $job ? date('d.m.Y', strtotime($job['j_date'])) : '';
$timeFmt   = $job ? substr($job['j_time'] ?? '', 0, 5) : '';
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Nachweis-Verifikation <?= e($reportId ?: 'unbekannt') ?> · <?= e(SITE) ?></title>
<style>
  *{box-sizing:border-box}
  body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;margin:0;padding:24px;background:#f5f7fa;color:#1a202c;min-height:100vh;display:flex;align-items:center;justify-content:center}
  .card{background:#fff;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,.08);max-width:520px;width:100%;padding:32px;text-align:center}
  .icon{font-size:64px;line-height:1;margin-bottom:12px}
  .ok{color:<?= e(BRAND) ?>}
  .bad{color:#c53030}
  h1{margin:0 0 8px;font-size:24px}
  .sub{color:#718096;margin-bottom:24px}
  .id{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:14px;background:#edf2f7;padding:8px 12px;border-radius:6px;display:inline-block;margin-bottom:24px}
  dl{text-align:left;margin:0;padding:16px 0;border-top:1px solid #e2e8f0}
  dl div{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f1f5f9}
  dl div:last-child{border-bottom:0}
  dt{color:#718096;font-size:14px}
  dd{margin:0;font-weight:600;font-size:14px;text-align:right}
  .footer{margin-top:24px;font-size:12px;color:#a0aec0;line-height:1.5}
  .footer a{color:<?= e(BRAND) ?>;text-decoration:none}
  .badge{display:inline-block;background:<?= e(BRAND_LIGHT) ?>;color:<?= e(BRAND_DARK) ?>;padding:4px 10px;border-radius:12px;font-size:12px;font-weight:600;margin-top:4px}
  .stars{color:#f59e0b;font-size:18px}
</style>
</head>
<body>
<div class="card">
<?php if ($status === 'valid'): ?>
  <div class="icon ok">✓</div>
  <h1>Nachweis bestätigt</h1>
  <p class="sub">Diese Reinigungsleistung wurde verifiziert von <?= e(SITE) ?>.</p>
  <div class="id"><?= e($reportId) ?></div>
  <dl>
    <div><dt>Datum</dt><dd><?= e($dateFmt) ?><?= $timeFmt ? ' · ' . e($timeFmt) : '' ?></dd></div>
    <div><dt>Service</dt><dd><?= e($job['stitle'] ?? '—') ?></dd></div>
    <div><dt>Ort</dt><dd><?= e($cityShort ?: '—') ?></dd></div>
    <div><dt>Dauer</dt><dd><?= e(number_format((float)$job['j_hours'], 1, ',', '.')) ?> h</dd></div>
    <div><dt>Partner</dt><dd>P-<?= (int)$job['emp_id_fk'] ?> <span class="badge"><?= e(SITE) ?>-Partner</span></dd></div>
    <div><dt>Foto-Belege</dt><dd><?= (int)$photoCount ?> Stück</dd></div>
    <?php if ($ratingStars > 0): ?>
    <div><dt>Bewertung</dt><dd><span class="stars"><?= str_repeat('★', $ratingStars) . str_repeat('☆', 5 - $ratingStars) ?></span></dd></div>
    <?php endif; ?>
    <div><dt>Status</dt><dd><?= e($job['job_status'] ?? '—') ?></dd></div>
  </dl>
<?php elseif ($status === 'not_found'): ?>
  <div class="icon bad">✗</div>
  <h1>Nachweis nicht gefunden</h1>
  <p class="sub">Die Nachweis-Nummer existiert nicht oder das Datum stimmt nicht überein.</p>
  <div class="id"><?= e($reportId) ?></div>
<?php else: ?>
  <div class="icon bad">⚠</div>
  <h1>Ungültige Nachweis-Nummer</h1>
  <p class="sub">Format muss <code>FF-YYYYMMDD-NNNNNN</code> sein.</p>
  <?php if ($reportId): ?><div class="id"><?= e($reportId) ?></div><?php endif; ?>
<?php endif; ?>
  <div class="footer">
    Geprüft am <?= e(date('d.m.Y H:i')) ?> Uhr.<br>
    Bei Rückfragen: <a href="mailto:<?= e(CONTACT_EMAIL) ?>"><?= e(CONTACT_EMAIL) ?></a>
  </div>
</div>
</body>
</html>
