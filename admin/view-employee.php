<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$eid = (int)($_GET['id'] ?? 0);
if (!$eid) { header('Location: /admin/employees.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { header('Location: ' . $_SERVER['REQUEST_URI']); exit; }
    $act = $_POST['action'] ?? '';
    if ($act === 'save') {
        $pw = !empty($_POST['password']) ? $_POST['password'] : null;
        $sql = "UPDATE employee SET name=?,surname=?,email=?,phone=?,tariff=?,location=?,nationality=?,status=?,notes=?,partner_type=?,contract_type=?,email_permissions=?";
        $params = [$_POST['name'],$_POST['surname']??'',$_POST['email'],$_POST['phone']??'',$_POST['tariff']??0,$_POST['location']??'',$_POST['nationality']??'',$_POST['status'],$_POST['notes']??'',$_POST['partner_type']??'cleaner',$_POST['contract_type']??'freelance'];
        // Build permissions JSON
        $allPerms = ['portal_dashboard','portal_jobs','portal_schedule','portal_earnings','portal_documents','portal_messages','portal_profile','can_start_stop','can_cancel','can_upload_photos','can_see_customer_info','can_see_address','can_see_phone','can_see_price'];
        $perms = [];
        foreach ($allPerms as $p) $perms[$p] = !empty($_POST['perm_'.$p]) ? 1 : 0;
        $params[] = json_encode($perms);
        if ($pw) { $sql .= ",password=?"; $params[] = $pw; }
        $sql .= " WHERE emp_id=?";
        $params[] = $eid;
        q($sql, $params);
        audit('update', 'employee', $eid, 'Profil bearbeitet');
        header("Location: /admin/view-employee.php?id=$eid&saved=1"); exit;
    }
}

$emp = one("SELECT * FROM employee WHERE emp_id=?", [$eid]);
if (!$emp) { header('Location: /admin/employees.php'); exit; }

$title = $emp['name'] . ' ' . ($emp['surname']??''); $page = 'employees';
$tab = $_GET['tab'] ?? 'info';

// Stats
$totalJobs = val("SELECT COUNT(*) FROM jobs WHERE emp_id_fk=? AND status=1", [$eid]);
$completedJobs = val("SELECT COUNT(*) FROM jobs WHERE emp_id_fk=? AND status=1 AND job_status='COMPLETED'", [$eid]);
$cancelledJobs = val("SELECT COUNT(*) FROM jobs WHERE emp_id_fk=? AND status=1 AND job_status='CANCELLED'", [$eid]);
$totalHours = val("SELECT COALESCE(SUM(COALESCE(total_hours,j_hours)),0) FROM jobs WHERE emp_id_fk=? AND status=1 AND job_status='COMPLETED'", [$eid]);
$totalEarnings = $totalHours * ($emp['tariff'] ?? 0);
$pendingJobs = val("SELECT COUNT(*) FROM jobs WHERE emp_id_fk=? AND status=1 AND job_status='PENDING' AND j_date>=CURDATE()", [$eid]);

// Intelligence
$firstJob = one("SELECT j_date FROM jobs WHERE emp_id_fk=? AND status=1 ORDER BY j_date ASC LIMIT 1", [$eid]);
$lastJob = one("SELECT j_date FROM jobs WHERE emp_id_fk=? AND status=1 ORDER BY j_date DESC LIMIT 1", [$eid]);
$partnerSince = $firstJob ? $firstJob['j_date'] : null;
$lastActivity = $lastJob ? $lastJob['j_date'] : null;
$monthsActive = $partnerSince ? max(1, round((time() - strtotime($partnerSince)) / (30*86400))) : 1;
$jobsPerMonth = round($totalJobs / $monthsActive, 1);
$hoursPerMonth = round($totalHours / $monthsActive, 1);
$earningsPerMonth = round($totalEarnings / $monthsActive, 2);
$avgJobDuration = $completedJobs > 0 ? round($totalHours / $completedJobs, 1) : 0;
$cancelRate = $totalJobs > 0 ? round(($cancelledJobs / $totalJobs) * 100) : 0;
$uniqueCustomers = val("SELECT COUNT(DISTINCT customer_id_fk) FROM jobs WHERE emp_id_fk=? AND status=1", [$eid]);
$topCustomer = one("SELECT c.name, COUNT(*) as cnt FROM jobs j LEFT JOIN customer c ON j.customer_id_fk=c.customer_id WHERE j.emp_id_fk=? AND j.status=1 GROUP BY j.customer_id_fk ORDER BY cnt DESC LIMIT 1", [$eid]);
try { $msgCount = valLocal("SELECT COUNT(*) FROM messages WHERE (sender_type='employee' AND sender_id=?) OR (recipient_type='employee' AND recipient_id=?)", [$eid, $eid]); } catch (Exception $e) { $msgCount = 0; }

// Jobs
$jobs = all("SELECT j.*, c.name as cname, c.customer_type as ctype, s.title as stitle FROM jobs j LEFT JOIN customer c ON j.customer_id_fk=c.customer_id LEFT JOIN services s ON j.s_id_fk=s.s_id WHERE j.emp_id_fk=? AND j.status=1 ORDER BY j.j_date DESC LIMIT 100", [$eid]);

include __DIR__ . '/../includes/layout.php';
?>

<?php if(!empty($_GET['saved'])): ?><div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4">Gespeichert!</div><?php endif; ?>

<!-- Header -->
<div class="flex flex-wrap items-center justify-between gap-4 mb-5">
  <div class="flex items-center gap-4">
    <a href="/admin/employees.php" class="text-gray-400 hover:text-gray-600">
      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
    </a>
    <div class="w-12 h-12 rounded-xl bg-brand text-white flex items-center justify-center text-lg font-bold"><?= strtoupper(substr($emp['name'],0,1)) ?></div>
    <div>
      <h2 class="text-lg font-semibold text-gray-900"><?= e($emp['name']) ?> <?= e($emp['surname']) ?></h2>
      <div class="flex items-center gap-2 text-xs text-gray-400">
        <span class="px-2 py-0.5 rounded-full bg-violet-50 text-violet-700 font-medium"><?= e($emp['partner_type'] ?? 'Cleaner') ?></span>
        <span class="px-2 py-0.5 rounded-full bg-blue-50 text-blue-700 font-medium"><?= e($emp['contract_type'] ?? 'Freelance') ?></span>
        <span>#<?= $emp['emp_id'] ?></span>
        <span><?= $emp['status'] ? 'Aktiv' : 'Inaktiv' ?></span>
      </div>
    </div>
  </div>
  <div class="flex gap-2">
    <?php if ($emp['phone']): $ph = preg_replace('/[^+0-9]/','',$emp['phone']); ?>
    <a href="tel:<?= e($ph) ?>" class="px-3 py-2 border rounded-lg text-sm hover:bg-gray-50">Anrufen</a>
    <a href="https://wa.me/<?= ltrim($ph,'+') ?>" target="_blank" class="px-3 py-2 bg-green-600 text-white rounded-lg text-sm">WhatsApp</a>
    <?php endif; ?>
    <a href="/admin/employees.php?impersonate=<?= $eid ?>" class="px-3 py-2 bg-blue-600 text-white rounded-lg text-sm">Als Partner einloggen</a>
  </div>
</div>

<!-- KPI Cards -->
<div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-2 mb-4">
  <div class="bg-white rounded-xl border p-3 text-center"><div class="text-xl font-bold"><?= $totalJobs ?></div><div class="text-[10px] text-gray-400">Jobs</div></div>
  <div class="bg-white rounded-xl border p-3 text-center"><div class="text-xl font-bold text-green-700"><?= $completedJobs ?></div><div class="text-[10px] text-gray-400">Erledigt</div></div>
  <div class="bg-white rounded-xl border p-3 text-center"><div class="text-xl font-bold text-amber-600"><?= $pendingJobs ?></div><div class="text-[10px] text-gray-400">Offen</div></div>
  <div class="bg-white rounded-xl border p-3 text-center"><div class="text-xl font-bold text-red-500"><?= $cancelledJobs ?></div><div class="text-[10px] text-gray-400">Storniert</div></div>
  <div class="bg-white rounded-xl border p-3 text-center"><div class="text-xl font-bold text-brand"><?= round($totalHours,1) ?>h</div><div class="text-[10px] text-gray-400">Stunden</div></div>
  <div class="bg-white rounded-xl border p-3 text-center"><div class="text-xl font-bold text-red-600"><?= money($totalEarnings) ?></div><div class="text-[10px] text-gray-400">Verdienst</div></div>
  <div class="bg-white rounded-xl border p-3 text-center"><div class="text-xl font-bold"><?= $uniqueCustomers ?></div><div class="text-[10px] text-gray-400">Kunden</div></div>
  <div class="bg-white rounded-xl border p-3 text-center"><div class="text-xl font-bold"><?= $msgCount ?></div><div class="text-[10px] text-gray-400">Nachr.</div></div>
</div>

<!-- Partner Intelligence -->
<div class="bg-white rounded-xl border p-4 mb-4">
  <h4 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">Partner-Intelligence</h4>
  <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4 text-sm">
    <div><div class="text-gray-400 text-xs">Partner seit</div><div class="font-medium"><?= $partnerSince ? date('d.m.Y', strtotime($partnerSince)) : '-' ?></div></div>
    <div><div class="text-gray-400 text-xs">Letzte Aktivität</div><div class="font-medium"><?= $lastActivity ? date('d.m.Y', strtotime($lastActivity)) : '-' ?></div></div>
    <div><div class="text-gray-400 text-xs">Jobs / Monat</div><div class="font-medium"><?= $jobsPerMonth ?></div></div>
    <div><div class="text-gray-400 text-xs">Stunden / Monat</div><div class="font-medium"><?= $hoursPerMonth ?>h</div></div>
    <div><div class="text-gray-400 text-xs">Verdienst / Monat</div><div class="font-medium"><?= money($earningsPerMonth) ?></div></div>
    <div><div class="text-gray-400 text-xs">⌀ Job-Dauer</div><div class="font-medium"><?= $avgJobDuration ?>h</div></div>
    <div><div class="text-gray-400 text-xs">Tarif</div><div class="font-medium"><?= money($emp['tariff'] ?? 0) ?>/h</div></div>
    <div><div class="text-gray-400 text-xs">Storno-Rate</div><div class="font-medium <?= $cancelRate > 15 ? 'text-red-600' : 'text-green-600' ?>"><?= $cancelRate ?>%</div></div>
    <?php if ($topCustomer): ?><div><div class="text-gray-400 text-xs">Top Kunde</div><div class="font-medium"><?= e($topCustomer['name']) ?> <span class="text-xs text-gray-400">(<?= $topCustomer['cnt'] ?>x)</span></div></div><?php endif; ?>
    <div><div class="text-gray-400 text-xs">Zuverlässigkeit</div><div class="font-medium <?= $cancelRate < 10 ? 'text-green-600' : ($cancelRate < 20 ? 'text-yellow-600' : 'text-red-600') ?>"><?= $cancelRate < 10 ? '★★★★★' : ($cancelRate < 20 ? '★★★★☆' : '★★★☆☆') ?></div></div>
  </div>
</div>

<?php
// OSINT
$empEmail = $emp['email'] ?? '';
$empDomain = $empEmail ? substr($empEmail, strpos($empEmail, '@') + 1) : '';
$empName = trim(($emp['name'] ?? '') . ' ' . ($emp['surname'] ?? ''));
$empPhone = $emp['phone'] ?? '';
$freeProviders = ['gmail.com','yahoo.com','hotmail.com','outlook.com','gmx.de','web.de','t-online.de','icloud.com','protonmail.com'];
$empEmailType = in_array(strtolower($empDomain), $freeProviders) ? 'Privat' : 'Geschäftlich';
$empMxValid = false;
if ($empDomain) { $mx = []; @getmxrr($empDomain, $mx); $empMxValid = !empty($mx); }
?>
<div class="bg-white rounded-xl border p-4 mb-4">
  <h4 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">Digital Footprint</h4>
  <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
    <div class="bg-gray-50 rounded-lg p-3">
      <div class="text-xs font-semibold text-gray-500 mb-2">E-Mail</div>
      <div class="space-y-1 text-sm">
        <div class="flex justify-between"><span class="text-gray-400">E-Mail:</span><span class="font-mono text-xs"><?= e($empEmail) ?></span></div>
        <div class="flex justify-between"><span class="text-gray-400">MX:</span><span class="<?= $empMxValid ? 'text-green-600' : 'text-red-600' ?>"><?= $empMxValid ? '✓ Gültig' : '✗ Ungültig' ?></span></div>
        <div class="flex justify-between"><span class="text-gray-400">Typ:</span><span><?= $empEmailType ?></span></div>
      </div>
    </div>
    <div class="bg-gray-50 rounded-lg p-3">
      <div class="text-xs font-semibold text-gray-500 mb-2">Suche</div>
      <div class="flex flex-wrap gap-1.5">
        <a href="https://www.google.com/search?q=<?= urlencode($empName . ' Berlin') ?>" target="_blank" class="px-2 py-1 text-xs bg-white border rounded-lg hover:bg-gray-100">🔍 Google</a>
        <a href="https://www.linkedin.com/search/results/all/?keywords=<?= urlencode($empName) ?>" target="_blank" class="px-2 py-1 text-xs bg-blue-50 text-blue-700 border border-blue-200 rounded-lg hover:bg-blue-100">LinkedIn</a>
        <a href="https://www.xing.com/search/members?keywords=<?= urlencode($empName) ?>" target="_blank" class="px-2 py-1 text-xs bg-green-50 text-green-700 border border-green-200 rounded-lg hover:bg-green-100">XING</a>
        <?php if ($empEmail): ?><a href="https://www.google.com/search?q=<?= urlencode('"' . $empEmail . '"') ?>" target="_blank" class="px-2 py-1 text-xs bg-yellow-50 text-yellow-700 border border-yellow-200 rounded-lg hover:bg-yellow-100">📧 Email</a><?php endif; ?>
        <?php if ($empPhone): ?><a href="https://www.tellows.de/num/<?= preg_replace('/[^0-9]/','',$empPhone) ?>" target="_blank" class="px-2 py-1 text-xs bg-orange-50 text-orange-700 border border-orange-200 rounded-lg hover:bg-orange-100">📞 Tellows</a><?php endif; ?>
      </div>
    </div>
    <div class="bg-gray-50 rounded-lg p-3">
      <div class="text-xs font-semibold text-gray-500 mb-2">Kontakt</div>
      <div class="space-y-1 text-sm">
        <div class="flex justify-between"><span class="text-gray-400">Telefon:</span><span><?= e($empPhone) ?></span></div>
        <div class="flex justify-between"><span class="text-gray-400">Ort:</span><span><?= e($emp['location'] ?? '-') ?></span></div>
        <div class="flex justify-between"><span class="text-gray-400">Nationalität:</span><span><?= e($emp['nationality'] ?? '-') ?></span></div>
      </div>
    </div>
  </div>
</div>

<!-- Tabs -->
<div class="flex gap-1 mb-5 bg-white rounded-xl border p-1 w-fit">
  <?php foreach (['info'=>'Stammdaten','jobs'=>'Jobs ('.$totalJobs.')','earnings'=>'Verdienst','rights'=>'Rechte'] as $tk=>$tl):
    $active = $tab===$tk ? 'bg-brand text-white' : 'text-gray-500 hover:bg-gray-100';
  ?>
  <a href="?id=<?= $eid ?>&tab=<?= $tk ?>" class="px-4 py-2 rounded-lg text-sm font-medium transition <?= $active ?>"><?= $tl ?></a>
  <?php endforeach; ?>
</div>

<?php if ($tab === 'info'): ?>
<form method="POST">
  <input type="hidden" name="action" value="save"/>
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <div class="bg-white rounded-xl border p-5">
      <h3 class="font-semibold mb-4">Persönliche Daten</h3>
      <div class="grid grid-cols-2 gap-3">
        <div><label class="block text-xs font-medium text-gray-500 mb-1">Name *</label><input name="name" value="<?= e($emp['name']) ?>" required class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
        <div><label class="block text-xs font-medium text-gray-500 mb-1">Nachname</label><input name="surname" value="<?= e($emp['surname']) ?>" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
        <div><label class="block text-xs font-medium text-gray-500 mb-1">E-Mail</label><input type="email" name="email" value="<?= e($emp['email']) ?>" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
        <div><label class="block text-xs font-medium text-gray-500 mb-1">Telefon</label><input name="phone" value="<?= e($emp['phone']) ?>" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
        <div><label class="block text-xs font-medium text-gray-500 mb-1">Partner-Typ</label>
          <select name="partner_type" class="w-full px-3 py-2 border rounded-xl text-sm">
            <?php foreach(['Cleaner','Sub-Partner','Teamleiter','Supervisor','Freelancer','Firma'] as $t): ?>
            <option value="<?= $t ?>" <?= ($emp['partner_type']??'')===$t?'selected':'' ?>><?= $t ?></option>
            <?php endforeach; ?>
          </select></div>
        <div><label class="block text-xs font-medium text-gray-500 mb-1">Vertrag</label>
          <select name="contract_type" class="w-full px-3 py-2 border rounded-xl text-sm">
            <?php foreach(['Freelance'=>'Freelance','Minijob'=>'Minijob (520€)','Teilzeit'=>'Teilzeit','Vollzeit'=>'Vollzeit','Subunternehmer'=>'Subunternehmer','Praktikant'=>'Praktikant'] as $k=>$v): ?>
            <option value="<?= $k ?>" <?= ($emp['contract_type']??'')===$k?'selected':'' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select></div>
        <div><label class="block text-xs font-medium text-gray-500 mb-1">Tarif (<?= CURRENCY ?>/h)</label><input type="number" name="tariff" value="<?= $emp['tariff'] ?>" step="0.5" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
        <div><label class="block text-xs font-medium text-gray-500 mb-1">Status</label>
          <select name="status" class="w-full px-3 py-2 border rounded-xl text-sm"><option value="1" <?= $emp['status']?'selected':'' ?>>Aktiv</option><option value="0" <?= !$emp['status']?'selected':'' ?>>Inaktiv</option></select></div>
        <div><label class="block text-xs font-medium text-gray-500 mb-1">Ort</label><input name="location" value="<?= e($emp['location']??'') ?>" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
        <div><label class="block text-xs font-medium text-gray-500 mb-1">Nationalität</label><input name="nationality" value="<?= e($emp['nationality']??'') ?>" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
        <div><label class="block text-xs font-medium text-gray-500 mb-1">Passwort setzen</label><input type="password" name="password" placeholder="Leer = unverändert" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
      </div>
      <div class="mt-3"><label class="block text-xs font-medium text-gray-500 mb-1">Notizen</label><textarea name="notes" rows="3" class="w-full px-3 py-2 border rounded-xl text-sm"><?= e($emp['notes']??'') ?></textarea></div>
      <button type="submit" class="w-full px-4 py-2.5 bg-brand text-white rounded-xl font-medium mt-3">Speichern</button>
    </div>
    <div class="bg-white rounded-xl border p-5">
      <h3 class="font-semibold mb-3">System-Info</h3>
      <div class="grid grid-cols-2 gap-2 text-sm">
        <div class="text-gray-500">Partner-ID</div><div class="font-mono"><?= $emp['emp_id'] ?></div>
        <div class="text-gray-500">Erstellt</div><div><?= $emp['created_at'] ?? '-' ?></div>
        <div class="text-gray-500">WhatsApp ID</div><div class="font-mono"><?= e($emp['wa_id'] ?? '-') ?></div>
      </div>
    </div>
  </div>
<?php // Hidden fields for rights tab (preserve existing perms when saving from info tab)
$perms = json_decode($emp['email_permissions'] ?? '{}', true);
if (!is_array($perms)) $perms = ['portal_dashboard'=>1,'portal_jobs'=>1,'portal_schedule'=>1,'portal_earnings'=>1,'portal_profile'=>1,'can_start_stop'=>1,'can_upload_photos'=>1,'can_see_address'=>1];
$allPermsKeys = ['portal_dashboard','portal_jobs','portal_schedule','portal_earnings','portal_documents','portal_messages','portal_profile','can_start_stop','can_cancel','can_upload_photos','can_see_customer_info','can_see_address','can_see_phone','can_see_price'];
foreach ($allPermsKeys as $pk): if(!empty($perms[$pk])): ?>
<input type="hidden" name="perm_<?= $pk ?>" value="1"/>
<?php endif; endforeach; ?>
</form>

<?php elseif ($tab === 'jobs'): ?>
<div class="bg-white rounded-xl border">
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-gray-50"><tr>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Datum</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Zeit</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Kunde</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Service</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Std</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Status</th>
      </tr></thead>
      <tbody class="divide-y">
      <?php foreach ($jobs as $j): ?>
      <tr class="hover:bg-gray-50 cursor-pointer" onclick="window.location='/admin/jobs.php?view=<?= $j['j_id'] ?>'">
        <td class="px-4 py-3 font-mono"><?= date('d.m.Y', strtotime($j['j_date'])) ?></td>
        <td class="px-4 py-3 font-mono"><?= substr($j['j_time'],0,5) ?></td>
        <td class="px-4 py-3"><?= e($j['cname']??'') ?> <span class="text-xs text-gray-400">(<?= e($j['ctype']??'') ?>)</span></td>
        <td class="px-4 py-3"><?= e($j['stitle']??'-') ?></td>
        <td class="px-4 py-3"><?= $j['total_hours'] ? round($j['total_hours'],1) : $j['j_hours'] ?>h</td>
        <td class="px-4 py-3"><?= badge($j['job_status']) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($tab === 'earnings'): ?>
<?php
$months = all("SELECT DATE_FORMAT(j_date,'%Y-%m') as m, COUNT(*) as cnt, SUM(COALESCE(total_hours,j_hours)) as hrs FROM jobs WHERE emp_id_fk=? AND status=1 AND job_status='COMPLETED' GROUP BY m ORDER BY m DESC LIMIT 12", [$eid]);
?>
<div class="bg-white rounded-xl border">
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-gray-50"><tr>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Monat</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Jobs</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Stunden</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Verdienst</th>
      </tr></thead>
      <tbody class="divide-y">
      <?php foreach ($months as $m): $earn = round($m['hrs'] * $emp['tariff'], 2); ?>
      <tr class="hover:bg-gray-50">
        <td class="px-4 py-3 font-medium"><?= $m['m'] ?></td>
        <td class="px-4 py-3"><?= $m['cnt'] ?></td>
        <td class="px-4 py-3 text-brand font-medium"><?= round($m['hrs'],1) ?>h</td>
        <td class="px-4 py-3 text-red-600 font-medium"><?= money($earn) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($tab === 'rights'): ?>
<form method="POST">
  <input type="hidden" name="action" value="save"/>
  <!-- Hidden fields to preserve basic info -->
  <input type="hidden" name="name" value="<?= e($emp['name']) ?>"/>
  <input type="hidden" name="surname" value="<?= e($emp['surname']??'') ?>"/>
  <input type="hidden" name="email" value="<?= e($emp['email']) ?>"/>
  <input type="hidden" name="phone" value="<?= e($emp['phone']??'') ?>"/>
  <input type="hidden" name="tariff" value="<?= $emp['tariff'] ?>"/>
  <input type="hidden" name="location" value="<?= e($emp['location']??'') ?>"/>
  <input type="hidden" name="nationality" value="<?= e($emp['nationality']??'') ?>"/>
  <input type="hidden" name="status" value="<?= $emp['status'] ?>"/>
  <input type="hidden" name="notes" value="<?= e($emp['notes']??'') ?>"/>
  <input type="hidden" name="partner_type" value="<?= e($emp['partner_type']??'Cleaner') ?>"/>
  <input type="hidden" name="contract_type" value="<?= e($emp['contract_type']??'Freelance') ?>"/>

  <div class="bg-white rounded-xl border p-5 max-w-xl">
    <h3 class="font-semibold mb-4">Portal-Rechte (Partner sieht/kann)</h3>
    <?php
    $perms = json_decode($emp['email_permissions'] ?? '{}', true);
    if (!is_array($perms)) $perms = ['portal_dashboard'=>1,'portal_jobs'=>1,'portal_profile'=>1,'can_start_stop'=>1,'can_see_address'=>1];
    $groups = [
      'Portal-Seiten' => [
        'portal_dashboard' => ['Dashboard', 'Übersicht sehen'],
        'portal_jobs' => ['Meine Jobs', 'Heutige + kommende Jobs'],
        'portal_schedule' => ['Kalender', 'Wochen-/Monatsübersicht'],
        'portal_earnings' => ['Verdienst', 'Stunden + Gehalt sehen'],
        'portal_documents' => ['Dokumente', 'Fotos/Videos ansehen'],
        'portal_messages' => ['Nachrichten', 'Admin kontaktieren'],
        'portal_profile' => ['Profil', 'Eigene Daten bearbeiten'],
      ],
      'Job-Aktionen' => [
        'can_start_stop' => ['Start/Stop', 'Jobs starten + beenden (GPS)'],
        'can_cancel' => ['Stornieren', 'Jobs selbst absagen'],
        'can_upload_photos' => ['Fotos hochladen', 'Bilder nach Job-Ende'],
      ],
      'Kunden-Infos sehen' => [
        'can_see_customer_info' => ['Kundenname', 'Name des Kunden sehen'],
        'can_see_address' => ['Adresse', 'Einsatzadresse sehen'],
        'can_see_phone' => ['Telefon', 'Kunden-Telefon sehen'],
        'can_see_price' => ['Preis', 'Service-Preis sehen'],
      ],
    ];
    foreach ($groups as $gLabel => $gPerms): ?>
    <div class="mb-4">
      <div class="text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-1"><?= $gLabel ?></div>
      <?php foreach ($gPerms as $pk => $pv): ?>
      <label class="flex items-center gap-2.5 py-1 cursor-pointer hover:bg-gray-50 rounded-lg px-2 -mx-2">
        <input type="checkbox" name="perm_<?= $pk ?>" value="1" <?= !empty($perms[$pk]) ? 'checked' : '' ?> class="w-3.5 h-3.5 rounded border-gray-300 text-brand focus:ring-brand"/>
        <div class="flex items-center gap-2 flex-1">
          <span class="text-xs font-medium text-gray-700"><?= $pv[0] ?></span>
          <span class="text-[9px] text-gray-300"><?= $pv[1] ?></span>
        </div>
      </label>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
    <div class="flex gap-2 mt-2 pt-2 border-t">
      <button type="button" onclick="document.querySelectorAll('[name^=perm_]').forEach(c=>c.checked=true)" class="text-xs text-brand hover:underline">Alle an</button>
      <button type="button" onclick="document.querySelectorAll('[name^=perm_]').forEach(c=>c.checked=false)" class="text-xs text-red-500 hover:underline">Alle aus</button>
    </div>
    <button type="submit" class="w-full px-4 py-2.5 bg-brand text-white rounded-xl font-medium mt-4">Rechte speichern</button>
  </div>
</form>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
