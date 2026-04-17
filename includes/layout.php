<?php
// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
$user = me(); $page = $page ?? ''; ?>
<!DOCTYPE html>
<html lang="de" class="h-full">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover"/>
  <title><?= e($title ?? 'Admin') ?> — <?= SITE ?></title>
  <link rel="manifest" href="/manifest.php"/>
  <meta name="theme-color" content="<?= BRAND ?>"/>
  <meta name="apple-mobile-web-app-capable" content="yes"/>
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent"/>
  <meta name="mobile-web-app-capable" content="yes"/>
  <link rel="apple-touch-icon" href="/icons/icon.php?s=180"/>
  <link rel="apple-touch-icon" sizes="152x152" href="/icons/icon.php?s=152"/>
  <link rel="apple-touch-icon" sizes="167x167" href="/icons/icon.php?s=167"/>
  <link rel="apple-touch-icon" sizes="180x180" href="/icons/icon.php?s=180"/>
  <meta name="apple-mobile-web-app-title" content="<?= SITE ?>"/>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>tailwind.config={theme:{extend:{colors:{brand:'<?= BRAND ?>','brand-dark':'<?= BRAND_DARK ?>','brand-light':'<?= BRAND_LIGHT ?>'},fontFamily:{sans:['Inter','system-ui','sans-serif']}}}}</script>
  <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
  <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
  <style>
    body { font-family: 'Inter', system-ui, sans-serif; -webkit-font-smoothing: antialiased; color: #0f172a; font-size: 14px; }
    /* Better readability — WCAG AA contrast */
    th, td { color: #0f172a; }
    label, .text-gray-600 { color: #1e293b !important; font-weight: 500; }
    .text-gray-500 { color: #334155 !important; }
    .text-gray-400 { color: #475569 !important; }
    .text-gray-300 { color: #64748b !important; }
    .text-gray-700 { color: #0f172a !important; }
    input, select, textarea { color: #0f172a; font-size: 14px; }
    input::placeholder, textarea::placeholder { color: #94a3b8 !important; }
    h1, h2, h3, h4 { color: #0f172a; font-weight: 700; }
    .text-xs { font-size: 0.8rem; }
    .text-sm { font-size: 0.875rem; }
    .text-\[11px\], .text-\[11\.5px\] { font-size: 12px !important; }
    .text-\[10px\] { font-size: 11px !important; }
    /* Sidebar */
    .sidebar-link { transition: all 0.15s ease; }
    .sidebar-link.active { background: linear-gradient(135deg, <?= BRAND ?>, <?= BRAND_DARK ?>); color: white; box-shadow: 0 2px 8px rgba(<?= BRAND_RGB ?>,0.3); }
    .sidebar-link:hover:not(.active) { background: <?= BRAND_LIGHT ?>; color: <?= BRAND_DARK ?>; }
    .sidebar-group-header {
      font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em;
      color: <?= BRAND_DARK ?>; padding: 14px 12px 6px 12px; margin-top: 8px;
      border-top: 1px solid rgba(0,0,0,0.06);
    }
    .sidebar-group-header:first-of-type { border-top: none; margin-top: 0; }
    [x-cloak] { display: none !important; }
    /* Scrollbar */
    ::-webkit-scrollbar { width: 6px; height: 6px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 3px; }
    ::-webkit-scrollbar-thumb:hover { background: #9ca3af; }
    /* Calendar */
    .fc-event { border: none !important; border-radius: 4px !important; }
    .fc-event:hover { filter: brightness(0.92); }
    .fc-daygrid-event { margin: 1px 2px !important; font-size: 11px; }
    .fc-daygrid-day-events { min-height: 0 !important; }
    .fc .fc-daygrid-day-frame { min-height: 100px !important; }
    .fc .fc-toolbar-title { font-size: 1.05rem; font-weight: 600; color: #111827; }
    .fc .fc-toolbar { margin-bottom: 0.75rem !important; }
    .fc .fc-button { font-size: 0.75rem; padding: 0.35em 0.6em; border-radius: 8px; font-weight: 500; transition: all 0.15s; }
    .fc .fc-button-primary { background: <?= BRAND ?>; border-color: <?= BRAND ?>; }
    .fc .fc-button-primary:hover { background: <?= BRAND_DARK ?>; border-color: <?= BRAND_DARK ?>; }
    .fc .fc-button-primary:not(:disabled).fc-button-active { background: <?= BRAND_DARK ?>; border-color: <?= BRAND_DARK ?>; }
    .fc .fc-col-header-cell { font-size: 0.8rem; padding: 8px 0 !important; color: #374151; font-weight: 600; }
    .fc .fc-daygrid-day-number { font-size: 0.85rem; padding: 4px 8px !important; color: #1f2937; font-weight: 500; }
    .fc .fc-day-today { background: <?= BRAND_LIGHT ?> !important; }
    .fc .fc-more-link { font-size: 10px; color: <?= BRAND ?>; font-weight: 600; }
    /* Transitions */
    .card-hover { transition: all 0.2s ease; }
    .card-hover:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.08); transform: translateY(-1px); }
    /* Mobile sidebar overlay */
    @media (max-width: 1023px) {
      #sidebar { position: fixed; left: 0; top: 0; bottom: 0; z-index: 40; }
      #sidebar-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.3); z-index: 39; }
    }
    @media (max-width: 768px) {
      .fc .fc-toolbar { flex-direction: column; gap: 8px; }
    }
    /* PWA — iOS safe areas */
    body { padding-top: env(safe-area-inset-top); padding-bottom: env(safe-area-inset-bottom); }
    main { padding-bottom: env(safe-area-inset-bottom, 0); }
    /* Mobile touch targets */
    @media (max-width: 768px) {
      .sidebar-link { padding: 10px 12px; min-height: 44px; }
      button, .btn, a.px-3, a.px-4 { min-height: 44px; }
      input, select, textarea { min-height: 44px; font-size: 16px !important; /* prevent iOS zoom */ }
      table th, table td { padding: 8px 6px; }
      .text-xs { font-size: 0.8rem; }
    }
    /* Pull-to-refresh indicator */
    #ptrIndicator { position: fixed; top: 0; left: 50%; transform: translateX(-50%) translateY(-60px);
                    z-index: 9999; transition: transform 0.2s; }
    #ptrIndicator.active { transform: translateX(-50%) translateY(10px); }
    /* Standalone mode: hide browser chrome gaps */
    @media (display-mode: standalone) {
      body { padding-top: env(safe-area-inset-top); }
    }
    /* Print */
    @media print { aside, header, footer, button { display: none !important; } main { margin: 0; } }
  </style>
  <link rel="stylesheet" href="/assets/ui-polish.css?v=20260413"/>
  <script>window.__userLang = '<?= function_exists("userLang") ? userLang() : "de" ?>';</script>
  <script defer src="/assets/auto-translate.js?v=20260413"></script>
</head>
<body class="h-full bg-gray-50" x-data="{ sidebarOpen: window.innerWidth >= 1024 }">
<div class="flex h-full">

  <!-- Mobile overlay -->
  <div id="sidebar-overlay" x-show="sidebarOpen && window.innerWidth < 1024" @click="sidebarOpen = false" x-cloak class="lg:hidden"></div>

  <!-- Sidebar -->
  <aside x-show="sidebarOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-x-0" x-transition:leave-end="-translate-x-full" class="w-64 bg-white border-r border-gray-200 flex-shrink-0 flex flex-col" id="sidebar">
    <?php
      // Home-Link je Rolle
      $homeLink = match($user['type']) {
          'admin' => '/admin/',
          'employee' => '/employee/',
          'customer' => '/customer/',
          default => '/login.php'
      };
      $panelLabel = match($user['type']) {
          'admin' => '🛠 Admin-Panel',
          'employee' => '👷 Partner-Portal',
          'customer' => '👤 Kundenbereich',
          default => 'Portal'
      };
      // Partner-Portal bekommt andere Brand-Farbe (optisch erkennbar)
      $brandBg = $user['type'] === 'employee' ? 'bg-gradient-to-br from-brand-dark to-brand' : 'bg-brand';
    ?>
    <div class="p-5 border-b <?= $user['type'] === 'employee' ? 'bg-gradient-to-r from-brand-light to-transparent' : '' ?>">
      <a href="<?= e($homeLink) ?>" class="flex items-center gap-2.5">
        <div class="w-8 h-8 rounded-lg <?= $brandBg ?> flex items-center justify-center shadow-sm">
          <span class="text-white font-bold text-sm"><?= $user['type'] === 'employee' ? '👷' : LOGO_LETTER ?></span>
        </div>
        <div>
          <div class="text-base font-bold text-gray-900 leading-tight"><?= SITE ?></div>
          <div class="text-[11px] font-semibold <?= $user['type']==='employee' ? 'text-brand-dark' : 'text-gray-500' ?> -mt-0.5"><?= $panelLabel ?></div>
        </div>
      </a>
      <?php if ($user['type'] === 'employee'): ?>
        <div class="mt-2 text-[11px] text-gray-700 truncate">Hallo <strong><?= e($user['name']) ?></strong></div>
      <?php endif; ?>
    </div>
    <nav class="flex-1 p-3 space-y-0.5 overflow-y-auto">
      <?php
      $iconHome = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>';
      $iconCal = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>';
      $iconUser = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>';
      $iconGroup = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>';
      $iconBuild = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>';
      $iconInv = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/>';
      $iconClock = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>';
      $iconSearch = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>';
      $iconCog = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>';
      $iconProfile = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>';
      $iconMsg = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>';

      if ($user['type'] === 'admin') {
        // Counts for badges
        $badgePending = val("SELECT COUNT(*) FROM jobs WHERE job_status='PENDING' AND status=1 AND j_date>=CURDATE()") ?: 0;
        $badgeUnpaid = val("SELECT COUNT(*) FROM invoices WHERE invoice_paid='no' AND remaining_price > 0") ?: 0;
        $badgeMsgs = 0;
        try { $badgeMsgs = valLocal("SELECT COUNT(*) FROM messages WHERE recipient_type='admin' AND read_at IS NULL") ?: 0; } catch (Exception $e) {}
        $menu = [
          ['/admin/', 'Dashboard', 'dashboard', $iconHome, ''],
          ['/admin/jobs.php', 'Jobs Kalender', 'jobs', $iconCal, $badgePending > 0 ? $badgePending : ''],
          ['/admin/route-planner.php', '🗺 Route-Planner', 'route-planner', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/>', ''],
          ['/admin/prebook-links.php', '🔗 Prebook-Links', 'prebook-links', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>', ''],
          ['/admin/vacations.php', '🏖 Kunden-Urlaube', 'vacations', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>', ''],
          ['/admin/blocked-days.php', '🚫 Tage sperren', 'blocked-days', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>', ''],
          ['/admin/customers.php', 'Kunden', 'customers', $iconUser, ''],
          ['/admin/leads.php', 'Leads', 'leads', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>', ''],
          ['/admin/keys.php', 'Schlüssel', 'keys', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>', ''],
          ['/admin/locks.php', 'Smart Locks', 'locks', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>', ''],
          ['/admin/employees.php', 'Partner', 'employees', $iconGroup, ''],
          ['/admin/services.php', 'Services', 'services', $iconBuild, ''],
          ['/admin/checklists.php', 'Checklisten', 'checklists', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>', ''],
          ['/admin/photo-scores.php', 'KI Foto-Scores', 'photo-scores', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/>', ''],
          ['/admin/optional-products.php', 'Optionale Produkte', 'optional-products', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>', ''],
          ['/admin/gutscheine.php', 'Gutscheine', 'gutscheine', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-7l-4 4z"/>', ''],
          ['/admin/invoices.php', 'Rechnungen', 'invoices', $iconInv, $badgeUnpaid > 0 ? $badgeUnpaid : ''],
          ['/admin/messages.php', 'Nachrichten', 'messages', $iconMsg, $badgeMsgs > 0 ? $badgeMsgs : ''],
          ['/admin/whatsapp.php', 'WhatsApp', 'whatsapp', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>', ''],
          ['/admin/work-hours.php', 'Arbeitszeit', 'work-hours', $iconClock, ''],
          ['/admin/availability.php', 'Verfügbarkeit', 'availability', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>', ''],
          ['/admin/live-map.php', 'Live-Karte', 'live-map', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>', ''],
          ['/admin/partners-live.php', 'Partner Live', 'partners-live', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>', ''],
          ['/admin/arrivals.php', 'Ankünfte Berlin', 'arrivals', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>', ''],
          ['/admin/doc-scanner.php', '📜 Doc-Scanner AI', 'doc-scanner', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>', ''],
          ['/admin/audit.php', 'Protokoll', 'audit', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>', ''],
          ['/admin/consent-log.php', 'DSGVO Consent', 'consent-log', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/>', ''],
          ['/admin/api-tokens.php', 'API-Tokens', 'api-tokens', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>', ''],
          ['/admin/notifications.php', 'Benachrichtigungen', 'notifications', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>', ''],
          ['/admin/booking-priority.php', 'Buchungs-Priorität', 'booking-priority', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>', ''],
          ['/admin/health.php', 'Health', 'health', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/>', ''],
          ['/admin/texts.php', 'Texte', 'texts', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h10"/>', ''],
          ['/admin/tenants.php', 'Tenants', 'tenants', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>', ''],
          ['/admin/settings.php', 'Einstellungen', 'settings', $iconCog, ''],
          ['/admin/sync.php', 'Daten-Sync', 'sync', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>', ''],
        ];
        // Conditional features
        if (FEATURE_SMOOBU) array_splice($menu, 2, 0, [
          ['/admin/bookings.php', 'Buchungen', 'bookings', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>', ''],
          ['/admin/availability.php', 'Verfügbarkeit', 'availability', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z"/>', ''],
        ]);
        if (FEATURE_OSINT) array_splice($menu, -2, 0, [
          ['/admin/osi.php', 'OSI', 'scanner', $iconSearch, ''],
        ]);
        array_splice($menu, -2, 0, [
          ['/admin/email-inbox.php', 'Email', 'email-inbox', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>', ''],
          ['/admin/reports.php', 'Reports', 'reports', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>', ''],
        ]);
      } elseif ($user['type'] === 'customer') {
        $menu = [];
        if (customerCan('dashboard')) $menu[] = ['/customer/', 'Dashboard', 'dashboard', $iconHome, ''];
        if (customerCan('jobs')) $menu[] = ['/customer/jobs.php', 'Meine Jobs', 'jobs', $iconCal, ''];
        if (customerCan('invoices')) $menu[] = ['/customer/invoices.php', 'Rechnungen', 'invoices', $iconInv, ''];
        if (customerCan('workhours')) $menu[] = ['/customer/workhours.php', 'Arbeitsstunden', 'workhours', $iconClock, ''];
        if (customerCan('documents')) $menu[] = ['/customer/documents.php', 'Dokumente', 'documents', $iconSearch, ''];
        if (customerCan('messages')) $menu[] = ['/customer/messages.php', 'Nachrichten', 'messages', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>', ''];
        if (customerCan('profile')) $menu[] = ['/customer/profile.php', 'Profil', 'profile', $iconProfile, ''];
        if (customerCan('booking')) $menu[] = ['/customer/booking.php', 'Neue Buchung', 'booking', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>', ''];
        $menu[] = ['/customer/vacations.php', '🏖 Urlaub', 'vacations', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>', ''];
        if (empty($menu)) $menu[] = ['/customer/', 'Dashboard', 'dashboard', $iconHome, ''];
      } elseif ($user['type'] === 'employee') {
        $badgeEmpMsgs = 0;
        try { $badgeEmpMsgs = valLocal("SELECT COUNT(*) FROM messages WHERE recipient_type='employee' AND recipient_id=? AND read_at IS NULL", [$user['id']]) ?: 0; } catch (Exception $e) {}
        $menu = [];
        if (employeeCan('portal_jobs')) $menu[] = ['/employee/', 'Meine Jobs', 'dashboard', $iconHome, ''];
        if (employeeCan('portal_earnings')) $menu[] = ['/employee/earnings.php', 'Verdienst', 'earnings', $iconInv, ''];
        if (employeeCan('portal_messages')) $menu[] = ['/employee/messages.php', 'Nachrichten', 'messages', $iconMsg, $badgeEmpMsgs > 0 ? $badgeEmpMsgs : ''];
        $menu[] = ['/employee/availability.php', 'Verfügbarkeit', 'availability', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>', ''];
        if (employeeCan('portal_profile')) $menu[] = ['/employee/profile.php', 'Profil', 'profile', $iconProfile, ''];
        if (empty($menu)) $menu[] = ['/employee/', 'Meine Jobs', 'dashboard', $iconHome, ''];
      } else {
        $menu = [['/login.php', 'Login', '', $iconHome, '']];
      }
      if ($user['type'] === 'admin') {
        // Group admin menu items into collapsible sections
        // Cleaner grouping — fewer, tighter sections, everything mapped (no _ungrouped).
        $groupMap = [
          'dashboard' => '_top',
          // Termine (appointments) — everything calendar/scheduling related
          'jobs' => 'Termine', 'bookings' => 'Termine', 'route-planner' => 'Termine',
          'prebook-links' => 'Termine', 'availability' => 'Termine',
          'booking-priority' => 'Termine', 'vacations' => 'Termine',
          'blocked-days' => 'Termine', 'arrivals' => 'Termine',
          // Kunden
          'customers' => 'Kunden', 'leads' => 'Kunden', 'consent-log' => 'Kunden',
          // Partner
          'employees' => 'Partner', 'work-hours' => 'Partner',
          'live-map' => 'Partner', 'partners-live' => 'Partner',
          // Zugang (access / keys)
          'keys' => 'Zugang', 'locks' => 'Zugang',
          // Services (catalog + pricing)
          'services' => 'Services', 'checklists' => 'Services',
          'optional-products' => 'Services', 'pricing' => 'Services',
          'photo-scores' => 'Services',
          // Finanzen
          'invoices' => 'Finanzen', 'reports' => 'Finanzen',
          'gutscheine' => 'Finanzen',
          // Kommunikation
          'messages' => 'Kommunikation', 'whatsapp' => 'Kommunikation',
          'email-inbox' => 'Kommunikation', 'notifications' => 'Kommunikation',
          // Tools / scanner
          'scanner' => 'Tools', 'doc-scanner' => 'Tools',
          // System (admin & config) — texts + tenants now live here (were ungrouped)
          'texts' => 'System', 'tenants' => 'System',
          'audit' => 'System', 'health' => 'System',
          'settings' => 'System', 'api-tokens' => 'System', 'sync' => 'System',
        ];
        $groups = []; $topItems = [];
        foreach ($menu as $item) {
          $grp = $groupMap[$item[2]] ?? '_ungrouped';
          if ($grp === '_top') { $topItems[] = $item; }
          else { $groups[$grp][] = $item; }
        }
        // Check if current page is in a group (to auto-open it)
        $activeGroup = $groupMap[$page] ?? '';
        // Render top items (Dashboard)
        foreach ($topItems as [$href, $label, $key, $icon, $badge]):
          $active = $page === $key ? 'active' : '';
      ?>
      <a href="<?= $href ?>" class="sidebar-link flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium text-gray-600 <?= $active ?>">
        <svg class="w-[18px] h-[18px] flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><?= $icon ?></svg>
        <span class="flex-1"><?= $label ?></span>
        <?php if ($badge): ?><span class="px-1.5 py-0.5 text-[10px] font-bold rounded-full bg-red-100 text-red-700"><?= $badge ?></span><?php endif; ?>
      </a>
      <?php endforeach;
        // Render grouped items — FLAT mit fixen Labels (keine Collapse-Klicks mehr)
        foreach ($groups as $groupName => $items):
      ?>
      <div class="sidebar-group-header"><?= e($groupName) ?></div>
      <?php foreach ($items as [$href, $label, $key, $icon, $badge]):
            $active = $page === $key ? 'active' : '';
      ?>
      <a href="<?= $href ?>" class="sidebar-link flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium text-gray-700 <?= $active ?>">
        <svg class="w-[16px] h-[16px] flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><?= $icon ?></svg>
        <span class="flex-1"><?= $label ?></span>
        <?php if ($badge): ?><span class="px-1.5 py-0.5 text-[10px] font-bold rounded-full bg-red-100 text-red-700"><?= $badge ?></span><?php endif; ?>
      </a>
      <?php endforeach; endforeach;
      } else {
        // Customer/Employee: flat menu
        foreach ($menu as [$href, $label, $key, $icon, $badge]):
          $active = $page === $key ? 'active' : '';
      ?>
      <a href="<?= $href ?>" class="sidebar-link flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium text-gray-600 <?= $active ?>">
        <svg class="w-[18px] h-[18px] flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><?= $icon ?></svg>
        <span class="flex-1"><?= $label ?></span>
        <?php if ($badge): ?><span class="px-1.5 py-0.5 text-[10px] font-bold rounded-full bg-red-100 text-red-700"><?= $badge ?></span><?php endif; ?>
      </a>
      <?php endforeach;
      } ?>
    </nav>
    <div class="p-3 border-t">
      <div class="flex items-center gap-3 px-3 py-2">
        <div class="w-8 h-8 rounded-full bg-gradient-to-br from-brand to-brand-dark text-white flex items-center justify-center text-xs font-bold"><?= strtoupper(substr($user['name'],0,1)) ?></div>
        <div class="flex-1 min-w-0">
          <div class="text-sm font-medium truncate text-gray-800"><?= e($user['name']) ?></div>
          <div class="text-[10px] text-gray-400 truncate"><?= e($user['email']) ?></div>
        </div>
      </div>
      <a href="/logout.php" class="flex items-center gap-2 px-3 py-1.5 text-xs text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg mt-1 transition">
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
        Abmelden
      </a>
    </div>
  </aside>

  <!-- Main -->
  <main class="flex-1 overflow-y-auto min-w-0">
    <header class="bg-white border-b border-gray-200 px-4 sm:px-6 py-3 flex items-center gap-3 sticky top-0 z-20">
      <button @click="sidebarOpen = !sidebarOpen" class="p-2 rounded-lg hover:bg-gray-100 transition" aria-label="Menü">
        <svg class="w-5 h-5 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
      </button>
      <h1 class="text-lg font-semibold text-gray-900 truncate hidden sm:block"><?= e($title ?? '') ?></h1>

      <?php if ($user['type'] === 'admin'): ?>
      <!-- Global Search -->
      <div class="flex-1 max-w-xl mx-auto" x-data="globalSearch()">
        <div class="relative">
          <svg class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
          <input type="text" x-model="q" @input.debounce.250ms="search()" @focus="if(results.length||q)open=true" @click.away="open=false" @keydown.escape="open=false" placeholder="Suche Kunde · Job #· Rechnung · Lead · Code…" class="w-full pl-9 pr-3 py-2 border border-gray-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-brand focus:border-brand"/>
          <div x-show="open && (results.length || loading)" x-cloak class="absolute top-full left-0 right-0 mt-1 bg-white border border-gray-200 rounded-xl shadow-lg max-h-96 overflow-y-auto z-30">
            <div x-show="loading" class="px-4 py-3 text-xs text-gray-400">Suche…</div>
            <template x-for="r in results" :key="r.type+'-'+r.id">
              <a :href="r.url" class="block px-4 py-2.5 hover:bg-brand-light border-b border-gray-100 last:border-0">
                <div class="flex items-center gap-2">
                  <span class="px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wider rounded"
                        :class="{'bg-blue-100 text-blue-700':r.type==='customer','bg-purple-100 text-purple-700':r.type==='job','bg-green-100 text-green-700':r.type==='invoice','bg-orange-100 text-orange-700':r.type==='lead','bg-pink-100 text-pink-700':r.type==='voucher'}"
                        x-text="r.type"></span>
                  <span class="font-medium text-sm text-gray-900" x-text="r.title"></span>
                </div>
                <div class="text-xs text-gray-500 mt-0.5" x-text="r.subtitle"></div>
              </a>
            </template>
            <div x-show="!loading && !results.length && q.length >= 2" class="px-4 py-3 text-xs text-gray-400">Keine Treffer.</div>
          </div>
        </div>
      </div>

      <!-- Quick-Actions -->
      <div class="relative" x-data="{open:false}">
        <button @click="open=!open" @click.away="open=false" class="flex items-center gap-1.5 px-3 py-2 bg-brand text-white rounded-lg text-sm font-medium hover:bg-brand-dark transition">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
          <span class="hidden sm:inline">Neu</span>
          <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
        </button>
        <div x-show="open" x-cloak x-transition.origin.top.right class="absolute right-0 mt-1 w-52 bg-white border border-gray-200 rounded-xl shadow-lg py-1 z-30">
          <a href="/admin/jobs.php?new=1" class="flex items-center gap-2 px-3 py-2 text-sm text-gray-800 hover:bg-brand-light">📅 Neuer Job</a>
          <a href="/admin/customers.php?new=1" class="flex items-center gap-2 px-3 py-2 text-sm text-gray-800 hover:bg-brand-light">👤 Neuer Kunde</a>
          <a href="/admin/employees.php?new=1" class="flex items-center gap-2 px-3 py-2 text-sm text-gray-800 hover:bg-brand-light">👷 Neuer Partner</a>
          <a href="/admin/bookings.php?new=1" class="flex items-center gap-2 px-3 py-2 text-sm text-gray-800 hover:bg-brand-light">🏨 Neue Buchung</a>
          <a href="/admin/services.php?new=1" class="flex items-center gap-2 px-3 py-2 text-sm text-gray-800 hover:bg-brand-light">🏢 Neuer Service</a>
          <a href="/admin/gutscheine.php?new=1" class="flex items-center gap-2 px-3 py-2 text-sm text-gray-800 hover:bg-brand-light">🎟 Neuer Gutschein</a>
          <a href="/admin/invoices.php?new=1" class="flex items-center gap-2 px-3 py-2 text-sm text-gray-800 hover:bg-brand-light">💶 Neue Rechnung</a>
          <div class="border-t my-1"></div>
          <a href="/admin/osi.php" class="flex items-center gap-2 px-3 py-2 text-sm text-gray-800 hover:bg-brand-light">🔍 OSI Scan</a>
        </div>
      </div>
      <?php endif; ?>

      <span class="text-xs text-gray-500 hidden xl:inline tabular-nums"><?= date('d.m.Y') ?></span>
    </header>

    <?php
    // Toast-Flash (ersetzt URL-Banner-Gescrolle — oben sticky, auto-dismiss)
    $flashMsg = null; $flashKind = 'info';
    if (!empty($_GET['saved']) || !empty($_GET['added'])) { $flashMsg = 'Gespeichert.'; $flashKind = 'ok'; }
    elseif (!empty($_GET['deleted'])) { $flashMsg = 'Gelöscht.'; $flashKind = 'ok'; }
    elseif (!empty($_GET['err'])) { $flashMsg = match($_GET['err']) { 'dup'=>'Bereits vorhanden.', 'code'=>'Pflichtfeld leer.', 'csrf'=>'Session abgelaufen — bitte neu laden.', default => 'Fehler: '.$_GET['err'] }; $flashKind = 'err'; }
    if ($flashMsg):
    ?>
    <div x-data="{show:true}" x-show="show" x-init="setTimeout(()=>show=false, 4500)" x-cloak
         class="fixed top-16 right-4 z-40 min-w-[280px] max-w-md px-4 py-3 rounded-xl border shadow-lg flex items-center gap-3
                <?= $flashKind==='ok' ? 'bg-green-50 border-green-200 text-green-800' : ($flashKind==='err' ? 'bg-red-50 border-red-200 text-red-800' : 'bg-blue-50 border-blue-200 text-blue-800') ?>">
      <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <?php if ($flashKind==='ok'): ?><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
        <?php elseif ($flashKind==='err'): ?><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5.07 19h13.86a2 2 0 001.73-3L13.73 4a2 2 0 00-3.46 0L3.34 16a2 2 0 001.73 3z"/>
        <?php else: ?><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/><?php endif; ?>
      </svg>
      <span class="flex-1 text-sm font-medium"><?= e($flashMsg) ?></span>
      <button @click="show=false" class="text-current opacity-60 hover:opacity-100">&times;</button>
    </div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['admin_uid'])): ?>
    <div class="bg-orange-500 text-white px-6 py-2 flex items-center justify-between text-sm">
      <span>Eingeloggt als <strong><?= e($user['name']) ?></strong> (<?= e($user['type']) ?>)</span>
      <a href="/admin/return-to-admin.php" class="px-3 py-1 bg-white text-orange-600 rounded-lg font-medium text-xs hover:bg-orange-50 transition">Zurück zu Admin</a>
    </div>
    <?php endif; ?>
    <div class="p-4 sm:p-6">
