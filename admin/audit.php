<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Protokoll'; $page = 'audit';

$filter = $_GET['entity'] ?? '';
$logs = all("SELECT * FROM audit_log" . ($filter ? " WHERE entity=?" : "") . " ORDER BY created_at DESC LIMIT 200", $filter ? [$filter] : []);

include __DIR__ . '/../includes/layout.php';
?>

<div class="bg-white rounded-xl border">
  <div class="p-5 border-b flex items-center justify-between">
    <h3 class="font-semibold">Aktivitäts-Protokoll (<?= count($logs) ?>)</h3>
    <div class="flex gap-2">
      <select onchange="window.location='?entity='+this.value" class="px-3 py-2 border rounded-lg text-sm">
        <option value="">Alle</option>
        <option value="job" <?= $filter==='job'?'selected':'' ?>>Jobs</option>
        <option value="customer" <?= $filter==='customer'?'selected':'' ?>>Kunden</option>
        <option value="invoice" <?= $filter==='invoice'?'selected':'' ?>>Rechnungen</option>
      </select>
    </div>
  </div>
  <div class="divide-y max-h-[700px] overflow-y-auto">
    <?php foreach ($logs as $log): ?>
    <div class="px-5 py-3 flex items-start gap-3 hover:bg-gray-50">
      <div class="w-8 h-8 rounded-full bg-gray-100 flex items-center justify-center flex-shrink-0 text-xs font-bold text-gray-500"><?= strtoupper(substr($log['user_name'],0,1)) ?></div>
      <div class="flex-1 min-w-0">
        <div class="text-sm">
          <span class="font-medium text-gray-900"><?= e($log['user_name']) ?></span>
          <span class="text-gray-500"><?= e($log['action']) ?></span>
          <span class="text-brand font-medium"><?= e($log['entity']) ?> #<?= $log['entity_id'] ?></span>
        </div>
        <?php if ($log['details']): ?><div class="text-xs text-gray-400 mt-0.5"><?= e($log['details']) ?></div><?php endif; ?>
      </div>
      <div class="text-xs text-gray-400 flex-shrink-0">
        <div><?= date('d.m.Y', strtotime($log['created_at'])) ?></div>
        <div><?= date('H:i', strtotime($log['created_at'])) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if (empty($logs)): ?>
    <div class="px-5 py-8 text-center text-gray-400 text-sm">Noch keine Einträge.</div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
