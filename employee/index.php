<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/email.php';
require_once __DIR__ . '/../includes/checklist-helpers.php';
require_once __DIR__ . '/../includes/translate-helper.php';
requireEmployee();
$title = 'Meine Jobs'; $page = 'dashboard';
$user = me();

// Handle Start/Stop/Cancel
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { header('Location: /employee/'); exit; }
    $act = $_POST['action'] ?? '';
    $jid = (int)($_POST['j_id'] ?? 0);
    if ($act === 'start_job') {
        q("UPDATE jobs SET job_status='RUNNING', start_time=?, start_location=?, is_start_location=1 WHERE j_id=? AND emp_id_fk=?",
          [date('H:i:s'), $_POST['location']??'', $jid, $user['id']]);
        notifyJobStarted($jid);
        webhookNotify('status', ['j_id'=>$jid,'status'=>'RUNNING','employee'=>$user['name'],'time'=>date('H:i:s')]);
        $jobInfo = one("SELECT j.*, c.name as cname, s.title as stitle FROM jobs j LEFT JOIN customer c ON j.customer_id_fk=c.customer_id LEFT JOIN services s ON j.s_id_fk=s.s_id WHERE j.j_id=?", [$jid]);
        telegramNotify("▶️ <b>Job gestartet</b>\n\n👷 " . e($user['name']) . "\n👤 " . e($jobInfo['cname']??'') . "\n📋 " . e($jobInfo['stitle']??'') . "\n⏰ " . date('H:i') . "\n📍 " . e($jobInfo['address']??''));
        audit('start', 'job', $jid, 'Partner: '.$user['name']);
        header("Location: /employee/?started=$jid"); exit;
    }
    if ($act === 'stop_job') {
        $endTime = date('H:i:s');
        $job = one("SELECT * FROM jobs WHERE j_id=?", [$jid]);
        $totalHours = 0;
        if ($job && $job['start_time']) {
            $start = new DateTime($job['j_date'].' '.$job['start_time']);
            $end = new DateTime($job['j_date'].' '.$endTime);
            $totalHours = round(($end->getTimestamp() - $start->getTimestamp()) / 3600, 2);
        }

        // Handle photo uploads
        $photos = [];
        if (!empty($_FILES['photos']['name'][0])) {
            $uploadDir = __DIR__ . '/../uploads/jobs/' . $jid . '/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            foreach ($_FILES['photos']['tmp_name'] as $i => $tmp) {
                if ($_FILES['photos']['error'][$i] !== UPLOAD_ERR_OK) continue;
                if ($_FILES['photos']['size'][$i] > 10 * 1024 * 1024) continue;
                // MIME type check — Fotos + Videos
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($tmp);
                $allowedMimes = [
                    'image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/heic'=>'heic',
                    'video/mp4'=>'mp4','video/webm'=>'webm','video/quicktime'=>'mov','video/x-m4v'=>'m4v'
                ];
                if (!isset($allowedMimes[$mime])) continue;
                $isVideo = str_starts_with($mime, 'video/');
                // Video Größe bis 50 MB; Foto bis 10 MB
                $maxBytes = $isVideo ? 50 * 1024 * 1024 : 10 * 1024 * 1024;
                if (filesize($tmp) > $maxBytes) continue;
                // Bilder: verifizieren dass es echt ein Bild ist
                if (!$isVideo && $mime !== 'image/heic' && @getimagesize($tmp) === false) continue;
                $ext = $allowedMimes[$mime];
                // Filename mit Zeitstempel für Nachweis (YYYYMMDD_HHMMSS_random.ext)
                $fname = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (move_uploaded_file($tmp, $uploadDir . $fname)) {
                    $photos[] = $fname;
                }
            }
        }
        $photosJson = !empty($photos) ? json_encode($photos) : null;

        q("UPDATE jobs SET job_status='COMPLETED', end_time=?, end_location=?, is_end_location=1, total_hours=?, job_note=?, job_photos=? WHERE j_id=? AND emp_id_fk=?",
          [$endTime, $_POST['location']??'', $totalHours, $_POST['note']??'', $photosJson, $jid, $user['id']]);
        notifyJobCompleted($jid);
        webhookNotify('status', ['j_id'=>$jid,'status'=>'COMPLETED','employee'=>$user['name'],'time'=>date('H:i:s'),'hours'=>$totalHours]);
        $jobInfo = one("SELECT j.*, c.name as cname, s.title as stitle FROM jobs j LEFT JOIN customer c ON j.customer_id_fk=c.customer_id LEFT JOIN services s ON j.s_id_fk=s.s_id WHERE j.j_id=?", [$jid]);
        $photoCount = count($photos);
        telegramNotify("✅ <b>Job erledigt</b>\n\n👷 " . e($user['name']) . "\n👤 " . e($jobInfo['cname']??'') . "\n📋 " . e($jobInfo['stitle']??'') . "\n⏱ " . round($totalHours,1) . "h\n⏰ " . ($jobInfo['start_time'] ? substr($jobInfo['start_time'],0,5) : '') . " — " . date('H:i') . ($photoCount ? "\n📸 {$photoCount} Foto(s)" : ''));
        audit('complete', 'job', $jid, 'Partner: '.$user['name'].', '.$totalHours.'h');
        header("Location: /employee/?stopped=$jid"); exit;
    }
    if ($act === 'send_customer_msg') {
        $jid = (int)($_POST['j_id'] ?? 0);
        $msg = trim($_POST['message'] ?? '');
        if ($jid && $msg !== '') {
            $jobInfo = one("SELECT customer_id_fk FROM jobs WHERE j_id=? AND emp_id_fk=?", [$jid, $user['id']]);
            if ($jobInfo) {
                // Messages live in dbLocal (same as customer/messages.php and chat-poll)
                qLocal("INSERT INTO messages (sender_type, sender_id, sender_name, recipient_type, recipient_id, message, job_id, channel) VALUES ('employee', ?, ?, 'customer', ?, ?, ?, 'portal')",
                  [$user['id'], $user['name'], (int)$jobInfo['customer_id_fk'], $msg, $jid]);
                // Ping Telegram so admin sees all traffic
                if (function_exists('telegramNotify')) {
                    $custName = val("SELECT name FROM customer WHERE customer_id=?", [(int)$jobInfo['customer_id_fk']]) ?: 'Kunde';
                    telegramNotify("💬 <b>Partner → Kunde</b>\n\n👷 " . e($user['name']) . " → 👤 " . e($custName) . "\n\n" . e(mb_substr($msg, 0, 200)));
                }
            }
        }
        header("Location: /employee/?msg_sent=$jid"); exit;
    }

    if ($act === 'cancel_job') {
        q("UPDATE jobs SET job_status='CANCELLED', cancel_date=?, cancelled_role='employee', cancelled_by=? WHERE j_id=? AND emp_id_fk=?",
          [date('Y-m-d H:i:s'), $user['id'], $jid, $user['id']]);
        webhookNotify('status', ['j_id'=>$jid,'status'=>'CANCELLED','employee'=>$user['name'],'time'=>date('H:i:s')]);
        $jobInfo = one("SELECT j.*, c.name as cname, s.title as stitle FROM jobs j LEFT JOIN customer c ON j.customer_id_fk=c.customer_id LEFT JOIN services s ON j.s_id_fk=s.s_id WHERE j.j_id=?", [$jid]);
        telegramNotify("❌ <b>Job storniert</b>\n\n👷 " . e($user['name']) . "\n👤 " . e($jobInfo['cname']??'') . "\n📋 " . e($jobInfo['stitle']??'') . "\n⏰ " . date('H:i'));
        audit('cancel', 'job', $jid, 'Partner: '.$user['name']);
        header("Location: /employee/?cancelled=$jid"); exit;
    }
}

$today = date('Y-m-d');
$empId = $user['id'];

// HEUTE + überfällige offene Jobs der letzten 2 Tage
$todayJobs = all("SELECT j.*, s.title as stitle, s.street, s.city, s.box_code, s.client_code, s.deposit_code, s.wifi_name, s.wifi_password,
    c.name as cname, c.phone as cphone, c.customer_type as ctype,
    j.no_children, j.no_pets, j.has_separate_beds, j.has_sofa_bed, j.extras_note, j.optional_products
    FROM jobs j LEFT JOIN services s ON j.s_id_fk=s.s_id LEFT JOIN customer c ON j.customer_id_fk=c.customer_id
    WHERE j.emp_id_fk=? AND j.status=1 AND j.job_status NOT IN ('CANCELLED','COMPLETED')
      AND (j.j_date = ? OR (j.j_date < ? AND j.j_date >= DATE_SUB(?, INTERVAL 2 DAY)))
    ORDER BY j.j_date, j.j_time", [$empId, $today, $today, $today]);

// Completed jobs heute zum informativ zeigen
$todayDoneJobs = all("SELECT j.j_id, j.j_time, j.end_time, j.total_hours, s.title as stitle, c.name as cname
    FROM jobs j LEFT JOIN services s ON j.s_id_fk=s.s_id LEFT JOIN customer c ON j.customer_id_fk=c.customer_id
    WHERE j.emp_id_fk=? AND j.j_date=? AND j.status=1 AND j.job_status='COMPLETED'
    ORDER BY j.j_time", [$empId, $today]);

// Map smart_locks per (customer + service) for today's jobs — enables "Tür öffnen" button
$jobLocks = [];
if (!empty($todayJobs)) {
    $cids = array_unique(array_map(fn($j) => (int)$j['customer_id_fk'], $todayJobs));
    $sids = array_unique(array_map(fn($j) => (int)$j['s_id_fk'], $todayJobs));
    if ($cids && $sids) {
        $cidList = implode(',', array_map('intval', $cids));
        $sidList = implode(',', array_map('intval', $sids));
        $locksForJobs = all("SELECT lock_id, customer_id_fk, linked_service_id, device_name, provider, battery_level, last_state
                             FROM smart_locks
                             WHERE is_active=1 AND customer_id_fk IN ($cidList)
                               AND (linked_service_id IS NULL OR linked_service_id IN ($sidList))", []);
        foreach ($locksForJobs as $lk) {
            $key = $lk['customer_id_fk'] . ':' . ($lk['linked_service_id'] ?: 'any');
            $jobLocks[$key][] = $lk;
        }
    }
}
function locksForJob(array $job, array $jobLocks): array {
    $k1 = $job['customer_id_fk'] . ':' . $job['s_id_fk'];
    $k2 = $job['customer_id_fk'] . ':any';
    return array_merge($jobLocks[$k1] ?? [], $jobLocks[$k2] ?? []);
}

$upcomingJobs = all("SELECT j.*, s.title as stitle, c.name as cname
    FROM jobs j LEFT JOIN services s ON j.s_id_fk=s.s_id LEFT JOIN customer c ON j.customer_id_fk=c.customer_id
    WHERE j.emp_id_fk=? AND j.j_date>? AND j.status=1 AND j.job_status!='CANCELLED'
    ORDER BY j.j_date, j.j_time LIMIT 10", [$empId, $today]);

$empData = one("SELECT * FROM employee WHERE emp_id=?", [$empId]);
$partnerLang = $empData['language'] ?? 'de';

// Preload checklists + translate for each today job's service
$checklistsByService = [];
foreach ($todayJobs as $tj) {
    $sid = (int) $tj['s_id_fk'];
    if ($sid && !isset($checklistsByService[$sid])) {
        $checklistsByService[$sid] = getChecklistForPartner($sid, $partnerLang);
    }
}

// Preload completion state keyed by "jid:cid" so the UI can show pre-checked items (e.g. on reload)
$completionsByJobItem = [];
$jobIds = array_column($todayJobs, 'j_id');
if (!empty($jobIds)) {
    $idList = implode(',', array_map('intval', $jobIds));
    $completions = all("SELECT job_id_fk, checklist_id_fk, completed, note, photo FROM checklist_completions WHERE job_id_fk IN ($idList)");
// Preload customer-uploaded media per checklist item (für Partner-Anweisungen)
$customerMediaByItem = [];
try {
    $allItemIds = [];
    foreach ($checklistsByService as $svcCl) foreach ($svcCl as $x) $allItemIds[] = (int)$x['checklist_id'];
    if ($allItemIds) {
        $idStr = implode(',', array_unique($allItemIds));
        foreach (all("SELECT * FROM checklist_media WHERE checklist_id_fk IN ($idStr) ORDER BY uploaded_at") as $m) {
            $customerMediaByItem[$m['checklist_id_fk']][] = $m;
        }
    }
} catch (Exception $e) {}
    foreach ($completions as $c) {
        $completionsByJobItem[$c['job_id_fk'] . ':' . $c['checklist_id_fk']] = $c;
    }
}

// Route planner — for each job, find the "next job" (same day, by time) for taxi routing
function nextJobAfter(array $todayJobs, array $currentJob): ?array {
    $currentTime = strtotime($currentJob['j_date'] . ' ' . $currentJob['j_time']);
    $next = null;
    foreach ($todayJobs as $j) {
        if ($j['j_id'] === $currentJob['j_id']) continue;
        if (in_array($j['job_status'], ['COMPLETED','CANCELLED'], true)) continue;
        $jTime = strtotime($j['j_date'] . ' ' . $j['j_time']);
        if ($jTime <= $currentTime) continue;
        if ($next === null || $jTime < strtotime($next['j_date'] . ' ' . $next['j_time'])) {
            $next = $j;
        }
    }
    return $next;
}

include __DIR__ . '/../includes/layout.php';
?>

<?php if(!empty($_GET['started'])): ?><div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4">Job gestartet! GPS erfasst.</div><?php endif; ?>
<?php if(!empty($_GET['stopped'])): ?><div class="bg-blue-50 border border-blue-200 text-blue-800 px-4 py-3 rounded-xl mb-4">Job beendet! Arbeitszeit gespeichert.</div><?php endif; ?>
<?php if(!empty($_GET['msg_sent'])): ?><div class="bg-brand-light border border-brand/20 text-brand px-4 py-3 rounded-xl mb-4">✓ Nachricht an Kunde gesendet.</div><?php endif; ?>

<?php
  // Partner-Stats — offene/erledigte
  $runningCount = count(array_filter($todayJobs, fn($j) => in_array($j['job_status'], ['RUNNING','STARTED'])));
  $doneToday = count($todayDoneJobs);
  $openCount = count($todayJobs);
  $hoursToday = array_sum(array_map(fn($j) => (float)($j['total_hours'] ?: 0), $todayDoneJobs));
  $tariff = (float)($empData['tariff'] ?? 0);
  $earningsToday = round($hoursToday * $tariff, 2);
  // Woche
  $weekCount = (int)val("SELECT COUNT(*) FROM jobs WHERE emp_id_fk=? AND j_date BETWEEN ? AND DATE_ADD(?, INTERVAL 7 DAY) AND status=1 AND job_status NOT IN ('CANCELLED','COMPLETED')", [$empId, $today, $today]);
?>

<!-- Partner-Portal Hero-Banner -->
<div class="relative mb-6 bg-gradient-to-br from-brand via-brand-dark to-brand text-white rounded-2xl p-6 shadow-lg overflow-hidden">
  <div class="absolute inset-0 opacity-10">
    <svg class="w-full h-full" viewBox="0 0 100 100" preserveAspectRatio="none">
      <circle cx="15" cy="20" r="2" fill="white"/><circle cx="85" cy="30" r="1.5" fill="white"/>
      <circle cx="50" cy="70" r="2" fill="white"/><circle cx="90" cy="85" r="1.5" fill="white"/>
    </svg>
  </div>
  <div class="relative flex items-start justify-between flex-wrap gap-4">
    <div>
      <div class="text-[11px] uppercase font-bold tracking-widest opacity-80 mb-1">👷 Partner-Portal</div>
      <h1 class="text-2xl sm:text-3xl font-extrabold">Hallo <?= e($empData['name']) ?>!</h1>
      <p class="text-white/90 text-sm mt-1"><?= date('l, d. F Y', strtotime('today')) ?></p>
    </div>
    <div class="grid grid-cols-3 gap-2 sm:gap-4 text-center">
      <div class="bg-white/20 backdrop-blur rounded-xl px-3 py-2 min-w-[80px]">
        <div class="text-2xl font-extrabold"><?= $openCount ?></div>
        <div class="text-[10px] uppercase opacity-90">Offen</div>
      </div>
      <div class="bg-white/20 backdrop-blur rounded-xl px-3 py-2 min-w-[80px]">
        <div class="text-2xl font-extrabold"><?= $doneToday ?></div>
        <div class="text-[10px] uppercase opacity-90">Heute erledigt</div>
      </div>
      <div class="bg-white/20 backdrop-blur rounded-xl px-3 py-2 min-w-[80px]">
        <div class="text-2xl font-extrabold"><?= $weekCount ?></div>
        <div class="text-[10px] uppercase opacity-90">Diese Woche</div>
      </div>
    </div>
  </div>
  <?php if ($runningCount > 0): ?>
  <div class="relative mt-4 flex items-center gap-2 bg-green-400/30 rounded-lg px-3 py-2">
    <span class="w-2 h-2 rounded-full bg-green-300 animate-pulse"></span>
    <span class="text-sm font-semibold"><?= $runningCount ?> Job läuft gerade</span>
  </div>
  <?php endif; ?>
</div>

<!-- Today's Jobs -->
<div class="space-y-4 mb-8">
  <?php foreach ($todayJobs as $j): ?>
  <div class="bg-white rounded-xl border p-5 <?= $j['job_status']==='RUNNING' ? 'ring-2 ring-brand' : '' ?>">
    <div class="flex items-start justify-between mb-3">
      <div>
        <h3 class="font-semibold text-lg"><?= e($j['stitle']) ?></h3>
        <p class="text-gray-500"><?= e($j['cname']) ?> <span class="text-xs">(<?= e($j['ctype']) ?>)</span></p>
      </div>
      <?= badge($j['job_status']) ?>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm mb-4">
      <div><span class="text-gray-400">Zeit:</span> <strong><?= substr($j['j_time'],0,5) ?></strong></div>
      <div><span class="text-gray-400">Stunden:</span> <strong><?= $j['j_hours'] ?>h</strong></div>
      <div><span class="text-gray-400">Personen:</span> <strong><?= $j['no_people'] ?></strong></div>
      <div><span class="text-gray-400">Plattform:</span> <?= e($j['platform']) ?></div>
    </div>

    <?php
    // Extras: children, pets, beds, sofa, notes + dynamische Zusatzleistungen
    $optionalRaw = trim((string)($j['optional_products'] ?? ''));
    $optionalList = $optionalRaw !== '' ? array_values(array_filter(array_map('trim', explode(',', $optionalRaw)))) : [];
    $hasExtras = !empty($j['no_children']) || !empty($j['no_pets']) || !empty($j['has_separate_beds']) || !empty($j['has_sofa_bed']) || !empty($j['extras_note']) || !empty($optionalList);

    // Lookup: icon pro Zusatzleistung (optional_products-Tabelle)
    $opLookup = [];
    if (!empty($optionalList)) {
        try {
            $placeholders = implode(',', array_fill(0, count($optionalList), '?'));
            foreach (all("SELECT name, icon, description FROM optional_products WHERE name IN ($placeholders)", $optionalList) as $op) {
                $opLookup[$op['name']] = $op;
            }
        } catch (Exception $e) {}
    }

    if ($hasExtras): ?>
    <div class="mb-4 p-3 rounded-xl bg-amber-50 border border-amber-200">
      <div class="text-[10px] uppercase font-bold text-amber-900 tracking-wide mb-1.5 flex items-center gap-1">
        <span>📋</span> Extras vom Kunden <span class="text-amber-700 normal-case">· vom Host ausgewählt</span>
      </div>
      <div class="flex flex-wrap gap-2 text-xs">
        <?php if (!empty($j['no_children'])): ?>
          <span class="px-2 py-1 bg-white border border-amber-200 rounded-full font-semibold">👶 <?= (int)$j['no_children'] ?> Kind<?= (int)$j['no_children'] === 1 ? '' : 'er' ?></span>
        <?php endif; ?>
        <?php if (!empty($j['no_pets'])): ?>
          <span class="px-2 py-1 bg-white border border-amber-200 rounded-full font-semibold">🐾 <?= (int)$j['no_pets'] ?> Tier<?= (int)$j['no_pets'] === 1 ? '' : 'e' ?></span>
        <?php endif; ?>
        <?php if (!empty($j['has_separate_beds'])): ?>
          <span class="px-2 py-1 bg-white border border-amber-200 rounded-full font-semibold">🛏️🛏️ Getrennte Betten</span>
        <?php endif; ?>
        <?php if (!empty($j['has_sofa_bed'])): ?>
          <span class="px-2 py-1 bg-white border border-amber-200 rounded-full font-semibold">🛋️ Sofa-Bett</span>
        <?php endif; ?>
        <?php foreach ($optionalList as $opName):
          $opInfo = $opLookup[$opName] ?? null;
          $icon = $opInfo['icon'] ?? '✓';
          $desc = $opInfo['description'] ?? '';
        ?>
          <span class="px-2 py-1 bg-white border-2 border-brand/40 text-brand-dark rounded-full font-semibold" <?= $desc ? 'title="' . e($desc) . '"' : '' ?>>
            <?= e($icon) ?> <?= e($opName) ?>
          </span>
        <?php endforeach; ?>
      </div>
      <?php if (!empty($j['extras_note'])): ?>
      <div class="mt-2 text-xs text-amber-900 bg-white/60 rounded-lg px-2 py-1.5 border border-amber-100">
        💬 <?= e($j['extras_note']) ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="text-sm mb-3">
      <p><strong>Adresse:</strong> <?= e($j['address'] ?: $j['street'].' '.$j['city']) ?></p>
      <?php if ($j['code_door']): ?><p><strong>Türcode:</strong> <span class="font-mono bg-gray-100 px-2 py-0.5 rounded"><?= e($j['code_door']) ?></span></p><?php endif; ?>
      <?php if ($j['box_code']): ?><p><strong>Box:</strong> <?= e($j['box_code']) ?> | <strong>Client:</strong> <?= e($j['client_code']) ?> | <strong>Deposit:</strong> <?= e($j['deposit_code']) ?></p><?php endif; ?>
      <?php if ($j['wifi_name']): ?><p><strong>WiFi:</strong> <?= e($j['wifi_name']) ?> / <?= e($j['wifi_password']) ?></p><?php endif; ?>
      <?php if ($j['emp_message']): ?><p class="mt-2 bg-yellow-50 p-2 rounded text-yellow-800"><?= autoTranslateHtml($j['emp_message'] ?? '', $partnerLang) ?></p><?php endif; ?>
    </div>

    <?php $chk = $checklistsByService[(int)$j['s_id_fk']] ?? []; if (!empty($chk)):
      $totalItems = count($chk);
      $doneItems = 0;
      foreach ($chk as $ci) {
        if (!empty($completionsByJobItem[$j['j_id'] . ':' . $ci['checklist_id']]['completed'])) $doneItems++;
      }
      $pct = $totalItems > 0 ? round(($doneItems / $totalItems) * 100) : 0;
    ?>
    <!-- Customer Checklist — interactive, partner ticks off items -->
    <div class="mb-3 rounded-xl border border-brand/20 bg-brand-light/50 overflow-hidden">
      <div class="px-4 py-2.5 bg-brand/10 flex items-center justify-between cursor-pointer" onclick="this.nextElementSibling.classList.toggle('hidden'); this.querySelector('.chev').classList.toggle('rotate-180')">
        <div class="flex items-center gap-2 flex-1 min-w-0">
          <span>📋</span>
          <span class="font-bold text-sm text-brand">Check-Liste</span>
          <span class="px-1.5 py-0.5 rounded-full bg-white text-brand text-[10px] font-bold"><?= $doneItems ?> / <?= $totalItems ?></span>
          <!-- Progress bar -->
          <div class="flex-1 h-1.5 bg-white/70 rounded-full overflow-hidden min-w-[60px] max-w-[120px]">
            <div class="h-full bg-brand transition-all" style="width: <?= $pct ?>%"></div>
          </div>
        </div>
        <svg class="chev w-4 h-4 text-brand transition" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
      </div>
      <div class="p-3 space-y-2 <?= $j['job_status'] === 'COMPLETED' ? 'hidden' : '' ?>">
        <?php foreach ($chk as $item):
          $compKey = $j['j_id'] . ':' . $item['checklist_id'];
          $comp = $completionsByJobItem[$compKey] ?? null;
          $isDone = !empty($comp['completed']);
          $prBorder = match($item['priority']) {
            'critical' => 'border-l-red-500 bg-red-50/50',
            'high'     => 'border-l-amber-500 bg-amber-50/50',
            default    => 'border-l-gray-300 bg-white',
          };
        ?>
        <div class="p-2.5 rounded-lg border-l-4 border border-gray-100 <?= $prBorder ?> <?= $isDone ? 'opacity-60' : '' ?>" id="chk-<?= $item['checklist_id'] ?>-<?= $j['j_id'] ?>">
          <div class="flex gap-3">
            <!-- Checkbox -->
            <button type="button"
                    onclick="toggleChecklist(<?= (int)$j['j_id'] ?>, <?= (int)$item['checklist_id'] ?>, this)"
                    class="flex-shrink-0 w-7 h-7 rounded-md border-2 <?= $isDone ? 'bg-brand border-brand' : 'border-gray-300 bg-white hover:border-brand' ?> flex items-center justify-center transition"
                    data-done="<?= $isDone ? '1' : '0' ?>">
              <svg class="w-4 h-4 text-white <?= $isDone ? '' : 'hidden' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
            </button>

            <?php if ($item['photo']): ?>
            <a href="<?= e($item['photo']) ?>" target="_blank" class="flex-shrink-0">
              <img src="<?= e($item['photo']) ?>" class="w-14 h-14 object-cover rounded-lg border" alt=""/>
            </a>
            <?php endif; ?>
            <?php $cMedia = $customerMediaByItem[$item['checklist_id']] ?? []; ?>
            <?php if ($cMedia): ?>
            <div class="flex-shrink-0 flex gap-1">
              <?php foreach (array_slice($cMedia, 0, 3) as $m): ?>
                <?php if ($m['media_type'] === 'video'): ?>
                  <a href="<?= e($m['file_path']) ?>" target="_blank" class="relative w-14 h-14 rounded-lg border block bg-black overflow-hidden" title="<?= e($m['caption']) ?>">
                    <video src="<?= e($m['file_path']) ?>" muted class="w-full h-full object-cover"></video>
                    <div class="absolute inset-0 flex items-center justify-center bg-black/30 text-white text-lg">▶</div>
                  </a>
                <?php else: ?>
                  <a href="<?= e($m['file_path']) ?>" target="_blank" class="flex-shrink-0" title="<?= e($m['caption']) ?>">
                    <img src="<?= e($m['file_path']) ?>" class="w-14 h-14 object-cover rounded-lg border-2 border-blue-300" alt="Kunde-Anweisung"/>
                  </a>
                <?php endif; ?>
              <?php endforeach; ?>
              <?php if (count($cMedia) > 3): ?><span class="w-14 h-14 flex items-center justify-center text-xs text-gray-500 bg-gray-100 rounded-lg border">+<?= count($cMedia)-3 ?></span><?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-1.5 mb-0.5 flex-wrap">
                <?php if ($item['priority'] === 'critical'): ?><span class="text-xs">🔴</span><?php elseif ($item['priority'] === 'high'): ?><span class="text-xs">🟠</span><?php endif; ?>
                <div class="font-semibold text-sm text-gray-900 <?= $isDone ? 'line-through' : '' ?>"><?= e($item['title_tr'] ?? $item['title']) ?></div>
                <?php if ($item['room']): ?><span class="text-[10px] text-gray-400">· <?= e($item['room']) ?></span><?php endif; ?>
              </div>
              <?php if (!empty($item['description_tr']) || !empty($item['description'])): ?>
              <div class="text-xs text-gray-600 leading-snug"><?= nl2br(e($item['description_tr'] ?? $item['description'])) ?></div>
              <?php endif; ?>
              <?php if (($item['title_tr'] ?? null) && $item['title_tr'] !== $item['title']): ?>
              <div class="text-[9px] text-gray-400 italic mt-0.5">DE: <?= e($item['title']) ?></div>
              <?php endif; ?>

              <!-- Photo upload button -->
              <button type="button"
                      onclick="uploadChecklistPhoto(<?= (int)$j['j_id'] ?>, <?= (int)$item['checklist_id'] ?>)"
                      class="mt-1.5 text-[10px] font-semibold text-brand hover:text-brand-dark flex items-center gap-1">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                <?php if (!empty($comp['photo'])): ?>Foto ersetzen<?php else: ?>Beweisfoto<?php endif; ?>
              </button>
              <?php if (!empty($comp['photo'])): ?>
              <a href="<?= e($comp['photo']) ?>" target="_blank" class="inline-block ml-2 text-[10px] text-green-600 font-semibold">✓ Foto hochgeladen</a>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php $jobLocksList = locksForJob($j, $jobLocks); if (!empty($jobLocksList)): ?>
    <!-- Smart Lock — Tür öffnen -->
    <div class="mb-3 p-3 rounded-xl bg-gradient-to-br from-orange-50 to-amber-50 border border-orange-200">
      <div class="flex items-center gap-2 mb-2">
        <span class="text-lg">🔐</span>
        <span class="text-xs font-bold text-orange-900 uppercase tracking-wide">Smart Lock verfügbar</span>
      </div>
      <?php foreach ($jobLocksList as $lk): ?>
      <div class="flex items-center justify-between gap-2 mb-1.5 last:mb-0">
        <div class="flex-1 min-w-0">
          <div class="font-semibold text-sm text-gray-900 truncate"><?= e($lk['device_name'] ?: 'Lock #'.$lk['lock_id']) ?></div>
          <div class="text-[10px] text-gray-500 flex items-center gap-1.5">
            <span class="uppercase"><?= e($lk['provider']) ?></span>
            <?php if ($lk['battery_level'] !== null): ?>
              <span>·</span>
              <span>🔋 <?= (int)$lk['battery_level'] ?>%</span>
            <?php endif; ?>
            <?php if ($lk['last_state']): ?>
              <span>·</span>
              <span><?= e($lk['last_state']) ?></span>
            <?php endif; ?>
          </div>
        </div>
        <button type="button"
                onclick="openLock(<?= (int)$lk['lock_id'] ?>, <?= (int)$j['j_id'] ?>, this)"
                class="px-3 py-2 bg-orange-500 hover:bg-orange-600 text-white rounded-lg text-xs font-bold shadow transition flex items-center gap-1 whitespace-nowrap">
          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z"/></svg>
          Tür öffnen
        </button>
      </div>
      <?php endforeach; ?>
      <div class="text-[10px] text-orange-700 mt-2 italic">
        Öffnung nur 15 Min vor → 30 Min nach Job. Jede Aktion wird protokolliert.
      </div>
    </div>
    <?php endif; ?>

    <!-- Action Buttons -->
    <?php if ($j['job_status'] === 'PENDING' || $j['job_status'] === 'CONFIRMED'): ?>

    <!-- KI VORHER-Foto (vor dem Start) -->
    <div x-data="photoAI_before_<?= $j['j_id'] ?>()" class="mb-3 bg-blue-50 border border-blue-200 rounded-xl p-3">
      <div class="flex items-center gap-2 mb-2">
        <span class="text-lg">📸</span>
        <span class="text-xs font-bold text-blue-800 uppercase tracking-wider">Vorher-Foto (optional)</span>
      </div>
      <label class="flex items-center justify-center gap-2 px-3 py-2 bg-white border border-blue-200 hover:border-blue-400 rounded-lg cursor-pointer text-xs font-semibold text-blue-700 transition">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
        <span x-text="analyzing ? 'Analysiere...' : 'Zustand dokumentieren'"></span>
        <input type="file" accept="image/*" capture="environment" class="hidden" @change="analyzePhoto($event, <?= $j['j_id'] ?>, 'before')" :disabled="analyzing"/>
      </label>
      <div x-show="result" x-cloak class="mt-2 p-2 rounded-lg text-xs" :class="(result?.analysis?.score||0) >= 5 ? 'bg-blue-100 text-blue-800' : 'bg-amber-100 text-amber-800'">
        <span class="font-bold" x-text="'Zustand: ' + (result?.analysis?.score||'?') + '/10'"></span>
        <span x-text="' — ' + (result?.analysis?.verdict || '')"></span>
      </div>
      <div x-show="error" x-cloak class="mt-1 text-[10px] text-red-600" x-text="error"></div>
    </div>

    <form method="POST" onsubmit="return getLocationAndSubmit(this, 'start')">
      <input type="hidden" name="action" value="start_job"/>
      <input type="hidden" name="j_id" value="<?= $j['j_id'] ?>"/>
      <input type="hidden" name="location" id="loc_start_<?= $j['j_id'] ?>"/>
      <button type="submit" class="w-full py-3 bg-brand text-white rounded-xl font-semibold text-lg hover:bg-brand/90 transition">
        ▶ <?= t('jobs.start') ?>
      </button>
    </form>
    <?php elseif ($j['job_status'] === 'RUNNING'): ?>
    <div class="bg-brand/5 rounded-xl p-4 mb-3">
      <div class="flex items-center justify-between">
        <div>
          <p class="text-brand font-medium">Gestartet um <?= substr($j['start_time'],0,5) ?></p>
          <?php
            $start = new DateTime($j['j_date'].' '.$j['start_time']);
            $now = new DateTime();
            $diff = $now->diff($start);
            $runMins = ($diff->h * 60) + $diff->i;
          ?>
          <p class="text-sm text-gray-500">Läuft seit <?= $diff->h ?>h <?= $diff->i ?>min</p>
        </div>
        <div class="flex items-center gap-2">
          <span class="flex items-center gap-1 px-2 py-1 bg-green-100 text-green-700 rounded-lg text-xs font-medium" id="gpsIndicator_<?= $j['j_id'] ?>">
            <span class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></span> GPS aktiv
          </span>
          <button onclick="openCamera(<?= $j['j_id'] ?>)" class="px-3 py-2 bg-brand text-white rounded-lg text-sm font-medium">
            <svg class="w-5 h-5 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            Foto
          </button>
        </div>
      </div>
    </div>
    <form method="POST" enctype="multipart/form-data" onsubmit="return getLocationAndSubmit(this, 'stop')" class="space-y-3">
      <input type="hidden" name="action" value="stop_job"/>
      <input type="hidden" name="j_id" value="<?= $j['j_id'] ?>"/>
      <input type="hidden" name="location" id="loc_stop_<?= $j['j_id'] ?>"/>
      <textarea name="note" required placeholder="Notiz zum Job (Pflicht!)..." class="w-full px-4 py-3 border rounded-xl" rows="2"></textarea>
      <div>
        <label class="block text-sm font-medium text-gray-600 mb-1">Fotos (optional)</label>
        <input type="file" name="photos[]" multiple accept="image/*,video/*" capture="environment" class="w-full px-3 py-2 border rounded-xl text-sm file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:bg-brand/10 file:text-brand file:font-medium"/>
        <p class="text-xs text-gray-500 mt-1">📸 Foto (bis 10MB) · 🎥 Video (bis 50MB) · JPG/PNG/WebP/MP4/WebM · <strong>Mit Zeitstempel für Nachweis</strong></p>
      </div>

      <!-- KI Foto-Check (vor dem Abschließen) -->
      <div x-data="photoAI_<?= $j['j_id'] ?>()" class="bg-purple-50 border border-purple-200 rounded-xl p-3">
        <div class="flex items-center gap-2 mb-2">
          <span class="text-lg">🤖</span>
          <span class="text-xs font-bold text-purple-800 uppercase tracking-wider">KI Sauberkeits-Check</span>
        </div>
        <div class="flex gap-2">
          <label class="flex-1 flex items-center justify-center gap-2 px-3 py-2 bg-white border border-purple-200 hover:border-purple-400 rounded-lg cursor-pointer text-xs font-semibold text-purple-700 transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            <span x-text="analyzing ? 'Analysiere...' : 'Foto prüfen lassen'"></span>
            <input type="file" accept="image/*" capture="environment" class="hidden" @change="analyzePhoto($event, <?= $j['j_id'] ?>, 'after')" :disabled="analyzing"/>
          </label>
        </div>
        <!-- Result -->
        <div x-show="result" x-cloak class="mt-3 p-3 rounded-lg" :class="result?.analysis?.passed ? 'bg-green-50 border border-green-200' : (result?.analysis?.score >= 7 ? 'bg-green-50 border border-green-200' : 'bg-amber-50 border border-amber-200')">
          <div class="flex items-center justify-between mb-2">
            <span class="font-bold text-sm" :class="(result?.analysis?.score || 0) >= 7 ? 'text-green-800' : 'text-amber-800'" x-text="(result?.analysis?.score || 0) >= 7 ? '✓ Sauber' : '⚠ Nachbessern'"></span>
            <span class="text-2xl font-extrabold" :class="(result?.analysis?.score || 0) >= 7 ? 'text-green-600' : 'text-amber-600'" x-text="(result?.analysis?.score || '?') + '/10'"></span>
          </div>
          <div class="text-xs text-gray-700" x-text="result?.analysis?.verdict || result?.analysis?.empfehlung || ''"></div>
          <template x-if="result?.analysis?.issues?.length > 0">
            <ul class="mt-2 text-xs text-gray-600 space-y-0.5">
              <template x-for="issue in result.analysis.issues" :key="issue">
                <li class="flex items-start gap-1"><span class="text-amber-500">•</span><span x-text="issue"></span></li>
              </template>
            </ul>
          </template>
        </div>
        <div x-show="error" x-cloak class="mt-2 text-xs text-red-600" x-text="error"></div>
      </div>

      <button type="submit" class="w-full py-3 bg-red-600 text-white rounded-xl font-semibold text-lg hover:bg-red-700 transition">
        <?= t('jobs.stop') ?>
      </button>
    </form>
    <?php elseif ($j['job_status'] === 'COMPLETED'): ?>
    <div class="bg-green-50 rounded-xl p-3 text-green-800 text-sm">
      Erledigt: <?= substr($j['start_time'],0,5) ?> — <?= substr($j['end_time'],0,5) ?> (<?= round($j['total_hours'],1) ?>h)
      <?php if ($j['job_note']): ?><p class="mt-1 text-gray-600"><?= autoTranslateHtml($j['job_note'] ?? '', $partnerLang) ?></p><?php endif; ?>
      <?php
        $jobPhotos = !empty($j['job_photos']) ? json_decode($j['job_photos'], true) : [];
        if (!empty($jobPhotos)):
      ?>
      <div class="flex gap-2 mt-2 flex-wrap">
        <?php foreach ($jobPhotos as $photo): ?>
        <a href="/uploads/jobs/<?= $j['j_id'] ?>/<?= e($photo) ?>" target="_blank"><img src="/uploads/jobs/<?= $j['j_id'] ?>/<?= e($photo) ?>" class="w-16 h-16 object-cover rounded-lg border" alt="Foto"/></a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php
    // Route planner — show route to next job (only if this one is RUNNING or COMPLETED)
    if (in_array($j['job_status'], ['RUNNING', 'COMPLETED'], true)):
      $nextJob = nextJobAfter($todayJobs, $j);
      if ($nextJob):
        $fromAddr = $j['address'] ?: trim(($j['street'] ?? '') . ' ' . ($j['city'] ?? ''));
        $toAddr = $nextJob['address'] ?: trim(($nextJob['street'] ?? '') . ' ' . ($nextJob['city'] ?? ''));
        $gmapsUrl = 'https://www.google.com/maps/dir/?api=1&origin=' . urlencode($fromAddr) . '&destination=' . urlencode($toAddr) . '&travelmode=transit';
        $gmapsCar = 'https://www.google.com/maps/dir/?api=1&origin=' . urlencode($fromAddr) . '&destination=' . urlencode($toAddr) . '&travelmode=driving';
        $uberUrl = 'https://m.uber.com/ul/?action=setPickup&pickup[formatted_address]=' . urlencode($fromAddr) . '&dropoff[formatted_address]=' . urlencode($toAddr);
    ?>
    <!-- Route to next job -->
    <div class="mt-3 p-3 rounded-xl bg-gradient-to-r from-indigo-50 to-blue-50 border border-indigo-200">
      <div class="flex items-center gap-2 mb-2">
        <span class="text-base">🚕</span>
        <span class="text-[11px] font-bold text-indigo-900 uppercase tracking-wide">Nächster Job um <?= substr($nextJob['j_time'], 0, 5) ?></span>
      </div>
      <div class="text-xs text-gray-700 mb-2 leading-snug">
        <strong><?= e($nextJob['stitle'] ?? '') ?></strong><br/>
        <span class="text-gray-500"><?= e($toAddr) ?></span>
      </div>
      <div class="grid grid-cols-3 gap-2">
        <a href="<?= e($gmapsUrl) ?>" target="_blank" rel="noopener" class="flex items-center justify-center gap-1 px-2 py-2 bg-white hover:bg-indigo-100 border border-indigo-200 rounded-lg text-[11px] font-bold text-indigo-700 transition">
          🚇 BVG
        </a>
        <a href="<?= e($gmapsCar) ?>" target="_blank" rel="noopener" class="flex items-center justify-center gap-1 px-2 py-2 bg-white hover:bg-indigo-100 border border-indigo-200 rounded-lg text-[11px] font-bold text-indigo-700 transition">
          🚗 Auto
        </a>
        <a href="<?= e($uberUrl) ?>" target="_blank" rel="noopener" class="flex items-center justify-center gap-1 px-2 py-2 bg-black hover:bg-gray-800 border border-black rounded-lg text-[11px] font-bold text-white transition">
          Uber
        </a>
      </div>
    </div>
    <?php endif; endif; ?>

    <!-- Direct message to customer -->
    <?php if ($j['job_status'] !== 'CANCELLED'): ?>
    <div class="mt-3" x-data="{ open: false }">
      <button type="button" @click="open = !open" class="text-[11px] font-semibold text-brand hover:text-brand-dark flex items-center gap-1">
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
        Nachricht an <?= e($j['cname'] ?? 'Kunde') ?>
      </button>
      <form x-show="open" x-cloak method="POST" class="mt-2 flex gap-2">
        <input type="hidden" name="action" value="send_customer_msg"/>
        <input type="hidden" name="j_id" value="<?= (int)$j['j_id'] ?>"/>
        <input type="text" name="message" required placeholder="Kurze Nachricht an den Kunden..." class="flex-1 px-3 py-2 border-2 border-gray-100 rounded-lg text-sm focus:border-brand outline-none"/>
        <button type="submit" class="px-3 py-2 bg-brand hover:bg-brand-dark text-white rounded-lg text-xs font-bold">Senden</button>
      </form>
    </div>
    <?php endif; ?>

    <?php if ($j['job_status'] !== 'COMPLETED' && $j['job_status'] !== 'CANCELLED'): ?>
    <form method="POST" class="mt-2" onsubmit="return confirm('Job wirklich stornieren?')">
      <input type="hidden" name="action" value="cancel_job"/><input type="hidden" name="j_id" value="<?= $j['j_id'] ?>"/>
      <button class="text-sm text-red-500 hover:underline">Stornieren</button>
    </form>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php if (empty($todayJobs)): ?>
  <div class="bg-white rounded-xl border p-8 text-center text-gray-400"><?= t('emp.no_jobs_today') ?></div>
  <?php endif; ?>
</div>

<?php if (!empty($upcomingJobs)): ?>
<h3 class="font-semibold mb-3"><?= t('emp.upcoming') ?></h3>
<div class="bg-white rounded-xl border divide-y">
  <?php foreach ($upcomingJobs as $j): ?>
  <div class="px-5 py-3 flex items-center justify-between">
    <div>
      <span class="font-mono text-sm"><?= date('d.m', strtotime($j['j_date'])) ?></span>
      <span class="ml-2"><?= e($j['stitle']) ?></span>
      <span class="text-gray-400 text-sm">— <?= e($j['cname']) ?></span>
    </div>
    <span class="text-sm font-mono"><?= substr($j['j_time'],0,5) ?></span>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
$empIdJs = $user['id'];
$apiKeyJs = API_KEY;
$hasRunningJob = false;
$runningJobId = null;
foreach ($todayJobs as $tj) {
    if ($tj['job_status'] === 'RUNNING') { $hasRunningJob = true; $runningJobId = $tj['j_id']; break; }
}
$script = <<<JS
function getLocationAndSubmit(form, type) {
    const jid = form.querySelector('[name="j_id"]').value;
    const locField = form.querySelector('[name="location"]');

    if (!navigator.geolocation) {
        locField.value = 'Geolocation not supported';
        return true;
    }

    const btn = form.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.textContent = 'GPS wird erfasst...';

    navigator.geolocation.getCurrentPosition(
        (pos) => {
            locField.value = pos.coords.latitude + ',' + pos.coords.longitude;
            form.submit();
        },
        (err) => {
            locField.value = 'GPS error: ' + err.message;
            form.submit();
        },
        { enableHighAccuracy: true, timeout: 10000 }
    );
    return false;
}

// Live GPS Tracking — sends position every 30s while job is RUNNING
(function() {
    const empId = $empIdJs;
    const runningJobId = $runningJobId;
    if (!runningJobId || !navigator.geolocation) return;

    function sendGPS() {
        navigator.geolocation.getCurrentPosition(
            (pos) => {
                fetch('/api/index.php?action=gps/update', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-API-Key': '$apiKeyJs' },
                    body: JSON.stringify({
                        emp_id: empId,
                        j_id: runningJobId,
                        lat: pos.coords.latitude,
                        lng: pos.coords.longitude,
                        accuracy: pos.coords.accuracy
                    })
                }).catch(() => {});
            },
            () => {}, // silently ignore GPS errors during tracking
            { enableHighAccuracy: true, timeout: 15000 }
        );
    }

    // Send immediately + every 30s
    sendGPS();
    setInterval(sendGPS, 30000);

    // Update GPS indicator
    var indicator = document.getElementById('gpsIndicator_' + runningJobId);
    if (indicator) {
        setInterval(function() {
            indicator.querySelector('span:first-child').style.opacity = indicator.querySelector('span:first-child').style.opacity === '0.3' ? '1' : '0.3';
        }, 1500);
    }
})();

// Checklist — toggle done state for a single item
function toggleChecklist(jobId, checklistId, btn) {
    var done = btn.dataset.done === '1';
    var newDone = !done;
    var fd = new FormData();
    fd.append('job_id', jobId);
    fd.append('checklist_id', checklistId);
    fd.append('completed', newDone ? '1' : '0');
    btn.disabled = true;
    fetch('/api/checklist-complete.php', { method: 'POST', credentials: 'same-origin', body: fd })
      .then(r => r.json())
      .then(d => {
        if (d.success) {
          btn.dataset.done = newDone ? '1' : '0';
          btn.classList.toggle('bg-brand', newDone);
          btn.classList.toggle('border-brand', newDone);
          btn.classList.toggle('border-gray-300', !newDone);
          btn.classList.toggle('bg-white', !newDone);
          var svg = btn.querySelector('svg');
          if (svg) svg.classList.toggle('hidden', !newDone);
          // Strike through title
          var card = btn.closest('[id^="chk-"]');
          if (card) {
            card.classList.toggle('opacity-60', newDone);
            var title = card.querySelector('.font-semibold.text-sm');
            if (title) title.classList.toggle('line-through', newDone);
          }
        } else {
          alert(d.error || 'Fehler');
        }
        btn.disabled = false;
      })
      .catch(e => { alert('Netzwerk-Fehler'); btn.disabled = false; });
}

// Checklist — upload proof photo for a single item
function uploadChecklistPhoto(jobId, checklistId) {
    var input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/*,video/*';
    input.capture = 'environment';
    input.onchange = function() {
      if (!input.files.length) return;
      var fd = new FormData();
      fd.append('job_id', jobId);
      fd.append('checklist_id', checklistId);
      fd.append('completed', '1');
      var f = input.files[0];
      var isVideo = f.type.startsWith('video/');
      fd.append(isVideo ? 'video' : 'photo', f);
      fetch('/api/checklist-complete.php', { method: 'POST', credentials: 'same-origin', body: fd })
        .then(r => r.json())
        .then(d => {
          if (d.success) location.reload();
          else alert(d.error || 'Upload fehlgeschlagen');
        })
        .catch(e => alert('Netzwerk-Fehler'));
    };
    input.click();
}

// Smart Lock — Tür öffnen (POST to lock-action API, respects 15min/30min window)
function openLock(lockId, jobId, btn) {
    if (!confirm('Tür jetzt öffnen? Diese Aktion wird protokolliert.')) return;
    var original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '⏳ Öffne...';
    fetch('/api/lock-action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ action: 'unlock', lock_id: lockId, job_id: jobId })
    })
    .then(function(r) { return r.json().then(function(d){ return { ok: r.ok, data: d }; }); })
    .then(function(res) {
        if (res.ok && res.data.success) {
            btn.innerHTML = '✓ Offen';
            btn.classList.remove('bg-orange-500','hover:bg-orange-600');
            btn.classList.add('bg-green-500');
            setTimeout(function(){ btn.innerHTML = original; btn.disabled = false; btn.classList.remove('bg-green-500'); btn.classList.add('bg-orange-500','hover:bg-orange-600'); }, 4000);
        } else {
            var msg = res.data.reason || res.data.error || 'Fehler';
            var map = { too_early: 'Zu früh — frühestens 15 Min vor Job', too_late: 'Zu spät — Zeitfenster abgelaufen', no_matching_job: 'Kein passender Job', service_mismatch: 'Schloss gehört zu anderem Service' };
            alert('Fehler: ' + (map[msg] || msg));
            btn.innerHTML = original; btn.disabled = false;
        }
    })
    .catch(function(e) {
        alert('Netzwerkfehler: ' + e.message);
        btn.innerHTML = original; btn.disabled = false;
    });
}

// Camera direct open
function openCamera(jid) {
    var input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/*';
    input.capture = 'environment';
    input.multiple = true;
    input.onchange = function() {
        if (!input.files.length) return;
        var formData = new FormData();
        for (var i = 0; i < input.files.length; i++) {
            formData.append('photos[]', input.files[i]);
        }
        formData.append('j_id', jid);
        fetch('/api/index.php?action=job/photos', {
            method: 'POST',
            headers: { 'X-API-Key': '$apiKeyJs' },
            body: formData
        }).then(function(r){return r.json()}).then(function(d){
            if (d.success) {
                var btn = event.target.closest ? event.target.closest('button') : null;
                if (btn) { btn.textContent = d.data.count + ' Foto(s) hochgeladen'; setTimeout(function(){ btn.innerHTML = '<svg class="w-5 h-5 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg> Foto'; }, 2000); }
            }
        });
    };
    input.click();
}
JS;

// Photo AI analysis functions (one per job to avoid Alpine scope issues)
foreach ($todayJobs as $j) {
    $jid = (int)$j['j_id'];
    // Before photo function (pre-start)
    echo "<script>function photoAI_before_{$jid}() { return {
      analyzing: false, result: null, error: null,
      async analyzePhoto(event, jobId, type) {
        const file = event.target.files?.[0];
        if (!file) return;
        this.analyzing = true; this.error = null; this.result = null;
        const fd = new FormData();
        fd.append('photo', file);
        fd.append('job_id', jobId);
        fd.append('type', type);
        try {
          const r = await fetch('/api/photo-analysis.php', { method: 'POST', body: fd, credentials: 'same-origin' });
          const d = await r.json();
          if (d.success) { this.result = d; }
          else { this.error = d.error || 'Analyse fehlgeschlagen'; }
        } catch (e) { this.error = 'Netzwerk-Fehler: ' + e.message; }
        this.analyzing = false;
      }
    }; }</script>\n";
    // After photo function (post-completion)
    echo "<script>function photoAI_{$jid}() { return {
      analyzing: false, result: null, error: null,
      async analyzePhoto(event, jobId, type) {
        const file = event.target.files?.[0];
        if (!file) return;
        this.analyzing = true; this.error = null; this.result = null;
        const fd = new FormData();
        fd.append('photo', file);
        fd.append('job_id', jobId);
        fd.append('type', type);
        try {
          const r = await fetch('/api/photo-analysis.php', { method: 'POST', body: fd, credentials: 'same-origin' });
          const d = await r.json();
          if (d.success) { this.result = d; }
          else { this.error = d.error || 'Analyse fehlgeschlagen'; }
        } catch (e) { this.error = 'Netzwerk-Fehler: ' + e.message; }
        this.analyzing = false;
      }
    }; }</script>\n";
}

include __DIR__ . '/../includes/footer.php';
?>
