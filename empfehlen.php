<?php
require_once __DIR__ . '/includes/config.php';
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>50 € weiterempfehlen · Fleckfrei</title>
<meta name="description" content="Empfehle Fleckfrei weiter und bekomme 50 € Gutschrift nach 3 Monaten — einfach & transparent."/>
<meta name="theme-color" content="<?= BRAND ?>"/>
<link rel="icon" href="https://fleckfrei.de/img/logo/favicon.svg"/>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<script>tailwind.config={theme:{extend:{colors:{brand:'<?= BRAND ?>','brand-dark':'<?= BRAND_DARK ?>','brand-light':'<?= BRAND_LIGHT ?>'},fontFamily:{sans:['Inter','sans-serif']}}}}</script>
<style>body{font-family:'Inter',sans-serif}</style>
</head>
<body class="bg-gray-50 text-gray-900">

<header class="bg-white border-b sticky top-0 z-30">
  <div class="max-w-5xl mx-auto px-4 py-3 flex items-center justify-between">
    <a href="https://fleckfrei.de" class="font-bold text-xl text-brand">Fleckfrei</a>
    <a href="/book.php" class="px-3 py-1.5 bg-brand text-white rounded-lg text-sm font-medium hover:bg-brand-dark">Jetzt buchen</a>
  </div>
</header>

<main class="max-w-5xl mx-auto px-4 py-8">

  <!-- Hero -->
  <section class="bg-gradient-to-br from-amber-400 via-orange-500 to-amber-600 text-white rounded-3xl p-8 md:p-12 mb-8 relative overflow-hidden">
    <div class="absolute -top-16 -right-16 w-64 h-64 bg-white/20 rounded-full blur-3xl"></div>
    <div class="absolute -bottom-20 -left-20 w-72 h-72 bg-white/10 rounded-full blur-3xl"></div>
    <div class="relative">
      <div class="text-6xl mb-4">🎁</div>
      <h1 class="text-4xl md:text-5xl font-extrabold mb-3">50 € für Sie.<br/>Für Ihre Freunde.</h1>
      <p class="text-lg md:text-xl text-white/90 max-w-2xl mb-6">Empfehlen Sie Fleckfrei Ihren Freunden, Nachbarn, Kollegen. Wenn jemand über Sie bucht, bekommen <b>Sie 50 € Gutschrift</b> nach 3 Monaten auf Ihr Kundenkonto.</p>
      <a href="/login.php?next=/customer/refer.php" class="inline-block px-6 py-3 bg-white text-brand-dark rounded-xl font-bold text-lg hover:bg-gray-100 transition">Mein Empfehlungscode →</a>
    </div>
  </section>

  <!-- So funktioniert's -->
  <section class="mb-8">
    <h2 class="text-2xl font-bold mb-6 text-center">So funktioniert es</h2>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div class="bg-white rounded-2xl border p-6">
        <div class="w-12 h-12 bg-brand-light text-brand rounded-full flex items-center justify-center font-bold text-xl mb-3">1</div>
        <h3 class="font-bold mb-2">Teilen Sie Ihren Code</h3>
        <p class="text-sm text-gray-600">Jeder Kunde bekommt nach der 1. Buchung einen persönlichen Code — über WhatsApp, Email oder Kopier-Button teilen.</p>
      </div>
      <div class="bg-white rounded-2xl border p-6">
        <div class="w-12 h-12 bg-brand-light text-brand rounded-full flex items-center justify-center font-bold text-xl mb-3">2</div>
        <h3 class="font-bold mb-2">Freund bucht mit Code</h3>
        <p class="text-sm text-gray-600">Er/sie gibt Ihren Code bei der Buchung ein. Bekommt selbst einen 10 € Willkommens-Rabatt auf den ersten Auftrag.</p>
      </div>
      <div class="bg-white rounded-2xl border p-6">
        <div class="w-12 h-12 bg-brand-light text-brand rounded-full flex items-center justify-center font-bold text-xl mb-3">3</div>
        <h3 class="font-bold mb-2">Sie bekommen 50 €</h3>
        <p class="text-sm text-gray-600">Nach 3 Monaten aktiver Nutzung (min. 2 erledigte Termine) wird die Gutschrift auf Ihr Konto gebucht.</p>
      </div>
    </div>
  </section>

  <!-- Konditionen -->
  <section class="bg-white border rounded-2xl p-6 md:p-8 mb-8">
    <h2 class="text-xl font-bold mb-4 flex items-center gap-2">📋 Konditionen</h2>
    <ol class="space-y-3 text-sm text-gray-700 list-decimal list-inside">
      <li><b>Anspruch auf die 50 € Gutschrift:</b> Der geworbene Kunde (Freund) muss innerhalb von 3 Monaten nach Erst-Buchung mindestens 2 erledigte und bezahlte Reinigungs-Termine haben.</li>
      <li><b>Auszahlung als Gutschrift:</b> Die 50 € werden als Guthaben auf Ihr Fleckfrei-Konto gebucht und automatisch mit Ihrer nächsten Rechnung verrechnet. Keine Bar-Auszahlung möglich.</li>
      <li><b>Gültigkeit der Gutschrift:</b> 12 Monate ab Gutschrift-Datum. Danach verfällt nicht-eingesetztes Guthaben.</li>
      <li><b>Mehrfach-Empfehlungen:</b> Unbegrenzt oft möglich — pro geworbenem Freund gibt es einmalig 50 €.</li>
      <li><b>Selbst-Empfehlung ausgeschlossen:</b> Der geworbene Kunde muss eine andere Person sein (anderer Haushalt, andere Rechnungsadresse, andere Email).</li>
      <li><b>Stornierungen:</b> Wird einer der ersten 2 Termine storniert oder nicht bezahlt, verfällt der Anspruch auf die Empfehlungs-Gutschrift.</li>
      <li><b>Kein Cashback auf Empfehlungs-Buchungen:</b> Der neue Kunde kann pro Buchung nur 1 Vorteil nutzen — entweder Empfehlungs-Code (10 € Willkommens-Rabatt) ODER anderen Voucher/Gutschein, nicht beides.</li>
      <li><b>Wir behalten uns vor,</b> Empfehlungen zu überprüfen und bei Missbrauch oder Verdacht auf Betrug (z.B. mehrere Accounts, gleiche IP) den Anspruch auszuschließen.</li>
      <li><b>DSGVO:</b> Wir speichern Ihren Code und die Verknüpfung zu geworbenen Kunden nur zum Zweck der Gutschrift. Auszahlungsdaten werden nach 24 Monaten gelöscht.</li>
      <li><b>Änderungen:</b> Fleckfrei kann die Bedingungen jederzeit mit 30 Tagen Vorlauf anpassen. Bereits qualifizierte Ansprüche bleiben davon unberührt.</li>
    </ol>
    <p class="text-xs text-gray-500 mt-5 pt-4 border-t">Stand: <?= date('d.m.Y') ?> · Fragen? <a href="mailto:<?= defined('CONTACT_EMAIL') ? CONTACT_EMAIL : 'kontakt@fleckfrei.de' ?>" class="text-brand underline">kontakt@fleckfrei.de</a></p>
  </section>

  <!-- CTA Bottom -->
  <section class="text-center py-8">
    <h2 class="text-2xl font-bold mb-3">Bereit, 50 € zu verdienen?</h2>
    <p class="text-gray-600 mb-5">Nach Ihrer 1. Buchung bekommen Sie Ihren persönlichen Code.</p>
    <a href="/book.php" class="inline-block px-6 py-3 bg-brand text-white rounded-xl font-bold hover:bg-brand-dark transition">Jetzt Termin buchen →</a>
    <a href="/login.php" class="ml-3 inline-block px-6 py-3 border-2 border-brand text-brand rounded-xl font-bold hover:bg-brand-light transition">Bereits Kunde? Login</a>
  </section>

</main>

<footer class="bg-white border-t py-6 mt-6">
  <div class="max-w-5xl mx-auto px-4 flex flex-wrap items-center justify-between gap-4 text-sm text-gray-500">
    <span class="font-bold text-brand">Fleckfrei</span>
    <div class="flex gap-6">
      <a href="https://fleckfrei.de/impressum" class="hover:text-brand">Impressum</a>
      <a href="https://fleckfrei.de/datenschutz" class="hover:text-brand">Datenschutz</a>
      <a href="https://fleckfrei.de/agb" class="hover:text-brand">AGB</a>
    </div>
  </div>
</footer>

<script defer src="/api/widget.js"></script>
</body>
</html>
