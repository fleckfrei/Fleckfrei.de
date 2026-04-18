<?php
/**
 * Admin: Booking-Priorität (STR-Fenster) + Pending Shifts
 */
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Buchungs-Priorität'; $page = 'booking-priority';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $act = $_POST['action'] ?? '';

    if ($act === 'save_window') {
        $pwId = (int)($_POST['pw_id'] ?? 0);
        // Checkbox-Arrays ODER Komma-String (backwards-compat)
        $prio = is_array($_POST['priority_types'] ?? null)
            ? array_map('trim', $_POST['priority_types'])
            : array_map('trim', explode(',', $_POST['priority_types'] ?? ''));
        $shift = is_array($_POST['shiftable_types'] ?? null)
            ? array_map('trim', $_POST['shiftable_types'])
            : array_map('trim', explode(',', $_POST['shiftable_types'] ?? ''));
        $params = [
            trim($_POST['priority_label'] ?? ''),
            $_POST['start_time'] ?? '11:00',
            $_POST['end_time'] ?? '16:00',
            json_encode(array_filter($prio)),
            json_encode(array_filter($shift)),
            (int)($_POST['max_shift_minutes'] ?? 30),
            !empty($_POST['allow_next_day']) ? 1 : 0,
            $_POST['fallback_mode'] ?? 'escalate',
            !empty($_POST['active']) ? 1 : 0,
            trim($_POST['notes'] ?? '')
        ];
        if ($pwId) {
            $params[] = $pwId;
            q("UPDATE booking_priority_windows SET priority_label=?, start_time=?, end_time=?, priority_customer_types=?, shiftable_customer_types=?, max_shift_minutes=?, allow_next_day=?, fallback_mode=?, active=?, notes=? WHERE pw_id=?", $params);
        } else {
            q("INSERT INTO booking_priority_windows (priority_label, start_time, end_time, priority_customer_types, shiftable_customer_types, max_shift_minutes, allow_next_day, fallback_mode, active, notes) VALUES (?,?,?,?,?,?,?,?,?,?)", $params);
        }
        header('Location: /admin/booking-priority.php?saved=1'); exit;
    }

    if ($act === 'toggle_window') {
        q("UPDATE booking_priority_windows SET active=1-active WHERE pw_id=?", [(int)$_POST['pw_id']]);
        header('Location: /admin/booking-priority.php?saved=1'); exit;
    }

    if ($act === 'apply_preset') {
        $presets = [
            'str' => [
                'priority_label' => 'STR_TURNOVER',
                'start_time' => '11:00', 'end_time' => '16:00',
                'priority_types' => ['Airbnb','Host','Co-Host','Short-Term Rental','Booking'],
                'shiftable_types' => ['Private Person','Private','Company','Firma','GmbH','B2B','Business'],
                'max_shift_minutes' => 120, 'allow_next_day' => 1, 'fallback_mode' => 'escalate',
                'notes' => 'STR-Turnover-Fenster: Check-outs haben Priorität, Privatkunden werden automatisch vor/nach dem Fenster gelegt.'
            ],
            'office' => [
                'priority_label' => 'B2B_MORNING',
                'start_time' => '07:00', 'end_time' => '10:00',
                'priority_types' => ['B2B','Company','Firma','GmbH','Business'],
                'shiftable_types' => ['Private Person','Private','Airbnb','Host','Co-Host'],
                'max_shift_minutes' => 120, 'allow_next_day' => 1, 'fallback_mode' => 'escalate',
                'notes' => 'B2B-Morgen-Fenster: Büros wollen früh gereinigt sein — andere Kundentypen werden auf später verschoben.'
            ]
        ];
        $p = $presets[$_POST['preset'] ?? ''] ?? null;
        if ($p) {
            $exists = val("SELECT pw_id FROM booking_priority_windows WHERE priority_label=?", [$p['priority_label']]);
            if ($exists) {
                q("UPDATE booking_priority_windows SET start_time=?, end_time=?, priority_customer_types=?, shiftable_customer_types=?, max_shift_minutes=?, allow_next_day=?, fallback_mode=?, active=1, notes=? WHERE pw_id=?",
                    [$p['start_time'], $p['end_time'], json_encode($p['priority_types']), json_encode($p['shiftable_types']), $p['max_shift_minutes'], $p['allow_next_day'], $p['fallback_mode'], $p['notes'], $exists]);
            } else {
                q("INSERT INTO booking_priority_windows (priority_label, start_time, end_time, priority_customer_types, shiftable_customer_types, max_shift_minutes, allow_next_day, fallback_mode, active, notes) VALUES (?,?,?,?,?,?,?,?,1,?)",
                    [$p['priority_label'], $p['start_time'], $p['end_time'], json_encode($p['priority_types']), json_encode($p['shiftable_types']), $p['max_shift_minutes'], $p['allow_next_day'], $p['fallback_mode'], $p['notes']]);
            }
        }
        header('Location: /admin/booking-priority.php?preset=1'); exit;
    }

    if ($act === 'shift_respond') {
        $psId = (int)$_POST['ps_id'];
        $resp = $_POST['response'] ?? '';
        $ps = one("SELECT * FROM pending_shifts WHERE ps_id=?", [$psId]);
        if ($ps && in_array($resp, ['accepted','rejected'])) {
            q("UPDATE pending_shifts SET customer_response=?, admin_override=1, responded_at=NOW() WHERE ps_id=?", [$resp, $psId]);
            if ($resp === 'accepted') {
                q("UPDATE jobs SET j_date=?, j_time=?, job_note=CONCAT(IFNULL(job_note,''), '\n[Admin-Shift ', NOW(), ']') WHERE j_id=?",
                  [$ps['proposed_date'], $ps['proposed_time'], $ps['job_id_fk']]);
                audit('admin_shift_accept', 'job', $ps['job_id_fk'], "→ {$ps['proposed_date']} {$ps['proposed_time']}");
            }
        }
        header('Location: /admin/booking-priority.php'); exit;
    }
}

$windows = all("SELECT * FROM booking_priority_windows ORDER BY active DESC, start_time");
$pendingShifts = all("SELECT ps.*, j.j_id, j.platform, c.name as customer_name, c.customer_type, c.phone, s.title as service_title
                     FROM pending_shifts ps
                     LEFT JOIN jobs j ON ps.job_id_fk=j.j_id
                     LEFT JOIN customer c ON j.customer_id_fk=c.customer_id
                     LEFT JOIN services s ON j.s_id_fk=s.s_id
                     WHERE ps.customer_response='pending'
                     ORDER BY ps.created_at DESC LIMIT 50");

include __DIR__ . '/../includes/layout.php';
?>

<?php if (!empty($_GET['saved'])): ?>
<div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4">✓ Gespeichert</div>
<?php endif; ?>
<?php if (!empty($_GET['preset'])): ?>
<div class="bg-blue-50 border border-blue-200 text-blue-800 px-4 py-3 rounded-xl mb-4">✨ Preset aktiviert — Auto-Shift läuft ab sofort.</div>
<?php endif; ?>

<div class="mb-6">
  <h1 class="text-2xl font-bold text-gray-900">Buchungs-Priorität & Shifting</h1>
  <p class="text-sm text-gray-600 mt-1">Legt fest wann welche Kundentypen Vorrang haben. Andere werden automatisch vor/nach das Fenster verschoben — ohne Admin-Eingriff.</p>
</div>

<!-- Quick-Presets: 1-Klick-Setup -->
<div class="bg-gradient-to-br from-brand-light/40 to-white border-2 border-brand/30 rounded-xl p-5 mb-6">
  <div class="flex items-center gap-2 mb-3">
    <span class="text-xl">⚡</span>
    <h2 class="font-bold text-gray-900">Schnell-Start (1 Klick)</h2>
  </div>
  <p class="text-xs text-gray-600 mb-4">Aktiviert typische Fenster automatisch. Du kannst sie danach trotzdem anpassen.</p>
  <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
    <form method="POST" onsubmit="return confirm('STR-Turnover-Fenster 11:00–16:00 anlegen/aktivieren?')" class="bg-white rounded-lg border p-4 hover:border-brand hover:shadow-md transition cursor-pointer" onclick="this.querySelector('button').click()">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="apply_preset"/>
      <input type="hidden" name="preset" value="str"/>
      <div class="flex items-center gap-2 mb-1">
        <span class="text-lg">🏨</span>
        <span class="font-bold text-gray-900">STR-Turnover 11:00–16:00</span>
      </div>
      <div class="text-xs text-gray-600 mb-3">Airbnb / Host / Booking haben Vorrang. Private/B2B werden automatisch auf 09:00 oder 16:00 verschoben.</div>
      <button type="submit" class="w-full px-3 py-2 bg-brand text-white rounded-lg text-sm font-semibold hover:bg-brand-dark">✓ Aktivieren</button>
    </form>
    <form method="POST" onsubmit="return confirm('B2B-Morgen-Fenster 07:00–10:00 anlegen/aktivieren?')" class="bg-white rounded-lg border p-4 hover:border-brand hover:shadow-md transition cursor-pointer" onclick="this.querySelector('button').click()">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="apply_preset"/>
      <input type="hidden" name="preset" value="office"/>
      <div class="flex items-center gap-2 mb-1">
        <span class="text-lg">💼</span>
        <span class="font-bold text-gray-900">B2B-Morgen 07:00–10:00</span>
      </div>
      <div class="text-xs text-gray-600 mb-3">Büros / B2B haben Vorrang. Privat / Airbnb werden auf später am Tag verschoben.</div>
      <button type="submit" class="w-full px-3 py-2 bg-brand text-white rounded-lg text-sm font-semibold hover:bg-brand-dark">✓ Aktivieren</button>
    </form>
  </div>
</div>

<!-- Pending Shifts — Action-Required -->
<?php if (!empty($pendingShifts)): ?>
<div class="bg-red-50 border-2 border-red-300 rounded-xl p-5 mb-6">
  <h2 class="text-lg font-bold text-red-900 mb-3">🚨 <?= count($pendingShifts) ?> offene Verschiebungs-Anfragen</h2>
  <div class="space-y-2">
    <?php foreach ($pendingShifts as $ps): ?>
    <div class="bg-white rounded-lg p-3 flex items-center justify-between gap-3">
      <div class="flex-1 min-w-0">
        <div class="font-semibold text-sm text-gray-900">
          Job #<?= $ps['job_id_fk'] ?> — <?= e($ps['customer_name']) ?>
          <span class="text-xs text-gray-600">(<?= e($ps['customer_type']) ?>)</span>
        </div>
        <div class="text-xs text-gray-700 mt-0.5">
          <strong>Von:</strong> <?= date('d.m.Y', strtotime($ps['original_date'])) ?> <?= substr($ps['original_time'],0,5) ?>
          →
          <strong>Auf:</strong> <?= date('d.m.Y', strtotime($ps['proposed_date'])) ?> <?= substr($ps['proposed_time'],0,5) ?>
          <span class="ml-2 text-gray-500">(<?= $ps['shift_minutes'] ?> min · <?= e($ps['reason']) ?>)</span>
        </div>
        <div class="text-[11px] text-gray-500 mt-0.5">
          📞 <?= e($ps['phone']) ?> · <?= e($ps['service_title']) ?> · 🕐 <?= date('H:i', strtotime($ps['created_at'])) ?>
        </div>
      </div>
      <div class="flex gap-2">
        <form method="POST" class="inline">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="shift_respond"/>
          <input type="hidden" name="ps_id" value="<?= $ps['ps_id'] ?>"/>
          <input type="hidden" name="response" value="accepted"/>
          <button type="submit" class="px-3 py-1.5 bg-green-600 text-white rounded-lg text-xs font-bold hover:bg-green-700">✓ Verschieben</button>
        </form>
        <form method="POST" class="inline">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="shift_respond"/>
          <input type="hidden" name="ps_id" value="<?= $ps['ps_id'] ?>"/>
          <input type="hidden" name="response" value="rejected"/>
          <button type="submit" class="px-3 py-1.5 bg-red-100 text-red-700 rounded-lg text-xs font-bold hover:bg-red-200">✕ Ablehnen</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Priority Windows -->
<div class="bg-white rounded-xl border overflow-hidden mb-6">
  <div class="p-5 border-b flex items-center justify-between">
    <div>
      <h2 class="font-bold text-gray-900">Priority-Fenster</h2>
      <p class="text-xs text-gray-600 mt-0.5">Während dieser Zeitfenster haben Prio-Kundentypen Vorrang. Andere Kunden werden automatisch verschoben.</p>
    </div>
    <button onclick="document.getElementById('windowForm').reset(); document.getElementById('windowForm').querySelector('[name=pw_id]').value=''; document.getElementById('formTitle').textContent='Neues Prio-Fenster'; document.getElementById('windowModal').classList.remove('hidden')" class="px-4 py-2 bg-brand text-white rounded-xl text-sm font-semibold">+ Neu</button>
  </div>
  <table class="w-full text-sm">
    <thead class="bg-gray-50 border-b">
      <tr>
        <th class="text-left px-4 py-3 font-medium">Name</th>
        <th class="text-left px-4 py-3 font-medium">Fenster</th>
        <th class="text-left px-4 py-3 font-medium">Prio-Kunden</th>
        <th class="text-left px-4 py-3 font-medium">Verschiebbar</th>
        <th class="text-left px-4 py-3 font-medium">Fallback</th>
        <th class="text-center px-4 py-3 font-medium">Aktiv</th>
        <th class="text-right px-4 py-3 font-medium">Aktion</th>
      </tr>
    </thead>
    <tbody class="divide-y">
    <?php foreach ($windows as $w):
      $prio = json_decode($w['priority_customer_types'], true) ?: [];
      $shift = json_decode($w['shiftable_customer_types'], true) ?: [];
    ?>
      <tr class="<?= $w['active'] ? '' : 'opacity-50' ?>">
        <td class="px-4 py-3 font-semibold"><?= e($w['priority_label']) ?>
          <?php if ($w['notes']): ?><div class="text-[11px] text-gray-500"><?= e($w['notes']) ?></div><?php endif; ?>
        </td>
        <td class="px-4 py-3 font-mono text-sm"><?= substr($w['start_time'],0,5) ?> – <?= substr($w['end_time'],0,5) ?></td>
        <td class="px-4 py-3">
          <div class="flex flex-wrap gap-1">
            <?php foreach ($prio as $t): ?><span class="px-1.5 py-0.5 bg-brand-light text-brand-dark text-[10px] rounded font-medium"><?= e($t) ?></span><?php endforeach; ?>
          </div>
        </td>
        <td class="px-4 py-3">
          <div class="flex flex-wrap gap-1">
            <?php foreach ($shift as $t): ?><span class="px-1.5 py-0.5 bg-gray-100 text-gray-700 text-[10px] rounded"><?= e($t) ?></span><?php endforeach; ?>
          </div>
        </td>
        <td class="px-4 py-3 text-xs"><?= e($w['fallback_mode']) ?></td>
        <td class="px-4 py-3 text-center">
          <form method="POST" class="inline">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="toggle_window"/>
            <input type="hidden" name="pw_id" value="<?= $w['pw_id'] ?>"/>
            <button class="<?= $w['active'] ? 'text-green-600' : 'text-gray-400' ?> text-lg"><?= $w['active'] ? '●' : '○' ?></button>
          </form>
        </td>
        <td class="px-4 py-3 text-right">
          <button onclick='editWindow(<?= json_encode($w, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)' class="text-brand hover:underline text-xs">Bearbeiten</button>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Edit Modal -->
<div id="windowModal" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4" onclick="if(event.target===this)this.classList.add('hidden')">
  <div class="bg-white rounded-xl p-5 w-full max-w-lg shadow-2xl">
    <h3 class="font-bold text-gray-900 mb-4" id="formTitle">Prio-Fenster</h3>
    <form id="windowForm" method="POST" class="space-y-3">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save_window"/>
      <input type="hidden" name="pw_id"/>
      <div>
        <label class="block text-xs font-semibold text-gray-700 mb-1 uppercase">Name</label>
        <input type="text" name="priority_label" required class="w-full px-3 py-2 border rounded-lg text-sm"/>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-xs font-semibold text-gray-700 mb-1 uppercase">Von</label>
          <input type="time" name="start_time" required value="11:00" class="w-full px-3 py-2 border rounded-lg text-sm"/>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-700 mb-1 uppercase">Bis</label>
          <input type="time" name="end_time" required value="16:00" class="w-full px-3 py-2 border rounded-lg text-sm"/>
        </div>
      </div>
      <?php $allTypes = ['Airbnb','Host','Co-Host','Short-Term Rental','Booking','Private Person','Private','Company','Firma','GmbH','B2B','Business']; ?>
      <div>
        <label class="block text-xs font-semibold text-gray-700 mb-1 uppercase">⭐ Prio-Kundentypen (haben Vorrang)</label>
        <div class="grid grid-cols-2 gap-1.5 p-2 bg-gray-50 rounded-lg">
          <?php foreach ($allTypes as $t): ?>
          <label class="flex items-center gap-1.5 px-2 py-1 hover:bg-white rounded cursor-pointer">
            <input type="checkbox" name="priority_types[]" value="<?= e($t) ?>" data-field="prio" class="rounded"/>
            <span class="text-xs"><?= e($t) ?></span>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-700 mb-1 uppercase">↔️ Verschiebbare Typen (werden auto-umgeplant)</label>
        <div class="grid grid-cols-2 gap-1.5 p-2 bg-gray-50 rounded-lg">
          <?php foreach ($allTypes as $t): ?>
          <label class="flex items-center gap-1.5 px-2 py-1 hover:bg-white rounded cursor-pointer">
            <input type="checkbox" name="shiftable_types[]" value="<?= e($t) ?>" data-field="shift" class="rounded"/>
            <span class="text-xs"><?= e($t) ?></span>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="grid grid-cols-3 gap-3">
        <div>
          <label class="block text-xs font-semibold text-gray-700 mb-1 uppercase">Max Shift (min)</label>
          <input type="number" name="max_shift_minutes" value="30" class="w-full px-3 py-2 border rounded-lg text-sm"/>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-700 mb-1 uppercase">Nächster Tag OK?</label>
          <input type="checkbox" name="allow_next_day" value="1" checked class="w-5 h-5 mt-2"/>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-700 mb-1 uppercase">Fallback</label>
          <select name="fallback_mode" class="w-full px-3 py-2 border rounded-lg text-sm">
            <option value="escalate">Admin eskalieren</option>
            <option value="force_same_day">Muss gleicher Tag</option>
            <option value="overbook">Überbuchen</option>
          </select>
        </div>
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-700 mb-1 uppercase">Notiz</label>
        <input type="text" name="notes" class="w-full px-3 py-2 border rounded-lg text-sm"/>
      </div>
      <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="active" value="1" checked class="w-4 h-4 rounded"/> Aktiv</label>
      <div class="flex gap-2 pt-2">
        <button type="button" onclick="document.getElementById('windowModal').classList.add('hidden')" class="flex-1 px-4 py-2 border rounded-lg text-sm">Abbrechen</button>
        <button type="submit" class="flex-1 px-4 py-2 bg-brand text-white rounded-lg text-sm font-semibold">Speichern</button>
      </div>
    </form>
  </div>
</div>
<script>
function editWindow(w) {
  const f = document.getElementById('windowForm');
  f.pw_id.value = w.pw_id;
  f.priority_label.value = w.priority_label;
  f.start_time.value = (w.start_time || '').slice(0, 5);
  f.end_time.value = (w.end_time || '').slice(0, 5);
  const prio = JSON.parse(w.priority_customer_types || '[]');
  const shift = JSON.parse(w.shiftable_customer_types || '[]');
  f.querySelectorAll('[data-field=prio]').forEach(cb => { cb.checked = prio.includes(cb.value); });
  f.querySelectorAll('[data-field=shift]').forEach(cb => { cb.checked = shift.includes(cb.value); });
  f.max_shift_minutes.value = w.max_shift_minutes;
  f.allow_next_day.checked = !!parseInt(w.allow_next_day);
  f.fallback_mode.value = w.fallback_mode;
  f.notes.value = w.notes || '';
  f.active.checked = !!parseInt(w.active);
  document.getElementById('formTitle').textContent = 'Fenster #' + w.pw_id + ' bearbeiten';
  document.getElementById('windowModal').classList.remove('hidden');
}
// "+ Neu" button reset
document.querySelector('[onclick*="windowForm"]')?.addEventListener('click', () => {
  document.querySelectorAll('#windowForm [data-field]').forEach(cb => cb.checked = false);
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
