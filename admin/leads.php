<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Leads (Neue Kunden)'; $page = 'leads';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { header('Location: /admin/leads.php'); exit; }
    $act = $_POST['action'] ?? '';
    $lid = (int)($_POST['lead_id'] ?? 0);

    // KI-Pitch generieren (AJAX → JSON)
    if ($act === 'generate_pitch' && $lid) {
        header('Content-Type: application/json; charset=utf-8');
        require_once __DIR__ . '/../includes/llm-helpers.php';
        $lead = one("SELECT * FROM leads WHERE lead_id=?", [$lid]);
        if (!$lead) { echo json_encode(['error' => 'not found']); exit; }

        $catPitch = [
            'airbnb' => "Airbnb/Ferienwohnungs-Host in Berlin. Fleckfrei ist spezialisiert auf SCHNELLE Turnover-Reinigung zwischen Gästen (11-16 Uhr STR-Fenster), 5-Sterne-Quality-Checks mit Foto-Beweis, flexible Last-Minute-Buchung, Wäsche-Service optional. Konzentriere auf: Review-Boost, Zeitersparnis, kein Stress bei kurzen Wechsel-Fenstern.",
            'cohost' => "Airbnb Co-Host / Vermieter-Support. Fleckfrei bietet Full-Service Ferienwohnungs-Management: Turnover-Reinigung, Gäste-Kommunikation, Check-In/Check-Out via Nuki-Schloss, Wäsche, Beschwerde-Handling. Für Vermieter die ihre Zeit zurück wollen.",
            'haushalt' => "Privathaushalt in Berlin sucht verlässliche Putz-/Haushaltshilfe. Fleckfrei bietet regelmäßige wöchentliche/14-tägliche/monatliche Reinigung mit festem Partner, Loyalty-Bonus nach 3 Monaten, transparente Preise, alle Versicherungen. Konzentriere auf: Verlässlichkeit, persönlicher fester Ansprechpartner, keine wechselnden Gesichter.",
            'buero' => "B2B Büroreinigung Berlin. Fleckfrei reinigt Büros, Praxen, Kanzleien abends/morgens außerhalb der Geschäftszeiten, monatliche Rechnung, Versicherung, Schlüssel-Übergabe dokumentiert. Konzentriere auf: Zuverlässigkeit, Diskretion, Bürozeiten-freundlich.",
            'event' => "Event- & Baustellenreinigung in Berlin. Fleckfrei kommt kurzfristig, bringt eigenes Equipment, arbeitet auch Wochenende. Konzentriere auf: Schnelligkeit, Flexibilität.",
            'umzug' => "Endreinigung nach Umzug / Mietwohnung. Fleckfrei macht die besen-reine Übergabe, garantiert vom Vermieter akzeptiert. Konzentriere auf: Geld-zurück-Garantie bei nicht-Abnahme.",
        ];

        $name = $lead['name'] ?: 'Lead';
        $snippet = $lead['raw_snippet'] ?: '';
        $sourceUrl = $lead['source_url'] ?? '';
        $cat = $lead['category'] ?? 'haushalt';
        $pitchCtx = $catPitch[$cat] ?? $catPitch['haushalt'];

        $prompt = "Du schreibst eine personalisierte Verkaufs-Email von Fleckfrei (Reinigungs-Service Berlin, fleckfrei.de) an einen potenziellen Kunden. "
                . "Wir haben seine Anzeige/Post gefunden und wollen ihn überzeugen, uns zu wählen.\n\n"
                . "Kunde-Info:\n"
                . "- Titel/Name: $name\n"
                . "- Anzeigen-Text: " . substr($snippet, 0, 500) . "\n"
                . "- Quelle: $sourceUrl\n"
                . "- Kategorie: $cat\n\n"
                . "Kontext zu Fleckfrei für diese Kategorie:\n$pitchCtx\n\n"
                . "Gib AUSSCHLIESSLICH JSON zurück, keine Markdown:\n"
                . '{"subject": "...", "body": "<p>...</p>HTML-Body mit <p>, <b>, <a>-Tags"}' . "\n\n"
                . "- Subject: max 70 Zeichen, persönlich, mit konkretem Nutzen (nicht generisch)\n"
                . "- Body: 4-5 kurze Absätze, warm und menschlich, keine Floskeln:\n"
                . "  1. Persönlicher Einstieg (bezug auf seine Anzeige — wenn Name bekannt, nutze ihn)\n"
                . "  2. Was Fleckfrei für SEINEN Fall konkret anbietet (kategorie-spezifisch)\n"
                . "  3. 2-3 konkrete Benefits als Liste oder Fließtext (keine Buzzwords — konkret)\n"
                . "  4. Ein grosser Button-Link: <a href=\"{{LINK}}\" style=\"background:#2E7D6B;color:#fff;padding:14px 28px;border-radius:10px;text-decoration:none;font-weight:700;display:inline-block\">Jetzt unverbindliches Angebot ansehen →</a>\n"
                . "  5. Signatur: 'Viele Grüße, Fleckfrei-Team · 030-xxx · info@fleckfrei.de'\n"
                . "- Platzhalter {{LINK}} wird später durch den persönlichen Buchungs-Link ersetzt.\n"
                . "- Keine aggressive Sprache, kein 'gratis', keine Emojis im Subject.";

        $r = groq_chat($prompt, 900);
        $content = trim($r['content'] ?? '');
        $content = preg_replace('/^```(?:json)?|```$/m', '', $content);
        $parsed = json_decode(trim($content), true);
        if (!is_array($parsed) || empty($parsed['subject']) || empty($parsed['body'])) {
            echo json_encode(['error' => 'KI-Antwort nicht lesbar', 'raw' => substr($content, 0, 400)]); exit;
        }
        echo json_encode(['subject' => $parsed['subject'], 'body' => $parsed['body']]);
        exit;
    }

    // Pitch senden + Lead konvertieren in einem Schritt
    if ($act === 'send_pitch' && $lid) {
        global $db;
        $lead = one("SELECT * FROM leads WHERE lead_id=?", [$lid]);
        $subject = trim($_POST['subject'] ?? '');
        $body = trim($_POST['body'] ?? '');
        if (!$lead || $subject === '' || $body === '' || empty($lead['email'])) {
            header('Location: /admin/leads.php?err=missing'); exit;
        }
        // Kunde + Prebook-Link anlegen (gleiche Logik wie convert)
        $name = trim($lead['name']) ?: 'Lead-Kunde';
        $email = strtolower(trim($lead['email']));
        $phone = trim($lead['phone'] ?? '');
        $typeMap = ['airbnb'=>'Airbnb','cohost'=>'Host','buero'=>'Company','haushalt'=>'Private Person','event'=>'Private Person','umzug'=>'Private Person'];
        $cType = $typeMap[$lead['category']] ?? 'Private Person';
        $cust = one("SELECT * FROM customer WHERE LOWER(email)=?", [$email]);
        if ($cust) { $cid = (int)$cust['customer_id']; }
        else {
            q("INSERT INTO customer (name, email, phone, customer_type, password, status, email_permissions, notes)
               VALUES (?, ?, ?, ?, '0000', 1, 'all', ?)",
               [$name, $email, $phone ?: null, $cType, "Aus Lead #$lid (KI-Pitch) · Quelle: " . ($lead['source'] ?? '—')]);
            $cid = (int) $db->lastInsertId();
            q("INSERT IGNORE INTO users (email, type) VALUES (?, 'customer')", [$email]);
        }
        $slugSrc = strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($name . '-' . $cid)));
        $slugSrc = trim(substr($slugSrc, 0, 40), '-') ?: 'lead-' . $cid;
        $token = $slugSrc;
        $n = 0;
        while (val("SELECT pl_id FROM prebooking_links WHERE token=?", [$token])) {
            $token = $slugSrc . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
            if (++$n > 5) break;
        }
        $svcType = ['airbnb'=>'str','cohost'=>'str','buero'=>'office','haushalt'=>'home_care'][$lead['category']] ?? 'home_care';
        q("INSERT INTO prebooking_links (token, email, name, phone, service_type, duration, created_by, expires_at)
           VALUES (?, ?, ?, ?, ?, 3, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))",
           [$token, $email, $name, $phone ?: null, $svcType, $_SESSION['uemail'] ?? 'admin']);

        $link = 'https://app.' . SITE_DOMAIN . '/p/' . $token;
        $body = str_replace('{{LINK}}', $link, $body);

        if (function_exists('sendEmail')) {
            sendEmail($email, $subject, $body, null, 'lead_pitch');
        }
        q("UPDATE leads SET status='contacted', contacted_at=NOW() WHERE lead_id=?", [$lid]);
        if (function_exists('audit')) audit('pitch_sent', 'leads', $lid, "→ customer #$cid + /p/$token · Subject: " . substr($subject, 0, 60));
        header("Location: /admin/leads.php?saved=1&sent=1"); exit;
    }

    // Junk-Purge: löscht alle Leads von Behörden/Job-Boards/Konkurrenten + alte kontaktlose
    if ($act === 'purge_junk') {
        $junkDomains = [
            'rki.de','bmg.bund.de','bundesregierung.de','gesetze-im-internet.de',
            'jooble.org','indeed.com','indeed.de','stepstone.de','stellenanzeigen.de',
            'monster.de','xing.com','linkedin.com','adzuna.de','kimeta.de','meinestadt.de',
            'dpolg.berlin','dpolg.de','verdi.de','dgb.de',
            'desomax.de','alfa24.de','helpling.de','book-a-tiger.com','jobruf.de',
            'gelbeseiten.de','dasoertliche.de','11880.com',
            'praxiswaesche.de','wikipedia.org','youtube.com','pinterest.',
        ];
        $deleted = 0;
        // 1) Domain-basiert löschen
        foreach ($junkDomains as $dom) {
            $n = q("DELETE FROM leads WHERE source_url LIKE ? OR source LIKE ?", ["%$dom%", "%$dom%"]);
            // rowCount via PDOStatement — q() returns it in some codebases; ignore result, DB wil do it
        }
        $deleted = (int) val("SELECT ROW_COUNT()"); // letzte Operation

        // 2) Alte Leads ohne Kontaktdaten + status=new älter 7 Tage
        q("DELETE FROM leads WHERE status='new' AND (email IS NULL OR email='') AND (phone IS NULL OR phone='') AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");

        // 3) Alle nicht-konvertierten älter 60 Tage
        q("DELETE FROM leads WHERE status='new' AND created_at < DATE_SUB(NOW(), INTERVAL 60 DAY)");

        header('Location: /admin/leads.php?saved=1&purged=1'); exit;
    }

    if ($act === 'delete_all_new') {
        q("DELETE FROM leads WHERE status='new'");
        header('Location: /admin/leads.php?saved=1&purged=1'); exit;
    }

    if ($act === 'add_manual') {
        $name = trim($_POST['m_name'] ?? '');
        if ($name !== '') {
            $cat = in_array($_POST['m_category'] ?? '', ['haushalt','airbnb','cohost','buero','event','umzug','other'], true) ? $_POST['m_category'] : 'other';
            $url = trim($_POST['m_url'] ?? '') ?: ('manual://' . time());
            q("INSERT INTO leads (source, source_url, category, name, email, phone, city, notes, raw_snippet, status) VALUES (?, ?, ?, ?, ?, ?, 'Berlin', ?, ?, 'new')",
              ['manual', $url, $cat, $name,
               strtolower(trim($_POST['m_email'] ?? '')) ?: null,
               trim($_POST['m_phone'] ?? '') ?: null,
               trim($_POST['m_notes'] ?? '') ?: null,
               trim($_POST['m_snippet'] ?? '') ?: null]);
        }
        header('Location: /admin/leads.php?saved=1'); exit;
    }

    if ($act === 'update_status' && $lid) {
        $status = in_array($_POST['status'] ?? '', ['new','contacted','converted','rejected'], true) ? $_POST['status'] : 'new';
        $contactedAt = $status === 'contacted' ? 'NOW()' : 'NULL';
        q("UPDATE leads SET status=?, contacted_at=" . ($status === 'contacted' ? 'NOW()' : 'contacted_at') . " WHERE lead_id=?", [$status, $lid]);
        audit('update', 'leads', $lid, "Status → $status");
        header('Location: /admin/leads.php?saved=1'); exit;
    }
    if ($act === 'delete' && $lid) {
        q("DELETE FROM leads WHERE lead_id=?", [$lid]);
        header('Location: /admin/leads.php?saved=1'); exit;
    }

    // 1-Klick-Conversion: Lead → Kunde + Prebook-Link + Redirect
    if ($act === 'convert' && $lid) {
        global $db;
        $lead = one("SELECT * FROM leads WHERE lead_id=?", [$lid]);
        if (!$lead) { header('Location: /admin/leads.php'); exit; }

        $name = trim($lead['name']) ?: 'Lead-Kunde';
        $email = strtolower(trim($lead['email'] ?? ''));
        $phone = trim($lead['phone'] ?? '');

        // Mapping Kategorie → customer_type
        $typeMap = ['airbnb'=>'Airbnb','buero'=>'Company','haushalt'=>'Private Person','event'=>'Private Person','umzug'=>'Private Person'];
        $cType = $typeMap[$lead['category']] ?? 'Private Person';

        // Existiert Kunde schon (email/phone)?
        $cust = null;
        if ($email) $cust = one("SELECT * FROM customer WHERE LOWER(email)=?", [$email]);
        if (!$cust && $phone) $cust = one("SELECT * FROM customer WHERE phone=?", [$phone]);

        if ($cust) {
            $cid = (int)$cust['customer_id'];
        } else {
            q("INSERT INTO customer (name, email, phone, customer_type, password, status, email_permissions, notes)
               VALUES (?, ?, ?, ?, '0000', 1, 'all', ?)",
               [$name, $email ?: null, $phone ?: null, $cType, "Aus Lead #$lid · Quelle: " . ($lead['source'] ?? '—')]);
            $cid = (int) $db->lastInsertId();
            if ($email) q("INSERT IGNORE INTO users (email, type) VALUES (?, 'customer')", [$email]);
        }

        // Prebook-Link generieren (falls noch keiner)
        $slugSrc = strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($name . '-' . $cid)));
        $slugSrc = trim(substr($slugSrc, 0, 40), '-') ?: 'lead-' . $cid;
        $token = $slugSrc;
        $n = 0;
        while (val("SELECT pl_id FROM prebooking_links WHERE token=?", [$token])) {
            $token = $slugSrc . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
            if (++$n > 5) break;
        }
        $svcType = ['airbnb'=>'str','buero'=>'office','haushalt'=>'home_care'][$lead['category']] ?? 'home_care';
        q("INSERT INTO prebooking_links (token, email, name, phone, service_type, duration, created_by, expires_at)
           VALUES (?, ?, ?, ?, ?, 3, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))",
           [$token, $email ?: null, $name, $phone ?: null, $svcType, $_SESSION['uemail'] ?? 'admin']);

        q("UPDATE leads SET status='converted', contacted_at=NOW() WHERE lead_id=?", [$lid]);
        if (function_exists('audit')) audit('convert', 'leads', $lid, "→ customer #$cid + prebook /p/$token");

        header("Location: /admin/view-customer.php?id=$cid&converted=1&token=" . urlencode($token));
        exit;
    }
}

$filter = $_GET['filter'] ?? 'new';
$category = $_GET['category'] ?? '';
$segment = $_GET['segment'] ?? ''; // B2B / B2C
$where = ['1=1'];
$params = [];
if ($filter !== 'all') {
    $where[] = 'status = ?';
    $params[] = $filter;
}
if ($category) {
    $where[] = 'category = ?';
    $params[] = $category;
}
if ($segment === 'B2B') {
    $where[] = "category IN ('buero','event')";
} elseif ($segment === 'B2C') {
    $where[] = "category IN ('haushalt','airbnb','cohost','umzug')";
}
$leads = all("SELECT * FROM leads WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC LIMIT 200", $params);

$counts = [];
foreach (['new','contacted','converted','rejected'] as $s) {
    $counts[$s] = (int) val("SELECT COUNT(*) FROM leads WHERE status=?", [$s]);
}
$counts['all'] = (int) val("SELECT COUNT(*) FROM leads");

$catLabels = [
    'haushalt' => '🏠 Haushalt',
    'airbnb' => '🌴 Airbnb',
    'cohost' => '🤝 Co-Host',
    'buero' => '🏢 Büro',
    'event' => '🎉 Event',
    'umzug' => '📦 Umzug',
    'other' => '📋 Sonstige',
];
$catSegment = ['haushalt'=>'B2C','airbnb'=>'B2C','cohost'=>'B2C','umzug'=>'B2C','buero'=>'B2B','event'=>'B2B','other'=>''];

include __DIR__ . '/../includes/layout.php';
?>

<?php if (!empty($_GET['saved'])): ?>
<div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4">Gespeichert.</div>
<?php endif; ?>

<div class="flex items-start justify-between mb-6 flex-wrap gap-4">
  <div>
    <h1 class="text-2xl font-bold text-gray-900">Leads — Neue Kunden</h1>
    <p class="text-sm text-gray-500 mt-1">Automatisch gefundene potenzielle Kunden aus öffentlichen Quellen.</p>
  </div>
  <div x-data="{ scanning: false, scanResult: null, manualOpen: false }" class="flex items-center gap-2 flex-wrap">
    <button
      @click="scanning = true; scanResult = null; fetch('/api/lead-scraper.php?cron=flk_scrape_2026').then(r => r.json()).then(d => { scanResult = d; scanning = false; if (d.total_new > 0) setTimeout(() => location.reload(), 1500); }).catch(() => { scanning = false; scanResult = { error: 'VPS nicht erreichbar — nutze manuellen Eintrag' }; })"
      :disabled="scanning"
      class="px-4 py-2 bg-brand hover:bg-brand/90 text-white rounded-xl text-sm font-semibold flex items-center gap-2 disabled:opacity-50">
      <svg x-show="!scanning" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
      <svg x-show="scanning" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
      <span x-text="scanning ? 'Scanne...' : '🔍 Auto-Scan'"></span>
    </button>
    <button @click="manualOpen = !manualOpen" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-xl text-sm font-semibold">+ Manueller Lead</button>
    <form method="POST" class="inline" onsubmit="return confirm('Junk-Leads (Job-Boards, Behörden, Konkurrenten, alte ohne Kontakt) löschen?');">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="purge_junk"/>
      <button type="submit" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-semibold">🗑 Junk löschen</button>
    </form>
    <div x-show="scanResult" x-cloak class="text-xs basis-full">
      <template x-if="scanResult?.success">
        <div>
          <span :class="scanResult.total_new > 0 ? 'text-green-700' : 'text-gray-500'">
            ✓ Scan fertig: <b x-text="scanResult.total_new"></b> neu · <b x-text="scanResult.total_seen"></b> geprüft
          </span>
          <span x-show="scanResult.total_new === 0" class="text-amber-700 ml-2">⚠ 0 neue Leads — SearXNG liefert wenig für site:-Queries. Nutze "+ Manueller Lead" aus Kleinanzeigen.</span>
        </div>
      </template>
      <span x-show="scanResult?.error" class="text-red-700">❌ <span x-text="scanResult?.error"></span></span>
    </div>
    <div x-show="manualOpen" x-cloak class="basis-full bg-green-50 border-2 border-green-300 rounded-xl p-4 mt-2">
      <form method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-2">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_manual"/>
        <input name="m_name" required placeholder="Name / Titel *" class="px-3 py-2 border rounded-lg text-sm"/>
        <select name="m_category" class="px-3 py-2 border rounded-lg text-sm">
          <option value="haushalt">🏠 Haushalt (B2C)</option>
          <option value="airbnb">🌴 Airbnb (B2C)</option>
          <option value="cohost">🤝 Co-Host (B2C)</option>
          <option value="buero">🏢 Büro (B2B)</option>
          <option value="event">🎉 Event (B2B)</option>
          <option value="umzug">📦 Umzug</option>
        </select>
        <input name="m_url" placeholder="Quelle-URL (z.B. Kleinanzeigen-Link)" class="px-3 py-2 border rounded-lg text-sm"/>
        <input type="email" name="m_email" placeholder="E-Mail" class="px-3 py-2 border rounded-lg text-sm"/>
        <input name="m_phone" placeholder="Telefon" class="px-3 py-2 border rounded-lg text-sm"/>
        <input name="m_notes" placeholder="Notiz" class="px-3 py-2 border rounded-lg text-sm"/>
        <textarea name="m_snippet" placeholder="Anzeigen-Text / Beschreibung" class="md:col-span-3 px-3 py-2 border rounded-lg text-sm" rows="2"></textarea>
        <button type="submit" class="md:col-span-3 px-4 py-2 bg-green-600 text-white rounded-xl text-sm font-semibold">💾 Lead speichern</button>
      </form>
    </div>
  </div>
</div>

<!-- B2B / B2C Segment-Tabs -->
<?php $segUrl = function($seg) use ($filter, $category) { return '?filter=' . urlencode($filter) . '&segment=' . urlencode($seg) . ($category ? '&category=' . urlencode($category) : ''); }; ?>
<div class="flex gap-2 mb-3 flex-wrap">
  <a href="<?= $segUrl('') ?>" class="px-3 py-1.5 rounded-lg text-xs font-bold <?= $segment === '' ? 'bg-gray-800 text-white' : 'bg-white border text-gray-600 hover:border-gray-800' ?>">Alle Leads</a>
  <a href="<?= $segUrl('B2C') ?>" class="px-3 py-1.5 rounded-lg text-xs font-bold <?= $segment === 'B2C' ? 'bg-purple-600 text-white' : 'bg-white border text-purple-700 hover:border-purple-600' ?>">👤 B2C (Privat + Airbnb + Co-Host)</a>
  <a href="<?= $segUrl('B2B') ?>" class="px-3 py-1.5 rounded-lg text-xs font-bold <?= $segment === 'B2B' ? 'bg-amber-600 text-white' : 'bg-white border text-amber-700 hover:border-amber-600' ?>">🏢 B2B (Büro + Event)</a>
</div>

<!-- Status filter tabs -->
<div class="flex gap-2 mb-4 flex-wrap">
  <a href="?filter=new" class="px-4 py-2 rounded-xl text-sm font-semibold <?= $filter === 'new' ? 'bg-brand text-white' : 'bg-white border text-gray-700 hover:border-brand' ?>">Neu (<?= $counts['new'] ?>)</a>
  <a href="?filter=contacted" class="px-4 py-2 rounded-xl text-sm font-semibold <?= $filter === 'contacted' ? 'bg-brand text-white' : 'bg-white border text-gray-700 hover:border-brand' ?>">Kontaktiert (<?= $counts['contacted'] ?>)</a>
  <a href="?filter=converted" class="px-4 py-2 rounded-xl text-sm font-semibold <?= $filter === 'converted' ? 'bg-green-600 text-white' : 'bg-white border text-gray-700 hover:border-green-600' ?>">Gewonnen (<?= $counts['converted'] ?>)</a>
  <a href="?filter=rejected" class="px-4 py-2 rounded-xl text-sm font-semibold <?= $filter === 'rejected' ? 'bg-gray-500 text-white' : 'bg-white border text-gray-700 hover:border-gray-500' ?>">Abgelehnt (<?= $counts['rejected'] ?>)</a>
  <a href="?filter=all" class="px-4 py-2 rounded-xl text-sm font-semibold <?= $filter === 'all' ? 'bg-gray-700 text-white' : 'bg-white border text-gray-700 hover:border-gray-700' ?>">Alle (<?= $counts['all'] ?>)</a>
</div>

<!-- Leads list -->
<div class="bg-white rounded-xl border overflow-hidden">
  <?php if (empty($leads)): ?>
  <div class="p-12 text-center">
    <div class="w-16 h-16 mx-auto rounded-full bg-gray-100 flex items-center justify-center mb-4">
      <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
    </div>
    <p class="text-gray-500 font-medium">Noch keine Leads</p>
    <p class="text-xs text-gray-400 mt-1">Click auf "Neue Leads suchen" oben, um eine Markt-Suche zu starten.</p>
  </div>
  <?php else: ?>
  <div class="divide-y">
    <?php foreach ($leads as $l): ?>
    <div class="p-5 hover:bg-gray-50 transition">
      <div class="flex items-start justify-between gap-4">
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2 mb-1 flex-wrap">
            <?php $_seg = $catSegment[$l['category']] ?? ''; ?>
            <?php if ($_seg === 'B2C'): ?>
              <span class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-purple-100 text-purple-700">👤 B2C</span>
            <?php elseif ($_seg === 'B2B'): ?>
              <span class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-amber-100 text-amber-700">🏢 B2B</span>
            <?php endif; ?>
            <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-brand/10 text-brand"><?= $catLabels[$l['category']] ?? $l['category'] ?></span>
            <span class="text-[10px] text-gray-400"><?= e($l['source']) ?></span>
            <span class="text-[10px] text-gray-400">·</span>
            <span class="text-[10px] text-gray-400"><?= date('d.m.Y H:i', strtotime($l['created_at'])) ?></span>
          </div>
          <h3 class="font-semibold text-gray-900 line-clamp-2"><?= e($l['name']) ?></h3>
          <?php if ($l['raw_snippet']): ?>
          <p class="text-xs text-gray-600 mt-1 line-clamp-2"><?= e($l['raw_snippet']) ?></p>
          <?php endif; ?>

          <!-- Contact info -->
          <div class="flex flex-wrap items-center gap-3 mt-3 text-xs">
            <a href="<?= e($l['source_url']) ?>" target="_blank" rel="noopener" class="text-brand hover:underline truncate max-w-xs">🔗 Quelle öffnen</a>
            <?php if ($l['email']): ?>
            <a href="mailto:<?= e($l['email']) ?>" class="text-blue-600 hover:underline">📧 <?= e($l['email']) ?></a>
            <?php endif; ?>
            <?php if ($l['phone']): ?>
            <a href="tel:<?= e($l['phone']) ?>" class="text-green-600 hover:underline">📞 <?= e($l['phone']) ?></a>
            <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $l['phone']) ?>" target="_blank" class="text-green-700 hover:underline">💬 WhatsApp</a>
            <?php endif; ?>
            <?php if (!$l['email'] && !$l['phone']): ?>
            <span class="text-gray-400">⚠ Keine Kontaktdaten — OSINT erforderlich</span>
            <?php endif; ?>
          </div>
        </div>

        <!-- Status actions -->
        <div class="flex flex-col gap-1 flex-shrink-0 min-w-[180px]">
          <?php if ($l['status'] !== 'converted'): ?>
          <?php if (!empty($l['email'])): ?>
          <button type="button" onclick='openPitch(<?= e(json_encode(["lead_id"=>$l["lead_id"],"name"=>$l["name"],"email"=>$l["email"],"category"=>$l["category"]])) ?>)' class="w-full px-3 py-1.5 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-xs font-bold">
            ✉️ KI-Pitch schreiben
          </button>
          <?php endif; ?>
          <form method="POST" onsubmit="return confirm('Lead → Kunde umwandeln (ohne Email)?');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="convert"/>
            <input type="hidden" name="lead_id" value="<?= $l['lead_id'] ?>"/>
            <button type="submit" class="w-full px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-lg text-xs font-bold">
              ✨ Nur umwandeln →
            </button>
          </form>
          <?php endif; ?>
          <form method="POST" class="flex flex-col gap-1">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_status"/>
            <input type="hidden" name="lead_id" value="<?= $l['lead_id'] ?>"/>
            <select name="status" onchange="this.form.submit()" class="px-2 py-1.5 border border-gray-200 rounded-lg text-xs bg-white">
              <option value="new" <?= $l['status'] === 'new' ? 'selected' : '' ?>>🆕 Neu</option>
              <option value="contacted" <?= $l['status'] === 'contacted' ? 'selected' : '' ?>>📧 Kontaktiert</option>
              <option value="converted" <?= $l['status'] === 'converted' ? 'selected' : '' ?>>✅ Gewonnen</option>
              <option value="rejected" <?= $l['status'] === 'rejected' ? 'selected' : '' ?>>❌ Abgelehnt</option>
            </select>
          </form>
          <form method="POST" onsubmit="return confirm('Lead wirklich löschen?')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete"/>
            <input type="hidden" name="lead_id" value="<?= $l['lead_id'] ?>"/>
            <button type="submit" class="w-full px-3 py-1 text-[11px] text-red-600 hover:bg-red-50 rounded">🗑 Löschen</button>
          </form>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- KI-Pitch Modal -->
<div id="pitchModal" class="hidden fixed inset-0 bg-black/50 z-50 items-center justify-center p-4">
  <div class="bg-white rounded-xl max-w-5xl w-full max-h-[94vh] overflow-auto">
    <div class="sticky top-0 bg-white border-b px-5 py-3 flex justify-between items-center">
      <h3 class="font-bold text-lg">✉️ KI-Pitch an Lead <span class="text-xs font-normal text-gray-500 ml-2" id="pitchTo">—</span></h3>
      <button type="button" onclick="closePitch()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
    </div>
    <form method="POST" class="p-5 space-y-3" onsubmit="return confirm('Email jetzt senden + Lead in Kunde umwandeln?');">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="send_pitch"/>
      <input type="hidden" name="lead_id" id="pitchLid"/>
      <div class="flex items-center gap-2">
        <button type="button" onclick="regenPitch()" id="pitchGenBtn" class="px-3 py-2 bg-purple-600 text-white rounded-lg text-sm font-semibold hover:bg-purple-700 disabled:opacity-50">🔄 KI neu generieren</button>
        <span id="pitchStatus" class="text-xs text-gray-500"></span>
        <span class="text-[11px] text-gray-400 ml-auto">Platzhalter <code class="bg-gray-100 px-1 rounded">{{LINK}}</code> wird beim Senden durch persönlichen Prebook-Link ersetzt.</span>
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-700 mb-1">Betreff</label>
        <input name="subject" id="pitchSubj" required maxlength="140" class="w-full px-3 py-2 border rounded-lg text-sm"/>
      </div>
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
        <div>
          <label class="block text-xs font-semibold text-gray-700 mb-1">Body (HTML — bearbeitbar)</label>
          <textarea name="body" id="pitchBody" required rows="16" oninput="renderPitchPreview()" class="w-full px-3 py-2 border rounded-lg text-xs font-mono"></textarea>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-700 mb-1">Live-Preview</label>
          <div id="pitchPreview" class="border rounded-lg p-4 bg-gray-50 text-sm max-h-[480px] overflow-auto"></div>
        </div>
      </div>
      <div class="flex gap-2 pt-2">
        <button type="button" onclick="closePitch()" class="flex-1 px-4 py-2 border rounded-lg">Abbrechen</button>
        <button type="submit" class="flex-1 px-4 py-2 bg-brand text-white rounded-lg font-semibold">📧 Senden + Kunde anlegen</button>
      </div>
    </form>
  </div>
</div>

<script>
let _pitchLead = null;
function openPitch(lead) {
  _pitchLead = lead;
  document.getElementById('pitchModal').classList.remove('hidden');
  document.getElementById('pitchModal').classList.add('flex');
  document.getElementById('pitchLid').value = lead.lead_id;
  document.getElementById('pitchTo').textContent = '→ ' + (lead.name || '') + ' <' + lead.email + '> · ' + lead.category;
  document.getElementById('pitchSubj').value = '';
  document.getElementById('pitchBody').value = '';
  document.getElementById('pitchPreview').innerHTML = '<div class="text-gray-400">Wird generiert…</div>';
  regenPitch();
}
function closePitch() {
  document.getElementById('pitchModal').classList.add('hidden');
  document.getElementById('pitchModal').classList.remove('flex');
  _pitchLead = null;
}
async function regenPitch() {
  if (!_pitchLead) return;
  const btn = document.getElementById('pitchGenBtn');
  const status = document.getElementById('pitchStatus');
  btn.disabled = true; status.textContent = 'KI denkt nach…';
  const fd = new FormData();
  fd.append('action', 'generate_pitch');
  fd.append('lead_id', _pitchLead.lead_id);
  fd.append('_csrf', '<?= csrfToken() ?>');
  try {
    const r = await fetch('/admin/leads.php', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.error) { status.textContent = '❌ ' + d.error; btn.disabled = false; return; }
    document.getElementById('pitchSubj').value = d.subject || '';
    document.getElementById('pitchBody').value = d.body || '';
    renderPitchPreview();
    status.textContent = '✅ Fertig — bearbeite und sende';
  } catch (e) { status.textContent = '❌ ' + e.message; }
  btn.disabled = false;
}
function renderPitchPreview() {
  const body = document.getElementById('pitchBody').value || '';
  const preview = body.replace(/\{\{LINK\}\}/g, 'https://app.fleckfrei.de/p/preview-link');
  document.getElementById('pitchPreview').innerHTML = preview || '<div class="text-gray-400">leer</div>';
}
</script>
