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
$job = $jid ? one("SELECT j.*, e.name as ename, e.surname as esurname, c.name as cname, c.customer_id as cid, s.title as stitle
    FROM jobs j LEFT JOIN employee e ON j.emp_id_fk=e.emp_id LEFT JOIN customer c ON j.customer_id_fk=c.customer_id
    LEFT JOIN services s ON j.s_id_fk=s.s_id WHERE j.j_id=? AND j.job_status='COMPLETED'", [$jid]) : null;

$expectedToken = $job ? md5($jid . $job['cid'] . $job['j_date'] . 'flk_rate_salt') : '';

if (!$job || $token !== $expectedToken) {
    $error = 'Ungültiger Link oder Job nicht gefunden.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $job && !$error) {
    $stars = max(1, min(5, (int)($_POST['stars'] ?? 5)));
    $comment = trim(htmlspecialchars($_POST['comment'] ?? '', ENT_QUOTES, 'UTF-8'));
    $ch = curl_init('https://app.' . SITE_DOMAIN . '/api/index.php?action=ratings/submit');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_POST=>1, CURLOPT_TIMEOUT=>10,
        CURLOPT_POSTFIELDS=>json_encode(['j_id'=>$jid, 'stars'=>$stars, 'comment'=>$comment]),
        CURLOPT_HTTPHEADER=>['Content-Type: application/json', 'X-API-Key: '.API_KEY]]);
    $result = curl_exec($ch); curl_close($ch);
    $submitted = true;
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
      <h2 class="text-xl font-bold mb-2">Danke fuer deine Bewertung!</h2>
      <p class="text-gray-500">Dein Feedback hilft uns besser zu werden.</p>
    </div>
    <?php else: ?>
    <div class="text-center mb-6">
      <div class="w-14 h-14 rounded-full bg-brand/10 flex items-center justify-center mx-auto mb-3">
        <svg class="w-7 h-7 text-brand" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
      </div>
      <h2 class="text-xl font-bold">Wie war der Service?</h2>
      <p class="text-gray-500 text-sm mt-1"><?= e($job['stitle']) ?> am <?= date('d.m.Y', strtotime($job['j_date'])) ?></p>
      <p class="text-gray-400 text-xs">Partner: <?= e($job['ename'] . ' ' . ($job['esurname'] ?? '')) ?></p>
    </div>
    <form method="POST">
      <div class="flex justify-center gap-3 mb-6" x-data="{stars:5}">
        <?php for ($i=1; $i<=5; $i++): ?>
        <label class="star text-3xl text-gray-300" onclick="this.parentNode.querySelectorAll('.star').forEach((s,j)=>{s.classList.toggle('active',j<<?= $i ?>);s.style.color=j<<?= $i ?>?'#f59e0b':'#d1d5db'});document.getElementById('starsInput').value=<?= $i ?>">&#9733;</label>
        <?php endfor; ?>
        <input type="hidden" name="stars" id="starsInput" value="5"/>
      </div>
      <textarea name="comment" rows="3" placeholder="Dein Feedback (optional)..." class="w-full px-4 py-3 border rounded-xl mb-4 text-sm"></textarea>
      <button type="submit" class="w-full py-3 bg-brand text-white rounded-xl font-bold text-lg hover:opacity-90">Bewertung abgeben</button>
    </form>
    <?php endif; ?>
  </div>
</body>
</html>
