<?php
require_once __DIR__ . '/../includes/auth.php';
requireCustomer();
if (!customerCan('documents')) { header('Location: /customer/'); exit; }
$title = 'Dokumente & Schlüssel'; $page = 'documents';
$cid = me()['id'];
$tab = in_array($_GET['tab'] ?? '', ['schluessel', 'dokumente', 'vertraege'], true) ? $_GET['tab'] : 'schluessel';

$customer = one("SELECT * FROM customer WHERE customer_id=?", [$cid]);

// Keys for this customer
try {
    $keys = all("SELECT * FROM key_inventory WHERE customer_id_fk=? ORDER BY key_id DESC", [$cid]);
    $keyHistory = all("SELECT h.*, k.label AS key_label FROM key_handovers h JOIN key_inventory k ON h.key_id_fk=k.key_id WHERE k.customer_id_fk=? ORDER BY h.happened_at DESC LIMIT 50", [$cid]);
} catch (Exception $e) { $keys = []; $keyHistory = []; }

function keyStatusBadge(string $s): array {
    return match($s) {
        'with_customer' => ['Bei Ihnen', 'bg-blue-100 text-blue-700'],
        'with_office'   => ['Im Fleckfrei-Büro', 'bg-green-100 text-green-700'],
        'with_partner'  => ['Beim Partner', 'bg-amber-100 text-amber-700'],
        'lost'          => ['Verloren ⚠', 'bg-red-100 text-red-700'],
        'returned'      => ['Zurückgegeben', 'bg-gray-100 text-gray-600'],
        default         => [$s, 'bg-gray-100 text-gray-600'],
    };
}

include __DIR__ . '/../includes/layout-customer.php';
?>

<!-- Back button -->
<div class="mb-4">
  <a href="/customer/" class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-brand transition">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
    Zurück
  </a>
</div>

<!-- Header -->
<div class="mb-6">
  <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Dokumente & Schlüssel</h1>
  <p class="text-gray-500 mt-1 text-sm">Hochgeladene Dokumente und Schlüsselverwaltung. Für Rechnungen gehen Sie bitte zu <a href="/customer/invoices.php" class="text-brand hover:underline">Rechnungen</a>.</p>
</div>

<!-- Tab navigation -->
<div class="border-b mb-6">
  <div class="flex gap-8">
    <a href="?tab=schluessel" class="tab-underline <?= $tab === 'schluessel' ? 'active' : '' ?>">
      🔑 Schlüssel <span class="text-gray-400 text-xs">(<?= count($keys) ?>)</span>
    </a>
    <a href="?tab=files" class="tab-underline <?= $tab === 'files' ? 'active' : '' ?>">
      📁 Dokumente <span class="text-gray-400 text-xs">(0)</span>
    </a>
  </div>
</div>

<?php if ($tab === 'files'): ?>
<!-- ============ FILES TAB (Placeholder — no uploads yet) ============ -->
<div class="card-elev text-center py-20 px-4">
  <div class="w-20 h-20 mx-auto rounded-full bg-gray-100 flex items-center justify-center mb-4">
    <svg class="w-10 h-10 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
  </div>
  <h3 class="text-lg font-semibold text-gray-900 mb-2">Keine Dokumente hochgeladen</h3>
  <p class="text-sm text-gray-500 max-w-md mx-auto mb-4">Hier erscheinen alle Dokumente die Sie oder Fleckfrei für Sie hochladen — z.B. Verträge, Mietnachweise, Zugangspläne oder Versicherungsdokumente.</p>
  <a href="<?= CONTACT_WHATSAPP_URL ?>" target="_blank" class="inline-flex items-center gap-2 px-5 py-2.5 border border-gray-200 hover:border-brand rounded-xl text-sm font-semibold text-gray-700 hover:text-brand transition">
    Dokument per WhatsApp senden
  </a>
</div>

<?php elseif ($tab === 'schluessel'): ?>
<!-- ============ KEYS TAB ============ -->
<?php
  $stats = ['office'=>0, 'partner'=>0, 'customer'=>0, 'lost'=>0];
  foreach ($keys as $k) {
      if ($k['status'] === 'with_office') $stats['office']++;
      elseif ($k['status'] === 'with_partner') $stats['partner']++;
      elseif ($k['status'] === 'with_customer') $stats['customer']++;
      elseif ($k['status'] === 'lost') $stats['lost']++;
  }
?>
<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
  <div class="card-elev p-4">
    <div class="text-2xl font-bold text-gray-900"><?= count($keys) ?></div>
    <div class="text-xs text-gray-500 uppercase font-semibold tracking-wider mt-1">Schlüssel gesamt</div>
  </div>
  <div class="card-elev p-4">
    <div class="text-2xl font-bold text-green-600"><?= $stats['office'] ?></div>
    <div class="text-xs text-gray-500 uppercase font-semibold tracking-wider mt-1">Im Fleckfrei-Büro</div>
  </div>
  <div class="card-elev p-4">
    <div class="text-2xl font-bold text-amber-600"><?= $stats['partner'] ?></div>
    <div class="text-xs text-gray-500 uppercase font-semibold tracking-wider mt-1">Beim Partner</div>
  </div>
  <div class="card-elev p-4 <?= $stats['lost'] > 0 ? 'border-red-200' : '' ?>">
    <div class="text-2xl font-bold <?= $stats['lost'] > 0 ? 'text-red-600' : 'text-gray-900' ?>"><?= $stats['lost'] ?></div>
    <div class="text-xs text-gray-500 uppercase font-semibold tracking-wider mt-1">Verloren</div>
  </div>
</div>

<!-- DSGVO Notice -->
<div class="card-elev p-4 mb-6 bg-blue-50 border-blue-200 flex items-start gap-3">
  <svg class="w-5 h-5 text-blue-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
  <div class="text-xs text-blue-900">
    <strong>DSGVO Hinweis:</strong> Alle Schlüsselübergaben werden lückenlos protokolliert nach §28 BDSG.
    Sie haben jederzeit Recht auf Auskunft, Berichtigung und Löschung Ihrer Daten gemäß Art. 15-17 DSGVO.
  </div>
</div>

<!-- Keys list -->
<div class="card-elev overflow-hidden mb-6">
  <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
    <h3 class="font-semibold text-gray-900">Ihre Schlüssel</h3>
    <a href="<?= CONTACT_WHATSAPP_URL ?>" target="_blank" class="text-xs text-brand hover:underline">+ Schlüssel übergeben</a>
  </div>

  <?php if (empty($keys)): ?>
    <div class="px-5 py-16 text-center">
      <div class="w-16 h-16 mx-auto rounded-full bg-gray-100 flex items-center justify-center mb-4">
        <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
      </div>
      <div class="text-gray-500 font-medium">Noch keine Schlüssel hinterlegt</div>
      <div class="text-sm text-gray-400 mt-1">Wenn Sie uns einen Schlüssel übergeben, erscheint er hier mit komplettem Übergabe-Verlauf.</div>
    </div>
  <?php else: ?>
    <div class="divide-y divide-gray-100">
      <?php foreach ($keys as $k):
        [$statusLabel, $statusClass] = keyStatusBadge($k['status']);
      ?>
      <div class="px-5 py-4 hover:bg-gray-50 transition">
        <div class="flex items-start justify-between gap-4">
          <div class="flex items-start gap-4 min-w-0 flex-1">
            <div class="w-11 h-11 rounded-xl bg-amber-50 flex items-center justify-center flex-shrink-0 text-2xl">🔑</div>
            <div class="min-w-0 flex-1">
              <div class="font-semibold text-gray-900"><?= e($k['label']) ?></div>
              <?php if ($k['description']): ?>
              <div class="text-xs text-gray-500 mt-0.5"><?= e($k['description']) ?></div>
              <?php endif; ?>
              <?php if ($k['property_address']): ?>
              <div class="text-xs text-gray-400 mt-1 flex items-center gap-1">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/></svg>
                <?= e($k['property_address']) ?>
              </div>
              <?php endif; ?>
              <div class="text-[10px] text-gray-400 mt-1">Übergeben am <?= date('d.m.Y H:i', strtotime($k['created_at'])) ?> Uhr</div>
            </div>
          </div>
          <span class="px-2.5 py-1 rounded-full text-[10px] font-semibold whitespace-nowrap <?= $statusClass ?>"><?= $statusLabel ?></span>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- Handover history -->
<?php if (!empty($keyHistory)): ?>
<div class="card-elev overflow-hidden">
  <div class="px-5 py-4 border-b border-gray-100">
    <h3 class="font-semibold text-gray-900">Übergabe-Verlauf</h3>
    <p class="text-xs text-gray-500 mt-0.5">Alle dokumentierten Schlüssel-Bewegungen</p>
  </div>
  <div class="divide-y divide-gray-100">
    <?php foreach ($keyHistory as $h):
      $actionLabels = [
          'received' => ['→ Erhalten', 'bg-green-100 text-green-700', '📥'],
          'given'    => ['→ Weitergegeben', 'bg-amber-100 text-amber-700', '📤'],
          'returned' => ['→ Zurückgegeben', 'bg-blue-100 text-blue-700', '↩️'],
          'lost'     => ['⚠ Verloren', 'bg-red-100 text-red-700', '❌'],
          'found'    => ['✓ Wiedergefunden', 'bg-green-100 text-green-700', '✅'],
      ];
      [$actLabel, $actClass, $actIcon] = $actionLabels[$h['action']] ?? [$h['action'], 'bg-gray-100', '•'];
    ?>
    <div class="px-5 py-3 flex items-start gap-3 hover:bg-gray-50">
      <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 text-lg"><?= $actIcon ?></div>
      <div class="flex-1 min-w-0">
        <div class="flex items-center gap-2 flex-wrap">
          <span class="font-medium text-gray-900 text-sm"><?= e($h['key_label']) ?></span>
          <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold <?= $actClass ?>"><?= $actLabel ?></span>
        </div>
        <div class="text-xs text-gray-500 mt-0.5">
          Von <strong><?= e($h['from_name']) ?></strong> →
          <strong><?= e($h['to_name']) ?></strong>
        </div>
        <?php if ($h['notes']): ?>
        <div class="text-xs text-gray-400 mt-1 italic">"<?= e($h['notes']) ?>"</div>
        <?php endif; ?>
        <div class="text-[10px] text-gray-400 mt-1"><?= date('d.m.Y H:i', strtotime($h['happened_at'])) ?> Uhr</div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer-customer.php'; ?>
