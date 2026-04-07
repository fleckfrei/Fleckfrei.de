<?php
require_once __DIR__ . '/../includes/auth.php';
requireEmployee();
$title = 'Mein Profil'; $page = 'profile';
$user = me();
$eid = $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'update_profile') {
    q("UPDATE employee SET phone=?, iban=? WHERE emp_id=?",
      [$_POST['phone']??'', $_POST['iban']??'', $eid]);
    if (!empty($_POST['new_password'])) {
        q("UPDATE employee SET password=? WHERE emp_id=?", [password_hash($_POST['new_password'], PASSWORD_BCRYPT, ['cost' => 12]), $eid]);
    }
    header("Location: /employee/profile.php?saved=1"); exit;
}

$emp = one("SELECT * FROM employee WHERE emp_id=?", [$eid]);
$month = $_GET['month'] ?? date('Y-m');

// Earnings this month
$jobs = all("SELECT j.*, s.title as stitle, c.name as cname
    FROM jobs j LEFT JOIN services s ON j.s_id_fk=s.s_id LEFT JOIN customer c ON j.customer_id_fk=c.customer_id
    WHERE j.emp_id_fk=? AND j.job_status='COMPLETED' AND j.j_date LIKE ? AND j.status=1
    ORDER BY j.j_date DESC", [$eid, "$month%"]);

$totalHours = 0;
$totalEarnings = 0;
foreach ($jobs as $j) {
    $hrs = $j['total_hours'] ?: $j['j_hours'];
    $totalHours += $hrs;
    $totalEarnings += $hrs * ($emp['tariff'] ?: 0);
}

// Stats overall
$totalJobsAll = val("SELECT COUNT(*) FROM jobs WHERE emp_id_fk=? AND job_status='COMPLETED' AND status=1", [$eid]);
$totalHoursAll = val("SELECT COALESCE(SUM(COALESCE(total_hours, j_hours)),0) FROM jobs WHERE emp_id_fk=? AND job_status='COMPLETED' AND status=1", [$eid]);

include __DIR__ . '/../includes/layout.php';
?>

<?php if (!empty($_GET['saved'])): ?><div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4">Profil gespeichert.</div><?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
  <!-- Profile Card -->
  <div class="lg:col-span-1">
    <div class="bg-white rounded-xl border p-6 mb-6">
      <div class="text-center mb-6">
        <div class="w-20 h-20 rounded-full bg-gradient-to-br from-brand to-brand-dark text-white flex items-center justify-center text-2xl font-bold mx-auto mb-3"><?= strtoupper(substr($emp['name'],0,1)) ?></div>
        <h2 class="text-xl font-bold"><?= e($emp['name'].' '.($emp['surname']??'')) ?></h2>
        <p class="text-sm text-gray-500"><?= e($emp['partner_type'] ?? 'Cleaner') ?> — <?= e($emp['contract_type'] ?? 'Freelance') ?></p>
      </div>
      <div class="space-y-3 text-sm">
        <div class="flex justify-between"><span class="text-gray-500">E-Mail</span><span class="font-medium"><?= e($emp['email']) ?></span></div>
        <div class="flex justify-between"><span class="text-gray-500">Telefon</span><span class="font-medium"><?= e($emp['phone']) ?></span></div>
        <div class="flex justify-between"><span class="text-gray-500">Stundenlohn</span><span class="font-bold text-brand"><?= money($emp['tariff'] ?: 0) ?>/h</span></div>
        <div class="flex justify-between"><span class="text-gray-500">Jobs gesamt</span><span class="font-medium"><?= $totalJobsAll ?></span></div>
        <div class="flex justify-between"><span class="text-gray-500">Stunden gesamt</span><span class="font-medium"><?= round($totalHoursAll, 1) ?>h</span></div>
      </div>
    </div>

    <!-- Edit Form -->
    <div class="bg-white rounded-xl border p-5">
      <h3 class="font-semibold mb-4">Daten bearbeiten</h3>
      <form method="POST" class="space-y-4">
        <input type="hidden" name="action" value="update_profile"/>
        <div><label class="block text-sm font-medium text-gray-600 mb-1">Telefon</label><input name="phone" value="<?= e($emp['phone']) ?>" class="w-full px-3 py-2.5 border rounded-xl"/></div>
        <div><label class="block text-sm font-medium text-gray-600 mb-1">IBAN</label><input name="iban" value="<?= e($emp['iban']??'') ?>" placeholder="DE..." class="w-full px-3 py-2.5 border rounded-xl font-mono"/></div>
        <div><label class="block text-sm font-medium text-gray-600 mb-1">Neues Passwort</label><input type="password" name="new_password" placeholder="Leer = nicht ändern" class="w-full px-3 py-2.5 border rounded-xl"/></div>
        <button type="submit" class="w-full px-4 py-2.5 bg-brand text-white rounded-xl font-medium">Speichern</button>
      </form>
    </div>
  </div>

  <!-- Earnings -->
  <div class="lg:col-span-2">
    <!-- Month selector + summary -->
    <div class="flex items-center gap-4 mb-4">
      <a href="?month=<?= date('Y-m', strtotime("$month-01 -1 month")) ?>" class="px-3 py-2 border rounded-lg text-sm hover:bg-gray-50">&larr;</a>
      <input type="month" value="<?= $month ?>" onchange="location='?month='+this.value" class="px-3 py-2 border rounded-lg text-sm font-medium"/>
      <a href="?month=<?= date('Y-m', strtotime("$month-01 +1 month")) ?>" class="px-3 py-2 border rounded-lg text-sm hover:bg-gray-50">&rarr;</a>
    </div>

    <div class="grid grid-cols-2 gap-4 mb-6">
      <div class="bg-white rounded-xl border p-4">
        <div class="text-2xl font-bold text-brand"><?= number_format($totalHours, 1) ?>h</div>
        <div class="text-sm text-gray-500">Stunden im Monat</div>
      </div>
      <div class="bg-white rounded-xl border p-4">
        <div class="text-2xl font-bold text-green-600"><?= money($totalEarnings) ?></div>
        <div class="text-sm text-gray-500">Verdienst (<?= money($emp['tariff'] ?: 0) ?>/h)</div>
      </div>
    </div>

    <!-- Jobs table -->
    <div class="bg-white rounded-xl border">
      <div class="p-5 border-b"><h3 class="font-semibold">Erledigte Jobs — <?= count($jobs) ?></h3></div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50"><tr>
            <th class="px-4 py-3 text-left font-medium text-gray-600">Datum</th>
            <th class="px-4 py-3 text-left font-medium text-gray-600">Kunde</th>
            <th class="px-4 py-3 text-left font-medium text-gray-600">Service</th>
            <th class="px-4 py-3 text-left font-medium text-gray-600">Start</th>
            <th class="px-4 py-3 text-left font-medium text-gray-600">Ende</th>
            <th class="px-4 py-3 text-left font-medium text-gray-600">Std</th>
            <th class="px-4 py-3 text-left font-medium text-gray-600">Verdienst</th>
          </tr></thead>
          <tbody class="divide-y">
          <?php foreach ($jobs as $j):
            $hrs = $j['total_hours'] ?: $j['j_hours'];
            $earning = $hrs * ($emp['tariff'] ?: 0);
          ?>
          <tr class="hover:bg-gray-50">
            <td class="px-4 py-3 font-mono"><?= date('d.m.Y', strtotime($j['j_date'])) ?></td>
            <td class="px-4 py-3"><?= e($j['cname']) ?></td>
            <td class="px-4 py-3"><?= e($j['stitle']) ?></td>
            <td class="px-4 py-3 font-mono"><?= $j['start_time'] ? substr($j['start_time'],0,5) : '-' ?></td>
            <td class="px-4 py-3 font-mono"><?= $j['end_time'] ? substr($j['end_time'],0,5) : '-' ?></td>
            <td class="px-4 py-3 font-medium"><?= number_format($hrs, 1) ?>h</td>
            <td class="px-4 py-3 text-green-600 font-medium"><?= money($earning) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($jobs)): ?>
          <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">Keine Jobs in diesem Monat.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
