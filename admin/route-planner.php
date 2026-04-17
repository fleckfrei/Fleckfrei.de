<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Route-Planner'; $page = 'route-planner';

$me = $_SESSION['uemail'] ?? 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { header('Location: /admin/route-planner.php'); exit; }
    $act = $_POST['action'] ?? '';

    if ($act === 'assign_route') {
        $date     = $_POST['assignment_date'] ?? '';
        $district = $_POST['district'] ?? '';
        $empId    = (int)($_POST['emp_id_fk'] ?? 0);
        $jobIds   = array_values(array_map('intval', $_POST['job_ids'] ?? []));

        // Auto-Suggest: falls Partner-ID = -1 → pick der Partner mit den WENIGSTEN Jobs an dem Tag
        if ($empId === -1 && $jobIds) {
            $candidates = all("SELECT e.emp_id, e.name,
                               (SELECT COUNT(*) FROM jobs j WHERE j.emp_id_fk=e.emp_id AND j.j_date=? AND j.status=1
                                AND (j.job_status IS NULL OR UPPER(j.job_status) NOT IN ('CANCELLED','REJECTED','DELETED'))) AS cnt
                               FROM employee e WHERE e.status=1 ORDER BY cnt ASC, e.emp_id ASC LIMIT 1", [$date]);
            if ($candidates) $empId = (int)$candidates[0]['emp_id'];
        }

        if ($date && $district && $empId > 0 && $jobIds) {
            // Sequence-Order: sortiere Jobs nach j_time (chronologisch)
            $ordered = all("SELECT j_id FROM jobs WHERE j_id IN (" . implode(',', array_map('intval', $jobIds)) . ") ORDER BY j_time ASC");
            $seqIds = array_column($ordered, 'j_id');

            q("INSERT INTO route_assignments (assignment_date, district, emp_id_fk, job_ids, sequence_order, created_by) VALUES (?,?,?,?,?,?)",
              [$date, $district, $empId, json_encode($seqIds), json_encode($seqIds), $me]);
            foreach ($seqIds as $jid) q("UPDATE jobs SET emp_id_fk=? WHERE j_id=?", [$empId, $jid]);
            header("Location: /admin/route-planner.php?assigned=1&date=$date&district=" . urlencode($district)); exit;
        }
    }

    if ($act === 'delete_assignment') {
        $raId = (int)($_POST['ra_id'] ?? 0);
        if ($raId) {
            $r = one("SELECT * FROM route_assignments WHERE ra_id=?", [$raId]);
            if ($r) {
                $jobIds = json_decode($r['job_ids'] ?? '[]', true) ?: [];
                foreach ($jobIds as $jid) q("UPDATE jobs SET emp_id_fk=NULL WHERE j_id=? AND emp_id_fk=?", [$jid, $r['emp_id_fk']]);
                q("DELETE FROM route_assignments WHERE ra_id=?", [$raId]);
            }
            header("Location: /admin/route-planner.php?deleted=1"); exit;
        }
    }
}

// Week navigation
$weekStart = $_GET['week'] ?? date('Y-m-d', strtotime('monday this week'));
$weekStartDt = new DateTime($weekStart);
$weekStartDt->modify('monday this week');
$weekStart = $weekStartDt->format('Y-m-d');
$prevWeek = (new DateTime($weekStart))->modify('-1 week')->format('Y-m-d');
$nextWeek = (new DateTime($weekStart))->modify('+1 week')->format('Y-m-d');
$weekEnd = (new DateTime($weekStart))->modify('+6 days')->format('Y-m-d');

$districts = all("SELECT * FROM berlin_districts ORDER BY sort_order, name");
$partners = all("SELECT emp_id, name, surname FROM employee WHERE status=1 ORDER BY name");

// ALL jobs this week grouped by district — not just premium
$jobs = all("SELECT j.j_id, j.j_date, j.j_time, j.j_hours, j.travel_block_until, j.emp_id_fk, j.address, j.doorbell_name, j.floor,
             c.customer_id, c.name AS cname, c.district, c.is_premium, c.travel_block_until AS cust_block
             FROM jobs j
             LEFT JOIN customer c ON j.customer_id_fk=c.customer_id
             WHERE j.j_date BETWEEN ? AND ?
             AND j.status=1
             AND (j.job_status IS NULL OR UPPER(j.job_status) NOT IN ('CANCELLED','CANCELED','REJECTED','DELETED'))
             ORDER BY j.j_date, j.j_time", [$weekStart, $weekEnd]);

// Index by date + district
$grid = [];
foreach ($jobs as $j) {
    $dist = $j['district'] ?: '— ohne Bezirk —';
    $grid[$j['j_date']][$dist][] = $j;
}

// Existing assignments this week
$assignments = all("SELECT ra.*, e.name AS emp_name FROM route_assignments ra
                    LEFT JOIN employee e ON ra.emp_id_fk=e.emp_id
                    WHERE ra.assignment_date BETWEEN ? AND ? ORDER BY ra.assignment_date", [$weekStart, $weekEnd]);
$assignmentsByDateDist = [];
foreach ($assignments as $a) $assignmentsByDateDist[$a['assignment_date']][$a['district']][] = $a;

// System-Status für Quick-Setup
$totalPremiumCust  = (int) val("SELECT COUNT(*) FROM customer WHERE is_premium=1");
$totalBlockVouch   = (int) val("SELECT COUNT(*) FROM vouchers WHERE block_until_time IS NOT NULL AND active=1");
$totalFuturePrem   = (int) val("SELECT COUNT(*) FROM jobs j LEFT JOIN customer c ON j.customer_id_fk=c.customer_id WHERE j.j_date >= CURDATE() AND j.status=1 AND (j.travel_block_until IS NOT NULL OR c.is_premium=1)");
$topCustomers      = all("SELECT customer_id, name, surname, email, district, is_premium FROM customer WHERE status=1 ORDER BY name, surname LIMIT 500");

// POST quick-setup actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $act = $_POST['action'] ?? '';
    if ($act === 'quick_mark_premium') {
        $cid  = (int)($_POST['customer_id'] ?? 0);
        $dist = $_POST['quick_district'] ?? '';
        $bu   = $_POST['quick_block_until'] ?? '15:00';
        if ($cid) {
            q("UPDATE customer SET is_premium=1, district=?, travel_block_until=? WHERE customer_id=?", [$dist ?: null, $bu ?: '15:00', $cid]);
            header("Location: /admin/route-planner.php?quickset=1"); exit;
        }
    }
    if ($act === 'quick_create_voucher') {
        $code = strtoupper(trim($_POST['quick_code'] ?? 'FLECKFREI-PREMIUM'));
        $ex = val("SELECT v_id FROM vouchers WHERE code=?", [$code]);
        if (!$ex) {
            q("INSERT INTO vouchers (code, description, type, value, valid_from, valid_until, max_uses, max_per_customer, active, block_until_time, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
              [$code, 'Premium/Weite Anfahrt · Partner-Tag bis 15:00', 'percent', 0, date('Y-m-d'), date('Y-m-d', strtotime('+1 year')), 0, 1, 1, '15:00', $me]);
        }
        header("Location: /admin/route-planner.php?quickvouch=1&code=" . urlencode($code)); exit;
    }
}

include __DIR__ . '/../includes/layout.php';
?>

<?php if (!empty($_GET['quickset'])): ?><div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4">✅ Kunde als Premium markiert — lädt jetzt in der Route-Woche.</div><?php endif; ?>
<?php if (!empty($_GET['quickvouch'])): ?><div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4">✅ Voucher <code><?= e($_GET['code'] ?? 'FLECKFREI-PREMIUM') ?></code> angelegt. Kunden können ihn jetzt einlösen → Partner-Tag bis 15:00 geblockt.</div><?php endif; ?>

<?php if (false && $totalFuturePrem === 0): // Quick-Setup-Card disabled (route planner now works for all jobs) ?>
<!-- QUICK-SETUP-CARD -->
<div class="bg-gradient-to-br from-amber-50 to-orange-50 border-2 border-amber-300 rounded-2xl p-6 mb-6">
  <h2 class="text-xl font-bold mb-2">🚀 Schnellstart — Route-Planner aktivieren</h2>
  <p class="text-sm text-amber-900 mb-4">Noch keine Premium-Jobs in der Pipeline (<?= $totalPremiumCust ?> Premium-Kunden · <?= $totalBlockVouch ?> aktive Voucher mit Tag-Block). So aktivierst du den Flow in <b>30 Sekunden</b>:</p>
  <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <!-- A: Kunden als Premium markieren -->
    <div class="bg-white rounded-xl border p-4">
      <h3 class="font-bold mb-2">A) Bestandskunde als Premium flaggen</h3>
      <form method="POST" class="space-y-2">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="quick_mark_premium"/>
        <select name="customer_id" required class="w-full px-3 py-2 border rounded-lg text-sm">
          <option value="">— Kunde wählen —</option>
          <?php foreach ($topCustomers as $cc): if ($cc['is_premium']) continue; ?>
          <option value="<?= $cc['customer_id'] ?>"><?= e(trim(($cc['name'] ?? '').' '.($cc['surname'] ?? ''))) ?> · <?= e($cc['email']) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="grid grid-cols-2 gap-2">
          <select name="quick_district" class="px-2 py-1.5 border rounded text-xs">
            <option value="">— Bezirk —</option>
            <?php foreach ($districts as $dd): ?><option value="<?= e($dd['name']) ?>"><?= e($dd['name']) ?></option><?php endforeach; ?>
          </select>
          <input type="time" name="quick_block_until" value="15:00" class="px-2 py-1.5 border rounded text-xs"/>
        </div>
        <button type="submit" class="w-full px-3 py-2 bg-amber-600 text-white rounded-lg text-sm font-semibold hover:bg-amber-700">⭐ Als Premium markieren</button>
      </form>
    </div>

    <!-- B: Voucher mit Block -->
    <div class="bg-white rounded-xl border p-4">
      <h3 class="font-bold mb-2">B) Premium-Voucher anlegen</h3>
      <p class="text-xs text-amber-900 mb-2">Ein Code den Kunden bei der Buchung eingeben → Partner-Tag bis 15:00 geblockt.</p>
      <form method="POST" class="space-y-2">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="quick_create_voucher"/>
        <input type="text" name="quick_code" value="FLECKFREI-PREMIUM" class="w-full px-3 py-2 border rounded-lg text-sm font-mono uppercase"/>
        <button type="submit" class="w-full px-3 py-2 bg-brand text-white rounded-lg text-sm font-semibold hover:bg-brand-dark">🎟 Voucher anlegen (1 Jahr gültig)</button>
      </form>
    </div>
  </div>
  <div class="mt-4 text-xs text-amber-800">
    ℹ️ Alternative: gehe direkt auf <a href="/admin/gutscheine.php?new=1" class="underline">/admin/gutscheine.php</a> oder öffne einen Kunden in <a href="/admin/customers.php" class="underline">/admin/customers.php</a>.
  </div>
</div>
<?php endif; ?>

<div class="flex items-center justify-between mb-4">
  <div class="flex items-center gap-3">
    <a href="/admin/route-planner.php?week=<?= $prevWeek ?>" class="px-3 py-2 border rounded-lg text-sm hover:bg-gray-50">← Woche</a>
    <div class="px-4 py-2 bg-brand text-white rounded-lg text-sm font-semibold">
      KW <?= date('W', strtotime($weekStart)) ?> · <?= date('d.m.', strtotime($weekStart)) ?> – <?= date('d.m.Y', strtotime($weekEnd)) ?>
    </div>
    <a href="/admin/route-planner.php?week=<?= $nextWeek ?>" class="px-3 py-2 border rounded-lg text-sm hover:bg-gray-50">Woche →</a>
    <a href="/admin/route-planner.php" class="text-xs text-brand hover:underline">Heute</a>
  </div>
  <div class="text-xs text-gray-500">
    <?= count($jobs) ?> Jobs · <?= count($assignments) ?> Routen geplant
  </div>
</div>

<?php if (!empty($_GET['assigned'])): ?><div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4">✅ Route zugewiesen: <?= e($_GET['district'] ?? '') ?> am <?= e($_GET['date'] ?? '') ?></div><?php endif; ?>
<?php if (!empty($_GET['deleted'])): ?><div class="bg-yellow-50 border border-yellow-200 text-yellow-800 px-4 py-3 rounded-xl mb-4">Route entfernt.</div><?php endif; ?>

<!-- 7-Tage × Bezirk Grid -->
<div class="bg-white rounded-xl border overflow-hidden">
  <div class="overflow-x-auto">
    <table class="w-full text-xs" id="route-grid">
      <thead class="bg-gray-50 sticky top-0">
        <tr>
          <th class="px-3 py-2 text-left font-semibold w-40">Bezirk</th>
          <?php for ($i=0; $i<7; $i++):
            $d = date('Y-m-d', strtotime("$weekStart +$i days"));
            $isToday = $d === date('Y-m-d');
          ?>
          <th class="px-2 py-2 text-center font-semibold border-l <?= $isToday ? 'bg-brand-light text-brand' : '' ?>">
            <div><?= ['Mo','Di','Mi','Do','Fr','Sa','So'][$i] ?></div>
            <div class="font-normal text-[10px]"><?= date('d.m', strtotime($d)) ?></div>
          </th>
          <?php endfor; ?>
        </tr>
      </thead>
      <tbody class="divide-y">
        <?php foreach ($districts as $dist):
          $dn = $dist['name'];
          $hasAny = false;
          for ($i=0; $i<7; $i++) { $d = date('Y-m-d', strtotime("$weekStart +$i days")); if (!empty($grid[$d][$dn])) { $hasAny = true; break; } }
          if (!$hasAny) continue; // hide empty districts for compactness
        ?>
        <tr class="hover:bg-gray-50">
          <td class="px-3 py-2 font-semibold"><?= $dist['icon'] ?> <?= e($dn) ?></td>
          <?php for ($i=0; $i<7; $i++):
            $d = date('Y-m-d', strtotime("$weekStart +$i days"));
            $cell = $grid[$d][$dn] ?? [];
            $assg = $assignmentsByDateDist[$d][$dn] ?? [];
          ?>
          <td class="px-1.5 py-2 border-l align-top min-w-[120px]">
            <?php if (!empty($assg)): ?>
              <?php foreach ($assg as $a): ?>
              <div class="mb-1 px-2 py-1 bg-purple-100 text-purple-900 rounded border border-purple-300">
                <div class="font-bold text-[11px]">✓ <?= e($a['emp_name'] ?? 'Partner #'.$a['emp_id_fk']) ?></div>
                <div class="text-[10px] text-purple-700">Jobs: <?= count(json_decode($a['job_ids'] ?? '[]', true) ?: []) ?></div>
                <form method="POST" class="inline" onsubmit="return confirm('Route wirklich löschen?')"><?= csrfField() ?><input type="hidden" name="action" value="delete_assignment"/><input type="hidden" name="ra_id" value="<?= $a['ra_id'] ?>"/><button class="text-[10px] text-red-600 hover:underline mt-0.5">↩ entfernen</button></form>
              </div>
              <?php endforeach; ?>
            <?php endif; ?>

            <?php foreach ($cell as $job): ?>
            <div class="mb-1 px-2 py-1 rounded border text-[11px] <?= $job['emp_id_fk'] ? 'bg-gray-100 border-gray-300 text-gray-600' : 'bg-amber-50 border-amber-300 text-amber-900' ?>">
              <div class="font-semibold truncate" title="<?= e($job['cname']) ?>">
                <?= !empty($job['is_premium']) ? '⭐' : '🚗' ?>
                <?= e(mb_strimwidth($job['cname'] ?? ('#'.$job['j_id']), 0, 16, '…')) ?>
              </div>
              <div class="text-[10px]"><?= substr($job['j_time'],0,5) ?>–<?= date('H:i', strtotime($job['j_time']) + $job['j_hours']*3600) ?></div>
              <?php if ($job['travel_block_until']): ?><div class="text-[9px] text-purple-700">block: <?= substr($job['travel_block_until'],0,5) ?></div><?php endif; ?>
            </div>
            <?php endforeach; ?>

            <?php if (!empty($cell)): // Assign-Button
              $unassignedJobs = array_values(array_filter($cell, fn($j) => !$j['emp_id_fk']));
              if (count($unassignedJobs) >= 1): ?>
              <details class="mt-1">
                <summary class="text-[10px] text-brand cursor-pointer hover:underline"><?= count($unassignedJobs) ?> als Route zuweisen</summary>
                <form method="POST" class="bg-white border rounded p-2 mt-1 space-y-1">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="assign_route"/>
                  <input type="hidden" name="assignment_date" value="<?= $d ?>"/>
                  <input type="hidden" name="district" value="<?= e($dn) ?>"/>
                  <?php foreach ($unassignedJobs as $uj): ?>
                  <label class="flex items-start gap-1 text-[10px]">
                    <input type="checkbox" name="job_ids[]" value="<?= $uj['j_id'] ?>" checked class="rounded mt-0.5"/>
                    <span><?= e(mb_strimwidth($uj['cname'] ?? '', 0, 20, '…')) ?> · <?= substr($uj['j_time'],0,5) ?></span>
                  </label>
                  <?php endforeach; ?>
                  <select name="emp_id_fk" class="w-full px-1 py-1 border rounded text-[10px]" required>
                    <option value="">Partner wählen…</option>
                    <option value="-1">🤖 Auto (wenigster ausgelastet)</option>
                    <?php foreach ($partners as $p): ?><option value="<?= $p['emp_id'] ?>"><?= e(trim(($p['name'] ?? '').' '.($p['surname'] ?? ''))) ?></option><?php endforeach; ?>
                  </select>
                  <button type="submit" class="w-full px-2 py-1 bg-brand text-white rounded text-[10px] font-semibold">Route zuweisen</button>
                </form>
              </details>
            <?php endif; endif; ?>
          </td>
          <?php endfor; ?>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($jobs)): ?>
        <tr><td colspan="8" class="px-4 py-12 text-center text-gray-400">Keine Jobs diese Woche.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Legende -->
<div class="mt-4 flex items-center gap-4 text-xs text-gray-500 flex-wrap">
  <span class="flex items-center gap-1"><span class="w-3 h-3 rounded bg-amber-100 border border-amber-300 inline-block"></span> Unzugewiesener Job</span>
  <span class="flex items-center gap-1"><span class="w-3 h-3 rounded bg-gray-100 border border-gray-300 inline-block"></span> Partner zugewiesen</span>
  <span class="flex items-center gap-1"><span class="w-3 h-3 rounded bg-purple-100 border border-purple-300 inline-block"></span> Route als Gruppe geplant</span>
  <span class="ml-auto">⭐ Premium · 🚗 Voucher-Block</span>
</div>

<?php include __DIR__.'/../includes/footer.php'; ?>
