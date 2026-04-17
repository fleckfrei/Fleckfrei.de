<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Prebooking-Links'; $page = 'prebook-links';
$me = $_SESSION['uemail'] ?? 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $act = $_POST['action'] ?? '';

    if ($act === 'generate_email_ai') {
        header('Content-Type: application/json; charset=utf-8');
        require_once __DIR__ . '/../includes/llm-helpers.php';
        $plid = (int)($_POST['pl_id'] ?? 0);
        $pl = $plid ? one("SELECT * FROM prebooking_links WHERE pl_id=?", [$plid]) : null;
        if (!$pl) { echo json_encode(['error' => 'link not found']); exit; }

        $svcLabels = ['home_care' => 'Reinigung (Home Care)', 'str' => 'Short-Term Rental Reinigung', 'office' => 'Büro-/Business-Reinigung'];
        $svc = $svcLabels[$pl['service_type']] ?? $pl['service_type'];
        $link = 'https://app.' . SITE_DOMAIN . '/p/' . $pl['token'];
        $gross = $pl['custom_hourly_gross'] ? (float)$pl['custom_hourly_gross'] : null;
        $net = $gross ? round($gross / (1 + TAX_RATE), 2) : null;

        $prompt = "Schreibe eine freundliche, kurze deutsche Email an einen potenziellen Kunden von Fleckfrei (Reinigungsservice Berlin). Enthält den persönlichen Buchungs-Link.\n\n"
                . "Kunde: " . ($pl['name'] ?: 'Kunde') . "\n"
                . "Email: " . ($pl['email'] ?: '-') . "\n"
                . "Adresse: " . trim(($pl['street'] ?? '') . ', ' . ($pl['plz'] ?? '') . ' ' . ($pl['city'] ?? ''), ', ') . "\n"
                . "Service: $svc\n"
                . "Dauer: " . (int)$pl['duration'] . " Stunden\n"
                . ($gross ? "Stundensatz: " . number_format($gross, 2, ',', '.') . " €/h brutto (netto " . number_format($net, 2, ',', '.') . " €/h)\n" : "")
                . ($pl['voucher_code'] ? "Voucher-Code: " . $pl['voucher_code'] . "\n" : "")
                . ((int)($pl['travel_tickets'] ?? 0) > 0 ? "BVG-Tickets: " . (int)$pl['travel_tickets'] . " × " . number_format((float)$pl['travel_ticket_price'], 2, ',', '.') . " € bar an Partner\n" : "")
                . ($pl['notes'] ? "Interne Notiz (nicht in Email erwähnen): " . $pl['notes'] . "\n" : "")
                . "Gültig bis: " . date('d.m.Y', strtotime($pl['expires_at'])) . "\n"
                . "Link: $link\n\n"
                . "Gib AUSSCHLIESSLICH JSON zurück, keine Erklärung, keine Markdown-Blöcke:\n"
                . '{"subject": "...", "body": "HTML-Body mit <p>, <b>, <a> — keine <html>/<body>-Tags"}' . "\n\n"
                . "- Subject: max 70 Zeichen, persönlich, mit Vornamen falls vorhanden\n"
                . "- Body: 3-4 kurze Absätze, herzlicher Ton, konkrete Details (Service, Dauer, Preis falls gesetzt), ein grosser Button-Link (a-Tag mit inline-style padding, background, color, border-radius) zum Link\n"
                . "- Signatur: 'Herzliche Grüße, Ihr Fleckfrei-Team'";

        $r = groq_chat($prompt, 700);
        $content = trim($r['content'] ?? '');
        $content = preg_replace('/^```(?:json)?|```$/m', '', $content);
        $parsed = json_decode(trim($content), true);
        if (!is_array($parsed) || empty($parsed['subject']) || empty($parsed['body'])) {
            echo json_encode(['error' => 'KI-Antwort nicht lesbar', 'raw' => $content]); exit;
        }
        echo json_encode(['subject' => $parsed['subject'], 'body' => $parsed['body']]);
        exit;
    }

    if ($act === 'send_custom_email' && function_exists('sendEmail')) {
        $plid = (int)($_POST['pl_id'] ?? 0);
        $pl = $plid ? one("SELECT * FROM prebooking_links WHERE pl_id=?", [$plid]) : null;
        $subj = trim($_POST['subject'] ?? '');
        $body = trim($_POST['body'] ?? '');
        if (!$pl || !$pl['email'] || $subj === '' || $body === '') {
            header("Location: /admin/prebook-links.php?err=missing"); exit;
        }
        sendEmail($pl['email'], $subj, $body, null, 'booking');
        header("Location: /admin/prebook-links.php?sent=1"); exit;
    }

    if ($act === 'create_link') {
        // Sanitizer: beliebiger Input → URL-safe slug
        $sanitize = function(string $raw): string {
            $s = strtolower(trim($raw));
            $s = str_replace(['ä','ö','ü','ß','é','è','à','ç','ñ','Ä','Ö','Ü'], ['ae','oe','ue','ss','e','e','a','c','n','ae','oe','ue'], $s);
            $s = preg_replace('/[^a-z0-9]+/', '-', $s);
            return trim($s, '-');
        };

        $customSlug = $sanitize($_POST['custom_slug'] ?? '');
        $nameSlug   = $sanitize($_POST['name']         ?? '');
        $baseSlug   = $customSlug !== '' ? $customSlug : ($nameSlug !== '' ? $nameSlug : 'gast');
        $baseSlug   = substr($baseSlug, 0, 30);
        if (strlen($baseSlug) < 3) $baseSlug = 'gast';

        // Kollision-Handling: hänge 4-char suffix nur bei echter Dopplung an
        $token = $baseSlug;
        $i = 0;
        while (val("SELECT pl_id FROM prebooking_links WHERE token=?", [$token])) {
            $token = substr($baseSlug, 0, 25) . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
            if (++$i > 5) { $token = bin2hex(random_bytes(16)); break; }
        }
        $expires = !empty($_POST['expires_at']) ? $_POST['expires_at'] : date('Y-m-d 23:59:59', strtotime('+30 days'));
        q("INSERT INTO prebooking_links (token, email, name, phone, street, plz, city, district, service_type, duration, voucher_code, notes, created_by, expires_at, custom_hourly_gross, travel_tickets, travel_ticket_price) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)", [
            $token,
            strtolower(trim($_POST['email'] ?? '')) ?: null,
            trim($_POST['name'] ?? '') ?: null,
            trim($_POST['phone'] ?? '') ?: null,
            trim($_POST['street'] ?? '') ?: null,
            trim($_POST['plz'] ?? '') ?: null,
            trim($_POST['city'] ?? 'Berlin'),
            trim($_POST['district'] ?? '') ?: null,
            $_POST['service_type'] ?? 'home_care',
            (int)($_POST['duration'] ?? 2),
            trim($_POST['voucher_code'] ?? '') ?: null,
            trim($_POST['notes'] ?? '') ?: null,
            $me,
            $expires,
            !empty($_POST['custom_hourly_gross']) ? (float)$_POST['custom_hourly_gross'] : null,
            max(0, (int)($_POST['travel_tickets'] ?? 0)),
            max(0, (float)($_POST['travel_ticket_price'] ?? 3.80)),
        ]);

        // Auto-Email senden wenn Checkbox aktiv und Email vorhanden
        $emailSent = 0;
        if (!empty($_POST['auto_send_email']) && !empty($_POST['email']) && function_exists('sendEmail')) {
            $link = 'https://app.' . SITE_DOMAIN . '/p/' . $token;
            $n = trim($_POST['name'] ?? '') ?: 'Kunde';
            $html = "<p>Hallo " . e($n) . ",</p><p>vielen Dank für Ihr Interesse an Fleckfrei. Über diesen persönlichen Link können Sie direkt Ihren Termin buchen — Ihre Daten sind bereits vorausgefüllt:</p><p><a href=\"$link\" style=\"background:<?= BRAND ?>;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:700;display:inline-block\">Jetzt Termin buchen →</a></p><p style=\"color:#666;font-size:13px;margin-top:16px\">Oder Link kopieren: <a href=\"$link\">$link</a></p><p style=\"color:#999;font-size:11px\">Link gültig bis " . date('d.m.Y', strtotime($expires)) . "</p><p>Viele Grüße<br/>Ihr Team von Fleckfrei</p>";
            if (sendEmail(strtolower(trim($_POST['email'])), 'Ihr persönlicher Buchungs-Link — Fleckfrei', $html, null, 'booking')) $emailSent = 1;
        }

        header("Location: /admin/prebook-links.php?created=" . urlencode($token) . ($emailSent ? '&emailed=1' : '')); exit;
    }
    if ($act === 'delete_link') {
        q("DELETE FROM prebooking_links WHERE pl_id=? AND used_at IS NULL", [(int)$_POST['pl_id']]);
        header("Location: /admin/prebook-links.php?deleted=1"); exit;
    }
    if ($act === 'edit_link') {
        $plid = (int)($_POST['pl_id'] ?? 0);
        $exp  = !empty($_POST['expires_at']) ? $_POST['expires_at'] . ' 23:59:59' : null;
        q("UPDATE prebooking_links SET custom_hourly_gross=?, duration=?, voucher_code=?, service_type=?, expires_at=?, notes=?, travel_tickets=?, travel_ticket_price=? WHERE pl_id=? AND used_at IS NULL", [
            !empty($_POST['custom_hourly_gross']) ? (float)$_POST['custom_hourly_gross'] : null,
            (int)($_POST['duration'] ?? 2),
            trim($_POST['voucher_code'] ?? '') ?: null,
            $_POST['service_type'] ?? 'home_care',
            $exp,
            trim($_POST['notes'] ?? '') ?: null,
            max(0, (int)($_POST['travel_tickets'] ?? 0)),
            max(0, (float)($_POST['travel_ticket_price'] ?? 3.80)),
            $plid
        ]);
        header("Location: /admin/prebook-links.php?edited=1"); exit;
    }
    if ($act === 'send_email' && function_exists('sendEmail')) {
        $pl = one("SELECT * FROM prebooking_links WHERE pl_id=?", [(int)$_POST['pl_id']]);
        if ($pl && $pl['email']) {
            $link = 'https://app.' . SITE_DOMAIN . '/p/' . $pl['token'];
            $html = "<p>Hallo " . e($pl['name'] ?? '') . ",</p><p>Über diesen persönlichen Link können Sie Ihren Termin direkt bei Fleckfrei buchen — Ihre Daten sind bereits vorausgefüllt:</p><p><a href=\"$link\" style=\"background:<?= BRAND ?>;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:700\">Jetzt Termin buchen →</a></p><p style=\"color:#666;font-size:12px\">Link gültig bis " . date('d.m.Y', strtotime($pl['expires_at'])) . "</p>";
            sendEmail($pl['email'], 'Ihr persönlicher Buchungs-Link — Fleckfrei', $html, null, 'booking');
            header("Location: /admin/prebook-links.php?sent=1"); exit;
        }
    }
}

$links = all("SELECT pl.*, (SELECT j_id FROM jobs j WHERE j.j_id=pl.created_job_id) AS job_link FROM prebooking_links pl ORDER BY pl_id DESC LIMIT 100");
$districts = all("SELECT name FROM berlin_districts ORDER BY sort_order, name");
include __DIR__ . '/../includes/layout.php';
?>

<?php if (!empty($_GET['created'])):
  $newToken = $_GET['created'];
  $newLink = 'https://app.' . SITE_DOMAIN . '/p/' . $newToken;
?>
<div class="bg-green-50 border-2 border-green-300 rounded-xl p-4 mb-6">
  <div class="font-bold text-green-900 mb-2">✅ Prebooking-Link erstellt<?php if (!empty($_GET['emailed'])): ?> <span class="text-xs font-normal ml-2 text-blue-700">📧 Email an Kunden gesendet</span><?php endif; ?></div>
  <div class="flex items-center gap-2 flex-wrap">
    <input type="text" value="<?= e($newLink) ?>" readonly class="flex-1 min-w-[240px] px-3 py-2 bg-white border rounded-lg font-mono text-xs" onclick="this.select()"/>
    <a href="<?= e($newLink) ?>" target="_blank" class="px-3 py-2 bg-brand text-white rounded-lg text-sm font-semibold hover:bg-brand-dark">👀 Als Kunde öffnen (neuer Tab)</a>
    <button onclick="navigator.clipboard.writeText('<?= e($newLink) ?>').then(()=>this.textContent='✓ kopiert')" class="px-3 py-2 bg-gray-700 text-white rounded-lg text-sm font-semibold">📋 Kopieren</button>
    <a href="https://wa.me/?text=<?= urlencode($newLink) ?>" target="_blank" class="px-3 py-2 bg-green-600 text-white rounded-lg text-sm font-semibold">WhatsApp</a>
  </div>
</div>
<?php endif; ?>
<?php if (!empty($_GET['deleted'])): ?><div class="bg-yellow-50 border border-yellow-200 text-yellow-800 px-4 py-3 rounded-xl mb-4">Link gelöscht.</div><?php endif; ?>
<?php if (!empty($_GET['sent'])): ?><div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4">📧 Email mit Link versendet.</div><?php endif; ?>

<!-- CREATE FORM -->
<div class="bg-white rounded-xl border p-5 mb-6">
  <h3 class="font-bold text-lg mb-3">🔗 Neuer Prebooking-Link</h3>
  <p class="text-sm text-gray-500 mb-4">Für Neukunden die noch nicht in der DB sind. Erstelle einen personalisierten Link mit vorausgefüllten Daten. Kunde klickt → direkte Buchung → Kundenkonto wird automatisch angelegt.</p>
  <form method="POST" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3" x-data="prebookForm()">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="create_link"/>
    <div class="relative">
      <label class="block text-xs text-gray-500 mb-1">Name <span class="text-[10px] text-brand">(Autocomplete aus DB)</span></label>
      <input name="name" x-model="f.name" @input.debounce.250ms="searchCust(f.name)" @focus="if(f.name.length>=2)open=true" @click.away="open=false" class="w-full px-3 py-2 border rounded-lg text-sm" placeholder="Kathrin Weidner · oder aus DB"/>
      <div x-show="open && results.length" x-cloak class="absolute top-full left-0 right-0 mt-1 bg-white border-2 border-brand/40 rounded-lg shadow-lg z-50 max-h-72 overflow-y-auto">
        <template x-for="c in results" :key="c.customer_id">
          <button type="button" @click="prefill(c)" class="w-full text-left px-3 py-2 hover:bg-brand-light border-b last:border-0 text-sm">
            <div class="font-semibold"><span x-text="c.name + ' ' + (c.surname || '')"></span> <span class="text-[10px] text-gray-400">#<span x-text="c.customer_id"></span></span></div>
            <div class="text-xs text-gray-500" x-text="c.email + ' · ' + (c.street || '—') + ' ' + (c.city || '')"></div>
          </button>
        </template>
      </div>
      <div x-show="f.customer_id" class="mt-1 text-[11px] text-brand font-semibold">✓ Bestehender Kunde #<span x-text="f.customer_id"></span> ausgewählt — wird verlinkt</div>
    </div>
    <div><label class="block text-xs text-gray-500 mb-1">Email <span class="text-red-500">*</span></label><input type="email" name="email" x-model="f.email" required class="w-full px-3 py-2 border rounded-lg text-sm" placeholder="kathrin@example.de"/></div>
    <div><label class="block text-xs text-gray-500 mb-1">Telefon</label><input name="phone" x-model="f.phone" class="w-full px-3 py-2 border rounded-lg text-sm" placeholder="+49 176 ..."/></div>
    <div class="lg:col-span-2 relative">
      <label class="block text-xs text-gray-500 mb-1">Straße & Nr. <span class="text-[10px] text-brand">(OpenStreetMap)</span></label>
      <input name="street" x-model="f.street" @input.debounce.400ms="searchAddr(f.street)" @click.away="addrOpen=false" class="w-full px-3 py-2 border rounded-lg text-sm" placeholder="Alexanderplatz 1"/>
      <div x-show="addrOpen && addrResults.length" x-cloak class="absolute top-full left-0 right-0 mt-1 bg-white border-2 border-brand/40 rounded-lg shadow-lg z-50 max-h-72 overflow-y-auto">
        <template x-for="a in addrResults" :key="a.place_id">
          <button type="button" @click="pickAddr(a)" class="w-full text-left px-3 py-2 hover:bg-brand-light border-b last:border-0 text-sm">
            <div class="font-semibold" x-text="a.display_name.split(',').slice(0,2).join(', ')"></div>
            <div class="text-xs text-gray-500" x-text="a.display_name.split(',').slice(2).join(', ')"></div>
          </button>
        </template>
      </div>
    </div>
    <div><label class="block text-xs text-gray-500 mb-1">PLZ</label><input name="plz" x-model="f.plz" maxlength="5" class="w-full px-3 py-2 border rounded-lg text-sm" placeholder="10178"/></div>
    <div><label class="block text-xs text-gray-500 mb-1">Stadt</label><input name="city" x-model="f.city" class="w-full px-3 py-2 border rounded-lg text-sm"/></div>
    <div>
      <label class="block text-xs text-gray-500 mb-1">Bezirk</label>
      <select name="district" class="w-full px-3 py-2 border rounded-lg text-sm">
        <option value="">—</option>
        <?php foreach ($districts as $d): ?><option value="<?= e($d['name']) ?>"><?= e($d['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="block text-xs text-gray-500 mb-1">Service</label>
      <select name="service_type" class="w-full px-3 py-2 border rounded-lg text-sm">
        <option value="home_care">🏠 Home Care</option>
        <option value="str">🏨 Short-Term Rental</option>
        <option value="office">🏢 Business</option>
      </select>
    </div>
    <div><label class="block text-xs text-gray-500 mb-1">Dauer (h)</label><input type="number" name="duration" value="3" min="2" max="12" class="w-full px-3 py-2 border rounded-lg text-sm"/></div>
    <div>
      <label class="block text-xs font-semibold text-brand mb-1">💰 Eigener Stundensatz (€/h brutto)</label>
      <div class="relative">
        <input type="number" step="0.01" name="custom_hourly_gross" id="c_rate" oninput="updNetto('c')" class="w-full px-3 py-2 pr-12 border-2 border-brand/30 rounded-lg text-sm bg-brand-light/30" placeholder="z.B. 25.00 — leer = Standard"/>
        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-500 pointer-events-none">€/h</span>
      </div>
      <div class="text-[10px] text-brand-dark mt-0.5">Überschreibt Preis aus pricing.php · <span id="c_netto" class="font-mono">netto —</span> (MwSt <?= (int)(TAX_RATE*100) ?>%)</div>
    </div>
    <div><label class="block text-xs text-gray-500 mb-1">Voucher-Code (optional, zusätzlich)</label><input name="voucher_code" class="w-full px-3 py-2 border rounded-lg text-sm font-mono" placeholder="WELCOME10"/></div>
    <div>
      <label class="block text-xs font-semibold text-orange-700 mb-1">🚇 BVG-Tickets (zahlt Kunde direkt an Partner)</label>
      <div class="flex gap-1">
        <input type="number" name="travel_tickets" value="0" min="0" class="w-20 px-2 py-2 border rounded-lg text-sm"/>
        <span class="py-2 text-xs text-gray-500">×</span>
        <input type="number" step="0.01" name="travel_ticket_price" value="3.80" class="w-24 px-2 py-2 border rounded-lg text-sm"/>
        <span class="py-2 text-xs text-gray-500">€</span>
      </div>
      <div class="text-[10px] text-orange-700 mt-0.5">z.B. 5 Tickets × 3,80 € = 19,00 € Cash an Partner</div>
    </div>
    <div><label class="block text-xs text-gray-500 mb-1">Gültig bis</label><input type="date" name="expires_at" value="<?= date('Y-m-d', strtotime('+30 days')) ?>" class="w-full px-3 py-2 border rounded-lg text-sm"/></div>
    <div class="lg:col-span-3">
      <label class="block text-xs text-gray-500 mb-1">Eigener Slug <span class="text-gray-400">(optional — sonst auto aus Name)</span></label>
      <div class="flex items-center gap-2">
        <span class="text-xs text-gray-500 font-mono">app.fleckfrei.de/p/</span>
        <input name="custom_slug" pattern="[a-zA-Z0-9_-]{3,40}" class="flex-1 px-3 py-2 border rounded-lg text-sm font-mono lowercase" placeholder="kathrin-weidner"/>
      </div>
    </div>
    <div class="lg:col-span-3"><label class="block text-xs text-gray-500 mb-1">Interne Notiz</label><input name="notes" class="w-full px-3 py-2 border rounded-lg text-sm" placeholder="z.B. Probereinigung nach Email-Anfrage 14.04"/></div>
    <div class="lg:col-span-3">
      <label class="flex items-center gap-2 mb-3 p-3 bg-blue-50 border border-blue-200 rounded-lg cursor-pointer">
        <input type="checkbox" name="auto_send_email" value="1" checked class="rounded"/>
        <span class="text-sm"><b>📧 Link direkt per Email senden</b> <span class="text-gray-500">(an Kunden-Email-Feld oben)</span></span>
      </label>
      <button type="submit" class="px-5 py-2.5 bg-brand text-white rounded-xl font-semibold hover:bg-brand-dark">🔗 Link erstellen</button>
      <span class="ml-3 text-xs text-gray-500">Token wird automatisch generiert. Kunde sieht prefillten Booking-Flow.</span>
    </div>
  </form>
</div>

<!-- LIST -->
<div class="bg-white rounded-xl border">
  <div class="p-5 border-b"><h3 class="font-bold">Aktive Links (<?= count($links) ?>)</h3></div>
  <div class="overflow-x-auto">
    <table class="w-full text-xs">
      <thead class="bg-gray-50"><tr>
        <th class="px-3 py-2 text-left">Erstellt</th>
        <th class="px-3 py-2 text-left">Email / Name</th>
        <th class="px-3 py-2 text-left">Service</th>
        <th class="px-3 py-2 text-left">Gültig bis</th>
        <th class="px-3 py-2 text-left">Status</th>
        <th class="px-3 py-2 text-left">Link</th>
        <th class="px-3 py-2 text-left">Aktion</th>
      </tr></thead>
      <tbody class="divide-y">
      <?php foreach ($links as $pl):
        $link = 'https://app.' . SITE_DOMAIN . '/p/' . $pl['token'];
        $status = $pl['used_at'] ? 'used' : (strtotime($pl['expires_at']) < time() ? 'expired' : 'active');
      ?>
      <tr class="hover:bg-gray-50">
        <td class="px-3 py-2 text-gray-500"><?= date('d.m. H:i', strtotime($pl['created_at'])) ?></td>
        <td class="px-3 py-2"><?= e($pl['email'] ?: '—') ?><?php if ($pl['name']): ?><div class="text-gray-500"><?= e($pl['name']) ?></div><?php endif; ?></td>
        <td class="px-3 py-2"><?= e($pl['service_type']) ?> · <?= e($pl['duration']) ?>h</td>
        <td class="px-3 py-2"><?= $pl['expires_at'] ? date('d.m.Y', strtotime($pl['expires_at'])) : '∞' ?></td>
        <td class="px-3 py-2">
          <?php if ($status==='used'): ?><span class="px-2 py-0.5 bg-green-100 text-green-700 rounded font-semibold">✓ genutzt</span><?= $pl['created_job_id'] ? ' #'.$pl['created_job_id'] : '' ?>
          <?php elseif ($status==='expired'): ?><span class="px-2 py-0.5 bg-gray-100 text-gray-600 rounded">Abgelaufen</span>
          <?php else: ?><span class="px-2 py-0.5 bg-blue-100 text-blue-700 rounded font-semibold">⏳ aktiv</span>
          <?php endif; ?>
        </td>
        <td class="px-3 py-2">
          <div class="font-mono text-[10px] text-gray-600">/p/<?= e($pl['token']) ?></div>
          <button onclick="navigator.clipboard.writeText('<?= e($link) ?>').then(()=>this.textContent='✓ kopiert')" class="text-brand hover:underline text-[11px]">📋 kopieren</button>
        </td>
        <td class="px-3 py-2"><div class="flex gap-1">
          <?php if ($status !== 'expired'): ?>
          <a href="<?= e($link) ?>" target="_blank" class="px-2 py-1 text-[10px] bg-blue-50 text-blue-700 rounded" title="Als Kunde öffnen">👀 test</a>
          <?php endif; ?>
          <?php if ($status==='active'): ?>
          <button type="button" onclick="openEdit(<?= e(json_encode($pl)) ?>)" class="px-2 py-1 text-[10px] bg-brand/10 text-brand rounded">✏️ edit</button>
          <?php endif; ?>
          <?php if ($status==='active' && $pl['email']): ?>
          <form method="POST" class="inline"><?= csrfField() ?><input type="hidden" name="action" value="send_email"/><input type="hidden" name="pl_id" value="<?= $pl['pl_id'] ?>"/><button class="px-2 py-1 text-[10px] bg-brand/10 text-brand rounded" title="Standard-Email senden">📧 std</button></form>
          <button type="button" onclick="openAiMail(<?= e(json_encode(['pl_id'=>$pl['pl_id'],'name'=>$pl['name'],'email'=>$pl['email']])) ?>)" class="px-2 py-1 text-[10px] bg-purple-100 text-purple-700 rounded" title="KI-generierte Email mit Preview">✨ KI-Mail</button>
          <?php endif; ?>
          <?php if ($status !== 'used'): ?>
          <form method="POST" class="inline" onsubmit="return confirm('Link löschen?')"><?= csrfField() ?><input type="hidden" name="action" value="delete_link"/><input type="hidden" name="pl_id" value="<?= $pl['pl_id'] ?>"/><button class="px-2 py-1 text-[10px] bg-red-50 text-red-600 rounded">🗑</button></form>
          <?php endif; ?>
        </div></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($links)): ?><tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">Noch keine Prebooking-Links. Erstell den ersten oben.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>


<!-- Edit-Modal -->
<div id="editModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center p-4 z-50" onclick="if(event.target===this)this.classList.add('hidden')">
  <div class="bg-white rounded-2xl p-6 w-full max-w-lg">
    <div class="flex items-center justify-between mb-4">
      <h3 class="font-bold text-lg">✏️ Link bearbeiten</h3>
      <button onclick="document.getElementById('editModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600">&times;</button>
    </div>
    <form method="POST" class="space-y-3">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="edit_link"/>
      <input type="hidden" id="e_pl_id" name="pl_id"/>
      <div class="text-xs text-gray-500">Slug: <code class="font-mono text-brand" id="e_slug"></code> · nicht änderbar</div>
      <div>
        <label class="block text-xs font-semibold text-brand mb-1">💰 Eigener Stundensatz (€/h brutto) — leer = Standard</label>
        <div class="relative">
          <input type="number" step="0.01" name="custom_hourly_gross" id="e_rate" oninput="updNetto('e')" class="w-full px-3 py-2 pr-12 border-2 border-brand/30 rounded-lg text-sm bg-brand-light/30"/>
          <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-500 pointer-events-none">€/h</span>
        </div>
        <div class="text-[10px] text-brand-dark mt-0.5"><span id="e_netto" class="font-mono">netto —</span> (MwSt <?= (int)(TAX_RATE*100) ?>%)</div>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-xs text-gray-500 mb-1">Dauer (h)</label>
          <input type="number" name="duration" id="e_dur" min="1" max="12" class="w-full px-3 py-2 border rounded-lg text-sm"/>
        </div>
        <div>
          <label class="block text-xs text-gray-500 mb-1">Service</label>
          <select name="service_type" id="e_svc" class="w-full px-3 py-2 border rounded-lg text-sm">
            <option value="home_care">🏠 Home Care</option>
            <option value="str">🏨 Short-Term Rental</option>
            <option value="office">🏢 Business</option>
          </select>
        </div>
      </div>
      <div>
        <label class="block text-xs text-gray-500 mb-1">Voucher-Code (optional)</label>
        <input name="voucher_code" id="e_voucher" class="w-full px-3 py-2 border rounded-lg text-sm font-mono"/>
      </div>
      <div>
        <label class="block text-xs text-gray-500 mb-1">Gültig bis</label>
        <input type="date" name="expires_at" id="e_exp" class="w-full px-3 py-2 border rounded-lg text-sm"/>
      </div>
      <div>
        <label class="block text-xs font-semibold text-orange-700 mb-1">🚇 BVG-Tickets (Cash direkt an Partner)</label>
        <div class="flex gap-1">
          <input type="number" name="travel_tickets" id="e_tickets" min="0" class="w-20 px-2 py-2 border rounded-lg text-sm"/>
          <span class="py-2 text-xs">×</span>
          <input type="number" step="0.01" name="travel_ticket_price" id="e_ticket_price" class="w-24 px-2 py-2 border rounded-lg text-sm"/>
          <span class="py-2 text-xs">€</span>
        </div>
      </div>
      <div>
        <label class="block text-xs text-gray-500 mb-1">Interne Notiz</label>
        <input name="notes" id="e_notes" class="w-full px-3 py-2 border rounded-lg text-sm"/>
      </div>
      <div class="flex gap-2 pt-2">
        <button type="button" onclick="document.getElementById('editModal').classList.add('hidden')" class="flex-1 px-4 py-2 border rounded-lg">Abbrechen</button>
        <button type="submit" class="flex-1 px-4 py-2 bg-brand text-white rounded-lg font-semibold">💾 Speichern</button>
      </div>
    </form>
  </div>
</div>

<div id="aiMailModal" class="hidden fixed inset-0 bg-black/50 z-50 items-center justify-center p-4">
  <div class="bg-white rounded-xl max-w-4xl w-full max-h-[92vh] overflow-auto">
    <div class="sticky top-0 bg-white border-b px-5 py-3 flex justify-between items-center">
      <h3 class="font-bold text-lg">✨ KI-Email schreiben <span class="text-xs font-normal text-gray-500 ml-2" id="m_to">—</span></h3>
      <button type="button" onclick="closeAiMail()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
    </div>
    <form method="POST" class="p-5 space-y-3" onsubmit="return confirm('Email jetzt senden?')">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="send_custom_email"/>
      <input type="hidden" name="pl_id" id="m_plid"/>
      <div class="flex items-center gap-2">
        <button type="button" onclick="regenAiMail()" id="m_genBtn" class="px-3 py-2 bg-purple-600 text-white rounded-lg text-sm font-semibold hover:bg-purple-700 disabled:opacity-50">🔄 Neu generieren</button>
        <span id="m_status" class="text-xs text-gray-500"></span>
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-700 mb-1">Betreff</label>
        <input name="subject" id="m_subj" required class="w-full px-3 py-2 border rounded-lg text-sm" maxlength="140"/>
      </div>
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
        <div>
          <label class="block text-xs font-semibold text-gray-700 mb-1">Body (HTML — bearbeitbar)</label>
          <textarea name="body" id="m_body" required rows="14" oninput="renderPreview()" class="w-full px-3 py-2 border rounded-lg text-xs font-mono"></textarea>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-700 mb-1">Preview (live)</label>
          <div id="m_preview" class="border rounded-lg p-4 bg-gray-50 text-sm max-h-[400px] overflow-auto prose prose-sm"></div>
        </div>
      </div>
      <div class="flex gap-2 pt-2">
        <button type="button" onclick="closeAiMail()" class="flex-1 px-4 py-2 border rounded-lg">Abbrechen</button>
        <button type="submit" class="flex-1 px-4 py-2 bg-brand text-white rounded-lg font-semibold">📧 Jetzt senden</button>
      </div>
    </form>
  </div>
</div>

<script>
function openAiMail(info) {
  document.getElementById('aiMailModal').classList.remove('hidden');
  document.getElementById('aiMailModal').classList.add('flex');
  document.getElementById('m_plid').value = info.pl_id;
  document.getElementById('m_to').textContent = '→ ' + (info.name || '') + ' <' + info.email + '>';
  document.getElementById('m_subj').value = '';
  document.getElementById('m_body').value = '';
  document.getElementById('m_preview').innerHTML = '<div class="text-gray-400">Wird generiert…</div>';
  regenAiMail();
}
function closeAiMail() {
  document.getElementById('aiMailModal').classList.add('hidden');
  document.getElementById('aiMailModal').classList.remove('flex');
}
async function regenAiMail() {
  const plid = document.getElementById('m_plid').value;
  const btn = document.getElementById('m_genBtn');
  const status = document.getElementById('m_status');
  btn.disabled = true; status.textContent = 'KI denkt nach…';
  try {
    const fd = new FormData();
    fd.append('action', 'generate_email_ai');
    fd.append('pl_id', plid);
    fd.append('_csrf', '<?= csrfToken() ?>');
    const r = await fetch('/admin/prebook-links.php', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.error) { status.textContent = '❌ ' + d.error; btn.disabled = false; return; }
    document.getElementById('m_subj').value = d.subject || '';
    document.getElementById('m_body').value = d.body || '';
    renderPreview();
    status.textContent = '✅ Fertig — bearbeite nach Bedarf';
  } catch (e) {
    status.textContent = '❌ Fehler: ' + e.message;
  }
  btn.disabled = false;
}
function renderPreview() {
  document.getElementById('m_preview').innerHTML = document.getElementById('m_body').value || '<div class="text-gray-400">leer</div>';
}

function prebookForm() {
  return {
    f: { name:'', email:'', phone:'', street:'', plz:'', city:'Berlin', customer_id:'' },
    results: [], open: false,
    addrResults: [], addrOpen: false,
    async searchCust(q) {
      if (!q || q.length < 2) { this.results = []; this.open = false; return; }
      try {
        const r = await fetch('/api/customer-search.php?q=' + encodeURIComponent(q));
        const d = await r.json();
        this.results = d.results || [];
        this.open = this.results.length > 0;
      } catch (e) { this.results = []; this.open = false; }
    },
    prefill(c) {
      this.f.name = (c.name || '') + (c.surname ? ' ' + c.surname : '');
      this.f.email = c.email || '';
      this.f.phone = c.phone || '';
      this.f.street = c.street || '';
      this.f.plz = c.plz || '';
      this.f.city = c.city || 'Berlin';
      this.f.customer_id = c.customer_id || '';
      this.open = false;
    },
    async searchAddr(q) {
      if (!q || q.length < 3) { this.addrResults = []; this.addrOpen = false; return; }
      try {
        const url = 'https://nominatim.openstreetmap.org/search?format=json&addressdetails=1&limit=5&countrycodes=de,at,ch&q=' + encodeURIComponent(q);
        const r = await fetch(url, { headers: { 'User-Agent': 'Fleckfrei-Admin' } });
        const d = await r.json();
        this.addrResults = d || [];
        this.addrOpen = this.addrResults.length > 0;
      } catch (e) { this.addrResults = []; }
    },
    pickAddr(a) {
      const ad = a.address || {};
      this.f.street = ((ad.road || ad.pedestrian || '') + ' ' + (ad.house_number || '')).trim();
      this.f.plz = ad.postcode || '';
      this.f.city = ad.city || ad.town || ad.village || ad.municipality || 'Berlin';
      this.addrOpen = false;
    },
  };
}
const TAX_RATE = <?= (float)TAX_RATE ?>;
function updNetto(prefix) {
  const i = document.getElementById(prefix + '_rate');
  const o = document.getElementById(prefix + '_netto');
  if (!i || !o) return;
  const g = parseFloat(i.value);
  if (!g || g <= 0) { o.textContent = 'netto —'; return; }
  const n = g / (1 + TAX_RATE);
  o.textContent = 'netto ' + n.toFixed(2).replace('.', ',') + ' €/h';
}
function openEdit(pl) {
  document.getElementById('editModal').classList.remove('hidden');
  document.getElementById('editModal').classList.add('flex');
  document.getElementById('e_pl_id').value = pl.pl_id;
  document.getElementById('e_slug').textContent = '/p/' + pl.token;
  document.getElementById('e_rate').value = pl.custom_hourly_gross || '';
  updNetto('e');
  document.getElementById('e_dur').value = pl.duration || 3;
  document.getElementById('e_svc').value = pl.service_type || 'home_care';
  document.getElementById('e_voucher').value = pl.voucher_code || '';
  document.getElementById('e_exp').value = pl.expires_at ? pl.expires_at.slice(0,10) : '';
  document.getElementById('e_notes').value = pl.notes || '';
  document.getElementById('e_tickets').value = pl.travel_tickets || 0;
  document.getElementById('e_ticket_price').value = pl.travel_ticket_price || '3.80';
}
</script>

<?php include __DIR__.'/../includes/footer.php'; ?>
