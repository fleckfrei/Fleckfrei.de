<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/email.php';
requireEmployee();
$title = 'Meine Jobs'; $page = 'dashboard';
$user = me();

// Handle Start/Stop/Cancel
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { header('Location: /employee/'); exit; }
    $act = $_POST['action'] ?? '';
    $jid = $_POST['j_id'] ?? 0;
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
                // MIME type check
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($tmp);
                $allowedMimes = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/heic'=>'heic'];
                if (!isset($allowedMimes[$mime])) continue;
                // Verify it's a real image
                if ($mime !== 'image/heic' && @getimagesize($tmp) === false) continue;
                $ext = $allowedMimes[$mime];
                $fname = bin2hex(random_bytes(8)) . '.' . $ext;
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

$todayJobs = all("SELECT j.*, s.title as stitle, s.street, s.city, s.box_code, s.client_code, s.deposit_code, s.wifi_name, s.wifi_password,
    c.name as cname, c.phone as cphone, c.customer_type as ctype
    FROM jobs j LEFT JOIN services s ON j.s_id_fk=s.s_id LEFT JOIN customer c ON j.customer_id_fk=c.customer_id
    WHERE j.emp_id_fk=? AND j.j_date=? AND j.status=1 AND j.job_status!='CANCELLED'
    ORDER BY j.j_time", [$empId, $today]);

$upcomingJobs = all("SELECT j.*, s.title as stitle, c.name as cname
    FROM jobs j LEFT JOIN services s ON j.s_id_fk=s.s_id LEFT JOIN customer c ON j.customer_id_fk=c.customer_id
    WHERE j.emp_id_fk=? AND j.j_date>? AND j.status=1 AND j.job_status!='CANCELLED'
    ORDER BY j.j_date, j.j_time LIMIT 10", [$empId, $today]);

$empData = one("SELECT * FROM employee WHERE emp_id=?", [$empId]);

include __DIR__ . '/../includes/layout.php';
?>

<?php if(!empty($_GET['started'])): ?><div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4">Job gestartet! GPS erfasst.</div><?php endif; ?>
<?php if(!empty($_GET['stopped'])): ?><div class="bg-blue-50 border border-blue-200 text-blue-800 px-4 py-3 rounded-xl mb-4">Job beendet! Arbeitszeit gespeichert.</div><?php endif; ?>

<div class="mb-6">
  <h2 class="text-lg font-semibold mb-1">Hallo <?= e($empData['name']) ?>!</h2>
  <p class="text-gray-500">Heute: <?= date('d.m.Y') ?> — <?= count($todayJobs) ?> Job(s)</p>
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
    <div class="text-sm mb-3">
      <p><strong>Adresse:</strong> <?= e($j['address'] ?: $j['street'].' '.$j['city']) ?></p>
      <?php if ($j['code_door']): ?><p><strong>Türcode:</strong> <span class="font-mono bg-gray-100 px-2 py-0.5 rounded"><?= e($j['code_door']) ?></span></p><?php endif; ?>
      <?php if ($j['box_code']): ?><p><strong>Box:</strong> <?= e($j['box_code']) ?> | <strong>Client:</strong> <?= e($j['client_code']) ?> | <strong>Deposit:</strong> <?= e($j['deposit_code']) ?></p><?php endif; ?>
      <?php if ($j['wifi_name']): ?><p><strong>WiFi:</strong> <?= e($j['wifi_name']) ?> / <?= e($j['wifi_password']) ?></p><?php endif; ?>
      <?php if ($j['emp_message']): ?><p class="mt-2 bg-yellow-50 p-2 rounded text-yellow-800"><?= e($j['emp_message']) ?></p><?php endif; ?>
    </div>

    <!-- Action Buttons -->
    <?php if ($j['job_status'] === 'PENDING' || $j['job_status'] === 'CONFIRMED'): ?>
    <form method="POST" onsubmit="return getLocationAndSubmit(this, 'start')">
      <input type="hidden" name="action" value="start_job"/>
      <input type="hidden" name="j_id" value="<?= $j['j_id'] ?>"/>
      <input type="hidden" name="location" id="loc_start_<?= $j['j_id'] ?>"/>
      <button type="submit" class="w-full py-3 bg-brand text-white rounded-xl font-semibold text-lg hover:bg-brand/90 transition">
        ▶ JOB STARTEN
      </button>
    </form>
    <?php elseif ($j['job_status'] === 'RUNNING'): ?>
    <div class="bg-brand/5 rounded-xl p-4 mb-3">
      <p class="text-brand font-medium">Gestartet um <?= substr($j['start_time'],0,5) ?></p>
      <?php
        $start = new DateTime($j['j_date'].' '.$j['start_time']);
        $now = new DateTime();
        $diff = $now->diff($start);
        $runMins = ($diff->h * 60) + $diff->i;
      ?>
      <p class="text-sm text-gray-500">Läuft seit <?= $diff->h ?>h <?= $diff->i ?>min</p>
    </div>
    <form method="POST" enctype="multipart/form-data" onsubmit="return getLocationAndSubmit(this, 'stop')" class="space-y-3">
      <input type="hidden" name="action" value="stop_job"/>
      <input type="hidden" name="j_id" value="<?= $j['j_id'] ?>"/>
      <input type="hidden" name="location" id="loc_stop_<?= $j['j_id'] ?>"/>
      <textarea name="note" required placeholder="Notiz zum Job (Pflicht!)..." class="w-full px-4 py-3 border rounded-xl" rows="2"></textarea>
      <div>
        <label class="block text-sm font-medium text-gray-600 mb-1">Fotos (optional)</label>
        <input type="file" name="photos[]" multiple accept="image/*" capture="environment" class="w-full px-3 py-2 border rounded-xl text-sm file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:bg-brand/10 file:text-brand file:font-medium"/>
        <p class="text-xs text-gray-400 mt-1">Max. 10MB pro Foto. JPG, PNG, WebP.</p>
      </div>
      <button type="submit" class="w-full py-3 bg-red-600 text-white rounded-xl font-semibold text-lg hover:bg-red-700 transition">
        JOB BEENDEN
      </button>
    </form>
    <?php elseif ($j['job_status'] === 'COMPLETED'): ?>
    <div class="bg-green-50 rounded-xl p-3 text-green-800 text-sm">
      Erledigt: <?= substr($j['start_time'],0,5) ?> — <?= substr($j['end_time'],0,5) ?> (<?= round($j['total_hours'],1) ?>h)
      <?php if ($j['job_note']): ?><p class="mt-1 text-gray-600"><?= e($j['job_note']) ?></p><?php endif; ?>
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

    <?php if ($j['job_status'] !== 'COMPLETED' && $j['job_status'] !== 'CANCELLED'): ?>
    <form method="POST" class="mt-2" onsubmit="return confirm('Job wirklich stornieren?')">
      <input type="hidden" name="action" value="cancel_job"/><input type="hidden" name="j_id" value="<?= $j['j_id'] ?>"/>
      <button class="text-sm text-red-500 hover:underline">Stornieren</button>
    </form>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php if (empty($todayJobs)): ?>
  <div class="bg-white rounded-xl border p-8 text-center text-gray-400">Keine Jobs für heute.</div>
  <?php endif; ?>
</div>

<?php if (!empty($upcomingJobs)): ?>
<h3 class="font-semibold mb-3">Kommende Jobs</h3>
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
})();
JS;
include __DIR__ . '/../includes/footer.php';
?>
