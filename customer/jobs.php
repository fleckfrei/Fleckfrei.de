<?php
require_once __DIR__ . '/../includes/auth.php';
requireCustomer();
if (!customerCan('jobs')) { header('Location: /customer/'); exit; }
$title = 'Meine Jobs'; $page = 'jobs';
$cid = me()['id'];

$tab = $_GET['tab'] ?? 'upcoming';
$today = date('Y-m-d');

if ($tab === 'upcoming') {
    $jobs = all("SELECT j.*, s.title as stitle, e.name as ename, e.surname as esurname
        FROM jobs j LEFT JOIN services s ON j.s_id_fk=s.s_id LEFT JOIN employee e ON j.emp_id_fk=e.emp_id
        WHERE j.customer_id_fk=? AND j.j_date>=? AND j.status=1 AND j.job_status!='CANCELLED'
        ORDER BY j.j_date, j.j_time", [$cid, $today]);
} elseif ($tab === 'completed') {
    $jobs = all("SELECT j.*, s.title as stitle, e.name as ename, e.surname as esurname
        FROM jobs j LEFT JOIN services s ON j.s_id_fk=s.s_id LEFT JOIN employee e ON j.emp_id_fk=e.emp_id
        WHERE j.customer_id_fk=? AND j.job_status='COMPLETED' AND j.status=1
        ORDER BY j.j_date DESC LIMIT 50", [$cid]);
} else {
    $jobs = all("SELECT j.*, s.title as stitle, e.name as ename, e.surname as esurname
        FROM jobs j LEFT JOIN services s ON j.s_id_fk=s.s_id LEFT JOIN employee e ON j.emp_id_fk=e.emp_id
        WHERE j.customer_id_fk=? AND j.job_status='CANCELLED' AND j.status=1
        ORDER BY j.j_date DESC LIMIT 50", [$cid]);
}

include __DIR__ . '/../includes/layout.php';
?>

<!-- Tabs -->
<div class="flex gap-1 mb-6 bg-gray-100 rounded-xl p-1 w-fit">
  <?php foreach (['upcoming'=>'Kommend','completed'=>'Erledigt','cancelled'=>'Storniert'] as $k=>$v): ?>
  <a href="?tab=<?= $k ?>" class="px-4 py-2 rounded-lg text-sm font-medium transition <?= $tab===$k ? 'bg-white shadow text-brand' : 'text-gray-500 hover:text-gray-800' ?>"><?= $v ?></a>
  <?php endforeach; ?>
</div>

<div class="bg-white rounded-xl border">
  <div class="p-5 border-b"><h3 class="font-semibold"><?= count($jobs) ?> Jobs</h3></div>
  <div class="divide-y">
    <?php foreach ($jobs as $j): ?>
    <div class="px-5 py-4">
      <div class="flex items-start justify-between">
        <div>
          <div class="font-medium"><?= e($j['stitle'] ?: 'Service') ?></div>
          <div class="text-sm text-gray-500">
            <?= date('d.m.Y', strtotime($j['j_date'])) ?> um <?= substr($j['j_time'],0,5) ?> — <?= $j['j_hours'] ?>h
          </div>
          <?php if ($j['address']): ?><div class="text-xs text-gray-400 mt-1"><?= e($j['address']) ?></div><?php endif; ?>
          <?php if ($j['ename']): ?><div class="text-xs text-gray-400">Partner: <?= e($j['ename'].' '.($j['esurname']??'')) ?></div><?php endif; ?>
          <?php if ($j['job_note']): ?><div class="text-xs text-gray-500 mt-1 bg-gray-50 rounded px-2 py-1"><?= e($j['job_note']) ?></div><?php endif; ?>
          <?php
            $photos = !empty($j['job_photos']) ? json_decode($j['job_photos'], true) : [];
            if (!empty($photos)):
          ?>
          <div class="flex gap-2 mt-2">
            <?php foreach ($photos as $p): ?>
            <a href="/uploads/jobs/<?= $j['j_id'] ?>/<?= e($p) ?>" target="_blank"><img src="/uploads/jobs/<?= $j['j_id'] ?>/<?= e($p) ?>" class="w-12 h-12 object-cover rounded-lg border"/></a>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
        <div class="text-right">
          <?= badge($j['job_status']) ?>
          <?php if ($j['total_hours']): ?><div class="text-xs text-gray-400 mt-1"><?= round($j['total_hours'],1) ?>h real</div><?php endif; ?>
          <?php if (customerCan('cancel') && in_array($j['job_status'], ['PENDING','CONFIRMED'])): ?>
          <button onclick="if(confirm('Job stornieren?'))fetch('/api/index.php?action=jobs/status',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({j_id:<?= $j['j_id'] ?>,status:'CANCELLED'})}).then(()=>location.reload())" class="mt-2 px-3 py-1 text-xs bg-red-50 text-red-600 rounded-lg">Stornieren</button>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if (empty($jobs)): ?>
    <div class="px-5 py-12 text-center text-gray-400">Keine Jobs in dieser Kategorie.</div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
