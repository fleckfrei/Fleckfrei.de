<?php
require_once __DIR__ . '/../includes/auth.php';
requireCustomer();
$title = 'Hilfe-Center'; $page = 'help';

include __DIR__ . '/../includes/layout-customer.php';
?>

<!-- Back button -->
<div class="mb-4">
  <a href="/customer/" class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-brand transition">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
    Zurück
  </a>
</div>

<!-- Hero -->
<div class="mb-8 text-center sm:text-left">
  <h1 class="text-3xl sm:text-4xl font-extrabold text-gray-900">Wie können wir helfen?</h1>
  <p class="text-gray-500 mt-2 text-sm">Antworten auf häufige Fragen oder direkter Kontakt — wir sind für Sie da.</p>
</div>

<!-- ========================================================== -->
<!-- Contact Cards — 3 Kanäle prominent                         -->
<!-- ========================================================== -->
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-10">

  <!-- WhatsApp (primary) -->
  <a href="<?= CONTACT_WHATSAPP_URL ?>" target="_blank" class="group card-elev p-6 hover:border-green-500 hover:shadow-lg transition bg-gradient-to-br from-green-50 to-transparent">
    <div class="w-14 h-14 rounded-2xl bg-green-500 text-white flex items-center justify-center mb-4 group-hover:scale-110 transition-transform shadow-lg shadow-green-500/30">
      <svg class="w-7 h-7" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413"/></svg>
    </div>
    <h3 class="font-bold text-gray-900 mb-1">WhatsApp</h3>
    <p class="text-xs text-gray-500 mb-3">Schnellste Antwort — meist innerhalb weniger Minuten. Bilder, Dokumente und Sprachnachrichten möglich.</p>
    <div class="inline-flex items-center gap-1.5 text-sm font-semibold text-green-600 group-hover:text-green-700">
      Chat starten
      <svg class="w-4 h-4 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
    </div>
  </a>

  <!-- E-Mail -->
  <a href="mailto:<?= CONTACT_EMAIL ?>" class="group card-elev p-6 hover:border-brand hover:shadow-lg transition bg-gradient-to-br from-brand/5 to-transparent">
    <div class="w-14 h-14 rounded-2xl bg-brand text-white flex items-center justify-center mb-4 group-hover:scale-110 transition-transform shadow-lg shadow-brand/30">
      <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
    </div>
    <h3 class="font-bold text-gray-900 mb-1">E-Mail</h3>
    <p class="text-xs text-gray-500 mb-3">Für ausführliche Anfragen, Dokumente oder offizielle Korrespondenz. Antwort meist am selben Werktag.</p>
    <div class="inline-flex items-center gap-1.5 text-sm font-semibold text-brand group-hover:text-brand-dark">
      <?= CONTACT_EMAIL ?>
      <svg class="w-4 h-4 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
    </div>
  </a>

  <!-- Chat im Portal -->
  <a href="/customer/messages.php" class="group card-elev p-6 hover:border-blue-500 hover:shadow-lg transition bg-gradient-to-br from-blue-50 to-transparent">
    <div class="w-14 h-14 rounded-2xl bg-blue-500 text-white flex items-center justify-center mb-4 group-hover:scale-110 transition-transform shadow-lg shadow-blue-500/30">
      <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
    </div>
    <h3 class="font-bold text-gray-900 mb-1">Chat im Portal</h3>
    <p class="text-xs text-gray-500 mb-3">Bleiben Sie im Fleckfrei-Portal und chatten Sie direkt mit uns. Übersetzt automatisch für Ihren Partner.</p>
    <div class="inline-flex items-center gap-1.5 text-sm font-semibold text-blue-600 group-hover:text-blue-700">
      Chat öffnen
      <svg class="w-4 h-4 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
    </div>
  </a>

</div>

<!-- ========================================================== -->
<!-- FAQ Sections                                               -->
<!-- ========================================================== -->
<div class="max-w-4xl mx-auto sm:mx-0" x-data="{ open: null }">
  <div class="flex items-center gap-3 mb-5">
    <svg class="w-6 h-6 text-brand" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    <h2 class="text-xl font-bold text-gray-900">Häufige Fragen</h2>
  </div>

  <?php
  // FAQ grouped by category
  $faqGroups = [
      '📅 Buchung & Termine' => [
          ['Wie buche ich einen neuen Termin?', 'Klicken Sie oben rechts auf „Jetzt buchen". Wählen Sie Häufigkeit, Größe Ihrer Wohnung, Datum und Uhrzeit. Sie sehen den Preis sofort live.'],
          ['Kann ich meinen Termin stornieren?', 'Ja. Bis 24 Stunden vor dem Termin ist die Stornierung kostenlos. Danach wird eine Stornogebühr von 50% berechnet. Stornieren können Sie unter „Termine" → „Stornieren".'],
          ['Kann ich umbuchen?', 'Ja, unter „Termine" → „Umbuchen" wählen Sie ein neues Datum. Wir zeigen Ihnen sofort welche Tage Partner-Verfügbarkeit haben. Bei weniger als 24h fällt ggf. eine Gebühr an.'],
          ['Was passiert wenn der Partner verspätet kommt?', 'Bei mehr als 15 Minuten Verspätung erhalten Sie eine automatische Benachrichtigung. Bei mehr als 30 Minuten Verspätung wird der Termin automatisch storniert ohne Gebühr.'],
      ],
      '💰 Preise & Zahlung' => [
          ['Wie bezahle ich meine Rechnung?', 'Sie können per SEPA-Lastschrift, Kreditkarte, PayPal oder per WhatsApp-Link bezahlen. Unter „Rechnungen" finden Sie alle offenen Posten und den Zahlungs-Link.'],
          ['Was bedeutet Netto / MwSt / Brutto?', 'Netto = Grundpreis, MwSt = gesetzliche Mehrwertsteuer (19%), Brutto = der Endbetrag den Sie zahlen. Bei jeder Rechnung sehen Sie die Aufschlüsselung.'],
          ['Gibt es einen Last-Minute-Rabatt?', 'Ja! Bei kurzfristigen Buchungen innerhalb der nächsten 24-48 Stunden gewähren wir automatisch 10-15% Rabatt, weil wir freie Kapazitäten besser nutzen können.'],
          ['Was mache ich bei fehlerhaften Rechnungen?', 'Unter „Rechnungen" klicken Sie auf „💬 Notiz / Einwand" und wählen Sie „⚠ Einwand". Wir prüfen das und melden uns zeitnah.'],
      ],
      '🧹 Service & Partner' => [
          ['Wer kommt bei mir zu Hause vorbei?', 'Ihre persönliche Reinigungspartnerin. Sie sehen Avatar + Anzeigename (der echte Name bleibt aus Datenschutzgründen privat). Sie können die Partnerin nach dem Termin bewerten und bei der nächsten Buchung dieselbe Person anfragen.'],
          ['Wie bewerte ich meinen Partner?', 'Nach abgeschlossenem Termin haben Sie 24 Stunden Zeit für eine Bewertung (1-5 Sterne + optionale Nachricht). Ihre Nachricht wird automatisch in die Sprache des Partners übersetzt.'],
          ['Was wenn ich unzufrieden bin?', 'Zwischen 24-48 Stunden nach dem Termin können Sie eine „Reklamation" einreichen. Wir prüfen jeden Fall individuell.'],
          ['Kann ich Reinigungsmittel mitbestellen?', 'Ja, beim Buchen können Sie „Reinigungsmittel inklusive" für 15,99 € pauschal auswählen. Wir bringen dann professionelle Produkte mit.'],
      ],
      '🔐 Sicherheit & Datenschutz' => [
          ['Sind meine Daten sicher?', 'Alle Daten werden DSGVO-konform auf EU-Servern gespeichert. Details finden Sie unter Kontoeinstellungen → Datenschutz. Wir verkaufen Ihre Daten nicht.'],
          ['Wie übergebe ich meinen Schlüssel sicher?', 'Kontaktieren Sie uns per WhatsApp für einen Termin. Jede Schlüsselübergabe wird lückenlos protokolliert (Datum, Uhrzeit, wer hat übergeben, wer hat erhalten). Sie sehen alles unter „Dokumente" → „Schlüssel".'],
          ['Kann ich mein Konto löschen?', 'Ja, unter Kontoeinstellungen → Konto → „Konto endgültig löschen". Wir erstellen einen verschlüsselten Datenschnappschuss für 30 Tage falls Sie sich umentscheiden.'],
      ],
      '🎁 Bonus & Sparen' => [
          ['Wie funktioniert der Einladungscode?', 'Unter „Weiterempfehlen" finden Sie Ihren persönlichen Code. Jeder neue Kunde bekommt 50 % Rabatt auf die erste Buchung — und Sie auch bei der nächsten.'],
          ['Bekomme ich einen Rabatt als Stammkunde?', 'Ja! Als Host/Airbnb-Kunde erhalten Sie automatisch Ihre individuellen Stammkunden-Preise. Bei regelmäßigen wöchentlichen Terminen gibt es 7% zusätzlich.'],
      ],
  ];

  $globalIdx = 0;
  foreach ($faqGroups as $groupTitle => $faqs):
  ?>
  <div class="mb-6">
    <h3 class="text-sm font-bold text-gray-600 uppercase tracking-wider mb-3"><?= e($groupTitle) ?></h3>
    <div class="card-elev overflow-hidden divide-y divide-gray-100">
      <?php foreach ($faqs as [$q, $a]): $idx = $globalIdx++; ?>
      <div>
        <button @click="open === <?= $idx ?> ? open = null : open = <?= $idx ?>" class="w-full flex items-center justify-between text-left px-5 py-4 hover:bg-gray-50 transition">
          <span class="font-semibold text-gray-900 text-sm pr-4"><?= e($q) ?></span>
          <svg class="w-5 h-5 text-gray-400 transition-transform flex-shrink-0" :class="open === <?= $idx ?> ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
        </button>
        <div x-show="open === <?= $idx ?>" x-cloak x-transition class="px-5 pb-4 text-sm text-gray-600 leading-relaxed"><?= e($a) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Bottom CTA -->
<div class="mt-10 mb-6 max-w-4xl">
  <div class="card-elev p-6 bg-gradient-to-br from-brand to-brand-dark text-white border-0">
    <div class="flex flex-col sm:flex-row items-center gap-4">
      <div class="text-5xl">💬</div>
      <div class="flex-1 text-center sm:text-left">
        <h3 class="font-bold text-xl">Nichts gefunden?</h3>
        <p class="text-white/80 text-sm mt-1">Schreiben Sie uns einfach — wir helfen Ihnen gerne persönlich weiter.</p>
      </div>
      <a href="<?= CONTACT_WHATSAPP_URL ?>" target="_blank" class="px-6 py-3 bg-white text-brand rounded-xl font-bold text-sm hover:bg-gray-50 transition flex items-center gap-2 shadow-lg whitespace-nowrap">
        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347"/></svg>
        WhatsApp öffnen
      </a>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer-customer.php'; ?>
