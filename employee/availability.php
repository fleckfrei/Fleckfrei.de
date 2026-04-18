<?php
/**
 * Employee Availability — Partner markiert Urlaub/Krankheit/frei-Tage
 */
require_once __DIR__ . '/../includes/auth.php';
requireEmployee();
$title = 'Meine Verfügbarkeit'; $page = 'availability';
$user = me();
$eid = $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { header('Location: /employee/availability.php'); exit; }
    $act = $_POST['action'] ?? '';

    if ($act === 'add') {
        $type = in_array($_POST['type'] ?? '', ['urlaub','krank','privat','schule','sonstiges'], true) ? $_POST['type'] : 'urlaub';
        $dateFrom = $_POST['date_from'] ?? '';
        $dateTo = $_POST['date_to'] ?? $dateFrom;
        $note = trim(mb_substr($_POST['note'] ?? '', 0, 500));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            q("INSERT INTO employee_availability (emp_id_fk, date_from, date_to, type, all_day, note) VALUES (?,?,?,?,1,?)",
              [$eid, $dateFrom, $dateTo, $type, $note]);

            // Check for collisions and auto-notify admin
            $collisions = all("SELECT j.j_id, j.j_date, j.j_time, s.title AS stitle, c.name AS cname
                FROM jobs j
                LEFT JOIN services s ON j.s_id_fk=s.s_id
                LEFT JOIN customer c ON j.customer_id_fk=c.customer_id
                WHERE j.emp_id_fk=? AND j.j_date BETWEEN ? AND ? AND j.status=1 AND j.job_status NOT IN ('CANCELLED','COMPLETED')
                ORDER BY j.j_date, j.j_time", [$eid, $dateFrom, $dateTo]);

            if (!empty($collisions) && function_exists('telegramNotify')) {
                $partnerName = $user['name'];
                $typeLabel = ['urlaub'=>'Urlaub','krank'=>'Krank','privat'=>'Privat','schule'=>'Schule','sonstiges'=>'Sonstiges'][$type] ?? $type;
                $msg = "🚨 <b>KOLLISION: Partner-Absenz</b>\n\n";
                $msg .= "👷 " . e($partnerName) . " hat " . e($typeLabel) . " eingetragen\n";
                $msg .= "📅 " . date('d.m.', strtotime($dateFrom)) . " — " . date('d.m.Y', strtotime($dateTo)) . "\n";
                $msg .= "\n⚠️ <b>" . count($collisions) . " Jobs kollidieren:</b>\n";
                foreach (array_slice($collisions, 0, 10) as $col) {
                    $msg .= "• " . date('d.m.', strtotime($col['j_date'])) . " " . substr($col['j_time'], 0, 5) . " — " . e($col['stitle'] ?? 'Job') . " / " . e($col['cname'] ?? '') . "\n";
                }
                if (count($collisions) > 10) $msg .= "• ... +" . (count($collisions) - 10) . " weitere\n";
                $msg .= "\n🔗 https://app.fleckfrei.de/admin/availability.php";
                telegramNotify($msg);
            }
        }
        header('Location: /employee/availability.php?saved=1'); exit;
    }

    if ($act === 'delete') {
        $eaId = (int)($_POST['ea_id'] ?? 0);
        q("DELETE FROM employee_availability WHERE ea_id=? AND emp_id_fk=?", [$eaId, $eid]);
        header('Location: /employee/availability.php?deleted=1'); exit;
    }
}

$today = date('Y-m-d');
$upcoming = all("SELECT * FROM employee_availability WHERE emp_id_fk=? AND date_to >= ? ORDER BY date_from", [$eid, $today]);
$past = all("SELECT * FROM employee_availability WHERE emp_id_fk=? AND date_to < ? ORDER BY date_from DESC LIMIT 10", [$eid, $today]);

// Admin-globale Sperrtage (Büro zu, alle Partner+Kunden) — partner-seitig zur Info
$adminBlocks = all("SELECT date_from, date_to, reason, category, weekday_mask FROM admin_blocked_days
                    WHERE date_to >= ? AND (applies_to='all' OR applies_to IS NULL)
                    AND customer_id_fk IS NULL AND (prebook_token IS NULL OR prebook_token='')
                    ORDER BY date_from ASC LIMIT 20", [$today]);

// Upcoming jobs collision check
$upcomingJobs = all("SELECT j_id, j_date, j_time FROM jobs WHERE emp_id_fk=? AND j_date >= ? AND status=1 AND job_status NOT IN ('CANCELLED','COMPLETED') ORDER BY j_date", [$eid, $today]);

$typeLabels = [
    'urlaub' => ['🏖 Urlaub', 'bg-blue-100 text-blue-700'],
    'krank' => ['🤒 Krank', 'bg-red-100 text-red-700'],
    'privat' => ['👤 Privat', 'bg-gray-100 text-gray-700'],
    'schule' => ['🎓 Schule / Fortbildung', 'bg-purple-100 text-purple-700'],
    'sonstiges' => ['📌 Sonstiges', 'bg-amber-100 text-amber-700'],
];

include __DIR__ . '/../includes/layout.php';
?>

<?php if (!empty($_GET['saved'])): ?><div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4">✓ Verfügbarkeit eingetragen</div><?php endif; ?>
<?php if (!empty($_GET['deleted'])): ?><div class="bg-gray-50 border border-gray-200 text-gray-700 px-4 py-3 rounded-xl mb-4">Eintrag gelöscht</div><?php endif; ?>

<div class="mb-6">
  <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-2">📅 Meine Verfügbarkeit</h1>
  <p class="text-sm text-gray-500 mt-1">Urlaub, Krankheit, Schule — markieren Sie Zeiten an denen Sie KEINE Jobs annehmen können. Das Büro sieht das sofort.</p>
</div>

<?php if (!empty($adminBlocks)): ?>
<div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-6">
  <div class="font-bold text-red-900 mb-2">🚫 Büro-Sperrtage (alle Partner &amp; Kunden)</div>
  <div class="text-sm text-red-800 space-y-1">
    <?php foreach ($adminBlocks as $ab):
      $from = date('d.m.Y', strtotime($ab['date_from']));
      $to   = date('d.m.Y', strtotime($ab['date_to']));
      $wdHint = '';
      if (!empty($ab['weekday_mask'])) {
        $wdNames = ['1'=>'Mo','2'=>'Di','3'=>'Mi','4'=>'Do','5'=>'Fr','6'=>'Sa','7'=>'So'];
        $parts = array_map(fn($n) => $wdNames[$n] ?? $n, explode(',', $ab['weekday_mask']));
        $wdHint = ' · nur ' . implode('/', $parts);
      }
    ?>
    <div>📅 <b><?= $from ?><?= $ab['date_from']!==$ab['date_to'] ? ' – '.$to : '' ?></b><?= $wdHint ?><?= $ab['category'] ? ' · '.e($ab['category']) : '' ?><?= $ab['reason'] ? ' — '.e($ab['reason']) : '' ?></div>
    <?php endforeach; ?>
  </div>
  <div class="text-[11px] text-red-700 mt-2">An diesen Tagen werden keine Jobs vergeben. Du brauchst hier keinen eigenen Urlaub eintragen.</div>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
  <!-- Add new -->
  <div class="lg:col-span-1">
    <div class="bg-white rounded-xl border p-5">
      <h3 class="font-bold text-gray-900 mb-3">+ Zeitraum hinzufügen</h3>
      <form method="POST" class="space-y-3">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add"/>

        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase">Typ</label>
          <select name="type" class="w-full px-3 py-2 border rounded-lg text-sm">
            <?php foreach ($typeLabels as $v => $lbl): ?>
            <option value="<?= $v ?>"><?= e($lbl[0]) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="grid grid-cols-2 gap-2">
          <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase">Von</label>
            <input type="date" name="date_from" required min="<?= $today ?>" class="w-full px-3 py-2 border rounded-lg text-sm"/>
          </div>
          <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase">Bis</label>
            <input type="date" name="date_to" required min="<?= $today ?>" class="w-full px-3 py-2 border rounded-lg text-sm"/>
          </div>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase">Notiz (optional)</label>
          <textarea name="note" rows="2" placeholder="z.B. Familienurlaub" class="w-full px-3 py-2 border rounded-lg text-sm"></textarea>
        </div>
        <button type="submit" class="w-full px-4 py-2 bg-brand hover:bg-brand-dark text-white rounded-xl text-sm font-semibold">Eintragen</button>
      </form>
    </div>
  </div>

  <!-- Current + upcoming -->
  <div class="lg:col-span-2">
    <div class="bg-white rounded-xl border overflow-hidden">
      <div class="px-5 py-3 border-b bg-gray-50">
        <h3 class="font-bold text-gray-900">Kommende Zeiträume (<?= count($upcoming) ?>)</h3>
      </div>
      <?php if (empty($upcoming)): ?>
      <div class="p-8 text-center text-sm text-gray-400">Keine Einträge — Sie sind für alle zukünftigen Jobs verfügbar.</div>
      <?php else: ?>
      <div class="divide-y">
        <?php foreach ($upcoming as $e2):
          $t = $typeLabels[$e2['type']] ?? $typeLabels['sonstiges'];
          $days = (int)((strtotime($e2['date_to']) - strtotime($e2['date_from'])) / 86400) + 1;

          // Count collisions: upcoming jobs in that window
          $collisions = 0;
          foreach ($upcomingJobs as $j) {
            if ($j['j_date'] >= $e2['date_from'] && $j['j_date'] <= $e2['date_to']) $collisions++;
          }
        ?>
        <div class="px-5 py-4 flex items-center gap-3 hover:bg-gray-50">
          <span class="px-2 py-0.5 rounded-full text-[11px] font-bold <?= $t[1] ?>"><?= e($t[0]) ?></span>
          <div class="flex-1 min-w-0">
            <div class="font-semibold text-gray-900 text-sm">
              <?= date('d.m.', strtotime($e2['date_from'])) ?>
              <?php if ($e2['date_from'] !== $e2['date_to']): ?> — <?= date('d.m.Y', strtotime($e2['date_to'])) ?><?php else: ?><?= date('.Y', strtotime($e2['date_from'])) ?><?php endif; ?>
              <span class="text-[11px] text-gray-400 font-normal"><?= $days ?> Tag<?= $days === 1 ? '' : 'e' ?></span>
            </div>
            <?php if ($e2['note']): ?><div class="text-[11px] text-gray-500 mt-0.5"><?= e($e2['note']) ?></div><?php endif; ?>
            <?php if ($collisions > 0): ?>
            <div class="text-[11px] text-red-600 font-semibold mt-1 flex items-center gap-1">
              ⚠️ <?= $collisions ?> Job<?= $collisions === 1 ? '' : 's' ?> kollidieren — bitte kontaktieren Sie das Büro
            </div>
            <?php endif; ?>
          </div>
          <form method="POST" onsubmit="return confirm('Eintrag löschen?')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete"/>
            <input type="hidden" name="ea_id" value="<?= (int)$e2['ea_id'] ?>"/>
            <button class="text-red-400 hover:text-red-600 text-xs">×</button>
          </form>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <?php if (!empty($past)): ?>
    <div class="mt-4 bg-white rounded-xl border overflow-hidden opacity-75">
      <div class="px-5 py-3 border-b bg-gray-50">
        <h3 class="font-bold text-gray-600 text-sm">Vergangene (letzte 10)</h3>
      </div>
      <div class="divide-y text-sm">
        <?php foreach ($past as $e2):
          $t = $typeLabels[$e2['type']] ?? $typeLabels['sonstiges'];
        ?>
        <div class="px-5 py-2 flex items-center gap-3">
          <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold <?= $t[1] ?>"><?= e($t[0]) ?></span>
          <span class="text-gray-600 text-xs">
            <?= date('d.m.', strtotime($e2['date_from'])) ?>–<?= date('d.m.Y', strtotime($e2['date_to'])) ?>
          </span>
          <?php if ($e2['note']): ?><span class="text-[11px] text-gray-400 italic flex-1 min-w-0 truncate"><?= e($e2['note']) ?></span><?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
