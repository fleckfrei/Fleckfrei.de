<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
require_once __DIR__ . '/../includes/texts.php';
siteTextEnsureTable();

$title = 'Texte (Website-Inhalte)';
$page  = 'texts';

// ---- handle POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { header('Location: /admin/texts.php?err=csrf'); exit; }
    $action = $_POST['action'] ?? '';
    $user   = $_SESSION['uname'] ?? 'admin';

    if ($action === 'save') {
        $updates = $_POST['texts'] ?? [];
        $changed = 0;
        foreach ($updates as $key => $value) {
            $key = trim((string) $key);
            if ($key === '') continue;
            $value = (string) $value;
            try {
                q("UPDATE site_content SET value_text=?, updated_by=? WHERE key_name=?", [$value, $user, $key]);
                $changed++;
            } catch (Exception $e) {}
        }
        audit('texts_updated', 'site_content', 0, "$changed keys");
        header('Location: /admin/texts.php?saved=' . $changed); exit;
    }

    if ($action === 'add') {
        $key  = trim($_POST['new_key'] ?? '');
        $cat  = trim($_POST['new_category'] ?? 'general') ?: 'general';
        $desc = trim($_POST['new_description'] ?? '');
        $html = !empty($_POST['new_is_html']) ? 1 : 0;
        $val  = (string) ($_POST['new_value'] ?? '');
        if ($key !== '' && preg_match('/^[a-z0-9_.-]+$/i', $key)) {
            try {
                q("INSERT INTO site_content (key_name, value_text, description, category, is_html, updated_by) VALUES (?,?,?,?,?,?)
                   ON DUPLICATE KEY UPDATE value_text=VALUES(value_text), description=VALUES(description), category=VALUES(category), is_html=VALUES(is_html), updated_by=VALUES(updated_by)",
                   [$key, $val, $desc, $cat, $html, $user]);
                audit('texts_added', 'site_content', 0, $key);
            } catch (Exception $e) {}
        }
        header('Location: /admin/texts.php?added=1'); exit;
    }

    if ($action === 'delete') {
        $key = trim($_POST['key'] ?? '');
        if ($key !== '') {
            try { q("DELETE FROM site_content WHERE key_name=?", [$key]); audit('texts_deleted', 'site_content', 0, $key); } catch (Exception $e) {}
        }
        header('Location: /admin/texts.php?deleted=1'); exit;
    }
}

// ---- seed a few defaults on first visit (only if table is empty) ----
try {
    $count = val("SELECT COUNT(*) FROM site_content");
    if ((int) $count === 0) {
        $seeds = [
            // key, value, description, category, is_html
            ['home.hero.title',         'Smart. Sauber. Zuverlässig.',                                  'Große Überschrift auf Startseite',     'website',     0],
            ['home.hero.subtitle',      'Professionelle Reinigung für Ihr Zuhause und Büro in Berlin.', 'Unterzeile auf Startseite',            'website',     0],
            ['home.cta.button',         'Jetzt buchen',                                                 'CTA-Button-Text auf Startseite',       'website',     0],
            ['booking.confirm.title',   'Danke für Ihre Buchung!',                                      'Überschrift Buchungs-Bestätigung',     'booking',     0],
            ['booking.confirm.body',    'Wir melden uns innerhalb von 2 Stunden mit der Bestätigung.',  'Bestätigungs-Fließtext',               'booking',     0],
            ['footer.copyright',        '© Fleckfrei GmbH — Berlin',                                   'Footer-Copyright',                      'footer',      0],
            ['footer.tagline',          'Smart. Sauber. Zuverlässig.',                                  'Footer-Tagline',                       'footer',      0],
            ['legal.imprint.link_text', 'Impressum',                                                    'Text für Impressum-Link',              'footer',      0],
        ];
        foreach ($seeds as $s) {
            try { q("INSERT IGNORE INTO site_content (key_name, value_text, description, category, is_html) VALUES (?,?,?,?,?)", $s); } catch (Exception $e) {}
        }
    }
} catch (Exception $e) {}

// ---- fetch ----
$rows = [];
try {
    $rows = all("SELECT key_name, value_text, description, category, is_html, updated_at, updated_by FROM site_content ORDER BY category, key_name");
} catch (Exception $e) {}

$byCat = [];
foreach ($rows as $r) { $byCat[$r['category']][] = $r; }

include __DIR__ . '/../includes/layout.php';
?>

<?php if (isset($_GET['saved'])): ?>
  <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4">
    <?= (int) $_GET['saved'] ?> Texte gespeichert.
  </div>
<?php endif; ?>
<?php if (isset($_GET['added'])): ?>
  <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4">Neuer Text hinzugefügt.</div>
<?php endif; ?>
<?php if (isset($_GET['deleted'])): ?>
  <div class="bg-yellow-50 border border-yellow-200 text-yellow-800 px-4 py-3 rounded-xl mb-4">Text gelöscht.</div>
<?php endif; ?>
<?php if (isset($_GET['err'])): ?>
  <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-xl mb-4">Fehler: <?= e($_GET['err']) ?></div>
<?php endif; ?>

<div class="mb-6">
  <p class="text-sm text-gray-600">
    Diese Texte erscheinen auf der Website und in E-Mails. Nach dem Speichern sofort sichtbar.
    Im Code einbinden mit <code class="bg-gray-100 px-1.5 py-0.5 rounded text-xs">&lt;?= siteText('key.name', 'Fallback') ?&gt;</code>.
  </p>
</div>

<form method="POST" class="space-y-6 mb-10">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="save"/>

  <?php if (empty($byCat)): ?>
    <div class="bg-white border rounded-xl p-6 text-center text-gray-500">
      Noch keine Texte angelegt. Benutzen Sie das Formular unten, um den ersten Text hinzuzufügen.
    </div>
  <?php else: ?>
    <?php foreach ($byCat as $cat => $items): ?>
      <div class="bg-white border rounded-xl overflow-hidden">
        <div class="px-5 py-3 bg-gray-50 border-b">
          <h3 class="font-semibold capitalize"><?= e($cat) ?></h3>
        </div>
        <div class="divide-y">
          <?php foreach ($items as $r): ?>
            <div class="px-5 py-4">
              <div class="flex items-start justify-between gap-3 mb-2">
                <div class="flex-1">
                  <div class="flex items-center gap-2 mb-1">
                    <code class="text-xs font-mono bg-gray-100 px-2 py-0.5 rounded text-brand"><?= e($r['key_name']) ?></code>
                    <?php if ($r['is_html']): ?>
                      <span class="text-[10px] uppercase tracking-wider bg-blue-50 text-blue-700 px-1.5 py-0.5 rounded">HTML</span>
                    <?php endif; ?>
                  </div>
                  <?php if ($r['description']): ?>
                    <div class="text-xs text-gray-500"><?= e($r['description']) ?></div>
                  <?php endif; ?>
                </div>
                <div class="text-[11px] text-gray-400 shrink-0 text-right">
                  <?= e($r['updated_at'] ?? '') ?><br>
                  <?= e($r['updated_by'] ?? '') ?>
                </div>
              </div>
              <textarea
                name="texts[<?= e($r['key_name']) ?>]"
                rows="<?= $r['is_html'] ? 5 : 2 ?>"
                class="w-full px-3 py-2 border rounded-xl font-mono text-sm"
                ><?= e($r['value_text'] ?? '') ?></textarea>
              <div class="mt-2 text-right">
                <button type="submit" form="delete-<?= e($r['key_name']) ?>"
                        class="text-xs text-red-600 hover:underline"
                        onclick="return confirm('Text \'<?= e($r['key_name']) ?>\' wirklich löschen?')">
                  Löschen
                </button>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>

    <div class="flex justify-end gap-3 sticky bottom-4">
      <button type="submit" class="px-6 py-2.5 bg-brand text-white rounded-xl font-medium shadow-lg hover:shadow-xl transition">
        Alle Änderungen speichern
      </button>
    </div>
  <?php endif; ?>
</form>

<?php foreach ($rows as $r): ?>
  <form method="POST" id="delete-<?= e($r['key_name']) ?>" class="hidden">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="delete"/>
    <input type="hidden" name="key" value="<?= e($r['key_name']) ?>"/>
  </form>
<?php endforeach; ?>

<div class="bg-white border rounded-xl p-5 mt-8">
  <h3 class="font-semibold mb-4">Neuer Text</h3>
  <form method="POST" class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="add"/>
    <div>
      <label class="block text-sm font-medium text-gray-600 mb-1">Schlüssel (key)</label>
      <input name="new_key" required pattern="[a-z0-9_.\-]+" placeholder="z.B. home.features.title"
             class="w-full px-3 py-2 border rounded-xl font-mono"/>
      <p class="text-xs text-gray-400 mt-1">Nur Kleinbuchstaben, Zahlen, Punkt, Unterstrich, Bindestrich.</p>
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-600 mb-1">Kategorie</label>
      <input name="new_category" value="general" class="w-full px-3 py-2 border rounded-xl"/>
    </div>
    <div class="lg:col-span-2">
      <label class="block text-sm font-medium text-gray-600 mb-1">Beschreibung (intern)</label>
      <input name="new_description" class="w-full px-3 py-2 border rounded-xl"
             placeholder="z.B. 'Titel der Feature-Sektion auf Startseite'"/>
    </div>
    <div class="lg:col-span-2">
      <label class="block text-sm font-medium text-gray-600 mb-1">Wert</label>
      <textarea name="new_value" rows="3" class="w-full px-3 py-2 border rounded-xl"></textarea>
    </div>
    <div>
      <label class="inline-flex items-center gap-2 text-sm">
        <input type="checkbox" name="new_is_html" value="1"/>
        HTML erlaubt (sonst Plain Text, in Seiten wird automatisch escaped)
      </label>
    </div>
    <div class="text-right">
      <button type="submit" class="px-5 py-2 bg-brand text-white rounded-xl font-medium">Hinzufügen</button>
    </div>
  </form>
</div>
