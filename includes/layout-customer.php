<?php
// Layout v2 — Helpling-inspired top-navigation layout for customer area
// Used only by /customer/* pages. Leaves /customer/ (v1) untouched.
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
    body { font-family: 'Inter', system-ui, sans-serif; -webkit-font-smoothing: antialiased; color: #0f172a; font-size: 14px; background: #f5f6f8; }
    [x-cloak] { display: none !important; }
    /* === Global contrast fixes (WCAG AA compliant) === */
    /* Darker gray text for all utility classes */
    .text-gray-400 { color: #475569 !important; }  /* was too light */
    .text-gray-500 { color: #334155 !important; }
    .text-gray-600 { color: #1e293b !important; }
    .text-gray-700 { color: #0f172a !important; }
    /* Keep true-light grays only for disabled/placeholder */
    input::placeholder, textarea::placeholder { color: #94a3b8 !important; }
    /* Labels darker + bolder */
    label { color: #0f172a; font-weight: 500; }
    /* Headings */
    h1, h2, h3, h4, h5 { color: #0f172a; }
    /* Links with more contrast — aber NICHT bei farbigen Buttons (bg-brand, bg-green, etc.) */
    a:not(.nav-link):not(.btn-outline-white):not([class*="bg-"]):not([class*="text-white"]) { color: <?= BRAND_DARK ?>; }
    a:not(.nav-link):not([class*="bg-"]):not([class*="text-white"]):hover { color: <?= BRAND ?>; }
    /* Button-Links: weiße Schrift darf nicht überschrieben werden */
    a.text-white, button.text-white, a[class*="bg-brand"], a[class*="bg-green"], a[class*="bg-blue"] { color: #fff !important; }
    a.text-white:hover, button.text-white:hover { color: #fff !important; }
    /* Header brand gradient */
    .nav-brand-bg { background: linear-gradient(135deg, <?= BRAND ?> 0%, <?= BRAND_DARK ?> 100%); }
    /* Nav link underline on active (helpling-style) */
    .nav-link { position: relative; padding: 22px 4px; color: rgba(255,255,255,0.95); font-weight: 500; font-size: 14px; transition: color 0.15s; }
    .nav-link:hover { color: #fff; }
    .nav-link.active { color: #fff; font-weight: 700; }
    .nav-link.active::after { content: ''; position: absolute; left: 0; right: 0; bottom: 0; height: 3px; background: #fff; border-radius: 2px 2px 0 0; }
    /* CTA outline button */
    .btn-outline-white { border: 1.5px solid rgba(255,255,255,0.9); color: #fff; padding: 8px 18px; border-radius: 8px; font-weight: 600; font-size: 14px; transition: all 0.15s; }
    .btn-outline-white:hover { background: #fff; color: <?= BRAND ?>; }
    /* Tab underline (secondary) */
    .tab-underline { padding: 14px 2px; color: #334155; font-weight: 500; border-bottom: 3px solid transparent; transition: all 0.15s; }
    .tab-underline.active { color: <?= BRAND_DARK ?>; border-bottom-color: <?= BRAND ?>; font-weight: 700; }
    .tab-underline:hover:not(.active) { color: #0f172a; }
    /* Card */
    .card-elev { background: #fff; border: 1px solid #cbd5e1; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
    .card-elev:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
    /* Accessibility: focus states */
    input:focus-visible, select:focus-visible, textarea:focus-visible, button:focus-visible, a:focus-visible {
      outline: 2px solid <?= BRAND ?>;
      outline-offset: 2px;
      border-radius: 4px;
    }
    /* Required field indicator */
    label:has(+ input[required])::after,
    label:has(+ textarea[required])::after,
    label:has(+ select[required])::after { content: ' *'; color: #ef4444; }
    /* Error state */
    .field-error input, .field-error select, .field-error textarea { border-color: #ef4444 !important; }
    .field-error .error-msg { display: block; color: #ef4444; font-size: 12px; margin-top: 4px; }
    .error-msg { display: none; }
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
      <a href="/customer/" class="flex items-center gap-2 text-white font-extrabold text-xl tracking-tight">
        <span class="text-2xl">✨</span>
        <span class="hidden sm:inline"><?= SITE ?></span>
      </a>

      <!-- Desktop nav -->
      <nav class="hidden lg:flex items-center gap-8">
        <a href="/customer/"          class="nav-link <?= $page==='dashboard' ? 'active' : '' ?>">Home</a>
        <a href="/customer/calendar.php" class="nav-link <?= $page==='calendar' || $page==='jobs' ? 'active' : '' ?>">Termine</a>
        <a href="/customer/invoices.php" class="nav-link <?= $page==='invoices' ? 'active' : '' ?>">Rechnungen</a>
        <?php if ($isHost): ?>
          <a href="/customer/services.php" class="nav-link <?= $page==='services' ? 'active' : '' ?>">Services</a>
          <a href="/customer/checklist.php" class="nav-link <?= $page==='checklist' ? 'active' : '' ?>">Check-Liste</a>
          <a href="/customer/smarthome.php" class="nav-link <?= $page==='smarthome' ? 'active' : '' ?>">Smart Home</a>
        <?php endif; ?>
        <a href="/customer/photo-scores.php" class="nav-link <?= $page==='photo-scores' ? 'active' : '' ?>">Foto-Score</a>
        <a href="/customer/messages.php" class="nav-link <?= $page==='messages' ? 'active' : '' ?>">Chat</a>
        <a href="/customer/help.php"     class="nav-link <?= $page==='help' ? 'active' : '' ?>">Hilfe</a>
      </nav>

      <!-- Right side: CTA + user -->
      <div class="flex items-center gap-3">
        <a href="/customer/booking.php" class="hidden sm:inline-block btn-outline-white">Jetzt buchen</a>

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
            <a href="/customer/profile.php" class="flex items-center gap-3 px-4 py-3 text-sm text-gray-700 hover:bg-gray-50">
              <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
              Kontoeinstellungen
            </a>
            <a href="/customer/documents.php" class="flex items-center gap-3 px-4 py-3 text-sm text-gray-700 hover:bg-gray-50">
              <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
              Dokumente
            </a>
            <a href="/customer/workhours.php" class="flex items-center gap-3 px-4 py-3 text-sm text-gray-700 hover:bg-gray-50">
              <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
              Arbeitsstunden
            </a>
            <?php if ($isHost): ?>
            <a href="/customer/ical.php" class="flex items-center gap-3 px-4 py-3 text-sm text-gray-700 hover:bg-gray-50">
              <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
              iCal Feeds
            </a>
            <?php endif; ?>
            <a href="/customer/donations.php" class="flex items-center gap-3 px-4 py-3 text-sm text-gray-700 hover:bg-gray-50">
              <svg class="w-4 h-4 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
              Meine Spenden
            </a>
            <a href="/customer/referral.php" class="flex items-center gap-3 px-4 py-3 text-sm text-gray-700 hover:bg-gray-50">
              <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/></svg>
              Weiterempfehlen
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

  <!-- Mobile menu drawer — grouped -->
  <div x-show="mobileMenu" x-cloak x-transition class="lg:hidden border-t border-white/10 max-h-[80vh] overflow-y-auto">
    <div class="px-4 py-3 space-y-4">

      <!-- CTA -->
      <a href="/customer/booking.php" class="block px-4 py-3 rounded-xl bg-white text-brand font-bold text-center text-sm shadow-sm <?= $page==='booking' ? 'ring-2 ring-white/50' : '' ?>">Jetzt buchen</a>

      <!-- Termine & Übersicht -->
      <div>
        <div class="text-[10px] uppercase font-bold text-white/50 tracking-wider px-3 mb-1">Termine</div>
        <?php
        $grpTermine = [
            ['/customer/', 'Home', 'dashboard'],
            ['/customer/calendar.php', 'Kalender', 'calendar'],
            ['/customer/jobs.php', 'Terminliste', 'jobs'],
            ['/customer/invoices.php', 'Rechnungen', 'invoices'],
        ];
        foreach ($grpTermine as [$href, $label, $pg]): ?>
        <a href="<?= $href ?>" class="block px-3 py-2.5 rounded-lg text-white font-medium text-sm <?= $page===$pg ? 'bg-white/20' : 'hover:bg-white/10' ?>"><?= $label ?></a>
        <?php endforeach; ?>
      </div>

      <?php if ($isHost): ?>
      <!-- Host-Tools -->
      <div>
        <div class="text-[10px] uppercase font-bold text-white/50 tracking-wider px-3 mb-1">Host-Tools</div>
        <?php
        $grpHost = [
            ['/customer/services.php', 'Services', 'services'],
            ['/customer/checklist.php', 'Check-Liste', 'checklist'],
            ['/customer/smarthome.php', 'Smart Home', 'smarthome'],
            ['/customer/ical.php', 'iCal Feeds', 'ical'],
        ];
        foreach ($grpHost as [$href, $label, $pg]): ?>
        <a href="<?= $href ?>" class="block px-3 py-2.5 rounded-lg text-white font-medium text-sm <?= $page===$pg ? 'bg-white/20' : 'hover:bg-white/10' ?>"><?= $label ?></a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- Kommunikation -->
      <div>
        <div class="text-[10px] uppercase font-bold text-white/50 tracking-wider px-3 mb-1">Kommunikation</div>
        <a href="/customer/photo-scores.php" class="block px-3 py-2.5 rounded-lg text-white font-medium text-sm <?= $page==='photo-scores' ? 'bg-white/20' : 'hover:bg-white/10' ?>">Foto-Score</a>
        <a href="/customer/messages.php" class="block px-3 py-2.5 rounded-lg text-white font-medium text-sm <?= $page==='messages' ? 'bg-white/20' : 'hover:bg-white/10' ?>">Chat</a>
        <a href="/customer/help.php" class="block px-3 py-2.5 rounded-lg text-white font-medium text-sm <?= $page==='help' ? 'bg-white/20' : 'hover:bg-white/10' ?>">Hilfe</a>
      </div>

      <!-- Konto -->
      <div>
        <div class="text-[10px] uppercase font-bold text-white/50 tracking-wider px-3 mb-1">Mein Konto</div>
        <?php
        $grpKonto = [
            ['/customer/profile.php', 'Kontoeinstellungen', 'profile'],
            ['/customer/documents.php', 'Dokumente', 'documents'],
            ['/customer/workhours.php', 'Arbeitsstunden', 'workhours'],
            ['/customer/donations.php', 'Meine Spenden', 'donations'],
            ['/customer/referral.php', 'Weiterempfehlen', 'referral'],
        ];
        foreach ($grpKonto as [$href, $label, $pg]): ?>
        <a href="<?= $href ?>" class="block px-3 py-2.5 rounded-lg text-white font-medium text-sm <?= $page===$pg ? 'bg-white/20' : 'hover:bg-white/10' ?>"><?= $label ?></a>
        <?php endforeach; ?>
      </div>

      <!-- Ausloggen -->
      <div class="pt-2 border-t border-white/10">
        <a href="/logout.php" class="block px-3 py-2.5 rounded-lg text-red-300 font-medium text-sm hover:bg-white/10">Ausloggen</a>
      </div>

    </div>
  </div>
</header>

<!-- ============ MAIN CONTENT ============ -->
<main class="max-w-7xl mx-auto px-4 sm:px-6 py-8">
