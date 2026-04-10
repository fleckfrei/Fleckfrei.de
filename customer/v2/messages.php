<?php
require_once __DIR__ . '/../../includes/auth.php';
requireCustomer();
$title = 'Chat'; $page = 'messages';
$cid = me()['id'];

// Try to fetch messages — if table doesn't exist or is empty, show empty state
$threads = [];
try {
    $threads = all("
        SELECT m.*, j.j_date, j.j_time, s.title as stitle, e.name as ename, e.surname as esurname
        FROM messages m
        LEFT JOIN jobs j ON m.j_id_fk = j.j_id
        LEFT JOIN services s ON j.s_id_fk = s.s_id
        LEFT JOIN employee e ON j.emp_id_fk = e.emp_id
        WHERE m.customer_id_fk = ?
        ORDER BY m.created_at DESC
        LIMIT 50
    ", [$cid]);
} catch (Exception $e) {
    $threads = [];
}

include __DIR__ . '/../../includes/layout-v2.php';
?>

<div class="mb-6">
  <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Chat</h1>
  <p class="text-gray-500 mt-1 text-sm">Nachrichten mit Ihrem Partner.</p>
</div>

<?php if (empty($threads)): ?>
<!-- Empty state (Helpling-style) -->
<div class="card-elev text-center py-20 px-4">
  <div class="inline-flex items-center justify-center w-24 h-24 rounded-full bg-brand-light mb-5">
    <svg class="w-12 h-12 text-brand" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
  </div>
  <h3 class="text-xl font-bold text-gray-900 mb-2">Noch keine Nachrichten</h3>
  <p class="text-sm text-gray-500 max-w-md mx-auto mb-6">
    Nach Ihrer ersten Buchung wird hier der direkte Chat mit Ihrem Reinigungspartner freigeschaltet.
    Ihre zentrale Anlaufstelle für alle Fragen rund um den Termin.
  </p>
  <a href="/customer/v2/booking.php" class="inline-flex items-center gap-2 px-6 py-3 bg-brand hover:bg-brand-dark text-white rounded-xl font-semibold text-sm shadow-sm transition">
    Jetzt buchen und Chat freischalten
  </a>
</div>
<?php else: ?>
<div class="card-elev">
  <div class="divide-y">
    <?php foreach ($threads as $t): ?>
    <div class="p-5 flex items-start gap-4 hover:bg-gray-50 transition">
      <div class="w-12 h-12 rounded-full bg-gradient-to-br from-brand to-brand-dark text-white flex items-center justify-center font-bold flex-shrink-0">
        <?= strtoupper(substr($t['ename'] ?? 'P', 0, 1) . substr($t['esurname'] ?? '', 0, 1)) ?>
      </div>
      <div class="flex-1 min-w-0">
        <div class="flex justify-between items-baseline gap-2">
          <div class="font-semibold text-gray-900 truncate"><?= e(($t['ename'] ?? 'Partner') . ' ' . ($t['esurname'] ?? '')) ?></div>
          <div class="text-[11px] text-gray-400 flex-shrink-0"><?= !empty($t['created_at']) ? date('d.m. H:i', strtotime($t['created_at'])) : '' ?></div>
        </div>
        <div class="text-xs text-gray-500 mb-1"><?= e($t['stitle'] ?? 'Service') ?> · <?= !empty($t['j_date']) ? date('d.m.Y', strtotime($t['j_date'])) : '' ?></div>
        <div class="text-sm text-gray-700 line-clamp-2"><?= e(mb_strimwidth($t['message'] ?? '', 0, 140, '…')) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<div class="mt-4 text-center">
  <a href="/customer/messages.php" class="text-sm text-brand hover:text-brand-dark font-medium">Vollständiger Chat (v1) →</a>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer-v2.php'; ?>
