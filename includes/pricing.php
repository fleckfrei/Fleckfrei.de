<?php
/**
 * Fleckfrei zentrale Preise — Single Source of Truth (matcht fleckfrei.de).
 *
 * Pauschal-Startpreise für 2h (laut Website):
 *   - Home Care:    54,58 €  brutto  (inkl. 19% MwSt) → 45,87 netto
 *   - STR:          73,99 €  netto   → 88,05 brutto
 *   - Business:     64,99 €  netto   → 77,34 brutto
 *
 * Display per Customer-centric-Pricing (ADR):
 *   - Home Care → Brutto (Privatkunde)
 *   - STR / B2B → Netto (Geschäftskunde)
 */

if (!defined('FLECKFREI_VAT_RATE'))           define('FLECKFREI_VAT_RATE', 0.19);
if (!defined('FLECKFREI_BASE_HOURS'))         define('FLECKFREI_BASE_HOURS', 2);
if (!defined('FLECKFREI_MIN_BOOKING_HOURS'))  define('FLECKFREI_MIN_BOOKING_HOURS', 2);

/**
 * Pro-Service Pauschal-Startpreis — LIVE aus settings-Tabelle (Admin-Override).
 * Fallback auf Hardcode wenn DB-Override nicht verfügbar.
 * Cache: static damit 1 Query pro Request.
 */
function fleckfrei_service_base(string $service): array {
    static $cache = null;
    if ($cache === null) {
        try {
            $s = one("SELECT
                website_price_private_override, website_price_str_override, website_price_office_override,
                display_website_private,        display_website_str,        display_website_office
              FROM settings LIMIT 1") ?: [];
        } catch (Exception $e) { $s = []; }
        // Fallbacks (matchen Website-Default-Layout)
        $defaults = [
            'home_care' => ['val' => 54.58, 'mode' => 'brutto'],
            'str'       => ['val' => 73.99, 'mode' => 'brutto'],
            'office'    => ['val' => 64.99, 'mode' => 'brutto'],
        ];
        $cache = [];
        foreach ($defaults as $svc => $def) {
            $k = ($svc === 'home_care') ? 'private' : $svc;
            $rawPrice = $s["website_price_{$k}_override"] ?? null;
            $price = ($rawPrice !== null && $rawPrice !== '') ? (float)$rawPrice : $def['val'];
            $mode  = $s["display_website_$k"] ?? $def['mode'];
            if ($mode === 'brutto') {
                $cache[$svc] = ['gross' => $price, 'net' => $price / (1 + FLECKFREI_VAT_RATE)];
            } else {
                $cache[$svc] = ['net' => $price, 'gross' => $price * (1 + FLECKFREI_VAT_RATE)];
            }
        }
    }
    return $cache[$service] ?? $cache['home_care'];
}

/** Stundensatz pro Service — full precision, keine Pre-Rundung */
function fleckfrei_hourly(string $service): array {
    $b = fleckfrei_service_base($service);
    return [
        'net'   => $b['net']   / FLECKFREI_BASE_HOURS,
        'gross' => $b['gross'] / FLECKFREI_BASE_HOURS,
    ];
}

function fleckfrei_gross(float $net): float { return round($net * (1 + FLECKFREI_VAT_RATE), 2); }

/** Kalkuliert Netto/Brutto für eine Buchung — Rundung erst am Ende */
function fleckfrei_price(string $service, float $hours): array {
    $h = max(FLECKFREI_MIN_BOOKING_HOURS, $hours);
    $hourly = fleckfrei_hourly($service);
    return [
        'hours' => $h,
        'net'   => round($h * $hourly['net'],   2),
        'gross' => round($h * $hourly['gross'], 2),
        'hourly_net'   => round($hourly['net'],   4),
        'hourly_gross' => round($hourly['gross'], 4),
    ];
}

/** Service-Metadaten (Label, Badge, Features) für die Booking-UI */
function fleckfrei_service_meta(string $service): array {
    // Display-Mode aus settings lesen — Admin-Override wirkt live
    static $modes = null;
    if ($modes === null) {
        try {
            $s = one("SELECT display_booking_private, display_booking_str, display_booking_office FROM settings LIMIT 1") ?: [];
        } catch (Exception $e) { $s = []; }
        $modes = [
            'home_care' => in_array($s['display_booking_private'] ?? '', ['brutto','netto'], true) ? $s['display_booking_private'] : 'brutto',
            'str'       => in_array($s['display_booking_str']     ?? '', ['brutto','netto'], true) ? $s['display_booking_str']     : 'brutto',
            'office'    => in_array($s['display_booking_office']  ?? '', ['brutto','netto'], true) ? $s['display_booking_office']  : 'brutto',
        ];
    }
    $meta = [
        'home_care' => [
            'num'   => '01',
            'label' => 'Home Care',
            'tag'   => 'Zuhause · Privatkunde',
            'icon'  => '🏠',
            'badge' => '',
            'display_mode' => $modes['home_care'],
            'features' => [
                'Regelmässige Pflege für Ihr Zuhause',
                'Vertrauenswürdige Partner · Live Updates',
                'Foto-Dokumentation nach jedem Einsatz',
                'Wöchentlich bis monatlich',
                'Fester Partner',
                'Zufriedenheitsgarantie',
            ],
        ],
        'str' => [
            'num'   => '02',
            'label' => 'Short-Term Rental',
            'tag'   => 'Airbnb · Booking · Host',
            'icon'  => '🏨',
            'badge' => '⭐ HAUPTANGEBOT',
            'display_mode' => $modes['str'],
            'features' => [
                'Vollautomatische Turnover-Service',
                'iCal Sync mit Buchungsplattformen',
                'Wäsche- und Handtuch-Service',
                'Schnelles Zeitfenster mit Fotos',
                'Produkt-Nachhaltung',
                'Gäste-Check-in ready',
            ],
        ],
        'office' => [
            'num'   => '03',
            'label' => 'Business & Office',
            'tag'   => 'Startups · B2B',
            'icon'  => '🏢',
            'badge' => '',
            'display_mode' => $modes['office'],
            'features' => [
                'Flexibler Service für Startups & Unternehmen',
                'On-Demand oder regelmässig',
                'Flexible Terminplanung',
                'Qualitätskontrolle via Doku',
                'Sofort buchbar',
                'MwSt. voll absetzbar',
            ],
        ],
    ];
    return $meta[$service] ?? $meta['home_care'];
}
