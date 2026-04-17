<?php
session_start();
require_once __DIR__ . '/includes/config.php';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Rate limiting: max 5 attempts per 15 min per IP
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $lockFile = sys_get_temp_dir() . '/fleckfrei_login_' . md5($ip);
    $attempts = 0;
    if (file_exists($lockFile)) {
        $data = json_decode(file_get_contents($lockFile), true);
        if ($data && time() - ($data['time'] ?? 0) < 900) {
            $attempts = $data['count'] ?? 0;
        }
    }
    if ($attempts >= 5) {
        $err = 'Zu viele Anmeldeversuche. Bitte warte 15 Minuten.';
    }
    $email = trim($_POST['email'] ?? '');
    $pass = trim($_POST['password'] ?? '');
    // Check for GDPR-deleted customer accounts BEFORE auth
    $deletedCustomer = one("SELECT is_cancel, deleted_at FROM customer WHERE email=? LIMIT 1", [$email]);
    $isAccountDeleted = $deletedCustomer && !empty($deletedCustomer['is_cancel']) && !empty($deletedCustomer['deleted_at']);

    $user = $isAccountDeleted ? null : one("SELECT * FROM users WHERE email = ? LIMIT 1", [$email]);
    if ($user) {
        $t = $user['type'];
        $allowedTables = ['admin'=>'admin','customer'=>'customer','employee'=>'employee'];
        $idMap = ['admin'=>'admin_id','customer'=>'customer_id','employee'=>'emp_id'];
        $id = $idMap[$t] ?? null;
        if ($id && isset($allowedTables[$t])) {
            $row = one("SELECT * FROM `$t` WHERE email = ? AND status = 1 LIMIT 1", [$email]);
            if ($row) {
                $authenticated = false;
                $storedPass = $row['password'] ?? '';

                // Check bcrypt hash first, then plaintext fallback for migration
                if (password_verify($pass, $storedPass)) {
                    $authenticated = true;
                    // Re-hash if cost factor changed
                    if (password_needs_rehash($storedPass, PASSWORD_BCRYPT, ['cost' => 12])) {
                        q("UPDATE `$t` SET password=? WHERE $id=?", [password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]), $row[$id]]);
                    }
                } elseif ($storedPass === $pass) {
                    // Plaintext match — migrate to bcrypt
                    $authenticated = true;
                    q("UPDATE `$t` SET password=? WHERE $id=?", [password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]), $row[$id]]);
                }

                if ($authenticated) {
                    @unlink($lockFile); // Reset rate limit on success
                    session_regenerate_id(true); // Prevent session fixation
                    $_SESSION['uid'] = $row[$id];
                    $_SESSION['uemail'] = $email;
                    $_SESSION['uname'] = $row['name'];
                    $_SESSION['utype'] = $t;
                    header("Location: /$t/");
                    exit;
                }
            }
        }
    }
    // Track failed attempt
    file_put_contents($lockFile, json_encode(['count' => $attempts + 1, 'time' => time()]));
    $err = $isAccountDeleted
        ? 'Dieses Konto wurde gelöscht. Für eine Wiederherstellung kontaktieren Sie bitte info@fleckfrei.de.'
        : 'Falsche E-Mail oder Passwort.';
}

// Show "deleted" info banner if user just completed delete flow
$deletedBanner = !empty($_GET['deleted']);
$resetSent = !empty($_GET['reset_sent']);
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Anmelden — <?= SITE ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
  <script>tailwind.config={theme:{extend:{colors:{brand:'<?= BRAND ?>','brand-dark':'<?= BRAND_DARK ?>','brand-light':'<?= BRAND_LIGHT ?>'}}}}</script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
  <style>
    body { font-family: 'Inter', system-ui, sans-serif; }
    [x-cloak] { display: none !important; }
    .glass { backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); }
    .blob {
      position: absolute;
      border-radius: 50%;
      filter: blur(80px);
      opacity: 0.5;
      pointer-events: none;
    }
    @keyframes float {
      0%, 100% { transform: translateY(0); }
      50% { transform: translateY(-20px); }
    }
    .blob-1 { animation: float 8s ease-in-out infinite; }
    .blob-2 { animation: float 10s ease-in-out infinite reverse; }
  </style>
</head>
<body class="min-h-screen relative overflow-hidden bg-gradient-to-br from-brand-light via-white to-brand/10">

<!-- Decorative background blobs -->
<div class="blob blob-1 w-96 h-96 bg-brand/30 -top-20 -left-20"></div>
<div class="blob blob-2 w-80 h-80 bg-emerald-300/40 -bottom-20 -right-20"></div>
<div class="blob w-60 h-60 bg-amber-200/30 top-1/3 right-1/4"></div>

<div class="relative min-h-screen flex flex-col items-center justify-center p-4" x-data="{ tab: 'login' }">

  <!-- Logo + Brand -->
  <div class="text-center mb-6 relative z-10">
    <div class="inline-flex items-center justify-center w-20 h-20 rounded-3xl bg-brand text-white text-3xl font-extrabold mb-4 shadow-xl shadow-brand/30">
      F
    </div>
    <h1 class="text-4xl font-extrabold text-gray-900 tracking-tight"><?= SITE ?></h1>
    <p class="text-gray-500 mt-1 text-sm"><?= e(siteText('login.tagline', 'Berlin · Reinigung neu gedacht')) ?></p>
  </div>

  <!-- Login Card -->
  <div class="w-full max-w-md relative z-10">
    <div class="bg-white/90 glass rounded-3xl shadow-2xl shadow-brand/10 border border-white/60 p-8 sm:p-10">

      <!-- Tab switcher: Login / Reset -->
      <div class="flex gap-1 p-1 bg-gray-100 rounded-2xl mb-6">
        <button @click="tab = 'login'" :class="tab === 'login' ? 'bg-white shadow text-brand' : 'text-gray-500'" class="flex-1 py-2 rounded-xl text-sm font-semibold transition">
          Anmelden
        </button>
        <button @click="tab = 'reset'" :class="tab === 'reset' ? 'bg-white shadow text-brand' : 'text-gray-500'" class="flex-1 py-2 rounded-xl text-sm font-semibold transition">
          Passwort vergessen?
        </button>
      </div>

      <!-- Banners -->
      <?php if ($deletedBanner): ?>
      <div class="bg-blue-50 border border-blue-200 text-blue-800 px-4 py-3 rounded-2xl mb-4 text-sm flex items-start gap-2">
        <span>✓</span>
        <span>Dein Konto wurde erfolgreich gelöscht. Bis bald.</span>
      </div>
      <?php endif; ?>
      <?php if ($resetSent): ?>
      <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-2xl mb-4 text-sm flex items-start gap-2">
        <span>✉</span>
        <span>Wenn die E-Mail bei uns registriert ist, hast du gerade einen Reset-Link erhalten. Check WhatsApp + E-Mail.</span>
      </div>
      <?php endif; ?>
      <?php if ($err): ?>
      <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-2xl mb-4 text-sm flex items-start gap-2">
        <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <span><?= e($err) ?></span>
      </div>
      <?php endif; ?>

      <!-- LOGIN FORM -->
      <form x-show="tab === 'login'" method="POST" class="space-y-4">
        <div>
          <label class="block text-xs font-bold text-gray-600 mb-1.5 uppercase tracking-wider">E-Mail</label>
          <div class="relative">
            <svg class="w-5 h-5 absolute left-4 top-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            <input type="email" name="email" required autofocus class="w-full pl-12 pr-4 py-3 border-2 border-gray-100 bg-gray-50 rounded-2xl focus:ring-4 focus:ring-brand/10 focus:border-brand focus:bg-white outline-none transition" placeholder="deine@email.de"/>
          </div>
        </div>
        <div>
          <label class="block text-xs font-bold text-gray-600 mb-1.5 uppercase tracking-wider">Passwort</label>
          <div class="relative" x-data="{ show: false }">
            <svg class="w-5 h-5 absolute left-4 top-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
            <input :type="show ? 'text' : 'password'" name="password" required class="w-full pl-12 pr-12 py-3 border-2 border-gray-100 bg-gray-50 rounded-2xl focus:ring-4 focus:ring-brand/10 focus:border-brand focus:bg-white outline-none transition"/>
            <button type="button" @click="show = !show" class="absolute right-3 top-3.5 text-gray-400 hover:text-brand">
              <svg x-show="!show" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
              <svg x-show="show" x-cloak class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.542 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
            </button>
          </div>
        </div>
        <button type="submit" class="w-full bg-brand hover:bg-brand-dark text-white font-bold py-3.5 rounded-2xl transition shadow-xl shadow-brand/20 flex items-center justify-center gap-2">
          Anmelden
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
        </button>

        <!-- Divider -->
        <div class="flex items-center gap-3 my-2">
          <div class="flex-1 h-px bg-gray-200"></div>
          <span class="text-xs text-gray-400 font-medium">oder</span>
          <div class="flex-1 h-px bg-gray-200"></div>
        </div>

        <!-- Sign in with Google -->
        <a href="/api/google-login.php" class="w-full flex items-center justify-center gap-3 py-3 border-2 border-gray-200 hover:border-brand hover:bg-brand/5 rounded-2xl transition font-semibold text-gray-700 text-sm">
          <svg class="w-5 h-5" viewBox="0 0 24 24"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18A10.96 10.96 0 0 0 1 12c0 1.77.42 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/></svg>
          Mit Google anmelden
        </a>
      </form>

      <!-- PASSWORD RESET FORM -->
      <form x-show="tab === 'reset'" x-cloak method="POST" action="/password-reset.php" class="space-y-4">
        <p class="text-sm text-gray-600">Gib deine E-Mail ein und wir senden dir einen Reset-Link per WhatsApp + E-Mail.</p>
        <div>
          <label class="block text-xs font-bold text-gray-600 mb-1.5 uppercase tracking-wider">E-Mail</label>
          <div class="relative">
            <svg class="w-5 h-5 absolute left-4 top-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            <input type="email" name="email" required class="w-full pl-12 pr-4 py-3 border-2 border-gray-100 bg-gray-50 rounded-2xl focus:ring-4 focus:ring-brand/10 focus:border-brand focus:bg-white outline-none transition" placeholder="deine@email.de"/>
          </div>
        </div>
        <button type="submit" class="w-full bg-brand hover:bg-brand-dark text-white font-bold py-3.5 rounded-2xl transition shadow-xl shadow-brand/20">
          Reset-Link senden
        </button>
        <button type="button" @click="tab = 'login'" class="w-full text-sm text-gray-500 hover:text-brand">← Zurück zum Login</button>
      </form>
    </div>

    <!-- Contact buttons below card — only shown when number configured -->
    <div class="mt-6 flex items-center justify-center gap-3 flex-wrap">
      <a href="<?= CONTACT_WHATSAPP_URL ?>" target="_blank" class="group flex items-center gap-2 px-4 py-2.5 bg-white/80 glass border border-white shadow-md hover:shadow-lg rounded-2xl text-sm font-semibold text-gray-700 hover:text-green-600 transition">
        <div class="w-7 h-7 rounded-full bg-green-500 text-white flex items-center justify-center group-hover:scale-110 transition">
          <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413"/></svg>
        </div>
        WhatsApp
      </a>
      <a href="https://t.me/<?= str_replace('@', '', CONTACT_TELEGRAM) ?>" target="_blank" class="group flex items-center gap-2 px-4 py-2.5 bg-white/80 glass border border-white shadow-md hover:shadow-lg rounded-2xl text-sm font-semibold text-gray-700 hover:text-blue-600 transition">
        <div class="w-7 h-7 rounded-full bg-blue-500 text-white flex items-center justify-center group-hover:scale-110 transition">
          <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg>
        </div>
        Telegram
      </a>
      <a href="mailto:<?= CONTACT_EMAIL ?>" class="group flex items-center gap-2 px-4 py-2.5 bg-white/80 glass border border-white shadow-md hover:shadow-lg rounded-2xl text-sm font-semibold text-gray-700 hover:text-brand transition">
        <div class="w-7 h-7 rounded-full bg-brand text-white flex items-center justify-center group-hover:scale-110 transition">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
        </div>
        E-Mail
      </a>
      <?php if (CONTACT_PHONE): ?>
      <a href="tel:<?= CONTACT_PHONE ?>" class="group flex items-center gap-2 px-4 py-2.5 bg-white/80 glass border border-white shadow-md hover:shadow-lg rounded-2xl text-sm font-semibold text-gray-700 hover:text-brand transition">
        <div class="w-7 h-7 rounded-full bg-brand text-white flex items-center justify-center group-hover:scale-110 transition">
          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
        </div>
        Anruf
      </a>
      <?php endif; ?>
    </div>

    <!-- Footer info -->
    <p class="text-center text-[11px] text-gray-400 mt-6">
      🇩🇪 Made in Berlin · 1 % unserer Einnahmen → Rumänien-Hilfe 🇷🇴<br/>
      <a href="https://fleckfrei.de/agb" class="hover:text-brand">AGB</a> ·
      <a href="https://fleckfrei.de/datenschutz" class="hover:text-brand">Datenschutz</a> ·
      <a href="https://fleckfrei.de/impressum" class="hover:text-brand">Impressum</a>
    </p>
  </div>
</div>

</body>
</html>
