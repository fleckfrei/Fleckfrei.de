<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Einstellungen'; $page = 'settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'update_settings') {
    $fields = ['first_name','last_name','company','phone','email','website','invoice_prefix','invoice_number','bank','bic','iban','USt_IdNr','business_number','fiscal_number','invoice_text','street','number','postal_code','city','country','note_for_email'];
    $sets = []; $params = [];
    foreach ($fields as $f) { $sets[] = "$f=?"; $params[] = $_POST[$f] ?? ''; }
    q("UPDATE settings SET " . implode(',', $sets), $params);
    header("Location: /admin/settings.php?saved=1"); exit;
}

$s = one("SELECT * FROM settings LIMIT 1") ?: [];
include __DIR__ . '/../includes/layout.php';
?>

<?php if (!empty($_GET['saved'])): ?><div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4">Einstellungen gespeichert.</div><?php endif; ?>

<form method="POST" class="space-y-6">
  <input type="hidden" name="action" value="update_settings"/>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <div class="bg-white rounded-xl border p-5">
      <h3 class="font-semibold mb-4">Unternehmen</h3>
      <div class="space-y-4">
        <div class="grid grid-cols-2 gap-4">
          <div><label class="block text-sm font-medium text-gray-600 mb-1">Vorname</label><input name="first_name" value="<?= e($s['first_name']??'') ?>" class="w-full px-3 py-2.5 border rounded-xl"/></div>
          <div><label class="block text-sm font-medium text-gray-600 mb-1">Nachname</label><input name="last_name" value="<?= e($s['last_name']??'') ?>" class="w-full px-3 py-2.5 border rounded-xl"/></div>
        </div>
        <div><label class="block text-sm font-medium text-gray-600 mb-1">Firma</label><input name="company" value="<?= e($s['company']??'') ?>" class="w-full px-3 py-2.5 border rounded-xl"/></div>
        <div class="grid grid-cols-2 gap-4">
          <div><label class="block text-sm font-medium text-gray-600 mb-1">Telefon</label><input name="phone" value="<?= e($s['phone']??'') ?>" class="w-full px-3 py-2.5 border rounded-xl"/></div>
          <div><label class="block text-sm font-medium text-gray-600 mb-1">E-Mail</label><input name="email" value="<?= e($s['email']??'') ?>" class="w-full px-3 py-2.5 border rounded-xl"/></div>
        </div>
        <div><label class="block text-sm font-medium text-gray-600 mb-1">Website</label><input name="website" value="<?= e($s['website']??'') ?>" class="w-full px-3 py-2.5 border rounded-xl"/></div>
        <div class="grid grid-cols-3 gap-4">
          <div class="col-span-2"><label class="block text-sm font-medium text-gray-600 mb-1">Strasse</label><input name="street" value="<?= e($s['street']??'') ?>" class="w-full px-3 py-2.5 border rounded-xl"/></div>
          <div><label class="block text-sm font-medium text-gray-600 mb-1">Nr.</label><input name="number" value="<?= e($s['number']??'') ?>" class="w-full px-3 py-2.5 border rounded-xl"/></div>
        </div>
        <div class="grid grid-cols-3 gap-4">
          <div><label class="block text-sm font-medium text-gray-600 mb-1">PLZ</label><input name="postal_code" value="<?= e($s['postal_code']??'') ?>" class="w-full px-3 py-2.5 border rounded-xl"/></div>
          <div><label class="block text-sm font-medium text-gray-600 mb-1">Stadt</label><input name="city" value="<?= e($s['city']??'') ?>" class="w-full px-3 py-2.5 border rounded-xl"/></div>
          <div><label class="block text-sm font-medium text-gray-600 mb-1">Land</label><input name="country" value="<?= e($s['country']??'') ?>" class="w-full px-3 py-2.5 border rounded-xl"/></div>
        </div>
      </div>
    </div>

    <div class="bg-white rounded-xl border p-5">
      <h3 class="font-semibold mb-4">Rechnungseinstellungen</h3>
      <div class="space-y-4">
        <div class="grid grid-cols-2 gap-4">
          <div><label class="block text-sm font-medium text-gray-600 mb-1">Prefix</label><input name="invoice_prefix" value="<?= e($s['invoice_prefix']??'') ?>" class="w-full px-3 py-2.5 border rounded-xl"/></div>
          <div><label class="block text-sm font-medium text-gray-600 mb-1">Nächste Nr.</label><input name="invoice_number" value="<?= e($s['invoice_number']??'') ?>" class="w-full px-3 py-2.5 border rounded-xl"/></div>
        </div>
        <div><label class="block text-sm font-medium text-gray-600 mb-1">Bank</label><input name="bank" value="<?= e($s['bank']??'') ?>" class="w-full px-3 py-2.5 border rounded-xl"/></div>
        <div class="grid grid-cols-2 gap-4">
          <div><label class="block text-sm font-medium text-gray-600 mb-1">IBAN</label><input name="iban" value="<?= e($s['iban']??'') ?>" class="w-full px-3 py-2.5 border rounded-xl font-mono"/></div>
          <div><label class="block text-sm font-medium text-gray-600 mb-1">BIC</label><input name="bic" value="<?= e($s['bic']??'') ?>" class="w-full px-3 py-2.5 border rounded-xl font-mono"/></div>
        </div>
        <div class="grid grid-cols-2 gap-4">
          <div><label class="block text-sm font-medium text-gray-600 mb-1">USt-IdNr</label><input name="USt_IdNr" value="<?= e($s['USt_IdNr']??'') ?>" class="w-full px-3 py-2.5 border rounded-xl font-mono"/></div>
          <div><label class="block text-sm font-medium text-gray-600 mb-1">Gewerbe-Nr.</label><input name="business_number" value="<?= e($s['business_number']??'') ?>" class="w-full px-3 py-2.5 border rounded-xl"/></div>
        </div>
        <div><label class="block text-sm font-medium text-gray-600 mb-1">Finanzamt-Nr.</label><input name="fiscal_number" value="<?= e($s['fiscal_number']??'') ?>" class="w-full px-3 py-2.5 border rounded-xl"/></div>
        <div><label class="block text-sm font-medium text-gray-600 mb-1">Rechnungstext</label><textarea name="invoice_text" rows="3" class="w-full px-3 py-2.5 border rounded-xl"><?= e($s['invoice_text']??'') ?></textarea></div>
        <div><label class="block text-sm font-medium text-gray-600 mb-1">E-Mail Notiz</label><textarea name="note_for_email" rows="3" class="w-full px-3 py-2.5 border rounded-xl"><?= e($s['note_for_email']??'') ?></textarea></div>
      </div>
    </div>
  </div>

  <div class="flex justify-end">
    <button type="submit" class="px-8 py-3 bg-brand text-white rounded-xl font-semibold text-lg shadow-lg shadow-brand/20 hover:bg-brand/90 transition">Einstellungen speichern</button>
  </div>
</form>
<?php include __DIR__ . '/../includes/footer.php'; ?>
