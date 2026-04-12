<?php
/**
 * Customer: KI Foto-Score — Cleanliness ratings for their completed jobs
 */
require_once __DIR__ . '/../includes/auth.php';
requireCustomer();
$title = 'Foto-Scores'; $page = 'photo-scores';
$cid = me()['id'];

// Get photo analyses for this customer's jobs
try {
    $scores = all("SELECT pa.*, j.j_date, j.j_time, j.job_status,
                    s.title as stitle, e.display_name as ename
                    FROM photo_analyses pa
                    LEFT JOIN jobs j ON pa.job_id_fk=j.j_id
                    LEFT JOIN services s ON j.s_id_fk=s.s_id
                    LEFT JOIN employee e ON j.emp_id_fk=e.emp_id
                    WHERE j.customer_id_fk=? AND j.status=1
                    ORDER BY pa.created_at DESC LIMIT 50", [$cid]);
} catch (Exception $e) {
    $scores = [];
}

$avgScore = !empty($scores) ? round(array_sum(array_column($scores, 'score')) / count($scores), 1) : 0;
$totalScores = count($scores);
$passCount = count(array_filter($scores, fn($s) => $s['score'] >= 7));
$failCount = $totalScores - $passCount;

include __DIR__ . '/../includes/layout-customer.php';
?>

<!-- Stats Cards -->
<div class="grid grid-cols-3 gap-4 mb-6">
  <div class="card-elev p-5 text-center">
    <div class="text-3xl font-bold <?= $avgScore >= 7 ? 'text-green-600' : ($avgScore >= 5 ? 'text-amber-600' : 'text-red-600') ?>"><?= $avgScore ?></div>
    <div class="text-xs text-gray-500 mt-1">Durchschnitt</div>
  </div>
  <div class="card-elev p-5 text-center">
    <div class="text-3xl font-bold text-green-600"><?= $passCount ?></div>
    <div class="text-xs text-gray-500 mt-1">Bestanden</div>
  </div>
  <div class="card-elev p-5 text-center">
    <div class="text-3xl font-bold text-red-600"><?= $failCount ?></div>
    <div class="text-xs text-gray-500 mt-1">Nachbessern</div>
  </div>
</div>

<?php if (empty($scores)): ?>
<div class="card-elev p-8 text-center">
  <div class="w-16 h-16 rounded-full bg-gray-100 flex items-center justify-center mx-auto mb-4">
    <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
  </div>
  <h3 class="font-semibold text-gray-900 mb-1">Noch keine Foto-Scores</h3>
  <p class="text-sm text-gray-500">Nach abgeschlossenen Terminen sehen Sie hier die KI-Bewertung der Sauberkeit.</p>
</div>
<?php else: ?>

<!-- Score Progress Bar -->
<?php if ($totalScores > 0): ?>
<div class="card-elev p-5 mb-6">
  <div class="flex items-center justify-between mb-2">
    <span class="text-sm font-medium text-gray-700">Qualitaetsindex</span>
    <span class="text-sm font-bold <?= $avgScore >= 7 ? 'text-green-600' : 'text-amber-600' ?>"><?= $avgScore ?>/10</span>
  </div>
  <div class="w-full bg-gray-200 rounded-full h-3">
    <div class="h-3 rounded-full transition-all <?= $avgScore >= 7 ? 'bg-green-500' : ($avgScore >= 5 ? 'bg-amber-500' : 'bg-red-500') ?>" style="width: <?= $avgScore * 10 ?>%"></div>
  </div>
  <p class="text-xs text-gray-400 mt-2"><?= $passCount ?> von <?= $totalScores ?> Bewertungen bestanden (Score 7+)</p>
</div>
<?php endif; ?>

<!-- Scores List -->
<div class="space-y-3">
<?php foreach ($scores as $s):
    $sc = (int)$s['score'];
    $analysis = json_decode($s['analysis_json'] ?? '', true) ?: [];
    $passed = $sc >= 7;
?>
  <div class="card-elev p-4">
    <div class="flex items-start gap-4">
      <!-- Score Badge -->
      <div class="w-14 h-14 rounded-xl flex-shrink-0 flex items-center justify-center text-white font-bold text-xl
        <?= $passed ? 'bg-green-500' : ($sc >= 5 ? 'bg-amber-500' : 'bg-red-500') ?>">
        <?= $sc ?>
      </div>
      <!-- Details -->
      <div class="flex-1 min-w-0">
        <div class="flex items-center justify-between">
          <div>
            <span class="font-medium text-gray-900"><?= e($s['stitle'] ?: 'Service') ?></span>
            <span class="text-xs text-gray-400 ml-2"><?= e($s['photo_type'] ?? '') ?></span>
          </div>
          <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $passed ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
            <?= $passed ? 'Bestanden' : 'Nachbessern' ?>
          </span>
        </div>
        <div class="text-xs text-gray-500 mt-1">
          <?= date('d.m.Y', strtotime($s['j_date'])) ?> um <?= substr($s['j_time'], 0, 5) ?>
          <?php if ($s['ename']): ?> &mdash; <?= e($s['ename']) ?><?php endif; ?>
        </div>
        <?php if (!empty($analysis['verdict'])): ?>
        <p class="text-sm text-gray-600 mt-2"><?= e($analysis['verdict']) ?></p>
        <?php endif; ?>
        <?php if (!empty($analysis['issues'])): ?>
        <div class="flex flex-wrap gap-1 mt-2">
          <?php foreach ($analysis['issues'] as $issue): ?>
          <span class="px-2 py-0.5 bg-gray-100 text-xs text-gray-600 rounded"><?= e($issue) ?></span>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <!-- Photo thumbnail -->
      <?php if ($s['photo_path']): ?>
      <a href="<?= e($s['photo_path']) ?>" target="_blank" class="flex-shrink-0">
        <img src="<?= e($s['photo_path']) ?>" class="w-16 h-16 rounded-lg object-cover border" alt="Foto"/>
      </a>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer-customer.php'; ?>
