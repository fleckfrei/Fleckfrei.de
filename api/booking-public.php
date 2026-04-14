<?php
/**
 * Public Booking API — creates customer if needed, creates job, sends confirmation.
 * POST /api/booking-public.php
 * Body: { service_type, qm, rooms, beds, plz, street, number, city, date, time, hours,
 *          frequency, extras[], name, email, phone, notes,
 *          consent_contact, consent_privacy, consent_marketing }
 */
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json; charset=utf-8');

$body = json_decode(file_get_contents('php://input'), true) ?: [];

// Validate required fields
$required = ['service_type', 'plz', 'date', 'time', 'hours', 'name', 'email', 'phone'];
foreach ($required as $f) {
    if (empty(trim((string)($body[$f] ?? '')))) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "Feld '$f' erforderlich"]);
        exit;
    }
}
if (!filter_var($body['email'], FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ungültige Email']);
    exit;
}
if (empty($body['consent_contact']) || empty($body['consent_privacy'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Kontakt- + Datenschutz-Einwilligung erforderlich']);
    exit;
}

// Map service → customer_type
$serviceTypeMap = ['hc' => 'private', 'str' => 'Airbnb', 'bs' => 'Business'];
$customerType = $serviceTypeMap[$body['service_type']] ?? 'private';

$name = trim($body['name']);
$email = strtolower(trim($body['email']));
$phone = preg_replace('~[^\d+]~', '', $body['phone']);
$plz = trim($body['plz']);
$street = trim($body['street'] ?? '');
$number = trim($body['number'] ?? '');
$city = trim($body['city'] ?? 'Berlin');
$country = trim($body['country'] ?? 'Deutschland');
$lat = (float)($body['lat'] ?? 0);
$lng = (float)($body['lng'] ?? 0);
$distanceKm = (int)($body['distance_km'] ?? 0);

// Reject blatantly fake addresses (no lat/lng verification or >100km from Berlin)
if ($lat === 0.0 && $lng === 0.0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Adresse nicht verifiziert — bitte aus der Liste wählen']);
    exit;
}
if ($distanceKm > 100) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Adresse liegt außerhalb unseres Servicegebiets (>100km Berlin). Bitte kontaktiere info@fleckfrei.de.']);
    exit;
}
$qm = (int)($body['qm'] ?? 0);
$rooms = (int)($body['rooms'] ?? 2);
$beds = (int)($body['beds'] ?? 2);
$date = $body['date'];
$time = $body['time'];
$hours = max(2, (float)$body['hours']);
$frequency = $body['frequency'] ?? 'once';
$weekdays = is_array($body['weekdays'] ?? null) ? array_map('intval', $body['weekdays']) : [];
$intervalWeeks = max(2, (int)($body['interval_weeks'] ?? 2));
$notes = trim($body['notes'] ?? '');
if ($frequency !== 'once') {
    $notes .= "\n[Recurring: $frequency"
           . ($frequency === 'nweekly' ? ", every $intervalWeeks weeks" : '')
           . ($weekdays ? ', weekdays: ' . implode(',', $weekdays) : '')
           . ']';
}
$extras = is_array($body['extras'] ?? null) ? $body['extras'] : [];

$ip = $_SERVER['REMOTE_ADDR'] ?? null;
$ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

try {
    // Coupon: validate + compute discount
    $couponCode = strtoupper(trim($body['coupon_code'] ?? ''));
    $discount = 0;
    if ($couponCode) {
        $c = one("SELECT * FROM coupons WHERE code=? AND is_active=1", [$couponCode]);
        if ($c) {
            // Simple re-validation
            $today = date('Y-m-d');
            $ok = (!$c['valid_from'] || $c['valid_from'] <= $today)
               && (!$c['valid_until'] || $c['valid_until'] >= $today)
               && ($c['usage_limit'] == 0 || $c['usage_count'] < $c['usage_limit']);
            if ($ok) {
                // Assume price from form
                $subtotal = (float)($body['subtotal'] ?? 0);
                if ($c['discount_type'] === 'percent') $discount = round($subtotal * ($c['discount_value'] / 100), 2);
                elseif ($c['discount_type'] === 'fixed') $discount = (float)$c['discount_value'];
                $discount = min($discount, $subtotal);
                // Usage-count inkrementieren
                q("UPDATE coupons SET usage_count = usage_count + 1 WHERE co_id=?", [$c['co_id']]);
            }
        }
    }

    // 1) Find or create customer (only if requested)
    $wantAccount = !empty($body['create_account']);
    $customer = one("SELECT * FROM customer WHERE email=? LIMIT 1", [$email]);
    $newCustomer = false;
    if ($customer) {
        $customerId = (int)$customer['customer_id'];
        if (empty($customer['phone']) && $phone) q("UPDATE customer SET phone=? WHERE customer_id=?", [$phone, $customerId]);
    } elseif ($wantAccount) {
        // Auto-register full account
        $nameParts = explode(' ', $name, 2);
        $fn = $nameParts[0] ?? $name;
        $ln = $nameParts[1] ?? '';
        $randomPw = bin2hex(random_bytes(16));
        q("INSERT INTO customer (name, surname, email, phone, customer_type, status,
             consent_email, consent_whatsapp, consent_phone, consent_updated_at, password, created_at)
           VALUES (?,?,?,?,?,1, ?,?,?, NOW(), ?, NOW())",
          [$fn, $ln, $email, $phone, $customerType,
           !empty($body['consent_marketing']) ? 1 : 0,
           !empty($body['consent_marketing']) ? 1 : 0,
           !empty($body['consent_marketing']) ? 1 : 0,
           password_hash($randomPw, PASSWORD_BCRYPT)]);
        $customerId = (int) lastInsertId();
        try { q("INSERT INTO users (email, type) VALUES (?, 'customer')", [$email]); } catch (Exception $e) {}
        $newCustomer = true;
        audit('auto_signup', 'customer', $customerId, "via /book.php — $email (user opted-in)");
    } else {
        // Guest booking — store as minimal-customer (flag or separate table)
        $nameParts = explode(' ', $name, 2);
        $fn = $nameParts[0] ?? $name;
        $ln = $nameParts[1] ?? '';
        q("INSERT INTO customer (name, surname, email, phone, customer_type, status, created_at)
           VALUES (?,?,?,?,?, 1, NOW())",
          [$fn, $ln, $email, $phone, $customerType]);
        $customerId = (int) lastInsertId();
        $newCustomer = false; // no account
    }

    // 2) Store address with full details
    $addressStr = trim("$street, $plz $city, $country");
    if ($lat && $lng) $addressStr .= " [GPS: $lat,$lng · $distanceKm km]";

    // 3) Create job
    $optionalProdStr = implode(',', array_map('intval', $extras));
    $jobTime = substr($time, 0, 5) . ':00';
    q("INSERT INTO jobs (customer_id_fk, j_date, j_time, j_hours, job_for, s_id_fk,
         address, optional_products, emp_message, no_people, no_children, no_pets, code_door,
         status, platform, job_status, coupon_code, discount_applied, created_at)
       VALUES (?, ?, ?, ?, ?, 0, ?, ?, '', ?, 0, 0, '', 1, 'Website', 'NEW', ?, ?, NOW())",
      [$customerId, $date, $jobTime, $hours, $customerType, $addressStr, $optionalProdStr, $rooms + $beds, $couponCode ?: null, $discount]);
    $jobId = (int) lastInsertId();

    // 4) Consent history
    try {
        q("INSERT INTO consent_history (customer_id_fk, channel, old_value, new_value, source, ip, user_agent, changed_by)
           VALUES (?, 'email', 0, ?, 'book.php', ?, ?, ?)",
          [$customerId, !empty($body['consent_marketing']) ? 1 : 0, $ip, $ua, 'public_booking:' . $jobId]);
    } catch (Exception $e) {}

    // 5) Send customer-confirmation email
    $title = match($customerType) { 'Airbnb' => 'Short-Term Rental', 'Business' => 'Business & Office', default => 'Home Care' };
    $subject = "Fleckfrei — Buchungsbestätigung #$jobId";
    $loginHint = $newCustomer
        ? "<p><strong>Dein Kundenkonto wurde automatisch erstellt.</strong> Logge dich ein: <a href=\"https://app.fleckfrei.de/login.php\">app.fleckfrei.de/login</a> — mit Google oder <a href=\"https://app.fleckfrei.de/password-reset.php\">Passwort setzen</a>.</p>"
        : "<p>Dein Kundenkonto: <a href=\"https://app.fleckfrei.de/login.php\">app.fleckfrei.de/login</a></p>";
    $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>body{font-family:sans-serif;max-width:600px;margin:0 auto;color:#111}
      .hdr{background:#2E7D6B;color:#fff;padding:24px;text-align:center}
      .box{padding:20px;background:#f8f9fa;margin:16px 0;border-radius:8px}
      .cta{display:inline-block;background:#2E7D6B;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:700}
      </style></head><body>
      <div class="hdr"><h1>✓ Buchung eingegangen</h1><p style="margin:8px 0 0">Buchung #' . $jobId . '</p></div>
      <div class="box">
        <p>Hallo ' . htmlspecialchars($fn ?? $name) . ',</p>
        <p>wir haben deine Buchung erhalten:</p>
        <ul>
          <li><strong>Service:</strong> ' . $title . '</li>
          <li><strong>Datum:</strong> ' . $date . ' um ' . $time . ' Uhr</li>
          <li><strong>Dauer:</strong> ' . $hours . ' Stunden</li>
          <li><strong>Adresse:</strong> ' . htmlspecialchars($addressStr) . '</li>
          <li><strong>Häufigkeit:</strong> ' . $frequency . '</li>
        </ul>
      </div>
      ' . $loginHint . '
      <p>Wir melden uns innerhalb von 24h mit einer Bestätigung + Partnerdaten.</p>
      <a class="cta" href="https://app.fleckfrei.de/login.php">Zum Kundenportal →</a>
      <p style="font-size:11px;color:#888;margin-top:24px">Fleckfrei · Berlin · <a href="https://fleckfrei.de">fleckfrei.de</a></p>
      </body></html>';
    $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n"
             . "From: Fleckfrei <no-reply@fleckfrei.de>\r\n"
             . "Reply-To: info@fleckfrei.de\r\n"
             . "Bcc: info@fleckfrei.de\r\n";
    @mail($email, $subject, $html, $headers);

    // 6) Telegram notify to admin
    if (function_exists('telegramNotify')) {
        $emoji = $newCustomer ? '🆕👤' : '📅';
        $msg = "$emoji <b>Neue Buchung #$jobId</b>\n\n"
             . "👤 " . htmlspecialchars($name) . ($newCustomer ? " (NEU)" : "") . "\n"
             . "📧 " . htmlspecialchars($email) . "\n"
             . "📱 " . htmlspecialchars($phone) . "\n"
             . "🏠 " . htmlspecialchars($title) . "\n"
             . "📅 " . $date . " um " . $time . " · " . $hours . "h · " . $frequency . "\n"
             . "📍 " . htmlspecialchars($addressStr) . "\n\n"
             . "→ <a href=\"https://app.fleckfrei.de/admin/view-job.php?id=$jobId\">Job öffnen</a>";
        telegramNotify($msg);
    }

    echo json_encode([
        'success' => true,
        'booking_id' => $jobId,
        'customer_id' => $customerId,
        'new_customer' => $newCustomer,
        'login_url' => 'https://app.fleckfrei.de/login.php',
    ]);
} catch (Exception $e) {
    http_response_code(500);
    error_log('booking-public error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Server-Fehler: ' . $e->getMessage()]);
}
