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

    // === Pricing-Rules: Multiplikatoren + Partner-Bonus ===
    if ($act === 'save_rule') {
        $prId = (int)($_POST['pr_id'] ?? 0);
        $fields = [
            $_POST['rule_type'] ?? 'base',
            trim($_POST['name'] ?? ''),
            (float)($_POST['multiplier'] ?? 1),
            (float)($_POST['partner_bonus'] ?? 0),
            $_POST['bonus_type'] ?? 'flat',
            !empty($_POST['admin_override']) ? 1 : 0,
            trim($_POST['notes'] ?? ''),
            !empty($_POST['active']) ? 1 : 0,
        ];
        if ($prId) {
            $fields[] = $prId;
            q("UPDATE pricing_rules SET rule_type=?, name=?, multiplier=?, partner_bonus=?, bonus_type=?, admin_override=?, notes=?, active=? WHERE pr_id=?", $fields);
            audit('update', 'pricing_rules', $prId, 'Rule: ' . $fields[1]);
        } else {
            q("INSERT INTO pricing_rules (rule_type, name, multiplier, partner_bonus, bonus_type, admin_override, notes, active) VALUES (?,?,?,?,?,?,?,?)", $fields);
            audit('create', 'pricing_rules', 0, 'New rule: ' . $fields[1]);
        }
        header('Location: /admin/pricing.php?saved=1#rules'); exit;
    }
    if ($act === 'toggle_rule') {
        $prId = (int)($_POST['pr_id'] ?? 0);
        q("UPDATE pricing_rules SET active=1-active WHERE pr_id=?", [$prId]);
        header('Location: /admin/pricing.php?saved=1#rules'); exit;
    }
    if ($act === 'delete_rule') {
        q("DELETE FROM pricing_rules WHERE pr_id=?", [(int)($_POST['pr_id'] ?? 0)]);
        header('Location: /admin/pricing.php?saved=1#rules'); exit;
    }
}

// Load pricing rules für Anzeige
$pricingRules = all("SELECT * FROM pricing_rules ORDER BY active DESC, rule_type, pr_id");

$configs = all("SELECT * FROM pricing_config ORDER BY customer_type");
$competitors = all("SELECT * FROM market_competitors WHERE city='Berlin' ORDER BY hourly_price ASC LIMIT 30");
$cheapest = !empty($competitors) ? min(array_column($competitors, 'hourly_price')) : null;
$mostExpensive = !empty($competitors) ? max(array_column($competitors, 'hourly_price')) : null;
$avgCompetitor = !empty($competitors) ? round(array_sum(array_column($competitors, 'hourly_price')) / count($competitors), 2) : null;

// Segment analysis
$platformPrices = array_column(array_filter($competitors, fn($c) => str_contains($c['source'], 'platform')), 'hourly_price');
$companyPrices = array_column(array_filter($competitors, fn($c) => str_contains($c['source'], 'company')), 'hourly_price');
$airbnbPrices = array_column(array_filter($competitors, fn($c) => str_contains($c['source'], 'airbnb')), 'hourly_price');
$avgPlatform = !empty($platformPrices) ? round(array_sum($platformPrices) / count($platformPrices), 2) : null;
$avgCompany = !empty($companyPrices) ? round(array_sum($companyPrices) / count($companyPrices), 2) : null;
$avgAirbnb = !empty($airbnbPrices) ? round(array_sum($airbnbPrices) / count($airbnbPrices), 2) : null;
$fleckfreiRate = (float)($configs[0]['base_hourly_netto'] ?? 24.29);

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
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
  <div class="bg-white rounded-xl border p-5 ring-2 ring-brand/20">
    <div class="text-xs font-semibold text-brand uppercase tracking-wider mb-1">Fleckfrei</div>
    <div class="text-3xl font-bold text-brand"><?= number_format($fleckfreiRate, 2, ',', '.') ?> €</div>
    <div class="text-xs text-gray-500 mt-1">netto / Stunde</div>
    <div class="text-xs font-semibold mt-2 <?= $fleckfreiRate <= $avgCompetitor ? 'text-green-600' : 'text-red-600' ?>">
      <?= $avgCompetitor ? ($fleckfreiRate <= $avgCompetitor ? '✓ unter Markt-Ø' : '⚠ über Markt-Ø') : '' ?>
    </div>
  </div>
  <div class="bg-white rounded-xl border p-5">
    <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Günstigster</div>
    <div class="text-3xl font-bold text-gray-900"><?= $cheapest !== null ? number_format($cheapest, 2, ',', '.') . ' €' : '—' ?></div>
    <div class="text-xs text-gray-500 mt-1"><?= count($competitors) ?> erfasst</div>
  </div>
  <div class="bg-white rounded-xl border p-5">
    <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Markt-Ø</div>
    <div class="text-3xl font-bold text-gray-900"><?= $avgCompetitor !== null ? number_format($avgCompetitor, 2, ',', '.') . ' €' : '—' ?></div>
    <div class="text-xs text-gray-500 mt-1">Durchschnitt Berlin</div>
  </div>
  <div class="bg-white rounded-xl border p-5">
    <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Teuerster</div>
    <div class="text-3xl font-bold text-gray-900"><?= $mostExpensive !== null ? number_format($mostExpensive, 2, ',', '.') . ' €' : '—' ?></div>
    <div class="text-xs text-gray-500 mt-1">Professionell</div>
  </div>
</div>

<!-- Segment breakdown -->
<?php if ($avgPlatform || $avgCompany || $avgAirbnb): ?>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
  <?php if ($avgPlatform): ?>
  <div class="bg-blue-50 rounded-xl border border-blue-200 p-4">
    <div class="text-[10px] font-bold text-blue-600 uppercase tracking-wider mb-1">Plattformen (Helpling, Wecasa...)</div>
    <div class="text-xl font-bold text-blue-900"><?= number_format($avgPlatform, 2, ',', '.') ?> €/h Ø</div>
    <div class="text-xs text-blue-700 mt-1"><?= count($platformPrices) ?> Anbieter · Spanne <?= number_format(min($platformPrices), 2, ',', '.') ?>–<?= number_format(max($platformPrices), 2, ',', '.') ?> €</div>
    <div class="mt-2 text-xs font-semibold <?= $fleckfreiRate > $avgPlatform ? 'text-amber-600' : 'text-green-600' ?>">
      Fleckfrei: <?= $fleckfreiRate > $avgPlatform ? '+' . number_format($fleckfreiRate - $avgPlatform, 2, ',', '.') . ' € drüber' : number_format($avgPlatform - $fleckfreiRate, 2, ',', '.') . ' € drunter' ?>
    </div>
  </div>
  <?php endif; ?>
  <?php if ($avgCompany): ?>
  <div class="bg-purple-50 rounded-xl border border-purple-200 p-4">
    <div class="text-[10px] font-bold text-purple-600 uppercase tracking-wider mb-1">Reinigungsfirmen</div>
    <div class="text-xl font-bold text-purple-900"><?= number_format($avgCompany, 2, ',', '.') ?> €/h Ø</div>
    <div class="text-xs text-purple-700 mt-1"><?= count($companyPrices) ?> Anbieter · Spanne <?= number_format(min($companyPrices), 2, ',', '.') ?>–<?= number_format(max($companyPrices), 2, ',', '.') ?> €</div>
    <div class="mt-2 text-xs font-semibold <?= $fleckfreiRate > $avgCompany ? 'text-amber-600' : 'text-green-600' ?>">
      Fleckfrei: <?= $fleckfreiRate > $avgCompany ? '+' . number_format($fleckfreiRate - $avgCompany, 2, ',', '.') . ' € drüber' : number_format($avgCompany - $fleckfreiRate, 2, ',', '.') . ' € drunter' ?>
    </div>
  </div>
  <?php endif; ?>
  <?php if ($avgAirbnb): ?>
  <div class="bg-red-50 rounded-xl border border-red-200 p-4">
    <div class="text-[10px] font-bold text-red-600 uppercase tracking-wider mb-1">Airbnb Turnover</div>
    <div class="text-xl font-bold text-red-900"><?= number_format($avgAirbnb, 2, ',', '.') ?> €/h Ø</div>
    <div class="text-xs text-red-700 mt-1"><?= count($airbnbPrices) ?> Anbieter · Spanne <?= number_format(min($airbnbPrices), 2, ',', '.') ?>–<?= number_format(max($airbnbPrices), 2, ',', '.') ?> €</div>
    <div class="mt-2 text-xs font-semibold <?= $fleckfreiRate > $avgAirbnb ? 'text-amber-600' : 'text-green-600' ?>">
      Fleckfrei: <?= $fleckfreiRate > $avgAirbnb ? '+' . number_format($fleckfreiRate - $avgAirbnb, 2, ',', '.') . ' € drüber' : number_format($avgAirbnb - $fleckfreiRate, 2, ',', '.') . ' € drunter' ?>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

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

<div class="mt-6 p-4 bg-brand-light border border-brand/20 rounded-xl text-xs text-gray-700">
  <strong>Stand April 2026:</strong> 15 Wettbewerber in Berlin erfasst (<?= count($competitors) ?> aktiv). Markt-Ø <?= number_format($avgCompetitor, 2, ',', '.') ?> €/h.
  Fleckfrei positioniert sich bei <?= number_format($fleckfreiRate, 2, ',', '.') ?> €/h im oberen Segment (Qualität + Pauschal-Transparenz).
  <br><br>
  <strong>Nächste Schritte:</strong> Cron-Job für tägliches Auto-Scraping via <code>/api/market-scraper.php?cron=flk_scrape_2026</code> in Hostinger hPanel einrichten. Proximity-Discount wenn Partner-GPS in 2km-Radius.
</div>

<!-- ========== PRICING RULES + PARTNER BONUS ========== -->
<div id="rules" class="mt-10 bg-white rounded-xl border overflow-hidden">
  <div class="p-5 border-b flex items-center justify-between">
    <div>
      <h2 class="text-lg font-bold text-gray-900">Preis-Regeln & Partner-Bonus</h2>
      <p class="text-xs text-gray-600 mt-0.5">Multiplikatoren (Wochenende, Saison, Auslastung) + pro Regel konfigurierbarer Partner-Bonus.</p>
    </div>
    <button type="button" onclick="document.getElementById('ruleForm').reset(); document.querySelector('#ruleForm [name=pr_id]').value=''; document.getElementById('ruleFormTitle').textContent='Neue Regel'; document.getElementById('ruleModal').classList.remove('hidden')" class="px-4 py-2 bg-brand text-white rounded-xl text-sm font-semibold">+ Neue Regel</button>
  </div>
  <table class="w-full text-sm">
    <thead class="bg-gray-50 border-b">
      <tr>
        <th class="text-left px-4 py-3 font-medium">Typ</th>
        <th class="text-left px-4 py-3 font-medium">Name</th>
        <th class="text-right px-4 py-3 font-medium">Multiplikator</th>
        <th class="text-right px-4 py-3 font-medium">Partner-Bonus</th>
        <th class="text-center px-4 py-3 font-medium">Admin-Override</th>
        <th class="text-center px-4 py-3 font-medium">Aktiv</th>
        <th class="text-right px-4 py-3 font-medium">Aktionen</th>
      </tr>
    </thead>
    <tbody class="divide-y">
      <?php foreach ($pricingRules as $r): ?>
      <tr class="<?= $r['active'] ? '' : 'opacity-50' ?>">
        <td class="px-4 py-3"><span class="px-2 py-0.5 bg-gray-100 rounded-full text-xs font-medium"><?= e($r['rule_type']) ?></span></td>
        <td class="px-4 py-3"><?= e($r['name']) ?><?php if ($r['notes']): ?><div class="text-[11px] text-gray-500 mt-0.5"><?= e($r['notes']) ?></div><?php endif; ?></td>
        <td class="px-4 py-3 text-right font-mono">
          <?php $mult = (float)$r['multiplier']; $pct = round(($mult - 1) * 100); ?>
          <span class="<?= $pct > 0 ? 'text-red-700' : ($pct < 0 ? 'text-green-700' : 'text-gray-700') ?>">
            × <?= number_format($mult, 3) ?> (<?= $pct >= 0 ? '+' : '' ?><?= $pct ?>%)
          </span>
        </td>
        <td class="px-4 py-3 text-right font-mono">
          <?php if ($r['partner_bonus'] > 0): ?>
            +<?= number_format($r['partner_bonus'], 2, ',', '.') ?>
            <?= $r['bonus_type'] === 'percent' ? '%' : '€' ?>
          <?php else: ?>
            <span class="text-gray-400">—</span>
          <?php endif; ?>
        </td>
        <td class="px-4 py-3 text-center"><?= $r['admin_override'] ? '🔒' : '' ?></td>
        <td class="px-4 py-3 text-center">
          <form method="POST" class="inline">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="toggle_rule"/>
            <input type="hidden" name="pr_id" value="<?= $r['pr_id'] ?>"/>
            <button type="submit" class="<?= $r['active'] ? 'text-green-600' : 'text-gray-400' ?>">
              <?= $r['active'] ? '●' : '○' ?>
            </button>
          </form>
        </td>
        <td class="px-4 py-3 text-right">
          <button type="button" onclick='editRule(<?= json_encode($r, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)' class="text-brand hover:underline text-xs">Bearbeiten</button>
          <form method="POST" class="inline ml-2" onsubmit="return confirm('Regel wirklich löschen?')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete_rule"/>
            <input type="hidden" name="pr_id" value="<?= $r['pr_id'] ?>"/>
            <button type="submit" class="text-red-600 hover:underline text-xs">Löschen</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Rule Edit Modal -->
<div id="ruleModal" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4" onclick="if(event.target===this) this.classList.add('hidden')">
  <div class="bg-white rounded-xl p-5 w-full max-w-lg shadow-2xl">
    <h3 class="font-bold text-gray-900 mb-4" id="ruleFormTitle">Regel bearbeiten</h3>
    <form id="ruleForm" method="POST" class="space-y-3">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save_rule"/>
      <input type="hidden" name="pr_id"/>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-xs font-semibold text-gray-700 mb-1 uppercase">Typ</label>
          <select name="rule_type" class="w-full px-3 py-2 border rounded-lg text-sm">
            <option value="base">Basis</option>
            <option value="weekend">Wochenende</option>
            <option value="season">Saison</option>
            <option value="demand">Auslastung</option>
            <option value="special">Spezial</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-700 mb-1 uppercase">Multiplikator</label>
          <input type="number" name="multiplier" step="0.001" value="1.000" class="w-full px-3 py-2 border rounded-lg text-sm font-mono" required/>
        </div>
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-700 mb-1 uppercase">Name</label>
        <input type="text" name="name" placeholder="z.B. Wochenende +15%" class="w-full px-3 py-2 border rounded-lg text-sm" required/>
      </div>
      <div class="grid grid-cols-3 gap-3">
        <div>
          <label class="block text-xs font-semibold text-gray-700 mb-1 uppercase">Partner-Bonus</label>
          <input type="number" name="partner_bonus" step="0.01" value="0" class="w-full px-3 py-2 border rounded-lg text-sm font-mono"/>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-700 mb-1 uppercase">Bonus-Typ</label>
          <select name="bonus_type" class="w-full px-3 py-2 border rounded-lg text-sm">
            <option value="flat">€ Pauschal</option>
            <option value="percent">% vom Netto</option>
          </select>
        </div>
        <div class="flex items-end">
          <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="admin_override" value="1" class="w-4 h-4 rounded text-brand"/>
            <span>🔒 Override (fix)</span>
          </label>
        </div>
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-700 mb-1 uppercase">Notiz (intern)</label>
        <input type="text" name="notes" placeholder="Hinweise zur Regel" class="w-full px-3 py-2 border rounded-lg text-sm"/>
      </div>
      <label class="flex items-center gap-2 text-sm">
        <input type="checkbox" name="active" value="1" checked class="w-4 h-4 rounded text-brand"/>
        <span>Aktiv</span>
      </label>
      <div class="flex gap-2 pt-2">
        <button type="button" onclick="document.getElementById('ruleModal').classList.add('hidden')" class="flex-1 px-4 py-2 border rounded-lg text-sm">Abbrechen</button>
        <button type="submit" class="flex-1 px-4 py-2 bg-brand text-white rounded-lg text-sm font-semibold">Speichern</button>
      </div>
    </form>
  </div>
</div>
<script>
function editRule(r) {
  const f = document.getElementById('ruleForm');
  f.pr_id.value = r.pr_id;
  f.rule_type.value = r.rule_type;
  f.name.value = r.name;
  f.multiplier.value = r.multiplier;
  f.partner_bonus.value = r.partner_bonus;
  f.bonus_type.value = r.bonus_type;
  f.admin_override.checked = !!parseInt(r.admin_override);
  f.notes.value = r.notes || '';
  f.active.checked = !!parseInt(r.active);
  document.getElementById('ruleFormTitle').textContent = 'Regel #' + r.pr_id + ' bearbeiten';
  document.getElementById('ruleModal').classList.remove('hidden');
}
</script>
