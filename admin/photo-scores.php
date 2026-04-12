<?php
/**
 * Admin: KI Foto-Score Dashboard — all AI cleanliness ratings at a glance
 */
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'KI Foto-Scores'; $page = 'checklists';

// Ensure table exists
try {
    $scores = all("SELECT pa.*, j.j_date, j.j_time, j.job_status, j.customer_id_fk,
                    c.name as cname, e.display_name as ename, s.title as stitle
                    FROM photo_analyses pa
                    LEFT JOIN jobs j ON pa.job_id_fk=j.j_id
                    LEFT JOIN customer c ON j.customer_id_fk=c.customer_id
                    LEFT JOIN employee e ON j.emp_id_fk=e.emp_id
                    LEFT JOIN services s ON j.s_id_fk=s.s_id
                    ORDER BY pa.created_at DESC LIMIT 50");
} catch (Exception $e) {
    $scores = [];
}

$avgScore = !empty($scores) ? round(array_sum(array_column($scores, 'score')) / count($scores), 1) : 0;
$passCount = count(array_filter($scores, fn($s) => $s['score'] >= 7));
$failCount = count($scores) - $passCount;

include __DIR__ . '/../includes/layout.php';
?>

<div class="mb-6">
  <h1 class="text-2xl font-bold text-gray-900">KI Foto-Scores</h1>
  <p class="text-sm text-gray-500 mt-1">Automatische Sauberkeits-Bewertung via Groq Vision AI</p>
</div>

<!-- Stats -->
<div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
  <div class="bg-white rounded-xl border p-5 text-center">
    <div class="text-3xl font-bold text-brand"><?= $avgScore ?>/10</div>
    <div class="text-xs text-gray-500 mt-1 uppercase font-semibold">Ø Score</div>
  </div>
  <div class="bg-white rounded-xl border p-5 text-center">
    <div class="text-3xl font-bold text-gray-900"><?= count($scores) ?></div>
    <div class="text-xs text-gray-500 mt-1 uppercase font-semibold">Analysen</div>
  </div>
  <div class="bg-white rounded-xl border p-5 text-center">
    <div class="text-3xl font-bold text-green-600"><?= $passCount ?></div>
    <div class="text-xs text-gray-500 mt-1 uppercase font-semibold">Bestanden</div>
  </div>
  <div class="bg-white rounded-xl border p-5 text-center">
    <div class="text-3xl font-bold text-red-600"><?= $failCount ?></div>
    <div class="text-xs text-gray-500 mt-1 uppercase font-semibold">Nachbessern</div>
  </div>
</div>

<?php if (empty($scores)): ?>
<div class="bg-white rounded-xl border p-16 text-center">
  <div class="text-5xl mb-4">🤖</div>
  <h3 class="text-lg font-bold text-gray-900 mb-2">Noch keine Foto-Analysen</h3>
  <p class="text-sm text-gray-500">Partner können Fotos beim Job-Abschluss analysieren lassen. Die Ergebnisse erscheinen hier.</p>
</div>
<?php else: ?>

<!-- Scores Table -->
<div class="bg-white rounded-xl border overflow-hidden">
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead>
        <tr class="bg-gray-50 text-left text-xs text-gray-500 uppercase tracking-wider">
          <th class="px-4 py-3">Score</th>
          <th class="px-4 py-3">Typ</th>
          <th class="px-4 py-3">Job</th>
          <th class="px-4 py-3">Kunde</th>
          <th class="px-4 py-3">Partner</th>
          <th class="px-4 py-3">Foto</th>
          <th class="px-4 py-3">Details</th>
          <th class="px-4 py-3">Datum</th>
        </tr>
      </thead>
      <tbody class="divide-y">
        <?php foreach ($scores as $s):
          $scoreColor = $s['score'] >= 8 ? 'bg-green-500' : ($s['score'] >= 6 ? 'bg-amber-500' : 'bg-red-500');
          $analysis = json_decode($s['analysis_json'] ?? '', true) ?: [];
        ?>
        <tr class="hover:bg-gray-50">
          <td class="px-4 py-3">
            <div class="flex items-center gap-2">
              <div class="w-10 h-10 rounded-lg <?= $scoreColor ?> text-white flex items-center justify-center font-bold text-lg">
                <?= (int)$s['score'] ?>
              </div>
              <span class="text-xs font-semibold <?= $s['score'] >= 7 ? 'text-green-700' : 'text-red-700' ?>">
                <?= $s['score'] >= 7 ? '✓ OK' : '✕ Fail' ?>
              </span>
            </div>
          </td>
          <td class="px-4 py-3">
            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase
              <?= match($s['photo_type']) { 'before' => 'bg-blue-100 text-blue-700', 'after' => 'bg-green-100 text-green-700', 'damage' => 'bg-red-100 text-red-700', default => 'bg-gray-100 text-gray-600' } ?>">
              <?= e($s['photo_type']) ?>
            </span>
          </td>
          <td class="px-4 py-3 font-mono text-xs">
            <?php if ($s['job_id_fk']): ?>
            <a href="/admin/jobs.php?highlight=<?= $s['job_id_fk'] ?>" class="text-brand hover:underline">#<?= $s['job_id_fk'] ?></a>
            <div class="text-[10px] text-gray-400"><?= date('d.m.', strtotime($s['j_date'] ?? '')) ?></div>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td class="px-4 py-3 text-xs"><?= e($s['cname'] ?? '—') ?></td>
          <td class="px-4 py-3 text-xs"><?= e($s['ename'] ?? '—') ?></td>
          <td class="px-4 py-3">
            <?php if ($s['photo_path']): ?>
            <a href="<?= e($s['photo_path']) ?>" target="_blank">
              <img src="<?= e($s['photo_path']) ?>" class="w-12 h-12 object-cover rounded-lg border hover:scale-150 transition"/>
            </a>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td class="px-4 py-3 text-xs max-w-[200px]">
            <?php if (!empty($analysis['verdict'])): ?>
            <div class="text-gray-700 truncate"><?= e($analysis['verdict']) ?></div>
            <?php endif; ?>
            <?php if (!empty($analysis['issues'])): ?>
            <div class="text-[10px] text-red-600 mt-0.5"><?= count($analysis['issues']) ?> Issues</div>
            <?php endif; ?>
          </td>
          <td class="px-4 py-3 text-xs text-gray-400 font-mono"><?= date('d.m. H:i', strtotime($s['created_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
