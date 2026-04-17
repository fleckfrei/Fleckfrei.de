<?php
// One-shot geocoder for employee addresses (lat/lng).
// Runs via: php scripts/geocode-partners.php   OR  /admin/route-planner.php?action=geocode
// Uses OSM Nominatim (free, no key). Swap to Google Geocoding API later if needed.
//
// Respects usage policy: 1 req/sec. Only processes rows where lat IS NULL.
// Safe to re-run — idempotent.

require_once __DIR__ . '/../includes/config.php';

function geocodeOSM(string $query): ?array {
    $url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' . urlencode($query);
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => "User-Agent: FleckfreiGeocoder/1.0 (info@fleckfrei.de)\r\n",
            'timeout' => 10,
        ]
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) return null;
    $data = json_decode($body, true);
    if (!is_array($data) || empty($data[0]['lat'])) return null;
    return ['lat' => (float)$data[0]['lat'], 'lng' => (float)$data[0]['lon']];
}

$rows = all("SELECT ea_id, street, number, postal_code, city, country
             FROM employee_address
             WHERE lat IS NULL OR lng IS NULL");

$done = 0; $fail = 0;
echo "Geocoding " . count($rows) . " partner addresses via OSM Nominatim (1 req/sec)...\n";

foreach ($rows as $r) {
    $parts = array_filter([
        trim(($r['street'] ?? '') . ' ' . ($r['number'] ?? '')),
        trim(($r['postal_code'] ?? '') . ' ' . ($r['city'] ?? '')),
        $r['country'] ?? 'Germany',
    ]);
    $query = implode(', ', $parts);
    if (!$query) { $fail++; continue; }
    $res = geocodeOSM($query);
    if ($res) {
        q("UPDATE employee_address SET lat=?, lng=? WHERE ea_id=?", [$res['lat'], $res['lng'], $r['ea_id']]);
        $done++;
        echo "  OK: ea_id={$r['ea_id']} → {$res['lat']},{$res['lng']}  ($query)\n";
    } else {
        $fail++;
        echo "  FAIL: ea_id={$r['ea_id']}  ($query)\n";
    }
    // Nominatim policy: ≤1 req/sec
    sleep(1);
}

echo "\nDone. $done OK, $fail failed.\n";
