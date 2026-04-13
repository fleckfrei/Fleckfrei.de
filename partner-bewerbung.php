<?php
/**
 * Public Partner-Bewerbungs-Form
 * URL: /partner-bewerbung.php
 * Kein Login nötig. Speichert in partner_applications + sendet Email.
 */
require_once __DIR__ . '/includes/config.php';

$success = false;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validation
        $required = ['full_name', 'email', 'phone'];
        foreach ($required as $f) {
            if (empty($_POST[$f])) throw new Exception("Bitte $f ausfüllen");
        }
        if (!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Ungültige Email-Adresse');
        }
        // Honeypot
        if (!empty($_POST['website_hp'])) throw new Exception('Spam erkannt');

        q("INSERT INTO partner_applications (full_name, email, phone, birth_date, street, postal_code, city, country,
            experience, motivation, desired_role, contract_type, has_gewerbe, has_haftpflicht, has_fuehrungszeugnis,
            ip, user_agent)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)", [
            substr(trim($_POST['full_name']), 0, 255),
            substr(trim($_POST['email']), 0, 255),
            substr(trim($_POST['phone']), 0, 50),
            !empty($_POST['birth_date']) ? $_POST['birth_date'] : null,
            substr($_POST['street'] ?? '', 0, 255),
            substr($_POST['postal_code'] ?? '', 0, 20),
            substr($_POST['city'] ?? '', 0, 100),
            substr($_POST['country'] ?? 'Deutschland', 0, 100),
            substr($_POST['experience'] ?? '', 0, 5000),
            substr($_POST['motivation'] ?? '', 0, 5000),
            $_POST['desired_role'] ?? 'Cleaner',
            $_POST['contract_type'] ?? 'Freelance',
            !empty($_POST['has_gewerbe']) ? 1 : 0,
            !empty($_POST['has_haftpflicht']) ? 1 : 0,
            !empty($_POST['has_fuehrungszeugnis']) ? 1 : 0,
            substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);

        // Notify
        try {
            require_once __DIR__ . '/includes/email.php';
            $body = "<h2>Neue Partner-Bewerbung</h2>"
                 . "<p><strong>Name:</strong> " . htmlspecialchars($_POST['full_name']) . "<br>"
                 . "<strong>Email:</strong> " . htmlspecialchars($_POST['email']) . "<br>"
                 . "<strong>Telefon:</strong> " . htmlspecialchars($_POST['phone']) . "<br>"
                 . "<strong>Rolle:</strong> " . htmlspecialchars($_POST['desired_role'] ?? '-') . " · "
                 . htmlspecialchars($_POST['contract_type'] ?? '-') . "</p>"
                 . "<p><strong>Nachricht:</strong><br>" . nl2br(htmlspecialchars($_POST['motivation'] ?? '')) . "</p>"
                 . "<hr><p style='font-size:12px;color:#888'>Bewerbung verwalten: <a href='https://app.fleckfrei.de/admin/partner-applications.php'>Admin-Panel</a></p>";
            @mail(CONTACT_EMAIL, '[Fleckfrei] Neue Partner-Bewerbung: ' . $_POST['full_name'],
                  $body,
                  "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: noreply@fleckfrei.de\r\nReply-To: " . $_POST['email']);
        } catch (Exception $e) {}

        $success = true;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Partner werden bei <?= e(SITE) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config={theme:{extend:{colors:{brand:'<?= BRAND ?>','brand-dark':'<?= BRAND_DARK ?>'}}}}</script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
<style>body{font-family:'Inter',system-ui,sans-serif}</style>
</head>
<body class="bg-gray-50 min-h-screen py-8 px-4">
<div class="max-w-2xl mx-auto">

  <div class="text-center mb-8">
    <div class="inline-block w-16 h-16 rounded-2xl bg-brand text-white flex items-center justify-center text-3xl font-bold mb-3"><?= e(LOGO_LETTER) ?></div>
    <h1 class="text-3xl font-bold text-gray-900">Werde <?= e(SITE) ?>-Partner</h1>
    <p class="text-gray-600 mt-2">Reinige flexibel, verdiene fair, eigene Zeiteinteilung.</p>
  </div>

<?php if ($success): ?>
  <div class="bg-white rounded-2xl shadow-lg p-8 text-center">
    <div class="text-6xl mb-4">✅</div>
    <h2 class="text-2xl font-bold mb-3">Bewerbung eingegangen!</h2>
    <p class="text-gray-600 mb-4">Vielen Dank für dein Interesse. Wir prüfen deine Bewerbung und melden uns innerhalb von 48 Stunden bei dir per Email oder Telefon.</p>
    <p class="text-sm text-gray-500">Bei Rückfragen: <a href="mailto:<?= e(CONTACT_EMAIL) ?>" class="text-brand"><?= e(CONTACT_EMAIL) ?></a></p>
  </div>
<?php else: ?>

  <?php if ($error): ?>
  <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-xl mb-4">⚠ <?= e($error) ?></div>
  <?php endif; ?>

  <form method="POST" class="bg-white rounded-2xl shadow-lg p-6 sm:p-8 space-y-5">
    <input type="text" name="website_hp" style="display:none" tabindex="-1" autocomplete="off"/>

    <div>
      <h2 class="text-lg font-semibold mb-3 text-gray-900">Persönliche Daten</h2>
      <div class="grid sm:grid-cols-2 gap-3">
        <div class="sm:col-span-2"><label class="block text-xs font-medium text-gray-500 mb-1">Voller Name *</label><input name="full_name" required class="w-full px-3 py-2.5 border rounded-xl focus:ring-2 focus:ring-brand/30"/></div>
        <div><label class="block text-xs font-medium text-gray-500 mb-1">Email *</label><input type="email" name="email" required class="w-full px-3 py-2.5 border rounded-xl"/></div>
        <div><label class="block text-xs font-medium text-gray-500 mb-1">Telefon *</label><input name="phone" required placeholder="+49 ..." class="w-full px-3 py-2.5 border rounded-xl"/></div>
        <div><label class="block text-xs font-medium text-gray-500 mb-1">Geburtsdatum</label><input type="date" name="birth_date" class="w-full px-3 py-2.5 border rounded-xl"/></div>
        <div><label class="block text-xs font-medium text-gray-500 mb-1">Wunsch-Vertrag</label>
          <select name="contract_type" class="w-full px-3 py-2.5 border rounded-xl">
            <option value="Freelance">Freelance / Selbstständig</option>
            <option value="Minijob">Minijob (520€)</option>
            <option value="Festanstellung">Festanstellung</option>
            <option value="Subunternehmer">Subunternehmer</option>
          </select>
        </div>
      </div>
    </div>

    <div>
      <h2 class="text-lg font-semibold mb-3 text-gray-900">Adresse</h2>
      <div class="grid sm:grid-cols-3 gap-3">
        <div class="sm:col-span-3"><label class="block text-xs font-medium text-gray-500 mb-1">Straße + Nr</label><input name="street" class="w-full px-3 py-2.5 border rounded-xl"/></div>
        <div><label class="block text-xs font-medium text-gray-500 mb-1">PLZ</label><input name="postal_code" class="w-full px-3 py-2.5 border rounded-xl"/></div>
        <div class="sm:col-span-2"><label class="block text-xs font-medium text-gray-500 mb-1">Stadt</label><input name="city" placeholder="Berlin" class="w-full px-3 py-2.5 border rounded-xl"/></div>
      </div>
    </div>

    <div>
      <h2 class="text-lg font-semibold mb-3 text-gray-900">Über dich</h2>
      <label class="block text-xs font-medium text-gray-500 mb-1">Erfahrung im Reinigungs-/Service-Bereich</label>
      <textarea name="experience" rows="3" placeholder="z.B. 3 Jahre Hotel-Reinigung, Erfahrung mit Airbnb..." class="w-full px-3 py-2.5 border rounded-xl mb-3"></textarea>

      <label class="block text-xs font-medium text-gray-500 mb-1">Warum möchtest du <?= e(SITE) ?>-Partner werden?</label>
      <textarea name="motivation" rows="3" class="w-full px-3 py-2.5 border rounded-xl"></textarea>
    </div>

    <div>
      <h2 class="text-lg font-semibold mb-3 text-gray-900">Dokumente vorhanden?</h2>
      <div class="space-y-2">
        <label class="flex items-center gap-3 p-3 bg-gray-50 rounded-lg cursor-pointer hover:bg-gray-100">
          <input type="checkbox" name="has_gewerbe" value="1" class="rounded"/>
          <span class="text-sm">Gewerbeanmeldung (für Freelance/Subunternehmer)</span>
        </label>
        <label class="flex items-center gap-3 p-3 bg-gray-50 rounded-lg cursor-pointer hover:bg-gray-100">
          <input type="checkbox" name="has_haftpflicht" value="1" class="rounded"/>
          <span class="text-sm">Haftpflicht-Versicherung</span>
        </label>
        <label class="flex items-center gap-3 p-3 bg-gray-50 rounded-lg cursor-pointer hover:bg-gray-100">
          <input type="checkbox" name="has_fuehrungszeugnis" value="1" class="rounded"/>
          <span class="text-sm">Erweitertes Führungszeugnis (max. 6 Monate alt)</span>
        </label>
      </div>
      <p class="text-xs text-gray-500 mt-2">Keine Dokumente? Kein Problem — wir helfen beim Onboarding.</p>
    </div>

    <button type="submit" class="w-full px-6 py-3.5 bg-brand text-white rounded-xl font-semibold text-base hover:bg-brand-dark transition shadow-lg">
      📩 Bewerbung absenden
    </button>

    <p class="text-xs text-gray-400 text-center">Mit dem Absenden stimmst du der Verarbeitung deiner Daten gemäß DSGVO zu. Wir melden uns binnen 48h.</p>
  </form>
<?php endif; ?>

  <div class="text-center mt-6 text-xs text-gray-400">
    <a href="https://<?= e(SITE_DOMAIN) ?>" class="hover:text-brand"><?= e(SITE) ?></a> · <?= e(CONTACT_EMAIL) ?>
  </div>
</div>
</body>
</html>
