<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Kunden-Urlaube'; $page = 'vacations';

$vacs = all("SELECT cv.*, c.name AS cname, c.email AS cemail
             FROM customer_vacations cv
             LEFT JOIN customer c ON cv.customer_id_fk=c.customer_id
             WHERE cv.status='active'
             ORDER BY cv.from_date DESC LIMIT 200");

$today = date('Y-m-d');
$stats = [
    'current'  => 0,
    'upcoming' => 0,
    'days'     => 0,
];
foreach ($vacs as $v) {
    if ($v['from_date'] <= $today && $v['to_date'] >= $today) $stats['current']++;
    elseif ($v['from_date'] > $today) $stats['upcoming']++;
    $stats['days'] += (strtotime($v['to_date']) - strtotime($v['from_date']))/86400 + 1;
}

include __DIR__ . '/../includes/layout.php';
?>

<div class="grid grid-cols-3 gap-3 mb-4">
  <div class="bg-white rounded-xl border p-4"><div class="text-xs text-gray-500">🏖 Aktuell im Urlaub</div><div class="text-2xl font-bold text-amber-600"><?= $stats['current'] ?></div></div>
  <div class="bg-white rounded-xl border p-4"><div class="text-xs text-gray-500">Geplante Urlaube</div><div class="text-2xl font-bold text-blue-600"><?= $stats['upcoming'] ?></div></div>
  <div class="bg-white rounded-xl border p-4"><div class="text-xs text-gray-500">Gesamt Urlaubstage</div><div class="text-2xl font-bold"><?= (int)$stats['days'] ?></div></div>
</div>

<div class="bg-white rounded-xl border">
  <div class="p-5 border-b"><h3 class="font-bold">Aktive Urlaube (<?= count($vacs) ?>)</h3></div>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-gray-50"><tr>
        <th class="px-3 py-2 text-left">Kunde</th>
        <th class="px-3 py-2 text-left">Von</th>
        <th class="px-3 py-2 text-left">Bis</th>
        <th class="px-3 py-2 text-left">Dauer</th>
        <th class="px-3 py-2 text-left">Status</th>
        <th class="px-3 py-2 text-left">Grund</th>
        <th class="px-3 py-2 text-left">Auto-Skip</th>
      </tr></thead>
      <tbody class="divide-y">
      <?php foreach ($vacs as $v):
        $days = (strtotime($v['to_date']) - strtotime($v['from_date']))/86400 + 1;
        $isCurrent  = $v['from_date'] <= $today && $v['to_date'] >= $today;
        $isUpcoming = $v['from_date'] > $today;
      ?>
      <tr class="hover:bg-gray-50">
        <td class="px-3 py-2"><a href="/admin/view-customer.php?id=<?= $v['customer_id_fk'] ?>" class="text-brand hover:underline font-semibold"><?= e($v['cname']) ?></a><div class="text-xs text-gray-500"><?= e($v['cemail']) ?></div></td>
        <td class="px-3 py-2"><?= date('d.m.Y', strtotime($v['from_date'])) ?></td>
        <td class="px-3 py-2"><?= date('d.m.Y', strtotime($v['to_date'])) ?></td>
        <td class="px-3 py-2"><?= (int)$days ?> Tage</td>
        <td class="px-3 py-2">
          <?php if ($isCurrent): ?><span class="px-2 py-0.5 bg-amber-100 text-amber-800 rounded text-xs font-semibold">🏖 Aktuell</span>
          <?php elseif ($isUpcoming): ?><span class="px-2 py-0.5 bg-blue-100 text-blue-700 rounded text-xs">Geplant</span>
          <?php else: ?><span class="px-2 py-0.5 bg-gray-100 text-gray-500 rounded text-xs">Vergangen</span>
          <?php endif; ?>
        </td>
        <td class="px-3 py-2 text-xs text-gray-600"><?= e($v['reason'] ?: '—') ?></td>
        <td class="px-3 py-2 text-center"><?= $v['auto_skip_jobs'] ? '✓' : '—' ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($vacs)): ?><tr><td colspan="7" class="px-3 py-8 text-center text-gray-400">Keine aktiven Urlaube.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__.'/../includes/footer.php'; ?>
