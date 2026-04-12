<?php
require_once __DIR__ . '/../includes/auth.php';
requireCustomer();
if (!customerCan('profile')) { header('Location: /customer/'); exit; }
$title = 'Kontoeinstellungen'; $page = 'profile';
$cid = me()['id'];

// Business customer types — show "Firmenname" instead of Vor/Nachname
$businessTypes = ['Airbnb', 'B2B', 'Host', 'Business', 'Booking', 'Short-Term Rental', 'Firma', 'GmbH'];
$isBusinessCustomer = in_array(one("SELECT customer_type FROM customer WHERE customer_id=?", [$cid])['customer_type'] ?? '', $businessTypes, true);

// ============================================================
// POST handlers
// ============================================================

// GDPR delete — archive data then empty visible fields
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_account_permanent') {
    if (!verifyCsrf()) { header('Location: /customer/profile.php?error=csrf'); exit; }
    $current = one("SELECT * FROM customer WHERE customer_id=?", [$cid]);
    // Archive snapshot (without password)
    unset($current['password']);
    q("UPDATE customer SET
        archived_snapshot=?,
        name='', surname='', phone='', notes='',
        is_cancel=1, status=0, deleted_at=NOW()
        WHERE customer_id=?",
      [json_encode($current, JSON_UNESCAPED_UNICODE), $cid]);
    audit('delete', 'customer', $cid, 'GDPR-Löschung: Daten archiviert, Profil geleert');
    telegramNotify("🗑 Kunde #$cid hat Konto GELÖSCHT (GDPR). Daten archiviert, Profil geleert.");
    session_destroy();
    header('Location: /login.php?deleted=1'); exit;
}

// Pause account (reversible)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_account') {
    if (!verifyCsrf()) { header('Location: /customer/profile.php?error=csrf'); exit; }
    q("UPDATE customer SET status=0 WHERE customer_id=?", [$cid]);
    audit('deactivate', 'customer', $cid, 'Kunde hat Konto pausiert');
    telegramNotify("⏸ Kunde #$cid hat Konto pausiert (reversibel).");
    session_destroy();
    header('Location: /login.php?deactivated=1'); exit;
}

// Update profile (personal + business)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    if (!verifyCsrf()) { header('Location: /customer/profile.php'); exit; }

    // Phone history tracking — append old phone before overwriting
    $old = one("SELECT phone, phone_history FROM customer WHERE customer_id=?", [$cid]);
    $newPhone = trim($_POST['phone'] ?? '');
    $newHistory = $old['phone_history'];
    if ($old['phone'] && $old['phone'] !== $newPhone) {
        $hist = json_decode($old['phone_history'] ?? '[]', true) ?: [];
        $hist[] = ['phone' => $old['phone'], 'removed_at' => date('Y-m-d H:i:s')];
        // Keep last 10
        if (count($hist) > 10) $hist = array_slice($hist, -10);
        $newHistory = json_encode($hist, JSON_UNESCAPED_UNICODE);
        audit('update', 'customer_phone', $cid, "Telefon geändert: {$old['phone']} → $newPhone");
    }

    q("UPDATE customer SET name=?, surname=?, phone=?, phone_history=?, notes=? WHERE customer_id=?",
      [$_POST['name'] ?? '', $_POST['surname'] ?? '', $newPhone, $newHistory, $_POST['notes'] ?? '', $cid]);

    if (!empty($_POST['new_password'])) {
        q("UPDATE customer SET password=? WHERE customer_id=?",
          [password_hash($_POST['new_password'], PASSWORD_BCRYPT, ['cost' => 12]), $cid]);
    }
    header("Location: /customer/profile.php?saved=1"); exit;
}

// Update consent (marketing)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_consent') {
    if (!verifyCsrf()) { header('Location: /customer/profile.php?section=privacy'); exit; }
    q("UPDATE customer SET consent_phone=?, consent_email=?, consent_whatsapp=?, consent_updated_at=NOW() WHERE customer_id=?",
      [!empty($_POST['consent_phone']) ? 1 : 0,
       !empty($_POST['consent_email']) ? 1 : 0,
       !empty($_POST['consent_whatsapp']) ? 1 : 0,
       $cid]);
    audit('update', 'customer_consent', $cid, 'Marketing-Einwilligungen aktualisiert');
    header("Location: /customer/profile.php?section=privacy&saved=1"); exit;
}

// Delete an address
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_address') {
    if (!verifyCsrf()) { header('Location: /customer/profile.php?section=addresses'); exit; }
    $aid = (int)($_POST['address_id'] ?? 0);
    if ($aid) {
        // Verify ownership
        $a = one("SELECT * FROM customer_address WHERE ca_id=? AND customer_id_fk=?", [$aid, $cid]);
        if ($a) {
            q("DELETE FROM customer_address WHERE ca_id=? AND customer_id_fk=?", [$aid, $cid]);
            audit('delete', 'customer_address', $aid, 'Adresse vom Kunden gelöscht');
        }
    }
    header("Location: /customer/profile.php?section=addresses&saved=1"); exit;
}

// Add new address
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_address') {
    if (!verifyCsrf()) { header('Location: /customer/profile.php?section=addresses'); exit; }
    $street = trim($_POST['street'] ?? '');
    $number = trim($_POST['number'] ?? '');
    $postal = trim($_POST['postal_code'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $country = trim($_POST['country'] ?? 'Deutschland');
    $type = trim($_POST['address_for'] ?? 'Wohnung');
    if ($street !== '' && $city !== '') {
        q("INSERT INTO customer_address (street, number, postal_code, city, country, address_for, customer_id_fk) VALUES (?, ?, ?, ?, ?, ?, ?)",
          [$street, $number, $postal, $city, $country, $type, $cid]);
        audit('create', 'customer_address', (int)lastInsertId(), "Adresse hinzugefügt: $type");
    }
    header("Location: /customer/profile.php?section=addresses&saved=1"); exit;
}

$customer = one("SELECT * FROM customer WHERE customer_id=?", [$cid]);
try { $addresses = all("SELECT * FROM customer_address WHERE customer_id_fk=? ORDER BY ca_id DESC", [$cid]); } catch (Exception $e) { $addresses = []; }

// Address type → readable label + color
function addressTypeLabel(string $type): array {
    $map = [
        'Billing Address' => ['Rechnungsadresse', 'bg-blue-100 text-blue-700'],
        'billing'         => ['Rechnungsadresse', 'bg-blue-100 text-blue-700'],
        'location'        => ['Service-Standort', 'bg-green-100 text-green-700'],
        'Wohnung'         => ['Wohnung', 'bg-green-100 text-green-700'],
        'Büro'            => ['Büro', 'bg-amber-100 text-amber-700'],
        'Ferienwohnung'   => ['Ferienwohnung', 'bg-purple-100 text-purple-700'],
        'Garage'          => ['Garage', 'bg-gray-100 text-gray-700'],
        'Praxis'          => ['Praxis', 'bg-pink-100 text-pink-700'],
        'Laden'           => ['Laden', 'bg-orange-100 text-orange-700'],
        'Treppenhaus'     => ['Treppenhaus', 'bg-gray-100 text-gray-700'],
        'Baustelle'       => ['Baustelle', 'bg-yellow-100 text-yellow-700'],
    ];
    return $map[$type] ?? [$type ?: 'Standort', 'bg-gray-100 text-gray-700'];
}

$section = $_GET['section'] ?? 'personal';
include __DIR__ . '/../includes/layout-customer.php';
?>

<!-- Back button -->
<div class="mb-4">
  <a href="/customer/" class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-brand transition">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
    Zurück
  </a>
</div>

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
<!-- ===================== PERSÖNLICH ===================== -->
<div class="card-elev p-6 max-w-2xl">
  <h2 class="font-bold text-lg mb-5"><?= $isBusinessCustomer ? 'Unternehmensdaten' : 'Persönliche Daten' ?></h2>
  <form method="POST" class="space-y-4">
    <input type="hidden" name="action" value="update_profile"/>

    <?php if ($isBusinessCustomer): ?>
    <!-- Business: company name full-width + optional contact person -->
    <div>
      <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Firmenname</label>
      <input name="name" value="<?= e($customer['name']) ?>" placeholder="Musterfirma GmbH" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none"/>
    </div>
    <div>
      <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Ansprechpartner <span class="text-gray-400 normal-case">(optional)</span></label>
      <input name="surname" value="<?= e($customer['surname']) ?>" placeholder="Max Mustermann" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none"/>
    </div>
    <?php else: ?>
    <!-- Private: Vorname + Nachname -->
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
    <?php endif; ?>

    <div>
      <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">E-Mail</label>
      <input value="<?= e($customer['email']) ?>" disabled class="w-full px-3 py-2.5 border border-gray-200 rounded-lg bg-gray-50 text-gray-500"/>
      <div class="text-[11px] text-gray-400 mt-1">Zum Ändern Ihrer E-Mail kontaktieren Sie uns bitte.</div>
    </div>
    <div>
      <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Telefon</label>
      <input name="phone" value="<?= e($customer['phone']) ?>" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none"/>
      <?php
        $phoneHist = json_decode($customer['phone_history'] ?? '[]', true) ?: [];
        if (!empty($phoneHist)):
      ?>
      <div class="text-[11px] text-gray-400 mt-1">
        <?= count($phoneHist) ?> frühere Nummer<?= count($phoneHist) === 1 ? '' : 'n' ?> gespeichert (aus Sicherheitsgründen archiviert)
      </div>
      <?php endif; ?>
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
<!-- ===================== ADRESSEN ===================== -->
<div class="card-elev p-6 max-w-2xl" x-data="{ showForm: false }">
  <div class="flex items-center justify-between mb-5">
    <h2 class="font-bold text-lg">Meine Adressen</h2>
    <button @click="showForm = !showForm" class="px-3 py-2 bg-brand hover:bg-brand-dark text-white rounded-lg text-sm font-semibold flex items-center gap-1.5 transition">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
      Hinzufügen
    </button>
  </div>

  <!-- Add form -->
  <div x-show="showForm" x-cloak x-transition class="mb-5 border border-brand/30 bg-brand-light/30 rounded-xl p-4">
    <form method="POST" class="space-y-3">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add_address"/>

      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Typ</label>
        <select name="address_for" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg bg-white outline-none focus:ring-2 focus:ring-brand focus:border-brand">
          <option value="Wohnung">🏠 Wohnung — Service-Standort</option>
          <option value="Büro">🏢 Büro — Service-Standort</option>
          <option value="Ferienwohnung">🌴 Ferienwohnung — Service-Standort</option>
          <option value="Praxis">⚕️ Praxis — Service-Standort</option>
          <option value="Laden">🛍️ Laden — Service-Standort</option>
          <option value="Baustelle">🚧 Baustelle — Service-Standort</option>
          <option value="Sonstige">📍 Sonstiger Standort</option>
          <option value="Billing Address">🧾 Rechnungsadresse</option>
        </select>
        <p class="text-[11px] text-gray-400 mt-1">Service-Standort = hier wird gereinigt. Rechnungsadresse = hierhin geht die Rechnung.</p>
      </div>

      <div class="grid grid-cols-3 gap-3">
        <div class="col-span-2">
          <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Straße</label>
          <input name="street" required placeholder="Pasteurstraße" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg outline-none focus:ring-2 focus:ring-brand focus:border-brand"/>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Nr.</label>
          <input name="number" placeholder="17" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg outline-none focus:ring-2 focus:ring-brand focus:border-brand"/>
        </div>
      </div>
      <div class="grid grid-cols-3 gap-3">
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">PLZ</label>
          <input name="postal_code" placeholder="10407" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg outline-none focus:ring-2 focus:ring-brand focus:border-brand"/>
        </div>
        <div class="col-span-2">
          <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Stadt</label>
          <input name="city" required placeholder="Berlin" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg outline-none focus:ring-2 focus:ring-brand focus:border-brand"/>
        </div>
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Land</label>
        <input name="country" value="Deutschland" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg outline-none focus:ring-2 focus:ring-brand focus:border-brand"/>
      </div>
      <div class="flex gap-2 pt-1">
        <button type="submit" class="flex-1 px-4 py-2.5 bg-brand hover:bg-brand-dark text-white rounded-lg font-semibold text-sm">Speichern</button>
        <button type="button" @click="showForm = false" class="px-4 py-2.5 border border-gray-200 rounded-lg text-sm text-gray-600 hover:bg-gray-50">Abbrechen</button>
      </div>
    </form>
  </div>

  <?php if (!empty($addresses)): ?>
  <div class="grid gap-3">
    <?php foreach ($addresses as $a):
      [$label, $colorClass] = addressTypeLabel($a['address_for'] ?? '');
    ?>
    <div class="border border-gray-200 rounded-xl p-4 flex items-start gap-3 hover:border-brand transition">
      <div class="w-10 h-10 rounded-lg bg-brand-light flex items-center justify-center flex-shrink-0">
        <svg class="w-5 h-5 text-brand" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
      </div>
      <div class="flex-1 min-w-0">
        <div class="flex items-start justify-between gap-2 mb-1">
          <div class="font-semibold text-gray-900"><?= e($a['street']) ?> <?= e($a['number']) ?></div>
          <span class="inline-block px-2 py-0.5 rounded-full text-[10px] font-semibold <?= $colorClass ?> whitespace-nowrap"><?= e($label) ?></span>
        </div>
        <div class="text-sm text-gray-500"><?= e($a['postal_code']) ?> <?= e($a['city']) ?><?= !empty($a['country']) ? ', ' . e($a['country']) : '' ?></div>
      </div>
      <form method="POST" onsubmit="return confirm('Adresse wirklich löschen?')" class="flex-shrink-0">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="delete_address"/>
        <input type="hidden" name="address_id" value="<?= (int)$a['ca_id'] ?>"/>
        <button type="submit" class="w-8 h-8 rounded-lg hover:bg-red-50 text-gray-400 hover:text-red-600 flex items-center justify-center transition" title="Löschen">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
        </button>
      </form>
    </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="text-center py-10">
    <div class="w-14 h-14 mx-auto rounded-full bg-gray-100 flex items-center justify-center mb-3">
      <svg class="w-7 h-7 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/></svg>
    </div>
    <p class="text-gray-500 text-sm">Noch keine Adressen.</p>
    <p class="text-xs text-gray-400 mt-1">Click oben auf "Hinzufügen" um eine Adresse anzulegen.</p>
  </div>
  <?php endif; ?>
</div>

<?php elseif ($section === 'privacy'): ?>
<!-- ===================== DATENSCHUTZ ===================== -->
<div class="card-elev p-6 max-w-2xl mb-6">
  <h2 class="font-bold text-lg mb-4">Datenschutz (DSGVO)</h2>
  <div class="text-sm text-gray-600 space-y-3 leading-relaxed">
    <p>Wir speichern Ihre Kontaktdaten (Name, E-Mail, Telefon), Adressen und Ihren Job-Verlauf zur Vertragserfüllung gemäß Art. 6 Abs. 1b DSGVO.</p>
    <p>GPS-Daten werden nur bei Job-Start/Stop erfasst zur Qualitätssicherung und Nachweis der Dienstleistung.</p>
    <p>Sie haben das Recht auf <strong>Auskunft, Berichtigung und Löschung</strong> Ihrer Daten (Art. 15–17 DSGVO). Für eine Datenauskunft kontaktieren Sie uns gerne.</p>
    <p class="text-xs text-gray-500 pt-2 border-t">Verantwortlicher: <?= SITE ?>, <?= CONTACT_EMAIL ?></p>
  </div>
</div>

<!-- Marketing consent -->
<div class="card-elev p-6 max-w-2xl">
  <h2 class="font-bold text-lg mb-1">Marketing-Einwilligungen</h2>
  <p class="text-sm text-gray-500 mb-5">Sie entscheiden, auf welchen Kanälen wir Sie kontaktieren dürfen. Jederzeit widerrufbar.</p>

  <form method="POST" class="space-y-3">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="update_consent"/>

    <label class="flex items-start gap-3 p-3 border border-gray-200 rounded-xl hover:border-brand cursor-pointer transition">
      <input type="checkbox" name="consent_phone" value="1" <?= !empty($customer['consent_phone']) ? 'checked' : '' ?> class="mt-1 accent-brand w-4 h-4"/>
      <div class="flex-1 min-w-0">
        <div class="font-semibold text-gray-900 text-sm">Telefon-Marketing erlaubt</div>
        <div class="text-xs text-gray-500 mt-0.5">Wir dürfen Sie anrufen für Angebote, Umfragen oder Terminabstimmung jenseits bestehender Buchungen.</div>
      </div>
    </label>

    <label class="flex items-start gap-3 p-3 border border-gray-200 rounded-xl hover:border-brand cursor-pointer transition">
      <input type="checkbox" name="consent_whatsapp" value="1" <?= !empty($customer['consent_whatsapp']) ? 'checked' : '' ?> class="mt-1 accent-brand w-4 h-4"/>
      <div class="flex-1 min-w-0">
        <div class="font-semibold text-gray-900 text-sm">WhatsApp-Marketing erlaubt</div>
        <div class="text-xs text-gray-500 mt-0.5">Wir dürfen Ihnen WhatsApp-Nachrichten mit Angeboten und Rabattaktionen senden.</div>
      </div>
    </label>

    <label class="flex items-start gap-3 p-3 border border-gray-200 rounded-xl hover:border-brand cursor-pointer transition">
      <input type="checkbox" name="consent_email" value="1" <?= !empty($customer['consent_email']) ? 'checked' : '' ?> class="mt-1 accent-brand w-4 h-4"/>
      <div class="flex-1 min-w-0">
        <div class="font-semibold text-gray-900 text-sm">E-Mail Newsletter erlaubt</div>
        <div class="text-xs text-gray-500 mt-0.5">Monatlicher Newsletter mit Tipps, Angeboten und neuen Services.</div>
      </div>
    </label>

    <?php if (!empty($customer['consent_updated_at'])): ?>
    <p class="text-[11px] text-gray-400 pt-1">Zuletzt aktualisiert: <?= date('d.m.Y H:i', strtotime($customer['consent_updated_at'])) ?> Uhr</p>
    <?php endif; ?>

    <button type="submit" class="w-full sm:w-auto px-6 py-3 bg-brand hover:bg-brand-dark text-white rounded-lg font-semibold text-sm mt-2">Einwilligungen speichern</button>
  </form>
</div>

<?php elseif ($section === 'account'): ?>
<!-- ===================== KONTO ===================== -->
<!-- Pause (reversible) -->
<div class="card-elev p-6 max-w-2xl bg-gray-50 mb-6">
  <h2 class="font-bold text-lg mb-3 text-gray-700">Konto pausieren</h2>
  <p class="text-sm text-gray-600 mb-4">Ihr Konto wird deaktiviert. <strong>Keine Daten werden gelöscht.</strong> Sie können es jederzeit reaktivieren — kontaktieren Sie uns per WhatsApp oder E-Mail.</p>
  <form method="POST" onsubmit="return confirm('Konto wirklich pausieren? Sie können es jederzeit wieder aktivieren.')">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="delete_account"/>
    <button type="submit" class="px-5 py-2.5 bg-gray-500 hover:bg-gray-600 text-white rounded-lg text-sm font-semibold">Konto pausieren</button>
  </form>
</div>

<!-- Permanent delete (GDPR) -->
<div class="card-elev p-6 max-w-2xl border-red-200 bg-red-50">
  <h2 class="font-bold text-lg mb-3 text-red-800">Konto endgültig löschen</h2>
  <div class="text-sm text-red-900/80 space-y-2 mb-4">
    <p><strong>Achtung:</strong> Dies ist ein endgültiger Schritt gemäß DSGVO Art. 17.</p>
    <ul class="list-disc pl-5 space-y-1 text-xs">
      <li>Ihr Name, Telefon, Notizen und Adressen werden aus der Anzeige entfernt.</li>
      <li>Ein verschlüsselter Datenschnappschuss wird <strong>30 Tage</strong> archiviert (falls Sie sich umentscheiden).</li>
      <li>Rechnungen und Job-Verlauf bleiben aus gesetzlichen Gründen (Aufbewahrungspflicht §147 AO) erhalten, jedoch ohne Bezug zu Ihrer Person.</li>
      <li>Login mit dieser E-Mail ist nach der Löschung nicht mehr möglich.</li>
    </ul>
  </div>
  <form method="POST" onsubmit="return confirm('Wirklich ENDGÜLTIG löschen? Dies kann NUR innerhalb von 30 Tagen durch Kontakt mit dem Support rückgängig gemacht werden.')">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="delete_account_permanent"/>
    <button type="submit" class="px-5 py-2.5 bg-red-600 hover:bg-red-700 text-white rounded-lg text-sm font-semibold">Konto endgültig löschen</button>
  </form>
</div>
<?php endif; ?>

<?php
// iCal subscription — generate token if missing
$icalToken = val("SELECT ical_token FROM customer WHERE customer_id=?", [$cid]);
if (empty($icalToken)) {
    $icalToken = bin2hex(random_bytes(24));
    q("UPDATE customer SET ical_token=? WHERE customer_id=?", [$icalToken, $cid]);
}
$icalUrl = 'https://app.fleckfrei.de/api/ical-export.php?token=' . $icalToken . '&customer=' . $cid;
$webcalUrl = str_replace('https://', 'webcal://', $icalUrl);
?>
<div class="card-elev p-6 mb-6" x-data="{ copied: false, showApple: false, showGoogle: false }">
  <div class="flex items-start gap-3 mb-4">
    <div class="w-10 h-10 rounded-xl bg-indigo-100 flex items-center justify-center text-xl flex-shrink-0">📅</div>
    <div class="flex-1">
      <h3 class="font-bold text-gray-900">Termine in Ihrem Kalender abonnieren</h3>
      <p class="text-xs text-gray-500 mt-0.5">Sehen Sie alle Ihre Fleckfrei-Reinigungstermine live in Apple Calendar, Google Calendar, Outlook etc. Updates kommen automatisch.</p>
    </div>
  </div>

  <!-- URL + copy button -->
  <div class="bg-gray-50 border border-gray-200 rounded-xl p-3 flex items-center gap-2 mb-3">
    <svg class="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
    <code class="flex-1 min-w-0 text-[11px] font-mono text-gray-700 truncate"><?= e($icalUrl) ?></code>
    <button @click="navigator.clipboard.writeText('<?= e($icalUrl) ?>'); copied = true; setTimeout(() => copied = false, 2000)"
            class="px-3 py-1.5 bg-brand hover:bg-brand-dark text-white rounded-lg text-[11px] font-semibold whitespace-nowrap transition">
      <span x-show="!copied">Kopieren</span>
      <span x-show="copied" x-cloak>✓ Kopiert</span>
    </button>
  </div>

  <!-- Quick buttons -->
  <div class="grid grid-cols-2 gap-2">
    <a href="<?= e($webcalUrl) ?>" class="flex items-center justify-center gap-2 px-3 py-2.5 border border-gray-200 hover:border-brand hover:bg-brand/5 rounded-lg text-xs font-semibold text-gray-700 hover:text-brand transition">
       Apple Kalender
    </a>
    <a href="https://calendar.google.com/calendar/u/0/r?cid=<?= urlencode($icalUrl) ?>" target="_blank" rel="noopener" class="flex items-center justify-center gap-2 px-3 py-2.5 border border-gray-200 hover:border-brand hover:bg-brand/5 rounded-lg text-xs font-semibold text-gray-700 hover:text-brand transition">
       Google Kalender
    </a>
  </div>

  <div class="mt-3 text-[10px] text-gray-400">
    <strong>Hinweis:</strong> Diese URL ist privat — teilen Sie sie nicht. Bei Verdacht können Sie jederzeit einen neuen Token anfordern (Support).
  </div>
</div>

<?php include __DIR__ . '/../includes/footer-customer.php'; ?>
