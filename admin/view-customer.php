<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$cid = (int)($_GET['id'] ?? 0);
if (!$cid) { header('Location: /admin/customers.php'); exit; }

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { header('Location: ' . $_SERVER['REQUEST_URI']); exit; }
    $act = $_POST['action'] ?? '';
    if ($act === 'apply_perms_all') {
        // Bulk: gleiche Portal-Rechte auf ALLE aktiven Customer anwenden (Stammdaten/Notes UNANGETASTET)
        $perms = [];
        $allPerms = ['dashboard','jobs','invoices','workhours','profile','booking','documents','messages','cancel','recurring',
            'wh_datum','wh_service','wh_mitarbeiter','wh_stunden','wh_umsatz','wh_fotos','wh_start_ende',
            'inv_betrag','inv_pdf','inv_status',
            'jobs_status','jobs_ma','jobs_adresse','jobs_zeit'];
        foreach ($allPerms as $pName) {
            $perms[$pName] = !empty($_POST['perm_'.$pName]) ? 1 : 0;
        }
        $permsJson = json_encode($perms);
        $rs = q("UPDATE customer SET email_permissions=? WHERE status=1", [$permsJson]);
        $cnt = $rs->rowCount();
        audit('bulk_update', 'customer', 0, "Portal-Rechte auf $cnt Kunden gespiegelt von cust=$cid");
        header("Location: /admin/view-customer.php?id=$cid&applied_all=$cnt"); exit;
    }
    if ($act === 'save') {
        // Build permissions JSON from checkboxes
        $perms = [];
        $allPerms = ['dashboard','jobs','invoices','workhours','profile','booking','documents','messages','cancel','recurring',
            'wh_datum','wh_service','wh_mitarbeiter','wh_stunden','wh_umsatz','wh_fotos','wh_start_ende',
            'inv_betrag','inv_pdf','inv_status',
            'jobs_status','jobs_ma','jobs_adresse','jobs_zeit'];
        foreach ($allPerms as $p) {
            $perms[$p] = !empty($_POST['perm_'.$p]) ? 1 : 0;
        }
        $permsJson = json_encode($perms);
        $pw = !empty($_POST['password']) ? password_hash($_POST['password'], PASSWORD_BCRYPT, ['cost' => 12]) : null;
        $isPremium   = !empty($_POST['is_premium']) ? 1 : 0;
        $travelBlock = trim($_POST['travel_block_until'] ?? '') ?: null;
        $district    = trim($_POST['district'] ?? '') ?: null;
        if ($pw) {
            q("UPDATE customer SET name=?,surname=?,email=?,phone=?,customer_type=?,status=?,notes=?,password=?,email_permissions=?,is_premium=?,travel_block_until=?,district=? WHERE customer_id=?",
              [$_POST['name'],$_POST['surname']??'',$_POST['email'],$_POST['phone']??'',$_POST['customer_type'],$_POST['status'],$_POST['notes']??'',$pw,$permsJson,$isPremium,$travelBlock,$district,$cid]);
        } else {
            q("UPDATE customer SET name=?,surname=?,email=?,phone=?,customer_type=?,status=?,notes=?,email_permissions=?,is_premium=?,travel_block_until=?,district=? WHERE customer_id=?",
              [$_POST['name'],$_POST['surname']??'',$_POST['email'],$_POST['phone']??'',$_POST['customer_type'],$_POST['status'],$_POST['notes']??'',$permsJson,$isPremium,$travelBlock,$district,$cid]);
        }
        audit('update', 'customer', $cid, 'Stammdaten bearbeitet');
        header("Location: /admin/view-customer.php?id=$cid&saved=1"); exit;
    }
    if ($act === 'add_note') {
        q("INSERT INTO customer_notes (customer_id_fk, author, message, created_at) VALUES (?,?,?,NOW())",
          [$cid, $_POST['author']??'Admin', $_POST['message']]);
        header("Location: /admin/view-customer.php?id=$cid&tab=notes#notes"); exit;
    }
}

$c = one("SELECT * FROM customer WHERE customer_id=?", [$cid]);
if (!$c) { header('Location: /admin/customers.php'); exit; }

$title = $c['name']; $page = 'customers';
$tab = $_GET['tab'] ?? 'info';

// Customer data
try { $addresses = all("SELECT * FROM customer_address WHERE customer_id_fk=?", [$cid]); } catch (Exception $e) { $addresses = []; }
$jobs = all("SELECT j.*, s.title as stitle, e.name as ename, e.surname as esurname FROM jobs j LEFT JOIN services s ON j.s_id_fk=s.s_id LEFT JOIN employee e ON j.emp_id_fk=e.emp_id WHERE j.customer_id_fk=? AND j.status=1 ORDER BY j.j_date DESC LIMIT 100", [$cid]);
$invoices = all("SELECT inv_id, customer_id_fk, invoice_number, issue_date, total_price, remaining_price, invoice_paid FROM invoices WHERE customer_id_fk=? ORDER BY issue_date DESC", [$cid]);
$services = all("SELECT * FROM services WHERE customer_id_fk=? AND status=1", [$cid]);

// Work hours not used here — computed in workhours tab from jobs table

// Stats
$totalJobs = val("SELECT COUNT(*) FROM jobs WHERE customer_id_fk=? AND status=1", [$cid]);
$completedJobs = val("SELECT COUNT(*) FROM jobs WHERE customer_id_fk=? AND status=1 AND job_status='COMPLETED'", [$cid]);
$cancelledJobs = val("SELECT COUNT(*) FROM jobs WHERE customer_id_fk=? AND status=1 AND job_status='CANCELLED'", [$cid]);
$pendingJobs = val("SELECT COUNT(*) FROM jobs WHERE customer_id_fk=? AND status=1 AND job_status='PENDING' AND j_date>=CURDATE()", [$cid]);
$totalRevenue = val("SELECT COALESCE(SUM(total_price),0) FROM invoices WHERE customer_id_fk=? AND invoice_paid='yes'", [$cid]);
$openAmount = val("SELECT COALESCE(SUM(remaining_price),0) FROM invoices WHERE customer_id_fk=? AND invoice_paid='no'", [$cid]);
$totalInvoices = val("SELECT COUNT(*) FROM invoices WHERE customer_id_fk=?", [$cid]);
$paidInvoices = val("SELECT COUNT(*) FROM invoices WHERE customer_id_fk=? AND invoice_paid='yes'", [$cid]);

// Intelligence: Frequenz, Durchschnitt, Letzte Aktivität
$avgJobValue = $completedJobs > 0 ? $totalRevenue / $completedJobs : 0;
$firstJob = one("SELECT j_date FROM jobs WHERE customer_id_fk=? AND status=1 ORDER BY j_date ASC LIMIT 1", [$cid]);
$lastJob = one("SELECT j_date FROM jobs WHERE customer_id_fk=? AND status=1 ORDER BY j_date DESC LIMIT 1", [$cid]);
$customerSince = $firstJob ? $firstJob['j_date'] : ($c['created_at'] ?? null);
$lastActivity = $lastJob ? $lastJob['j_date'] : null;
$monthsActive = $customerSince ? max(1, round((time() - strtotime($customerSince)) / (30*86400))) : 1;
$jobsPerMonth = round($totalJobs / $monthsActive, 1);
$revenuePerMonth = round($totalRevenue / $monthsActive, 2);

// Payment behavior
$paymentRate = $totalInvoices > 0 ? round(($paidInvoices / $totalInvoices) * 100) : 0;
$totalHours = val("SELECT COALESCE(SUM(GREATEST(COALESCE(total_hours,j_hours),2)),0) FROM jobs WHERE customer_id_fk=? AND job_status='COMPLETED' AND status=1", [$cid]);

// Top Partner for this customer
$topPartner = one("SELECT e.name, e.surname, COUNT(*) as cnt FROM jobs j LEFT JOIN employee e ON j.emp_id_fk=e.emp_id WHERE j.customer_id_fk=? AND j.status=1 AND j.emp_id_fk IS NOT NULL GROUP BY j.emp_id_fk ORDER BY cnt DESC LIMIT 1", [$cid]);

// Messages count
try { $msgCount = valLocal("SELECT COUNT(*) FROM messages WHERE (sender_type='customer' AND sender_id=?) OR (recipient_type='customer' AND recipient_id=?)", [$cid, $cid]); } catch (Exception $e) { $msgCount = 0; }

include __DIR__ . '/../includes/layout.php';
?>

<?php if(!empty($_GET['saved'])): ?><div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4">Gespeichert!</div><?php endif; ?>

<!-- Header -->
<div class="flex flex-wrap items-center justify-between gap-4 mb-5">
  <div class="flex items-center gap-4">
    <a href="/admin/customers.php" class="text-gray-400 hover:text-gray-600">
      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
    </a>
    <div class="w-12 h-12 rounded-xl bg-brand text-white flex items-center justify-center text-lg font-bold"><?= strtoupper(substr($c['name'],0,1)) ?></div>
    <div>
      <h2 class="text-lg font-semibold text-gray-900"><?= e($c['name']) ?> <?= e($c['surname']) ?></h2>
      <div class="flex items-center gap-2 text-xs text-gray-400">
        <span class="px-2 py-0.5 rounded-full bg-blue-50 text-blue-700 font-medium"><?= e($c['customer_type']) ?></span>
        <span>#<?= $c['customer_id'] ?></span>
        <span><?= $c['status'] ? '● Aktiv' : '○ Inaktiv' ?></span>
      </div>
    </div>
  </div>
  <div class="flex gap-2">
    <?php if ($c['phone']): $ph = preg_replace('/[^+0-9]/','',$c['phone']); ?>
    <a href="tel:<?= e($ph) ?>" class="px-3 py-2 border rounded-lg text-sm hover:bg-gray-50">📞 Anrufen</a>
    <a href="https://wa.me/<?= ltrim($ph,'+') ?>" target="_blank" class="px-3 py-2 bg-green-600 text-white rounded-lg text-sm">💬 WhatsApp</a>
    <?php endif; ?>
    <a href="mailto:<?= e($c['email']) ?>" class="px-3 py-2 border rounded-lg text-sm hover:bg-gray-50">✉ E-Mail</a>
    <form method="POST" action="/admin/customers.php" class="inline"><?= csrfField() ?><input type="hidden" name="action" value="impersonate"/><input type="hidden" name="customer_id" value="<?= $cid ?>"/><button type="submit" class="px-3 py-2 bg-blue-600 text-white rounded-lg text-sm">Als Kunde einloggen</button></form>
  </div>
</div>

<!-- Intelligence Dashboard -->
<div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-2 mb-4">
  <div class="bg-white rounded-xl border p-3 text-center">
    <div class="text-xl font-bold text-gray-900"><?= $totalJobs ?></div>
    <div class="text-[10px] text-gray-400">Jobs gesamt</div>
  </div>
  <div class="bg-white rounded-xl border p-3 text-center">
    <div class="text-xl font-bold text-green-700"><?= $completedJobs ?></div>
    <div class="text-[10px] text-gray-400">Erledigt</div>
  </div>
  <div class="bg-white rounded-xl border p-3 text-center">
    <div class="text-xl font-bold text-yellow-600"><?= $pendingJobs ?></div>
    <div class="text-[10px] text-gray-400">Offen</div>
  </div>
  <div class="bg-white rounded-xl border p-3 text-center">
    <div class="text-xl font-bold text-red-500"><?= $cancelledJobs ?></div>
    <div class="text-[10px] text-gray-400">Storniert</div>
  </div>
  <div class="bg-white rounded-xl border p-3 text-center">
    <div class="text-xl font-bold text-brand"><?= money($totalRevenue) ?></div>
    <div class="text-[10px] text-gray-400">Umsatz</div>
  </div>
  <div class="bg-white rounded-xl border p-3 text-center">
    <div class="text-xl font-bold <?= $openAmount > 0 ? 'text-red-600' : 'text-gray-400' ?>"><?= money($openAmount) ?></div>
    <div class="text-[10px] text-gray-400">Offen €</div>
  </div>
  <div class="bg-white rounded-xl border p-3 text-center">
    <div class="text-xl font-bold text-brand"><?= round($totalHours, 1) ?>h</div>
    <div class="text-[10px] text-gray-400">Stunden</div>
  </div>
  <div class="bg-white rounded-xl border p-3 text-center">
    <div class="text-xl font-bold"><?= $msgCount ?></div>
    <div class="text-[10px] text-gray-400">Nachrichten</div>
  </div>
</div>

<!-- Customer Intelligence -->
<div class="bg-white rounded-xl border p-4 mb-4">
  <h4 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">Kunden-Intelligence</h4>
  <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4 text-sm">
    <div>
      <div class="text-gray-400 text-xs">Kunde seit</div>
      <div class="font-medium"><?= $customerSince ? date('d.m.Y', strtotime($customerSince)) : '-' ?></div>
    </div>
    <div>
      <div class="text-gray-400 text-xs">Letzte Aktivität</div>
      <div class="font-medium"><?= $lastActivity ? date('d.m.Y', strtotime($lastActivity)) : '-' ?></div>
    </div>
    <div>
      <div class="text-gray-400 text-xs">Jobs / Monat</div>
      <div class="font-medium"><?= $jobsPerMonth ?></div>
    </div>
    <div>
      <div class="text-gray-400 text-xs">Umsatz / Monat</div>
      <div class="font-medium"><?= money($revenuePerMonth) ?></div>
    </div>
    <div>
      <div class="text-gray-400 text-xs">⌀ Job-Wert</div>
      <div class="font-medium"><?= money($avgJobValue) ?></div>
    </div>
    <div>
      <div class="text-gray-400 text-xs">Zahlungsquote</div>
      <div class="font-medium <?= $paymentRate >= 80 ? 'text-green-600' : ($paymentRate >= 50 ? 'text-yellow-600' : 'text-red-600') ?>"><?= $paymentRate ?>%</div>
    </div>
    <?php if ($topPartner): ?>
    <div>
      <div class="text-gray-400 text-xs">Bevorzugter Partner</div>
      <div class="font-medium"><?= e($topPartner['name'] . ' ' . ($topPartner['surname'] ?? '')) ?> <span class="text-xs text-gray-400">(<?= $topPartner['cnt'] ?>x)</span></div>
    </div>
    <?php endif; ?>
    <div>
      <div class="text-gray-400 text-xs">Rechnungen</div>
      <div class="font-medium"><?= $paidInvoices ?>/<?= $totalInvoices ?> bezahlt</div>
    </div>
    <div>
      <div class="text-gray-400 text-xs">Storno-Rate</div>
      <div class="font-medium <?= $totalJobs > 0 && ($cancelledJobs/$totalJobs) > 0.2 ? 'text-red-600' : 'text-green-600' ?>"><?= $totalJobs > 0 ? round(($cancelledJobs/$totalJobs)*100) : 0 ?>%</div>
    </div>
  </div>
</div>

<!-- OSINT / Digital Footprint -->
<?php
$email = $c['email'] ?? '';
$emailDomain = $email ? substr($email, strpos($email, '@') + 1) : '';
$fullName = trim(($c['name'] ?? '') . ' ' . ($c['surname'] ?? ''));
$phone = $c['phone'] ?? '';
$phoneClean = preg_replace('/[^0-9]/', '', $phone);

// Email domain check
$emailValid = false;
$emailProvider = '';
if ($emailDomain) {
    $mxRecords = [];
    @getmxrr($emailDomain, $mxRecords);
    $emailValid = !empty($mxRecords);
    $freeProviders = ['gmail.com','yahoo.com','hotmail.com','outlook.com','gmx.de','web.de','t-online.de','aol.com','icloud.com','protonmail.com','mail.ru','yandex.com'];
    $emailProvider = in_array(strtolower($emailDomain), $freeProviders) ? 'Privat' : 'Geschäftlich';
}
?>
<div class="bg-white rounded-xl border p-4 mb-4">
  <h4 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">Digital Footprint / OSINT</h4>
  <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
    <!-- Email Analysis -->
    <div class="bg-gray-50 rounded-lg p-3">
      <div class="text-xs font-semibold text-gray-500 mb-2">E-Mail Analyse</div>
      <div class="space-y-1.5 text-sm">
        <div class="flex justify-between"><span class="text-gray-400">E-Mail:</span><span class="font-mono text-xs"><?= e($email) ?></span></div>
        <div class="flex justify-between"><span class="text-gray-400">Domain:</span><span class="font-medium"><?= e($emailDomain) ?></span></div>
        <div class="flex justify-between"><span class="text-gray-400">MX Valid:</span><span class="font-medium <?= $emailValid ? 'text-green-600' : 'text-red-600' ?>"><?= $emailValid ? '✓ Gültig' : '✗ Ungültig' ?></span></div>
        <div class="flex justify-between"><span class="text-gray-400">Typ:</span><span class="font-medium"><?= $emailProvider ?></span></div>
        <?php if ($emailProvider === 'Geschäftlich' && $emailDomain): ?>
        <div class="flex justify-between"><span class="text-gray-400">Firma-Website:</span><a href="https://<?= e($emailDomain) ?>" target="_blank" class="text-brand hover:underline text-xs"><?= e($emailDomain) ?> ↗</a></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Quick Search Links -->
    <div class="bg-gray-50 rounded-lg p-3">
      <div class="text-xs font-semibold text-gray-500 mb-2">Suche nach: <?= e($fullName) ?></div>
      <div class="flex flex-wrap gap-1.5">
        <a href="https://www.google.com/search?q=<?= urlencode($fullName . ' Berlin') ?>" target="_blank" class="px-2 py-1 text-xs bg-white border rounded-lg hover:bg-gray-100">🔍 Google</a>
        <a href="https://www.google.com/search?q=<?= urlencode('"' . $fullName . '"') ?>" target="_blank" class="px-2 py-1 text-xs bg-white border rounded-lg hover:bg-gray-100">🔍 Exakt</a>
        <a href="https://www.linkedin.com/search/results/all/?keywords=<?= urlencode($fullName) ?>" target="_blank" class="px-2 py-1 text-xs bg-blue-50 text-blue-700 border border-blue-200 rounded-lg hover:bg-blue-100">LinkedIn</a>
        <a href="https://www.xing.com/search/members?keywords=<?= urlencode($fullName) ?>" target="_blank" class="px-2 py-1 text-xs bg-green-50 text-green-700 border border-green-200 rounded-lg hover:bg-green-100">XING</a>
        <a href="https://www.facebook.com/search/people/?q=<?= urlencode($fullName) ?>" target="_blank" class="px-2 py-1 text-xs bg-blue-50 text-blue-800 border border-blue-200 rounded-lg hover:bg-blue-100">Facebook</a>
        <a href="https://www.instagram.com/<?= urlencode(strtolower(str_replace(' ', '', $fullName))) ?>" target="_blank" class="px-2 py-1 text-xs bg-pink-50 text-pink-700 border border-pink-200 rounded-lg hover:bg-pink-100">Instagram</a>
        <?php if ($email): ?>
        <a href="https://www.google.com/search?q=<?= urlencode('"' . $email . '"') ?>" target="_blank" class="px-2 py-1 text-xs bg-yellow-50 text-yellow-700 border border-yellow-200 rounded-lg hover:bg-yellow-100">📧 Email Search</a>
        <a href="https://haveibeenpwned.com/account/<?= urlencode($email) ?>" target="_blank" class="px-2 py-1 text-xs bg-red-50 text-red-700 border border-red-200 rounded-lg hover:bg-red-100">🔓 Breach Check</a>
        <?php endif; ?>
        <?php if ($phoneClean): ?>
        <a href="https://www.google.com/search?q=<?= urlencode($phone) ?>" target="_blank" class="px-2 py-1 text-xs bg-white border rounded-lg hover:bg-gray-100">📞 Tel. Search</a>
        <a href="https://www.tellows.de/num/<?= $phoneClean ?>" target="_blank" class="px-2 py-1 text-xs bg-orange-50 text-orange-700 border border-orange-200 rounded-lg hover:bg-orange-100">Tellows</a>
        <?php endif; ?>
        <?php if ($emailDomain && $emailProvider === 'Geschäftlich'): ?>
        <a href="https://www.google.com/search?q=site:<?= urlencode($emailDomain) ?>" target="_blank" class="px-2 py-1 text-xs bg-white border rounded-lg hover:bg-gray-100">🌐 Domain</a>
        <a href="https://www.handelsregister.de/rp_web/search.xhtml" target="_blank" class="px-2 py-1 text-xs bg-gray-100 border rounded-lg hover:bg-gray-200">Handelsregister</a>
        <?php endif; ?>
      </div>
    </div>

    <!-- Contact & Location -->
    <div class="bg-gray-50 rounded-lg p-3">
      <div class="text-xs font-semibold text-gray-500 mb-2">Kontakt & Standort</div>
      <div class="space-y-1.5 text-sm">
        <?php if ($phone): ?>
        <div class="flex justify-between"><span class="text-gray-400">Telefon:</span><span><?= e($phone) ?></span></div>
        <?php endif; ?>
        <?php if (!empty($addresses)): $addr = $addresses[0]; ?>
        <div class="flex justify-between"><span class="text-gray-400">Adresse:</span><span class="text-xs"><?= e($addr['street'] . ' ' . ($addr['number']??'')) ?></span></div>
        <div class="flex justify-between"><span class="text-gray-400">Ort:</span><span><?= e(($addr['postal_code']??'') . ' ' . ($addr['city']??'')) ?></span></div>
        <a href="https://www.google.com/maps/search/<?= urlencode($addr['street'] . ' ' . ($addr['number']??'') . ', ' . ($addr['postal_code']??'') . ' ' . ($addr['city']??'')) ?>" target="_blank" class="inline-block mt-1 px-2 py-1 text-xs bg-white border rounded-lg hover:bg-gray-100">📍 Google Maps</a>
        <?php else: ?>
        <div class="text-gray-400 text-xs">Keine Adresse hinterlegt</div>
        <?php endif; ?>
        <div class="flex justify-between mt-2"><span class="text-gray-400">Typ:</span><span class="font-medium"><?= e($c['customer_type']) ?></span></div>
      </div>
    </div>
  </div>
</div>

<!-- Tabs -->
<div class="flex gap-1 mb-5 bg-white rounded-xl border p-1 w-fit">
  <?php
  $tabs = ['info'=>'Stammdaten','jobs'=>'Jobs ('.$totalJobs.')','invoices'=>'Rechnungen ('.count($invoices).')','workhours'=>'Arbeitsstunden','services'=>'Services ('.count($services).')','notes'=>'Notizen'];
  foreach ($tabs as $tk=>$tl):
    $active = $tab===$tk ? 'bg-brand text-white' : 'text-gray-500 hover:bg-gray-100';
  ?>
  <a href="?id=<?= $cid ?>&tab=<?= $tk ?>" class="px-4 py-2 rounded-lg text-sm font-medium transition <?= $active ?>"><?= $tl ?></a>
  <?php endforeach; ?>
</div>

<?php if ($tab === 'info'):
  // Personal-Link für diesen Kunden — Lazy-Generate wenn slug fehlt
  if (empty($c['personal_slug'])) {
      $sanitize = fn($r) => trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(str_replace(['ä','ö','ü','ß','é'], ['ae','oe','ue','ss','e'], $r))), '-');
      $base = $sanitize(($c['name'] ?? '') . ' ' . ($c['surname'] ?? ''));
      if (strlen($base) < 3) $base = 'k' . $c['customer_id'];
      $slug = substr($base, 0, 40);
      $i = 0;
      while (val("SELECT customer_id FROM customer WHERE personal_slug=?", [$slug])) {
          $slug = substr($base, 0, 36) . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
          if (++$i > 5) { $slug = 'k' . $c['customer_id'] . '-' . bin2hex(random_bytes(3)); break; }
      }
      try { q("UPDATE customer SET personal_slug=? WHERE customer_id=?", [$slug, $c['customer_id']]); $c['personal_slug'] = $slug; } catch (Exception $e) {}
  }
  $personalLink = 'https://app.' . SITE_DOMAIN . '/p/' . $c['personal_slug'];

  // Stundensatz bestimmen — wenn Kunde Company/Airbnb/Host → STR/B2B, sonst home_care
  $ctype = $c['customer_type'] ?? 'Private Person';
  $svcKey = in_array($ctype, ['Airbnb','Host'], true) ? 'str' : (in_array($ctype, ['Company'], true) ? 'office' : 'home_care');
  $rate = function_exists('fleckfrei_hourly') ? fleckfrei_hourly($svcKey) : ['gross' => 27.29, 'net' => 22.94];
?>

<div class="bg-gradient-to-br from-brand-light to-brand-light/50 border-2 border-brand/30 rounded-xl p-4 mb-4">
  <div class="flex items-start gap-3">
    <div class="text-2xl">🔗</div>
    <div class="flex-1">
      <div class="flex items-center justify-between flex-wrap gap-2 mb-1">
        <div class="font-bold text-brand-dark">Persönlicher Buchungs-Link · dauerhaft</div>
        <div class="text-sm">
          <span class="font-semibold text-brand-dark"><?= number_format($rate['gross'], 2, ',', '.') ?> €/h</span>
          <span class="text-[10px] text-brand">brutto</span>
          <span class="text-gray-400 mx-1">·</span>
          <span class="text-[11px] text-gray-600"><?= number_format($rate['net'], 2, ',', '.') ?> €/h netto</span>
          <span class="text-gray-400 mx-1">·</span>
          <span class="text-[10px] uppercase tracking-wider text-brand-dark"><?= strtoupper($svcKey === 'home_care' ? 'Home Care' : ($svcKey === 'str' ? 'STR' : 'B2B')) ?></span>
        </div>
      </div>
      <div class="flex items-center gap-2 flex-wrap">
        <input type="text" value="<?= e($personalLink) ?>" readonly class="flex-1 min-w-[280px] px-3 py-2 bg-white border rounded-lg font-mono text-xs" onclick="this.select()"/>
        <a href="<?= e($personalLink) ?>" target="_blank" class="px-3 py-2 bg-brand text-white rounded-lg text-sm font-semibold hover:bg-brand-dark">👀 Als Kunde öffnen</a>
        <button type="button" onclick="navigator.clipboard.writeText('<?= e($personalLink) ?>').then(()=>this.textContent='✓ kopiert')" class="px-3 py-2 bg-gray-700 text-white rounded-lg text-sm font-semibold">📋 Kopieren</button>
        <a href="https://wa.me/<?= preg_replace('/\D/', '', $c['phone'] ?? '') ?>?text=<?= urlencode('Hallo ' . ($c['name'] ?? '') . ",\n\nIhr persönlicher Fleckfrei-Buchungs-Link:\n" . $personalLink) ?>" target="_blank" class="px-3 py-2 bg-green-600 text-white rounded-lg text-sm font-semibold">WhatsApp</a>
      </div>
      <div class="text-xs text-brand-dark mt-1.5">Kunde #<?= (int)$c['customer_id'] ?> · Booking auto-prefilled mit Name, Adresse, Telefon · permanent gültig</div>
    </div>
  </div>
</div>

<!-- Stammdaten -->
<form method="POST">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="save"/>
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <div class="bg-white rounded-xl border p-5">
      <h3 class="font-semibold mb-4">Persönliche Daten</h3>
      <div class="grid grid-cols-2 gap-3">
        <div><label class="block text-xs font-medium text-gray-500 mb-1">Name *</label><input name="name" value="<?= e($c['name']) ?>" required class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
        <div><label class="block text-xs font-medium text-gray-500 mb-1">Nachname</label><input name="surname" value="<?= e($c['surname']) ?>" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
        <div><label class="block text-xs font-medium text-gray-500 mb-1">E-Mail *</label><input type="email" name="email" value="<?= e($c['email']) ?>" required class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
        <div><label class="block text-xs font-medium text-gray-500 mb-1">Telefon</label><input name="phone" value="<?= e($c['phone']) ?>" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
        <div><label class="block text-xs font-medium text-gray-500 mb-1">Typ</label>
          <select name="customer_type" class="w-full px-3 py-2 border rounded-xl text-sm"><?php foreach(['Private Person','Company','Airbnb','Host'] as $t): ?><option <?= $c['customer_type']===$t?'selected':'' ?>><?= $t ?></option><?php endforeach; ?></select></div>
        <div><label class="block text-xs font-medium text-gray-500 mb-1">Status</label>
          <select name="status" class="w-full px-3 py-2 border rounded-xl text-sm"><option value="1" <?= $c['status']?'selected':'' ?>>Aktiv</option><option value="0" <?= !$c['status']?'selected':'' ?>>Inaktiv</option></select></div>
        <div><label class="block text-xs font-medium text-gray-500 mb-1">Passwort setzen</label><input type="password" name="password" autocomplete="new-password" value="" placeholder="Neues Passwort (leer = unverändert)" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
      </div>
      <!-- Portal-Rechte: granular mit Sub-Permissions -->
      <div class="mt-4 pt-4 border-t">
        <h4 class="text-xs font-semibold text-gray-500 uppercase mb-3">Portal-Rechte (Kunde sieht/kann)</h4>
        <?php
        $perms = json_decode($c['email_permissions'] ?? '{}', true);
        if (!is_array($perms)) $perms = ($c['email_permissions'] === 'all' || $c['email_permissions'] === '') ?
          ['dashboard'=>1,'jobs'=>1,'invoices'=>1,'workhours'=>1,'profile'=>1,'booking'=>1,'documents'=>1,'messages'=>1,
           'wh_datum'=>1,'wh_service'=>1,'wh_mitarbeiter'=>1,'wh_stunden'=>1,'wh_umsatz'=>1,'wh_fotos'=>1,'wh_start_ende'=>1,
           'inv_betrag'=>1,'inv_pdf'=>1,'inv_status'=>1,
           'jobs_status'=>1,'jobs_ma'=>1,'jobs_adresse'=>1,'jobs_zeit'=>1] : [];

        // Main permissions with groups
        $groups = [
          'Seiten' => [
            'dashboard' => ['Dashboard', 'Übersicht mit Stats'],
            'profile' => ['Profil bearbeiten', 'Name, Telefon, Adresse'],
            'booking' => ['Neue Buchung', 'Job über Portal buchen'],
            'messages' => ['Nachrichten', 'An Admin senden'],
          ],
          'Jobs' => [
            'jobs' => ['Jobs sehen', 'Liste eigener Jobs'],
            'jobs_status' => ['— Status', 'Status-Badge sehen'],
            'jobs_ma' => ['— Partner', 'Wer kommt'],
            'jobs_adresse' => ['— Adresse', 'Adresse sehen'],
            'jobs_zeit' => ['— Start/Ende', 'Echte Zeiten sehen'],
            'cancel' => ['— Stornieren', 'Jobs selbst absagen'],
            'recurring' => ['— Wiederkehrende', 'Serien verwalten'],
          ],
          'Rechnungen' => [
            'invoices' => ['Rechnungen sehen', 'Rechnungsliste'],
            'inv_betrag' => ['— Betrag', 'Preise sehen'],
            'inv_pdf' => ['— PDF Download', 'Rechnung herunterladen'],
            'inv_status' => ['— Zahlstatus', 'Bezahlt/Offen sehen'],
          ],
          'Arbeitsstunden' => [
            'workhours' => ['Arbeitsstunden sehen', 'Stunden-Übersicht'],
            'wh_datum' => ['— Datum', 'Wann gearbeitet'],
            'wh_service' => ['— Service', 'Welcher Service'],
            'wh_mitarbeiter' => ['— Partner', 'Wer gearbeitet hat'],
            'wh_stunden' => ['— Stunden', 'Wie lange'],
            'wh_start_ende' => ['— Start/Ende', 'Echte Start- & Endzeit'],
            'wh_umsatz' => ['— Preis/Umsatz', 'Kosten pro Job'],
            'wh_fotos' => ['— Fotos/Videos', 'Bilder vom Job sehen'],
          ],
          'Medien' => [
            'documents' => ['Dokumente', 'Fotos/Videos ansehen'],
          ],
        ];
        foreach ($groups as $gLabel => $gPerms): ?>
        <div class="mb-3">
          <div class="text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-1 mt-2"><?= $gLabel ?></div>
          <?php foreach ($gPerms as $pk => $pv):
            $isSub = str_starts_with($pv[0], '—');
          ?>
          <label class="flex items-center gap-2.5 py-1 cursor-pointer hover:bg-gray-50 rounded-lg px-2 -mx-2 <?= $isSub ? 'ml-4' : '' ?>">
            <input type="checkbox" name="perm_<?= $pk ?>" value="1" <?= !empty($perms[$pk]) ? 'checked' : '' ?> class="w-3.5 h-3.5 rounded border-gray-300 text-brand focus:ring-brand"/>
            <div class="flex items-center gap-2 flex-1">
              <span class="text-xs font-medium <?= $isSub ? 'text-gray-500' : 'text-gray-700' ?>"><?= $pv[0] ?></span>
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
      </div>
      </div>
      <div class="mt-3"><label class="block text-xs font-medium text-gray-500 mb-1">Notizen</label><textarea name="notes" rows="3" class="w-full px-3 py-2 border rounded-xl text-sm"><?= e($c['notes']) ?></textarea></div>

      <!-- Premium / Travel-Block + Bezirk -->
      <div class="mt-3 p-3 rounded-xl border-2 bg-amber-50 border-amber-200">
        <label class="flex items-center gap-2 font-semibold text-amber-900">
          <input type="checkbox" name="is_premium" value="1" <?= !empty($c['is_premium']) ? 'checked' : '' ?>/>
          🚗 Premium-Kunde (weite Anfahrt / VIP)
        </label>
        <div class="mt-2 grid grid-cols-1 md:grid-cols-2 gap-2">
          <div>
            <label class="block text-xs text-amber-900">Partner-Tag blockieren bis:</label>
            <input type="time" name="travel_block_until" value="<?= e($c['travel_block_until'] ?? '') ?>" class="w-full px-2 py-1 border rounded text-sm bg-white"/>
          </div>
          <div>
            <label class="block text-xs text-amber-900">🗺 Berlin-Bezirk (für Route-Planner):</label>
            <select name="district" class="w-full px-2 py-1 border rounded text-sm bg-white">
              <option value="">— kein Bezirk —</option>
              <?php try { $ds = all("SELECT name FROM berlin_districts ORDER BY sort_order, name"); } catch (Exception $e) { $ds = []; }
              foreach ($ds as $d): ?>
              <option value="<?= e($d['name']) ?>" <?= ($c['district'] ?? '')===$d['name'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="text-[11px] text-amber-700 mt-2">z.B. <code>15:00</code> → Partner darf an diesem Tag bis dahin keinen Parallel-Job annehmen. Bezirk gruppiert Kunden in <a href="/admin/route-planner.php" class="underline">Route-Planner</a>.</div>
      </div>
      <div class="mt-4 pt-4 border-t space-y-2">
        <button type="submit" class="w-full px-4 py-3 bg-brand text-white rounded-xl font-semibold text-base">💾 Speichern (nur dieser Kunde)</button>
        <button type="submit" name="action" value="apply_perms_all"
                onclick="return confirm('Diese Portal-Rechte werden auf ALLE aktiven Kunden gesetzt. Stammdaten + Notizen bleiben unverändert. Fortfahren?');"
                class="w-full px-4 py-3 bg-amber-500 hover:bg-amber-600 text-white rounded-xl font-semibold text-base">
          ⚙ Auf ALLE Kunden anwenden
        </button>
        <p class="text-xs text-gray-500 text-center pt-1">💡 Tipp: "Alle aus" + "Auf ALLE Kunden anwenden" = Portal komplett deaktivieren</p>
      </div>
      <?php if (isset($_GET['applied_all'])): ?>
      <div class="mt-2 px-3 py-2 bg-green-50 border border-green-200 text-green-800 rounded-lg text-xs">
        ✓ Portal-Rechte auf <?= (int)$_GET['applied_all'] ?> Kunden angewendet.
      </div>
      <?php endif; ?>
    </div>

    <div class="space-y-5">
      <!-- Addresses -->
      <div class="bg-white rounded-xl border p-5">
        <h3 class="font-semibold mb-3">Adressen (<?= count($addresses) ?>)</h3>
        <?php foreach ($addresses as $a): ?>
        <div class="bg-gray-50 rounded-lg p-3 mb-2 text-sm">
          <div class="font-medium"><?= e($a['street']) ?> <?= e($a['number']) ?></div>
          <div class="text-gray-500"><?= e($a['postal_code']) ?> <?= e($a['city']) ?></div>
        </div>
        <?php endforeach; ?>
        <?php if(empty($addresses)): ?><p class="text-sm text-gray-400">Keine Adressen.</p><?php endif; ?>
      </div>

      <!-- Meta -->
      <div class="bg-white rounded-xl border p-5">
        <h3 class="font-semibold mb-3">System-Info</h3>
        <div class="grid grid-cols-2 gap-2 text-sm">
          <div class="text-gray-500">Kunden-ID</div><div class="font-mono"><?= $c['customer_id'] ?></div>
          <div class="text-gray-500">Erstellt</div><div><?= $c['created_at'] ?? '-' ?></div>
          <div class="text-gray-500">WhatsApp ID</div><div class="font-mono"><?= e($c['wa_id'] ?: '-') ?></div>
          <div class="text-gray-500">iCal Token</div><div class="font-mono text-xs"><?= e($c['ical_token'] ?: '-') ?></div>
        </div>
      </div>
    </div>
  </div>
</form>

<?php elseif ($tab === 'jobs'): ?>
<!-- Jobs -->
<div class="bg-white rounded-xl border">
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-gray-50"><tr>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Datum</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Zeit</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Service</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Partner</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Std</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Status</th>
      </tr></thead>
      <tbody class="divide-y">
      <?php foreach ($jobs as $j): ?>
      <tr class="hover:bg-gray-50 cursor-pointer" onclick="window.location='/admin/jobs.php?view=<?= $j['j_id'] ?>'">
        <td class="px-4 py-3 font-mono"><?= date('d.m.Y', strtotime($j['j_date'])) ?></td>
        <td class="px-4 py-3 font-mono"><?= substr($j['j_time'],0,5) ?></td>
        <td class="px-4 py-3"><?= e($j['stitle'] ?: '-') ?></td>
        <td class="px-4 py-3"><?= e(($j['ename']??'').' '.($j['esurname']??'')) ?: '<span class="text-red-400">—</span>' ?></td>
        <td class="px-4 py-3"><?= $j['j_hours'] ?>h</td>
        <td class="px-4 py-3"><?= badge($j['job_status']) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($tab === 'invoices'): ?>
<!-- Rechnungen -->
<div class="bg-white rounded-xl border">
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-gray-50"><tr>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Nr.</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Datum</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Betrag</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Bezahlt</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Offen</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Status</th>
      </tr></thead>
      <tbody class="divide-y">
      <?php foreach ($invoices as $inv): ?>
      <tr class="hover:bg-gray-50">
        <td class="px-4 py-3 font-mono"><?= e($inv['invoice_number']) ?></td>
        <td class="px-4 py-3"><?= $inv['issue_date'] ? date('d.m.Y', strtotime($inv['issue_date'])) : '-' ?></td>
        <td class="px-4 py-3 font-medium"><?= money($inv['total_price']) ?></td>
        <td class="px-4 py-3"><?= money($inv['total_price'] - $inv['remaining_price']) ?></td>
        <td class="px-4 py-3 <?= $inv['remaining_price'] > 0 ? 'text-red-600 font-medium' : '' ?>"><?= money($inv['remaining_price']) ?></td>
        <td class="px-4 py-3"><?= $inv['invoice_paid']==='yes' ? '<span class="px-2 py-1 text-xs rounded-full bg-green-100 text-green-700">Bezahlt</span>' : '<span class="px-2 py-1 text-xs rounded-full bg-red-100 text-red-700">Offen</span>' ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($tab === 'workhours'): ?>
<!-- Arbeitsstunden -->
<div class="flex gap-2 mb-4">
  <a href="/api/index.php?action=export/workhours&customer_id=<?= $cid ?>&month=<?= date('Y-m') ?>&key=<?= API_KEY ?>" class="px-3 py-2 border rounded-lg text-sm text-gray-600 hover:bg-gray-50">CSV Export (<?= date('M Y') ?>)</a>
  <button onclick="genInvoice(<?= $cid ?>)" class="px-3 py-2 bg-brand text-white rounded-lg text-sm font-medium">Rechnung aus Jobs erstellen</button>
</div>
<script>
function genInvoice(cid){
    const m=prompt('Für welchen Monat? (YYYY-MM)','<?= date('Y-m') ?>');
    if(!m)return;
    fetch('/api/index.php?action=invoice/generate',{method:'POST',headers:{'Content-Type':'application/json','X-API-Key':'<?= API_KEY ?>'},body:JSON.stringify({customer_id:cid,month:m})})
    .then(r=>r.json()).then(d=>{if(d.success)alert('Rechnung '+d.data.invoice_number+' erstellt! '+d.data.jobs_count+' Jobs, '+d.data.total.toFixed(2)+' €');else alert(d.error||'Fehler');});
}
</script>
<?php
$wh = all("SELECT j.j_date, j.j_time, j.j_hours, j.total_hours, j.job_status,
    s.title as stitle, s.total_price as sprice,
    e.name as ename, e.surname as esurname, e.tariff
    FROM jobs j
    LEFT JOIN services s ON j.s_id_fk=s.s_id
    LEFT JOIN employee e ON j.emp_id_fk=e.emp_id
    WHERE j.customer_id_fk=? AND j.status=1 AND j.job_status='COMPLETED'
    ORDER BY j.j_date DESC", [$cid]);
$totalH = 0; $totalRev = 0;
foreach ($wh as $w) {
    $h = $w['total_hours'] ?: $w['j_hours'];
    $custH = max(2, $h); // Min 2h Regel
    $totalH += $custH;
    $price = $w['sprice'] ?: 0;
    $totalRev += $custH * $price;
}
?>
<div class="bg-white rounded-xl border mb-4 p-4">
  <div class="grid grid-cols-3 gap-4 text-center">
    <div><div class="text-2xl font-bold text-gray-900"><?= count($wh) ?></div><div class="text-xs text-gray-400">Erledigte Jobs</div></div>
    <div><div class="text-2xl font-bold text-brand"><?= round($totalH, 1) ?>h</div><div class="text-xs text-gray-400">Stunden gesamt (Kd.)</div></div>
    <div><div class="text-2xl font-bold text-emerald-700"><?= money($totalRev) ?></div><div class="text-xs text-gray-400">Umsatz (berechnet)</div></div>
  </div>
</div>
<div class="bg-white rounded-xl border">
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-gray-50"><tr>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Datum</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Service</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Partner</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Std (real)</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Std (Kunde)</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">€/h</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Umsatz</th>
      </tr></thead>
      <tbody class="divide-y">
      <?php foreach ($wh as $w):
        $realH = $w['total_hours'] ?: $w['j_hours'];
        $custH = max(2, $realH);
        $price = $w['sprice'] ?: 0;
        $rev = $custH * $price;
      ?>
      <tr class="hover:bg-gray-50">
        <td class="px-4 py-3 font-mono"><?= date('d.m.Y', strtotime($w['j_date'])) ?></td>
        <td class="px-4 py-3"><?= e($w['stitle'] ?: '-') ?></td>
        <td class="px-4 py-3"><?= e(($w['ename']??'').' '.($w['esurname']??'')) ?></td>
        <td class="px-4 py-3"><?= round($realH,1) ?>h</td>
        <td class="px-4 py-3 font-medium"><?= round($custH,1) ?>h<?= $realH < 2 ? ' <span class="text-xs text-gray-400">(Min.2h)</span>' : '' ?></td>
        <td class="px-4 py-3"><?= money($price) ?></td>
        <td class="px-4 py-3 font-medium text-brand"><?= money($rev) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if(empty($wh)): ?><tr><td colspan="7" class="px-4 py-6 text-center text-gray-400">Keine erledigten Jobs.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($tab === 'services'): ?>
<!-- Services -->
<div class="flex items-center justify-between mb-4">
  <h3 class="font-semibold"><?= count($services) ?> Services</h3>
  <button onclick="document.getElementById('addSvcForm').classList.toggle('hidden')" class="px-3 py-1.5 bg-brand text-white rounded-lg text-sm font-medium">+ Neuer Service</button>
</div>
<!-- Add Service Form (hidden) -->
<div id="addSvcForm" class="hidden bg-white rounded-xl border p-5 mb-4">
  <h4 class="font-semibold mb-3">Neuer Service für <?= e($c['name']) ?></h4>
  <div class="grid grid-cols-2 gap-3">
    <div class="col-span-2"><label class="text-xs text-gray-500">Service-Name *</label><input id="ns-title" placeholder="z.B. Wohnungsreinigung Berlin" class="w-full px-2 py-1.5 border rounded-lg text-sm"/></div>
    <div><label class="text-xs text-gray-500">Preis (netto/h) *</label><input id="ns-price" type="number" step="0.01" value="30.00" class="w-full px-2 py-1.5 border rounded-lg text-sm"/></div>
    <div><label class="text-xs text-gray-500">MwSt (%)</label><input id="ns-tax" type="number" value="19" class="w-full px-2 py-1.5 border rounded-lg text-sm"/></div>
    <div><label class="text-xs text-gray-500">Straße</label><input id="ns-street" value="<?= e($addr['street'] ?? '') ?> <?= e($addr['number'] ?? '') ?>" class="w-full px-2 py-1.5 border rounded-lg text-sm"/></div>
    <div><label class="text-xs text-gray-500">Stadt</label><input id="ns-city" value="<?= e($addr['city'] ?? 'Berlin') ?>" class="w-full px-2 py-1.5 border rounded-lg text-sm"/></div>
    <div><label class="text-xs text-gray-500">m²</label><input id="ns-qm" type="number" class="w-full px-2 py-1.5 border rounded-lg text-sm"/></div>
    <div><label class="text-xs text-gray-500">Zimmer</label><input id="ns-room" type="number" class="w-full px-2 py-1.5 border rounded-lg text-sm"/></div>
    <div><label class="text-xs text-gray-500">Box-Code</label><input id="ns-box" class="w-full px-2 py-1.5 border rounded-lg text-sm"/></div>
    <div><label class="text-xs text-gray-500">Client-Code</label><input id="ns-client" class="w-full px-2 py-1.5 border rounded-lg text-sm"/></div>
  </div>
  <div class="flex gap-2 mt-3">
    <button onclick="createSvc()" class="px-4 py-1.5 bg-brand text-white rounded-lg text-xs font-medium">Erstellen</button>
    <button onclick="document.getElementById('addSvcForm').classList.add('hidden')" class="px-4 py-1.5 border rounded-lg text-xs">Abbrechen</button>
  </div>
  <div id="ns-msg" class="text-xs mt-2 hidden"></div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
  <?php foreach ($services as $sv): $sid = $sv['s_id']; ?>
  <div class="bg-white rounded-xl border p-5" id="svc-<?= $sid ?>">
    <!-- View Mode -->
    <div id="svc-view-<?= $sid ?>">
      <div class="flex items-start justify-between">
        <h4 class="font-semibold mb-2"><?= e($sv['title']) ?></h4>
        <button onclick="toggleSvcEdit(<?= $sid ?>)" class="text-xs text-brand hover:underline">Edit</button>
      </div>
      <div class="space-y-1 text-sm text-gray-600">
        <div><?= e($sv['street']) ?> <?= e($sv['number']) ?>, <?= e($sv['postal_code']) ?> <?= e($sv['city']) ?></div>
        <?php if ($sv['total_price']): ?><div class="font-medium text-brand"><?= money($sv['total_price']) ?>/h</div><?php endif; ?>
        <?php if ($sv['box_code']): ?><div class="text-xs text-gray-500">Box: <?= e($sv['box_code']) ?><?= $sv['client_code'] ? ' | Client: '.e($sv['client_code']) : '' ?><?= $sv['deposit_code'] ? ' | Deposit: '.e($sv['deposit_code']) : '' ?></div><?php endif; ?>
        <?php if ($sv['wifi_name']): ?><div class="text-xs text-gray-500">WiFi: <?= e($sv['wifi_name']) ?> / <?= e($sv['wifi_password']) ?></div><?php endif; ?>
        <?php if ($sv['qm']): ?><div class="text-xs text-gray-500"><?= $sv['qm'] ?>m² | <?= $sv['room'] ?? '?' ?> Zimmer</div><?php endif; ?>
      </div>
    </div>
    <!-- Edit Mode (hidden by default) -->
    <div id="svc-edit-<?= $sid ?>" class="hidden space-y-3">
      <div class="grid grid-cols-2 gap-2">
        <div class="col-span-2"><label class="text-xs text-gray-500">Name</label><input id="se-title-<?= $sid ?>" value="<?= e($sv['title']) ?>" class="w-full px-2 py-1.5 border rounded-lg text-sm"/></div>
        <div><label class="text-xs text-gray-500">Preis (netto/h)</label><input id="se-price-<?= $sid ?>" type="number" step="0.01" value="<?= $sv['total_price'] ?>" class="w-full px-2 py-1.5 border rounded-lg text-sm"/></div>
        <div><label class="text-xs text-gray-500">MwSt (%)</label><input id="se-tax-<?= $sid ?>" type="number" step="1" value="19" class="w-full px-2 py-1.5 border rounded-lg text-sm"/></div>
        <div><label class="text-xs text-gray-500">Straße</label><input id="se-street-<?= $sid ?>" value="<?= e($sv['street']) ?>" class="w-full px-2 py-1.5 border rounded-lg text-sm"/></div>
        <div><label class="text-xs text-gray-500">Stadt</label><input id="se-city-<?= $sid ?>" value="<?= e($sv['city']) ?>" class="w-full px-2 py-1.5 border rounded-lg text-sm"/></div>
        <div><label class="text-xs text-gray-500">m²</label><input id="se-qm-<?= $sid ?>" type="number" value="<?= $sv['qm'] ?? '' ?>" class="w-full px-2 py-1.5 border rounded-lg text-sm"/></div>
        <div><label class="text-xs text-gray-500">Zimmer</label><input id="se-room-<?= $sid ?>" type="number" value="<?= $sv['room'] ?? '' ?>" class="w-full px-2 py-1.5 border rounded-lg text-sm"/></div>
        <div><label class="text-xs text-gray-500">Box-Code</label><input id="se-box-<?= $sid ?>" value="<?= e($sv['box_code'] ?? '') ?>" class="w-full px-2 py-1.5 border rounded-lg text-sm"/></div>
        <div><label class="text-xs text-gray-500">Client-Code</label><input id="se-client-<?= $sid ?>" value="<?= e($sv['client_code'] ?? '') ?>" class="w-full px-2 py-1.5 border rounded-lg text-sm"/></div>
      </div>
      <div class="flex gap-2">
        <button onclick="saveSvc(<?= $sid ?>)" class="px-4 py-1.5 bg-brand text-white rounded-lg text-xs font-medium">Speichern</button>
        <button onclick="toggleSvcEdit(<?= $sid ?>)" class="px-4 py-1.5 border rounded-lg text-xs">Abbrechen</button>
      </div>
      <div id="svc-msg-<?= $sid ?>" class="text-xs hidden"></div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if(empty($services)): ?><p class="text-sm text-gray-400">Keine Services zugewiesen. <a href="/admin/services.php" class="text-brand hover:underline">Service erstellen</a></p><?php endif; ?>
</div>
<script>
function toggleSvcEdit(id) {
    document.getElementById('svc-view-'+id).classList.toggle('hidden');
    document.getElementById('svc-edit-'+id).classList.toggle('hidden');
}
function createSvc() {
    const title = document.getElementById('ns-title').value.trim();
    if (!title) { alert('Name ist Pflichtfeld'); return; }
    const data = {
        title: title,
        customer_id_fk: <?= $cid ?>,
        total_price: parseFloat(document.getElementById('ns-price').value) || 30,
        price: parseFloat(document.getElementById('ns-price').value) || 30,
        tax_percentage: parseInt(document.getElementById('ns-tax').value) || 19,
        street: document.getElementById('ns-street').value,
        city: document.getElementById('ns-city').value,
        qm: document.getElementById('ns-qm').value || null,
        room: document.getElementById('ns-room').value || null,
        box_code: document.getElementById('ns-box').value,
        client_code: document.getElementById('ns-client').value,
    };
    fetch('/api/index.php?action=services/create', {
        method: 'POST',
        headers: {'Content-Type':'application/json', 'X-API-Key':'<?= API_KEY ?>'},
        body: JSON.stringify(data)
    }).then(r=>r.json()).then(d=>{
        const msg = document.getElementById('ns-msg');
        if(d.success) {
            msg.textContent = 'Service erstellt!'; msg.className = 'text-xs text-green-600'; msg.classList.remove('hidden');
            setTimeout(() => location.reload(), 800);
        } else {
            msg.textContent = d.error || 'Fehler'; msg.className = 'text-xs text-red-600'; msg.classList.remove('hidden');
        }
    });
}
function saveSvc(id) {
    const data = {
        s_id: id,
        title: document.getElementById('se-title-'+id).value,
        total_price: parseFloat(document.getElementById('se-price-'+id).value) || 0,
        street: document.getElementById('se-street-'+id).value,
        city: document.getElementById('se-city-'+id).value,
        qm: parseInt(document.getElementById('se-qm-'+id).value) || null,
        room: parseInt(document.getElementById('se-room-'+id).value) || null,
        box_code: document.getElementById('se-box-'+id).value,
        client_code: document.getElementById('se-client-'+id).value,
    };
    fetch('/api/index.php?action=services/update', {
        method: 'POST',
        headers: {'Content-Type':'application/json', 'X-API-Key':'<?= API_KEY ?>'},
        body: JSON.stringify(data)
    }).then(r=>r.json()).then(d=>{
        const msg = document.getElementById('svc-msg-'+id);
        if(d.success) {
            msg.textContent = 'Gespeichert!'; msg.className = 'text-xs text-green-600'; msg.classList.remove('hidden');
            setTimeout(() => location.reload(), 800);
        } else {
            msg.textContent = d.error || 'Fehler'; msg.className = 'text-xs text-red-600'; msg.classList.remove('hidden');
        }
    });
}
</script>

<?php elseif ($tab === 'notes'): ?>
<!-- Notizen / Interne Nachrichten -->
<div class="max-w-2xl">
  <form method="POST" class="bg-white rounded-xl border p-5 mb-4">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="add_note"/>
    <input type="hidden" name="author" value="<?= e(me()['name']) ?>"/>
    <textarea name="message" required rows="2" placeholder="Interne Notiz schreiben..." class="w-full px-3 py-2 border rounded-xl text-sm mb-2"></textarea>
    <button type="submit" class="px-4 py-2 bg-brand text-white rounded-xl text-sm font-medium">Notiz speichern</button>
  </form>
  <?php
  // Try to load notes (table may not exist yet)
  try { $notes = all("SELECT * FROM customer_notes WHERE customer_id_fk=? ORDER BY created_at DESC", [$cid]); } catch (Exception $e) { $notes = []; }
  foreach ($notes as $n): ?>
  <div class="bg-white rounded-xl border p-4 mb-2">
    <div class="flex justify-between text-xs text-gray-400 mb-1">
      <span class="font-medium text-gray-600"><?= e($n['author']) ?></span>
      <span><?= $n['created_at'] ?></span>
    </div>
    <p class="text-sm"><?= nl2br(e($n['message'])) ?></p>
  </div>
  <?php endforeach; ?>
  <?php if(empty($notes)): ?><p class="text-sm text-gray-400">Noch keine Notizen.</p><?php endif; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
