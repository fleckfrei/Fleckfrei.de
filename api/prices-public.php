<?php
/**
 * Public Pricing-API für Website fleckfrei.de
 * GET /api/prices-public.php → JSON mit allen aktiven Preisen
 * No-auth (public endpoint), CORS für fleckfrei.de
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=300'); // 5 Min cache
require_once __DIR__ . '/../includes/config.php';

try {
    // Base rates per customer-type
    $configs = all("SELECT customer_type, base_hourly_netto, min_billable_hours, tax_percentage FROM pricing_config WHERE is_active=1");
    $base = [];
    foreach ($configs as $c) $base[$c['customer_type']] = $c;

    // Pricing-Tiers (Apartment-Größe → Stunden)
    $tiers = all("SELECT customer_type, max_sqm, partner_hours_min, partner_hours_max, billed_hours_min, billed_hours_max, notes FROM pricing_tiers WHERE is_active=1 ORDER BY customer_type, sort_order");

    // Add-ons (visible to all)
    $addons = all("SELECT op_id, name, description, pricing_type, customer_price, partner_bonus, tax_percentage, visibility, icon, sort_order
                   FROM optional_products
                   WHERE is_active=1 AND visibility != 'hidden'
                   ORDER BY sort_order, name");

    // Frequency discounts
    $st = one("SELECT discount_active, discount_weekly, discount_biweekly, discount_monthly FROM settings LIMIT 1") ?: [];
    $freqDisc = !empty($st['discount_active']) ? [
        'woechentlich' => (float)($st['discount_weekly'] ?? 7) / 100,
        '2wochen' => (float)($st['discount_biweekly'] ?? 5) / 100,
        'monatlich' => (float)($st['discount_monthly'] ?? 3) / 100,
        'einmalig' => 0,
    ] : ['einmalig'=>0,'monatlich'=>0,'2wochen'=>0,'woechentlich'=>0];

    // Competitive pricing
    $compAvg = (float)val("SELECT AVG(hourly_price) FROM market_competitors WHERE city='Berlin' AND competitor NOT LIKE '%Mindestlohn%'") ?: 18.50;
    $st2 = one("SELECT competitive_mode, competitive_premium_pct, new_customer_discount_pct FROM settings LIMIT 1") ?: [];
    $compMode = (int)($st2['competitive_mode'] ?? 0);
    $compPremium = (float)($st2['competitive_premium_pct'] ?? 5);
    $newCustDisc = (float)($st2['new_customer_discount_pct'] ?? 0);

    // Calculate min-price per customer-type from tiers
    $minPrices = [];
    foreach ($tiers as $t) {
        $hourly = (float)($base[$t['customer_type']]['base_hourly_netto'] ?? 24.29);
        if ($compMode && $compAvg > 0) {
            $compMax = $compAvg * (1 + $compPremium/100);
            if ($hourly > $compMax) $hourly = $compMax;
        }
        $price = $hourly * (float)$t['billed_hours_min'];
        if ($newCustDisc > 0) $price *= (1 - $newCustDisc/100);
        if (!isset($minPrices[$t['customer_type']]) || $price < $minPrices[$t['customer_type']]) {
            $minPrices[$t['customer_type']] = round($price, 2);
        }
    }

    // Website-Overrides aus settings (Admin kann direkt eingeben)
    $wsOverride = one("SELECT website_price_private_override, website_price_str_override, website_price_office_override, website_title_home_care, website_title_str, website_title_office FROM settings LIMIT 1") ?: [];
    if (!empty($wsOverride["website_price_private_override"])) $minPrices["private"] = (float)$wsOverride["website_price_private_override"];
    if (!empty($wsOverride["website_price_str_override"]))     $minPrices["str"] = (float)$wsOverride["website_price_str_override"];
    if (!empty($wsOverride["website_price_office_override"]))  $minPrices["office"] = (float)$wsOverride["website_price_office_override"];
    $titles = [
        "home_care" => $wsOverride["website_title_home_care"] ?? "Home Care",
        "str"       => $wsOverride["website_title_str"] ?? "Short-Term Rental",
        "office"    => $wsOverride["website_title_office"] ?? "Business & Office",
    ];

    echo json_encode([
        'success' => true,
        'updated_at' => date('c'),
        'base' => $base,
        'tiers' => $tiers,
        'addons' => array_map(fn($a) => [
            'id' => (int)$a['op_id'],
            'name' => $a['name'],
            'description' => $a['description'],
            'pricing_type' => $a['pricing_type'],
            'price' => (float)$a['customer_price'],
            'tax' => (float)$a['tax_percentage'],
            'visibility' => $a['visibility'],
            'icon' => $a['icon'],
        ], $addons),
        'frequency_discounts' => $freqDisc,
        'min_prices' => $minPrices,
        'currency' => 'EUR',
        'competitive' => [
            'mode' => $compMode,
            'competitor_avg' => $compAvg,
            'premium_pct' => $compPremium,
            'new_customer_discount_pct' => $newCustDisc,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    // Website-Overrides aus settings (Admin kann direkt eingeben)
    $wsOverride = one("SELECT website_price_private_override, website_price_str_override, website_price_office_override, website_title_home_care, website_title_str, website_title_office FROM settings LIMIT 1") ?: [];
    if (!empty($wsOverride["website_price_private_override"])) $minPrices["private"] = (float)$wsOverride["website_price_private_override"];
    if (!empty($wsOverride["website_price_str_override"]))     $minPrices["str"] = (float)$wsOverride["website_price_str_override"];
    if (!empty($wsOverride["website_price_office_override"]))  $minPrices["office"] = (float)$wsOverride["website_price_office_override"];
    $titles = [
        "home_care" => $wsOverride["website_title_home_care"] ?? "Home Care",
        "str"       => $wsOverride["website_title_str"] ?? "Short-Term Rental",
        "office"    => $wsOverride["website_title_office"] ?? "Business & Office",
    ];

    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
