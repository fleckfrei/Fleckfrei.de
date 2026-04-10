<?php
require_once __DIR__ . '/../../includes/auth.php';
requireCustomer();
$title = 'Weiterempfehlen'; $page = 'referral';
$cid = me()['id'];

$customer = one("SELECT * FROM customer WHERE customer_id=?", [$cid]);

// Referral code: stable per customer. If customer table has referral_code field, use it.
// Otherwise derive a deterministic short code from customer_id.
$refCode = $customer['referral_code'] ?? null;
if (!$refCode) {
    // Deterministic code: first 3 letters of name + customer id in base36
    $prefix = strtoupper(substr(preg_replace('/[^a-z]/i', '', $customer['name'] ?? 'FLK'), 0, 3) ?: 'FLK');
    $refCode = $prefix . str_pad(base_convert((string) $cid, 10, 36), 4, '0', STR_PAD_LEFT);
}

$refLink = 'https://fleckfrei.de/?ref=' . $refCode;
$shareText = "Ich nutze " . SITE . " für meine Wohnungsreinigung in Berlin — hier ist mein Einladungscode für 50% Rabatt auf deine erste Buchung: $refCode";

include __DIR__ . '/../../includes/layout-v2.php';
?>

<div class="mb-6">
  <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Sauber sparen — 50 % Rabatt</h1>
  <p class="text-gray-500 mt-1 text-sm">Empfehlen Sie <?= SITE ?> an Freunde und sparen Sie beide 50 % auf Ihre nächste Buchung.</p>
</div>

<div class="card-elev p-6 sm:p-8 mb-6 bg-gradient-to-br from-white to-brand-light max-w-3xl">
  <h2 class="text-xl font-bold text-gray-900 mb-3">Ihr Einladungscode</h2>

  <div class="bg-white rounded-xl border-2 border-dashed border-brand p-6 text-center my-5" x-data="{ copied: false }">
    <div class="text-[11px] uppercase tracking-widest text-gray-500 font-semibold mb-2">Ihr persönlicher Code</div>
    <div class="text-3xl sm:text-4xl font-extrabold text-brand font-mono tracking-widest mb-3"><?= e($refCode) ?></div>
    <button
      @click="navigator.clipboard.writeText('<?= e($refCode) ?>'); copied = true; setTimeout(() => copied = false, 2000);"
      class="inline-flex items-center gap-2 px-4 py-2 bg-brand hover:bg-brand-dark text-white rounded-lg text-sm font-semibold transition">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
      <span x-text="copied ? 'Kopiert!' : 'Code kopieren'"></span>
    </button>
  </div>

  <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-6">
    <!-- WhatsApp -->
    <a href="https://wa.me/?text=<?= urlencode($shareText . "\n\n" . $refLink) ?>" target="_blank" rel="noopener"
       class="flex flex-col items-center gap-2 p-4 rounded-xl border border-gray-200 hover:border-green-500 hover:bg-green-50 transition">
      <div class="w-10 h-10 rounded-full bg-green-500 flex items-center justify-center text-white text-lg">💬</div>
      <span class="text-xs font-semibold text-gray-700">WhatsApp</span>
    </a>
    <!-- Email -->
    <a href="mailto:?subject=<?= urlencode('50 % Rabatt bei ' . SITE) ?>&body=<?= urlencode($shareText . "\n\n" . $refLink) ?>"
       class="flex flex-col items-center gap-2 p-4 rounded-xl border border-gray-200 hover:border-amber-500 hover:bg-amber-50 transition">
      <div class="w-10 h-10 rounded-full bg-amber-500 flex items-center justify-center text-white text-lg">✉️</div>
      <span class="text-xs font-semibold text-gray-700">E-Mail</span>
    </a>
    <!-- Facebook -->
    <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode($refLink) ?>" target="_blank" rel="noopener"
       class="flex flex-col items-center gap-2 p-4 rounded-xl border border-gray-200 hover:border-blue-600 hover:bg-blue-50 transition">
      <div class="w-10 h-10 rounded-full bg-blue-600 flex items-center justify-center text-white text-lg">f</div>
      <span class="text-xs font-semibold text-gray-700">Facebook</span>
    </a>
    <!-- Copy Link -->
    <button onclick="navigator.clipboard.writeText('<?= e($refLink) ?>'); this.querySelector('span').textContent='Kopiert!'; setTimeout(() => this.querySelector('span').textContent='Link kopieren', 2000);"
       class="flex flex-col items-center gap-2 p-4 rounded-xl border border-gray-200 hover:border-brand hover:bg-brand-light transition">
      <div class="w-10 h-10 rounded-full bg-brand flex items-center justify-center text-white text-lg">🔗</div>
      <span class="text-xs font-semibold text-gray-700">Link kopieren</span>
    </button>
  </div>
</div>

<!-- So funktioniert's -->
<div class="card-elev p-6 sm:p-8 max-w-3xl">
  <h2 class="text-lg font-bold text-gray-900 mb-5">So funktioniert's</h2>
  <div class="space-y-5">
    <div class="flex gap-4">
      <div class="flex-shrink-0 w-10 h-10 rounded-full bg-brand text-white flex items-center justify-center font-bold">1</div>
      <div>
        <h3 class="font-semibold text-gray-900 mb-1">Code teilen</h3>
        <p class="text-sm text-gray-500">Senden Sie Ihren Einladungscode per WhatsApp, E-Mail oder Facebook an Freunde und Familie.</p>
      </div>
    </div>
    <div class="flex gap-4">
      <div class="flex-shrink-0 w-10 h-10 rounded-full bg-brand text-white flex items-center justify-center font-bold">2</div>
      <div>
        <h3 class="font-semibold text-gray-900 mb-1">Freund bucht mit Code</h3>
        <p class="text-sm text-gray-500">Ihr Freund gibt Ihren Code bei der ersten Buchung ein und erhält sofort 50 % Rabatt auf seine erste Reinigung.</p>
      </div>
    </div>
    <div class="flex gap-4">
      <div class="flex-shrink-0 w-10 h-10 rounded-full bg-brand text-white flex items-center justify-center font-bold">3</div>
      <div>
        <h3 class="font-semibold text-gray-900 mb-1">Sie sparen auch</h3>
        <p class="text-sm text-gray-500">Sobald Ihr Freund seine erste Buchung erfolgreich abgeschlossen hat, erhalten Sie ebenfalls <strong>50 % Rabatt</strong> auf Ihre nächste Buchung.</p>
      </div>
    </div>
  </div>
</div>

<p class="text-[11px] text-gray-400 mt-6 max-w-3xl">
  * Der Rabatt wird nach erfolgreichem Abschluss des ersten Termins Ihres geworbenen Freundes gutgeschrieben. Maximal ein Rabatt pro Buchung. Nicht mit anderen Aktionen kombinierbar.
</p>

<?php include __DIR__ . '/../../includes/footer-v2.php'; ?>
