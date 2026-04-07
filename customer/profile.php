<?php
require_once __DIR__ . '/../includes/auth.php';
requireCustomer();
if (!customerCan('profile')) { header('Location: /customer/'); exit; }
$title = 'Mein Profil'; $page = 'profile';
$cid = me()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'delete_account') {
    if (!verifyCsrf()) { header('Location: /customer/profile.php?error=csrf'); exit; }
    // Deactivate account (data retained for legal/business purposes)
    q("UPDATE customer SET status=0 WHERE customer_id=?", [$cid]);
    audit('deactivate', 'customer', $cid, 'Kunde hat Konto deaktiviert');
    telegramNotify("Kunde #$cid hat sein Konto deaktiviert. Daten bleiben erhalten.");
    session_destroy();
    header('Location: /login.php?deactivated=1'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'update_profile') {
    q("UPDATE customer SET name=?,surname=?,phone=?,notes=? WHERE customer_id=?",
      [$_POST['name'],$_POST['surname']??'',$_POST['phone']??'',$_POST['notes']??'',$cid]);
    if (!empty($_POST['new_password'])) {
        q("UPDATE customer SET password=? WHERE customer_id=?", [password_hash($_POST['new_password'], PASSWORD_BCRYPT, ['cost' => 12]), $cid]);
    }
    header("Location: /customer/profile.php?saved=1"); exit;
}

$c = one("SELECT * FROM customer WHERE customer_id=?", [$cid]);
$addresses = all("SELECT * FROM customer_address WHERE customer_id_fk=?", [$cid]);

include __DIR__ . '/../includes/layout.php';
?>

<?php if (!empty($_GET['saved'])): ?><div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4">Profil gespeichert.</div><?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
  <div class="bg-white rounded-xl border p-5">
    <h3 class="font-semibold mb-4">Persönliche Daten</h3>
    <form method="POST" class="space-y-4">
      <input type="hidden" name="action" value="update_profile"/>
      <div class="grid grid-cols-2 gap-4">
        <div><label class="block text-sm font-medium text-gray-600 mb-1">Name</label><input name="name" value="<?= e($c['name']) ?>" class="w-full px-3 py-2.5 border rounded-xl"/></div>
        <div><label class="block text-sm font-medium text-gray-600 mb-1">Nachname</label><input name="surname" value="<?= e($c['surname']) ?>" class="w-full px-3 py-2.5 border rounded-xl"/></div>
      </div>
      <div><label class="block text-sm font-medium text-gray-600 mb-1">E-Mail</label><input value="<?= e($c['email']) ?>" disabled class="w-full px-3 py-2.5 border rounded-xl bg-gray-50 text-gray-500"/></div>
      <div><label class="block text-sm font-medium text-gray-600 mb-1">Telefon</label><input name="phone" value="<?= e($c['phone']) ?>" class="w-full px-3 py-2.5 border rounded-xl"/></div>
      <div><label class="block text-sm font-medium text-gray-600 mb-1">Typ</label><input value="<?= e($c['customer_type']) ?>" disabled class="w-full px-3 py-2.5 border rounded-xl bg-gray-50 text-gray-500"/></div>
      <div><label class="block text-sm font-medium text-gray-600 mb-1">Notizen</label><textarea name="notes" rows="3" class="w-full px-3 py-2.5 border rounded-xl"><?= e($c['notes']) ?></textarea></div>
      <div><label class="block text-sm font-medium text-gray-600 mb-1">Neues Passwort (leer lassen = nicht ändern)</label><input type="password" name="new_password" class="w-full px-3 py-2.5 border rounded-xl" placeholder="••••••••"/></div>
      <button type="submit" class="w-full px-4 py-2.5 bg-brand text-white rounded-xl font-medium">Speichern</button>
    </form>
  </div>

  <div>
    <div class="bg-white rounded-xl border p-5 mb-6">
      <h3 class="font-semibold mb-4">Adressen</h3>
      <?php if (!empty($addresses)): ?>
      <div class="space-y-3">
        <?php foreach ($addresses as $a): ?>
        <div class="bg-gray-50 rounded-lg p-3 text-sm">
          <div class="font-medium"><?= e($a['street']) ?> <?= e($a['number']) ?></div>
          <div class="text-gray-500"><?= e($a['postal_code']) ?> <?= e($a['city']) ?>, <?= e($a['country']) ?></div>
          <div class="text-xs text-gray-400"><?= e($a['address_for']) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <p class="text-gray-400 text-sm">Keine Adressen hinterlegt.</p>
      <?php endif; ?>
    </div>

    <div class="bg-brand/5 rounded-xl border border-brand/20 p-5 mb-6">
      <h3 class="font-semibold text-brand mb-3">Hilfe & Kontakt</h3>
      <div class="space-y-2">
        <a href="https://wa.me/<?= CONTACT_WA ?>" target="_blank" class="flex items-center gap-2 text-sm text-green-700 hover:underline">WhatsApp kontaktieren</a>
        <a href="mailto:<?= CONTACT_EMAIL ?>" class="flex items-center gap-2 text-sm text-gray-700 hover:underline"><?= CONTACT_EMAIL ?></a>
      </div>
    </div>

    <!-- GDPR -->
    <div class="bg-white rounded-xl border p-5 mb-6">
      <h3 class="font-semibold mb-3">Datenschutz (DSGVO)</h3>
      <div class="text-sm text-gray-500 space-y-2">
        <p>Wir speichern Ihre Kontaktdaten (Name, E-Mail, Telefon), Adressen und Job-Verlauf zur Vertragserfüllung gemäß Art. 6 Abs. 1b DSGVO.</p>
        <p>GPS-Daten werden nur bei Job-Start/Stop erfasst zur Qualitätssicherung.</p>
        <p>Sie haben das Recht auf Auskunft, Berichtigung und Löschung Ihrer Daten (Art. 15-17 DSGVO).</p>
        <p>Verantwortlich: <?= SITE ?>, <?= CONTACT_EMAIL ?></p>
      </div>
    </div>

    <!-- Account deaktivieren -->
    <div class="bg-red-50 rounded-xl border border-red-200 p-5">
      <h3 class="font-semibold text-red-700 mb-2">Konto deaktivieren</h3>
      <p class="text-sm text-red-600 mb-3">Ihr Konto wird deaktiviert. Sie können es jederzeit durch Kontakt mit uns reaktivieren.</p>
      <form method="POST" onsubmit="return confirm('Konto wirklich deaktivieren?')">
        <input type="hidden" name="action" value="delete_account"/>
        <?= csrfField() ?>
        <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-xl text-sm font-medium">Konto deaktivieren</button>
      </form>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
