<?php
/**
 * Admin — Partner-Verfügbarkeit Übersicht
 * Alle aktuellen und kommenden Absenzen + Job-Kollisionen
 */
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Partner-Verfügbarkeit'; $page = 'availability';

$today = date('Y-m-d');
$in30 = date('Y-m-d', strtotime('+30 days'));

// All upcoming absences
$absences = all("
    SELECT ea.*, e.name, e.surname, e.display_name
    FROM employee_availability ea
    LEFT JOIN employee e ON ea.emp_id_fk = e.emp_id
    WHERE ea.date_to >= ? AND e.status = 1
    ORDER BY ea.date_from
", [$today]);

// Collision check per absence
foreach ($absences as &$ab) {
    $ab['collisions'] = all("
        SELECT j_id, j_date, j_time, s.title AS stitle, c.name AS cname
        FROM jobs j
        LEFT JOIN services s ON j.s_id_fk = s.s_id
        LEFT JOIN customer c ON j.customer_id_fk = c.customer_id
        WHERE j.emp_id_fk = ? AND j.j_date BETWEEN ? AND ?
          AND j.status = 1 AND j.job_status NOT IN ('CANCELLED','COMPLETED')
        ORDER BY j.j_date, j.j_time
    ", [$ab['emp_id_fk'], $ab['date_from'], $ab['date_to']]);
}
unset($ab);

$totalAbsences = count($absences);
$totalCollisions = array_sum(array_map(fn($a) => count($a['collisions']), $absences));
$absentToday = count(array_filter($absences, fn($a) => $a['date_from'] <= $today && $a['date_to'] >= $today));
$absentIn30 = count(array_filter($absences, fn($a) => $a['date_from'] <= $in30));

$typeLabels = [
    'urlaub' => ['🏖 Urlaub', 'bg-blue-100 text-blue-700'],
    'krank' => ['🤒 Krank', 'bg-red-100 text-red-700'],
    'privat' => ['👤 Privat', 'bg-gray-100 text-gray-700'],
    'schule' => ['🎓 Schule', 'bg-purple-100 text-purple-700'],
    'sonstiges' => ['📌 Sonstiges', 'bg-amber-100 text-amber-700'],
];

include __DIR__ . '/../includes/layout.php';
?>

<div class="mb-6">
  <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-2">📅 Partner-Verfügbarkeit</h1>
  <p class="text-sm text-gray-500 mt-1">Alle eingetragenen Absenzen + automatische Kollisions-Prüfung mit gebuchten Jobs.</p>
</div>

<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
  <div class="bg-white rounded-xl border p-4">
    <div class="text-2xl font-extrabold text-gray-900"><?= $totalAbsences ?></div>
    <div class="text-[11px] uppercase text-gray-500 mt-1">Aktive Einträge</div>
  </div>
  <div class="bg-white rounded-xl border p-4">
    <div class="text-2xl font-extrabold <?= $absentToday > 0 ? 'text-red-600' : 'text-gray-900' ?>"><?= $absentToday ?></div>
    <div class="text-[11px] uppercase text-gray-500 mt-1">Heute abwesend</div>
  </div>
  <div class="bg-white rounded-xl border p-4">
    <div class="text-2xl font-extrabold text-gray-900"><?= $absentIn30 ?></div>
    <div class="text-[11px] uppercase text-gray-500 mt-1">In nächsten 30 Tagen</div>
  </div>
  <div class="bg-white rounded-xl border p-4 <?= $totalCollisions > 0 ? 'bg-red-50 border-red-200' : '' ?>">
    <div class="text-2xl font-extrabold <?= $totalCollisions > 0 ? 'text-red-600' : 'text-green-600' ?>"><?= $totalCollisions ?></div>
    <div class="text-[11px] uppercase text-gray-500 mt-1">🚨 Job-Kollisionen</div>
  </div>
</div>

<?php if (empty($absences)): ?>
<div class="bg-white rounded-xl border p-12 text-center">
  <div class="text-5xl mb-3">✅</div>
  <h3 class="font-bold text-gray-900">Keine Absenzen</h3>
  <p class="text-sm text-gray-500 mt-1">Alle Partner sind verfügbar.</p>
</div>
<?php else: ?>
<div class="bg-white rounded-xl border overflow-hidden">
  <div class="divide-y">
    <?php foreach ($absences as $ab):
      $t = $typeLabels[$ab['type']] ?? $typeLabels['sonstiges'];
      $days = (int)((strtotime($ab['date_to']) - strtotime($ab['date_from'])) / 86400) + 1;
      $partnerName = $ab['display_name'] ?: trim(($ab['name'] ?? '') . ' ' . ($ab['surname'] ?? ''));
      $isCurrent = $ab['date_from'] <= $today && $ab['date_to'] >= $today;
      $collCount = count($ab['collisions']);
    ?>
    <div class="p-5 <?= $isCurrent ? 'bg-blue-50/30' : '' ?> <?= $collCount > 0 ? 'bg-red-50/30' : '' ?>">
      <div class="flex items-start justify-between gap-3 flex-wrap">
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2 flex-wrap mb-1">
            <span class="px-2 py-0.5 rounded-full text-[11px] font-bold <?= $t[1] ?>"><?= e($t[0]) ?></span>
            <a href="/admin/view-employee.php?id=<?= (int)$ab['emp_id_fk'] ?>" class="font-bold text-gray-900 hover:text-brand"><?= e($partnerName) ?></a>
            <?php if ($isCurrent): ?><span class="px-1.5 py-0.5 rounded bg-blue-500 text-white text-[10px] font-bold uppercase">Aktiv</span><?php endif; ?>
          </div>
          <div class="text-sm text-gray-700 font-semibold">
            <?= date('d.m.', strtotime($ab['date_from'])) ?>
            <?php if ($ab['date_from'] !== $ab['date_to']): ?> — <?= date('d.m.Y', strtotime($ab['date_to'])) ?><?php else: ?><?= date('.Y', strtotime($ab['date_from'])) ?><?php endif; ?>
            <span class="text-[11px] text-gray-400 font-normal">· <?= $days ?> Tag<?= $days === 1 ? '' : 'e' ?></span>
          </div>
          <?php if ($ab['note']): ?><div class="text-[11px] text-gray-500 italic mt-0.5"><?= e($ab['note']) ?></div><?php endif; ?>
        </div>
      </div>

      <?php if ($collCount > 0): ?>
      <div class="mt-3 p-3 rounded-lg bg-red-100 border border-red-200">
        <div class="flex items-center gap-2 mb-2">
          <span class="text-red-600">🚨</span>
          <span class="text-xs font-bold text-red-900 uppercase"><?= $collCount ?> Job<?= $collCount === 1 ? '' : 's' ?> kollidieren mit der Abwesenheit</span>
        </div>
        <div class="space-y-1">
          <?php foreach ($ab['collisions'] as $col): ?>
          <div class="flex items-center gap-2 text-xs">
            <span class="font-mono text-red-700"><?= date('d.m.', strtotime($col['j_date'])) ?> <?= substr($col['j_time'], 0, 5) ?></span>
            <span class="text-gray-700 truncate"><?= e($col['stitle']) ?> · <?= e($col['cname']) ?></span>
            <a href="/admin/jobs.php?j=<?= (int)$col['j_id'] ?>" class="ml-auto px-2 py-0.5 bg-red-600 hover:bg-red-700 text-white rounded text-[10px] font-bold whitespace-nowrap">Umplanen →</a>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
