<?php
require_once __DIR__ . '/../includes/auth.php';
if (($_SESSION['utype'] ?? '') !== 'customer') { header('Location: /login.php'); exit; }
$cid = (int)($_SESSION['uid'] ?? 0);
$title = 'Urlaub'; $page = 'vacations';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $act = $_POST['action'] ?? '';
    if ($act === 'add_vacation') {
        $from = $_POST['from_date'] ?? '';
        $to   = $_POST['to_date'] ?? '';
        $reason = trim($_POST['reason'] ?? '');
        $skip = !empty($_POST['auto_skip_jobs']) ? 1 : 0;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) && $to >= $from) {
            q("INSERT INTO customer_vacations (customer_id_fk, from_date, to_date, reason, auto_skip_jobs) VALUES (?,?,?,?,?)",
              [$cid, $from, $to, $reason ?: null, $skip]);
            // Telegram-Notify an Admin (falls konfiguriert)
            if (function_exists('telegramNotify')) {
                $cname = val("SELECT name FROM customer WHERE customer_id=?", [$cid]) ?: 'Kunde';
                telegramNotify("🏖 <b>Urlaub angemeldet</b>\n\n👤 $cname\n📅 $from – $to\n" . ($reason ? "📝 $reason\n" : '') . ($skip ? '⏭ auto-skip für bestehende Jobs' : ''));
            }
            // Optional: Jobs im Zeitraum automatisch pausieren
            if ($skip) {
                try {
                    q("UPDATE jobs SET job_status='PAUSED_VACATION' WHERE customer_id_fk=? AND j_date BETWEEN ? AND ? AND status=1 AND (job_status IS NULL OR UPPER(job_status) IN ('NEW','PENDING','CONFIRMED','PAUSED_VACATION'))",
                      [$cid, $from, $to]);
                } catch (Exception $e) {}
            }
            header("Location: /customer/vacations.php?saved=1"); exit;
        }
    }
    if ($act === 'cancel_vacation') {
        $id = (int)($_POST['cv_id'] ?? 0);
        q("UPDATE customer_vacations SET status='cancelled' WHERE cv_id=? AND customer_id_fk=?", [$id, $cid]);
        // Jobs reaktivieren
        try {
            $r = one("SELECT from_date, to_date FROM customer_vacations WHERE cv_id=?", [$id]);
            if ($r) q("UPDATE jobs SET job_status='CONFIRMED' WHERE customer_id_fk=? AND j_date BETWEEN ? AND ? AND job_status='PAUSED_VACATION'", [$cid, $r['from_date'], $r['to_date']]);
        } catch (Exception $e) {}
        header("Location: /customer/vacations.php?cancelled=1"); exit;
    }
}

$vacs = all("SELECT cv_id, from_date, to_date, reason, auto_skip_jobs, status, created_at FROM customer_vacations WHERE customer_id_fk=? ORDER BY from_date DESC", [$cid]);
include __DIR__ . '/../includes/layout.php';
?>

<?php if (!empty($_GET['saved'])): ?><div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4">✅ Urlaub eingetragen. Wir pausieren automatisch alle Reinigungen in diesem Zeitraum.</div><?php endif; ?>
<?php if (!empty($_GET['cancelled'])): ?><div class="bg-yellow-50 border border-yellow-200 text-yellow-800 px-4 py-3 rounded-xl mb-4">Urlaub storniert — Termine wieder aktiv.</div><?php endif; ?>

<div class="bg-white rounded-xl border p-5 mb-6">
  <h2 class="text-xl font-bold mb-2">🏖 Urlaub eintragen</h2>
  <p class="text-sm text-gray-600 mb-4">In diesem Zeitraum pausieren wir alle bestehenden Reinigungen automatisch. Neue Buchungen sind während deines Urlaubs gesperrt.</p>
  <form method="POST" class="grid grid-cols-1 md:grid-cols-4 gap-3">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="add_vacation"/>
    <div>
      <label class="block text-xs font-semibold mb-1">Von *</label>
      <input type="date" name="from_date" required min="<?= date('Y-m-d') ?>" class="w-full px-3 py-2 border rounded-lg text-sm"/>
    </div>
    <div>
      <label class="block text-xs font-semibold mb-1">Bis *</label>
      <input type="date" name="to_date" required min="<?= date('Y-m-d') ?>" class="w-full px-3 py-2 border rounded-lg text-sm"/>
    </div>
    <div class="md:col-span-2">
      <label class="block text-xs font-semibold mb-1">Grund (optional)</label>
      <input name="reason" placeholder="z.B. Urlaub, Umzug, Geschäftsreise" class="w-full px-3 py-2 border rounded-lg text-sm"/>
    </div>
    <div class="md:col-span-4">
      <label class="flex items-center gap-2 text-sm">
        <input type="checkbox" name="auto_skip_jobs" value="1" checked class="rounded"/>
        Bestehende Reinigungen im Zeitraum <b>automatisch pausieren</b>
      </label>
    </div>
    <div class="md:col-span-4">
      <button type="submit" class="px-5 py-2.5 bg-brand text-white rounded-xl font-semibold hover:bg-brand-dark">🏖 Urlaub eintragen</button>
    </div>
  </form>
</div>

<div class="bg-white rounded-xl border">
  <div class="p-5 border-b"><h3 class="font-bold">Meine Urlaube (<?= count($vacs) ?>)</h3></div>
  <div class="divide-y">
    <?php foreach ($vacs as $v):
      $today = date('Y-m-d');
      $isUpcoming = $v['from_date'] > $today;
      $isCurrent = $v['from_date'] <= $today && $v['to_date'] >= $today;
      $days = (strtotime($v['to_date']) - strtotime($v['from_date'])) / 86400 + 1;
    ?>
    <div class="p-4 flex items-center justify-between hover:bg-gray-50">
      <div>
        <div class="font-semibold">
          <?= date('d.m.Y', strtotime($v['from_date'])) ?> – <?= date('d.m.Y', strtotime($v['to_date'])) ?>
          <span class="text-xs text-gray-500 ml-2">(<?= $days ?> Tage)</span>
          <?php if ($v['status'] === 'cancelled'): ?><span class="ml-2 px-2 py-0.5 bg-gray-100 text-gray-500 rounded text-xs">Storniert</span>
          <?php elseif ($isCurrent): ?><span class="ml-2 px-2 py-0.5 bg-amber-100 text-amber-800 rounded text-xs font-semibold">🏖 gerade im Urlaub</span>
          <?php elseif ($isUpcoming): ?><span class="ml-2 px-2 py-0.5 bg-blue-100 text-blue-700 rounded text-xs">Geplant</span>
          <?php else: ?><span class="ml-2 px-2 py-0.5 bg-gray-100 text-gray-500 rounded text-xs">Vergangen</span>
          <?php endif; ?>
        </div>
        <?php if ($v['reason']): ?><div class="text-xs text-gray-500"><?= e($v['reason']) ?></div><?php endif; ?>
        <div class="text-[10px] text-gray-400 mt-1">Eingetragen <?= date('d.m.Y H:i', strtotime($v['created_at'])) ?><?php if ($v['auto_skip_jobs']): ?> · ⏭ Auto-Pause aktiv<?php endif; ?></div>
      </div>
      <?php if ($v['status'] === 'active' && !$isCurrent): ?>
      <form method="POST" onsubmit="return confirm('Urlaub stornieren? Termine werden wieder aktiv.')">
        <?= csrfField() ?><input type="hidden" name="action" value="cancel_vacation"/><input type="hidden" name="cv_id" value="<?= $v['cv_id'] ?>"/>
        <button class="px-3 py-1.5 text-xs bg-red-50 text-red-600 rounded-lg hover:bg-red-100">✕ Stornieren</button>
      </form>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php if (empty($vacs)): ?><div class="p-8 text-center text-gray-400">Noch kein Urlaub eingetragen.</div><?php endif; ?>
  </div>
</div>

<?php include __DIR__.'/../includes/footer.php'; ?>
