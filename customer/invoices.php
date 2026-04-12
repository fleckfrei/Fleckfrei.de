<?php
require_once __DIR__ . '/../includes/auth.php';
requireCustomer();
if (!customerCan('invoices')) { header('Location: /customer/'); exit; }
$title = 'Rechnungen'; $page = 'invoices';
$cid = me()['id'];

// POST: submit a note/dispute on an invoice
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_note') {
    if (!verifyCsrf()) { header('Location: /customer/invoices.php?error=csrf'); exit; }
    $invId = (int)($_POST['inv_id'] ?? 0);
    $noteType = in_array($_POST['note_type'] ?? '', ['comment','dispute','correction'], true) ? $_POST['note_type'] : 'comment';
    $content = trim($_POST['content'] ?? '');
    // Verify ownership
    $owned = (int) val("SELECT inv_id FROM invoices WHERE inv_id=? AND customer_id_fk=?", [$invId, $cid]);
    if ($owned && $content !== '') {
        q("INSERT INTO invoice_notes (inv_id_fk, customer_id_fk, note_type, content, status) VALUES (?, ?, ?, ?, 'open')",
          [$invId, $cid, $noteType, $content]);
        audit('create', 'invoice_note', $invId, "Kunde hat $noteType zur Rechnung hinzugefügt");
        $emoji = $noteType === 'dispute' ? '⚠' : ($noteType === 'correction' ? '✏️' : '💬');
        telegramNotify("$emoji Kunde #$cid hat Einwand/Notiz auf Rechnung #$invId: " . substr($content, 0, 100));
    }
    header('Location: /customer/invoices.php?saved=note'); exit;
}

$tab = $_GET['tab'] ?? 'service';
$allInvoices = all("SELECT * FROM invoices WHERE customer_id_fk=? ORDER BY issue_date DESC", [$cid]);
$invoices = $allInvoices;

// Load existing notes per invoice
$invoiceIds = array_column($invoices, 'inv_id');
$notesByInv = [];
if (!empty($invoiceIds)) {
    $ph = implode(',', array_fill(0, count($invoiceIds), '?'));
    try {
        $notesRows = all("SELECT * FROM invoice_notes WHERE inv_id_fk IN ($ph) ORDER BY created_at DESC", $invoiceIds);
        foreach ($notesRows as $n) $notesByInv[$n['inv_id_fk']][] = $n;
    } catch (Exception $e) {}
}

$totalUnpaid = (float) val("SELECT COALESCE(SUM(remaining_price),0) FROM invoices WHERE customer_id_fk=? AND invoice_paid='no'", [$cid]);
$unpaidCount = (int) val("SELECT COUNT(*) FROM invoices WHERE customer_id_fk=? AND invoice_paid='no'", [$cid]);
$savedMsg = $_GET['saved'] ?? '';

include __DIR__ . '/../includes/layout-customer.php';
?>

<!-- Back button -->
<div class="mb-4">
  <a href="/customer/" class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-brand transition">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
    Zurück
  </a>
</div>

<div class="mb-6">
  <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Rechnungen</h1>
  <p class="text-gray-500 mt-1 text-sm">Alle Belege und Zahlungen im Überblick.</p>
</div>

<?php if ($savedMsg === 'note'): ?>
<div class="card-elev bg-green-50 border-green-200 p-4 mb-4 text-sm text-green-800 flex items-center gap-2">
  ✓ Ihre Notiz wurde eingereicht — wir prüfen das umgehend und melden uns bei Ihnen.
</div>
<?php endif; ?>

<?php if ($totalUnpaid > 0):
    // Find oldest unpaid invoice to pay first
    $oldestUnpaid = null;
    foreach ($invoices as $inv) {
        if ($inv['invoice_paid'] !== 'yes') { $oldestUnpaid = $inv; break; }
    }
?>
<div class="card-elev border-red-200 bg-red-50 p-5 mb-6" x-data="{ showBank: false }">
  <div class="flex items-start justify-between gap-4 flex-wrap mb-4">
    <div class="flex items-center gap-3">
      <div class="w-12 h-12 rounded-full bg-red-100 flex items-center justify-center flex-shrink-0">
        <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      </div>
      <div>
        <div class="font-bold text-red-900 text-base"><?= $unpaidCount ?> offene Rechnung<?= $unpaidCount === 1 ? '' : 'en' ?></div>
        <div class="text-sm text-red-700 mt-0.5">Gesamtbetrag: <strong class="text-lg"><?= money($totalUnpaid) ?></strong></div>
      </div>
    </div>
  </div>

  <!-- 4 Zahlungsoptionen -->
  <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
    <?php if ($oldestUnpaid && defined('FEATURE_STRIPE') && FEATURE_STRIPE): ?>
    <button onclick="stripePayInv(<?= (int)$oldestUnpaid['inv_id'] ?>, event)" class="px-4 py-3 bg-white hover:bg-brand/5 border border-gray-200 hover:border-brand rounded-xl text-sm font-semibold text-gray-800 hover:text-brand flex flex-col items-center gap-1 transition">
      <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
      <span class="text-xs">Karte / SEPA</span>
    </button>
    <?php endif; ?>

    <?php if ($oldestUnpaid && defined('FEATURE_PAYPAL') && FEATURE_PAYPAL): ?>
    <button onclick="document.querySelector('paypal-button[data-inv=\'<?= (int)$oldestUnpaid['inv_id'] ?>\']')?.click()" class="px-4 py-3 bg-white hover:bg-blue-50 border border-gray-200 hover:border-blue-500 rounded-xl text-sm font-semibold text-gray-800 hover:text-blue-600 flex flex-col items-center gap-1 transition">
      <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24"><path d="M20.067 8.478c.492.315.844.825.983 1.401.304 1.244.115 2.528-.561 3.621-.832 1.345-2.323 2.147-4.194 2.257-.19.012-.38.017-.57.017h-.398l-.285 1.802c-.046.294-.3.51-.597.51h-2.04c-.237 0-.447-.164-.5-.395l-.014-.075.284-1.802H10.96l-.285 1.802c-.046.294-.3.51-.597.51H8.04c-.297 0-.533-.269-.496-.563l2.05-12.988c.047-.297.302-.515.6-.515h4.844c.842 0 1.655.149 2.402.439.748.289 1.375.702 1.853 1.226.366.4.62.865.758 1.377.059.22.101.444.125.672l.008-.295z"/></svg>
      <span class="text-xs">PayPal</span>
    </button>
    <?php endif; ?>

    <button @click="showBank = !showBank" class="px-4 py-3 bg-white hover:bg-amber-50 border border-gray-200 hover:border-amber-500 rounded-xl text-sm font-semibold text-gray-800 hover:text-amber-600 flex flex-col items-center gap-1 transition">
      <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 10V6a2 2 0 012-2h12a2 2 0 012 2v4M4 10l8 4 8-4M4 10v8a2 2 0 002 2h12a2 2 0 002-2v-8"/></svg>
      <span class="text-xs">Überweisung</span>
    </button>

    <a href="<?= CONTACT_WHATSAPP_URL ?>" target="_blank" class="px-4 py-3 bg-green-500 hover:bg-green-600 text-white rounded-xl text-sm font-semibold flex flex-col items-center gap-1 transition shadow-lg shadow-green-500/20">
      <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898"/></svg>
      <span class="text-xs">WhatsApp</span>
    </a>
  </div>

  <!-- Bank transfer details (expandable) -->
  <div x-show="showBank" x-cloak x-transition class="mt-4 pt-4 border-t border-red-200 bg-white rounded-xl p-4">
    <h4 class="font-bold text-gray-900 mb-2 flex items-center gap-2">
      <svg class="w-4 h-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 10V6a2 2 0 012-2h12a2 2 0 012 2v4M4 10l8 4 8-4M4 10v8a2 2 0 002 2h12a2 2 0 002-2v-8"/></svg>
      Überweisungsdetails
    </h4>
    <?php
    // Load bank details from settings
    $settings = null;
    try { $settings = one("SELECT iban, bic, bank, company FROM settings LIMIT 1"); } catch (Exception $e) {}
    ?>
    <?php if ($settings && !empty($settings['iban'])): ?>
    <div class="space-y-2 text-sm">
      <div class="flex items-center justify-between gap-3 py-2 border-b border-gray-100">
        <span class="text-gray-500 text-xs font-semibold uppercase tracking-wider">Empfänger</span>
        <span class="font-medium text-gray-900"><?= e($settings['company'] ?? SITE) ?></span>
      </div>
      <div class="flex items-center justify-between gap-3 py-2 border-b border-gray-100">
        <span class="text-gray-500 text-xs font-semibold uppercase tracking-wider">IBAN</span>
        <span class="font-mono text-gray-900 text-sm select-all"><?= e($settings['iban']) ?></span>
      </div>
      <?php if (!empty($settings['bic'])): ?>
      <div class="flex items-center justify-between gap-3 py-2 border-b border-gray-100">
        <span class="text-gray-500 text-xs font-semibold uppercase tracking-wider">BIC</span>
        <span class="font-mono text-gray-900 text-sm select-all"><?= e($settings['bic']) ?></span>
      </div>
      <?php endif; ?>
      <?php if (!empty($settings['bank'])): ?>
      <div class="flex items-center justify-between gap-3 py-2 border-b border-gray-100">
        <span class="text-gray-500 text-xs font-semibold uppercase tracking-wider">Bank</span>
        <span class="text-gray-900"><?= e($settings['bank']) ?></span>
      </div>
      <?php endif; ?>
      <div class="flex items-center justify-between gap-3 py-2 border-b border-gray-100">
        <span class="text-gray-500 text-xs font-semibold uppercase tracking-wider">Betrag</span>
        <span class="font-bold text-gray-900"><?= money($totalUnpaid) ?></span>
      </div>
      <?php if ($oldestUnpaid): ?>
      <div class="flex items-center justify-between gap-3 py-2">
        <span class="text-gray-500 text-xs font-semibold uppercase tracking-wider">Verwendungszweck</span>
        <span class="font-mono text-gray-900 text-sm select-all"><?= e($oldestUnpaid['invoice_number']) ?></span>
      </div>
      <?php endif; ?>
    </div>
    <p class="text-[11px] text-gray-500 mt-3 italic">💡 Tipp: Klicken Sie auf eine Zeile um den Wert zu markieren. Überweisungen werden nach 1-2 Werktagen automatisch zugeordnet.</p>
    <?php else: ?>
    <p class="text-xs text-gray-500">Bankdaten werden gerade nachgeladen. Bitte kontaktieren Sie uns per WhatsApp für die Überweisungsdetails.</p>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- Tabs -->
<div class="border-b mb-6">
  <div class="flex gap-8">
    <a href="?tab=service" class="tab-underline <?= $tab === 'service' ? 'active' : '' ?>">Dienstleistung</a>
    <a href="?tab=other" class="tab-underline <?= $tab === 'other' ? 'active' : '' ?>">Andere</a>
  </div>
</div>

<?php if ($tab === 'service'):
    if (empty($invoices)): ?>
<div class="card-elev text-center py-16 px-4">
  <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-brand-light mb-5">
    <svg class="w-10 h-10 text-brand" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
  </div>
  <h3 class="text-lg font-semibold text-gray-900 mb-2">Keine Rechnungen</h3>
  <p class="text-sm text-gray-500 max-w-md mx-auto">Nach Ihrem ersten Termin werden Ihnen hier Ihre Rechnungen angezeigt.</p>
</div>
<?php else: ?>
<div class="grid gap-3" x-data="{ noteModal: null, noteModalData: null }">
<?php foreach ($invoices as $inv):
    $paid = $inv['invoice_paid'] === 'yes';
    $partial = !$paid && (float) $inv['remaining_price'] < (float) $inv['total_price'];
    $netto = (float) ($inv['price'] ?? 0);
    $tax   = (float) ($inv['tax'] ?? 0);
    $brutto = (float) $inv['total_price'];
    // If netto=0 but total exists, derive: netto = total / 1.19
    if ($netto <= 0 && $brutto > 0) {
        $netto = round($brutto / 1.19, 2);
        $tax   = round($brutto - $netto, 2);
    }
    $taxPct = $netto > 0 ? round(($tax / $netto) * 100) : 19;
    $invNotes = $notesByInv[$inv['inv_id']] ?? [];
    $noteCount = count($invNotes);
    $openNotes = count(array_filter($invNotes, fn($n) => $n['status'] === 'open'));
?>
<div class="card-elev p-5">
  <!-- Top row: icon, number, status, payment buttons -->
  <div class="flex items-start justify-between gap-4 flex-wrap">
    <div class="flex items-start gap-4 min-w-0 flex-1">
      <div class="w-12 h-12 rounded-xl <?= $paid ? 'bg-green-50' : 'bg-amber-50' ?> flex items-center justify-center flex-shrink-0">
        <svg class="w-6 h-6 <?= $paid ? 'text-green-600' : 'text-amber-600' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
      </div>
      <div class="min-w-0">
        <div class="flex items-center gap-2 flex-wrap">
          <div class="font-mono font-bold text-gray-900"><?= e($inv['invoice_number']) ?></div>
          <?php if ($paid): ?>
            <span class="inline-block px-2 py-0.5 rounded-full bg-green-100 text-green-700 text-[10px] font-semibold">Bezahlt</span>
          <?php elseif ($partial): ?>
            <span class="inline-block px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 text-[10px] font-semibold">Teilzahlung</span>
          <?php else: ?>
            <span class="inline-block px-2 py-0.5 rounded-full bg-red-100 text-red-700 text-[10px] font-semibold">Offen <?= money($inv['remaining_price']) ?></span>
          <?php endif; ?>
          <?php if ($openNotes > 0): ?>
            <span class="inline-block px-2 py-0.5 rounded-full bg-blue-100 text-blue-700 text-[10px] font-semibold">💬 <?= $openNotes ?> Notiz<?= $openNotes > 1 ? 'en' : '' ?></span>
          <?php endif; ?>
        </div>
        <div class="text-xs text-gray-500 mt-0.5">
          Ausgestellt <?= date('d.m.Y', strtotime($inv['issue_date'])) ?>
          <?php if (!empty($inv['start_date']) && !empty($inv['end_date'])): ?>
            · Leistungszeitraum <?= date('d.m.', strtotime($inv['start_date'])) ?>–<?= date('d.m.Y', strtotime($inv['end_date'])) ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="flex items-center gap-2 flex-wrap justify-end">
      <a href="/admin/invoice-pdf.php?id=<?= (int) $inv['inv_id'] ?>" target="_blank" class="px-3 py-2 border border-brand/30 bg-brand/5 hover:bg-brand/10 text-brand rounded-lg text-xs font-semibold flex items-center gap-1 transition">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
        PDF
      </a>
      <?php if (!$paid && defined('FEATURE_STRIPE') && FEATURE_STRIPE): ?>
      <button onclick="stripePayInv(<?= (int) $inv['inv_id'] ?>, event)" class="px-4 py-2 bg-brand hover:bg-brand-dark text-white rounded-lg text-xs font-semibold flex items-center gap-1">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
        Karte / SEPA
      </button>
      <?php endif; ?>
      <?php if (!$paid && defined('FEATURE_PAYPAL') && FEATURE_PAYPAL): ?>
      <paypal-button hidden data-inv="<?= (int) $inv['inv_id'] ?>" class="paypal-v6-btn"></paypal-button>
      <?php endif; ?>
    </div>
  </div>

  <!-- Netto / Tax / Brutto breakdown -->
  <div class="mt-4 pt-4 border-t border-gray-100 grid grid-cols-3 gap-3">
    <div class="text-center">
      <div class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Netto</div>
      <div class="text-base font-bold text-gray-700 mt-0.5"><?= money($netto) ?></div>
    </div>
    <div class="text-center border-x border-gray-100">
      <div class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">MwSt (<?= $taxPct ?>%)</div>
      <div class="text-base font-bold text-gray-700 mt-0.5">+ <?= money($tax) ?></div>
    </div>
    <div class="text-center">
      <div class="text-[10px] font-bold text-brand uppercase tracking-wider">Brutto</div>
      <div class="text-lg font-bold text-gray-900 mt-0.5"><?= money($brutto) ?></div>
    </div>
  </div>

  <?php if ($paid): $invDonation = round($netto * 0.01, 2); ?>
  <!-- 1% vom Netto → Rumänien-Hilfe -->
  <div class="mt-3 px-3 py-2 rounded-lg bg-gradient-to-r from-amber-50 to-red-50 border border-amber-100 flex items-center justify-between gap-2 text-[11px]">
    <div class="flex items-center gap-2">
      <span class="text-base">🤝</span>
      <span class="text-gray-700">1% vom Netto (<?= money($netto) ?>) → <strong>Rumänien-Hilfe</strong></span>
    </div>
    <strong class="text-amber-700 font-mono"><?= money($invDonation) ?></strong>
  </div>
  <?php endif; ?>

  <!-- Notiz / Einwand row -->
  <div class="mt-4 pt-3 border-t border-gray-100 flex items-center justify-between gap-2 flex-wrap">
    <div class="text-[11px] text-gray-500 flex-1 min-w-0">
      <?php if ($noteCount > 0): ?>
        <div class="flex items-center gap-1.5">
          <span>💬 <?= $noteCount ?> Notiz<?= $noteCount > 1 ? 'en' : '' ?> zur Rechnung:</span>
          <?php $lastNote = $invNotes[0]; ?>
          <span class="italic text-gray-400 truncate">"<?= e(substr($lastNote['content'], 0, 60)) ?><?= strlen($lastNote['content']) > 60 ? '...' : '' ?>"</span>
        </div>
      <?php else: ?>
        <span class="text-gray-400">Stimmt etwas nicht? Schreiben Sie uns eine Notiz.</span>
      <?php endif; ?>
    </div>
    <button @click='noteModalData = { inv_id: <?= (int)$inv['inv_id'] ?>, invoice_number: <?= json_encode($inv['invoice_number']) ?> }; noteModal = true' class="px-3 py-1.5 border border-blue-200 bg-blue-50 hover:bg-blue-100 text-blue-700 rounded-lg text-xs font-semibold transition flex items-center gap-1">
      <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
      Notiz / Einwand
    </button>
  </div>

  <!-- Existing notes preview (max 3) -->
  <?php if (!empty($invNotes)): ?>
  <div class="mt-3 space-y-2">
    <?php foreach (array_slice($invNotes, 0, 3) as $n):
      $badge = match($n['note_type']) {
          'dispute' => ['⚠ Einwand', 'bg-red-100 text-red-700'],
          'correction' => ['✏️ Korrektur', 'bg-amber-100 text-amber-700'],
          default => ['💬 Notiz', 'bg-blue-100 text-blue-700'],
      };
      $statusLabel = match($n['status']) {
          'open' => ['Offen', 'bg-gray-100 text-gray-600'],
          'reviewed' => ['Geprüft', 'bg-blue-100 text-blue-700'],
          'resolved' => ['Gelöst ✓', 'bg-green-100 text-green-700'],
          'rejected' => ['Abgelehnt', 'bg-red-100 text-red-700'],
          default => [$n['status'], 'bg-gray-100'],
      };
    ?>
    <div class="text-xs p-2 bg-gray-50 rounded-lg border border-gray-100">
      <div class="flex items-center gap-2 mb-1">
        <span class="px-1.5 py-0.5 rounded text-[9px] font-semibold <?= $badge[1] ?>"><?= $badge[0] ?></span>
        <span class="px-1.5 py-0.5 rounded text-[9px] font-semibold <?= $statusLabel[1] ?>"><?= $statusLabel[0] ?></span>
        <span class="text-gray-400 text-[10px]"><?= date('d.m.Y H:i', strtotime($n['created_at'])) ?></span>
      </div>
      <div class="text-gray-700"><?= e($n['content']) ?></div>
      <?php if (!empty($n['admin_response'])): ?>
      <div class="mt-2 pl-3 border-l-2 border-brand text-gray-600">
        <span class="font-semibold text-brand">Fleckfrei:</span> <?= e($n['admin_response']) ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php endforeach; ?>

<!-- NOTE MODAL -->
<div x-show="noteModal" x-cloak @click.self="noteModal = false" class="fixed inset-0 bg-black/40 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4" x-transition.opacity>
  <form method="POST" class="bg-white w-full sm:max-w-md rounded-t-2xl sm:rounded-2xl shadow-xl overflow-hidden" @click.stop x-transition>
    <?= csrfField() ?>
    <input type="hidden" name="action" value="submit_note"/>
    <input type="hidden" name="inv_id" :value="noteModalData?.inv_id"/>
    <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
      <div>
        <h3 class="font-semibold text-gray-900">Notiz zur Rechnung</h3>
        <p class="text-xs text-gray-500" x-text="noteModalData?.invoice_number"></p>
      </div>
      <button type="button" @click="noteModal = false" class="w-8 h-8 rounded-lg hover:bg-gray-100 flex items-center justify-center text-gray-500">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>
    <div class="p-5 space-y-4">
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-2 uppercase tracking-wider">Art der Anmerkung</label>
        <div class="grid grid-cols-3 gap-2">
          <label class="cursor-pointer">
            <input type="radio" name="note_type" value="comment" checked class="peer sr-only"/>
            <div class="p-3 border-2 border-gray-200 rounded-xl peer-checked:border-blue-500 peer-checked:bg-blue-50 text-center transition">
              <div class="text-xl">💬</div>
              <div class="text-xs font-semibold mt-1">Notiz</div>
            </div>
          </label>
          <label class="cursor-pointer">
            <input type="radio" name="note_type" value="correction" class="peer sr-only"/>
            <div class="p-3 border-2 border-gray-200 rounded-xl peer-checked:border-amber-500 peer-checked:bg-amber-50 text-center transition">
              <div class="text-xl">✏️</div>
              <div class="text-xs font-semibold mt-1">Korrektur</div>
            </div>
          </label>
          <label class="cursor-pointer">
            <input type="radio" name="note_type" value="dispute" class="peer sr-only"/>
            <div class="p-3 border-2 border-gray-200 rounded-xl peer-checked:border-red-500 peer-checked:bg-red-50 text-center transition">
              <div class="text-xl">⚠</div>
              <div class="text-xs font-semibold mt-1">Einwand</div>
            </div>
          </label>
        </div>
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Was stimmt nicht?</label>
        <textarea name="content" rows="4" required placeholder="Beschreiben Sie Ihre Anmerkung oder den Fehler in der Rechnung..." class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none text-sm"></textarea>
      </div>
      <div class="text-xs p-3 bg-blue-50 border border-blue-200 rounded-lg text-blue-800">
        ℹ Ihre Notiz wird an unser Team gesendet. Wir prüfen und melden uns zeitnah. Bei berechtigten Einwänden wird die Rechnung korrigiert.
      </div>
      <div class="flex gap-2 pt-1">
        <button type="button" @click="noteModal = false" class="flex-1 px-4 py-2.5 border border-gray-200 rounded-lg text-sm font-semibold text-gray-700 hover:bg-gray-50">Abbrechen</button>
        <button type="submit" class="flex-1 px-4 py-2.5 bg-brand hover:bg-brand-dark text-white rounded-lg text-sm font-semibold">Einreichen</button>
      </div>
    </div>
  </form>
</div>
</div>
<?php endif;
else: // tab === other
?>
<div class="card-elev text-center py-16 px-4">
  <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-gray-100 mb-5">
    <svg class="w-10 h-10 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/></svg>
  </div>
  <h3 class="text-lg font-semibold text-gray-900 mb-2">Keine sonstigen Belege</h3>
  <p class="text-sm text-gray-500 max-w-md mx-auto">Hier erscheinen sonstige Belege wie Gutscheine oder Gutschriften.</p>
</div>
<?php endif;

// ============ Stripe + PayPal JS ============
$apiKey = defined('API_KEY') ? API_KEY : '';
$paypalId = defined('PAYPAL_CLIENT_ID') ? PAYPAL_CLIENT_ID : '';
?>

<script>
function stripePayInv(invId, ev) {
  const btn = ev?.target?.closest('button') || ev?.target;
  if (btn) { btn.textContent = 'Wird geladen…'; btn.disabled = true; }
  fetch('/api/index.php?action=stripe/checkout', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-API-Key': <?= json_encode($apiKey) ?> },
    body: JSON.stringify({ inv_id: invId })
  }).then(r => r.json()).then(d => {
    if (d.success && d.data?.checkout_url) {
      window.location.href = d.data.checkout_url;
    } else {
      alert(d.error || 'Fehler beim Erstellen der Zahlung');
      if (btn) { btn.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg> Karte / SEPA'; btn.disabled = false; }
    }
  }).catch(() => {
    alert('Netzwerk-Fehler');
    if (btn) { btn.textContent = 'Karte / SEPA'; btn.disabled = false; }
  });
}
</script>

<?php if (defined('FEATURE_PAYPAL') && FEATURE_PAYPAL): ?>
<script>
(function () {
  const s = document.createElement('script');
  s.src = 'https://www.paypal.com/web-sdk/v6/core';
  s.onload = async function () {
    try {
      const sdkInstance = await window.paypal.createInstance({
        clientId: <?= json_encode($paypalId) ?>,
        components: ['paypal-payments'],
        pageType: 'checkout',
        locale: 'de-DE',
      });
      const methods = await sdkInstance.findEligibleMethods({ currencyCode: 'EUR' });
      if (!methods.isEligible('paypal')) return;

      document.querySelectorAll('.paypal-v6-btn').forEach(btn => {
        const invId = parseInt(btn.dataset.inv);
        const session = sdkInstance.createPayPalOneTimePaymentSession({
          async onApprove(data) {
            try {
              const resp = await fetch('/api/paypal-capture.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ order_id: data.orderId, inv_id: invId })
              });
              const d = await resp.json();
              if (d.success) location.reload();
              else alert(d.error || 'Capture fehlgeschlagen');
            } catch (e) { alert('Netzwerk-Fehler'); }
          },
          onCancel() { },
          onError(err) { console.error('PayPal Error:', err); },
        });

        btn.removeAttribute('hidden');
        btn.addEventListener('click', async () => {
          try {
            const resp = await fetch('/api/paypal-create.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ inv_id: invId })
            });
            const d = await resp.json();
            if (!d.success) { alert(d.error); return; }
            await session.start({ presentationMode: 'auto' }, Promise.resolve({ orderId: d.order_id }));
          } catch (e) { console.error('PayPal start error:', e); }
        });
      });
    } catch (e) { console.error('PayPal v6 init error:', e); }
  };
  document.head.appendChild(s);
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer-customer.php'; ?>
