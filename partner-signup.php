<?php
/**
 * Öffentliches Partner-Onboarding-Formular — Token-basiert, passwortlos.
 * Admin erstellt Token in /admin/employees.php → sendet Link an Interessent →
 * Partner füllt Formular → Employee-Eintrag wird mit status=2 (pending_review) angelegt →
 * Admin bestätigt in Backend.
 *
 * URL: https://app.fleckfrei.de/p-signup/<token>
 * Struktur basiert auf WhatsApp Flow "1_WA_new_partner_fleckfrei" (Google Doc).
 */
require_once __DIR__ . '/includes/config.php';

// Idempotente Schema-Migration
try { q("CREATE TABLE IF NOT EXISTS partner_invites (
    pi_id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    token VARCHAR(64) UNIQUE NOT NULL,
    email VARCHAR(255) NULL,
    created_by VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    used_at TIMESTAMP NULL,
    emp_id_fk INT UNSIGNED NULL,
    expires_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Exception $e) {}

$token = trim($_GET['t'] ?? $_GET['token'] ?? '');
if (!$token || !preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $token)) {
    http_response_code(404);
    die('Invite-Token fehlt oder ungültig.');
}

$invite = one("SELECT * FROM partner_invites WHERE token=?", [$token]);
if (!$invite) {
    http_response_code(404);
    die('Einladung existiert nicht. Bitte neue Einladung anfordern.');
}
if (!empty($invite['used_at'])) {
    die('<h2>Diese Einladung wurde bereits genutzt.</h2><p>Falls du Zugangsdaten brauchst, kontaktiere uns über <a href="https://fleckfrei.de">fleckfrei.de</a>.</p>');
}
if (!empty($invite['expires_at']) && strtotime($invite['expires_at']) < time()) {
    die('<h2>Diese Einladung ist abgelaufen.</h2><p>Bitte frag beim Admin nach einer neuen.</p>');
}

// POST — Formular-Submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = $_POST;
    $req = ['first_name','last_name','email','phone','partner_type','city','postal_code'];
    $missing = [];
    foreach ($req as $r) if (empty(trim($d[$r] ?? ''))) $missing[] = $r;

    if ($missing) {
        $err = 'Bitte folgende Felder ausfüllen: ' . implode(', ', $missing);
    } else {
        // Check for email-dupe in employee table
        $exists = one("SELECT emp_id FROM employee WHERE LOWER(email)=LOWER(?)", [trim($d['email'])]);
        if ($exists) {
            $err = 'Mit dieser E-Mail existiert bereits ein Partner-Account. Bitte logge dich ein oder kontaktiere den Admin.';
        } else {
            // Full record as JSON in notes (audit trail + future review)
            $raw = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

            // Map WhatsApp-Flow-Fields → employee-Spalten
            $name = trim($d['first_name']);
            $surname = trim($d['last_name']);
            $email = strtolower(trim($d['email']));
            $phone = trim($d['phone']);
            $location = trim(($d['home_address'] ?? '') . ', ' . ($d['postal_code'] ?? '') . ' ' . ($d['city'] ?? ''));
            $nationality = trim($d['nationality'] ?? '');
            $tariff = (float)($d['hourly_rate'] ?? 0);
            $partnerType = $d['partner_type'] ?? '';
            $contractType = $d['business_type'] ?? '';
            $companyName = trim($d['business_name'] ?? '');
            $companySize = (int)($d['employees_count'] ?? 1);
            $taxId = trim($d['tax_id'] ?? '');
            $taxIdClean = preg_replace('/[^A-Z0-9]/', '', strtoupper($taxId));

            $notes = "=== PARTNER-SIGNUP via Formular " . date('Y-m-d H:i') . " ===\n"
                   . "Token: $token\n\n"
                   . "Vollständige Antworten:\n" . $raw;

            // Password generieren (Partner kriegt das per Email vom Admin)
            $pwdPlain = substr(bin2hex(random_bytes(4)), 0, 8);
            $pwdHash = password_hash($pwdPlain, PASSWORD_DEFAULT);

            global $db;
            q("INSERT INTO employee (name, surname, email, phone, tariff, location, nationality,
                  partner_type, contract_type, company_name, company_size, tax_id,
                  password, status, notes, email_permissions, display_name)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,2,?,'all',?)",
                [$name, $surname, $email, $phone, $tariff, $location, $nationality,
                 $partnerType, $contractType, $companyName, $companySize, $taxIdClean,
                 $pwdHash, $notes, $name . ' ' . strtoupper(substr($surname, 0, 1)) . '.']);
            $empId = (int)$db->lastInsertId();

            // Token als genutzt markieren
            q("UPDATE partner_invites SET used_at=NOW(), emp_id_fk=? WHERE pi_id=?", [$empId, $invite['pi_id']]);

            // Admin-Notification
            if (function_exists('telegramNotify')) {
                telegramNotify("🎉 <b>Neue Partner-Bewerbung</b>\n\n"
                    . "👤 $name $surname ($partnerType)\n"
                    . "📧 $email\n"
                    . "📞 $phone\n"
                    . "📍 $location\n"
                    . "💰 {$tariff} €/h\n"
                    . ($companyName ? "🏢 $companyName\n" : '')
                    . "\n→ /admin/employees.php (status=pending_review)");
            }

            // Success-Seite
            $success = true;
        }
    }
}

$err = $err ?? null;
$success = $success ?? false;
$brand = defined('BRAND') ? BRAND : '#2E7D6B';
$brandDark = defined('BRAND_DARK') ? BRAND_DARK : '#1a5d4e';
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Partner werden — Fleckfrei</title>
<script src="https://cdn.tailwindcss.com"></script>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.3/dist/cdn.min.js"></script>
<script>tailwind.config={theme:{extend:{colors:{brand:'<?= $brand ?>','brand-dark':'<?= $brandDark ?>','brand-light':'<?= $brand ?>15'}}}}</script>
<style>[x-cloak]{display:none!important}</style>
</head>
<body class="bg-gradient-to-br from-brand-light via-white to-brand-light/30 min-h-screen py-8 px-4">

<?php if ($success): ?>
<div class="max-w-lg mx-auto bg-white rounded-2xl shadow-xl p-8 text-center">
  <div class="text-6xl mb-4">🎉</div>
  <h1 class="text-2xl font-bold text-brand-dark mb-2">Bewerbung eingegangen!</h1>
  <p class="text-gray-700 mb-4">Danke <?= htmlspecialchars($d['first_name']) ?>. Wir schauen uns deine Angaben an und melden uns innerhalb von 48 Stunden per E-Mail oder WhatsApp.</p>
  <p class="text-sm text-gray-500">📧 <?= htmlspecialchars($d['email']) ?></p>
  <a href="https://fleckfrei.de" class="inline-block mt-6 px-6 py-3 bg-brand text-white rounded-xl font-semibold hover:bg-brand-dark">→ Zurück zu fleckfrei.de</a>
</div>
<?php exit; endif; ?>

<div class="max-w-2xl mx-auto" x-data="signupForm()" x-cloak>
  <!-- Header -->
  <div class="text-center mb-6">
    <div class="inline-flex w-16 h-16 rounded-full bg-brand text-white items-center justify-center text-3xl font-bold mb-3">F</div>
    <h1 class="text-3xl font-extrabold text-brand-dark">Partner werden</h1>
    <p class="text-gray-600 mt-1">Willkommen bei Fleckfrei — lass uns was Grosses aufbauen.</p>
  </div>

  <!-- Progress -->
  <div class="flex items-center gap-1 mb-6">
    <template x-for="n in totalSteps" :key="n">
      <div class="flex-1 h-1.5 rounded-full transition" :class="n <= step ? 'bg-brand' : 'bg-gray-200'"></div>
    </template>
  </div>
  <div class="text-xs text-center text-gray-500 mb-4">Schritt <span x-text="step"></span> / <span x-text="totalSteps"></span></div>

  <?php if ($err): ?><div class="bg-rose-50 border border-rose-300 text-rose-900 px-4 py-3 rounded-xl mb-4"><?= htmlspecialchars($err) ?></div><?php endif; ?>

  <form method="POST" class="bg-white rounded-2xl shadow-xl p-6" @submit="if (step < totalSteps) { $event.preventDefault(); nextStep(); }">

    <!-- STEP 1: Welcome + Consent -->
    <div x-show="step === 1">
      <h2 class="text-xl font-bold mb-4">🤝 Willkommen bei Fleckfrei Partners!</h2>
      <div class="bg-brand-light/40 border border-brand/20 rounded-lg p-4 text-sm text-gray-800 mb-4">
        Als Partner bekommst du: stetigen Job-Strom · 60-70% Provision · professioneller Support · flexibler Zeitplan. Berlin & Rumänien.
      </div>
      <label class="flex items-start gap-2 p-3 bg-gray-50 rounded-lg cursor-pointer">
        <input type="checkbox" name="gdpr_consent" value="1" required class="mt-0.5"/>
        <span class="text-sm">Ich stimme der <a href="https://fleckfrei.de/datenschutz" target="_blank" class="text-brand underline">Datenschutzerklärung</a> und den AGB zu *</span>
      </label>
      <label class="flex items-start gap-2 p-3 bg-gray-50 rounded-lg cursor-pointer mt-2">
        <input type="checkbox" name="partner_consent" value="1" required class="mt-0.5"/>
        <span class="text-sm">Ich stimme zu per E-Mail, SMS und Telefon kontaktiert zu werden *</span>
      </label>
      <div class="mt-4">
        <label class="block text-sm font-semibold mb-2">Partner-Typ *</label>
        <div class="grid grid-cols-2 gap-2">
          <?php foreach (['individual'=>'👤 Einzelperson','small_team'=>'👥 Kleines Team (2-5)','agency'=>'🏢 Reinigungsagentur','other'=>'📋 Anderer Anbieter'] as $k=>$v): ?>
          <label class="flex items-center gap-2 p-3 border rounded-lg cursor-pointer hover:border-brand has-[:checked]:border-brand has-[:checked]:bg-brand-light">
            <input type="radio" name="partner_type" value="<?= $k ?>" required/>
            <span class="text-sm"><?= $v ?></span>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- STEP 2: Personal Info -->
    <div x-show="step === 2">
      <h2 class="text-xl font-bold mb-4">👤 Persönliche Infos</h2>
      <div class="grid grid-cols-2 gap-3">
        <div><label class="text-xs font-semibold">Vorname *</label><input name="first_name" required class="w-full px-3 py-2 border rounded-lg"/></div>
        <div><label class="text-xs font-semibold">Nachname *</label><input name="last_name" required class="w-full px-3 py-2 border rounded-lg"/></div>
      </div>
      <div class="grid grid-cols-2 gap-3 mt-3">
        <div><label class="text-xs font-semibold">E-Mail *</label><input type="email" name="email" required class="w-full px-3 py-2 border rounded-lg"/></div>
        <div><label class="text-xs font-semibold">Telefon *</label><input type="tel" name="phone" required placeholder="+49..." class="w-full px-3 py-2 border rounded-lg"/></div>
      </div>
      <div class="grid grid-cols-2 gap-3 mt-3">
        <div><label class="text-xs font-semibold">Nationalität</label>
          <select name="nationality" class="w-full px-3 py-2 border rounded-lg">
            <option value="">— wählen —</option><option value="DE">Deutschland</option><option value="RO">Rumänien</option><option value="AT">Österreich</option><option value="PL">Polen</option><option value="BG">Bulgarien</option><option value="HR">Kroatien</option><option value="OTHER">Andere</option>
          </select>
        </div>
        <div><label class="text-xs font-semibold">Geburtsdatum</label><input type="date" name="date_of_birth" class="w-full px-3 py-2 border rounded-lg"/></div>
      </div>
      <div class="mt-3"><label class="text-xs font-semibold">Ausweis- / Pass-Nr.</label><input name="id_number" class="w-full px-3 py-2 border rounded-lg"/></div>
    </div>

    <!-- STEP 3: Business -->
    <div x-show="step === 3">
      <h2 class="text-xl font-bold mb-4">🏢 Business-Daten</h2>
      <div class="grid grid-cols-2 gap-3">
        <div class="col-span-2"><label class="text-xs font-semibold">Firmenname</label><input name="business_name" placeholder="z.B. Max Reinigung GmbH" class="w-full px-3 py-2 border rounded-lg"/></div>
        <div><label class="text-xs font-semibold">Rechtsform</label>
          <select name="business_type" class="w-full px-3 py-2 border rounded-lg">
            <option value="sole">Einzelunternehmer</option><option value="partnership">Partnerschaft</option><option value="gmbh">GmbH / UG</option><option value="other">Andere</option>
          </select>
        </div>
        <div><label class="text-xs font-semibold">Erfahrung (Jahre)</label><input type="number" name="years_experience" min="0" class="w-full px-3 py-2 border rounded-lg"/></div>
        <div><label class="text-xs font-semibold">Anzahl Mitarbeiter</label><input type="number" name="employees_count" min="1" value="1" class="w-full px-3 py-2 border rounded-lg"/></div>
        <div><label class="text-xs font-semibold">Steuer-ID / USt-IdNr.</label><input name="tax_id" placeholder="DE123456789" class="w-full px-3 py-2 border rounded-lg"/></div>
        <div class="col-span-2"><label class="text-xs font-semibold">Handelsregister-Nr.</label><input name="business_registration" placeholder="HRB123456" class="w-full px-3 py-2 border rounded-lg"/></div>
      </div>
    </div>

    <!-- STEP 4: Location -->
    <div x-show="step === 4">
      <h2 class="text-xl font-bold mb-4">📍 Service-Gebiet</h2>
      <div class="grid grid-cols-2 gap-3">
        <div class="col-span-2"><label class="text-xs font-semibold">Adresse *</label><input name="home_address" placeholder="Straße + Hausnr." class="w-full px-3 py-2 border rounded-lg"/></div>
        <div><label class="text-xs font-semibold">PLZ *</label><input name="postal_code" required class="w-full px-3 py-2 border rounded-lg"/></div>
        <div><label class="text-xs font-semibold">Stadt *</label><input name="city" required value="Berlin" class="w-full px-3 py-2 border rounded-lg"/></div>
        <div class="col-span-2"><label class="text-xs font-semibold">Land</label>
          <select name="country" class="w-full px-3 py-2 border rounded-lg"><option value="DE">Deutschland</option><option value="RO">Rumänien</option><option value="OTHER">Andere</option></select>
        </div>
      </div>
      <div class="mt-3"><label class="text-xs font-semibold">Welche Bezirke kannst du bedienen?</label>
        <div class="grid grid-cols-3 gap-1 mt-1">
          <?php foreach (['mitte'=>'Mitte','charlottenburg'=>'Charlottenburg','prenzlauer'=>'Prenzlauer Berg','kreuzberg'=>'Kreuzberg','neukolln'=>'Neukölln','tempelhof'=>'Tempelhof','spandau'=>'Spandau','kopenick'=>'Köpenick','lichtenberg'=>'Lichtenberg','pankow'=>'Pankow','wedding'=>'Wedding','treptow'=>'Treptow'] as $k=>$v): ?>
          <label class="flex items-center gap-1 p-1.5 border rounded text-xs hover:bg-brand-light has-[:checked]:bg-brand-light has-[:checked]:border-brand"><input type="checkbox" name="service_areas[]" value="<?= $k ?>"/><?= $v ?></label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="grid grid-cols-2 gap-3 mt-3">
        <div><label class="text-xs font-semibold">Max. Anfahrt (km)</label><input type="number" name="max_distance" min="1" value="15" class="w-full px-3 py-2 border rounded-lg"/></div>
        <div><label class="text-xs font-semibold">Verfügbarkeit</label>
          <div class="flex gap-2 text-xs mt-1"><label class="flex items-center gap-1"><input type="checkbox" name="availability[]" value="weekdays"/>Mo-Fr</label><label class="flex items-center gap-1"><input type="checkbox" name="availability[]" value="weekends"/>Wochenende</label><label class="flex items-center gap-1"><input type="checkbox" name="availability[]" value="flexible"/>Flexibel</label></div>
        </div>
      </div>
    </div>

    <!-- STEP 5: Experience -->
    <div x-show="step === 5">
      <h2 class="text-xl font-bold mb-4">💼 Erfahrung & Spezialisierung</h2>
      <div><label class="text-xs font-semibold">Worauf spezialisiert?</label>
        <div class="grid grid-cols-2 gap-1 mt-1">
          <?php foreach (['airbnb'=>'Airbnb / STR','residential'=>'Wohnungsreinigung','commercial'=>'Gewerbe/Büro','deep'=>'Grundreinigung','post_construction'=>'Baustellen','windows'=>'Fenster','carpet'=>'Teppich','eco'=>'Öko / Green'] as $k=>$v): ?>
          <label class="flex items-center gap-1 p-1.5 border rounded text-xs hover:bg-brand-light has-[:checked]:bg-brand-light has-[:checked]:border-brand"><input type="checkbox" name="cleaning_specialization[]" value="<?= $k ?>"/><?= $v ?></label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="mt-3"><label class="text-xs font-semibold">Sprachen</label>
        <div class="flex flex-wrap gap-1 mt-1">
          <?php foreach (['de'=>'Deutsch','ro'=>'Rumänisch','en'=>'Englisch','fr'=>'Französisch','es'=>'Spanisch','pl'=>'Polnisch','tr'=>'Türkisch'] as $k=>$v): ?>
          <label class="flex items-center gap-1 px-2 py-1 border rounded text-xs hover:bg-brand-light has-[:checked]:bg-brand-light has-[:checked]:border-brand"><input type="checkbox" name="languages[]" value="<?= $k ?>"/><?= $v ?></label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="mt-3"><label class="text-xs font-semibold">Haftpflichtversicherung?</label>
        <div class="flex gap-2 mt-1">
          <label class="flex items-center gap-1 text-sm"><input type="radio" name="insurance" value="yes"/>Ja</label>
          <label class="flex items-center gap-1 text-sm"><input type="radio" name="insurance" value="no"/>Nein</label>
        </div>
      </div>
      <div class="mt-3"><label class="text-xs font-semibold">Versicherung bei</label><input name="insurance_provider" placeholder="z.B. Allianz, HUK-Coburg" class="w-full px-3 py-2 border rounded-lg"/></div>
      <div class="mt-3"><label class="text-xs font-semibold">Referenzen (optional)</label><textarea name="references" rows="2" placeholder="Vorherige Kunden, Empfehlungen..." class="w-full px-3 py-2 border rounded-lg"></textarea></div>
    </div>

    <!-- STEP 6: Pricing -->
    <div x-show="step === 6">
      <h2 class="text-xl font-bold mb-4">💰 Deine Preise</h2>
      <div class="grid grid-cols-2 gap-3">
        <div><label class="text-xs font-semibold">Stundensatz (€) *</label><input type="number" name="hourly_rate" step="0.5" min="10" required placeholder="20" class="w-full px-3 py-2 border rounded-lg"/></div>
        <div><label class="text-xs font-semibold">Min. Einsatz (h)</label><input type="number" name="min_job_duration" min="1" value="2" class="w-full px-3 py-2 border rounded-lg"/></div>
        <div><label class="text-xs font-semibold">Pauschale Turnover (€)</label><input type="number" name="flat_rate_turnover" placeholder="150" class="w-full px-3 py-2 border rounded-lg"/></div>
        <div><label class="text-xs font-semibold">Pauschale Grundreinigung (€)</label><input type="number" name="flat_rate_deep" placeholder="250" class="w-full px-3 py-2 border rounded-lg"/></div>
        <div class="col-span-2"><label class="text-xs font-semibold">Anfahrt pro km (€)</label><input type="number" name="travel_fee" step="0.1" placeholder="0.50" class="w-full px-3 py-2 border rounded-lg"/></div>
      </div>
      <div class="mt-3 bg-amber-50 border border-amber-200 rounded-lg p-3 text-xs">
        <div class="font-semibold text-amber-900 mb-1">💡 Provision</div>
        Fleckfrei behält 30-40% des Kundenpreises, du bekommst 60-70% pro Job.
      </div>
      <div class="mt-3"><label class="text-xs font-semibold">Provisions-Modell akzeptiert?</label>
        <div class="flex gap-2 mt-1">
          <label class="flex items-center gap-1 text-sm"><input type="radio" name="commission_acceptable" value="yes"/>Ja</label>
          <label class="flex items-center gap-1 text-sm"><input type="radio" name="commission_acceptable" value="no"/>Nein, wir besprechen</label>
        </div>
      </div>
      <div class="mt-3"><label class="text-xs font-semibold">Zahlung per</label>
        <select name="preferred_payment" class="w-full px-3 py-2 border rounded-lg"><option value="bank">Bank-Überweisung</option><option value="paypal">PayPal</option><option value="wise">Wise</option><option value="cash">Bar</option></select>
      </div>
    </div>

    <!-- STEP 7: Equipment -->
    <div x-show="step === 7">
      <h2 class="text-xl font-bold mb-4">🧰 Equipment</h2>
      <div><label class="text-xs font-semibold">Eigenes Reinigungs-Equipment?</label>
        <div class="flex gap-2 mt-1">
          <label class="flex items-center gap-1 text-sm"><input type="radio" name="own_equipment" value="yes"/>Ja</label>
          <label class="flex items-center gap-1 text-sm"><input type="radio" name="own_equipment" value="no"/>Nein</label>
        </div>
      </div>
      <div class="mt-3"><label class="text-xs font-semibold">Was hast du?</label><textarea name="equipment_list" rows="2" placeholder="Staubsauger, Mop, Reinigungsmittel..." class="w-full px-3 py-2 border rounded-lg"></textarea></div>
      <div class="mt-3"><label class="text-xs font-semibold">Reinigungsmittel vom Kunden nötig?</label>
        <div class="flex gap-2 mt-1">
          <label class="flex items-center gap-1 text-sm"><input type="radio" name="need_supplies" value="yes"/>Ja, bitte bereitstellen</label>
          <label class="flex items-center gap-1 text-sm"><input type="radio" name="need_supplies" value="no"/>Nein, bringe eigene</label>
        </div>
      </div>
      <div class="mt-3"><label class="text-xs font-semibold">Allergien / chemische Unverträglichkeiten?</label><textarea name="allergies" rows="2" class="w-full px-3 py-2 border rounded-lg"></textarea></div>
      <div class="mt-3"><label class="text-xs font-semibold">Öko-Produkte bevorzugt?</label>
        <div class="flex gap-2 mt-1">
          <label class="flex items-center gap-1 text-sm"><input type="radio" name="eco_friendly" value="yes"/>Ja</label>
          <label class="flex items-center gap-1 text-sm"><input type="radio" name="eco_friendly" value="no"/>Keine Präferenz</label>
        </div>
      </div>
    </div>

    <!-- STEP 8: Communication -->
    <div x-show="step === 8">
      <h2 class="text-xl font-bold mb-4">📱 Kommunikation</h2>
      <div><label class="text-xs font-semibold">Bevorzugter Kontakt-Weg</label>
        <select name="preferred_contact" class="w-full px-3 py-2 border rounded-lg"><option value="whatsapp">WhatsApp</option><option value="telegram">Telegram</option><option value="email">E-Mail</option><option value="call">Anruf</option><option value="sms">SMS</option></select>
      </div>
      <div class="mt-3 grid grid-cols-2 gap-3">
        <div><label class="text-xs font-semibold">Telegram @username</label><input name="telegram_id" placeholder="@max_mustermann" class="w-full px-3 py-2 border rounded-lg"/></div>
        <div><label class="text-xs font-semibold">WhatsApp nutzen?</label>
          <div class="flex gap-2 mt-2"><label class="flex items-center gap-1 text-sm"><input type="radio" name="whatsapp" value="yes"/>Ja</label><label class="flex items-center gap-1 text-sm"><input type="radio" name="whatsapp" value="no"/>Nein</label></div>
        </div>
      </div>
      <div class="mt-3 grid grid-cols-2 gap-3">
        <div><label class="text-xs font-semibold">Antwort-Zeit auf Job-Angebote</label>
          <select name="response_time" class="w-full px-3 py-2 border rounded-lg"><option value="15min">&lt; 15 min</option><option value="1h">&lt; 1h</option><option value="4h">2-4h</option><option value="24h">&lt; 24h</option></select>
        </div>
        <div><label class="text-xs font-semibold">Technik-Komfort</label>
          <select name="tech_comfort" class="w-full px-3 py-2 border rounded-lg"><option value="very">Sehr sicher</option><option value="comfortable">Gut</option><option value="basic">Basis</option><option value="support">Brauche Support</option></select>
        </div>
      </div>
    </div>

    <!-- STEP 9: Background + Consents -->
    <div x-show="step === 9">
      <h2 class="text-xl font-bold mb-4">🔒 Hintergrund & Einwilligungen</h2>
      <div><label class="text-xs font-semibold">Vorstrafen?</label>
        <div class="flex gap-2 mt-1">
          <label class="flex items-center gap-1 text-sm"><input type="radio" name="criminal_record" value="no"/>Nein</label>
          <label class="flex items-center gap-1 text-sm"><input type="radio" name="criminal_record" value="yes"/>Ja (besprechen)</label>
        </div>
      </div>
      <div class="space-y-2 mt-4">
        <label class="flex items-start gap-2 p-3 bg-gray-50 rounded-lg cursor-pointer"><input type="checkbox" name="background_check_consent" value="1" required class="mt-0.5"/><span class="text-sm">Ich stimme einer <b>Hintergrundprüfung</b> zu (erforderlich) *</span></label>
        <label class="flex items-start gap-2 p-3 bg-gray-50 rounded-lg cursor-pointer"><input type="checkbox" name="references_check_consent" value="1" required class="mt-0.5"/><span class="text-sm">Ich stimme einer <b>Referenz-Prüfung</b> zu *</span></label>
        <label class="flex items-start gap-2 p-3 bg-gray-50 rounded-lg cursor-pointer"><input type="checkbox" name="data_verification" value="1" required class="mt-0.5"/><span class="text-sm">Ich stimme der <b>Daten-Verifikation</b> zu *</span></label>
      </div>
    </div>

    <!-- STEP 10: Agreement -->
    <div x-show="step === 10">
      <h2 class="text-xl font-bold mb-4">📋 Partner-Vereinbarung</h2>
      <div class="bg-gray-50 rounded-lg p-4 text-xs text-gray-700 space-y-2 max-h-48 overflow-y-auto mb-3">
        <p class="font-semibold">Als Fleckfrei-Partner stimmst du zu:</p>
        <ul class="list-disc ml-4 space-y-1">
          <li>Qualitätsstandards einhalten</li>
          <li>Professionelles Verhalten</li>
          <li>Prompte Antwort auf Job-Angebote</li>
          <li>Ausgezeichneter Kunden-Service</li>
          <li>Vertraulichkeit wahren</li>
          <li>Lokale Gesetze einhalten</li>
        </ul>
      </div>
      <div class="space-y-2">
        <label class="flex items-start gap-2 p-3 bg-gray-50 rounded-lg cursor-pointer"><input type="checkbox" name="terms_agreement" value="1" required class="mt-0.5"/><span class="text-sm">Ich stimme der <b>Partnerschafts-Vereinbarung</b> zu *</span></label>
        <label class="flex items-start gap-2 p-3 bg-gray-50 rounded-lg cursor-pointer"><input type="checkbox" name="quality_standards" value="1" required class="mt-0.5"/><span class="text-sm">Ich halte die <b>Qualitätsstandards</b> ein *</span></label>
        <label class="flex items-start gap-2 p-3 bg-gray-50 rounded-lg cursor-pointer"><input type="checkbox" name="confidentiality" value="1" required class="mt-0.5"/><span class="text-sm">Ich wahre <b>Vertraulichkeit</b> (Kunden-Daten) *</span></label>
      </div>
    </div>

    <!-- STEP 11: Bank + Final -->
    <div x-show="step === 11">
      <h2 class="text-xl font-bold mb-4">💳 Bankdaten & Letzte Angaben</h2>
      <div><label class="text-xs font-semibold">Kontoinhaber *</label><input name="bank_account_name" required class="w-full px-3 py-2 border rounded-lg"/></div>
      <div class="mt-3"><label class="text-xs font-semibold">IBAN *</label><input name="bank_iban" required placeholder="DE89..." class="w-full px-3 py-2 border rounded-lg font-mono"/></div>
      <div class="mt-3"><label class="text-xs font-semibold">BIC / SWIFT (optional)</label><input name="bank_bic" placeholder="COBADEFFXXX" class="w-full px-3 py-2 border rounded-lg font-mono"/></div>
      <div class="mt-3"><label class="text-xs font-semibold">Wie bist du auf uns aufmerksam geworden?</label>
        <select name="how_heard" class="w-full px-3 py-2 border rounded-lg"><option value="google">Google-Suche</option><option value="facebook">Facebook</option><option value="instagram">Instagram</option><option value="referral">Empfehlung</option><option value="linkedin">LinkedIn</option><option value="whatsapp">WhatsApp-Gruppe</option><option value="other">Andere</option></select>
      </div>
      <div class="mt-3"><label class="text-xs font-semibold">Warum willst du mit uns arbeiten? (optional)</label><textarea name="motivation" rows="2" class="w-full px-3 py-2 border rounded-lg"></textarea></div>
      <div class="mt-3"><label class="text-xs font-semibold">Anmerkungen (optional)</label><textarea name="additional_notes" rows="2" class="w-full px-3 py-2 border rounded-lg"></textarea></div>
    </div>

    <!-- STEP 12: Review + Submit -->
    <div x-show="step === 12">
      <h2 class="text-xl font-bold mb-2">✨ Fast geschafft!</h2>
      <p class="text-sm text-gray-700 mb-4">Durch Absenden bestätigst du, dass alle Angaben korrekt sind. Wir melden uns innerhalb von 48h.</p>
      <div class="bg-brand-light/40 border border-brand/30 rounded-xl p-4 text-sm space-y-1">
        <div>🌐 <b>fleckfrei.de</b> · 📞 <b>WhatsApp-Kontakt</b> beim Admin</div>
      </div>
    </div>

    <!-- Nav -->
    <div class="flex gap-2 mt-6">
      <button type="button" x-show="step > 1" @click="step--" class="px-5 py-2.5 border rounded-xl font-semibold">← Zurück</button>
      <button type="button" x-show="step < totalSteps" @click="nextStep()" class="flex-1 px-5 py-2.5 bg-brand text-white rounded-xl font-semibold hover:bg-brand-dark">Weiter →</button>
      <button type="submit" x-show="step === totalSteps" class="flex-1 px-5 py-2.5 bg-brand text-white rounded-xl font-bold hover:bg-brand-dark">✅ Bewerbung absenden</button>
    </div>
  </form>
  <div class="text-center text-xs text-gray-400 mt-6">© <?= date('Y') ?> Fleckfrei · <a href="https://fleckfrei.de" class="underline">fleckfrei.de</a></div>
</div>

<script>
function signupForm() {
  return {
    step: 1,
    totalSteps: 12,
    nextStep() {
      const cur = this.$el.querySelector(`[x-show="step === ${this.step}"]`);
      const reqs = cur.querySelectorAll('[required]');
      for (const r of reqs) if (!r.checkValidity()) { r.reportValidity(); return; }
      this.step++;
      window.scrollTo({top:0, behavior:'smooth'});
    }
  };
}
</script>
</body>
</html>
