<?php $user = me(); $page = $page ?? ''; ?>
<!DOCTYPE html>
<html lang="de" class="h-full">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title><?= e($title ?? 'Admin') ?> — <?= SITE ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>tailwind.config={theme:{extend:{colors:{brand:'<?= BRAND ?>','brand-dark':'<?= BRAND_DARK ?>','brand-light':'<?= BRAND_LIGHT ?>'},fontFamily:{sans:['Inter','system-ui','sans-serif']}}}}</script>
  <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
  <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
  <style>
    body { font-family: 'Inter', system-ui, sans-serif; -webkit-font-smoothing: antialiased; }
    /* Sidebar */
    .sidebar-link { transition: all 0.15s ease; }
    .sidebar-link.active { background: linear-gradient(135deg, <?= BRAND ?>, <?= BRAND_DARK ?>); color: white; box-shadow: 0 2px 8px rgba(<?= BRAND_RGB ?>,0.3); }
    .sidebar-link:hover:not(.active) { background: <?= BRAND_LIGHT ?>; color: <?= BRAND ?>; }
    [x-cloak] { display: none !important; }
    /* Scrollbar */
    ::-webkit-scrollbar { width: 6px; height: 6px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 3px; }
    ::-webkit-scrollbar-thumb:hover { background: #9ca3af; }
    /* Calendar */
    .fc-event { border: none !important; border-radius: 4px !important; transition: opacity 0.15s; }
    .fc-event:hover { opacity: 0.85; }
    .fc-daygrid-event { margin: 1px 2px !important; font-size: 11px; }
    .fc-daygrid-day-events { min-height: 0 !important; }
    .fc .fc-daygrid-day-frame { min-height: 100px !important; }
    .fc .fc-toolbar-title { font-size: 1.05rem; font-weight: 600; color: #111827; }
    .fc .fc-toolbar { margin-bottom: 0.75rem !important; }
    .fc .fc-button { font-size: 0.75rem; padding: 0.35em 0.6em; border-radius: 8px; font-weight: 500; transition: all 0.15s; }
    .fc .fc-button-primary { background: <?= BRAND ?>; border-color: <?= BRAND ?>; }
    .fc .fc-button-primary:hover { background: <?= BRAND_DARK ?>; border-color: <?= BRAND_DARK ?>; }
    .fc .fc-button-primary:not(:disabled).fc-button-active { background: <?= BRAND_DARK ?>; border-color: <?= BRAND_DARK ?>; }
    .fc .fc-col-header-cell { font-size: 0.75rem; padding: 8px 0 !important; color: #6b7280; font-weight: 500; }
    .fc .fc-daygrid-day-number { font-size: 0.8rem; padding: 4px 8px !important; color: #374151; }
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
    /* Print */
    @media print { aside, header, footer, button { display: none !important; } main { margin: 0; } }
  </style>
</head>
<body class="h-full bg-gray-50" x-data="{ sidebarOpen: window.innerWidth >= 1024 }">
<div class="flex h-full">

  <!-- Mobile overlay -->
  <div id="sidebar-overlay" x-show="sidebarOpen && window.innerWidth < 1024" @click="sidebarOpen = false" x-cloak class="lg:hidden"></div>

  <!-- Sidebar -->
  <aside x-show="sidebarOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-x-0" x-transition:leave-end="-translate-x-full" class="w-64 bg-white border-r border-gray-200 flex-shrink-0 flex flex-col" id="sidebar">
    <div class="p-5 border-b">
      <a href="/admin/" class="flex items-center gap-2.5">
        <div class="w-8 h-8 rounded-lg bg-brand flex items-center justify-center"><span class="text-white font-bold text-sm">F</span></div>
        <div>
          <div class="text-lg font-bold text-gray-900"><?= SITE ?></div>
          <div class="text-[10px] text-gray-400 -mt-0.5"><?= ucfirst($user['type']) ?> Panel</div>
        </div>
      </a>
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
        try { $badgeMsgs = val("SELECT COUNT(*) FROM messages WHERE recipient_type='admin' AND read_at IS NULL") ?: 0; } catch (Exception $e) {}
        $menu = [
          ['/admin/', 'Dashboard', 'dashboard', $iconHome, ''],
          ['/admin/jobs.php', 'Jobs Kalender', 'jobs', $iconCal, $badgePending > 0 ? $badgePending : ''],
          ['/admin/customers.php', 'Kunden', 'customers', $iconUser, ''],
          ['/admin/employees.php', 'Partner', 'employees', $iconGroup, ''],
          ['/admin/services.php', 'Services', 'services', $iconBuild, ''],
          ['/admin/invoices.php', 'Rechnungen', 'invoices', $iconInv, $badgeUnpaid > 0 ? $badgeUnpaid : ''],
          ['/admin/messages.php', 'Nachrichten', 'messages', $iconMsg, $badgeMsgs > 0 ? $badgeMsgs : ''],
          ['/admin/work-hours.php', 'Arbeitszeit', 'work-hours', $iconClock, ''],
          ['/admin/audit.php', 'Protokoll', 'audit', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>', ''],
          ['/admin/settings.php', 'Einstellungen', 'settings', $iconCog, ''],
        ];
        // Conditional features
        if (FEATURE_OSINT) array_splice($menu, -2, 0, [  ['/admin/scanner.php', 'OSINT Scanner', 'scanner', $iconSearch, '']  ]);
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
        if (empty($menu)) $menu[] = ['/customer/', 'Dashboard', 'dashboard', $iconHome, ''];
      } elseif ($user['type'] === 'employee') {
        $badgeEmpMsgs = 0;
        try { $badgeEmpMsgs = val("SELECT COUNT(*) FROM messages WHERE recipient_type='employee' AND recipient_id=? AND read_at IS NULL", [$user['id']]) ?: 0; } catch (Exception $e) {}
        $menu = [
          ['/employee/', 'Meine Jobs', 'dashboard', $iconHome, ''],
          ['/employee/messages.php', 'Nachrichten', 'messages', $iconMsg, $badgeEmpMsgs > 0 ? $badgeEmpMsgs : ''],
          ['/employee/profile.php', 'Profil', 'profile', $iconProfile, ''],
        ];
      } else {
        $menu = [['/login.php', 'Login', '', $iconHome, '']];
      }
      foreach ($menu as [$href, $label, $key, $icon, $badge]):
        $active = $page === $key ? 'active' : '';
      ?>
      <a href="<?= $href ?>" class="sidebar-link flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium text-gray-600 <?= $active ?>">
        <svg class="w-[18px] h-[18px] flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><?= $icon ?></svg>
        <span class="flex-1"><?= $label ?></span>
        <?php if ($badge): ?><span class="px-1.5 py-0.5 text-[10px] font-bold rounded-full bg-red-100 text-red-700"><?= $badge ?></span><?php endif; ?>
      </a>
      <?php endforeach; ?>
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
    <header class="bg-white/80 backdrop-blur-sm border-b px-6 py-3 flex items-center justify-between sticky top-0 z-10">
      <div class="flex items-center gap-3">
        <button @click="sidebarOpen = !sidebarOpen" class="p-2 rounded-lg hover:bg-gray-100 transition">
          <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <h1 class="text-lg font-semibold text-gray-900"><?= e($title ?? '') ?></h1>
      </div>
      <div class="flex items-center gap-3">
        <span class="text-xs text-gray-400 hidden sm:inline"><?= date('d.m.Y H:i') ?></span>
      </div>
    </header>
    <?php if (!empty($_SESSION['admin_uid'])): ?>
    <div class="bg-orange-500 text-white px-6 py-2 flex items-center justify-between text-sm">
      <span>Eingeloggt als <strong><?= e($user['name']) ?></strong> (<?= e($user['type']) ?>)</span>
      <a href="/admin/return-to-admin.php" class="px-3 py-1 bg-white text-orange-600 rounded-lg font-medium text-xs hover:bg-orange-50 transition">Zurück zu Admin</a>
    </div>
    <?php endif; ?>
    <div class="p-4 sm:p-6">
