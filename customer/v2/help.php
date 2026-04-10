<?php
require_once __DIR__ . '/../../includes/auth.php';
requireCustomer();
$title = 'Hilfe-Center'; $page = 'help';

include __DIR__ . '/../../includes/layout-v2.php';
?>

<div class="mb-6">
  <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Hilfe-Center</h1>
  <p class="text-gray-500 mt-1 text-sm">Antworten auf häufige Fragen und direkter Kontakt.</p>
</div>

<!-- Contact card -->
<div class="card-elev p-6 mb-6 max-w-3xl">
  <div class="flex items-start gap-4">
    <div class="w-12 h-12 rounded-full bg-brand-light flex items-center justify-center flex-shrink-0">
      <svg class="w-6 h-6 text-brand" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z"/></svg>
    </div>
    <div class="flex-1">
      <h2 class="font-bold text-gray-900">Sie brauchen Hilfe?</h2>
      <p class="text-sm text-gray-500 mt-1 mb-4">Wir sind für Sie da. Wählen Sie den Kanal, der für Sie am besten passt.</p>
      <div class="flex flex-col sm:flex-row gap-3">
        <a href="https://wa.me/<?= CONTACT_WA ?>" target="_blank" class="inline-flex items-center justify-center gap-2 px-5 py-3 bg-green-500 hover:bg-green-600 text-white rounded-lg font-semibold text-sm transition">
          <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
          WhatsApp
        </a>
        <a href="mailto:<?= CONTACT_EMAIL ?>" class="inline-flex items-center justify-center gap-2 px-5 py-3 border border-gray-200 hover:bg-gray-50 text-gray-700 rounded-lg font-semibold text-sm transition">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
          E-Mail
        </a>
      </div>
    </div>
  </div>
</div>

<!-- FAQ -->
<div class="max-w-3xl" x-data="{ open: null }">
  <h2 class="text-lg font-bold text-gray-900 mb-4">Häufige Fragen</h2>
  <div class="card-elev overflow-hidden divide-y">
    <?php
    $faqs = [
        ['Wie buche ich einen neuen Termin?', 'Klicken Sie oben rechts auf „Jetzt buchen". Wählen Sie Häufigkeit, Größe Ihrer Wohnung, Datum und Uhrzeit. Sie sehen den Preis sofort.'],
        ['Kann ich meinen Termin stornieren?', 'Ja. Bis 24 Stunden vor dem Termin ist die Stornierung kostenlos. Danach wird eine Stornogebühr berechnet. Stornieren können Sie unter „Meine Termine".'],
        ['Wie bezahle ich meine Rechnung?', 'Sie können per SEPA-Lastschrift, Kreditkarte oder PayPal bezahlen. Unter „Rechnungen" finden Sie alle offenen Posten und den Zahlungs-Link.'],
        ['Wer kommt bei mir zu Hause vorbei?', 'Ihre persönliche Reinigungspartnerin. Sie können Ihre Partnerin nach dem Termin bewerten und ggf. bei der nächsten Buchung dieselbe Person anfragen.'],
        ['Sind meine Daten sicher?', 'Alle Daten werden DSGVO-konform in Deutschland/EU gespeichert. Details finden Sie unter Kontoeinstellungen → Datenschutz.'],
        ['Wie funktioniert der Einladungscode?', 'Unter „Weiterempfehlen" finden Sie Ihren persönlichen Code. Jeder neue Kunde bekommt 50 % Rabatt auf die erste Buchung — und Sie auch bei der nächsten.'],
    ];
    foreach ($faqs as $i => [$q, $a]):
    ?>
    <div>
      <button @click="open === <?= $i ?> ? open = null : open = <?= $i ?>" class="w-full flex items-center justify-between text-left px-5 py-4 hover:bg-gray-50 transition">
        <span class="font-semibold text-gray-900 text-sm"><?= e($q) ?></span>
        <svg class="w-5 h-5 text-gray-400 transition-transform" :class="open === <?= $i ?> ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
      </button>
      <div x-show="open === <?= $i ?>" x-cloak x-transition class="px-5 pb-4 text-sm text-gray-600 leading-relaxed"><?= e($a) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<?php include __DIR__ . '/../../includes/footer-v2.php'; ?>
