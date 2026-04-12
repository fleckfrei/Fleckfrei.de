<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Leads (Neue Kunden)'; $page = 'leads';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { header('Location: /admin/leads.php'); exit; }
    $act = $_POST['action'] ?? '';
    $lid = (int)($_POST['lead_id'] ?? 0);

    if ($act === 'update_status' && $lid) {
        $status = in_array($_POST['status'] ?? '', ['new','contacted','converted','rejected'], true) ? $_POST['status'] : 'new';
        $contactedAt = $status === 'contacted' ? 'NOW()' : 'NULL';
        q("UPDATE leads SET status=?, contacted_at=" . ($status === 'contacted' ? 'NOW()' : 'contacted_at') . " WHERE lead_id=?", [$status, $lid]);
        audit('update', 'leads', $lid, "Status → $status");
        header('Location: /admin/leads.php?saved=1'); exit;
    }
    if ($act === 'delete' && $lid) {
        q("DELETE FROM leads WHERE lead_id=?", [$lid]);
        header('Location: /admin/leads.php?saved=1'); exit;
    }
}

$filter = $_GET['filter'] ?? 'new';
$category = $_GET['category'] ?? '';
$where = ['1=1'];
$params = [];
if ($filter !== 'all') {
    $where[] = 'status = ?';
    $params[] = $filter;
}
if ($category) {
    $where[] = 'category = ?';
    $params[] = $category;
}
$leads = all("SELECT * FROM leads WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC LIMIT 200", $params);

$counts = [];
foreach (['new','contacted','converted','rejected'] as $s) {
    $counts[$s] = (int) val("SELECT COUNT(*) FROM leads WHERE status=?", [$s]);
}
$counts['all'] = (int) val("SELECT COUNT(*) FROM leads");

$catLabels = [
    'haushalt' => '🏠 Haushalt',
    'airbnb' => '🌴 Airbnb',
    'buero' => '🏢 Büro',
    'event' => '🎉 Event',
    'umzug' => '📦 Umzug',
    'other' => '📋 Sonstige',
];

include __DIR__ . '/../includes/layout.php';
?>

<?php if (!empty($_GET['saved'])): ?>
<div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4">Gespeichert.</div>
<?php endif; ?>

<div class="flex items-start justify-between mb-6 flex-wrap gap-4">
  <div>
    <h1 class="text-2xl font-bold text-gray-900">Leads — Neue Kunden</h1>
    <p class="text-sm text-gray-500 mt-1">Automatisch gefundene potenzielle Kunden aus öffentlichen Quellen.</p>
  </div>
  <div x-data="{ scanning: false, scanResult: null }" class="flex items-center gap-2">
    <button
      @click="scanning = true; scanResult = null; fetch('/api/lead-scraper.php?cron=flk_scrape_2026').then(r => r.json()).then(d => { scanResult = d; scanning = false; setTimeout(() => location.reload(), 1500); }).catch(() => { scanning = false; scanResult = { error: 'Fehler' }; })"
      :disabled="scanning"
      class="px-4 py-2 bg-brand hover:bg-brand/90 text-white rounded-xl text-sm font-semibold flex items-center gap-2 disabled:opacity-50">
      <svg x-show="!scanning" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
      <svg x-show="scanning" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
      <span x-text="scanning ? 'Scanne Markt...' : 'Neue Leads suchen'"></span>
    </button>
    <div x-show="scanResult" x-cloak class="text-xs">
      <span x-show="scanResult?.success" class="text-green-700">✓ <span x-text="scanResult?.total_new"></span> neue Leads</span>
      <span x-show="scanResult?.error" class="text-red-700" x-text="scanResult?.error"></span>
    </div>
  </div>
</div>

<!-- Status filter tabs -->
<div class="flex gap-2 mb-4 flex-wrap">
  <a href="?filter=new" class="px-4 py-2 rounded-xl text-sm font-semibold <?= $filter === 'new' ? 'bg-brand text-white' : 'bg-white border text-gray-700 hover:border-brand' ?>">Neu (<?= $counts['new'] ?>)</a>
  <a href="?filter=contacted" class="px-4 py-2 rounded-xl text-sm font-semibold <?= $filter === 'contacted' ? 'bg-brand text-white' : 'bg-white border text-gray-700 hover:border-brand' ?>">Kontaktiert (<?= $counts['contacted'] ?>)</a>
  <a href="?filter=converted" class="px-4 py-2 rounded-xl text-sm font-semibold <?= $filter === 'converted' ? 'bg-green-600 text-white' : 'bg-white border text-gray-700 hover:border-green-600' ?>">Gewonnen (<?= $counts['converted'] ?>)</a>
  <a href="?filter=rejected" class="px-4 py-2 rounded-xl text-sm font-semibold <?= $filter === 'rejected' ? 'bg-gray-500 text-white' : 'bg-white border text-gray-700 hover:border-gray-500' ?>">Abgelehnt (<?= $counts['rejected'] ?>)</a>
  <a href="?filter=all" class="px-4 py-2 rounded-xl text-sm font-semibold <?= $filter === 'all' ? 'bg-gray-700 text-white' : 'bg-white border text-gray-700 hover:border-gray-700' ?>">Alle (<?= $counts['all'] ?>)</a>
</div>

<!-- Leads list -->
<div class="bg-white rounded-xl border overflow-hidden">
  <?php if (empty($leads)): ?>
  <div class="p-12 text-center">
    <div class="w-16 h-16 mx-auto rounded-full bg-gray-100 flex items-center justify-center mb-4">
      <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
    </div>
    <p class="text-gray-500 font-medium">Noch keine Leads</p>
    <p class="text-xs text-gray-400 mt-1">Click auf "Neue Leads suchen" oben, um eine Markt-Suche zu starten.</p>
  </div>
  <?php else: ?>
  <div class="divide-y">
    <?php foreach ($leads as $l): ?>
    <div class="p-5 hover:bg-gray-50 transition">
      <div class="flex items-start justify-between gap-4">
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2 mb-1 flex-wrap">
            <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-brand/10 text-brand"><?= $catLabels[$l['category']] ?? $l['category'] ?></span>
            <span class="text-[10px] text-gray-400"><?= e($l['source']) ?></span>
            <span class="text-[10px] text-gray-400">·</span>
            <span class="text-[10px] text-gray-400"><?= date('d.m.Y H:i', strtotime($l['created_at'])) ?></span>
          </div>
          <h3 class="font-semibold text-gray-900 line-clamp-2"><?= e($l['name']) ?></h3>
          <?php if ($l['raw_snippet']): ?>
          <p class="text-xs text-gray-600 mt-1 line-clamp-2"><?= e($l['raw_snippet']) ?></p>
          <?php endif; ?>

          <!-- Contact info -->
          <div class="flex flex-wrap items-center gap-3 mt-3 text-xs">
            <a href="<?= e($l['source_url']) ?>" target="_blank" rel="noopener" class="text-brand hover:underline truncate max-w-xs">🔗 Quelle öffnen</a>
            <?php if ($l['email']): ?>
            <a href="mailto:<?= e($l['email']) ?>" class="text-blue-600 hover:underline">📧 <?= e($l['email']) ?></a>
            <?php endif; ?>
            <?php if ($l['phone']): ?>
            <a href="tel:<?= e($l['phone']) ?>" class="text-green-600 hover:underline">📞 <?= e($l['phone']) ?></a>
            <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $l['phone']) ?>" target="_blank" class="text-green-700 hover:underline">💬 WhatsApp</a>
            <?php endif; ?>
            <?php if (!$l['email'] && !$l['phone']): ?>
            <span class="text-gray-400">⚠ Keine Kontaktdaten — OSINT erforderlich</span>
            <?php endif; ?>
          </div>
        </div>

        <!-- Status actions -->
        <div class="flex flex-col gap-1 flex-shrink-0">
          <form method="POST" class="flex flex-col gap-1">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_status"/>
            <input type="hidden" name="lead_id" value="<?= $l['lead_id'] ?>"/>
            <select name="status" onchange="this.form.submit()" class="px-2 py-1.5 border border-gray-200 rounded-lg text-xs bg-white">
              <option value="new" <?= $l['status'] === 'new' ? 'selected' : '' ?>>🆕 Neu</option>
              <option value="contacted" <?= $l['status'] === 'contacted' ? 'selected' : '' ?>>📧 Kontaktiert</option>
              <option value="converted" <?= $l['status'] === 'converted' ? 'selected' : '' ?>>✅ Gewonnen</option>
              <option value="rejected" <?= $l['status'] === 'rejected' ? 'selected' : '' ?>>❌ Abgelehnt</option>
            </select>
          </form>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
