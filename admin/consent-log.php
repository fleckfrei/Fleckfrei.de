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

// Drill-down: wenn auf eine Stat-Card geklickt wurde, zeig die konkrete Kunden-Liste
$drillChannel = $_GET['channel'] ?? ''; // email | wa | phone
$drillStatus  = $_GET['status'] ?? 'ok'; // ok | no
$drillList = [];
if (in_array($drillChannel, ['email','wa','phone'], true)) {
    $col = ['email'=>'consent_email','wa'=>'consent_whatsapp','phone'=>'consent_phone'][$drillChannel];
    $val = $drillStatus === 'no' ? 0 : 1;
    $drillList = all("SELECT customer_id, name, email, phone, customer_type FROM customer
                      WHERE status=1 AND $col=? ORDER BY name ASC LIMIT 500", [$val]);
}

include __DIR__ . '/../includes/layout.php';
?>

<div class="mb-6">
  <h1 class="text-2xl font-bold text-gray-900">DSGVO Consent-Übersicht</h1>
  <p class="text-sm text-gray-600 mt-1">Marketing-Einwilligungen nach DSGVO. Kunde klickt → gespeichert → System respektiert es automatisch.</p>
</div>

<!-- Stats-Cards (klickbar für Drill-down) -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
  <?php
  $cards = [
    ['channel'=>'email','icon'=>'📧','label'=>'E-Mail-Marketing','ok'=>'email_ok','no'=>'email_no','what'=>'Email-Adresse'],
    ['channel'=>'wa',   'icon'=>'💬','label'=>'WhatsApp',        'ok'=>'wa_ok',   'no'=>'wa_no',   'what'=>'Telefon-Nummer'],
    ['channel'=>'phone','icon'=>'📞','label'=>'Telefon/SMS',     'ok'=>'phone_ok','no'=>'phone_no','what'=>'Telefon-Nummer'],
  ];
  foreach ($cards as $c):
    $okN = (int)$stats[$c['ok']]; $noN = (int)$stats[$c['no']];
    $pct = $stats['total_customers'] > 0 ? round($okN / $stats['total_customers'] * 100) : 0;
    $isActive = $drillChannel === $c['channel'];
  ?>
  <div class="bg-white rounded-xl border <?= $isActive ? 'border-brand ring-2 ring-brand/30' : '' ?> p-5">
    <div class="flex items-center gap-2 mb-2">
      <span class="text-lg"><?= $c['icon'] ?></span>
      <h3 class="font-bold text-gray-900"><?= $c['label'] ?></h3>
      <span class="ml-auto text-[10px] text-gray-400">→ <?= $c['what'] ?></span>
    </div>
    <div class="text-3xl font-bold text-brand-dark"><?= $okN ?></div>
    <div class="text-xs text-gray-600 mt-1">
      von <?= (int)$stats['total_customers'] ?> Kunden erlaubt ·
      <strong class="text-red-700"><?= $noN ?></strong> gesperrt
    </div>
    <div class="mt-3 w-full bg-gray-200 rounded-full h-2">
      <div class="h-2 bg-brand rounded-full" style="width: <?= $pct ?>%"></div>
    </div>
    <div class="flex gap-1 mt-3 text-[11px]">
      <a href="?channel=<?= $c['channel'] ?>&status=ok" class="flex-1 px-2 py-1 text-center rounded <?= $isActive && $drillStatus==='ok' ? 'bg-emerald-600 text-white' : 'bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100' ?>">✓ Zeige <?= $okN ?> erlaubt</a>
      <a href="?channel=<?= $c['channel'] ?>&status=no" class="flex-1 px-2 py-1 text-center rounded <?= $isActive && $drillStatus==='no' ? 'bg-rose-600 text-white' : 'bg-rose-50 text-rose-700 border border-rose-200 hover:bg-rose-100' ?>">✕ Zeige <?= $noN ?> gesperrt</a>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php if ($drillChannel && $drillList): ?>
<!-- Drill-down Liste: konkrete Kunden -->
<?php
  $_chLabels = ['email'=>'E-Mail-Marketing','wa'=>'WhatsApp','phone'=>'Telefon/SMS'];
  $_stLabel  = $drillStatus === 'ok' ? '✓ ERLAUBT' : '✕ GESPERRT';
  $_stColor  = $drillStatus === 'ok' ? 'emerald' : 'rose';
?>
<div class="bg-white rounded-xl border mb-6">
  <div class="px-5 py-3 border-b flex items-center justify-between">
    <div>
      <h2 class="font-bold text-gray-900">📋 <?= count($drillList) ?> Kunden — <?= e($_chLabels[$drillChannel]) ?> <span class="text-<?= $_stColor ?>-700"><?= $_stLabel ?></span></h2>
      <p class="text-xs text-gray-500 mt-0.5">Hier sind die konkreten Kontaktdaten die du dafür nutzen darfst (bzw. NICHT nutzen darfst).</p>
    </div>
    <a href="/admin/consent-log.php" class="text-xs text-gray-500 hover:text-brand">✕ Filter zurücksetzen</a>
  </div>
  <table class="w-full text-sm">
    <thead class="bg-gray-50 border-b">
      <tr>
        <th class="text-left px-4 py-2 font-medium">Kunde</th>
        <th class="text-left px-4 py-2 font-medium">Typ</th>
        <th class="text-left px-4 py-2 font-medium">📧 Email</th>
        <th class="text-left px-4 py-2 font-medium">📞 Telefon</th>
        <th class="text-right px-4 py-2 font-medium">Profil</th>
      </tr>
    </thead>
    <tbody class="divide-y">
      <?php foreach ($drillList as $c): ?>
      <tr class="hover:bg-gray-50">
        <td class="px-4 py-2 font-semibold"><?= e($c['name']) ?></td>
        <td class="px-4 py-2 text-xs text-gray-600"><?= e($c['customer_type'] ?? '—') ?></td>
        <td class="px-4 py-2 text-xs">
          <?php if ($c['email']): ?>
            <a href="mailto:<?= e($c['email']) ?>" class="text-brand hover:underline <?= $drillChannel==='email' && $drillStatus==='no' ? 'line-through text-gray-400 no-underline' : '' ?>"><?= e($c['email']) ?></a>
          <?php else: ?>
            <span class="text-gray-400">—</span>
          <?php endif; ?>
        </td>
        <td class="px-4 py-2 text-xs">
          <?php if ($c['phone']):
            $ph = preg_replace('/[^+0-9]/','',$c['phone']);
            $blocked = in_array($drillChannel,['wa','phone'],true) && $drillStatus==='no';
          ?>
            <span class="<?= $blocked ? 'line-through text-gray-400' : '' ?>">
              <a href="tel:<?= e($ph) ?>" class="text-brand hover:underline"><?= e($c['phone']) ?></a>
              <?php if ($drillChannel==='wa' && !$blocked): ?>
                <a href="https://wa.me/<?= ltrim($ph,'+') ?>" target="_blank" class="ml-1 text-emerald-600">💬</a>
              <?php endif; ?>
            </span>
          <?php else: ?>
            <span class="text-gray-400">—</span>
          <?php endif; ?>
        </td>
        <td class="px-4 py-2 text-right">
          <a href="/admin/view-customer.php?id=<?= (int)$c['customer_id'] ?>" class="text-xs text-brand hover:underline">→ Öffnen</a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php elseif ($drillChannel): ?>
<div class="bg-amber-50 border border-amber-200 text-amber-900 px-4 py-3 rounded-xl mb-6">
  Keine Kunden in dieser Kategorie.
</div>
<?php endif; ?>

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
