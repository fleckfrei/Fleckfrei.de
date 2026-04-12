<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Optionale Produkte'; $page = 'optional-products';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { header('Location: /admin/optional-products.php'); exit; }
    $act = $_POST['action'] ?? '';

    if ($act === 'create' || $act === 'update') {
        $fields = [
            $_POST['name'] ?? '',
            $_POST['description'] ?? null,
            $_POST['pricing_type'] ?? 'flat',
            (float)($_POST['customer_price'] ?? 0),
            (float)($_POST['partner_bonus'] ?? 0),
            (float)($_POST['tax_percentage'] ?? 19),
            !empty($_POST['is_active']) ? 1 : 0,
            $_POST['visibility'] ?? 'all',
            $_POST['icon'] ?? null,
            (int)($_POST['sort_order'] ?? 0),
        ];
        if ($act === 'create') {
            q("INSERT INTO optional_products (name, description, pricing_type, customer_price, partner_bonus, tax_percentage, is_active, visibility, icon, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?)", $fields);
            audit('create', 'optional_products', (int)lastInsertId(), 'Optionales Produkt erstellt: ' . $fields[0]);
        } else {
            $fields[] = (int)($_POST['op_id'] ?? 0);
            q("UPDATE optional_products SET name=?, description=?, pricing_type=?, customer_price=?, partner_bonus=?, tax_percentage=?, is_active=?, visibility=?, icon=?, sort_order=? WHERE op_id=?", $fields);
            audit('update', 'optional_products', (int)$_POST['op_id'], 'Optionales Produkt aktualisiert: ' . $fields[0]);
        }
        header('Location: /admin/optional-products.php?saved=1'); exit;
    }

    if ($act === 'delete') {
        $opId = (int)($_POST['op_id'] ?? 0);
        q("UPDATE optional_products SET is_active=0 WHERE op_id=?", [$opId]);
        audit('delete', 'optional_products', $opId, 'Deaktiviert');
        header('Location: /admin/optional-products.php?saved=1'); exit;
    }
    if ($act === 'reactivate') {
        q("UPDATE optional_products SET is_active=1 WHERE op_id=?", [(int)($_POST['op_id'] ?? 0)]);
        header('Location: /admin/optional-products.php?saved=1'); exit;
    }
}

$products = all("SELECT * FROM optional_products ORDER BY sort_order ASC, op_id ASC");
$editId = (int)($_GET['edit'] ?? 0);
$editing = $editId ? one("SELECT * FROM optional_products WHERE op_id=?", [$editId]) : null;

$pricingLabels = [
    'flat' => 'Pauschal (fester Betrag)',
    'per_hour' => 'Pro Stunde (Aufschlag)',
    'percentage' => 'Prozentual (vom Gesamtpreis)',
];
$visibilityLabels = [
    'all' => '👁 Alle Kunden sehen es',
    'private' => '🏠 Nur Privatkunden',
    'business' => '🏢 Nur Geschäftskunden (B2B/Airbnb)',
    'host' => '🌴 Nur Hosts (Airbnb/Booking)',
    'hidden' => '🔒 Versteckt (nur per Admin zuweisbar)',
];

include __DIR__ . '/../includes/layout.php';
?>

<?php if (!empty($_GET['saved'])): ?>
<div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4">Gespeichert.</div>
<?php endif; ?>

<div class="flex items-start justify-between mb-6 flex-wrap gap-4">
  <div>
    <h1 class="text-2xl font-bold text-gray-900">Optionale Produkte</h1>
    <p class="text-sm text-gray-500 mt-1">Add-Ons die Kunden bei der Buchung auswählen können (z.B. Priorität, Reinigungsmittel).</p>
  </div>
  <a href="/admin/optional-products.php?edit=new" class="inline-flex items-center gap-2 px-4 py-2 bg-brand hover:bg-brand/90 text-white rounded-xl text-sm font-semibold">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
    Neues Produkt
  </a>
</div>

<?php if ($editing !== null || $editId === -1 || isset($_GET['edit'])): ?>
<?php $e_row = $editing ?: ['op_id'=>0,'name'=>'','description'=>'','pricing_type'=>'flat','customer_price'=>0,'partner_bonus'=>0,'tax_percentage'=>19,'is_active'=>1,'visibility'=>'all','icon'=>'','sort_order'=>0]; ?>
<div class="bg-white rounded-xl border p-6 mb-6">
  <h2 class="text-lg font-semibold mb-4"><?= $editing ? 'Bearbeiten' : 'Neues Produkt' ?></h2>
  <form method="POST" class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>"/>
    <?php if ($editing): ?><input type="hidden" name="op_id" value="<?= (int)$e_row['op_id'] ?>"/><?php endif; ?>

    <div class="lg:col-span-2">
      <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Name</label>
      <input name="name" value="<?= e($e_row['name']) ?>" required placeholder="z.B. Priorität / Fixe Uhrzeit" class="w-full px-3 py-2.5 border rounded-xl"/>
    </div>

    <div class="lg:col-span-2">
      <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Beschreibung</label>
      <textarea name="description" rows="2" placeholder="Was bekommt der Kunde?" class="w-full px-3 py-2.5 border rounded-xl"><?= e($e_row['description'] ?? '') ?></textarea>
    </div>

    <div>
      <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Preis-Typ</label>
      <select name="pricing_type" class="w-full px-3 py-2.5 border rounded-xl bg-white">
        <?php foreach ($pricingLabels as $k => $v): ?>
        <option value="<?= $k ?>" <?= $e_row['pricing_type'] === $k ? 'selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Icon (Emoji)</label>
      <input name="icon" value="<?= e($e_row['icon'] ?? '') ?>" maxlength="4" placeholder="⏰" class="w-full px-3 py-2.5 border rounded-xl"/>
    </div>

    <div>
      <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Kundenpreis (netto)</label>
      <div class="relative">
        <input type="number" step="0.01" name="customer_price" value="<?= e($e_row['customer_price']) ?>" required class="w-full px-3 py-2.5 border rounded-xl pr-10"/>
        <span class="absolute right-3 top-2.5 text-gray-400 text-sm">€</span>
      </div>
    </div>

    <div>
      <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Partner-Bonus pro Std</label>
      <div class="relative">
        <input type="number" step="0.01" name="partner_bonus" value="<?= e($e_row['partner_bonus']) ?>" class="w-full px-3 py-2.5 border rounded-xl pr-10"/>
        <span class="absolute right-3 top-2.5 text-gray-400 text-sm">€</span>
      </div>
      <p class="text-[11px] text-gray-400 mt-1">Extra für Partner als Anreiz (z.B. 0,30 € bei Priorität)</p>
    </div>

    <div>
      <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">MwSt %</label>
      <input type="number" step="0.01" name="tax_percentage" value="<?= e($e_row['tax_percentage']) ?>" class="w-full px-3 py-2.5 border rounded-xl"/>
    </div>

    <div>
      <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Sichtbarkeit</label>
      <select name="visibility" class="w-full px-3 py-2.5 border rounded-xl bg-white">
        <?php foreach ($visibilityLabels as $k => $v): ?>
        <option value="<?= $k ?>" <?= $e_row['visibility'] === $k ? 'selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Sortierung</label>
      <input type="number" name="sort_order" value="<?= (int)$e_row['sort_order'] ?>" class="w-full px-3 py-2.5 border rounded-xl"/>
    </div>

    <div class="flex items-center gap-2">
      <label class="inline-flex items-center gap-2 cursor-pointer">
        <input type="checkbox" name="is_active" value="1" <?= $e_row['is_active'] ? 'checked' : '' ?> class="w-4 h-4 accent-brand"/>
        <span class="text-sm text-gray-700">Aktiv</span>
      </label>
    </div>

    <div class="lg:col-span-2 flex gap-3 pt-2">
      <button type="submit" class="px-6 py-2.5 bg-brand hover:bg-brand/90 text-white rounded-xl font-semibold">Speichern</button>
      <a href="/admin/optional-products.php" class="px-6 py-2.5 border border-gray-200 rounded-xl text-gray-700 hover:bg-gray-50 font-semibold">Abbrechen</a>
    </div>
  </form>
</div>
<?php endif; ?>

<!-- List -->
<div class="bg-white rounded-xl border overflow-hidden">
  <div class="px-5 py-3 border-b bg-gray-50 flex items-center justify-between">
    <h3 class="font-semibold text-gray-900"><?= count($products) ?> Produkte</h3>
  </div>

  <?php if (empty($products)): ?>
  <div class="p-10 text-center text-sm text-gray-400">Noch keine optionalen Produkte.</div>
  <?php else: ?>
  <div class="divide-y">
    <?php foreach ($products as $p): ?>
    <div class="p-5 flex items-start gap-4 hover:bg-gray-50 transition <?= !$p['is_active'] ? 'opacity-50' : '' ?>">
      <div class="w-12 h-12 rounded-xl bg-brand/10 flex items-center justify-center text-2xl flex-shrink-0"><?= e($p['icon'] ?: '📦') ?></div>

      <div class="flex-1 min-w-0">
        <div class="flex items-center gap-2 flex-wrap">
          <h4 class="font-semibold text-gray-900"><?= e($p['name']) ?></h4>
          <?php if (!$p['is_active']): ?><span class="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-gray-200 text-gray-600 uppercase">Inaktiv</span><?php endif; ?>
          <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-blue-100 text-blue-700"><?= e($visibilityLabels[$p['visibility']] ?? $p['visibility']) ?></span>
        </div>
        <?php if ($p['description']): ?>
        <p class="text-xs text-gray-500 mt-1"><?= e($p['description']) ?></p>
        <?php endif; ?>
        <div class="flex items-center gap-4 mt-2 text-xs">
          <span class="font-semibold text-gray-900"><?= money($p['customer_price']) ?>
            <span class="text-gray-400 font-normal">
              <?php if ($p['pricing_type'] === 'per_hour'): ?>/ Std<?php elseif ($p['pricing_type'] === 'percentage'): ?>%<?php else: ?>pauschal<?php endif; ?>
            </span>
          </span>
          <?php if ($p['partner_bonus'] > 0): ?>
          <span class="text-gray-500">+ <?= money($p['partner_bonus']) ?> für Partner / Std</span>
          <?php endif; ?>
          <span class="text-gray-400"><?= e($p['tax_percentage']) ?>% MwSt</span>
        </div>
      </div>

      <div class="flex items-center gap-2 flex-shrink-0">
        <a href="/admin/optional-products.php?edit=<?= $p['op_id'] ?>" class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 rounded-lg text-xs font-semibold text-gray-700">Bearbeiten</a>
        <form method="POST" class="inline">
          <?= csrfField() ?>
          <?php if ($p['is_active']): ?>
          <input type="hidden" name="action" value="delete"/>
          <input type="hidden" name="op_id" value="<?= $p['op_id'] ?>"/>
          <button type="submit" onclick="return confirm('Wirklich deaktivieren?')" class="w-8 h-8 rounded-lg hover:bg-red-50 text-gray-400 hover:text-red-600 flex items-center justify-center" title="Deaktivieren">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
          </button>
          <?php else: ?>
          <input type="hidden" name="action" value="reactivate"/>
          <input type="hidden" name="op_id" value="<?= $p['op_id'] ?>"/>
          <button type="submit" class="px-3 py-1.5 bg-green-100 hover:bg-green-200 text-green-700 rounded-lg text-xs font-semibold">Aktivieren</button>
          <?php endif; ?>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
