<?php
/**
 * Customer Rating Page — Rate partner after completed job
 * URL: /customer/rate.php?j=123&t=TOKEN
 * Token prevents unauthorized ratings
 */
require_once __DIR__ . '/../includes/config.php';

$jid = (int)($_GET['j'] ?? 0);
$token = $_GET['t'] ?? '';
$submitted = false;
$error = '';

// Validate token (simple hash of job_id + customer_id + date)
$job = $jid ? one("SELECT j.*, e.display_name as edisplay, e.profile_pic as eavatar, c.name as cname, c.customer_id as cid, s.title as stitle
    FROM jobs j LEFT JOIN employee e ON j.emp_id_fk=e.emp_id LEFT JOIN customer c ON j.customer_id_fk=c.customer_id
    LEFT JOIN services s ON j.s_id_fk=s.s_id WHERE j.j_id=? AND j.job_status='COMPLETED'", [$jid]) : null;

$expectedToken = $job ? md5($jid . $job['cid'] . $job['j_date'] . 'flk_rate_salt') : '';

if (!$job || $token !== $expectedToken) {
    $error = 'Ungültiger Link oder Job nicht gefunden.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $job && !$error) {
    $stars = max(1, min(5, (int)($_POST['stars'] ?? 5)));
    $comment = trim(htmlspecialchars($_POST['comment'] ?? '', ENT_QUOTES, 'UTF-8'));

    // Photo upload (optional)
    $photoPath = null;
    if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $tmp = $_FILES['photo']['tmp_name'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp);
        $allowedMimes = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/heic'=>'heic'];
        if (isset($allowedMimes[$mime]) && $_FILES['photo']['size'] < 10*1024*1024) {
            $dir = __DIR__ . '/../uploads/ratings/';
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $fname = 'r' . $jid . '_' . bin2hex(random_bytes(6)) . '.' . $allowedMimes[$mime];
            if (move_uploaded_file($tmp, $dir . $fname)) {
                $photoPath = '/uploads/ratings/' . $fname;
            }
        }
    }

    // Direct MySQL upsert — use the canonical table
    try {
        q("INSERT INTO job_ratings (j_id_fk, customer_id_fk, emp_id_fk, stars, comment, photo)
           VALUES (?, ?, ?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE stars=VALUES(stars), comment=VALUES(comment), photo=COALESCE(VALUES(photo), photo)",
          [$jid, (int)$job['customer_id_fk'], (int)$job['emp_id_fk'], $stars, $comment, $photoPath]);
    } catch (Exception $e) {
        $error = 'Fehler beim Speichern: ' . $e->getMessage();
    }

    if (function_exists('telegramNotify') && !$error) {
        $starEmoji = str_repeat('⭐', $stars);
        telegramNotify("⭐ <b>Neue Bewertung</b>\n\n👤 " . e($job['cname']) . " → 👷 " . e(partnerDisplayName($job)) . "\n{$starEmoji} ({$stars}/5)" . ($comment ? "\n💬 " . e($comment) : '') . ($photoPath ? "\n📸 Foto hochgeladen" : ''));
    }
    $submitted = !$error;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8"/><meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Bewertung — <?= SITE ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>tailwind.config={theme:{extend:{colors:{brand:'<?= BRAND ?>'}}}}</script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <style>body{font-family:'Inter',system-ui,sans-serif;} .star{cursor:pointer;transition:all 0.15s;} .star:hover,.star.active{color:#f59e0b;transform:scale(1.2);}</style>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center p-4">
  <div class="bg-white rounded-2xl border shadow-sm p-8 w-full max-w-md">
    <?php if ($error): ?>
    <div class="text-center">
      <div class="text-4xl mb-4">&#128683;</div>
      <h2 class="text-xl font-bold mb-2">Fehler</h2>
      <p class="text-gray-500"><?= $error ?></p>
    </div>
    <?php elseif ($submitted): ?>
    <div class="text-center">
      <div class="text-4xl mb-4">&#11088;</div>
      <h2 class="text-xl font-bold mb-2">Vielen Dank für Ihre Bewertung!</h2>
      <p class="text-gray-500">Ihr Feedback hilft uns besser zu werden.</p>
    </div>
    <?php else: ?>
    <div class="text-center mb-6">
      <div class="w-14 h-14 rounded-full bg-brand/10 flex items-center justify-center mx-auto mb-3">
        <svg class="w-7 h-7 text-brand" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
      </div>
      <h2 class="text-xl font-bold">Wie war der Service?</h2>
      <p class="text-gray-500 text-sm mt-1"><?= e($job['stitle']) ?> am <?= date('d.m.Y', strtotime($job['j_date'])) ?></p>
      <p class="text-gray-400 text-xs">Partner: <?= e(partnerDisplayName($job)) ?></p>
    </div>
    <form method="POST" enctype="multipart/form-data">
      <div class="flex justify-center gap-3 mb-6">
        <?php for ($i=1; $i<=5; $i++): ?>
        <label class="star text-3xl text-gray-300" onclick="this.parentNode.querySelectorAll('.star').forEach((s,j)=>{s.classList.toggle('active',j<<?= $i ?>);s.style.color=j<<?= $i ?>?'#f59e0b':'#d1d5db'});document.getElementById('starsInput').value=<?= $i ?>">&#9733;</label>
        <?php endfor; ?>
        <input type="hidden" name="stars" id="starsInput" value="5"/>
      </div>

      <textarea name="comment" rows="3" placeholder="Ihr Feedback (optional)..." class="w-full px-4 py-3 border rounded-xl mb-3 text-sm focus:border-brand outline-none"></textarea>

      <!-- Photo upload -->
      <div class="mb-4">
        <label class="block text-xs font-semibold text-gray-600 mb-2 uppercase tracking-wide">📸 Foto anhängen (optional)</label>
        <div class="flex items-center gap-3">
          <label class="flex-1 cursor-pointer">
            <input type="file" name="photo" accept="image/*" capture="environment" class="hidden" id="ratePhoto" onchange="document.getElementById('ratePhotoLabel').textContent = this.files[0] ? this.files[0].name : 'Kein Foto'"/>
            <div class="px-3 py-2 border-2 border-dashed border-gray-200 rounded-xl text-center text-xs text-gray-500 hover:border-brand hover:bg-brand/5 transition">
              <span id="ratePhotoLabel">Foto auswählen</span>
            </div>
          </label>
        </div>
        <p class="text-[10px] text-gray-400 mt-1">z.B. Bereich der nicht richtig gereinigt wurde, oder ein besonders gutes Ergebnis.</p>
      </div>

      <button type="submit" class="w-full py-3 bg-brand text-white rounded-xl font-bold text-lg hover:opacity-90">Bewertung abgeben</button>
    </form>
    <?php endif; ?>
  </div>
</body>
</html>
