<?php
// Layout v2 — Helpling-inspired top-navigation layout for customer area
// Used only by /customer/v2/* pages. Leaves /customer/ (v1) untouched.
// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
$user = me();
$customer = $customer ?? (function_exists('one') ? one("SELECT * FROM customer WHERE customer_id=?", [$user['id']]) : null);
$ctype = $customer['customer_type'] ?? 'Regular';
$isHost = in_array($ctype, ['Airbnb', 'Host'], true);
$page = $page ?? '';
?>
<!DOCTYPE html>
<html lang="de" class="h-full">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover"/>
  <title><?= e($title ?? 'Mein Konto') ?> — <?= SITE ?></title>
  <link rel="manifest" href="/manifest.php"/>
  <meta name="theme-color" content="<?= BRAND ?>"/>
  <meta name="apple-mobile-web-app-capable" content="yes"/>
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent"/>
  <meta name="mobile-web-app-capable" content="yes"/>
  <link rel="apple-touch-icon" href="/icons/icon.php?s=180"/>
  <link rel="apple-touch-icon" sizes="180x180" href="/icons/icon.php?s=180"/>
  <meta name="apple-mobile-web-app-title" content="<?= SITE ?>"/>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>tailwind.config={theme:{extend:{colors:{brand:'<?= BRAND ?>','brand-dark':'<?= BRAND_DARK ?>','brand-light':'<?= BRAND_LIGHT ?>'},fontFamily:{sans:['Inter','system-ui','sans-serif']}}}}</script>
  <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
  <style>
    body { font-family: 'Inter', system-ui, sans-serif; -webkit-font-smoothing: antialiased; color: #1a1a1a; font-size: 14px; background: #f5f6f8; }
    [x-cloak] { display: none !important; }
    /* Header brand gradient */
    .nav-brand-bg { background: linear-gradient(135deg, <?= BRAND ?> 0%, <?= BRAND_DARK ?> 100%); }
    /* Nav link underline on active (helpling-style) */
    .nav-link { position: relative; padding: 22px 4px; color: rgba(255,255,255,0.85); font-weight: 500; font-size: 14px; transition: color 0.15s; }
    .nav-link:hover { color: #fff; }
    .nav-link.active { color: #fff; font-weight: 600; }
    .nav-link.active::after { content: ''; position: absolute; left: 0; right: 0; bottom: 0; height: 3px; background: #fff; border-radius: 2px 2px 0 0; }
    /* CTA outline button */
    .btn-outline-white { border: 1.5px solid rgba(255,255,255,0.7); color: #fff; padding: 8px 18px; border-radius: 8px; font-weight: 500; font-size: 14px; transition: all 0.15s; }
    .btn-outline-white:hover { background: #fff; color: <?= BRAND ?>; }
    /* Tab underline (secondary) */
    .tab-underline { padding: 14px 2px; color: #6b7280; font-weight: 500; border-bottom: 3px solid transparent; transition: all 0.15s; }
    .tab-underline.active { color: <?= BRAND ?>; border-bottom-color: <?= BRAND ?>; font-weight: 600; }
    .tab-underline:hover:not(.active) { color: #374151; }
    /* Card */
    .card-elev { background: #fff; border: 1px solid #e9ebef; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
    .card-elev:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.06); }
    /* Mobile touch targets */
    @media (max-width: 768px) {
      button, a.px-3, a.px-4 { min-height: 44px; }
      input, select, textarea { min-height: 44px; font-size: 16px !important; }
    }
    /* iOS safe areas */
    body { padding-top: env(safe-area-inset-top); padding-bottom: env(safe-area-inset-bottom); }
  </style>
</head>
<body class="h-full" x-data="{ mobileMenu: false, userMenu: false }">

<!-- ============ TOP NAVIGATION ============ -->
<header class="nav-brand-bg shadow-md sticky top-0 z-40">
  <div class="max-w-7xl mx-auto px-4 sm:px-6">
    <div class="flex items-center justify-between h-[68px]">

      <!-- Logo -->
      <a href="/customer/v2/" class="flex items-center gap-2 text-white font-extrabold text-xl tracking-tight">
        <span class="text-2xl">✨</span>
        <span class="hidden sm:inline"><?= SITE ?></span>
      </a>

      <!-- Desktop nav -->
      <nav class="hidden lg:flex items-center gap-8">
        <a href="/customer/v2/"          class="nav-link <?= $page==='dashboard' ? 'active' : '' ?>">Home</a>
        <a href="/customer/v2/jobs.php"  class="nav-link <?= $page==='jobs' ? 'active' : '' ?>">Meine Termine</a>
        <a href="/customer/v2/invoices.php" class="nav-link <?= $page==='invoices' ? 'active' : '' ?>">Rechnungen</a>
        <?php if ($isHost): ?>
          <a href="/customer/v2/services.php" class="nav-link <?= $page==='services' ? 'active' : '' ?>">Services</a>
          <a href="/customer/v2/calendar.php" class="nav-link <?= $page==='calendar' ? 'active' : '' ?>">Kalender</a>
        <?php endif; ?>
        <a href="/customer/v2/referral.php" class="nav-link <?= $page==='referral' ? 'active' : '' ?>">Weiterempfehlen</a>
        <a href="/customer/v2/messages.php" class="nav-link <?= $page==='messages' ? 'active' : '' ?>">Chat</a>
        <a href="/customer/v2/help.php"     class="nav-link <?= $page==='help' ? 'active' : '' ?>">Hilfe</a>
      </nav>

      <!-- Right side: CTA + user -->
      <div class="flex items-center gap-3">
        <a href="/customer/v2/booking.php" class="hidden sm:inline-block btn-outline-white">Jetzt buchen</a>

        <!-- User dropdown -->
        <div class="relative hidden lg:block" @click.away="userMenu = false">
          <button @click="userMenu = !userMenu" class="flex items-center gap-2 text-white hover:bg-white/10 rounded-full px-2 py-1.5 transition">
            <div class="w-9 h-9 rounded-full bg-white/20 flex items-center justify-center font-semibold text-white">
              <?= strtoupper(substr($customer['name'] ?? 'M', 0, 1)) ?>
            </div>
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
          </button>
          <div x-show="userMenu" x-cloak x-transition class="absolute right-0 mt-2 w-56 bg-white rounded-xl shadow-lg border overflow-hidden">
            <div class="px-4 py-3 border-b bg-gray-50">
              <div class="font-semibold text-gray-900 text-sm"><?= e($customer['name'] ?? '') ?></div>
              <div class="text-xs text-gray-500 truncate"><?= e($customer['email'] ?? '') ?></div>
            </div>
            <a href="/customer/v2/profile.php" class="flex items-center gap-3 px-4 py-3 text-sm text-gray-700 hover:bg-gray-50">
              <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
              Kontoeinstellungen
            </a>
            <a href="/logout.php" class="flex items-center gap-3 px-4 py-3 text-sm text-red-600 hover:bg-red-50 border-t">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
              Ausloggen
            </a>
          </div>
        </div>

        <!-- Mobile hamburger -->
        <button @click="mobileMenu = !mobileMenu" class="lg:hidden text-white p-2">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
      </div>
    </div>
  </div>

  <!-- Mobile menu drawer -->
  <div x-show="mobileMenu" x-cloak x-transition class="lg:hidden border-t border-white/10">
    <div class="px-4 py-2 space-y-1">
      <?php
      $mobileItems = [
          ['/customer/v2/', 'Home', 'dashboard'],
          ['/customer/v2/jobs.php', 'Meine Termine', 'jobs'],
          ['/customer/v2/invoices.php', 'Rechnungen', 'invoices'],
      ];
      if ($isHost) {
          $mobileItems[] = ['/customer/v2/services.php', 'Services', 'services'];
          $mobileItems[] = ['/customer/v2/calendar.php', 'Kalender', 'calendar'];
      }
      $mobileItems = array_merge($mobileItems, [
          ['/customer/v2/referral.php', 'Weiterempfehlen', 'referral'],
          ['/customer/v2/messages.php', 'Chat', 'messages'],
          ['/customer/v2/help.php', 'Hilfe', 'help'],
          ['/customer/v2/profile.php', 'Kontoeinstellungen', 'profile'],
          ['/customer/v2/booking.php', 'Jetzt buchen', 'booking'],
          ['/logout.php', 'Ausloggen', 'logout'],
      ]);
      foreach ($mobileItems as [$href, $label, $pg]):
      ?>
      <a href="<?= $href ?>" class="block px-3 py-3 rounded-lg text-white font-medium <?= $page===$pg ? 'bg-white/20' : 'hover:bg-white/10' ?>"><?= $label ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</header>

<!-- ============ MAIN CONTENT ============ -->
<main class="max-w-7xl mx-auto px-4 sm:px-6 py-8">
