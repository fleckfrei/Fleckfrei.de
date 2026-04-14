<?php
require_once __DIR__ . '/includes/config.php';

$token = trim($_GET['t'] ?? '');
$pl = null;
$error = null;

if ($token) {
    // 1) Erst prebooking_links check (temporäre Admin-Links)
    $pl = one("SELECT * FROM prebooking_links WHERE token=? LIMIT 1", [$token]);
    if ($pl) {
        if (!empty($pl['used_at'])) {
            $error = 'Link wurde bereits genutzt. Ihre Buchung ist aktiv — Login unter <a href="/login.php" style="color:#2E7D6B;text-decoration:underline">app.fleckfrei.de/login</a>';
        } elseif ($pl['expires_at'] && strtotime($pl['expires_at']) < time()) {
            $error = 'Link ist abgelaufen. Bitte neuen Link anfordern.';
        }
    } else {
        // 2) Fallback: personal_slug auf customer → dauerhafter Stamm-Kunden-Link
        $cust = one("SELECT customer_id, name, surname, email, phone, customer_type, district, is_premium, personal_slug, travel_tickets, travel_ticket_price FROM customer WHERE personal_slug=? AND status=1 LIMIT 1", [strtolower($token)]);
        if ($cust) {
            $addr = one("SELECT street, number, postal_code, city, country FROM customer_address WHERE customer_id_fk=? ORDER BY ca_id DESC LIMIT 1", [$cust['customer_id']]);

            // Service-Type aus customer.customer_type
            $ctype = $cust['customer_type'] ?? 'Private Person';
            $svcKey = in_array($ctype, ['Airbnb','Host','Booking','Short-Term Rental'], true) ? 'str'
                    : (in_array($ctype, ['Company','B2B','Office'], true) ? 'office' : 'home_care');
            // Eigener Service-Preis (falls Kunde eigene Services gepflegt hat)
            $custSvc = null;
            try { $custSvc = one("SELECT s_id, title, total_price FROM services WHERE customer_id_fk=? AND status=1 AND is_cleaning=1 ORDER BY s_id DESC LIMIT 1", [$cust['customer_id']]); } catch (Exception $e) {}
            $customRate = !empty($custSvc['total_price']) ? (float)$custSvc['total_price'] : null;

            $pl = [
                'token'      => $cust['personal_slug'],
                'email'      => $cust['email'],
                'name'       => trim(($cust['name'] ?? '') . ' ' . ($cust['surname'] ?? '')),
                'phone'      => $cust['phone'],
                'street'     => trim(($addr['street'] ?? '') . ($addr['number'] ? ' '.$addr['number'] : '')),
                'plz'        => $addr['postal_code'] ?? '',
                'city'       => $addr['city'] ?? 'Berlin',
                'district'   => $cust['district'] ?? '',
                'service_type' => $svcKey,
                'duration'   => 2,
                'voucher_code' => '',
                'custom_hourly_gross' => $customRate,
                'travel_tickets' => (int)($cust['travel_tickets'] ?? 0),
                'travel_ticket_price' => (float)($cust['travel_ticket_price'] ?? 3.80),
                'used_at'    => null,
                'expires_at' => null,
                '_customer_id' => $cust['customer_id'],
            ];
        } else {
            $error = 'Link ungültig. Bitte neuen Link anfordern.';
        }
    }
} else {
    $error = 'Kein Token in URL.';
}

// Redirect mit Query-Prefill → book.php
if ($pl && !$error) {
    $qs = http_build_query([
        'pb'       => $token,
        'email'    => $pl['email'],
        'name'     => $pl['name'],
        'phone'    => $pl['phone'],
        'street'   => $pl['street'],
        'plz'      => $pl['plz'],
        'city'     => $pl['city'],
        'district' => $pl['district'],
        'service'  => $pl['service_type'],
        'hours'    => $pl['duration'],
        'voucher'  => $pl['voucher_code'],
        'rate'     => $pl['custom_hourly_gross'] ?? '',
        'tickets'  => (int)($pl['travel_tickets'] ?? 0),
        'tix_p'    => (float)($pl['travel_ticket_price'] ?? 3.80),
    ]);
    header("Location: /book.php?$qs");
    exit;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Prebooking · Fleckfrei</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center p-4">
  <div class="max-w-md bg-white rounded-2xl border p-8 text-center">
    <div class="text-4xl mb-3">🔗</div>
    <h1 class="text-xl font-bold mb-3">Link nicht verfügbar</h1>
    <p class="text-sm text-gray-600 mb-5"><?= $error ?></p>
    <a href="/book.php" class="inline-block px-5 py-2.5 bg-brand text-white rounded-xl font-semibold" style="background:#2E7D6B">Normal buchen →</a>
  </div>
</body>
</html>
