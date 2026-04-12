<?php
/**
 * Public Partner Profile — Customer sees partner bio, stats, ratings
 * URL: /customer/partner.php?id=42
 * Shows ONLY information meant for customer view (no private data).
 */
require_once __DIR__ . '/../includes/auth.php';
requireCustomer();
$title = 'Partner-Profil';
$page = 'partner';
$cid = me()['id'];

$pid = (int)($_GET['id'] ?? 0);
if (!$pid) { header('Location: /customer/'); exit; }

// Customer can only see partners they have worked with (privacy)
$hasWorkedTogether = (int) val("SELECT COUNT(*) FROM jobs WHERE customer_id_fk=? AND emp_id_fk=? AND status=1", [$cid, $pid]);
if (!$hasWorkedTogether) { header('Location: /customer/'); exit; }

$partner = one("SELECT emp_id, display_name, name, profile_pic, language, partner_type, contract_type, company_name, bio, created_at
                FROM employee WHERE emp_id=? AND status=1", [$pid]);
if (!$partner) { header('Location: /customer/'); exit; }

// Rating stats (across ALL customers, anonymized)
$ratingStats = one("SELECT AVG(stars) AS avg_stars, COUNT(*) AS total, SUM(CASE WHEN stars=5 THEN 1 ELSE 0 END) AS fives, SUM(CASE WHEN stars<3 THEN 1 ELSE 0 END) AS lows FROM job_ratings WHERE emp_id_fk=?", [$pid]);
$avgStars = round((float)($ratingStats['avg_stars'] ?? 0), 1);
$totalRatings = (int)($ratingStats['total'] ?? 0);

// Recent ratings with comments (from OTHER customers too, no names)
$recentRatings = all("SELECT r.stars, r.comment, r.photo, r.created_at FROM job_ratings r
                     WHERE r.emp_id_fk=? AND r.comment IS NOT NULL AND r.comment != ''
                     ORDER BY r.created_at DESC LIMIT 10", [$pid]);

// Stats from THIS customer specifically
$mineStats = one("SELECT COUNT(*) AS total_jobs,
                         SUM(COALESCE(total_hours, j_hours)) AS total_hours,
                         MIN(j_date) AS first_job,
                         MAX(j_date) AS last_job
                  FROM jobs WHERE customer_id_fk=? AND emp_id_fk=? AND status=1 AND job_status='COMPLETED'", [$cid, $pid]);

$partnerTypeLabel = match($partner['partner_type'] ?? '') {
    'mitarbeiter' => '👷 Mitarbeiter',
    'freelancer'  => '🧑‍💼 Freelancer',
    'kleinunternehmen' => '🏢 Kleinunternehmen' . ($partner['company_name'] ? ' · ' . $partner['company_name'] : ''),
    default => null,
};

$langLabel = match($partner['language'] ?? 'de') {
    'de' => '🇩🇪 Deutsch',
    'en' => '🇬🇧 English',
    'ro' => '🇷🇴 Română',
    'ru' => '🇷🇺 Русский',
    'pl' => '🇵🇱 Polski',
    'tr' => '🇹🇷 Türkçe',
    'ar' => '🇸🇦 العربية',
    default => strtoupper($partner['language'] ?? 'de'),
};

include __DIR__ . '/../includes/layout-customer.php';
?>

<!-- Back -->
<div class="mb-4">
  <a href="/customer/" class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-brand">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
    Zurück
  </a>
</div>

<!-- Header card -->
<div class="card-elev overflow-hidden mb-6">
  <div class="relative h-24 bg-gradient-to-br from-brand to-brand-dark"></div>
  <div class="px-6 pb-6 -mt-12 relative">
    <div class="flex items-end justify-between gap-4 flex-wrap">
      <?php
        $avatar = $partner['profile_pic'] ?? '';
        if ($avatar && !str_starts_with($avatar, '/') && !str_starts_with($avatar, 'http')) $avatar = '/uploads/' . ltrim($avatar, '/');
      ?>
      <?php if ($avatar): ?>
        <img src="<?= e($avatar) ?>" class="w-24 h-24 rounded-full object-cover border-4 border-white shadow-lg"/>
      <?php else: ?>
        <div class="w-24 h-24 rounded-full bg-brand text-white flex items-center justify-center text-3xl font-extrabold border-4 border-white shadow-lg">
          <?= strtoupper(substr($partner['display_name'] ?: $partner['name'], 0, 1)) ?>
        </div>
      <?php endif; ?>
      <?php if ($totalRatings > 0): ?>
      <div class="flex items-center gap-2 bg-amber-50 border border-amber-200 rounded-xl px-4 py-2 mt-4">
        <span class="text-2xl">⭐</span>
        <div>
          <div class="font-extrabold text-gray-900 text-xl"><?= number_format($avgStars, 1) ?></div>
          <div class="text-[10px] text-gray-500 uppercase font-semibold"><?= $totalRatings ?> Bewertung<?= $totalRatings === 1 ? '' : 'en' ?></div>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <div class="mt-3">
      <h1 class="text-2xl font-bold text-gray-900"><?= e($partner['display_name'] ?: $partner['name']) ?></h1>
      <div class="flex items-center gap-2 mt-1 flex-wrap text-sm text-gray-500">
        <?php if ($partnerTypeLabel): ?><span><?= e($partnerTypeLabel) ?></span>· <?php endif; ?>
        <span><?= e($langLabel) ?></span>
        <?php if ($partner['created_at']): ?>· <span>Seit <?= date('Y', strtotime($partner['created_at'])) ?></span><?php endif; ?>
      </div>
    </div>
    <?php if (!empty($partner['bio'])): ?>
    <div class="mt-4 p-4 rounded-xl bg-brand-light/50 border border-brand/10">
      <div class="text-[11px] uppercase font-bold text-brand-dark tracking-wide mb-1">Über mich</div>
      <div class="text-sm text-gray-700 leading-relaxed"><?= nl2br(e($partner['bio'])) ?></div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Shared history with THIS customer -->
<?php if ($mineStats && (int)$mineStats['total_jobs'] > 0): ?>
<div class="card-elev p-5 mb-6">
  <h2 class="text-sm font-bold text-gray-500 uppercase tracking-wide mb-3">Ihre gemeinsame Historie</h2>
  <div class="grid grid-cols-3 gap-3 text-center">
    <div>
      <div class="text-2xl font-extrabold text-brand"><?= (int)$mineStats['total_jobs'] ?></div>
      <div class="text-[10px] uppercase text-gray-500">Jobs gesamt</div>
    </div>
    <div>
      <div class="text-2xl font-extrabold text-gray-900"><?= number_format((float)$mineStats['total_hours'], 1) ?>h</div>
      <div class="text-[10px] uppercase text-gray-500">Stunden</div>
    </div>
    <div>
      <?php $daysWorking = $mineStats['first_job'] ? (int) ((time() - strtotime($mineStats['first_job'])) / 86400) : 0; ?>
      <div class="text-2xl font-extrabold text-indigo-600"><?= $daysWorking ?></div>
      <div class="text-[10px] uppercase text-gray-500">Tage zusammen</div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Rating breakdown + recent reviews -->
<?php if ($totalRatings > 0): ?>
<div class="card-elev overflow-hidden mb-6">
  <div class="px-5 py-3 border-b border-gray-100 bg-gradient-to-r from-amber-50 to-transparent">
    <h2 class="font-bold text-gray-900 flex items-center gap-2">
      <span>⭐</span> Bewertungen ({<?= $totalRatings ?>})
    </h2>
  </div>
  <!-- Breakdown -->
  <div class="px-5 py-4 border-b border-gray-100">
    <?php
      $breakdown = [];
      for ($s = 5; $s >= 1; $s--) {
        $count = (int) val("SELECT COUNT(*) FROM job_ratings WHERE emp_id_fk=? AND stars=?", [$pid, $s]);
        $breakdown[$s] = $count;
      }
    ?>
    <?php for ($s = 5; $s >= 1; $s--):
      $pct = $totalRatings > 0 ? round($breakdown[$s] / $totalRatings * 100) : 0;
    ?>
    <div class="flex items-center gap-3 text-xs mb-1.5">
      <span class="w-8 font-mono"><?= $s ?> ⭐</span>
      <div class="flex-1 h-2 bg-gray-100 rounded-full overflow-hidden">
        <div class="h-full bg-amber-400" style="width: <?= $pct ?>%"></div>
      </div>
      <span class="w-10 text-right text-gray-500 font-mono"><?= $breakdown[$s] ?></span>
    </div>
    <?php endfor; ?>
  </div>

  <!-- Recent reviews -->
  <?php if (!empty($recentRatings)): ?>
  <div class="divide-y divide-gray-100">
    <?php foreach ($recentRatings as $r): ?>
    <div class="px-5 py-3">
      <div class="flex items-center gap-2 mb-1">
        <span class="text-amber-500"><?= str_repeat('⭐', (int)$r['stars']) ?></span>
        <span class="text-[10px] text-gray-400"><?= date('d.m.Y', strtotime($r['created_at'])) ?></span>
      </div>
      <?php if (!empty($r['comment'])): ?>
      <div class="text-sm text-gray-700 italic">„<?= e($r['comment']) ?>"</div>
      <?php endif; ?>
      <?php if (!empty($r['photo'])): ?>
      <a href="<?= e($r['photo']) ?>" target="_blank"><img src="<?= e($r['photo']) ?>" class="w-20 h-20 object-cover rounded-lg border mt-2"/></a>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php else: ?>
<div class="card-elev p-8 text-center mb-6">
  <div class="text-4xl mb-2">⭐</div>
  <h3 class="font-bold text-gray-900">Noch keine Bewertungen</h3>
  <p class="text-sm text-gray-500 mt-1">Seien Sie der Erste der eine Bewertung abgibt!</p>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer-customer.php'; ?>
