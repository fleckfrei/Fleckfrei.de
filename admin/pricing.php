<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Preis-Strategie'; $page = 'pricing';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { header('Location: /admin/pricing.php'); exit; }
    $act = $_POST['action'] ?? '';

    if ($act === 'update_config') {
        $pcId = (int)($_POST['pc_id'] ?? 0);
        q("UPDATE pricing_config SET base_hourly_netto=?, min_billable_hours=?, partner_commission_pct=?, tax_percentage=?, market_price_reference=? WHERE pc_id=?",
          [(float)$_POST['base_hourly_netto'], (float)$_POST['min_billable_hours'], (float)$_POST['partner_commission_pct'], (float)$_POST['tax_percentage'], $_POST['market_price_reference'] !== '' ? (float)$_POST['market_price_reference'] : null, $pcId]);
        audit('update', 'pricing_config', $pcId, 'Preis-Config aktualisiert');
        header('Location: /admin/pricing.php?saved=1'); exit;
    }

    if ($act === 'add_competitor') {
        q("INSERT INTO market_competitors (source, competitor, hourly_price, city) VALUES (?,?,?,?)",
          [$_POST['source'] ?? 'manual', $_POST['competitor'] ?? '', (float)$_POST['hourly_price'], $_POST['city'] ?? 'Berlin']);
        header('Location: /admin/pricing.php?saved=1'); exit;
    }
}

$configs = all("SELECT * FROM pricing_config ORDER BY customer_type");
$competitors = all("SELECT * FROM market_competitors WHERE city='Berlin' ORDER BY hourly_price ASC LIMIT 20");
$cheapest = !empty($competitors) ? min(array_column($competitors, 'hourly_price')) : null;
$avgCompetitor = !empty($competitors) ? round(array_sum(array_column($competitors, 'hourly_price')) / count($competitors), 2) : null;

include __DIR__ . '/../includes/layout.php';
?>

<?php if (!empty($_GET['saved'])): ?>
<div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4">Gespeichert.</div>
<?php endif; ?>

<div class="mb-6">
  <h1 class="text-2xl font-bold text-gray-900">Preis-Strategie</h1>
  <p class="text-sm text-gray-500 mt-1">Pauschal-Pricing, Partner-Provision und Markt-Wettbewerb.</p>
</div>

<!-- Market overview -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
  <div class="bg-white rounded-xl border p-5">
    <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Fleckfrei Basis</div>
    <div class="text-3xl font-bold text-brand"><?= number_format($configs[0]['base_hourly_netto'] ?? 24.29, 2, ',', '.') ?> €</div>
    <div class="text-xs text-gray-500 mt-1">netto / Stunde</div>
  </div>
  <div class="bg-white rounded-xl border p-5">
    <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Günstigster Wettbewerber</div>
    <div class="text-3xl font-bold text-gray-900"><?= $cheapest !== null ? number_format($cheapest, 2, ',', '.') . ' €' : '—' ?></div>
    <div class="text-xs text-gray-500 mt-1"><?= count($competitors) ?> erfasst in Berlin</div>
  </div>
  <div class="bg-white rounded-xl border p-5">
    <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Markt-Ø</div>
    <div class="text-3xl font-bold text-gray-900"><?= $avgCompetitor !== null ? number_format($avgCompetitor, 2, ',', '.') . ' €' : '—' ?></div>
    <div class="text-xs text-gray-500 mt-1">Durchschnittspreis</div>
  </div>
</div>

<!-- Strategy explanation -->
<div class="bg-gradient-to-br from-brand/5 to-transparent border border-brand/20 rounded-xl p-6 mb-6">
  <h2 class="font-bold text-gray-900 mb-2 flex items-center gap-2">
    <span>🎯</span>
    Pauschal-Strategie
  </h2>
  <div class="text-sm text-gray-700 space-y-2">
    <p><strong>Hebel 1:</strong> Stundensatz beginnt bei <?= number_format($configs[0]['base_hourly_netto'] ?? 24.29, 2, ',', '.') ?> € netto. Dem Kunden zeigen wir aber direkt den <strong>Pauschalpreis für 2 Stunden</strong> (<?= number_format(($configs[0]['base_hourly_netto'] ?? 24.29) * 2, 2, ',', '.') ?> € netto).</p>
    <p><strong>Hebel 2:</strong> Der Kunde denkt "Ich habe einen Deal gemacht" weil er einen Fixpreis sieht statt Unsicherheit über die Stunden.</p>
    <p><strong>Hebel 3:</strong> Partner bekommen <strong><?= number_format(100 - ($configs[0]['partner_commission_pct'] ?? 15), 2, ',', '.') ?>%</strong> vom Netto-Satz — Fleckfrei behält <strong><?= number_format($configs[0]['partner_commission_pct'] ?? 15, 2, ',', '.') ?>%</strong> Provision.</p>
    <p class="text-xs text-gray-500 pt-1 border-t mt-3">💡 Das System berechnet automatisch auf Basis der <code>pricing_config</code> Tabelle.</p>
  </div>
</div>

<!-- Configs per customer type -->
<div class="bg-white rounded-xl border mb-6">
  <div class="px-5 py-3 border-b bg-gray-50 flex items-center justify-between">
    <h3 class="font-semibold text-gray-900">Preis-Konfiguration pro Kundentyp</h3>
    <span class="text-xs text-gray-400"><?= count($configs) ?> Konfigurationen</span>
  </div>
  <div class="divide-y">
    <?php foreach ($configs as $c):
      $flatPrice = $c['base_hourly_netto'] * $c['min_billable_hours'];
      $flatBrutto = $flatPrice * (1 + $c['tax_percentage']/100);
      $partnerShare = $c['base_hourly_netto'] * (1 - $c['partner_commission_pct']/100);
      $fleckfreiShare = $c['base_hourly_netto'] * ($c['partner_commission_pct']/100);
      $icon = match($c['customer_type']) { 'private' => '🏠', 'business' => '🏢', 'airbnb' => '🌴', 'host' => '🌴', default => '📋' };
    ?>
    <details class="group">
      <summary class="p-5 cursor-pointer hover:bg-gray-50 flex items-center justify-between">
        <div class="flex items-center gap-3">
          <span class="text-lg"><?= $icon ?></span>
          <div>
            <div class="font-semibold text-gray-900 uppercase text-sm"><?= e($c['customer_type']) ?></div>
            <div class="text-xs text-gray-500">
              <?= number_format($c['base_hourly_netto'], 2, ',', '.') ?> €/h netto ·
              Pauschal ab <?= number_format($flatPrice, 2, ',', '.') ?> € netto (<?= number_format($flatBrutto, 2, ',', '.') ?> € brutto)
            </div>
          </div>
        </div>
        <svg class="w-4 h-4 text-gray-400 group-open:rotate-180 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
      </summary>
      <div class="p-5 border-t bg-gray-50">
        <form method="POST" class="grid grid-cols-2 lg:grid-cols-5 gap-3">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="update_config"/>
          <input type="hidden" name="pc_id" value="<?= (int)$c['pc_id'] ?>"/>
          <div>
            <label class="block text-[10px] font-semibold text-gray-600 uppercase tracking-wider mb-1">Basis €/h netto</label>
            <input type="number" step="0.01" name="base_hourly_netto" value="<?= e($c['base_hourly_netto']) ?>" class="w-full px-3 py-2 border rounded-lg text-sm"/>
          </div>
          <div>
            <label class="block text-[10px] font-semibold text-gray-600 uppercase tracking-wider mb-1">Mindest-Std</label>
            <input type="number" step="0.25" name="min_billable_hours" value="<?= e($c['min_billable_hours']) ?>" class="w-full px-3 py-2 border rounded-lg text-sm"/>
          </div>
          <div>
            <label class="block text-[10px] font-semibold text-gray-600 uppercase tracking-wider mb-1">Provision %</label>
            <input type="number" step="0.01" name="partner_commission_pct" value="<?= e($c['partner_commission_pct']) ?>" class="w-full px-3 py-2 border rounded-lg text-sm"/>
          </div>
          <div>
            <label class="block text-[10px] font-semibold text-gray-600 uppercase tracking-wider mb-1">MwSt %</label>
            <input type="number" step="0.01" name="tax_percentage" value="<?= e($c['tax_percentage']) ?>" class="w-full px-3 py-2 border rounded-lg text-sm"/>
          </div>
          <div>
            <label class="block text-[10px] font-semibold text-gray-600 uppercase tracking-wider mb-1">Markt-Referenz</label>
            <input type="number" step="0.01" name="market_price_reference" value="<?= e($c['market_price_reference'] ?? '') ?>" placeholder="auto" class="w-full px-3 py-2 border rounded-lg text-sm"/>
          </div>
          <div class="col-span-2 lg:col-span-5 flex items-end justify-between gap-3 pt-2">
            <div class="text-xs text-gray-600 space-y-0.5">
              <div>📊 <strong>Kunde sieht:</strong> <?= number_format($flatPrice, 2, ',', '.') ?> € netto / <?= number_format($flatBrutto, 2, ',', '.') ?> € brutto</div>
              <div>👤 <strong>Partner bekommt:</strong> <?= number_format($partnerShare, 2, ',', '.') ?> € / Std (= <?= number_format(100 - $c['partner_commission_pct'], 2, ',', '.') ?>%)</div>
              <div>💰 <strong>Fleckfrei behält:</strong> <?= number_format($fleckfreiShare, 2, ',', '.') ?> € / Std (= <?= number_format($c['partner_commission_pct'], 2, ',', '.') ?>%)</div>
            </div>
            <button type="submit" class="px-4 py-2 bg-brand hover:bg-brand/90 text-white rounded-lg text-sm font-semibold">Speichern</button>
          </div>
        </form>
      </div>
    </details>
    <?php endforeach; ?>
  </div>
</div>

<!-- Competitors -->
<div class="bg-white rounded-xl border" x-data="{ scanning: false, scanResult: null }">
  <div class="px-5 py-3 border-b bg-gray-50 flex items-center justify-between gap-3 flex-wrap">
    <h3 class="font-semibold text-gray-900">Markt-Wettbewerber (Berlin)</h3>
    <div class="flex items-center gap-2">
      <span class="text-xs text-gray-400"><?= count($competitors) ?> erfasst</span>
      <button
        @click="scanning = true; scanResult = null; fetch('/api/market-scraper.php').then(r => r.json()).then(d => { scanResult = d; scanning = false; setTimeout(() => location.reload(), 1500); }).catch(() => { scanning = false; scanResult = { error: 'Fehler beim Scan' }; })"
        :disabled="scanning"
        class="px-3 py-1.5 bg-brand hover:bg-brand/90 text-white rounded-lg text-xs font-semibold flex items-center gap-1.5 disabled:opacity-50">
        <svg x-show="!scanning" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
        <svg x-show="scanning" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
        <span x-text="scanning ? 'Scanne...' : 'Markt scannen'"></span>
      </button>
    </div>
  </div>

  <!-- Scan result display -->
  <div x-show="scanResult" x-cloak class="px-5 py-3 bg-green-50 border-b border-green-200">
    <div x-show="scanResult && scanResult.success" class="text-xs text-green-800">
      ✓ <span x-text="scanResult?.competitors_count"></span> Wettbewerber gescraped · Günstigster: <strong x-text="scanResult?.cheapest_price + ' €'"></strong>
    </div>
    <div x-show="scanResult && scanResult.error" class="text-xs text-red-800" x-text="scanResult?.error"></div>
  </div>

  <form method="POST" class="p-5 border-b bg-gray-50 grid grid-cols-2 lg:grid-cols-4 gap-3">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="add_competitor"/>
    <input name="competitor" required placeholder="Name (z.B. Helpling)" class="px-3 py-2 border rounded-lg text-sm"/>
    <input name="source" placeholder="Quelle (URL)" class="px-3 py-2 border rounded-lg text-sm"/>
    <input type="number" step="0.01" name="hourly_price" required placeholder="€/h" class="px-3 py-2 border rounded-lg text-sm"/>
    <button type="submit" class="px-4 py-2 bg-brand hover:bg-brand/90 text-white rounded-lg text-sm font-semibold">Hinzufügen</button>
  </form>

  <?php if (empty($competitors)): ?>
  <div class="p-10 text-center text-sm text-gray-400">
    Noch keine Wettbewerber erfasst.
    <p class="mt-2 text-[11px] text-gray-400">💡 Market-Scraper (OSINT) kann später automatisch Preise ziehen: Helpling, Book-a-Tiger, Holmes Cleaning, Batmaid.</p>
  </div>
  <?php else: ?>
  <div class="divide-y">
    <?php foreach ($competitors as $i => $comp): ?>
    <div class="px-5 py-3 flex items-center justify-between hover:bg-gray-50">
      <div class="flex items-center gap-3">
        <?php if ($i === 0): ?>
        <span class="text-xs font-bold text-green-600 bg-green-100 px-2 py-0.5 rounded-full uppercase">Günstigster</span>
        <?php endif; ?>
        <div>
          <div class="font-medium text-gray-900 text-sm"><?= e($comp['competitor']) ?></div>
          <div class="text-[11px] text-gray-400"><?= e($comp['source']) ?> · <?= date('d.m.Y', strtotime($comp['scraped_at'])) ?></div>
        </div>
      </div>
      <div class="text-lg font-bold text-gray-900"><?= number_format($comp['hourly_price'], 2, ',', '.') ?> €</div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<div class="mt-6 p-4 bg-amber-50 border border-amber-200 rounded-xl text-xs text-amber-800">
  <strong>Roadmap:</strong> Auto-Scraping der Berlin-Konkurrenten (Helpling, Book-a-Tiger, Holmes, Batmaid) läuft über ein separates OSINT-Modul (n8n Workflow). Preise werden täglich in <code>market_competitors</code> geschrieben. Fleckfrei unterbietet dann automatisch um 0,50 € — sichtbar als "Günstigster Preis in Berlin ✓" im Booking-Flow.
</div>
