<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Tage sperren'; $page = 'blocked-days';
$me = $_SESSION['uemail'] ?? 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $act = $_POST['action'] ?? '';
    if ($act === 'add_category') {
        $n = trim($_POST['cat_name'] ?? '');
        $ic = trim($_POST['cat_icon'] ?? '🚫');
        if ($n) q("INSERT IGNORE INTO block_categories (name, icon, created_by) VALUES (?,?,?)", [$n, $ic, $me]);
        header("Location: /admin/blocked-days.php?catadded=1"); exit;
    }
    if ($act === 'add_block') {
        $from = $_POST['date_from'] ?? '';
        $to   = $_POST['date_to']   ?? $from;
        $weekdays = $_POST['weekdays'] ?? []; // array of 1-7 (ISO: Mo=1..So=7)
        $mask = is_array($weekdays) ? implode(',', array_map('intval', $weekdays)) : null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            q("INSERT INTO admin_blocked_days (date_from, date_to, reason, applies_to, customer_id_fk, prebook_token, category, weekday_mask, created_by) VALUES (?,?,?,?,?,?,?,?,?)", [
                $from, $to,
                trim($_POST['reason'] ?? '') ?: null,
                in_array($_POST['applies_to'] ?? 'all', ['all','premium_only','non_premium','prebook_only'], true) ? $_POST['applies_to'] : 'all',
                !empty($_POST['customer_id_fk']) ? (int)$_POST['customer_id_fk'] : null,
                trim($_POST['prebook_token'] ?? '') ?: null,
                trim($_POST['category'] ?? '') ?: null,
                $mask ?: null,
                $me
            ]);
            header("Location: /admin/blocked-days.php?saved=1"); exit;
        }
    }
    if ($act === 'delete_block') {
        q("DELETE FROM admin_blocked_days WHERE bd_id=?", [(int)$_POST['bd_id']]);
        header("Location: /admin/blocked-days.php?deleted=1"); exit;
    }
}

$blocks = all("SELECT ab.*, c.name AS cname FROM admin_blocked_days ab LEFT JOIN customer c ON ab.customer_id_fk=c.customer_id WHERE ab.date_to >= CURDATE() ORDER BY ab.date_from ASC");
$past   = all("SELECT ab.*, c.name AS cname FROM admin_blocked_days ab LEFT JOIN customer c ON ab.customer_id_fk=c.customer_id WHERE ab.date_to < CURDATE() ORDER BY ab.date_from DESC LIMIT 20");
$custs  = all("SELECT customer_id, name, email, is_premium FROM customer WHERE status=1 ORDER BY name LIMIT 500");
$cats   = all("SELECT * FROM block_categories ORDER BY cat_id");
$preb   = all("SELECT token, email, name FROM prebooking_links WHERE used_at IS NULL AND expires_at >= CURDATE() ORDER BY pl_id DESC LIMIT 50");
include __DIR__ . '/../includes/layout.php';
?>

<?php if (!empty($_GET['saved'])): ?><div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4">✅ Tage gesperrt.</div><?php endif; ?>
<?php if (!empty($_GET['deleted'])): ?><div class="bg-yellow-50 border border-yellow-200 text-yellow-800 px-4 py-3 rounded-xl mb-4">Sperre aufgehoben.</div><?php endif; ?>

<div id="catform" class="hidden bg-brand-light/30 border-2 border-brand/30 rounded-xl p-4 mb-4">
  <form method="POST" class="flex gap-2 items-end">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="add_category"/>
    <div class="flex-1"><label class="block text-xs font-semibold mb-1">Kategorie-Name</label><input name="cat_name" required placeholder="z.B. Krankheit" class="w-full px-3 py-2 border rounded-lg text-sm"/></div>
    <div><label class="block text-xs font-semibold mb-1">Icon</label><input name="cat_icon" value="🚫" maxlength="4" class="w-20 px-3 py-2 border rounded-lg text-sm text-center"/></div>
    <button type="submit" class="px-4 py-2 bg-brand text-white rounded-lg text-sm font-semibold">+ Anlegen</button>
  </form>
</div>

<div class="bg-white rounded-xl border p-5 mb-6">
  <h2 class="text-xl font-bold mb-2">🚫 Tage sperren</h2>
  <p class="text-sm text-gray-600 mb-4">Sperre einzelne Tage oder Zeiträume — global, nur für Premium-Kunden, nur für Non-Premium, oder nur für einen bestimmten Kunden.</p>
  <form method="POST" class="grid grid-cols-1 md:grid-cols-4 gap-3">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="add_block"/>
    <div>
      <label class="block text-xs font-semibold mb-1">Von *</label>
      <input type="date" name="date_from" required min="<?= date('Y-m-d') ?>" class="w-full px-3 py-2 border rounded-lg text-sm"/>
    </div>
    <div>
      <label class="block text-xs font-semibold mb-1">Bis (optional)</label>
      <input type="date" name="date_to" class="w-full px-3 py-2 border rounded-lg text-sm"/>
    </div>
    <div>
      <label class="block text-xs font-semibold mb-1">Gilt für</label>
      <select name="applies_to" class="w-full px-3 py-2 border rounded-lg text-sm">
        <option value="all">🚫 Alle Kunden</option>
        <option value="premium_only">⭐ Nur Premium-Kunden</option>
        <option value="non_premium">👥 Nur normale Kunden</option>
        <option value="prebook_only">🔗 Nur Prebook-Link-Kunden</option>
      </select>
    </div>
    <div>
      <label class="block text-xs font-semibold mb-1">Einzelner Kunde (optional)</label>
      <select name="customer_id_fk" class="w-full px-3 py-2 border rounded-lg text-sm">
        <option value="">— alle laut "Gilt für" —</option>
        <?php foreach ($custs as $cc): ?>
        <option value="<?= $cc['customer_id'] ?>">#<?= $cc['customer_id'] ?> · <?= e($cc['name']) ?><?= $cc['is_premium'] ? ' ⭐' : '' ?> · <?= e($cc['email']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="block text-xs font-semibold mb-1">🔗 Nur für spezifischen Prebook-Link</label>
      <select name="prebook_token" class="w-full px-3 py-2 border rounded-lg text-sm">
        <option value="">— alle laut "Gilt für" —</option>
        <?php foreach ($preb as $p): ?>
        <option value="<?= e($p['token']) ?>"><?= e($p['name'] ?: $p['email']) ?> (/p/<?= e($p['token']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="block text-xs font-semibold mb-1">🏷 Kategorie</label>
      <div class="flex gap-1">
        <select name="category" class="flex-1 px-3 py-2 border rounded-lg text-sm">
          <option value="">—</option>
          <?php foreach ($cats as $cat): ?>
          <option value="<?= e($cat['name']) ?>"><?= $cat['icon'] ?> <?= e($cat['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="button" onclick="document.getElementById('catform').classList.toggle('hidden')" class="px-2 py-1 text-xs border rounded text-brand hover:bg-brand-light" title="Neue Kategorie">+</button>
      </div>
    </div>
    <div class="md:col-span-4">
      <label class="block text-xs font-semibold mb-1">🔁 Wochentag-Muster (optional) — wenn gesetzt, sperrt nur diese Wochentage im Zeitraum</label>
      <div class="flex gap-2 flex-wrap">
        <?php foreach (['Mo'=>1,'Di'=>2,'Mi'=>3,'Do'=>4,'Fr'=>5,'Sa'=>6,'So'=>7] as $lbl => $n): ?>
        <label class="flex items-center gap-1.5 px-3 py-1.5 border rounded-lg cursor-pointer hover:bg-brand-light">
          <input type="checkbox" name="weekdays[]" value="<?= $n ?>" class="rounded"/>
          <span class="text-sm"><?= $lbl ?></span>
        </label>
        <?php endforeach; ?>
      </div>
      <div class="text-[11px] text-gray-500 mt-1">z.B. nur <b>Samstag</b> ankreuzen + Bis-Datum = 31.12. → alle Samstage bis Jahresende gesperrt.</div>
    </div>
    <div class="md:col-span-4">
      <label class="block text-xs font-semibold mb-1">Grund (intern)</label>
      <input name="reason" placeholder="z.B. Feiertag · Wartung · Team-Tag · Urlaub Fleckfrei" class="w-full px-3 py-2 border rounded-lg text-sm"/>
    </div>
    <div class="md:col-span-4">
      <button type="submit" class="px-5 py-2.5 bg-brand text-white rounded-xl font-semibold hover:bg-brand-dark">🚫 Sperre anlegen</button>
    </div>
  </form>
</div>

<div class="bg-white rounded-xl border mb-6">
  <div class="p-5 border-b"><h3 class="font-bold">Aktive Sperren (<?= count($blocks) ?>)</h3></div>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-gray-50"><tr>
        <th class="px-3 py-2 text-left">Zeitraum</th>
        <th class="px-3 py-2 text-left">Dauer</th>
        <th class="px-3 py-2 text-left">Gilt für</th>
        <th class="px-3 py-2 text-left">Kunde</th>
        <th class="px-3 py-2 text-left">Grund</th>
        <th class="px-3 py-2 text-left">Erstellt</th>
        <th class="px-3 py-2 text-left"></th>
      </tr></thead>
      <tbody class="divide-y">
      <?php foreach ($blocks as $b):
        $days = (strtotime($b['date_to']) - strtotime($b['date_from']))/86400 + 1;
        $label = ['all'=>'🚫 Alle','premium_only'=>'⭐ Premium','non_premium'=>'👥 Normal'][$b['applies_to']] ?? $b['applies_to'];
      ?>
      <tr class="hover:bg-gray-50">
        <td class="px-3 py-2"><?= date('d.m.Y', strtotime($b['date_from'])) ?><?= $b['date_from']!==$b['date_to'] ? ' – '.date('d.m.Y', strtotime($b['date_to'])) : '' ?></td>
        <td class="px-3 py-2 text-xs text-gray-500"><?= (int)$days ?> Tag<?= $days!=1?'e':'' ?></td>
        <td class="px-3 py-2 text-xs"><?= e($label) ?></td>
        <td class="px-3 py-2 text-xs"><?= $b['cname'] ? e($b['cname']) : '—' ?></td>
        <td class="px-3 py-2 text-xs text-gray-600"><?= e($b['reason'] ?: '—') ?></td>
        <td class="px-3 py-2 text-[10px] text-gray-400"><?= date('d.m. H:i', strtotime($b['created_at'])) ?> · <?= e($b['created_by']) ?></td>
        <td class="px-3 py-2">
          <form method="POST" class="inline" onsubmit="return confirm('Sperre aufheben?')"><?= csrfField() ?><input type="hidden" name="action" value="delete_block"/><input type="hidden" name="bd_id" value="<?= $b['bd_id'] ?>"/><button class="px-2 py-1 text-xs bg-red-50 text-red-600 rounded">✕ Aufheben</button></form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($blocks)): ?><tr><td colspan="7" class="px-3 py-8 text-center text-gray-400">Keine aktiven Sperren.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if (!empty($past)): ?>
<details class="bg-white rounded-xl border">
  <summary class="p-5 cursor-pointer font-bold hover:bg-gray-50">🗄 Vergangene Sperren (letzte 20)</summary>
  <div class="px-5 pb-4">
    <?php foreach ($past as $b): ?>
    <div class="py-2 border-b text-xs text-gray-500"><?= date('d.m.Y', strtotime($b['date_from'])) ?><?= $b['date_from']!==$b['date_to'] ? ' – '.date('d.m.Y', strtotime($b['date_to'])) : '' ?> · <?= e($b['reason'] ?: '—') ?></div>
    <?php endforeach; ?>
  </div>
</details>
<?php endif; ?>

<?php include __DIR__.'/../includes/footer.php'; ?>
