<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Einstellungen'; $page = 'settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'update_settings') {
    if (!verifyCsrf()) { header('Location: /admin/settings.php'); exit; }
    $fields = ['first_name','last_name','company','phone','email','website','invoice_prefix','invoice_number','bank','bic','iban','USt_IdNr','business_number','fiscal_number','invoice_text','street','number','postal_code','city','country','note_for_email','email_booking','email_job_start','email_job_complete','email_invoice','email_reminder','discount_weekly','discount_biweekly','discount_monthly','discount_active'];
    $checkboxes = ['email_booking','email_job_start','email_job_complete','email_invoice','email_reminder','discount_active'];
    $sets = []; $params = [];
    foreach ($fields as $f) { $sets[] = "$f=?"; $params[] = in_array($f, $checkboxes) ? (isset($_POST[$f]) ? '1' : '0') : ($_POST[$f] ?? ''); }

    // Handle logo upload
    if (!empty($_FILES['logo']['tmp_name'])) {
        $allowed = ['image/png','image/jpeg','image/gif','image/svg+xml','image/webp'];
        if (in_array($_FILES['logo']['type'], $allowed) && $_FILES['logo']['size'] < 2 * 1024 * 1024) {
            $ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
            $uploadDir = __DIR__ . '/../uploads/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $filename = 'logo_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $uploadDir . $filename)) {
                $sets[] = "logo_path=?";
                $params[] = '/uploads/' . $filename;
            }
        }
    }

    q("UPDATE settings SET " . implode(',', $sets), $params);
    header("Location: /admin/settings.php?saved=1"); exit;
}

$s = one("SELECT * FROM settings LIMIT 1") ?: [];
include __DIR__ . '/../includes/layout.php';
?>

<?php if (!empty($_GET['saved'])): ?><div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4">Einstellungen gespeichert.</div><?php endif; ?>

<form method="POST" enctype="multipart/form-data" class="space-y-6">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="update_settings"/>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <div class="bg-white rounded-xl border p-5">
      <h3 class="font-semibold mb-4">Unternehmen</h3>
      <div class="space-y-4">
        <!-- Logo Upload -->
        <div>
          <label class="block text-sm font-medium text-gray-600 mb-2">Firmen-Logo</label>
          <div class="flex items-center gap-4">
            <?php if (!empty($s['logo_path'])): ?>
            <img src="<?= e($s['logo_path']) ?>" class="w-16 h-16 rounded-xl object-contain border bg-white" alt="Logo"/>
            <?php else: ?>
            <div class="w-16 h-16 rounded-xl bg-brand flex items-center justify-center text-white text-2xl font-bold"><?= LOGO_LETTER ?></div>
            <?php endif; ?>
            <div class="flex-1">
              <input type="file" name="logo" accept="image/png,image/jpeg,image/svg+xml,image/webp" class="w-full text-sm text-gray-500 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-brand-light file:text-brand hover:file:bg-brand hover:file:text-white file:cursor-pointer file:transition"/>
              <p class="text-xs text-gray-400 mt-1">PNG, JPG, SVG oder WebP. Max 2MB.</p>
            </div>
          </div>
        </div>
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

  
  <!-- Stammkunden-Rabatte -->
  <div class="bg-white rounded-xl border p-5">
    <h3 class="font-semibold mb-1">💰 Stammkunden-Rabatte (Booking)</h3>
    <p class="text-xs text-gray-500 mb-4">Wirkt auf Customer-Booking-Form. Inaktiv = keine Rabatte gewährt, Buttons zeigen kein "Sparen Sie X%".</p>
    <label class="flex items-center gap-3 mb-4 p-3 bg-gray-50 rounded-lg cursor-pointer">
      <input type="checkbox" name="discount_active" value="1" <?= ($s['discount_active']??1) ? 'checked' : '' ?> class="rounded"/>
      <span class="text-sm font-medium">Rabatte aktiv</span>
    </label>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Wöchentlich (%)</label>
        <input type="number" step="0.5" min="0" max="50" name="discount_weekly" value="<?= e($s['discount_weekly']??7) ?>" class="w-full px-3 py-2 border rounded-lg text-sm"/>
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Alle 2 Wochen (%)</label>
        <input type="number" step="0.5" min="0" max="50" name="discount_biweekly" value="<?= e($s['discount_biweekly']??5) ?>" class="w-full px-3 py-2 border rounded-lg text-sm"/>
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Monatlich (%)</label>
        <input type="number" step="0.5" min="0" max="50" name="discount_monthly" value="<?= e($s['discount_monthly']??3) ?>" class="w-full px-3 py-2 border rounded-lg text-sm"/>
      </div>
    </div>
  </div>

  <!-- Email & Benachrichtigungen -->
  <div class="bg-white rounded-xl border p-5">
    <h3 class="font-semibold mb-4">E-Mail Benachrichtigungen</h3>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
      <div class="space-y-3">
        <p class="text-sm text-gray-500">Automatische E-Mails werden gesendet bei:</p>
        <label class="flex items-center gap-3"><input type="checkbox" name="email_booking" value="1" <?= ($s['email_booking']??1) ? 'checked' : '' ?> class="rounded"/> <span class="text-sm">Neue Buchung (Bestätigung)</span></label>
        <label class="flex items-center gap-3"><input type="checkbox" name="email_job_start" value="1" <?= ($s['email_job_start']??1) ? 'checked' : '' ?> class="rounded"/> <span class="text-sm">Job gestartet</span></label>
        <label class="flex items-center gap-3"><input type="checkbox" name="email_job_complete" value="1" <?= ($s['email_job_complete']??1) ? 'checked' : '' ?> class="rounded"/> <span class="text-sm">Job abgeschlossen</span></label>
        <label class="flex items-center gap-3"><input type="checkbox" name="email_invoice" value="1" <?= ($s['email_invoice']??1) ? 'checked' : '' ?> class="rounded"/> <span class="text-sm">Neue Rechnung</span></label>
        <label class="flex items-center gap-3"><input type="checkbox" name="email_reminder" value="1" <?= ($s['email_reminder']??1) ? 'checked' : '' ?> class="rounded"/> <span class="text-sm">Erinnerung (1 Tag vorher)</span></label>
      </div>
      <div class="bg-gray-50 rounded-xl p-4">
        <h4 class="text-sm font-medium text-gray-700 mb-2">Bankverbindung auf Rechnungen</h4>
        <div class="text-sm text-gray-500 space-y-1">
          <p><strong>Bank:</strong> <?= e($s['bank']??'—') ?></p>
          <p><strong>IBAN:</strong> <?= e($s['iban']??'—') ?></p>
          <p><strong>BIC:</strong> <?= e($s['bic']??'—') ?></p>
          <p><strong>USt-IdNr:</strong> <?= e($s['USt_IdNr']??'—') ?></p>
        </div>
        <p class="text-xs text-gray-400 mt-3">Diese Daten erscheinen auf PDF-Rechnungen und im Kundenportal.</p>
      </div>
    </div>
  </div>

  <div class="flex justify-end">
    <button type="submit" class="px-8 py-3 bg-brand text-white rounded-xl font-semibold text-lg shadow-lg shadow-brand/20 hover:bg-brand/90 transition">Einstellungen speichern</button>
  </div>
</form>

<!-- iCal Feeds Management -->
<div class="mt-6" x-data="icalFeedsApp()" x-init="loadFeeds()">
  <div class="bg-white rounded-xl border p-5">
    <div class="flex items-center justify-between mb-4">
      <h3 class="font-semibold">iCal Feeds</h3>
      <div class="flex gap-2">
        <button @click="syncAll()" :disabled="syncing" class="px-3 py-1.5 bg-brand text-white rounded-lg text-xs font-medium hover:opacity-90 transition" x-text="syncing ? 'Sync...' : 'Alle synchronisieren'"></button>
        <button @click="showAdd=!showAdd" class="px-3 py-1.5 border rounded-lg text-xs font-medium hover:bg-gray-50 transition">+ Feed hinzufügen</button>
      </div>
    </div>

    <!-- Sync result -->
    <div x-show="syncMsg" x-transition class="bg-green-50 border border-green-200 text-green-800 px-3 py-2 rounded-lg mb-4 text-sm" x-text="syncMsg"></div>

    <!-- Add form -->
    <div x-show="showAdd" x-transition class="bg-gray-50 rounded-xl p-4 mb-4 space-y-3">
      <div class="grid grid-cols-2 gap-3">
        <div><label class="block text-xs font-medium text-gray-600 mb-1">Name</label><input x-model="newFeed.label" placeholder="z.B. Airbnb Aurorei" class="w-full px-3 py-2 border rounded-lg text-sm"/></div>
        <div><label class="block text-xs font-medium text-gray-600 mb-1">Plattform</label>
          <select x-model="newFeed.platform" class="w-full px-3 py-2 border rounded-lg text-sm">
            <option value="airbnb">Airbnb</option>
            <option value="booking">Booking.com</option>
            <option value="vrbo">VRBO</option>
            <option value="smoobu">Smoobu</option>
            <option value="ical">Andere (iCal)</option>
          </select>
        </div>
      </div>
      <div><label class="block text-xs font-medium text-gray-600 mb-1">iCal URL</label><input x-model="newFeed.url" placeholder="https://..." class="w-full px-3 py-2 border rounded-lg text-sm font-mono"/></div>
      <div><label class="block text-xs font-medium text-gray-600 mb-1">Kunde</label>
        <select x-model="newFeed.customer_id_fk" class="w-full px-3 py-2 border rounded-lg text-sm">
          <option value="">— Kein Kunde —</option>
          <?php $customers = all("SELECT customer_id, name FROM customer WHERE status=1 ORDER BY name"); foreach ($customers as $c): ?>
          <option value="<?= $c['customer_id'] ?>"><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="flex gap-2">
        <button @click="testFeed()" :disabled="testing" class="px-3 py-1.5 border rounded-lg text-xs font-medium hover:bg-gray-50" x-text="testing ? 'Teste...' : 'URL testen'"></button>
        <button @click="addFeed()" class="px-3 py-1.5 bg-brand text-white rounded-lg text-xs font-medium hover:opacity-90">Hinzufügen</button>
        <button @click="showAdd=false" class="px-3 py-1.5 text-gray-400 text-xs">Abbrechen</button>
      </div>
      <div x-show="testResult" class="text-xs px-3 py-2 rounded-lg" :class="testResult.startsWith('OK') ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'" x-text="testResult"></div>
    </div>

    <!-- Feeds table -->
    <div class="divide-y" x-show="feeds.length > 0">
      <template x-for="f in feeds" :key="f.id">
        <div class="py-3 flex items-center justify-between">
          <div>
            <div class="font-medium text-sm" x-text="f.label"></div>
            <div class="text-xs text-gray-400 font-mono truncate max-w-md" x-text="f.url"></div>
            <div class="flex gap-3 mt-1 text-xs text-gray-400">
              <span x-text="f.platform"></span>
              <span x-text="f.customer_name || '—'"></span>
              <span x-text="f.last_sync ? 'Sync: ' + f.last_sync : 'Noch nie synchronisiert'" :class="f.last_sync ? 'text-green-600' : 'text-yellow-600'"></span>
              <span x-text="'Jobs: ' + (f.jobs_created || 0)"></span>
            </div>
          </div>
          <div class="flex gap-2 flex-shrink-0">
            <button @click="syncFeed(f.id)" class="px-2 py-1 border rounded text-xs hover:bg-gray-50">Sync</button>
            <button @click="deleteFeed(f.id)" class="px-2 py-1 border border-red-200 text-red-600 rounded text-xs hover:bg-red-50">Löschen</button>
          </div>
        </div>
      </template>
    </div>
    <div x-show="feeds.length === 0 && !showAdd" class="py-6 text-center text-gray-400 text-sm">Keine iCal Feeds konfiguriert. Klicke "Feed hinzufügen" um externe Kalender zu importieren.</div>
  </div>
</div>

<?php
$apiKey = API_KEY;
$script = <<<JS
function icalFeedsApp() {
  return {
    feeds: [], showAdd: false, syncing: false, testing: false,
    syncMsg: '', testResult: '',
    newFeed: { label:'', url:'', platform:'airbnb', customer_id_fk:'' },

    loadFeeds() {
      fetch('/api/index.php?action=ical/feeds', { headers:{'X-API-Key':'$apiKey'} })
        .then(r=>r.json())
        .then(d=>{ this.feeds = d.data || []; });
    },

    addFeed() {
      if (!this.newFeed.label || !this.newFeed.url) return alert('Name und URL erforderlich');
      fetch('/api/index.php?action=ical/feeds', {
        method:'POST', headers:{'Content-Type':'application/json','X-API-Key':'$apiKey'},
        body: JSON.stringify(this.newFeed)
      }).then(r=>r.json()).then(d=> {
        if (d.success) { this.showAdd=false; this.newFeed={label:'',url:'',platform:'airbnb',customer_id_fk:''}; this.loadFeeds(); }
        else alert(d.error);
      });
    },

    deleteFeed(id) {
      if (!confirm('Feed wirklich löschen?')) return;
      fetch('/api/index.php?action=ical/feeds/delete', {
        method:'POST', headers:{'Content-Type':'application/json','X-API-Key':'$apiKey'},
        body: JSON.stringify({id})
      }).then(r=>r.json()).then(()=> this.loadFeeds());
    },

    syncFeed(id) {
      this.syncing = true; this.syncMsg = '';
      fetch('/api/index.php?action=ical/sync', {
        method:'POST', headers:{'Content-Type':'application/json','X-API-Key':'$apiKey'},
        body: JSON.stringify({feed_id: id})
      }).then(r=>r.json()).then(d=> {
        this.syncing = false;
        this.syncMsg = d.success ? d.data.total_created + ' erstellt, ' + d.data.total_updated + ' aktualisiert' : (d.error || 'Fehler');
        this.loadFeeds();
      }).catch(()=> { this.syncing=false; });
    },

    syncAll() {
      this.syncing = true; this.syncMsg = '';
      fetch('/api/index.php?action=ical/sync', {
        method:'POST', headers:{'Content-Type':'application/json','X-API-Key':'$apiKey'},
        body: JSON.stringify({})
      }).then(r=>r.json()).then(d=> {
        this.syncing = false;
        this.syncMsg = d.success ? d.data.feeds_synced + ' Feeds: ' + d.data.total_created + ' erstellt, ' + d.data.total_updated + ' aktualisiert' : (d.error || 'Fehler');
        this.loadFeeds();
      }).catch(()=> { this.syncing=false; });
    },

    testFeed() {
      if (!this.newFeed.url) return;
      this.testing = true; this.testResult = '';
      fetch('/api/ical-import.php?key=$apiKey&test=1&url=' + encodeURIComponent(this.newFeed.url))
        .then(r=>r.json())
        .then(d=> {
          this.testing = false;
          this.testResult = d.success ? 'OK — ' + d.events + ' Events gefunden' : 'Fehler: ' + (d.error || 'Nicht erreichbar');
        }).catch(()=> { this.testing=false; this.testResult='Netzwerk-Fehler'; });
    }
  };
}
JS;
?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
