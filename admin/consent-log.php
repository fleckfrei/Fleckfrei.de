<?php
/**
 * Admin: DSGVO-Consent-Übersicht + Audit-Trail
 */
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'DSGVO Consent'; $page = 'consent-log';

// Stats
$stats = one("SELECT
    COUNT(*) AS total_customers,
    SUM(consent_email=1) AS email_ok,
    SUM(consent_whatsapp=1) AS wa_ok,
    SUM(consent_phone=1) AS phone_ok,
    SUM(consent_email=0) AS email_no,
    SUM(consent_whatsapp=0) AS wa_no,
    SUM(consent_phone=0) AS phone_no
    FROM customer WHERE status=1");

$historyAvailable = false;
$recent = [];
try {
    $recent = all("SELECT ch.*, ch.new_value AS granted, ch.ip AS ip_address, ch.changed_at AS created_at, c.name as cname
                   FROM consent_history ch
                   LEFT JOIN customer c ON ch.customer_id_fk=c.customer_id
                   ORDER BY ch.changed_at DESC LIMIT 50");
    $historyAvailable = true;
} catch (Exception $e) {}

include __DIR__ . '/../includes/layout.php';
?>

<div class="mb-6">
  <h1 class="text-2xl font-bold text-gray-900">DSGVO Consent-Übersicht</h1>
  <p class="text-sm text-gray-600 mt-1">Marketing-Einwilligungen nach DSGVO. Kunde klickt → gespeichert → System respektiert es automatisch.</p>
</div>

<!-- Stats-Cards -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
  <div class="bg-white rounded-xl border p-5">
    <div class="flex items-center gap-2 mb-2">
      <span class="text-lg">📧</span>
      <h3 class="font-bold text-gray-900">E-Mail-Marketing</h3>
    </div>
    <div class="text-3xl font-bold text-brand-dark"><?= (int)$stats['email_ok'] ?></div>
    <div class="text-xs text-gray-600 mt-1">
      von <?= (int)$stats['total_customers'] ?> Kunden erlaubt ·
      <strong class="text-red-700"><?= (int)$stats['email_no'] ?></strong> gesperrt
    </div>
    <div class="mt-3 w-full bg-gray-200 rounded-full h-2">
      <div class="h-2 bg-brand rounded-full" style="width: <?= $stats['total_customers'] > 0 ? round($stats['email_ok'] / $stats['total_customers'] * 100) : 0 ?>%"></div>
    </div>
  </div>

  <div class="bg-white rounded-xl border p-5">
    <div class="flex items-center gap-2 mb-2">
      <span class="text-lg">💬</span>
      <h3 class="font-bold text-gray-900">WhatsApp</h3>
    </div>
    <div class="text-3xl font-bold text-brand-dark"><?= (int)$stats['wa_ok'] ?></div>
    <div class="text-xs text-gray-600 mt-1">
      von <?= (int)$stats['total_customers'] ?> Kunden erlaubt ·
      <strong class="text-red-700"><?= (int)$stats['wa_no'] ?></strong> gesperrt
    </div>
    <div class="mt-3 w-full bg-gray-200 rounded-full h-2">
      <div class="h-2 bg-brand rounded-full" style="width: <?= $stats['total_customers'] > 0 ? round($stats['wa_ok'] / $stats['total_customers'] * 100) : 0 ?>%"></div>
    </div>
  </div>

  <div class="bg-white rounded-xl border p-5">
    <div class="flex items-center gap-2 mb-2">
      <span class="text-lg">📞</span>
      <h3 class="font-bold text-gray-900">Telefon/SMS</h3>
    </div>
    <div class="text-3xl font-bold text-brand-dark"><?= (int)$stats['phone_ok'] ?></div>
    <div class="text-xs text-gray-600 mt-1">
      von <?= (int)$stats['total_customers'] ?> Kunden erlaubt ·
      <strong class="text-red-700"><?= (int)$stats['phone_no'] ?></strong> gesperrt
    </div>
    <div class="mt-3 w-full bg-gray-200 rounded-full h-2">
      <div class="h-2 bg-brand rounded-full" style="width: <?= $stats['total_customers'] > 0 ? round($stats['phone_ok'] / $stats['total_customers'] * 100) : 0 ?>%"></div>
    </div>
  </div>
</div>

<!-- History-Timeline -->
<div class="bg-white rounded-xl border overflow-hidden">
  <div class="p-5 border-b">
    <h2 class="font-bold text-gray-900">📜 Audit-Trail (DSGVO-Nachweis)</h2>
    <p class="text-xs text-gray-600 mt-0.5">Jede Änderung mit Zeitstempel, IP, Quelle. Für Behörden-Anfragen archiviert.</p>
  </div>
  <?php if (!$historyAvailable || empty($recent)): ?>
  <div class="p-10 text-center text-sm text-gray-500">
    Noch keine Änderungen protokolliert. Logs entstehen sobald ein Kunde eine Einwilligung ändert.
  </div>
  <?php else: ?>
  <table class="w-full text-sm">
    <thead class="bg-gray-50 border-b">
      <tr>
        <th class="text-left px-4 py-3 font-medium">Zeit</th>
        <th class="text-left px-4 py-3 font-medium">Kunde</th>
        <th class="text-left px-4 py-3 font-medium">Channel</th>
        <th class="text-left px-4 py-3 font-medium">Status</th>
        <th class="text-left px-4 py-3 font-medium">Quelle</th>
        <th class="text-left px-4 py-3 font-medium">IP</th>
      </tr>
    </thead>
    <tbody class="divide-y">
      <?php foreach ($recent as $r): ?>
      <tr class="hover:bg-gray-50">
        <td class="px-4 py-3 text-xs"><?= date('d.m.Y H:i', strtotime($r['created_at'])) ?></td>
        <td class="px-4 py-3">
          <span class="font-medium"><?= e($r['cname'] ?? 'Unbekannt') ?></span>
          <span class="text-[10px] text-gray-500">#<?= (int)$r['customer_id_fk'] ?></span>
        </td>
        <td class="px-4 py-3">
          <span class="px-2 py-0.5 bg-gray-100 rounded text-xs font-medium">
            <?php echo match($r['channel']) { 'email'=>'📧 E-Mail', 'whatsapp'=>'💬 WhatsApp', 'phone'=>'📞 Phone', 'sms'=>'📱 SMS', default=>$r['channel'] }; ?>
          </span>
        </td>
        <td class="px-4 py-3">
          <?php if ($r['granted']): ?>
            <span class="px-2 py-0.5 bg-green-100 text-green-700 rounded-full text-xs font-bold">✓ ERLAUBT</span>
          <?php else: ?>
            <span class="px-2 py-0.5 bg-red-100 text-red-700 rounded-full text-xs font-bold">✕ WIDERRUFEN</span>
          <?php endif; ?>
        </td>
        <td class="px-4 py-3 text-xs text-gray-600"><?= e($r['source']) ?></td>
        <td class="px-4 py-3 text-xs font-mono text-gray-500"><?= e($r['ip_address'] ?? '—') ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<!-- Wie Guards funktionieren -->
<div class="mt-6 bg-brand-light border border-brand/30 rounded-xl p-5">
  <h3 class="font-bold text-brand-dark mb-2">🛡 Wie wird Consent durchgesetzt?</h3>
  <ul class="text-sm text-gray-800 space-y-1.5">
    <li>✅ <strong>Kunde klickt ab</strong> → DB updated sofort + OSINT-Graph aktualisiert</li>
    <li>✅ <strong>Marketing-Funktion</strong> ruft <code class="text-xs bg-white px-1 rounded">canContact($cid, 'email')</code> → returnt <code>false</code> wenn nicht erlaubt</li>
    <li>✅ <strong>Fail-safe</strong>: Bei DB-Fehler wird im Zweifel NICHT gesendet (DSGVO-sicher)</li>
    <li>✅ <strong>Audit-Log</strong> speichert geblockte Sendungen (<code class="text-xs bg-white px-1 rounded">blocked_marketing</code>)</li>
    <li>✅ <strong>History-Tabelle</strong> archiviert jede Consent-Änderung mit IP + User-Agent</li>
    <li>✅ <strong>Widerruf</strong> unter <code class="text-xs bg-white px-1 rounded">/customer/profile.php</code> oder beim nächsten Booking</li>
  </ul>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
