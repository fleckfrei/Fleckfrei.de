<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$eid = (int)($_GET['id'] ?? 0);
if (!$eid) { header('Location: /admin/employees.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { header('Location: ' . $_SERVER['REQUEST_URI']); exit; }
    $act = $_POST['action'] ?? '';
    if ($act === 'save') {
        $pw = !empty($_POST['password']) ? password_hash($_POST['password'], PASSWORD_BCRYPT, ['cost' => 12]) : null;
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

// === Document & Minijob Handlers ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { header('Location: ?id='.$eid.'&tab=docs'); exit; }
    $act = $_POST['action'] ?? '';
    if ($act === 'save_minijob') {
        $data = [];
        foreach (['birth_name','birth_date','birth_place','gender','marital_status','nationality','disabled_grade',
                  'tax_id','sv_nr','health_insurance','religion','tax_class','child_allowance',
                  'iban','bic','bank',
                  'start_date','position','hours_per_week','wage_eur','contract_type','probation_months','employment_type',
                  'rv_befreiung','rv_befreiung_date','kv_status',
                  'school_degree','vocational_training','student'] as $k) {
            $data[$k] = trim($_POST['mj_'.$k] ?? '');
        }
        q("UPDATE employee SET minijob_data=? WHERE emp_id=?", [json_encode($data, JSON_UNESCAPED_UNICODE), $eid]);
        audit('update', 'employee', $eid, 'Minijob-Daten aktualisiert');
        header("Location: ?id=$eid&tab=docs&saved=mj"); exit;
    }
    if ($act === 'save_selbststaendig') {
        $data = [];
        foreach (['steuernummer','ust_idnr','firmenname','firmen_anschrift','gewerbeschein_nr','gewerbe_eintrag_datum',
                  'finanzamt','iban','bic','bank','versicherung_haftpflicht','versicherung_haftpflicht_summe','rechnungs_email'] as $k) {
            $data[$k] = trim($_POST['st_'.$k] ?? '');
        }
        q("UPDATE employee SET selbststaendig_data=? WHERE emp_id=?", [json_encode($data, JSON_UNESCAPED_UNICODE), $eid]);
        audit('update', 'employee', $eid, 'Selbständigen-Daten aktualisiert');
        header("Location: ?id=$eid&tab=docs&saved=st"); exit;
    }
    if ($act === 'save_subunternehmer') {
        $checked = [];
        $allDocs = ['gewerbeanmeldung','ust_idnr','betriebshaftpflicht','freistellung_48b','unbedenklichkeit_fa',
                    'sv_ausweis','a1_bescheinigung','kk_bescheinigung','berufshaftpflicht','fuehrungszeugnis'];
        foreach ($allDocs as $d) $checked[$d] = !empty($_POST['sub_'.$d]) ? 1 : 0;
        q("UPDATE employee SET subunternehmer_data=? WHERE emp_id=?", [json_encode($checked), $eid]);
        audit('update', 'employee', $eid, 'Subunternehmer-Doc-Check aktualisiert');
        header("Location: ?id=$eid&tab=docs&saved=sub"); exit;
    }
    if ($act === 'upload_doc') {
        if (!empty($_FILES['file']['tmp_name']) && $_FILES['file']['error'] === 0) {
            $allowed = ['application/pdf','image/png','image/jpeg','image/jpg','image/webp'];
            if (in_array($_FILES['file']['type'], $allowed) && $_FILES['file']['size'] < 10*1024*1024) {
                $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
                $dir = __DIR__ . "/../uploads/partner-docs/$eid/";
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $fname = 'doc_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $path = $dir . $fname;
                if (move_uploaded_file($_FILES['file']['tmp_name'], $path)) {
                    q("INSERT INTO documents (entity_type, entity_id, doc_type, label, file_path, file_name, file_size, mime_type, issued_at, expires_at, issuer, notes, extracted_text, status, uploaded_by)
                       VALUES ('employee',?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                       [$eid, $_POST['doc_type'] ?: 'sonstiges', $_POST['label'] ?: $_FILES['file']['name'],
                        "/uploads/partner-docs/$eid/$fname", $_FILES['file']['name'], $_FILES['file']['size'], $_FILES['file']['type'],
                        $_POST['issued_at'] ?: null, $_POST['expires_at'] ?: null, $_POST['issuer'] ?: null,
                        $_POST['notes'] ?: null, $_POST['extracted_text'] ?? null, 'valid', 'admin']);
                    audit('upload', 'document', $eid, 'Doc hochgeladen: '.$_POST['label']);
                }
            }
        }
        header("Location: ?id=$eid&tab=docs&saved=doc"); exit;
    }
    if ($act === 'edit_doc') {
        $docId = (int)($_POST['doc_id'] ?? 0);
        q("UPDATE documents SET label=?, doc_type=?, issued_at=?, expires_at=?, issuer=?, notes=?
            WHERE doc_id=? AND entity_type='employee' AND entity_id=?",
          [$_POST['label'] ?? '', $_POST['doc_type'] ?? 'sonstiges',
           $_POST['issued_at'] ?: null, $_POST['expires_at'] ?: null,
           $_POST['issuer'] ?? '', $_POST['notes'] ?? '',
           $docId, $eid]);
        audit('update', 'document', $eid, 'Doc bearbeitet: ' . ($_POST['label'] ?? ''));
        header("Location: ?id=$eid&tab=docs&saved=edit"); exit;
    }
    if ($act === 'delete_doc') {
        $doc = one("SELECT file_path FROM documents WHERE doc_id=? AND entity_type='employee' AND entity_id=?", [(int)$_POST['doc_id'], $eid]);
        if ($doc) {
            @unlink(__DIR__ . '/..' . $doc['file_path']);
            q("DELETE FROM documents WHERE doc_id=? AND entity_type='employee' AND entity_id=?", [(int)$_POST['doc_id'], $eid]);
            audit('delete', 'document', $eid, 'Doc gelöscht');
        }
        header("Location: ?id=$eid&tab=docs&saved=del"); exit;
    }
}

// Load doc data + auto-update status
q("UPDATE documents SET status = CASE
    WHEN expires_at IS NULL THEN 'valid'
    WHEN expires_at < CURDATE() THEN 'expired'
    WHEN expires_at <= CURDATE() + INTERVAL 30 DAY THEN 'expiring_soon'
    ELSE 'valid' END
   WHERE entity_type='employee' AND entity_id=?", [$eid]);
$documents = all("SELECT * FROM documents WHERE entity_type='employee' AND entity_id=? ORDER BY status='expired' DESC, expires_at ASC, created_at DESC", [$eid]);
$minijob = json_decode((string)val("SELECT minijob_data FROM employee WHERE emp_id=?", [$eid]), true) ?: [];
$subuntDocs = json_decode((string)val("SELECT subunternehmer_data FROM employee WHERE emp_id=?", [$eid]), true) ?: [];


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
    <form method="POST" action="/admin/employees.php" class="inline"><?= csrfField() ?><input type="hidden" name="action" value="impersonate"/><input type="hidden" name="emp_id" value="<?= $eid ?>"/><button type="submit" class="px-3 py-2 bg-blue-600 text-white rounded-lg text-sm">Als Partner einloggen</button></form>
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
  <?php foreach (['info'=>'Stammdaten','jobs'=>'Jobs ('.$totalJobs.')','earnings'=>'Verdienst','rights'=>'Rechte','docs'=>'📄 Unterlagen'] as $tk=>$tl):
    $active = $tab===$tk ? 'bg-brand text-white' : 'text-gray-500 hover:bg-gray-100';
  ?>
  <a href="?id=<?= $eid ?>&tab=<?= $tk ?>" class="px-4 py-2 rounded-lg text-sm font-medium transition <?= $active ?>"><?= $tl ?></a>
  <?php endforeach; ?>
</div>

<?php if ($tab === 'info'): ?>
<form method="POST">
  <?= csrfField() ?>
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
            <?php foreach([''=>'— Bitte wählen —','mitarbeiter'=>'👷 Mitarbeiter (Festangestellt)','freelancer'=>'🆓 Freelancer (Selbstständig)','kleinunternehmen'=>'🏢 Kleinunternehmen'] as $tk=>$tl): ?>
            <option value="<?= $tk ?>" <?= ($emp['partner_type']??'')===$tk?'selected':'' ?>><?= $tl ?></option>
            <?php endforeach; ?>
          </select></div>
        <div><label class="block text-xs font-medium text-gray-500 mb-1">Vertrag</label>
          <select name="contract_type" class="w-full px-3 py-2 border rounded-xl text-sm">
            <?php foreach([''=>'— Bitte wählen —','minijob'=>'Minijob (520€)','midijob'=>'Midijob (520-2000€)','teilzeit'=>'Teilzeit','vollzeit'=>'Vollzeit'] as $k=>$v): ?>
            <option value="<?= $k ?>" <?= ($emp['contract_type']??'')===$k?'selected':'' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select></div>
        <div><label class="block text-xs font-medium text-gray-500 mb-1">Tarif (<?= CURRENCY ?>/h)</label><input type="number" name="tariff" value="<?= $emp['tariff'] ?>" step="0.5" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
        <div><label class="block text-xs font-medium text-gray-500 mb-1">Status</label>
          <select name="status" class="w-full px-3 py-2 border rounded-xl text-sm"><option value="1" <?= $emp['status']?'selected':'' ?>>Aktiv</option><option value="0" <?= !$emp['status']?'selected':'' ?>>Inaktiv</option></select></div>
        <div><label class="block text-xs font-medium text-gray-500 mb-1">Ort</label><input name="location" value="<?= e($emp['location']??'') ?>" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
        <div><label class="block text-xs font-medium text-gray-500 mb-1">Nationalität</label><input name="nationality" value="<?= e($emp['nationality']??'') ?>" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
        <div><label class="block text-xs font-medium text-gray-500 mb-1">Passwort setzen</label><input type="password" name="password" autocomplete="new-password" placeholder="Leer = unverändert" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
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
  <?= csrfField() ?>
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

<?php if ($tab === 'docs'): ?>
<div class="space-y-6">

  <!-- Erfolgsbanner -->
  <?php if (!empty($_GET['saved'])): ?>
  <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl text-sm">
    ✓ Gespeichert (<?= e($_GET['saved']) ?>)
  </div>
  <?php endif; ?>

  <!-- Documents Section -->
  <div class="bg-white rounded-xl border p-5">
    <div class="flex items-center justify-between mb-4">
      <h3 class="font-semibold flex items-center gap-2">📁 Dokumente <span class="text-xs text-gray-500">(<?= count($documents) ?>)</span></h3>
    </div>

    <!-- Upload Form (One-Click Auto-Extract) -->
    <form method="POST" enctype="multipart/form-data" id="docUploadForm" class="bg-gray-50 p-4 rounded-xl mb-4">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="upload_doc"/>

      <h4 class="text-sm font-semibold mb-3 flex items-center gap-2">+ Neues Dokument hochladen <span class="text-xs font-normal text-gray-500">— AI extrahiert automatisch</span></h4>

      <!-- Big file input -->
      <label for="docFile" class="block cursor-pointer border-2 border-dashed border-gray-300 hover:border-brand bg-white rounded-xl p-6 text-center transition">
        <div class="text-3xl mb-2">📄</div>
        <div class="text-sm font-semibold text-gray-700" id="docFileLabel">Datei wählen oder hierher ziehen</div>
        <div class="text-xs text-gray-500 mt-1">PDF, JPG, PNG · max 10MB · AI erkennt automatisch Aussteller, Datum, Bezeichnung</div>
        <input type="file" name="file" id="docFile" required accept=".pdf,.png,.jpg,.jpeg,.webp" class="hidden"/>
      </label>

      <!-- Status nach Upload -->
      <div id="ocrStatus" class="text-xs mt-3 hidden"></div>

      <!-- Auto-extracted Preview (shown after AI runs) -->
      <div id="docPreview" class="hidden mt-3 bg-white rounded-lg p-3 border border-green-200">
        <div class="text-xs font-semibold text-gray-700 mb-2">✓ Erkannte Daten (kannst du hier korrigieren):</div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-2 text-xs">
          <div>
            <label class="block text-[10px] text-gray-500">Typ</label>
            <select name="doc_type" class="w-full px-2 py-1 border rounded">
              <option value="vertrag">Partner-Vertrag</option>
              <option value="ust_bescheinigung">USt-Bescheinigung</option>
              <option value="gewerbeanmeldung">Gewerbeanmeldung</option>
              <option value="haftpflicht">Haftpflicht</option>
              <option value="berufshaftpflicht">Berufshaftpflicht</option>
              <option value="freistellung_48b">§48b Freistellung</option>
              <option value="unbedenklichkeit_fa">Unbedenkl. FA</option>
              <option value="sv_ausweis">SV-Ausweis</option>
              <option value="a1_bescheinigung">A1-Bescheinigung</option>
              <option value="kk_bescheinigung">KK-Besch.</option>
              <option value="fuehrungszeugnis">Führungszeugnis</option>
              <option value="ausweis">Ausweis</option>
              <option value="minijob_personalbogen">Minijob-Personalbogen</option>
              <option value="sonstiges" selected>Sonstiges</option>
            </select>
          </div>
          <div>
            <label class="block text-[10px] text-gray-500">Bezeichnung</label>
            <input name="label" id="docLabel" placeholder="Auto-erkannt" class="w-full px-2 py-1 border rounded"/>
          </div>
          <div>
            <label class="block text-[10px] text-gray-500">Aussteller</label>
            <input name="issuer" id="docIssuer" placeholder="Auto-erkannt" class="w-full px-2 py-1 border rounded"/>
          </div>
          <div></div>
          <div>
            <label class="block text-[10px] text-gray-500">Ausgestellt am</label>
            <input type="date" name="issued_at" id="docIssued" class="w-full px-2 py-1 border rounded"/>
          </div>
          <div>
            <label class="block text-[10px] text-gray-500">Gültig bis</label>
            <input type="date" name="expires_at" id="docExpires" class="w-full px-2 py-1 border rounded"/>
          </div>
          <div class="md:col-span-2">
            <label class="block text-[10px] text-gray-500">Notizen / Resume</label>
            <textarea name="notes" id="docNotes" rows="2" placeholder="Auto-erkannt" class="w-full px-2 py-1 border rounded"></textarea>
          </div>
        </div>
        <input type="hidden" name="extracted_text" id="docFullText"/>
        <details class="mt-3 text-xs" id="docFullTextPreview" style="display:none">
          <summary class="cursor-pointer text-gray-500 hover:text-purple-600">📜 Kompletter extrahierter Text (inkl. Handschrift) ansehen</summary>
          <pre id="docFullTextContent" class="mt-2 p-3 bg-purple-50 rounded text-[11px] whitespace-pre-wrap font-mono max-h-64 overflow-y-auto border border-purple-200"></pre>
        </details>
        <button type="submit" class="mt-3 w-full px-4 py-2 bg-brand hover:bg-brand-dark text-white rounded-lg text-sm font-semibold">✓ Bestätigen + Hochladen</button>
      </div>
    </form>

    <!-- Documents List -->
    <?php if ($documents): ?>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
          <tr>
            <th class="px-3 py-2 text-left">Typ / Bezeichnung</th>
            <th class="px-3 py-2 text-left">Aussteller</th>
            <th class="px-3 py-2 text-left">Ausgestellt</th>
            <th class="px-3 py-2 text-left">Gültig bis</th>
            <th class="px-3 py-2 text-left">Status</th>
            <th class="px-3 py-2 text-right">Aktion</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($documents as $d):
            $statusBadge = match($d['status']) {
              'expired' => '<span class="px-2 py-0.5 text-xs bg-red-100 text-red-700 rounded">⚠ Abgelaufen</span>',
              'expiring_soon' => '<span class="px-2 py-0.5 text-xs bg-amber-100 text-amber-700 rounded">⏰ Bald ablauf</span>',
              default => '<span class="px-2 py-0.5 text-xs bg-green-100 text-green-700 rounded">✓ Gültig</span>'
            };
          ?>
          <tr class="border-t hover:bg-gray-50">
            <td class="px-3 py-2">
              <div class="font-medium"><?= e($d['label'] ?: $d['file_name']) ?></div>
              <div class="text-xs text-gray-500"><?= e($d['doc_type']) ?> · <?= round($d['file_size']/1024) ?> KB</div>
            </td>
            <td class="px-3 py-2 text-gray-600"><?= e($d['issuer'] ?: '—') ?></td>
            <td class="px-3 py-2 text-gray-600"><?= $d['issued_at'] ? date('d.m.Y', strtotime($d['issued_at'])) : '—' ?></td>
            <td class="px-3 py-2 text-gray-600"><?= $d['expires_at'] ? date('d.m.Y', strtotime($d['expires_at'])) : '—' ?></td>
            <td class="px-3 py-2"><?= $statusBadge ?></td>
            <td class="px-3 py-2 text-right whitespace-nowrap">
              <a href="<?= e($d['file_path']) ?>" target="_blank" class="text-blue-600 hover:underline text-xs">👁 Ansehen</a>
              <a href="<?= e($d['file_path']) ?>" download class="text-brand hover:underline text-xs ml-2">📥 Download</a>
              <button type="button" onclick="document.getElementById('edit-doc-<?= $d['doc_id'] ?>').classList.toggle('hidden')" class="text-amber-600 hover:underline text-xs ml-2">✏ Bearbeiten</button>
              <?php if (!empty($d['extracted_text'])): ?>
              <button type="button" onclick="document.getElementById('fulltext-<?= $d['doc_id'] ?>').classList.toggle('hidden')" class="text-purple-600 hover:underline text-xs ml-2">📜 Text</button>
              <?php endif; ?>
              <form method="POST" class="inline" onsubmit="return confirm('Wirklich löschen?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete_doc"/>
                <input type="hidden" name="doc_id" value="<?= $d['doc_id'] ?>"/>
                <button class="text-red-500 hover:underline text-xs ml-2">🗑</button>
              </form>
            </td>
          </tr>
          <tr id="edit-doc-<?= $d['doc_id'] ?>" class="hidden bg-amber-50 border-t">
            <td colspan="6" class="px-3 py-3">
              <form method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-2 text-xs">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="edit_doc"/>
                <input type="hidden" name="doc_id" value="<?= $d['doc_id'] ?>"/>
                <div>
                  <label class="block text-[10px] text-gray-500 mb-0.5">Bezeichnung</label>
                  <input name="label" value="<?= e($d['label']) ?>" class="w-full px-2 py-1 border rounded"/>
                </div>
                <div>
                  <label class="block text-[10px] text-gray-500 mb-0.5">Typ</label>
                  <select name="doc_type" class="w-full px-2 py-1 border rounded">
                    <?php foreach(['vertrag'=>'Partner-Vertrag','ust_bescheinigung'=>'USt-Bescheinigung','gewerbeanmeldung'=>'Gewerbeanmeldung','haftpflicht'=>'Haftpflicht','berufshaftpflicht'=>'Berufshaftpflicht','freistellung_48b'=>'§48b Freistellung','unbedenklichkeit_fa'=>'Unbedenkl. FA','sv_ausweis'=>'SV-Ausweis','a1_bescheinigung'=>'A1-Besch.','kk_bescheinigung'=>'KK-Besch.','fuehrungszeugnis'=>'Führungszeugnis','ausweis'=>'Ausweis','minijob_personalbogen'=>'Minijob-Personalbogen','sonstiges'=>'Sonstiges'] as $tk=>$tl): ?>
                    <option value="<?= $tk ?>" <?= $d['doc_type']===$tk?'selected':'' ?>><?= $tl ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label class="block text-[10px] text-gray-500 mb-0.5">Aussteller</label>
                  <input name="issuer" value="<?= e($d['issuer']) ?>" class="w-full px-2 py-1 border rounded"/>
                </div>
                <div>
                  <label class="block text-[10px] text-gray-500 mb-0.5">Ausgestellt am</label>
                  <input type="date" name="issued_at" value="<?= e($d['issued_at']) ?>" class="w-full px-2 py-1 border rounded"/>
                </div>
                <div>
                  <label class="block text-[10px] text-gray-500 mb-0.5">Gültig bis</label>
                  <input type="date" name="expires_at" value="<?= e($d['expires_at']) ?>" class="w-full px-2 py-1 border rounded"/>
                </div>
                <div></div>
                <div class="md:col-span-3">
                  <label class="block text-[10px] text-gray-500 mb-0.5">Notizen</label>
                  <textarea name="notes" rows="2" class="w-full px-2 py-1 border rounded"><?= e($d['notes']) ?></textarea>
                </div>
                <div class="md:col-span-3 flex gap-2">
                  <button type="submit" class="px-3 py-1 bg-brand text-white rounded text-xs">💾 Speichern</button>
                  <button type="button" onclick="document.getElementById('edit-doc-<?= $d['doc_id'] ?>').classList.add('hidden')" class="px-3 py-1 bg-gray-200 rounded text-xs">Abbrechen</button>
                </div>
              </form>
            </td>
          </tr>
          <?php if (!empty($d['extracted_text'])): ?>
          <tr id="fulltext-<?= $d['doc_id'] ?>" class="hidden bg-purple-50 border-t">
            <td colspan="6" class="px-3 py-3">
              <div class="flex items-center justify-between mb-2">
                <strong class="text-xs text-purple-700">📜 Extrahierter Text (inkl. Handschrift via AI):</strong>
                <button type="button" onclick="navigator.clipboard.writeText(this.parentNode.nextElementSibling.textContent); this.textContent='✓ Kopiert'" class="text-xs text-purple-600 hover:underline">📋 Kopieren</button>
              </div>
              <pre class="text-[11px] whitespace-pre-wrap font-mono bg-white p-3 rounded border max-h-96 overflow-y-auto"><?= e($d['extracted_text']) ?></pre>
            </td>
          </tr>
          <?php endif; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div class="text-center text-gray-400 text-sm py-6">Noch keine Dokumente.</div>
    <?php endif; ?>
  </div>

  <!-- Minijob Personalbogen — nur bei contract_type=minijob -->
  <?php if (($emp['contract_type'] ?? '') === 'minijob'): ?>
  <div class="bg-white rounded-xl border p-5">
    <h3 class="font-semibold mb-4 flex items-center gap-2">📝 Minijob-Personalbogen (DATEV)</h3>
    <p class="text-xs text-gray-500 mb-3">Vertragstyp: <strong>Minijob (520€)</strong> · Diese Felder werden für DATEV-Lohnabrechnung benötigt.</p>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save_minijob"/>
      <?php
      $mjFields = [
        'Persönliche Daten' => [
          'birth_name' => 'Geburtsname', 'birth_date' => 'Geburtsdatum (date)',
          'birth_place' => 'Geburtsort', 'gender' => 'Geschlecht',
          'marital_status' => 'Familienstand', 'nationality' => 'Staatsangehörigkeit',
          'disabled_grade' => 'Schwerbehinderung GdB',
        ],
        'Steuer & Sozialversicherung' => [
          'tax_id' => 'Steuer-ID (11-stellig)', 'sv_nr' => 'Sozialversicherungs-Nr',
          'health_insurance' => 'Krankenkasse', 'religion' => 'Konfession',
          'tax_class' => 'Steuerklasse', 'child_allowance' => 'Kinderfreibeträge',
        ],
        'Bankverbindung' => [
          'iban' => 'IBAN', 'bic' => 'BIC', 'bank' => 'Bank',
        ],
        'Beschäftigung' => [
          'start_date' => 'Eintrittsdatum (date)', 'position' => 'Tätigkeit/Berufsbezeichnung',
          'hours_per_week' => 'Stunden/Woche', 'wage_eur' => 'Arbeitsentgelt (€)',
          'contract_type' => 'Befristet bis (date)', 'probation_months' => 'Probezeit (Monate)',
          'employment_type' => 'Haupt-/Nebenbeschäftigung',
        ],
        'Sozialversicherung' => [
          'rv_befreiung' => 'RV-Befreiung beantragt (J/N)', 'rv_befreiung_date' => 'RV-Befreiung Datum (date)',
          'kv_status' => 'KV-Status (pflicht/freiw/privat)',
        ],
        'Bildung' => [
          'school_degree' => 'Schulabschluss', 'vocational_training' => 'Berufsausbildung',
          'student' => 'Studierend (J/N)',
        ],
      ];
      foreach ($mjFields as $grp => $fields): ?>
        <div class="mb-4 pb-4 border-b">
          <h4 class="text-xs font-semibold text-gray-500 uppercase mb-2"><?= $grp ?></h4>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <?php foreach ($fields as $key => $label):
              $isDate = strpos($label, '(date)') !== false;
              $cleanLabel = str_replace(' (date)', '', $label);
              $val = $minijob[$key] ?? '';
            ?>
            <div>
              <label class="block text-xs font-medium text-gray-500 mb-1"><?= $cleanLabel ?></label>
              <input type="<?= $isDate ? 'date' : 'text' ?>" name="mj_<?= $key ?>" value="<?= e($val) ?>" class="w-full px-3 py-1.5 border rounded-lg text-sm"/>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
      <button type="submit" class="px-4 py-2 bg-brand text-white rounded-lg text-sm font-medium">💾 Minijob-Daten speichern</button>
    </form>
  </div>
  <?php endif; ?>

  <!-- Selbständigen-Form — nur bei partner_type=freelancer/kleinunternehmen -->
  <?php if (in_array($emp['partner_type'] ?? '', ['freelancer','kleinunternehmen'], true)): ?>
  <div class="bg-white rounded-xl border p-5">
    <h3 class="font-semibold mb-4 flex items-center gap-2">💼 Selbständigen-Daten</h3>
    <p class="text-xs text-gray-500 mb-3">Partner-Typ: <strong><?= $emp['partner_type']==='freelancer'?'Freelancer':'Kleinunternehmen' ?></strong> · Steuer- und Firmen-Daten für Rechnungsstellung.</p>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save_selbststaendig"/>
      <?php
      $stFields = [
        'Firma' => [
          'firmenname' => 'Firmenname / Geschäftsbezeichnung',
          'firmen_anschrift' => 'Geschäftsanschrift',
          'rechnungs_email' => 'Email für Rechnungen',
        ],
        'Steuer' => [
          'steuernummer' => 'Steuernummer',
          'ust_idnr' => 'USt-IdNr (DE...)',
          'finanzamt' => 'Finanzamt',
        ],
        'Gewerbe' => [
          'gewerbeschein_nr' => 'Gewerbeschein-Nr',
          'gewerbe_eintrag_datum' => 'Gewerbe-Eintragsdatum (date)',
        ],
        'Bank' => [
          'iban' => 'IBAN',
          'bic' => 'BIC',
          'bank' => 'Bank',
        ],
        'Versicherung' => [
          'versicherung_haftpflicht' => 'Haftpflicht-Versicherung (Anbieter + Police-Nr)',
          'versicherung_haftpflicht_summe' => 'Deckungssumme (€)',
        ],
      ];
      foreach ($stFields as $grp => $fields): ?>
        <div class="mb-4 pb-4 border-b">
          <h4 class="text-xs font-semibold text-gray-500 uppercase mb-2"><?= $grp ?></h4>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <?php foreach ($fields as $key => $label):
              $isDate = strpos($label, '(date)') !== false;
              $cleanLabel = str_replace(' (date)', '', $label);
              $val = $selbststaendig[$key] ?? '';
            ?>
            <div>
              <label class="block text-xs font-medium text-gray-500 mb-1"><?= $cleanLabel ?></label>
              <input type="<?= $isDate ? 'date' : 'text' ?>" name="st_<?= $key ?>" value="<?= e($val) ?>" class="w-full px-3 py-1.5 border rounded-lg text-sm"/>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
      <button type="submit" class="px-4 py-2 bg-brand text-white rounded-lg text-sm font-medium">💾 Selbständigen-Daten speichern</button>
    </form>
  </div>
  <?php endif; ?>

  <!-- Hinweis wenn kein contract_type/partner_type gesetzt -->
  <?php if (empty($emp['contract_type']) && empty($emp['partner_type'])): ?>
  <div class="bg-amber-50 border border-amber-200 text-amber-900 rounded-xl p-4 text-sm">
    ⚠ <strong>Vertragstyp nicht gesetzt.</strong> Im Tab "Stammdaten" Partner-Typ + Vertragstyp wählen, dann erscheinen die passenden Personaldaten-Formulare hier.
  </div>
  <?php endif; ?>

  <!-- Subunternehmer Doc-Checkliste — primär bei freelancer/kleinunternehmen -->
  <?php if (in_array($emp['partner_type'] ?? '', ['freelancer','kleinunternehmen'], true)): ?>
  <div class="bg-white rounded-xl border p-5">
    <h3 class="font-semibold mb-4 flex items-center gap-2">🔧 Subunternehmer — Erforderliche Dokumente</h3>
    <p class="text-xs text-gray-500 mb-4">Häkchen setzen wenn Dokument vorliegt. Upload separat oben.</p>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save_subunternehmer"/>
      <?php
      $subDocs = [
        'gewerbeanmeldung' => ['Gewerbeanmeldung', 'Aktuell, mit korrekter Tätigkeit'],
        'ust_idnr' => ['USt-IdNr Bestätigung', 'Bei umsatzsteuerpflichtigen Subs'],
        'betriebshaftpflicht' => ['Betriebshaftpflicht-Versicherung', 'Mind. 1 Mio €, gültige Police'],
        'freistellung_48b' => ['§48b Freistellungsbescheinigung', 'Bauabzugsteuer (FA)'],
        'unbedenklichkeit_fa' => ['Unbedenklichkeitsbescheinigung Finanzamt', 'Steuerlich nichts zu beanstanden'],
        'sv_ausweis' => ['Sozialversicherungsausweis', 'Bei abhängig Beschäftigten'],
        'a1_bescheinigung' => ['A1-Bescheinigung', 'Bei EU-Entsendung'],
        'kk_bescheinigung' => ['Krankenkassen-Bescheinigung', 'Über fristgerechte Beitragszahlung'],
        'berufshaftpflicht' => ['Berufshaftpflicht', 'Spezifisch für Reinigungs-Tätigkeit'],
        'fuehrungszeugnis' => ['Erweitertes Führungszeugnis', 'Nicht älter als 6 Monate'],
      ];
      foreach ($subDocs as $key => $info): ?>
      <label class="flex items-start gap-3 py-2 border-b last:border-0 cursor-pointer hover:bg-gray-50 px-2 rounded">
        <input type="checkbox" name="sub_<?= $key ?>" value="1" <?= !empty($subuntDocs[$key]) ? 'checked' : '' ?> class="mt-1 rounded"/>
        <div>
          <div class="font-medium text-sm"><?= e($info[0]) ?></div>
          <div class="text-xs text-gray-500"><?= e($info[1]) ?></div>
        </div>
      </label>
      <?php endforeach; ?>
      <button type="submit" class="mt-4 px-4 py-2 bg-brand text-white rounded-lg text-sm font-medium">💾 Doc-Check speichern</button>
    </form>
  </div>
  <?php endif; ?>

</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>


<script>
(function(){
  var fileInput = document.getElementById('docFile');
  if (!fileInput) return;
  var fileLabel = document.getElementById('docFileLabel');
  var status = document.getElementById('ocrStatus');
  var preview = document.getElementById('docPreview');
  var dropZone = fileInput.closest('label');

  // Drag & drop
  if (dropZone) {
    ['dragenter','dragover'].forEach(ev => dropZone.addEventListener(ev, e => {
      e.preventDefault(); dropZone.classList.add('border-brand','bg-brand/5');
    }));
    ['dragleave','drop'].forEach(ev => dropZone.addEventListener(ev, e => {
      e.preventDefault(); dropZone.classList.remove('border-brand','bg-brand/5');
    }));
    dropZone.addEventListener('drop', e => {
      if (e.dataTransfer.files.length) { fileInput.files = e.dataTransfer.files; fileInput.dispatchEvent(new Event('change')); }
    });
  }

  fileInput.addEventListener('change', async function() {
    var f = fileInput.files[0];
    if (!f) return;
    if (fileLabel) fileLabel.textContent = '📎 ' + f.name + ' (' + Math.round(f.size/1024) + ' KB)';
    status.classList.remove('hidden');
    status.className = 'text-xs mt-3 text-blue-600';
    status.innerHTML = '<span class="inline-block animate-spin">⟳</span> Scanne Dokument mit AI...';
    preview.classList.add('hidden');

    var fd = new FormData();
    fd.append('file', f);
    try {
      var r = await fetch('/api/doc-extract.php', { method: 'POST', body: fd, credentials: 'same-origin' });
      var d = await r.json();
      if (d.success) {
        if (d.label) document.getElementById('docLabel').value = d.label;
        if (d.issued_at) document.getElementById('docIssued').value = d.issued_at;
        if (d.expires_at) document.getElementById('docExpires').value = d.expires_at;
        if (d.issuer) document.getElementById('docIssuer').value = d.issuer;
        if (d.notes) document.getElementById('docNotes').value = d.notes;
        if (d.full_text) {
          document.getElementById('docFullText').value = d.full_text;
          document.getElementById('docFullTextContent').textContent = d.full_text;
          document.getElementById('docFullTextPreview').style.display = 'block';
        }
        status.className = 'text-xs mt-3 text-green-600';
        status.textContent = '✓ Daten extrahiert — bitte prüfen + bestätigen';
        preview.classList.remove('hidden');
        preview.scrollIntoView({behavior:'smooth', block:'nearest'});
      } else {
        status.className = 'text-xs mt-3 text-amber-600';
        status.textContent = '⚠ Auto-Fill nicht möglich (' + (d.error || 'kein Text') + ') — bitte manuell ausfüllen';
        preview.classList.remove('hidden');
      }
    } catch(e) {
      status.className = 'text-xs mt-3 text-red-600';
      status.textContent = '✗ Scanner-Fehler: ' + e.message;
      preview.classList.remove('hidden');
    }
  });
})();
</script>
