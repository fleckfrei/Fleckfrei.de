<?php
require_once __DIR__ . '/../includes/auth.php';
requireEmployee();
$title = t('nav.profile'); $page = 'profile';
$user = me();
$eid = $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'update_profile') {
    if (!verifyCsrf()) { header('Location: /employee/profile.php'); exit; }
    $displayName = trim($_POST['display_name'] ?? '');
    if (mb_strlen($displayName) > 60) $displayName = mb_substr($displayName, 0, 60);
    $lang = in_array($_POST['language'] ?? '', ['de','en','ro','ru','pl','tr','ar','fr','es','uk','vi','th','zh'], true) ? $_POST['language'] : 'de';

    // Partner type classification (3 types: mitarbeiter/freelancer/kleinunternehmen)
    $partnerType = in_array($_POST['partner_type'] ?? '', ['mitarbeiter','freelancer','kleinunternehmen'], true) ? $_POST['partner_type'] : null;
    $contractType = null;
    if ($partnerType === 'mitarbeiter') {
        $contractType = in_array($_POST['contract_type'] ?? '', ['minijob','midijob','teilzeit','vollzeit'], true) ? $_POST['contract_type'] : null;
    }
    $companyName = $partnerType === 'kleinunternehmen' ? trim($_POST['company_name'] ?? '') : null;
    $companySize = $partnerType === 'kleinunternehmen' ? min(10, max(1, (int)($_POST['company_size'] ?? 1))) : null;
    $taxId = trim($_POST['tax_id'] ?? '') ?: null;
    $maxHours = (int)($_POST['max_hours_month'] ?? 0) ?: null;
    $bio = trim($_POST['bio'] ?? '') ?: null;
    if ($bio && mb_strlen($bio) > 500) $bio = mb_substr($bio, 0, 500);

    q("UPDATE employee SET phone=?, iban=?, display_name=?, language=?,
         partner_type=?, contract_type=?, company_name=?, company_size=?,
         tax_id=?, max_hours_month=?, bio=?
       WHERE emp_id=?",
      [$_POST['phone']??'', $_POST['iban']??'', $displayName ?: null, $lang,
       $partnerType, $contractType, $companyName, $companySize,
       $taxId, $maxHours, $bio, $eid]);
    if (!empty($_POST['new_password'])) {
        q("UPDATE employee SET password=? WHERE emp_id=?", [password_hash($_POST['new_password'], PASSWORD_BCRYPT, ['cost' => 12]), $eid]);
    }
    // Optional avatar upload — goes to profile_pic (visible to customers)
    if (!empty($_FILES['customer_avatar']['tmp_name'])) {
        $allowed = ['image/png','image/jpeg','image/webp'];
        if (in_array($_FILES['customer_avatar']['type'], $allowed) && $_FILES['customer_avatar']['size'] < 2 * 1024 * 1024) {
            $ext = pathinfo($_FILES['customer_avatar']['name'], PATHINFO_EXTENSION);
            $uploadDir = __DIR__ . '/../uploads/partners/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $fname = 'p' . $eid . '_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['customer_avatar']['tmp_name'], $uploadDir . $fname)) {
                q("UPDATE employee SET profile_pic=? WHERE emp_id=?", ['partners/' . $fname, $eid]);
            }
        }
    }
    header("Location: /employee/profile.php?saved=1"); exit;
}

// Upload contract document
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'upload_doc') {
    if (!verifyCsrf()) { header('Location: /employee/profile.php'); exit; }
    $docType = in_array($_POST['doc_type'] ?? '', ['vertrag','ust_bescheinigung','gewerbeanmeldung','haftpflicht','fuehrungszeugnis','ausweis','sonstiges'], true) ? $_POST['doc_type'] : 'sonstiges';
    $label = trim($_POST['label'] ?? '') ?: 'Dokument';
    if (!empty($_FILES['doc_file']['name']) && $_FILES['doc_file']['error'] === UPLOAD_ERR_OK) {
        $tmp = $_FILES['doc_file']['tmp_name'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp);
        $allowed = ['application/pdf','image/jpeg','image/png','image/webp'];
        if (in_array($mime, $allowed, true) && $_FILES['doc_file']['size'] < 10*1024*1024) {
            $ext = match($mime) { 'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', default => 'bin' };
            $dir = __DIR__ . '/../uploads/partner-docs/' . $eid . '/';
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $fname = bin2hex(random_bytes(8)) . '.' . $ext;
            if (move_uploaded_file($tmp, $dir . $fname)) {
                $path = '/uploads/partner-docs/' . $eid . '/' . $fname;
                q("INSERT INTO partner_documents (emp_id_fk, doc_type, label, file_path, file_size, mime_type, uploaded_by) VALUES (?,?,?,?,?,?,'partner')",
                  [$eid, $docType, $label, $path, $_FILES['doc_file']['size'], $mime]);
            }
        }
    }
    header("Location: /employee/profile.php?doc_saved=1"); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'delete_doc') {
    if (!verifyCsrf()) { header('Location: /employee/profile.php'); exit; }
    $docId = (int)($_POST['doc_id'] ?? 0);
    $doc = one("SELECT * FROM partner_documents WHERE doc_id=? AND emp_id_fk=?", [$docId, $eid]);
    if ($doc) {
        @unlink(__DIR__ . '/..' . $doc['file_path']);
        q("DELETE FROM partner_documents WHERE doc_id=?", [$docId]);
    }
    header("Location: /employee/profile.php?doc_deleted=1"); exit;
}

$emp = one("SELECT * FROM employee WHERE emp_id=?", [$eid]);
$partnerDocs = all("SELECT * FROM partner_documents WHERE emp_id_fk=? ORDER BY created_at DESC", [$eid]);
$month = $_GET['month'] ?? date('Y-m');

// Earnings this month
$jobs = all("SELECT j.*, s.title as stitle, c.name as cname
    FROM jobs j LEFT JOIN services s ON j.s_id_fk=s.s_id LEFT JOIN customer c ON j.customer_id_fk=c.customer_id
    WHERE j.emp_id_fk=? AND j.job_status='COMPLETED' AND j.j_date LIKE ? AND j.status=1
    ORDER BY j.j_date DESC", [$eid, "$month%"]);

$totalHours = 0;
$totalEarnings = 0;
$totalBonus = 0;
foreach ($jobs as $j) {
    $hrs = $j['total_hours'] ?: $j['j_hours'];
    $base = $hrs * ($emp['tariff'] ?: 0);
    $totalHours += $hrs;
    $totalEarnings += $base;
    // Checklist bonus: +5% if all items completed with photos
    if (!empty($j['s_id_fk'])) {
        $totalItems = (int) val("SELECT COUNT(*) FROM service_checklists WHERE s_id_fk=? AND is_active=1", [$j['s_id_fk']]);
        if ($totalItems > 0) {
            $doneWithPhoto = (int) val("SELECT COUNT(*) FROM checklist_completions WHERE job_id_fk=? AND completed=1 AND photo IS NOT NULL", [$j['j_id']]);
            if ($doneWithPhoto >= $totalItems) {
                $totalBonus += round($base * 0.05, 2);
            }
        }
    }
}
$totalEarnings += $totalBonus;

// Stats overall
$totalJobsAll = val("SELECT COUNT(*) FROM jobs WHERE emp_id_fk=? AND job_status='COMPLETED' AND status=1", [$eid]);
$totalHoursAll = val("SELECT COALESCE(SUM(COALESCE(total_hours, j_hours)),0) FROM jobs WHERE emp_id_fk=? AND job_status='COMPLETED' AND status=1", [$eid]);

include __DIR__ . '/../includes/layout.php';
?>

<?php if (!empty($_GET['saved'])): ?><div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4">Profil gespeichert.</div><?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
  <!-- Profile Card -->
  <div class="lg:col-span-1">
    <div class="bg-white rounded-xl border p-6 mb-6">
      <div class="text-center mb-6">
        <div class="w-20 h-20 rounded-full bg-gradient-to-br from-brand to-brand-dark text-white flex items-center justify-center text-2xl font-bold mx-auto mb-3"><?= strtoupper(substr($emp['name'],0,1)) ?></div>
        <h2 class="text-xl font-bold"><?= e($emp['name'].' '.($emp['surname']??'')) ?></h2>
        <?php
          $ptLabels = [
            'mitarbeiter' => ['👷 Mitarbeiter', [
              'minijob' => 'Minijob', 'midijob' => 'Midijob', 'teilzeit' => 'Teilzeit', 'vollzeit' => 'Vollzeit'
            ]],
            'freelancer' => ['🧑‍💼 Freelancer', []],
            'kleinunternehmen' => ['🏢 Kleinunternehmen', []],
          ];
          $pt = $emp['partner_type'] ?? null;
          $ptInfo = $ptLabels[$pt] ?? null;
        ?>
        <?php if ($ptInfo): ?>
          <p class="text-sm text-gray-500"><?= e($ptInfo[0]) ?><?php if ($emp['contract_type'] && isset($ptInfo[1][$emp['contract_type']])): ?> — <?= e($ptInfo[1][$emp['contract_type']]) ?><?php endif; ?></p>
          <?php if ($pt === 'kleinunternehmen' && $emp['company_name']): ?><p class="text-xs text-gray-400"><?= e($emp['company_name']) ?> · <?= (int)$emp['company_size'] ?> MA</p><?php endif; ?>
        <?php else: ?>
          <p class="text-sm text-gray-400 italic">Partner-Typ nicht gesetzt</p>
        <?php endif; ?>
      </div>
      <div class="space-y-3 text-sm">
        <div class="flex justify-between"><span class="text-gray-500">E-Mail</span><span class="font-medium"><?= e($emp['email']) ?></span></div>
        <div class="flex justify-between"><span class="text-gray-500">Telefon</span><span class="font-medium"><?= e($emp['phone']) ?></span></div>
        <div class="flex justify-between"><span class="text-gray-500">Stundenlohn</span><span class="font-bold text-brand"><?= money($emp['tariff'] ?: 0) ?>/h</span></div>
        <div class="flex justify-between"><span class="text-gray-500">Jobs gesamt</span><span class="font-medium"><?= $totalJobsAll ?></span></div>
        <div class="flex justify-between"><span class="text-gray-500">Stunden gesamt</span><span class="font-medium"><?= round($totalHoursAll, 1) ?>h</span></div>
      </div>
    </div>

    <!-- Public (customer-facing) profile -->
    <div class="bg-white rounded-xl border p-5 mb-5">
      <h3 class="font-semibold mb-1">Öffentliches Profil</h3>
      <p class="text-xs text-gray-500 mb-4">So sehen dich die Kunden. Dein richtiger Name bleibt privat.</p>
      <form method="POST" enctype="multipart/form-data" class="space-y-4">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="update_profile"/>

        <!-- Display name (public) -->
        <div>
          <label class="block text-sm font-medium text-gray-600 mb-1">Anzeigename für Kunden</label>
          <input name="display_name" value="<?= e($emp['display_name'] ?? '') ?>" maxlength="60" placeholder="z.B. Anna K." class="w-full px-3 py-2.5 border rounded-xl"/>
          <p class="text-xs text-gray-400 mt-1">Der Name, den Kunden im Kalender und in Jobs sehen. Leer lassen = "Ihr Partner".</p>
        </div>

        <!-- Language (for auto-translation of customer ratings) -->
        <div>
          <label class="block text-sm font-medium text-gray-600 mb-1">Deine Sprache</label>
          <select name="language" class="w-full px-3 py-2.5 border rounded-xl bg-white">
            <?php
              $langs = ['de'=>'Deutsch','en'=>'English','ro'=>'Română','ru'=>'Русский','pl'=>'Polski','tr'=>'Türkçe','ar'=>'العربية','fr'=>'Français','es'=>'Español','uk'=>'Українська','vi'=>'Tiếng Việt','th'=>'ไทย','zh'=>'中文'];
              $currentLang = $emp['language'] ?? 'de';
              foreach ($langs as $code => $label):
            ?>
            <option value="<?= $code ?>" <?= $currentLang === $code ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
          <p class="text-xs text-gray-400 mt-1">Kundenbewertungen werden automatisch in deine Sprache übersetzt.</p>
        </div>

        <!-- Bio (public, for customer-facing profile) -->
        <div>
          <label class="block text-sm font-medium text-gray-600 mb-1">Über mich <span class="text-xs text-gray-400">(öffentlich)</span></label>
          <textarea name="bio" rows="3" maxlength="500" placeholder="z.B. 'Seit 5 Jahren Reinigungserfahrung in Berlin. Spezialisiert auf Airbnb-Wohnungen.'" class="w-full px-3 py-2.5 border rounded-xl text-sm"><?= e($emp['bio'] ?? '') ?></textarea>
          <p class="text-xs text-gray-400 mt-1">Kurze Vorstellung für Kunden. Max 500 Zeichen.</p>
        </div>

        <hr class="border-gray-100"/>

        <!-- Partner type (admin-only config, but stored here for profile) -->
        <div x-data="{ type: <?= json_encode($emp['partner_type'] ?? '') ?> }">
          <label class="block text-sm font-medium text-gray-600 mb-2">Partner-Typ <span class="text-xs text-gray-400">(für die Abrechnung)</span></label>
          <div class="grid grid-cols-3 gap-2 mb-3">
            <label class="cursor-pointer">
              <input type="radio" name="partner_type" value="mitarbeiter" x-model="type" class="sr-only peer"/>
              <div class="p-3 border-2 border-gray-200 rounded-xl text-center transition peer-checked:border-brand peer-checked:bg-brand/5">
                <div class="text-xl mb-1">👷</div>
                <div class="text-[11px] font-bold">Mitarbeiter</div>
              </div>
            </label>
            <label class="cursor-pointer">
              <input type="radio" name="partner_type" value="freelancer" x-model="type" class="sr-only peer"/>
              <div class="p-3 border-2 border-gray-200 rounded-xl text-center transition peer-checked:border-brand peer-checked:bg-brand/5">
                <div class="text-xl mb-1">🧑‍💼</div>
                <div class="text-[11px] font-bold">Freelancer</div>
              </div>
            </label>
            <label class="cursor-pointer">
              <input type="radio" name="partner_type" value="kleinunternehmen" x-model="type" class="sr-only peer"/>
              <div class="p-3 border-2 border-gray-200 rounded-xl text-center transition peer-checked:border-brand peer-checked:bg-brand/5">
                <div class="text-xl mb-1">🏢</div>
                <div class="text-[11px] font-bold">Kleinunternehmen</div>
              </div>
            </label>
          </div>

          <!-- Mitarbeiter: Arbeitsverhältnis -->
          <div x-show="type === 'mitarbeiter'" x-cloak class="space-y-2">
            <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide">Arbeitsverhältnis</label>
            <select name="contract_type" class="w-full px-3 py-2.5 border rounded-xl bg-white">
              <option value="">— wählen —</option>
              <?php foreach (['minijob' => 'Minijob (bis 538 €/Monat)', 'midijob' => 'Midijob (538–2.000 €)', 'teilzeit' => 'Teilzeit', 'vollzeit' => 'Vollzeit'] as $v => $lbl): ?>
              <option value="<?= $v ?>" <?= ($emp['contract_type'] ?? '') === $v ? 'selected' : '' ?>><?= e($lbl) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="number" name="max_hours_month" value="<?= (int)($emp['max_hours_month'] ?? 0) ?: '' ?>" placeholder="Max Stunden pro Monat" class="w-full px-3 py-2.5 border rounded-xl"/>
          </div>

          <!-- Freelancer: Steuernummer -->
          <div x-show="type === 'freelancer'" x-cloak>
            <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Steuernummer / USt-IdNr</label>
            <input type="text" name="tax_id" value="<?= e($emp['tax_id'] ?? '') ?>" placeholder="DE..." class="w-full px-3 py-2.5 border rounded-xl font-mono"/>
          </div>

          <!-- Kleinunternehmen: Firma + Größe + USt -->
          <div x-show="type === 'kleinunternehmen'" x-cloak class="space-y-2">
            <input type="text" name="company_name" value="<?= e($emp['company_name'] ?? '') ?>" placeholder="Firmenname" class="w-full px-3 py-2.5 border rounded-xl"/>
            <div class="grid grid-cols-2 gap-2">
              <div>
                <label class="block text-[10px] font-semibold text-gray-500 uppercase mb-1">Anzahl Mitarbeiter</label>
                <input type="number" name="company_size" value="<?= (int)($emp['company_size'] ?? 0) ?: '' ?>" min="1" max="10" placeholder="1–10" class="w-full px-3 py-2 border rounded-xl"/>
              </div>
              <div>
                <label class="block text-[10px] font-semibold text-gray-500 uppercase mb-1">USt-IdNr</label>
                <input type="text" name="tax_id" value="<?= e($emp['tax_id'] ?? '') ?>" placeholder="DE..." class="w-full px-3 py-2 border rounded-xl font-mono"/>
              </div>
            </div>
          </div>
        </div>

        <hr class="border-gray-100"/>

        <!-- Avatar upload (public) -->
        <div>
          <label class="block text-sm font-medium text-gray-600 mb-2">Profilbild für Kunden</label>
          <div class="flex items-center gap-4">
            <?php if (!empty($emp['profile_pic'])):
              $pic = $emp['profile_pic'];
              if (!str_starts_with($pic, '/') && !str_starts_with($pic, 'http')) $pic = '/uploads/' . ltrim($pic, '/');
            ?>
            <img src="<?= e($pic) ?>" alt="" class="w-16 h-16 rounded-full object-cover border-2 border-gray-200"/>
            <?php else: ?>
            <div class="w-16 h-16 rounded-full bg-brand text-white flex items-center justify-center text-xl font-bold"><?= strtoupper(substr($emp['display_name'] ?: $emp['name'], 0, 1)) ?></div>
            <?php endif; ?>
            <div class="flex-1">
              <input type="file" name="customer_avatar" accept="image/png,image/jpeg,image/webp" class="w-full text-xs text-gray-500 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-brand/10 file:text-brand hover:file:bg-brand hover:file:text-white file:cursor-pointer file:transition"/>
              <p class="text-xs text-gray-400 mt-1">Max 2MB. PNG, JPG oder WebP.</p>
            </div>
          </div>
        </div>

        <hr class="border-gray-100"/>

        <!-- Private data (admin-only) -->
        <div>
          <label class="block text-sm font-medium text-gray-600 mb-1">Telefon <span class="text-xs text-gray-400">(privat)</span></label>
          <input name="phone" value="<?= e($emp['phone']) ?>" class="w-full px-3 py-2.5 border rounded-xl"/>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-600 mb-1">IBAN <span class="text-xs text-gray-400">(privat)</span></label>
          <input name="iban" value="<?= e($emp['iban']??'') ?>" placeholder="DE..." class="w-full px-3 py-2.5 border rounded-xl font-mono"/>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-600 mb-1">Neues Passwort</label>
          <input type="password" name="new_password" placeholder="Leer = nicht ändern" class="w-full px-3 py-2.5 border rounded-xl"/>
        </div>
        <button type="submit" class="w-full px-4 py-2.5 bg-brand text-white rounded-xl font-medium">Speichern</button>
      </form>
    </div>

    <!-- Vertragsdokumente -->
    <div class="bg-white rounded-xl border p-5 mb-5">
      <h3 class="font-semibold mb-1">Meine Dokumente</h3>
      <p class="text-xs text-gray-500 mb-3">Vertrag, USt-Bescheinigung, Haftpflicht etc. PDF oder Foto, max 10 MB.</p>

      <?php if (!empty($_GET['doc_saved'])): ?><div class="text-[11px] text-green-700 bg-green-50 border border-green-200 px-3 py-2 rounded-lg mb-3">✓ Hochgeladen</div><?php endif; ?>
      <?php if (!empty($_GET['doc_deleted'])): ?><div class="text-[11px] text-gray-600 bg-gray-50 border border-gray-200 px-3 py-2 rounded-lg mb-3">Dokument gelöscht</div><?php endif; ?>

      <form method="POST" enctype="multipart/form-data" class="space-y-2 mb-4 p-3 border border-dashed border-gray-200 rounded-xl">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="upload_doc"/>
        <div class="grid grid-cols-2 gap-2">
          <select name="doc_type" class="px-2 py-1.5 border rounded-lg text-xs">
            <option value="vertrag">📄 Vertrag (Arbeitsvertrag / Minijob)</option>
            <option value="ust_bescheinigung">💶 USt-Bescheinigung</option>
            <option value="gewerbeanmeldung">🏛 Gewerbeanmeldung</option>
            <option value="haftpflicht">🛡 Haftpflicht</option>
            <option value="fuehrungszeugnis">📋 Führungszeugnis</option>
            <option value="ausweis">🪪 Ausweis</option>
            <option value="sonstiges">📎 Sonstiges</option>
          </select>
          <input type="text" name="label" placeholder="Beschreibung" class="px-2 py-1.5 border rounded-lg text-xs"/>
        </div>
        <input type="file" name="doc_file" accept="application/pdf,image/*" required class="w-full text-xs file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-brand/10 file:text-brand"/>
        <button type="submit" class="w-full px-3 py-1.5 bg-brand hover:bg-brand-dark text-white text-xs font-semibold rounded-lg">+ Dokument hochladen</button>
      </form>

      <?php if (!empty($partnerDocs)): ?>
      <div class="space-y-1.5">
        <?php foreach ($partnerDocs as $doc):
          $icon = match($doc['doc_type']) {
            'vertrag' => '📄', 'ust_bescheinigung' => '💶', 'gewerbeanmeldung' => '🏛',
            'haftpflicht' => '🛡', 'fuehrungszeugnis' => '📋', 'ausweis' => '🪪', default => '📎',
          };
          $sizeKb = round(($doc['file_size'] ?? 0) / 1024);
        ?>
        <div class="flex items-center gap-2 p-2 border border-gray-100 rounded-lg hover:bg-gray-50">
          <span><?= $icon ?></span>
          <a href="<?= e($doc['file_path']) ?>" target="_blank" class="flex-1 min-w-0 text-xs font-medium text-gray-800 hover:text-brand truncate"><?= e($doc['label']) ?></a>
          <span class="text-[10px] text-gray-400 whitespace-nowrap"><?= $sizeKb ?> KB</span>
          <form method="POST" onsubmit="return confirm('Dokument löschen?')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete_doc"/>
            <input type="hidden" name="doc_id" value="<?= (int)$doc['doc_id'] ?>"/>
            <button class="text-red-400 hover:text-red-600 text-xs">×</button>
          </form>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <p class="text-[11px] text-gray-400 italic text-center py-2">Noch keine Dokumente hochgeladen</p>
      <?php endif; ?>
    </div>
  </div>

  <!-- Earnings -->
  <div class="lg:col-span-2">
    <!-- Month selector + summary -->
    <div class="flex items-center gap-4 mb-4">
      <a href="?month=<?= date('Y-m', strtotime("$month-01 -1 month")) ?>" class="px-3 py-2 border rounded-lg text-sm hover:bg-gray-50">&larr;</a>
      <input type="month" value="<?= $month ?>" onchange="location='?month='+this.value" class="px-3 py-2 border rounded-lg text-sm font-medium"/>
      <a href="?month=<?= date('Y-m', strtotime("$month-01 +1 month")) ?>" class="px-3 py-2 border rounded-lg text-sm hover:bg-gray-50">&rarr;</a>
    </div>

    <div class="grid grid-cols-2 gap-4 mb-6">
      <div class="bg-white rounded-xl border p-4">
        <div class="text-2xl font-bold text-brand"><?= number_format($totalHours, 1) ?>h</div>
        <div class="text-sm text-gray-500">Stunden im Monat</div>
      </div>
      <div class="bg-white rounded-xl border p-4">
        <div class="text-2xl font-bold text-green-600"><?= money($totalEarnings) ?></div>
        <div class="text-sm text-gray-500">Verdienst (<?= money($emp['tariff'] ?: 0) ?>/h)</div>
      </div>
    </div>

    <!-- Jobs table -->
    <div class="bg-white rounded-xl border">
      <div class="p-5 border-b"><h3 class="font-semibold">Erledigte Jobs — <?= count($jobs) ?></h3></div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50"><tr>
            <th class="px-4 py-3 text-left font-medium text-gray-600">Datum</th>
            <th class="px-4 py-3 text-left font-medium text-gray-600">Kunde</th>
            <th class="px-4 py-3 text-left font-medium text-gray-600">Service</th>
            <th class="px-4 py-3 text-left font-medium text-gray-600">Start</th>
            <th class="px-4 py-3 text-left font-medium text-gray-600">Ende</th>
            <th class="px-4 py-3 text-left font-medium text-gray-600">Std</th>
            <th class="px-4 py-3 text-left font-medium text-gray-600">Verdienst</th>
          </tr></thead>
          <tbody class="divide-y">
          <?php foreach ($jobs as $j):
            $hrs = $j['total_hours'] ?: $j['j_hours'];
            $earning = $hrs * ($emp['tariff'] ?: 0);
          ?>
          <tr class="hover:bg-gray-50">
            <td class="px-4 py-3 font-mono"><?= date('d.m.Y', strtotime($j['j_date'])) ?></td>
            <td class="px-4 py-3"><?= e($j['cname']) ?></td>
            <td class="px-4 py-3"><?= e($j['stitle']) ?></td>
            <td class="px-4 py-3 font-mono"><?= $j['start_time'] ? substr($j['start_time'],0,5) : '-' ?></td>
            <td class="px-4 py-3 font-mono"><?= $j['end_time'] ? substr($j['end_time'],0,5) : '-' ?></td>
            <td class="px-4 py-3 font-medium"><?= number_format($hrs, 1) ?>h</td>
            <td class="px-4 py-3 text-green-600 font-medium"><?= money($earning) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($jobs)): ?>
          <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">Keine Jobs in diesem Monat.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
