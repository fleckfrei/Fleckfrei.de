<?php
// One-shot: auto-fill customer.district from customer_address.postal_code.
// Usage: php scripts/auto-district-from-plz.php
// Idempotent — only updates customers where district IS NULL or ''.

require_once __DIR__ . '/../includes/config.php';

// PLZ → district mapping (Berlin 12 Bezirke + Umland)
// Based on official Berlin postleitzahl → Bezirk assignments.
function plzToDistrict(string $plz): ?string {
    $p = (int) preg_replace('/\D/', '', $plz);
    if ($p === 0) return null;

    // Berlin 12 Bezirke
    if (($p >= 10115 && $p <= 10179) || ($p >= 10557 && $p <= 10559) || $p === 10969)                       return 'Mitte';
    if (($p >= 10243 && $p <= 10249) || ($p >= 10961 && $p <= 10967) || $p === 10999)                       return 'Friedrichshain-Kreuzberg';
    if (($p >= 10405 && $p <= 10439) || ($p >= 13086 && $p <= 13089) || ($p >= 13125 && $p <= 13129)
        || ($p >= 13156 && $p <= 13159) || ($p >= 13187 && $p <= 13189))                                    return 'Pankow';
    if (($p >= 10585 && $p <= 10629) || ($p >= 10707 && $p <= 10719) || $p === 10789
        || ($p >= 14050 && $p <= 14059))                                                                     return 'Charlottenburg-Wilmersdorf';
    if ($p >= 13581 && $p <= 13599)                                                                          return 'Spandau';
    if (($p >= 12157 && $p <= 12169) || ($p >= 12247 && $p <= 12279) || ($p >= 14129 && $p <= 14199))       return 'Steglitz-Zehlendorf';
    if (($p >= 10777 && $p <= 10787) || ($p >= 10823 && $p <= 10829) || ($p >= 12099 && $p <= 12107))       return 'Tempelhof-Schöneberg';
    if (($p >= 12043 && $p <= 12059) || ($p >= 12347 && $p <= 12359))                                       return 'Neukölln';
    if (($p >= 12435 && $p <= 12459) || ($p >= 12487 && $p <= 12489) || ($p >= 12524 && $p <= 12529)
        || $p === 12555 || $p === 12587)                                                                     return 'Treptow-Köpenick';
    if ($p >= 12619 && $p <= 12689)                                                                          return 'Marzahn-Hellersdorf';
    if (($p >= 10315 && $p <= 10319) || ($p >= 10365 && $p <= 10369) || ($p >= 13051 && $p <= 13059))       return 'Lichtenberg';
    if (($p >= 13403 && $p <= 13437) || ($p >= 13465 && $p <= 13469) || ($p >= 13507 && $p <= 13509))       return 'Reinickendorf';

    // Umland
    if ($p >= 14469 && $p <= 14482) return 'Potsdam';
    if ($p === 14513 || $p === 14514 || $p === 14515) return 'Teltow';
    if ($p === 14532) return 'Kleinmachnow';
    if ($p === 14612) return 'Falkensee';
    if ($p === 16761 || $p === 16727) return 'Hennigsdorf';
    if ($p === 16515 || $p === 16556) return 'Oranienburg';
    if ($p === 16321 || $p === 16341) return 'Bernau bei Berlin';
    if ($p === 15711 || $p === 15712 || $p === 15713) return 'Königs Wusterhausen';
    if ($p === 16356) return 'Ahrensfelde';
    if ($p === 15344) return 'Strausberg';
    if ($p === 15732 || $p === 15738) return 'Eichwalde / Zeuthen';
    if ($p === 12529 || $p === 12521) return 'Schönefeld / Flughafen';

    // Anything else — tag as generic Umland (admin can correct manually)
    return 'Umland (sonstiges)';
}

$rows = all("
    SELECT c.customer_id, c.name, ca.postal_code
    FROM customer c
    LEFT JOIN customer_address ca ON ca.customer_id_fk = c.customer_id AND ca.ca_id = (
        SELECT MIN(ca_id) FROM customer_address WHERE customer_id_fk = c.customer_id
    )
    WHERE (c.district IS NULL OR c.district = '')
");

$updated = 0; $skipped = 0; $byDistrict = [];
echo "Processing " . count($rows) . " customers without district...\n";
foreach ($rows as $r) {
    $plz = $r['postal_code'] ?? '';
    if (!$plz) { $skipped++; continue; }
    $d = plzToDistrict($plz);
    if (!$d) { $skipped++; continue; }
    q("UPDATE customer SET district=? WHERE customer_id=?", [$d, $r['customer_id']]);
    $updated++;
    $byDistrict[$d] = ($byDistrict[$d] ?? 0) + 1;
}

arsort($byDistrict);
echo "\nUpdated: $updated, skipped: $skipped\n\nBreakdown by district:\n";
foreach ($byDistrict as $d => $n) echo "  $n × $d\n";
