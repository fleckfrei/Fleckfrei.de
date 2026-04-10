<?php
require_once __DIR__ . '/../../includes/auth.php';
requireCustomer();
if (!customerCan('profile')) { header('Location: /customer/v2/'); exit; }
$title = 'Kontoeinstellungen'; $page = 'profile';
$cid = me()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_account') {
    if (!verifyCsrf()) { header('Location: /customer/v2/profile.php?error=csrf'); exit; }
    q("UPDATE customer SET status=0 WHERE customer_id=?", [$cid]);
    audit('deactivate', 'customer', $cid, 'Kunde hat Konto deaktiviert (v2)');
    telegramNotify("Kunde #$cid hat sein Konto deaktiviert (v2). Daten bleiben erhalten.");
    session_destroy();
    header('Location: /login.php?deactivated=1'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    if (!verifyCsrf()) { header('Location: /customer/v2/profile.php'); exit; }
    q("UPDATE customer SET name=?, surname=?, phone=?, notes=? WHERE customer_id=?",
      [$_POST['name'] ?? '', $_POST['surname'] ?? '', $_POST['phone'] ?? '', $_POST['notes'] ?? '', $cid]);
    if (!empty($_POST['new_password'])) {
        q("UPDATE customer SET password=? WHERE customer_id=?",
          [password_hash($_POST['new_password'], PASSWORD_BCRYPT, ['cost' => 12]), $cid]);
    }
    header("Location: /customer/v2/profile.php?saved=1"); exit;
}

$customer = one("SELECT * FROM customer WHERE customer_id=?", [$cid]);
try { $addresses = all("SELECT * FROM customer_address WHERE customer_id_fk=?", [$cid]); } catch (Exception $e) { $addresses = []; }

$section = $_GET['section'] ?? 'personal';
include __DIR__ . '/../../includes/layout-v2.php';
?>

<div class="mb-6">
  <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Kontoeinstellungen</h1>
  <p class="text-gray-500 mt-1 text-sm">Persönliche Daten, Adressen und Datenschutz.</p>
</div>

<?php if (!empty($_GET['saved'])): ?>
<div class="mb-6 card-elev border-green-200 bg-green-50 p-4 text-sm text-green-800 flex items-center gap-2">
  <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
  Änderungen gespeichert.
</div>
<?php endif; ?>

<!-- Section tabs -->
<div class="border-b mb-6">
  <div class="flex gap-8 overflow-x-auto">
    <a href="?section=personal" class="tab-underline whitespace-nowrap <?= $section === 'personal' ? 'active' : '' ?>">Persönlich</a>
    <a href="?section=addresses" class="tab-underline whitespace-nowrap <?= $section === 'addresses' ? 'active' : '' ?>">Adressen</a>
    <a href="?section=privacy" class="tab-underline whitespace-nowrap <?= $section === 'privacy' ? 'active' : '' ?>">Datenschutz</a>
    <a href="?section=account" class="tab-underline whitespace-nowrap <?= $section === 'account' ? 'active' : '' ?>">Konto</a>
  </div>
</div>

<?php if ($section === 'personal'): ?>
<div class="card-elev p-6 max-w-2xl">
  <h2 class="font-bold text-lg mb-5">Persönliche Daten</h2>
  <form method="POST" class="space-y-4">
    <input type="hidden" name="action" value="update_profile"/>
    <div class="grid grid-cols-2 gap-4">
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Vorname</label>
        <input name="name" value="<?= e($customer['name']) ?>" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none"/>
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Nachname</label>
        <input name="surname" value="<?= e($customer['surname']) ?>" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none"/>
      </div>
    </div>
    <div>
      <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">E-Mail</label>
      <input value="<?= e($customer['email']) ?>" disabled class="w-full px-3 py-2.5 border border-gray-200 rounded-lg bg-gray-50 text-gray-500"/>
      <div class="text-[11px] text-gray-400 mt-1">Zum Ändern Ihrer E-Mail kontaktieren Sie uns bitte.</div>
    </div>
    <div>
      <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Telefon</label>
      <input name="phone" value="<?= e($customer['phone']) ?>" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none"/>
    </div>
    <div>
      <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Kundentyp</label>
      <input value="<?= e($customer['customer_type']) ?>" disabled class="w-full px-3 py-2.5 border border-gray-200 rounded-lg bg-gray-50 text-gray-500"/>
    </div>
    <div>
      <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Notizen</label>
      <textarea name="notes" rows="3" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none"><?= e($customer['notes']) ?></textarea>
    </div>
    <div>
      <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Neues Passwort</label>
      <input type="password" name="new_password" placeholder="leer lassen = nicht ändern" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none"/>
    </div>
    <?= csrfField() ?>
    <button type="submit" class="w-full sm:w-auto px-6 py-3 bg-brand hover:bg-brand-dark text-white rounded-lg font-semibold text-sm">Änderungen speichern</button>
  </form>
</div>

<?php elseif ($section === 'addresses'): ?>
<div class="card-elev p-6 max-w-2xl">
  <h2 class="font-bold text-lg mb-5">Meine Adressen</h2>
  <?php if (!empty($addresses)): ?>
  <div class="grid gap-3">
    <?php foreach ($addresses as $a): ?>
    <div class="border border-gray-200 rounded-xl p-4 flex items-start gap-3 hover:border-brand transition">
      <div class="w-10 h-10 rounded-lg bg-brand-light flex items-center justify-center flex-shrink-0">
        <svg class="w-5 h-5 text-brand" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
      </div>
      <div class="flex-1 min-w-0">
        <div class="font-semibold text-gray-900"><?= e($a['street']) ?> <?= e($a['number']) ?></div>
        <div class="text-sm text-gray-500"><?= e($a['postal_code']) ?> <?= e($a['city']) ?><?= !empty($a['country']) ? ', ' . e($a['country']) : '' ?></div>
        <?php if (!empty($a['address_for'])): ?>
        <div class="text-xs text-gray-400 mt-1">Für: <?= e($a['address_for']) ?></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <p class="text-gray-500 text-sm">Keine Adressen hinterlegt. Adressen werden beim Buchungsprozess automatisch ergänzt.</p>
  <?php endif; ?>
</div>

<?php elseif ($section === 'privacy'): ?>
<div class="card-elev p-6 max-w-2xl">
  <h2 class="font-bold text-lg mb-4">Datenschutz (DSGVO)</h2>
  <div class="text-sm text-gray-600 space-y-3 leading-relaxed">
    <p>Wir speichern Ihre Kontaktdaten (Name, E-Mail, Telefon), Adressen und Ihren Job-Verlauf zur Vertragserfüllung gemäß Art. 6 Abs. 1b DSGVO.</p>
    <p>GPS-Daten werden nur bei Job-Start/Stop erfasst zur Qualitätssicherung und Nachweis der Dienstleistung.</p>
    <p>Sie haben das Recht auf <strong>Auskunft, Berichtigung und Löschung</strong> Ihrer Daten (Art. 15–17 DSGVO). Für eine Datenauskunft kontaktieren Sie uns gerne.</p>
    <p class="text-xs text-gray-500 pt-2 border-t">Verantwortlicher: <?= SITE ?>, <?= CONTACT_EMAIL ?></p>
  </div>
</div>

<?php elseif ($section === 'account'): ?>
<div class="card-elev p-6 max-w-2xl bg-gray-50">
  <h2 class="font-bold text-lg mb-3 text-gray-700">Konto pausieren</h2>
  <p class="text-sm text-gray-600 mb-4">Ihr Konto wird deaktiviert. <strong>Keine Daten werden gelöscht.</strong> Sie können es jederzeit reaktivieren — kontaktieren Sie uns per WhatsApp oder E-Mail.</p>
  <form method="POST" onsubmit="return confirm('Konto wirklich pausieren? Sie können es jederzeit wieder aktivieren.')">
    <input type="hidden" name="action" value="delete_account"/>
    <?= csrfField() ?>
    <button type="submit" class="px-5 py-2.5 bg-gray-500 hover:bg-gray-600 text-white rounded-lg text-sm font-semibold">Konto pausieren</button>
  </form>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer-v2.php'; ?>
