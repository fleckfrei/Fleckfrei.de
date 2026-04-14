<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Preis-Strategie'; $page = 'pricing';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { header('Location: /admin/pricing.php'); exit; }
    $act = $_POST['action'] ?? '';

    if ($act === 'update_config') {
        $pcId = (int)($_POST['pc_id'] ?? 0);
        $minP = ($_POST['price_min_netto'] ?? '') !== '' ? (float)$_POST['price_min_netto'] : null;
        $maxP = ($_POST['price_max_netto'] ?? '') !== '' ? (float)$_POST['price_max_netto'] : null;
        q("UPDATE pricing_config SET base_hourly_netto=?, min_billable_hours=?, partner_commission_pct=?, tax_percentage=?, market_price_reference=?, price_min_netto=?, price_max_netto=? WHERE pc_id=?",
          [(float)$_POST['base_hourly_netto'], (float)$_POST['min_billable_hours'], (float)$_POST['partner_commission_pct'], (float)$_POST['tax_percentage'], $_POST['market_price_reference'] !== '' ? (float)$_POST['market_price_reference'] : null, $minP, $maxP, $pcId]);
        audit('update', 'pricing_config', $pcId, 'Preis-Config aktualisiert (inkl. min/max range)');
        header('Location: /admin/pricing.php?saved=1'); exit;
    }

    if ($act === 'add_competitor') {
        q("INSERT INTO market_competitors (source, competitor, hourly_price, city) VALUES (?,?,?,?)",
          [$_POST['source'] ?? 'manual', $_POST['competitor'] ?? '', (float)$_POST['hourly_price'], $_POST['city'] ?? 'Berlin']);
        header('Location: /admin/pricing.php?saved=1'); exit;
    }

    // === Pricing-Rules: Multiplikatoren + Partner-Bonus ===
    if ($act === 'save_addon') {
        $opId = (int)($_POST['op_id'] ?? 0);
        $data = [
            trim($_POST['name'] ?? ''),
            trim($_POST['description'] ?? ''),
            $_POST['pricing_type'] ?? 'flat',
            (float)($_POST['customer_price'] ?? 0),
            (float)($_POST['partner_bonus'] ?? 0),
            (float)($_POST['tax_percentage'] ?? 19),
            !empty($_POST['is_active']) ? 1 : 0,
            $_POST['visibility'] ?? 'all',
            trim($_POST['icon'] ?? ''),
            (int)($_POST['sort_order'] ?? 0),
        ];
        if ($opId > 0) {
            $data[] = $opId;
            q("UPDATE optional_products SET name=?, description=?, pricing_type=?, customer_price=?, partner_bonus=?, tax_percentage=?, is_active=?, visibility=?, icon=?, sort_order=? WHERE op_id=?", $data);
        } else {
            q("INSERT INTO optional_products (name, description, pricing_type, customer_price, partner_bonus, tax_percentage, is_active, visibility, icon, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?)", $data);
        }
        audit('update', 'optional_products', $opId ?: (int)lastInsertId(), 'Add-On gespeichert: ' . $_POST['name']);
        header('Location: /admin/pricing.php?saved=1#addons'); exit;
    }
    if ($act === 'delete_addon') {
        $opId = (int)($_POST['op_id'] ?? 0);
        q("UPDATE optional_products SET is_active=0 WHERE op_id=?", [$opId]);
        audit('delete', 'optional_products', $opId, 'Add-On deaktiviert');
        header('Location: /admin/pricing.php?saved=1#addons'); exit;
    }
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

    if ($act === 'save_tier') {
        $ptId = (int)($_POST['pt_id'] ?? 0);
        $fields = [
            $_POST['customer_type'] ?? 'private',
            (int)($_POST['max_sqm'] ?? 0),
            (float)($_POST['partner_hours_min'] ?? 0),
            (float)($_POST['partner_hours_max'] ?? 0),
            (float)($_POST['billed_hours_min'] ?? 0),
            (float)($_POST['billed_hours_max'] ?? 0),
            trim($_POST['notes'] ?? ''),
            (int)($_POST['sort_order'] ?? 0),
            !empty($_POST['is_active']) ? 1 : 0,
        ];
        if ($ptId > 0) {
            $fields[] = $ptId;
            q("UPDATE pricing_tiers SET customer_type=?, max_sqm=?, partner_hours_min=?, partner_hours_max=?, billed_hours_min=?, billed_hours_max=?, notes=?, sort_order=?, is_active=? WHERE pt_id=?", $fields);
            audit('update', 'pricing_tiers', $ptId, 'Tier geändert');
        } else {
            q("INSERT INTO pricing_tiers (customer_type, max_sqm, partner_hours_min, partner_hours_max, billed_hours_min, billed_hours_max, notes, sort_order, is_active) VALUES (?,?,?,?,?,?,?,?,?)", $fields);
            audit('create', 'pricing_tiers', 0, 'Neuer Tier');
        }
        header('Location: /admin/pricing.php?saved=1#tiers'); exit;
    }
    if ($act === 'delete_tier') {
        q("DELETE FROM pricing_tiers WHERE pt_id=?", [(int)($_POST['pt_id'] ?? 0)]);
        header('Location: /admin/pricing.php?saved=1#tiers'); exit;
    }
    if ($act === 'save_website_prices') {
        // Hours per card + display modes (website / booking / invoice) per customer type
        $hrs = fn($v) => max(1, min(12, (int)$v));
        $mode = fn($v) => in_array($v, ['brutto','netto'], true) ? $v : 'netto';
        try {
            q("UPDATE settings SET
                website_hours_home_care=?, website_hours_str=?, website_hours_office=?,
                display_website_private=?, display_website_str=?, display_website_office=?,
                display_booking_private=?, display_booking_str=?, display_booking_office=?,
                display_invoice_private=?, display_invoice_str=?, display_invoice_office=?", [
                $hrs($_POST['website_hours_home_care'] ?? 2),
                $hrs($_POST['website_hours_str'] ?? 2),
                $hrs($_POST['website_hours_office'] ?? 2),
                $mode($_POST['display_website_private'] ?? 'brutto'),
                $mode($_POST['display_website_str'] ?? 'netto'),
                $mode($_POST['display_website_office'] ?? 'netto'),
                $mode($_POST['display_booking_private'] ?? 'brutto'),
                $mode($_POST['display_booking_str'] ?? 'netto'),
                $mode($_POST['display_booking_office'] ?? 'netto'),
                $mode($_POST['display_invoice_private'] ?? 'netto'),
                $mode($_POST['display_invoice_str'] ?? 'netto'),
                $mode($_POST['display_invoice_office'] ?? 'netto'),
            ]);
        } catch (Exception $e) {}

        // Normalize decimal — accept "12,34" (DE) and "12.34" (EN)
        $parsePrice = fn($v) => (string)$v === '' ? null : (float) str_replace([' ', ','], ['', '.'], trim((string)$v));
        q("UPDATE settings SET website_price_private_override=?, website_price_str_override=?, website_price_office_override=?, website_title_home_care=?, website_title_str=?, website_title_office=?",
          [
            $parsePrice($_POST['website_price_private_override'] ?? ''),
            $parsePrice($_POST['website_price_str_override'] ?? ''),
            $parsePrice($_POST['website_price_office_override'] ?? ''),
            trim($_POST['website_title_home_care'] ?? 'Home Care'),
            trim($_POST['website_title_str'] ?? 'Short-Term Rental'),
            trim($_POST['website_title_office'] ?? 'Business & Office'),
          ]);
        audit('update', 'settings', 0, 'Website-Preise & Titel geändert');
        header('Location: /admin/pricing.php?saved=1#website'); exit;
    }
    if ($act === 'save_competitive') {
        q("UPDATE settings SET competitive_mode=?, competitive_premium_pct=?, new_customer_discount_pct=?, discount_active=?, discount_weekly=?, discount_biweekly=?, discount_monthly=?",
          [!empty($_POST['competitive_mode']) ? 1 : 0,
           (float)($_POST['competitive_premium_pct'] ?? 5),
           (float)($_POST['new_customer_discount_pct'] ?? 10),
           !empty($_POST['discount_active']) ? 1 : 0,
           (float)($_POST['discount_weekly'] ?? 7),
           (float)($_POST['discount_biweekly'] ?? 5),
           (float)($_POST['discount_monthly'] ?? 3)]);
        audit('update', 'settings', 0, 'Competitive/Frequenz-Pricing geändert');
        header('Location: /admin/pricing.php?saved=1#competitive'); exit;
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

$tiers = all("SELECT * FROM pricing_tiers ORDER BY customer_type, sort_order, max_sqm");
$settings = one("SELECT competitive_mode, competitive_premium_pct, new_customer_discount_pct, discount_active, discount_weekly, discount_biweekly, discount_monthly,
    website_price_private_override, website_price_str_override, website_price_office_override,
    website_title_home_care, website_title_str, website_title_office,
    website_hours_home_care, website_hours_str, website_hours_office,
    display_website_private, display_website_str, display_website_office,
    display_booking_private, display_booking_str, display_booking_office,
    display_invoice_private, display_invoice_str, display_invoice_office
    FROM settings LIMIT 1") ?: [];

include __DIR__ . '/../includes/layout.php';
?>

<?php if (!empty($_GET['saved'])): ?>
<div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4">Gespeichert.</div>
<?php endif; ?>

<!-- Quick-Nav Banner -->
<div class="bg-gradient-to-r from-brand to-brand-dark text-white rounded-xl p-5 mb-6">
  <div class="flex items-center justify-between flex-wrap gap-3">
    <div>
      <h2 class="font-bold text-xl mb-1">💰 Pricing — alle Hebel an einem Ort</h2>
      <p class="text-sm text-white/80">Sprung zu den 4 Sektionen — scroll runter für Add-Ons, Tiers, Rules.</p>
    </div>
    <div class="flex gap-2 flex-wrap">
      <a href="#website" class="px-4 py-2 bg-white text-brand rounded-lg font-semibold text-sm hover:bg-white/90">🌐 Website ab-Preise</a>
      <a href="#competitive" class="px-4 py-2 bg-white/20 border border-white text-white rounded-lg text-sm hover:bg-white/30">⚔️ Competitive</a>
      <a href="#tiers" class="px-4 py-2 bg-white/20 border border-white text-white rounded-lg text-sm hover:bg-white/30">📐 Tiers</a>
      <a href="#addons" class="px-4 py-2 bg-white/20 border border-white text-white rounded-lg text-sm hover:bg-white/30">🧴 Add-Ons</a>
    </div>
  </div>
</div>

<div class="mb-6">
  <h1 class="text-2xl font-bold text-gray-900">Preis-Strategie</h1>
  <p class="text-sm text-gray-500 mt-1">Pauschal-Pricing, Partner-Provision und Markt-Wettbewerb.</p>
</div>

<!-- Market overview -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
  <?php
    // Primary Fleckfrei-config: prefer 'all', fallback to first entry
    $ffCfg = null;
    foreach ($configs as $cc) if ($cc['customer_type'] === 'all') { $ffCfg = $cc; break; }
    if (!$ffCfg) $ffCfg = $configs[0] ?? null;
  ?>
  <div class="bg-white rounded-xl border p-5 ring-2 ring-brand/20" x-data="{editing:false}">
    <div class="flex items-center justify-between mb-1">
      <div class="text-xs font-semibold text-brand uppercase tracking-wider">Fleckfrei</div>
      <button type="button" @click="editing=!editing" class="text-xs text-gray-400 hover:text-brand" x-text="editing ? '✕' : '✏️'"></button>
    </div>
    <div x-show="!editing">
      <div class="text-3xl font-bold text-brand"><?= number_format($fleckfreiRate, 2, ',', '.') ?> €</div>
      <div class="text-xs text-gray-500 mt-1">netto / Stunde · <code class="text-[10px]"><?= e($ffCfg['customer_type'] ?? 'all') ?></code></div>
      <div class="text-xs font-semibold mt-2 <?= $fleckfreiRate <= $avgCompetitor ? 'text-green-600' : 'text-red-600' ?>">
        <?= $avgCompetitor ? ($fleckfreiRate <= $avgCompetitor ? '✓ unter Markt-Ø' : '⚠ über Markt-Ø') : '' ?>
      </div>
    </div>
    <form x-show="editing" method="POST" class="space-y-2 mt-1">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="update_config"/>
      <input type="hidden" name="pc_id" value="<?= (int)($ffCfg['pc_id'] ?? 0) ?>"/>
      <input type="hidden" name="min_billable_hours" value="<?= e($ffCfg['min_billable_hours'] ?? 2) ?>"/>
      <input type="hidden" name="partner_commission_pct" value="<?= e($ffCfg['partner_commission_pct'] ?? 15) ?>"/>
      <input type="hidden" name="tax_percentage" value="<?= e($ffCfg['tax_percentage'] ?? 19) ?>"/>
      <input type="hidden" name="market_price_reference" value="<?= e($ffCfg['market_price_reference'] ?? '') ?>"/>
      <label class="block text-[10px] text-gray-500">Basis-Stundensatz (€ netto)</label>
      <input type="number" step="0.01" name="base_hourly_netto" value="<?= e($ffCfg['base_hourly_netto'] ?? $fleckfreiRate) ?>" autofocus class="w-full px-2 py-1.5 border-2 border-brand rounded text-lg font-bold text-brand"/>
      <div class="grid grid-cols-2 gap-1 mt-1">
        <div>
          <label class="block text-[10px] text-gray-500">Min Ø netto</label>
          <input type="number" step="0.01" name="price_min_netto" value="<?= e($ffCfg['price_min_netto'] ?? '') ?>" placeholder="z.B. 20,00" class="w-full px-2 py-1.5 border rounded text-sm"/>
        </div>
        <div>
          <label class="block text-[10px] text-gray-500">Max Ø netto</label>
          <input type="number" step="0.01" name="price_max_netto" value="<?= e($ffCfg['price_max_netto'] ?? '') ?>" placeholder="z.B. 35,00" class="w-full px-2 py-1.5 border rounded text-sm"/>
        </div>
      </div>
      <div class="flex gap-1 mt-2">
        <button type="submit" class="flex-1 px-2 py-1.5 bg-brand text-white rounded text-xs font-semibold">💾 Speichern</button>
        <button type="button" @click="editing=false" class="px-2 py-1.5 border rounded text-xs">Abbr.</button>
      </div>
    </form>
    <?php if (!empty($ffCfg['price_min_netto']) && !empty($ffCfg['price_max_netto'])): ?>
    <div x-show="!editing" class="mt-2 pt-2 border-t text-[11px] text-gray-500 flex items-center justify-between">
      <span>Dyn. Bereich (Neukunde):</span>
      <span class="font-mono"><?= number_format($ffCfg['price_min_netto'],2,',','.') ?> – <?= number_format($ffCfg['price_max_netto'],2,',','.') ?> €</span>
    </div>
    <?php endif; ?>
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
        <form method="POST" class="grid grid-cols-2 lg:grid-cols-7 gap-3">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="update_config"/>
          <input type="hidden" name="pc_id" value="<?= (int)$c['pc_id'] ?>"/>
          <div>
            <label class="block text-[10px] font-semibold text-gray-600 uppercase tracking-wider mb-1">Basis €/h netto</label>
            <input type="number" step="0.01" name="base_hourly_netto" value="<?= e($c['base_hourly_netto']) ?>" class="w-full px-3 py-2 border rounded-lg text-sm"/>
          </div>
          <div>
            <label class="block text-[10px] font-semibold text-brand uppercase tracking-wider mb-1">Min €/h netto</label>
            <input type="number" step="0.01" name="price_min_netto" value="<?= e($c['price_min_netto'] ?? '') ?>" placeholder="Neukunde-Lowball" class="w-full px-3 py-2 border-2 border-brand/30 rounded-lg text-sm"/>
          </div>
          <div>
            <label class="block text-[10px] font-semibold text-brand uppercase tracking-wider mb-1">Max €/h netto</label>
            <input type="number" step="0.01" name="price_max_netto" value="<?= e($c['price_max_netto'] ?? '') ?>" placeholder="Peak/Premium" class="w-full px-3 py-2 border-2 border-brand/30 rounded-lg text-sm"/>
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
            <label class="block text-[10px] font-semibold text-gray-600 uppercase tracking-wider mb-1">Markt-Ref</label>
            <input type="number" step="0.01" name="market_price_reference" value="<?= e($c['market_price_reference'] ?? '') ?>" placeholder="auto" class="w-full px-3 py-2 border rounded-lg text-sm"/>
          </div>
          <div class="col-span-2 lg:col-span-7 flex items-end justify-between gap-3 pt-2">
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
<!-- ============ Website-Service-Cards (fleckfrei.de) ============ -->
<div id="website" class="bg-white rounded-xl border p-5 mt-6">
  <h3 class="font-semibold mb-2 flex items-center gap-2">🌐 Website Service-Cards (fleckfrei.de)</h3>
  <p class="text-xs text-gray-500 mb-4">Direkt-Edit der 3 Preis-Cards auf <a href="https://fleckfrei.de" target="_blank" class="underline">fleckfrei.de</a>. Lass Preis leer = automatisch berechneter Min-Preis aus Tiers. Zahl eintragen = manueller Override.</p>
  <form method="POST" class="space-y-4">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save_website_prices"/>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <?php
      $cards = [
        ['key'=>'private',  'suffix'=>'home_care', 'icon'=>'🏠', 'label'=>'Home Care · private', 'hilight'=>false],
        ['key'=>'str',      'suffix'=>'str',       'icon'=>'🏨', 'label'=>'Short-Term Rental ★', 'hilight'=>true],
        ['key'=>'office',   'suffix'=>'office',    'icon'=>'🏢', 'label'=>'Business & Office',    'hilight'=>false],
      ];
      foreach ($cards as $i => $c):
        $k = $c['key']; $s = $c['suffix'];
        $priceVal = $settings["website_price_{$k}_override"] ?? null;
        $hoursVal = (int)($settings["website_hours_$s"] ?? 2);
        $dWeb = $settings["display_website_$k"]  ?? ($k==='private' ? 'brutto' : 'netto');
        $dBook= $settings["display_booking_$k"]  ?? ($k==='private' ? 'brutto' : 'netto');
        $dInv = $settings["display_invoice_$k"]  ?? 'netto';
        $cls = $c['hilight'] ? 'border-2 border-brand bg-brand/5' : 'border';
        $num = str_pad($i+1, 2, '0', STR_PAD_LEFT);
      ?>
      <div class="<?= $cls ?> rounded-lg p-3">
        <div class="text-xs <?= $c['hilight']?'text-brand font-semibold':'text-gray-500' ?> mb-2"><?= $num ?> · <?= $c['icon'] ?> <?= e($c['label']) ?></div>

        <label class="block text-xs mb-1">Card-Titel</label>
        <input name="website_title_<?= $s ?>" value="<?= e($settings["website_title_$s"] ?? ucfirst(str_replace('_',' ',$s))) ?>" class="w-full px-2 py-1.5 border rounded mb-2 text-sm"/>

        <div class="grid grid-cols-2 gap-2 mb-2">
          <div>
            <label class="block text-xs mb-1">„ab XX EUR" Preis</label>
            <input type="text" inputmode="decimal" pattern="[0-9.,]*" name="website_price_<?= $k ?>_override" value="<?= $priceVal !== null ? e($priceVal) : '' ?>" placeholder="auto" class="w-full px-2 py-1.5 border rounded font-mono text-sm"/>
          </div>
          <div>
            <label class="block text-xs mb-1">pro … Stunden</label>
            <select name="website_hours_<?= $s ?>" class="w-full px-2 py-1.5 border rounded text-sm">
              <?php foreach ([1,2,3,4,5,6,8] as $h): ?>
                <option value="<?= $h ?>" <?= $h===$hoursVal?'selected':'' ?>><?= $h ?> h</option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <p class="text-[10px] text-gray-400 mb-2">Preis leer = auto. Stunden = Basis für Card-Preis.</p>

        <div class="space-y-1.5 pt-2 border-t text-xs">
          <div class="text-gray-500 font-semibold mb-1">Anzeige als:</div>
          <?php foreach ([['website','🌐 Website',$dWeb],['booking','📋 Booking-Formular',$dBook],['invoice','💶 Rechnung',$dInv]] as [$scope,$lbl,$cur]): ?>
            <label class="flex items-center justify-between gap-2">
              <span class="text-gray-600"><?= e($lbl) ?></span>
              <select name="display_<?= $scope ?>_<?= $k ?>" class="px-2 py-1 border rounded text-xs bg-white">
                <option value="brutto" <?= $cur==='brutto'?'selected':'' ?>>Brutto</option>
                <option value="netto"  <?= $cur==='netto'?'selected':'' ?>>Netto</option>
              </select>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <button class="px-4 py-2 bg-brand text-white rounded-lg font-semibold">Website-Preise speichern</button>
    <p class="text-xs text-gray-500">⚡ Änderungen sind in <strong>~15 Sekunden live</strong> auf <a href="https://fleckfrei.de" target="_blank" class="underline">fleckfrei.de</a> (kein Cache, JS-Auto-Poll). Komma oder Punkt erlaubt (0,02 oder 0.02).</p>
  </form>
</div>

<!-- ============ Competitive + Frequenz Toggles ============ -->
<div id="competitive" class="bg-white rounded-xl border p-5 mt-6">
  <h3 class="font-semibold mb-4 flex items-center gap-2">⚔️ Competitive Pricing & Frequenz-Rabatte</h3>
  <p class="text-xs text-amber-700 mb-3 bg-amber-50 px-3 py-2 rounded">⚠ Wirkt NUR auf neue Kunden/Buchungen. Bestehende bleiben unberührt.</p>
  <form method="POST" class="space-y-4">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save_competitive"/>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
      <label class="flex items-center gap-2 p-3 border rounded-lg">
        <input type="checkbox" name="competitive_mode" value="1" <?= !empty($settings['competitive_mode']) ? 'checked' : '' ?> class="rounded"/>
        <div><div class="text-sm font-medium">Competitive-Cap</div><div class="text-xs text-gray-500">Stundensatz gedeckelt auf Markt-Avg × (1+Premium)</div></div>
      </label>
      <div>
        <label class="block text-xs text-gray-500 mb-1">Competitive Premium %</label>
        <input type="number" step="0.1" name="competitive_premium_pct" value="<?= e((float)($settings['competitive_premium_pct'] ?? 5)) ?>" class="w-full px-3 py-2 border rounded-lg"/>
      </div>
      <div>
        <label class="block text-xs text-gray-500 mb-1">Neukunden-Rabatt %</label>
        <input type="number" step="0.1" name="new_customer_discount_pct" value="<?= e((float)($settings['new_customer_discount_pct'] ?? 10)) ?>" class="w-full px-3 py-2 border rounded-lg"/>
      </div>
      <label class="flex items-center gap-2 p-3 border rounded-lg">
        <input type="checkbox" name="discount_active" value="1" <?= !empty($settings['discount_active']) ? 'checked' : '' ?> class="rounded"/>
        <div><div class="text-sm font-medium">Frequenz-Rabatte an</div></div>
      </label>
    </div>
    <div class="grid grid-cols-3 gap-3 pt-2 border-t">
      <div>
        <label class="block text-xs text-gray-500 mb-1">Wöchentlich %</label>
        <input type="number" step="0.1" name="discount_weekly" value="<?= e((float)($settings['discount_weekly'] ?? 7)) ?>" class="w-full px-3 py-2 border rounded-lg"/>
      </div>
      <div>
        <label class="block text-xs text-gray-500 mb-1">14-täglich %</label>
        <input type="number" step="0.1" name="discount_biweekly" value="<?= e((float)($settings['discount_biweekly'] ?? 5)) ?>" class="w-full px-3 py-2 border rounded-lg"/>
      </div>
      <div>
        <label class="block text-xs text-gray-500 mb-1">Monatlich %</label>
        <input type="number" step="0.1" name="discount_monthly" value="<?= e((float)($settings['discount_monthly'] ?? 3)) ?>" class="w-full px-3 py-2 border rounded-lg"/>
      </div>
    </div>
    <button class="px-4 py-2 bg-brand text-white rounded-lg font-semibold">Speichern</button>
  </form>
</div>

<!-- ============ Pricing-Tiers (Wohnungsgrößen → Stunden) ============ -->
<div id="tiers" class="bg-white rounded-xl border p-5 mt-6">
  <div class="flex items-center justify-between mb-4">
    <h3 class="font-semibold flex items-center gap-2">📐 Pricing-Tiers (qm → Partner-/Billed-Stunden)</h3>
    <button type="button" onclick="editTier({pt_id:0,customer_type:'private',is_active:1,sort_order:0})" class="px-3 py-1.5 bg-brand text-white rounded-lg text-sm">+ Neuer Tier</button>
  </div>
  <p class="text-xs text-gray-500 mb-3">Quelle für Booking-Modal-Berechnung via <code>/api/prices-public.php</code></p>
  <table class="w-full text-sm">
    <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
      <tr>
        <th class="px-3 py-2 text-left">Typ</th>
        <th class="px-3 py-2 text-right">≤ qm</th>
        <th class="px-3 py-2 text-right">Partner h (min–max)</th>
        <th class="px-3 py-2 text-right">Billed h (min–max)</th>
        <th class="px-3 py-2 text-left">Notes</th>
        <th class="px-3 py-2 text-center">Aktiv</th>
        <th class="px-3 py-2 text-right">Aktion</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($tiers as $t): ?>
      <tr class="border-t hover:bg-gray-50">
        <td class="px-3 py-2"><span class="text-xs px-2 py-0.5 bg-gray-100 rounded"><?= e($t['customer_type']) ?></span></td>
        <td class="px-3 py-2 text-right font-semibold"><?= (int)$t['max_sqm'] ?></td>
        <td class="px-3 py-2 text-right text-xs"><?= number_format((float)$t['partner_hours_min'],2,',','.') ?> – <?= number_format((float)$t['partner_hours_max'],2,',','.') ?></td>
        <td class="px-3 py-2 text-right text-xs"><?= number_format((float)$t['billed_hours_min'],2,',','.') ?> – <?= number_format((float)$t['billed_hours_max'],2,',','.') ?></td>
        <td class="px-3 py-2 text-xs text-gray-500"><?= e($t['notes']) ?></td>
        <td class="px-3 py-2 text-center"><?= $t['is_active'] ? '✓' : '✗' ?></td>
        <td class="px-3 py-2 text-right">
          <button onclick='editTier(<?= json_encode($t, JSON_HEX_APOS) ?>)' class="text-brand hover:underline text-xs">Edit</button>
          <form method="POST" class="inline" onsubmit="return confirm('Tier wirklich löschen?')">
            <?= csrfField() ?><input type="hidden" name="action" value="delete_tier"/><input type="hidden" name="pt_id" value="<?= $t['pt_id'] ?>"/>
            <button class="text-red-500 hover:underline text-xs ml-2">Del</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Tier Edit Modal -->
<div id="tierModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4" onclick="if(event.target===this)this.classList.add('hidden')">
  <div class="bg-white rounded-2xl max-w-lg w-full p-6 max-h-[90vh] overflow-y-auto">
    <h3 class="text-lg font-semibold mb-4" id="tierModalTitle">Tier bearbeiten</h3>
    <form method="POST" class="space-y-3" id="tierForm">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save_tier"/>
      <input type="hidden" name="pt_id" id="t_pt_id"/>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-xs text-gray-500 mb-1">Kundentyp *</label>
          <select name="customer_type" id="t_customer_type" class="w-full px-3 py-2 border rounded-lg">
            <option value="private">Private</option><option value="str">STR/Host</option><option value="office">Office</option>
          </select>
        </div>
        <div>
          <label class="block text-xs text-gray-500 mb-1">≤ max qm *</label>
          <input type="number" name="max_sqm" id="t_max_sqm" required class="w-full px-3 py-2 border rounded-lg"/>
        </div>
      </div>
      <div class="grid grid-cols-2 gap-3 pt-2 border-t">
        <div>
          <label class="block text-xs text-gray-500 mb-1">Partner h min</label>
          <input type="number" step="0.25" name="partner_hours_min" id="t_partner_hours_min" class="w-full px-3 py-2 border rounded-lg"/>
        </div>
        <div>
          <label class="block text-xs text-gray-500 mb-1">Partner h max</label>
          <input type="number" step="0.25" name="partner_hours_max" id="t_partner_hours_max" class="w-full px-3 py-2 border rounded-lg"/>
        </div>
        <div>
          <label class="block text-xs text-gray-500 mb-1">Billed h min</label>
          <input type="number" step="0.25" name="billed_hours_min" id="t_billed_hours_min" class="w-full px-3 py-2 border rounded-lg"/>
        </div>
        <div>
          <label class="block text-xs text-gray-500 mb-1">Billed h max</label>
          <input type="number" step="0.25" name="billed_hours_max" id="t_billed_hours_max" class="w-full px-3 py-2 border rounded-lg"/>
        </div>
      </div>
      <div>
        <label class="block text-xs text-gray-500 mb-1">Notes</label>
        <input name="notes" id="t_notes" class="w-full px-3 py-2 border rounded-lg" placeholder="z.B. Studio, 2-Zimmer"/>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-xs text-gray-500 mb-1">Sortierung</label>
          <input type="number" name="sort_order" id="t_sort_order" value="0" class="w-full px-3 py-2 border rounded-lg"/>
        </div>
        <label class="flex items-center gap-2 text-sm pt-5"><input type="checkbox" name="is_active" id="t_is_active" value="1" class="rounded"/> Aktiv</label>
      </div>
      <div class="flex gap-2 pt-3 border-t">
        <button type="submit" class="flex-1 px-4 py-2 bg-brand text-white rounded-lg font-semibold">Speichern</button>
        <button type="button" onclick="document.getElementById('tierModal').classList.add('hidden')" class="px-4 py-2 bg-gray-200 rounded-lg">Abbrechen</button>
      </div>
    </form>
  </div>
</div>
<script>
function editTier(t) {
  const ids = ['pt_id','customer_type','max_sqm','partner_hours_min','partner_hours_max','billed_hours_min','billed_hours_max','notes','sort_order'];
  ids.forEach(k => { const el = document.getElementById('t_'+k); if (el) el.value = t[k] ?? ''; });
  document.getElementById('t_is_active').checked = !!parseInt(t.is_active || 0);
  document.getElementById('tierModalTitle').textContent = t.pt_id ? ('Tier #'+t.pt_id+' bearbeiten') : 'Neuer Tier';
  document.getElementById('tierModal').classList.remove('hidden');
}
</script>

<?php
$addons = all("SELECT * FROM optional_products ORDER BY sort_order, name");
?>
<div id="addons" class="bg-white rounded-xl border p-5 mt-6">
  <div class="flex items-center justify-between mb-4">
    <h3 class="font-semibold flex items-center gap-2">🧴 Add-On Preise (Zusatzleistungen)</h3>
    <button type="button" onclick="editAddon({op_id:0,pricing_type:'flat',is_active:1,visibility:'all',tax_percentage:19,sort_order:0})" class="px-3 py-1.5 bg-brand text-white rounded-lg text-sm">+ Neues Add-On</button>
  </div>
  <p class="text-xs text-amber-700 mb-3 bg-amber-50 px-3 py-2 rounded">⚠ Änderungen wirken NUR auf neue Buchungen. Bestehende Jobs/Services behalten ihre alten Preise.</p>
  <table class="w-full text-sm">
    <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
      <tr><th class="px-3 py-2 text-left">Name</th><th class="px-3 py-2 text-left">Typ</th><th class="px-3 py-2 text-right">Kunde</th><th class="px-3 py-2 text-right">Partner-Bonus</th><th class="px-3 py-2 text-left">Sichtbar für</th><th class="px-3 py-2 text-center">Aktiv</th><th class="px-3 py-2 text-right">Aktion</th></tr>
    </thead>
    <tbody>
      <?php foreach ($addons as $a): ?>
      <tr class="border-t hover:bg-gray-50">
        <td class="px-3 py-2"><?= e($a['icon']) ?> <strong><?= e($a['name']) ?></strong><div class="text-xs text-gray-500"><?= e($a['description']) ?></div></td>
        <td class="px-3 py-2 text-xs"><?= e($a['pricing_type']) ?></td>
        <td class="px-3 py-2 text-right font-semibold"><?= number_format((float)$a['customer_price'], 2, ',', '.') ?> €</td>
        <td class="px-3 py-2 text-right text-gray-600"><?= number_format((float)$a['partner_bonus'], 2, ',', '.') ?> €</td>
        <td class="px-3 py-2 text-xs"><?= e($a['visibility']) ?></td>
        <td class="px-3 py-2 text-center"><?= $a['is_active'] ? '✓' : '✗' ?></td>
        <td class="px-3 py-2 text-right">
          <button onclick='editAddon(<?= json_encode($a, JSON_HEX_APOS) ?>)' class="text-brand hover:underline text-xs">Edit</button>
          <form method="POST" class="inline" onsubmit="return confirm('Add-On deaktivieren?')">
            <?= csrfField() ?><input type="hidden" name="action" value="delete_addon"/><input type="hidden" name="op_id" value="<?= $a['op_id'] ?>"/>
            <button class="text-red-500 hover:underline text-xs ml-2">Del</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Addon Edit Modal -->
<div id="addonModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4" onclick="if(event.target===this)this.classList.add('hidden')">
  <div class="bg-white rounded-2xl max-w-xl w-full p-6 max-h-[90vh] overflow-y-auto">
    <h3 class="text-lg font-semibold mb-4" id="addonModalTitle">Add-On bearbeiten</h3>
    <form method="POST" class="space-y-3" id="addonForm">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save_addon"/>
      <input type="hidden" name="op_id" id="a_op_id"/>
      <div class="grid grid-cols-2 gap-3">
        <div><label class="block text-xs text-gray-500 mb-1">Name *</label><input name="name" id="a_name" required class="w-full px-3 py-2 border rounded-lg"/></div>
        <div><label class="block text-xs text-gray-500 mb-1">Icon (Emoji)</label><input name="icon" id="a_icon" maxlength="4" class="w-full px-3 py-2 border rounded-lg"/></div>
      </div>
      <div><label class="block text-xs text-gray-500 mb-1">Beschreibung</label><textarea name="description" id="a_description" rows="2" class="w-full px-3 py-2 border rounded-lg"></textarea></div>
      <div class="grid grid-cols-3 gap-3">
        <div><label class="block text-xs text-gray-500 mb-1">Pricing-Typ</label>
          <select name="pricing_type" id="a_pricing_type" class="w-full px-3 py-2 border rounded-lg">
            <option value="flat">Pauschal</option><option value="per_hour">Pro Stunde</option><option value="percentage">% von Total</option>
          </select></div>
        <div><label class="block text-xs text-gray-500 mb-1">Kunden-Preis €</label><input type="number" step="0.01" name="customer_price" id="a_customer_price" class="w-full px-3 py-2 border rounded-lg"/></div>
        <div><label class="block text-xs text-gray-500 mb-1">Partner-Bonus €</label><input type="number" step="0.01" name="partner_bonus" id="a_partner_bonus" class="w-full px-3 py-2 border rounded-lg"/></div>
      </div>
      <div class="grid grid-cols-3 gap-3">
        <div><label class="block text-xs text-gray-500 mb-1">Steuer %</label><input type="number" step="0.5" name="tax_percentage" id="a_tax_percentage" value="19" class="w-full px-3 py-2 border rounded-lg"/></div>
        <div><label class="block text-xs text-gray-500 mb-1">Sichtbar für</label>
          <select name="visibility" id="a_visibility" class="w-full px-3 py-2 border rounded-lg">
            <option value="all">Alle</option><option value="private">Private</option><option value="business">Business</option><option value="host">Host/STR</option><option value="hidden">Versteckt</option>
          </select></div>
        <div><label class="block text-xs text-gray-500 mb-1">Sortierung</label><input type="number" name="sort_order" id="a_sort_order" value="0" class="w-full px-3 py-2 border rounded-lg"/></div>
      </div>
      <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" id="a_is_active" value="1" class="rounded"/> Aktiv</label>
      <div class="flex gap-2 pt-3 border-t">
        <button type="submit" class="flex-1 px-4 py-2 bg-brand text-white rounded-lg font-semibold">Speichern</button>
        <button type="button" onclick="document.getElementById('addonModal').classList.add('hidden')" class="px-4 py-2 bg-gray-200 rounded-lg">Abbrechen</button>
      </div>
    </form>
  </div>
</div>
<script>
function editAddon(a) {
  const ids = ['op_id','name','icon','description','pricing_type','customer_price','partner_bonus','tax_percentage','visibility','sort_order'];
  ids.forEach(k => { const el = document.getElementById('a_'+k); if (el) el.value = a[k] ?? ''; });
  document.getElementById('a_is_active').checked = !!parseInt(a.is_active || 0);
  document.getElementById('addonModalTitle').textContent = a.op_id ? ('Add-On #'+a.op_id+' bearbeiten') : 'Neues Add-On';
  document.getElementById('addonModal').classList.remove('hidden');
}
</script>
