<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
require_once __DIR__ . '/../includes/sync-engine.php';
$title = 'Sync'; $page = 'sync';

$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { header('Location: /admin/sync.php'); exit; }
    $act = $_POST['action'] ?? '';
    if ($act === 'run_all')          $result = sync_run_all();
    if ($act === 'sheet_to_db_svc')  $result = ['sheet_to_db_services' => sync_sheet_to_db_services()];
    if ($act === 'sheet_to_db_kw')   $result = ['sheet_to_db_keywords' => sync_sheet_to_db_keywords()];
    if ($act === 'db_to_sheet')      $result = ['db_to_sheet_platform' => sync_db_to_sheet_platform()];
    if ($act === 'snapshot_only')    $result = ['snapshot' => sync_snapshot()];
}

sync_ensure_schema();
$recentLog = all("SELECT * FROM sync_log ORDER BY sl_id DESC LIMIT 40");
$snapDir   = __DIR__ . '/../uploads/sync-snapshots';
$snapshots = is_dir($snapDir) ? array_reverse(array_map('basename', glob($snapDir . '/*.json') ?: [])) : [];
$stats = [
    'services_total'    => (int) val("SELECT COUNT(*) FROM services"),
    'services_active'   => (int) val("SELECT COUNT(*) FROM services WHERE status=1"),
    'services_with_kw'  => (int) val("SELECT COUNT(*) FROM services WHERE wa_keyword IS NOT NULL AND wa_keyword<>''"),
    'keywords_flow'     => (int) val("SELECT COUNT(*) FROM whatsapp_flow_keywords") ?: 0,
    'sync_runs'         => (int) val("SELECT COUNT(*) FROM sync_log"),
    'last_sync'         => val("SELECT MAX(created_at) FROM sync_log"),
];
include __DIR__ . '/../includes/layout.php';
?>

<div class="grid grid-cols-2 md:grid-cols-6 gap-3 mb-6">
  <div class="bg-white rounded-xl border p-4"><div class="text-xs text-gray-500">Services total</div><div class="text-2xl font-bold"><?= $stats['services_total'] ?></div></div>
  <div class="bg-white rounded-xl border p-4"><div class="text-xs text-gray-500">Services aktiv</div><div class="text-2xl font-bold"><?= $stats['services_active'] ?></div></div>
  <div class="bg-white rounded-xl border p-4"><div class="text-xs text-gray-500">Mit WA-Keyword</div><div class="text-2xl font-bold"><?= $stats['services_with_kw'] ?></div></div>
  <div class="bg-white rounded-xl border p-4"><div class="text-xs text-gray-500">Flow-Keywords</div><div class="text-2xl font-bold"><?= $stats['keywords_flow'] ?></div></div>
  <div class="bg-white rounded-xl border p-4"><div class="text-xs text-gray-500">Sync-Events</div><div class="text-2xl font-bold"><?= $stats['sync_runs'] ?></div></div>
  <div class="bg-white rounded-xl border p-4"><div class="text-xs text-gray-500">Letzter Sync</div><div class="text-sm font-semibold"><?= $stats['last_sync'] ? date('d.m. H:i', strtotime($stats['last_sync'])) : '—' ?></div></div>
</div>

<div class="bg-white rounded-xl border p-5 mb-6">
  <h3 class="font-bold text-lg mb-3">🔄 Sync-Aktionen</h3>
  <p class="text-sm text-gray-600 mb-4">
    Alle Aktionen sind <b>Zero-Data-Loss</b>: UPSERT-only (nie gelöscht), Snapshot vorher, Audit-Log.
    Sheet: <a href="https://docs.google.com/spreadsheets/d/1IuKJJgdJ5Ln0j99e1kEIaEZLFYKYKFDuOK5I-NjgV1g" target="_blank" class="text-brand underline">Calendar-Fleckfrei.de</a>
  </p>
  <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
    <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="run_all"/>
      <button class="w-full py-3 bg-brand text-white rounded-xl font-semibold hover:bg-brand-dark">▶ Alles syncen (empfohlen)</button>
    </form>
    <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="sheet_to_db_svc"/>
      <button class="w-full py-3 border rounded-xl text-sm font-semibold hover:bg-gray-50">📥 Sheet → DB (Services)</button>
    </form>
    <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="sheet_to_db_kw"/>
      <button class="w-full py-3 border rounded-xl text-sm font-semibold hover:bg-gray-50">📥 Sheet → DB (WA-Keywords)</button>
    </form>
    <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="db_to_sheet"/>
      <button class="w-full py-3 border rounded-xl text-sm font-semibold hover:bg-gray-50">📤 DB → Sheet (Platform-Svcs)</button>
    </form>
  </div>
  <form method="POST" class="mt-3"><?= csrfField() ?><input type="hidden" name="action" value="snapshot_only"/>
    <button class="text-xs text-gray-500 underline">📸 Nur Snapshot erstellen (Backup)</button>
  </form>
</div>

<?php if ($result): ?>
<div class="bg-emerald-50 border border-emerald-200 rounded-xl p-5 mb-6">
  <h3 class="font-bold text-emerald-900 mb-2">✅ Sync-Ergebnis</h3>
  <pre class="text-xs bg-white p-3 rounded border overflow-auto"><?= e(json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) ?></pre>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
  <div class="bg-white rounded-xl border p-5">
    <h3 class="font-bold mb-3">📋 Letzte 40 Sync-Events</h3>
    <div class="overflow-auto max-h-96">
    <table class="w-full text-xs">
      <thead class="bg-gray-50 sticky top-0"><tr>
        <th class="px-2 py-1.5 text-left">Zeit</th>
        <th class="px-2 py-1.5 text-left">Quelle</th>
        <th class="px-2 py-1.5 text-left">Entität</th>
        <th class="px-2 py-1.5 text-left">Aktion</th>
        <th class="px-2 py-1.5 text-left">Ref</th>
        <th class="px-2 py-1.5 text-left">Details</th>
      </tr></thead>
      <tbody class="divide-y">
      <?php foreach ($recentLog as $l): ?>
      <tr class="hover:bg-gray-50">
        <td class="px-2 py-1.5 text-gray-500"><?= date('d.m. H:i:s', strtotime($l['created_at'])) ?></td>
        <td class="px-2 py-1.5"><?= e($l['source']) ?></td>
        <td class="px-2 py-1.5"><?= e($l['entity']) ?></td>
        <td class="px-2 py-1.5"><span class="px-1.5 py-0.5 rounded text-[10px] font-semibold
          <?= match($l['action']) { 'insert'=>'bg-green-100 text-green-700', 'update'=>'bg-blue-100 text-blue-700', 'error'=>'bg-red-100 text-red-700', 'skip'=>'bg-gray-100 text-gray-500', default=>'bg-gray-50 text-gray-600' } ?>"><?= e($l['action']) ?></span></td>
        <td class="px-2 py-1.5 font-mono text-gray-500"><?= e($l['ref_id'] ?: '—') ?></td>
        <td class="px-2 py-1.5 text-gray-600"><?= e(mb_strimwidth($l['details'], 0, 100, '…')) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($recentLog)): ?><tr><td colspan="6" class="px-2 py-8 text-center text-gray-400">Noch kein Sync gelaufen.</td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
  </div>

  <div class="bg-white rounded-xl border p-5">
    <h3 class="font-bold mb-3">📸 Snapshots (30 letzte)</h3>
    <div class="max-h-96 overflow-auto space-y-1 text-sm">
      <?php foreach ($snapshots as $snap): ?>
        <div class="flex justify-between items-center py-1 border-b last:border-0">
          <span class="font-mono text-xs"><?= e($snap) ?></span>
          <span class="text-xs text-gray-400"><?= e(date('d.m. H:i:s', strtotime(str_replace('_', ' ', substr($snap, 0, 17))))) ?></span>
        </div>
      <?php endforeach; ?>
      <?php if (empty($snapshots)): ?><div class="text-gray-400">Noch kein Snapshot.</div><?php endif; ?>
    </div>
    <p class="text-xs text-gray-500 mt-3">Snapshots liegen unter <code>uploads/sync-snapshots/</code> — bei Problem manuell restore möglich.</p>
  </div>
</div>

<?php include __DIR__.'/../includes/footer.php'; ?>
