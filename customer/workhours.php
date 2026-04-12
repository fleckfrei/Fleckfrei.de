<?php
require_once __DIR__ . '/../includes/auth.php';
requireCustomer();
require_once __DIR__ . '/../includes/llm-keys.php';
if (!customerCan('workhours')) { header('Location: /customer/'); exit; }
$title = 'Arbeitsstunden'; $page = 'workhours';
$cid = me()['id'];
$customer = one("SELECT * FROM customer WHERE customer_id=?", [$cid]);

// ============================================================
// POST: Submit rating
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_rating') {
    if (!verifyCsrf()) { header('Location: /customer/workhours.php?error=csrf'); exit; }
    $jid = (int)($_POST['j_id'] ?? 0);
    $stars = max(1, min(5, (int)($_POST['stars'] ?? 5)));
    $comment = trim($_POST['comment'] ?? '');

    // Verify ownership
    $job = one("SELECT j.*, e.language AS elang, e.emp_id AS eid FROM jobs j LEFT JOIN employee e ON j.emp_id_fk=e.emp_id WHERE j.j_id=? AND j.customer_id_fk=?", [$jid, $cid]);
    if (!$job) { header('Location: /customer/workhours.php?error=not_found'); exit; }

    // Auto-translate comment to partner language via Groq (if not German)
    $translated = null;
    $translatedTo = null;
    $partnerLang = $job['elang'] ?: 'de';
    if ($comment !== '' && $partnerLang !== 'de' && defined('GROQ_API_KEY') && GROQ_API_KEY) {
        $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . GROQ_API_KEY,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => 'llama-3.3-70b-versatile',
                'messages' => [
                    ['role' => 'system', 'content' => "You are a translator. Translate the customer feedback to {$partnerLang}. Output ONLY the translation, no explanations, no quotes."],
                    ['role' => 'user', 'content' => $comment],
                ],
                'temperature' => 0.2,
                'max_tokens' => 300,
            ]),
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        if ($resp) {
            $json = json_decode($resp, true);
            $translated = trim($json['choices'][0]['message']['content'] ?? '');
            if ($translated) $translatedTo = $partnerLang;
        }
    }

    // Upsert rating
    q("INSERT INTO job_ratings (j_id_fk, customer_id_fk, emp_id_fk, stars, comment, comment_translated, translated_to)
       VALUES (?, ?, ?, ?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE stars=VALUES(stars), comment=VALUES(comment), comment_translated=VALUES(comment_translated), translated_to=VALUES(translated_to), created_at=CURRENT_TIMESTAMP",
      [$jid, $cid, $job['emp_id_fk'] ?: null, $stars, $comment ?: null, $translated, $translatedTo]);

    audit('create', 'job_rating', $jid, "Kunde hat Job #$jid bewertet: $stars★");
    header("Location: /customer/workhours.php?month=" . urlencode($_POST['month'] ?? date('Y-m')) . "&rated=$jid"); exit;
}

// ============================================================
// Read jobs
// ============================================================
$month = $_GET['month'] ?? date('Y-m');
$monthLabel = date('F Y', strtotime($month . '-01'));
$monthLabelDe = strtr($monthLabel, [
    'January' => 'Januar', 'February' => 'Februar', 'March' => 'März', 'April' => 'April',
    'May' => 'Mai', 'June' => 'Juni', 'July' => 'Juli', 'August' => 'August',
    'September' => 'September', 'October' => 'Oktober', 'November' => 'November', 'December' => 'Dezember'
]);

// All jobs in month (including CANCELLED for cancel-rate)
$allJobs = all("
    SELECT j.*, s.title as stitle, s.price as sprice,
           e.display_name as edisplay, e.profile_pic as eavatar,
           r.stars as r_stars, r.comment as r_comment, r.created_at as r_created
    FROM jobs j
    LEFT JOIN services s ON j.s_id_fk = s.s_id
    LEFT JOIN employee e ON j.emp_id_fk = e.emp_id
    LEFT JOIN job_ratings r ON r.j_id_fk = j.j_id
    WHERE j.customer_id_fk = ? AND j.j_date LIKE ? AND j.status = 1
    ORDER BY j.j_date DESC, j.j_time DESC
", [$cid, "$month%"]);

// Load all communications for these jobs in one batch
$jobIds = array_column($allJobs, 'j_id');
$commsByJob = [];
if (!empty($jobIds)) {
    $ph = implode(',', array_fill(0, count($jobIds), '?'));
    $comms = all("SELECT * FROM job_communications WHERE j_id_fk IN ($ph) AND sender_type IN ('partner','admin') ORDER BY created_at ASC", $jobIds);
    foreach ($comms as $c) {
        $commsByJob[$c['j_id_fk']][] = $c;
    }
}

// Split by status
$completed = [];
$cancelled = [];
$other = [];
foreach ($allJobs as $j) {
    if ($j['job_status'] === 'COMPLETED') $completed[] = $j;
    elseif ($j['job_status'] === 'CANCELLED') $cancelled[] = $j;
    else $other[] = $j;
}

// Totals
$totalHours = 0;
$totalCost = 0;
$ratedCount = 0;
$totalStars = 0;
foreach ($completed as $j) {
    $hrs = max(MIN_HOURS, $j['total_hours'] ?: $j['j_hours']);
    $totalHours += $hrs;
    $totalCost += $hrs * ($j['sprice'] ?: 0);
    if ($j['r_stars']) {
        $ratedCount++;
        $totalStars += $j['r_stars'];
    }
}
$avgRating = $ratedCount > 0 ? round($totalStars / $ratedCount, 1) : 0;

// Cancel rate breakdown by initiator
$cancelByCustomer = 0;
$cancelByFleckfrei = 0;
foreach ($cancelled as $j) {
    $role = strtolower($j['cancelled_role'] ?? '');
    if ($role === 'customer') $cancelByCustomer++;
    else $cancelByFleckfrei++;  // admin/employee/null → Fleckfrei-side
}
$totalJobsAll = count($completed) + count($cancelled);
$cancelRate = $totalJobsAll > 0 ? round((count($cancelled) / $totalJobsAll) * 100, 1) : 0;

$prevMonth = date('Y-m', strtotime("$month-01 -1 month"));
$nextMonth = date('Y-m', strtotime("$month-01 +1 month"));
$canShowUmsatz = customerCan('wh_umsatz');
$ratedJustNow = (int)($_GET['rated'] ?? 0);

include __DIR__ . '/../includes/layout-customer.php';
?>

<!-- Back button -->
<div class="mb-4">
  <a href="/customer/" class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-brand transition">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
    Zurück
  </a>
</div>

<!-- Header + month nav -->
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
  <div>
    <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Arbeitsstunden</h1>
    <p class="text-gray-500 mt-1 text-sm">Abgeschlossen, offen und abgesagt — <?= $monthLabelDe ?></p>
  </div>
  <div class="flex items-center gap-2 bg-white rounded-xl border border-gray-200 p-1">
    <a href="?month=<?= $prevMonth ?>" class="w-9 h-9 rounded-lg flex items-center justify-center hover:bg-gray-100 text-gray-600 transition">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
    </a>
    <input type="month" value="<?= $month ?>" onchange="location='?month='+this.value" class="px-2 py-1.5 text-sm font-semibold text-gray-900 focus:outline-none bg-transparent"/>
    <a href="?month=<?= $nextMonth ?>" class="w-9 h-9 rounded-lg flex items-center justify-center hover:bg-gray-100 text-gray-600 transition">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
    </a>
  </div>
</div>

<?php if ($ratedJustNow): ?>
<div class="mb-5 card-elev border-green-200 bg-green-50 p-4 text-sm text-green-800 flex items-center gap-2">
  <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
  Vielen Dank für Ihre Bewertung! Wir leiten sie an Ihren Partner weiter.
</div>
<?php endif; ?>

<!-- Stats cards -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
  <div class="card-elev p-5">
    <div class="flex items-center justify-between mb-2">
      <div class="text-xs font-medium text-gray-500 uppercase tracking-wider">Erledigt</div>
      <div class="w-8 h-8 rounded-lg bg-green-100 flex items-center justify-center">
        <svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
      </div>
    </div>
    <div class="text-2xl font-bold text-gray-900"><?= count($completed) ?></div>
    <div class="text-sm text-gray-500 mt-0.5"><?= number_format($totalHours, 1) ?>h gesamt</div>
  </div>

  <?php if ($canShowUmsatz): ?>
  <div class="card-elev p-5">
    <div class="flex items-center justify-between mb-2">
      <div class="text-xs font-medium text-gray-500 uppercase tracking-wider">Kosten</div>
      <div class="w-8 h-8 rounded-lg bg-brand/10 flex items-center justify-center">
        <svg class="w-4 h-4 text-brand" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"/></svg>
      </div>
    </div>
    <div class="text-2xl font-bold text-gray-900"><?= money($totalCost) ?></div>
    <div class="text-sm text-gray-500 mt-0.5">netto</div>
  </div>
  <?php endif; ?>

  <div class="card-elev p-5">
    <div class="flex items-center justify-between mb-2">
      <div class="text-xs font-medium text-gray-500 uppercase tracking-wider">Bewertung</div>
      <div class="w-8 h-8 rounded-lg bg-amber-100 flex items-center justify-center">
        <svg class="w-4 h-4 text-amber-600" fill="currentColor" viewBox="0 0 24 24"><path d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
      </div>
    </div>
    <div class="text-2xl font-bold text-gray-900">
      <?= $ratedCount > 0 ? number_format($avgRating, 1) . '★' : '—' ?>
    </div>
    <div class="text-sm text-gray-500 mt-0.5"><?= $ratedCount ?>/<?= count($completed) ?> bewertet</div>
  </div>

  <!-- Cancel rate breakdown -->
  <div class="card-elev p-5 <?= $cancelRate > 10 ? 'border-red-200' : '' ?>">
    <div class="flex items-center justify-between mb-2">
      <div class="text-xs font-medium text-gray-500 uppercase tracking-wider">Absage-Quote</div>
      <div class="w-8 h-8 rounded-lg <?= $cancelRate > 10 ? 'bg-red-100' : 'bg-gray-100' ?> flex items-center justify-center">
        <svg class="w-4 h-4 <?= $cancelRate > 10 ? 'text-red-600' : 'text-gray-500' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
      </div>
    </div>
    <div class="text-2xl font-bold text-gray-900"><?= $cancelRate ?>%</div>
    <div class="text-[11px] text-gray-500 mt-1 space-y-0.5">
      <?php if ($cancelByCustomer > 0): ?>
      <div class="flex items-center gap-1">
        <span class="w-1.5 h-1.5 rounded-full bg-blue-400"></span>
        <span><?= $cancelByCustomer ?>× von Ihnen abgesagt</span>
      </div>
      <?php endif; ?>
      <?php if ($cancelByFleckfrei > 0): ?>
      <div class="flex items-center gap-1">
        <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span>
        <span><?= $cancelByFleckfrei ?>× von Fleckfrei abgesagt</span>
      </div>
      <?php endif; ?>
      <?php if ($cancelByCustomer === 0 && $cancelByFleckfrei === 0): ?>
      <div class="text-gray-400">Keine Absagen</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Completed jobs (cards with rating) -->
<div class="mb-6">
  <h2 class="font-bold text-gray-900 text-lg mb-3 flex items-center gap-2">
    Erledigte Einsätze
    <span class="text-sm font-normal text-gray-400">(<?= count($completed) ?>)</span>
  </h2>

  <?php if (empty($completed)): ?>
    <div class="card-elev p-10 text-center">
      <div class="w-14 h-14 mx-auto rounded-full bg-gray-100 flex items-center justify-center mb-3">
        <svg class="w-7 h-7 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
      </div>
      <p class="text-gray-500 font-medium">Keine erledigten Einsätze in <?= $monthLabelDe ?></p>
    </div>
  <?php else: ?>
    <div class="space-y-3" x-data="{ ratingFor: null, stars: 5, comment: '' }">
      <?php foreach ($completed as $j):
        $hrs = max(MIN_HOURS, $j['total_hours'] ?: $j['j_hours']);
        $cost = $hrs * ($j['sprice'] ?: 0);
        $hasRating = !empty($j['r_stars']);
        $pAvatar = partnerAvatarUrl(['eavatar' => $j['eavatar']]);
        $pInitial = partnerInitial(['edisplay' => $j['edisplay']]);
        // Photos
        $photos = !empty($j['job_photos']) ? (json_decode($j['job_photos'], true) ?: []) : [];
        // Communications split
        $jobComms = $commsByJob[$j['j_id']] ?? [];
        $messages = array_filter($jobComms, fn($c) => $c['type'] === 'message');
        $requests = array_filter($jobComms, fn($c) => $c['type'] === 'request');
        $purchases = array_filter($jobComms, fn($c) => $c['type'] === 'purchase');
        $purchaseTotal = array_sum(array_column($purchases, 'amount'));
        $hasExtras = !empty($photos) || !empty($jobComms);
      ?>
      <div class="card-elev p-4 sm:p-5 hover:border-brand/40 transition">
        <div class="flex items-start justify-between gap-4 flex-wrap">
          <!-- Left: date + service + partner -->
          <div class="flex items-start gap-4 min-w-0 flex-1">
            <!-- Date block -->
            <div class="flex-shrink-0 w-14 text-center bg-gray-50 rounded-xl py-2">
              <div class="text-[10px] font-bold uppercase text-gray-500"><?= date('D', strtotime($j['j_date'])) ?></div>
              <div class="text-lg font-bold text-gray-900 leading-none"><?= date('d', strtotime($j['j_date'])) ?></div>
              <div class="text-[10px] text-gray-500 uppercase"><?= date('M', strtotime($j['j_date'])) ?></div>
            </div>

            <div class="min-w-0 flex-1">
              <div class="font-semibold text-gray-900 truncate"><?= e($j['stitle'] ?? 'Reinigung') ?></div>
              <div class="text-xs text-gray-500 mt-0.5 flex items-center gap-3 flex-wrap">
                <span class="flex items-center gap-1">
                  <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                  <?= substr($j['start_time'] ?? $j['j_time'], 0, 5) ?>
                  <?php if ($j['end_time']): ?> → <?= substr($j['end_time'], 0, 5) ?><?php endif; ?>
                </span>
                <span class="font-medium text-gray-700"><?= number_format($hrs, 1) ?>h</span>
                <?php if ($canShowUmsatz): ?>
                <span class="text-gray-400"><?= money($cost) ?></span>
                <?php endif; ?>
              </div>

              <!-- Partner chip -->
              <?php if (!empty($j['emp_id_fk'])): ?>
              <div class="flex items-center gap-2 mt-2">
                <?php if ($pAvatar): ?>
                <img src="<?= e($pAvatar) ?>" alt="" class="w-6 h-6 rounded-full object-cover"/>
                <?php else: ?>
                <div class="w-6 h-6 rounded-full bg-brand text-white flex items-center justify-center text-[10px] font-bold"><?= e($pInitial) ?></div>
                <?php endif; ?>
                <span class="text-xs text-gray-500">mit <?= e(partnerDisplayName(['edisplay' => $j['edisplay']])) ?></span>
              </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Right: rating button or stars -->
          <div class="flex-shrink-0">
            <?php if ($hasRating): ?>
            <div class="flex flex-col items-end gap-1">
              <div class="flex gap-0.5">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                <svg class="w-4 h-4 <?= $i <= $j['r_stars'] ? 'text-amber-400' : 'text-gray-200' ?>" fill="currentColor" viewBox="0 0 24 24"><path d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
                <?php endfor; ?>
              </div>
              <span class="text-[10px] text-gray-400">Bewertet</span>
            </div>
            <?php else: ?>
            <button @click="ratingFor = <?= (int)$j['j_id'] ?>; stars = 5; comment = ''" class="px-3 py-1.5 border border-amber-200 bg-amber-50 text-amber-700 hover:bg-amber-100 rounded-lg text-xs font-semibold transition flex items-center gap-1">
              <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
              Bewerten
            </button>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($hasExtras): ?>
        <div class="mt-4 pt-4 border-t border-gray-100 space-y-3">

          <?php if (!empty($photos)): ?>
          <!-- Photos gallery -->
          <div>
            <div class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 mb-2 flex items-center gap-1.5">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
              Fotos vom Partner (<?= count($photos) ?>)
            </div>
            <div class="flex gap-2 flex-wrap">
              <?php foreach ($photos as $p): ?>
              <a href="/uploads/jobs/<?= $j['j_id'] ?>/<?= e($p) ?>" target="_blank" class="block">
                <img src="/uploads/jobs/<?= $j['j_id'] ?>/<?= e($p) ?>" class="w-16 h-16 object-cover rounded-lg border border-gray-200 hover:scale-105 transition cursor-pointer"/>
              </a>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>

          <?php foreach ($messages as $m): ?>
          <!-- Partner message -->
          <div class="flex items-start gap-2 p-3 bg-blue-50 border border-blue-100 rounded-lg">
            <svg class="w-4 h-4 text-blue-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
            <div class="min-w-0 flex-1">
              <div class="text-[10px] font-semibold text-blue-700 uppercase tracking-wider mb-0.5">Nachricht vom Partner</div>
              <div class="text-xs text-gray-700"><?= e($m['content']) ?></div>
              <div class="text-[10px] text-gray-400 mt-1"><?= date('d.m.Y H:i', strtotime($m['created_at'])) ?></div>
            </div>
          </div>
          <?php endforeach; ?>

          <?php foreach ($requests as $r): ?>
          <!-- Purchase request (please buy X) -->
          <div class="flex items-start gap-2 p-3 bg-amber-50 border border-amber-200 rounded-lg">
            <svg class="w-4 h-4 text-amber-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
            <div class="min-w-0 flex-1">
              <div class="text-[10px] font-semibold text-amber-700 uppercase tracking-wider mb-0.5">Einkaufsbitte</div>
              <div class="text-xs text-gray-700"><?= e($r['content']) ?></div>
              <div class="text-[10px] text-gray-400 mt-1"><?= date('d.m.Y H:i', strtotime($r['created_at'])) ?></div>
            </div>
          </div>
          <?php endforeach; ?>

          <?php if (!empty($purchases)): ?>
          <!-- Purchases (we bought X for €Y) -->
          <div class="p-3 bg-green-50 border border-green-200 rounded-lg">
            <div class="flex items-center justify-between mb-2">
              <div class="text-[10px] font-semibold text-green-700 uppercase tracking-wider flex items-center gap-1.5">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
                Eingekauft für Sie
              </div>
              <div class="text-sm font-bold text-green-700"><?= money($purchaseTotal) ?></div>
            </div>
            <div class="space-y-1.5">
              <?php foreach ($purchases as $p): ?>
              <div class="flex items-start justify-between gap-3 text-xs">
                <div class="text-gray-700 flex-1 min-w-0">
                  • <?= e($p['content']) ?>
                  <?php if ($p['added_to_invoice']): ?>
                  <span class="ml-1 inline-block px-1.5 py-0.5 rounded bg-green-200 text-green-800 text-[9px] font-semibold uppercase">In Rechnung</span>
                  <?php endif; ?>
                </div>
                <div class="text-gray-900 font-semibold whitespace-nowrap"><?= money($p['amount']) ?></div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>

        </div>
        <?php endif; ?>

        <?php if ($hasRating && !empty($j['r_comment'])): ?>
        <div class="mt-3 pt-3 border-t border-gray-100 text-xs text-gray-600 italic">
          "<?= e($j['r_comment']) ?>"
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>

      <!-- Rating modal -->
      <div x-show="ratingFor" x-cloak @click.self="ratingFor = null" class="fixed inset-0 bg-black/40 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4" x-transition.opacity>
        <form method="POST" class="bg-white w-full sm:max-w-md rounded-t-2xl sm:rounded-2xl shadow-xl overflow-hidden" @click.stop x-transition>
          <?= csrfField() ?>
          <input type="hidden" name="action" value="submit_rating"/>
          <input type="hidden" name="month" value="<?= e($month) ?>"/>
          <input type="hidden" name="j_id" :value="ratingFor"/>
          <input type="hidden" name="stars" :value="stars"/>

          <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="font-semibold text-gray-900">Wie war der Service?</h3>
            <button type="button" @click="ratingFor = null" class="w-8 h-8 rounded-lg hover:bg-gray-100 flex items-center justify-center text-gray-500">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
          </div>

          <div class="p-6">
            <!-- Stars -->
            <div class="flex justify-center gap-2 mb-5">
              <template x-for="i in 5">
                <button type="button" @click="stars = i" class="text-4xl transition hover:scale-110 focus:outline-none">
                  <svg class="w-10 h-10" :class="i <= stars ? 'text-amber-400' : 'text-gray-200'" fill="currentColor" viewBox="0 0 24 24"><path d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
                </button>
              </template>
            </div>

            <!-- Comment -->
            <label class="block text-xs font-semibold text-gray-600 mb-1.5 uppercase tracking-wider">Nachricht an Ihren Partner <span class="text-gray-400 normal-case">(optional)</span></label>
            <textarea name="comment" x-model="comment" rows="3" placeholder="Ihr Feedback... wird automatisch in die Sprache des Partners übersetzt." class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none text-sm"></textarea>
            <p class="text-[11px] text-gray-400 mt-1.5">🌐 Automatische Übersetzung via KI</p>

            <button type="submit" class="w-full mt-4 px-5 py-3 bg-brand hover:bg-brand-dark text-white rounded-xl font-semibold transition">Bewertung abgeben</button>
          </div>
        </form>
      </div>
    </div>
  <?php endif; ?>
</div>

<!-- Cancelled jobs (collapsed by default) -->
<?php if (!empty($cancelled)): ?>
<div x-data="{ open: false }">
  <button @click="open = !open" class="w-full flex items-center justify-between mb-3 py-2 text-left">
    <h2 class="font-bold text-gray-900 text-lg flex items-center gap-2">
      Abgesagte Einsätze
      <span class="text-sm font-normal text-gray-400">(<?= count($cancelled) ?>)</span>
    </h2>
    <svg class="w-5 h-5 text-gray-400 transition" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
  </button>
  <div x-show="open" x-cloak x-transition class="space-y-2">
    <?php foreach ($cancelled as $j):
      $role = strtolower($j['cancelled_role'] ?? '');
      $isCustomerCancel = $role === 'customer';
    ?>
    <div class="card-elev p-4 opacity-80">
      <div class="flex items-start justify-between gap-3">
        <div class="flex items-start gap-3 min-w-0 flex-1">
          <div class="w-10 h-10 rounded-lg bg-gray-100 flex items-center justify-center flex-shrink-0">
            <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
          </div>
          <div class="min-w-0 flex-1">
            <div class="font-medium text-gray-700 line-through"><?= e($j['stitle'] ?? 'Reinigung') ?></div>
            <div class="text-xs text-gray-500 mt-0.5"><?= date('d.m.Y', strtotime($j['j_date'])) ?><?php if ($j['cancel_date']): ?> · abgesagt am <?= date('d.m.Y', strtotime($j['cancel_date'])) ?><?php endif; ?></div>
          </div>
        </div>
        <span class="px-2.5 py-1 rounded-full text-[10px] font-semibold whitespace-nowrap <?= $isCustomerCancel ? 'bg-blue-100 text-blue-700' : 'bg-amber-100 text-amber-700' ?>">
          <?= $isCustomerCancel ? 'Von Ihnen' : 'Von Fleckfrei' ?>
        </span>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>
