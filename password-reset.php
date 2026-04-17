<?php
session_start();
require_once __DIR__ . '/includes/config.php';

$err = '';
$success = '';
$step = 'request'; // 'request' | 'reset' | 'done'

// Step 1: User submitted email → generate token + send via WhatsApp/Email
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['token'])) {
    $email = trim($_POST['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Ungültige E-Mail-Adresse.';
    } else {
        // Find user across all 3 tables
        $user = one("SELECT * FROM users WHERE email = ? LIMIT 1", [$email]);
        if ($user) {
            // Generate token (valid 1 hour)
            $token = bin2hex(random_bytes(24));
            q("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))", [$email, $token]);

            $resetUrl = 'https://app.fleckfrei.de/password-reset.php?token=' . $token;
            $msg = "Hallo,\n\nKlick auf diesen Link um dein Fleckfrei-Passwort zurückzusetzen:\n$resetUrl\n\nDer Link ist 1 Stunde gültig. Wenn du das nicht warst, ignoriere diese Nachricht.\n\n— Fleckfrei";

            // Try to send via Telegram (admin gets the link to forward) + log audit
            telegramNotify("🔐 Password-Reset für $email · Link: $resetUrl");
            audit('create', 'password_reset', 0, "Reset link generated for $email");
        }
    }
    // Always show success (don't leak which emails exist)
    header('Location: /login.php?reset_sent=1'); exit;
}

// Step 2: User clicked the email link → show reset form
$token = $_GET['token'] ?? $_POST['token'] ?? '';
if ($token) {
    $reset = one("SELECT * FROM password_resets WHERE token = ? AND used = 0 AND expires_at > NOW() LIMIT 1", [$token]);
    if (!$reset) {
        $err = 'Link ungültig oder abgelaufen. Bitte fordere einen neuen an.';
        $step = 'request';
    } else {
        $step = 'reset';
        // Step 3: User submitted new password
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['new_password'])) {
            $newPass = $_POST['new_password'];
            $confirm = $_POST['confirm_password'] ?? '';
            if (strlen($newPass) < 4) {
                $err = 'Passwort muss mindestens 4 Zeichen haben.';
            } elseif ($newPass !== $confirm) {
                $err = 'Passwörter stimmen nicht überein.';
            } else {
                $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
                // Update across all 3 tables (user might be in any)
                q("UPDATE customer SET password=? WHERE email=?", [$hash, $reset['email']]);
                q("UPDATE employee SET password=? WHERE email=?", [$hash, $reset['email']]);
                q("UPDATE admin    SET password=? WHERE email=?", [$hash, $reset['email']]);
                q("UPDATE password_resets SET used=1 WHERE pr_id=?", [$reset['pr_id']]);
                audit('update', 'password_reset', $reset['pr_id'], "Password reset completed for {$reset['email']}");
                telegramNotify("✅ Password-Reset abgeschlossen für {$reset['email']}");
                $step = 'done';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Passwort zurücksetzen — <?= SITE ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>tailwind.config={theme:{extend:{colors:{brand:'<?= BRAND ?>','brand-dark':'<?= BRAND_DARK ?>','brand-light':'<?= BRAND_LIGHT ?>'}}}}</script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
  <style>body{font-family:'Inter',system-ui,sans-serif}</style>
</head>
<body class="min-h-screen bg-gradient-to-br from-brand-light via-white to-brand/10 flex items-center justify-center p-4">
  <div class="w-full max-w-md">
    <div class="text-center mb-6">
      <div class="inline-flex items-center justify-center w-20 h-20 rounded-3xl bg-brand text-white text-3xl font-extrabold mb-4 shadow-xl shadow-brand/30"><?= LOGO_LETTER ?></div>
      <h1 class="text-4xl font-extrabold text-gray-900"><?= SITE ?></h1>
      <p class="text-gray-500 mt-1 text-sm">Passwort zurücksetzen</p>
    </div>

    <div class="bg-white rounded-3xl shadow-2xl shadow-brand/10 border border-gray-100 p-8">
      <?php if ($err): ?>
      <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-2xl mb-4 text-sm"><?= e($err) ?></div>
      <?php endif; ?>

      <?php if ($step === 'reset'): ?>
      <h2 class="font-bold text-gray-900 text-lg mb-4">Neues Passwort wählen</h2>
      <form method="POST" class="space-y-4">
        <input type="hidden" name="token" value="<?= e($token) ?>"/>
        <div>
          <label class="block text-xs font-bold text-gray-600 mb-1.5 uppercase tracking-wider">Neues Passwort</label>
          <input type="password" name="new_password" required minlength="4" autofocus class="w-full px-4 py-3 border-2 border-gray-100 bg-gray-50 rounded-2xl focus:ring-4 focus:ring-brand/10 focus:border-brand outline-none"/>
        </div>
        <div>
          <label class="block text-xs font-bold text-gray-600 mb-1.5 uppercase tracking-wider">Wiederholen</label>
          <input type="password" name="confirm_password" required minlength="4" class="w-full px-4 py-3 border-2 border-gray-100 bg-gray-50 rounded-2xl focus:ring-4 focus:ring-brand/10 focus:border-brand outline-none"/>
        </div>
        <button type="submit" class="w-full bg-brand hover:bg-brand-dark text-white font-bold py-3.5 rounded-2xl transition shadow-xl shadow-brand/20">
          Passwort speichern
        </button>
      </form>

      <?php elseif ($step === 'done'): ?>
      <div class="text-center py-6">
        <div class="w-16 h-16 mx-auto rounded-full bg-green-100 flex items-center justify-center mb-4">
          <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
        </div>
        <h2 class="text-xl font-bold text-gray-900 mb-2">Passwort geändert ✓</h2>
        <p class="text-sm text-gray-500 mb-4">Du kannst dich jetzt mit deinem neuen Passwort anmelden.</p>
        <a href="/login.php" class="inline-block px-6 py-3 bg-brand hover:bg-brand-dark text-white rounded-2xl font-bold">Zum Login</a>
      </div>

      <?php else: ?>
      <h2 class="font-bold text-gray-900 text-lg mb-2">Passwort vergessen?</h2>
      <p class="text-sm text-gray-500 mb-4">Gib deine E-Mail ein und wir senden dir einen Reset-Link.</p>
      <form method="POST" class="space-y-4">
        <div>
          <label class="block text-xs font-bold text-gray-600 mb-1.5 uppercase tracking-wider">E-Mail</label>
          <input type="email" name="email" required autofocus class="w-full px-4 py-3 border-2 border-gray-100 bg-gray-50 rounded-2xl focus:ring-4 focus:ring-brand/10 focus:border-brand outline-none" placeholder="deine@email.de"/>
        </div>
        <button type="submit" class="w-full bg-brand hover:bg-brand-dark text-white font-bold py-3.5 rounded-2xl transition shadow-xl shadow-brand/20">
          Reset-Link senden
        </button>
        <a href="/login.php" class="block text-center text-sm text-gray-500 hover:text-brand">← Zurück zum Login</a>
      </form>
      <?php endif; ?>
    </div>

    <p class="text-center text-[11px] text-gray-400 mt-6">
      Brauchst du Hilfe?
      <a href="<?= CONTACT_WHATSAPP_URL ?>" target="_blank" class="text-green-600 font-semibold">WhatsApp</a> ·
      <a href="https://t.me/<?= str_replace('@', '', CONTACT_TELEGRAM) ?>" class="text-blue-600 font-semibold">Telegram</a> ·
      <a href="mailto:<?= CONTACT_EMAIL ?>" class="text-brand font-semibold"><?= CONTACT_EMAIL ?></a>
    </p>
  </div>
</body>
</html>
