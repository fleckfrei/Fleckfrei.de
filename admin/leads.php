<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Leads (Neue Kunden)'; $page = 'leads';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { header('Location: /admin/leads.php'); exit; }
    $act = $_POST['action'] ?? '';
    $lid = (int)($_POST['lead_id'] ?? 0);

    // Background Check: Perplexity OSINT-Lookup über Person/Business hinter der Anzeige
    if ($act === 'background_check' && $lid) {
        header('Content-Type: application/json; charset=utf-8');
        require_once __DIR__ . '/../includes/llm-helpers.php';
        $lead = one("SELECT * FROM leads WHERE lead_id=?", [$lid]);
        if (!$lead) { echo json_encode(['error'=>'not found']); exit; }

        // Kontaktname aus notes
        $contactName = null; $district = null;
        if (!empty($lead['notes'])) {
            if (preg_match('/\[KONTAKT:([^\]]+)\]/', $lead['notes'], $m)) $contactName = trim($m[1]);
            if (preg_match('/\[BEZIRK:([^\]]+)\]/', $lead['notes'], $m)) $district = trim($m[1]);
        }

        $queryParts = [];
        if ($contactName) $queryParts[] = $contactName;
        if ($lead['email']) $queryParts[] = $lead['email'];
        if ($lead['phone']) $queryParts[] = $lead['phone'];
        $queryParts[] = 'Berlin';
        if ($district) $queryParts[] = $district;
        $query = implode(' ', $queryParts);
        if (count($queryParts) < 2) { echo json_encode(['error'=>'zu wenig Infos für OSINT — erst "OSINT anreichern" klicken']); exit; }

        // Erst: Perplexity via VPS (wenn verfügbar)
        $osintSummary = '';
        try {
            $perp = vps_call('perplexity', [
                'query' => "Wer ist $query? Such öffentliche Infos: Business, Website, LinkedIn, Beruf, Tätigkeit. Nur verifizierbare Fakten. Antworte DEUTSCH in 3-5 Stichpunkten + Quellen-Links.",
                'max_tokens' => 500,
            ], true);
            if (is_array($perp) && !empty($perp['answer'])) {
                $osintSummary = $perp['answer'];
            }
        } catch (Exception $e) {}

        // Fallback: Groq-Analyse des Anzeigen-Texts
        if (!$osintSummary) {
            $prompt = "Analysiere diese Kleinanzeige für einen möglichen Kunden einer Reinigungsfirma:\n\n"
                   . "Titel: {$lead['name']}\n"
                   . "Beschreibung: " . substr($lead['raw_snippet'] ?? '', 0, 2000) . "\n"
                   . ($contactName ? "Kontaktname: $contactName\n" : '')
                   . ($district ? "Bezirk: $district\n" : '')
                   . "\nAntworte DEUTSCH in strukturierten Stichpunkten:\n"
                   . "• Was sucht die Person konkret?\n"
                   . "• Geschätzter Typ (Privatkunde / Hausverwaltung / Unternehmen / Airbnb-Host)?\n"
                   . "• Dringlichkeit (sofort / flexibel)?\n"
                   . "• Budget-Signal (sparsam / mittel / hochpreisig)?\n"
                   . "• Empfohlenes Sales-Vorgehen für Fleckfrei?";
            $r = groq_chat($prompt, 500);
            $osintSummary = trim($r['content'] ?? '');
        }

        // WhatsApp / Telegram / Signal Detection
        $phone = $lead['phone'] ?? '';
        $phoneClean = preg_replace('/[^0-9]/', '', $phone);
        $text = ($lead['raw_snippet'] ?? '') . ' ' . ($lead['notes'] ?? '');
        $hasWA = (bool)($phoneClean && preg_match('/^(49|0049|49)/', $phoneClean)) || preg_match('/whatsapp|whats\s?app|wa\.me|wa\s*nr/i', $text);
        $hasTg = (bool)preg_match('/telegram|@[a-z0-9_]{4,32}|t\.me\//i', $text);
        $hasSignal = (bool)preg_match('/signal\s*(app|messenger|kontakt)/i', $text);

        // Business-Website aus raw_snippet fischen
        $businessUrl = null;
        if (preg_match_all('#https?://[^\s"<>\']+#i', $text, $urls)) {
            foreach ($urls[0] as $u) {
                if (strpos($u, 'kleinanzeigen') === false && strpos($u, 'fleckfrei') === false) { $businessUrl = $u; break; }
            }
        }

        echo json_encode([
            'success' => true,
            'summary' => $osintSummary,
            'contact_name' => $contactName,
            'district' => $district,
            'business_url' => $businessUrl,
            'channels' => [
                'whatsapp' => $hasWA,
                'telegram' => $hasTg,
                'signal' => $hasSignal,
                'phone' => $phone,
                'wa_link' => $phoneClean ? "https://wa.me/$phoneClean" : null,
            ],
        ]);
        exit;
    }

    // On-demand OSINT-Enrichment: Ad-Seite fetchen und Kontakt-Daten nachladen
    if ($act === 'enrich' && $lid) {
        header('Content-Type: application/json; charset=utf-8');
        require_once __DIR__ . '/../api/lead-scraper.php';
        $lead = one("SELECT * FROM leads WHERE lead_id=?", [$lid]);
        if (!$lead) { echo json_encode(['error'=>'not found']); exit; }
        if (!function_exists('fetchLeadDetails')) { echo json_encode(['error'=>'fetchLeadDetails not loaded']); exit; }
        $d = fetchLeadDetails($lead['source_url']);
        if (!$d) { echo json_encode(['error'=>'Ad-Seite nicht erreichbar (Cloudflare?)']); exit; }
        $newEmail = $d['email'] ?: $lead['email'];
        $newPhone = $d['phone'] ?: $lead['phone'];
        $notes = $lead['notes'] ?: '';
        if ($d['district'] && strpos($notes, '[BEZIRK:') === false) $notes .= " [BEZIRK:{$d['district']}]";
        if ($d['name'] && strpos($notes, '[KONTAKT:') === false) $notes .= " [KONTAKT:{$d['name']}]";
        q("UPDATE leads SET email=?, phone=?, notes=?, raw_snippet=? WHERE lead_id=?",
          [$newEmail, $newPhone, $notes, substr($d['full_text'] ?: $lead['raw_snippet'], 0, 1500), $lid]);
        echo json_encode(['success'=>true,'email'=>$newEmail,'phone'=>$newPhone,'district'=>$d['district'],'contact_name'=>$d['name']]);
        exit;
    }

    // Lead-Feld inline bearbeiten
    if ($act === 'update_lead' && $lid) {
        header('Content-Type: application/json; charset=utf-8');
        $field = $_POST['field'] ?? '';
        $value = trim($_POST['value'] ?? '');
        if (!in_array($field, ['email','phone','name'], true)) { echo json_encode(['error'=>'invalid field']); exit; }
        q("UPDATE leads SET $field=? WHERE lead_id=?", [$value ?: null, $lid]);
        echo json_encode(['success'=>true]);
        exit;
    }

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

        $prompt = "Du schreibst eine personalisierte Verkaufs-Email von Fleckfrei (Reinigungs-Service Berlin) an einen potenziellen Kunden dessen Kleinanzeige wir gefunden haben.\n\n"
                . "KUNDE:\n"
                . "Titel: $name\n"
                . "Anzeigen-Text: " . substr($snippet, 0, 500) . "\n"
                . "Kategorie: $cat\n\n"
                . "FLECKFREI-KONTEXT ($cat):\n$pitchCtx\n\n"
                . "AUFGABE — gib die Email in EXAKT diesem Format zurück (nichts vor SUBJECT, nichts nach </body>):\n\n"
                . "SUBJECT: <Betreff max 70 Zeichen, persönlich, konkret, keine Emojis>\n"
                . "---\n"
                . "<p>Persönlicher Einstieg mit Bezug auf seine Anzeige</p>\n"
                . "<p>Was Fleckfrei konkret für SEINEN Fall anbietet (kategorie-spezifisch aus dem Kontext oben)</p>\n"
                . "<p>2-3 konkrete Benefits als Fließtext</p>\n"
                . "<p><a href=\"{{LINK}}\" style=\"background:#2E7D6B;color:#fff;padding:14px 28px;border-radius:10px;text-decoration:none;font-weight:700;display:inline-block\">Jetzt unverbindliches Angebot ansehen →</a></p>\n"
                . "<p>Herzliche Grüße<br>Ihr Fleckfrei-Team<br>info@fleckfrei.de</p>\n\n"
                . "REGELN:\n"
                . "- {{LINK}} wird automatisch durch den persönlichen Prebook-Link ersetzt\n"
                . "- Warm + menschlich + konkret, KEINE Floskeln, KEIN 'gratis', KEINE Emojis im Subject\n"
                . "- KEINE Markdown-Fences (```), KEIN Intro, KEIN Outro — nur SUBJECT-Zeile + --- + HTML-Body";

        $r = groq_chat($prompt, 900);
        $content = trim($r['content'] ?? '');
        $clean = preg_replace('#```(?:json|html)?\s*#m', '', $content);
        $clean = preg_replace('#\s*```\s*#m', '', $clean);
        $clean = trim($clean);

        $parsed = null;

        // 1) Primary: SUBJECT: xxx\n---\nBODY format
        if (preg_match('/SUBJECT\s*:\s*(.+?)\s*\n\s*---+\s*\n(.+)$/is', $clean, $sm)) {
            $parsed = ['subject' => trim($sm[1], " \"'`"), 'body' => trim($sm[2])];
        }

        // 2) JSON-Block im Text finden (alter Stil falls Groq das zurückgibt)
        if (!is_array($parsed) && preg_match('/\{[\s\S]*"subject"[\s\S]*\}/m', $clean, $m)) {
            $parsed = json_decode($m[0], true);
        }

        // 3) Nur SUBJECT: Zeile finden, Rest = Body
        if (!is_array($parsed) || empty($parsed['subject'])) {
            if (preg_match('/(?:SUBJECT|Betreff)\s*:\s*(.+?)\n([\s\S]+)/i', $clean, $m)) {
                $parsed = ['subject' => trim($m[1], " \"'`*"), 'body' => trim($m[2])];
            }
        }

        // 4) Ultimate Fallback: erste Zeile als Subject
        if (!is_array($parsed) || empty($parsed['subject']) || empty($parsed['body'])) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $clean))));
            if (count($lines) >= 2) {
                $subj = trim($lines[0], " \"'`#*:");
                $rest = array_slice($lines, 1);
                $body = strpos(implode('', $rest), '<') !== false
                    ? implode("\n", $rest)
                    : '<p>' . implode('</p><p>', $rest) . '</p>';
                if ($subj && strlen($subj) < 200) $parsed = ['subject' => $subj, 'body' => $body];
            }
        }

        if (!is_array($parsed) || empty($parsed['subject']) || empty($parsed['body'])) {
            echo json_encode(['error' => 'KI-Antwort nicht lesbar — bitte "Neu generieren" klicken', 'raw' => substr($content, 0, 400)]); exit;
        }
        echo json_encode(['subject' => trim($parsed['subject']), 'body' => trim($parsed['body'])]);
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

    // Tote Anzeigen finden + löschen: checked Leads deren URL 404 zurückgibt oder
    // deren Ad-Seite "Anzeige nicht mehr verfügbar" zeigt.
    if ($act === 'purge_dead') {
        $check = all("SELECT lead_id, source_url FROM leads WHERE status='new' AND source_url LIKE 'http%' ORDER BY created_at ASC LIMIT 150");
        $deadMarkers = [
            'Anzeige nicht mehr verfügbar',
            'Die Anzeige wurde entfernt',
            'Anzeige existiert nicht',
            'wurde gelöscht',
            'bereits reserviert',
            'no longer available',
            'viewad-not-found',
            'nicht gefunden',
            'not found',
        ];
        $deleted = 0; $checked = 0;
        foreach ($check as $l) {
            $checked++;
            $ch = curl_init($l['source_url']);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/537.36 Chrome/120.0 Safari/537.36',
                CURLOPT_TIMEOUT => 6,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $html = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $isDead = false;
            if ($code >= 400 && $code !== 429) $isDead = true;
            if ($html) {
                foreach ($deadMarkers as $m) { if (stripos($html, $m) !== false) { $isDead = true; break; } }
            } elseif ($code === 0) {
                // Netz-Fehler — nicht löschen, nur bei echten 4xx oder dead-markern
            }
            if ($isDead) {
                q("DELETE FROM leads WHERE lead_id=?", [$l['lead_id']]);
                $deleted++;
            }
            usleep(150000); // 0.15s
        }
        header("Location: /admin/leads.php?saved=1&dead=$deleted&checked=$checked"); exit;
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
// Freshness-Filter: nur Anzeigen die KÜRZLICH gepostet wurden (via [POSTED:YYYY-MM-DD] in notes)
$fresh = $_GET['fresh'] ?? ''; // 7d / 30d / all
if ($fresh === '7d') {
    $where[] = "(notes REGEXP '\\\\[POSTED:[0-9-]+[^]]*\\\\]' AND STR_TO_DATE(SUBSTRING_INDEX(SUBSTRING_INDEX(notes, '[POSTED:', -1), ' ', 1), '%Y-%m-%d') >= DATE_SUB(NOW(), INTERVAL 7 DAY))";
} elseif ($fresh === '30d') {
    $where[] = "(notes REGEXP '\\\\[POSTED:[0-9-]+[^]]*\\\\]' AND STR_TO_DATE(SUBSTRING_INDEX(SUBSTRING_INDEX(notes, '[POSTED:', -1), ' ', 1), '%Y-%m-%d') >= DATE_SUB(NOW(), INTERVAL 30 DAY))";
}

// Sortierung: nach POSTED-Date falls vorhanden, sonst created_at
$leads = all("SELECT *,
                COALESCE(STR_TO_DATE(SUBSTRING_INDEX(SUBSTRING_INDEX(notes, '[POSTED:', -1), ' ', 1), '%Y-%m-%d'), DATE(created_at)) AS effective_date
             FROM leads WHERE " . implode(' AND ', $where) . "
             ORDER BY effective_date DESC, created_at DESC LIMIT 200", $params);

// Letzter Scan-Zeitpunkt (aus audit)
$lastScan = val("SELECT MAX(created_at) FROM audit_log WHERE action='scrape' AND entity='leads'") ?: null;

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

<?php if (!empty($_GET['saved']) && !isset($_GET['dead'])): ?>
<div class="bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 rounded-xl mb-4">Gespeichert.</div>
<?php endif; ?>
<?php if (isset($_GET['dead'])): ?>
<div class="bg-amber-50 border border-amber-300 text-amber-900 px-4 py-3 rounded-xl mb-4">
  🧹 <b><?= (int)$_GET['dead'] ?></b> tote Anzeigen entfernt (von <?= (int)($_GET['checked'] ?? 0) ?> geprüft). Nochmal klicken für die nächsten 150.
</div>
<?php endif; ?>

<div class="flex items-start justify-between mb-6 flex-wrap gap-4">
  <div>
    <h1 class="text-2xl font-bold text-gray-900">Leads — Neue Kunden</h1>
    <p class="text-sm text-gray-500 mt-1">Automatisch gefundene potenzielle Kunden aus öffentlichen Quellen.</p>
    <?php if ($lastScan): ?>
    <div class="text-[11px] text-gray-500 mt-1">⏱ Letzter Scan: <b><?= date('d.m.Y H:i', strtotime($lastScan)) ?></b> (<?= (int)((time() - strtotime($lastScan))/60) ?> min her)</div>
    <?php else: ?>
    <div class="text-[11px] text-red-600 mt-1">⚠ Noch nie gescannt — klick "Auto-Scan" oder richte Cron ein</div>
    <?php endif; ?>
  </div>
  <div x-data="{ scanning: false, scanResult: null, manualOpen: false }" class="flex items-center gap-2 flex-wrap">
    <button
      @click="scanning = true; scanResult = null; fetch('/api/lead-scraper.php?cron=flk_scrape_2026').then(r => r.json()).then(d => { scanResult = d; scanning = false; if (d.total_new > 0) setTimeout(() => location.reload(), 1500); }).catch(() => { scanning = false; scanResult = { error: 'VPS nicht erreichbar — nutze manuellen Eintrag' }; })"
      :disabled="scanning"
      class="px-4 py-2 bg-brand hover:bg-brand-dark text-white rounded-xl text-sm font-semibold flex items-center gap-2 disabled:opacity-50 shadow-sm">
      <svg x-show="!scanning" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
      <svg x-show="scanning" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
      <span x-text="scanning ? 'Scanne...' : 'Auto-Scan'"></span>
    </button>
    <button @click="manualOpen = !manualOpen" class="px-4 py-2 bg-white border-2 border-brand text-brand hover:bg-brand-light rounded-xl text-sm font-semibold flex items-center gap-1.5">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
      Manueller Lead
    </button>
    <form method="POST" class="inline" onsubmit="return confirm('Prüft bis zu 150 Leads und entfernt alle deren Kleinanzeigen-URL nicht mehr erreichbar ist (404 / gelöscht / entfernt). Läuft 30-60s.');">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="purge_dead"/>
      <button type="submit" class="px-4 py-2 bg-white border-2 border-amber-500 text-amber-700 hover:bg-amber-50 rounded-xl text-sm font-semibold flex items-center gap-1.5">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        Tote Anzeigen
      </button>
    </form>
    <form method="POST" class="inline" onsubmit="return confirm('Junk-Leads (Job-Boards, Behörden, Konkurrenten, alte ohne Kontakt) löschen?');">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="purge_junk"/>
      <button type="submit" class="px-4 py-2 bg-white border-2 border-rose-600 text-rose-700 hover:bg-rose-50 rounded-xl text-sm font-semibold flex items-center gap-1.5">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
        Junk löschen
      </button>
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
        <button type="submit" class="md:col-span-3 px-4 py-2 bg-brand hover:bg-brand-dark text-white rounded-xl text-sm font-semibold">💾 Lead speichern</button>
      </form>
    </div>
  </div>
</div>

<!-- Freshness-Filter: nur kürzlich gepostete Anzeigen -->
<?php $freshUrl = function($f) use ($filter, $segment, $category) { return '?filter=' . urlencode($filter) . '&segment=' . urlencode($segment) . '&fresh=' . urlencode($f) . ($category ? '&category=' . urlencode($category) : ''); }; ?>
<div class="flex gap-2 mb-3 flex-wrap items-center">
  <span class="text-xs text-gray-500 font-semibold">⏱ Frische:</span>
  <a href="<?= $freshUrl('7d') ?>" class="px-3 py-1 rounded-lg text-xs font-bold <?= $fresh === '7d' ? 'bg-emerald-600 text-white' : 'bg-white border text-emerald-700 hover:border-emerald-600' ?>">🟢 letzte 7 Tage</a>
  <a href="<?= $freshUrl('30d') ?>" class="px-3 py-1 rounded-lg text-xs font-bold <?= $fresh === '30d' ? 'bg-amber-600 text-white' : 'bg-white border text-amber-700 hover:border-amber-600' ?>">🟡 letzte 30 Tage</a>
  <a href="<?= $freshUrl('') ?>" class="px-3 py-1 rounded-lg text-xs font-bold <?= $fresh === '' ? 'bg-gray-700 text-white' : 'bg-white border text-gray-700 hover:border-gray-700' ?>">alle</a>
</div>

<!-- B2B / B2C Segment-Tabs -->
<?php $segUrl = function($seg) use ($filter, $category) { return '?filter=' . urlencode($filter) . '&segment=' . urlencode($seg) . ($category ? '&category=' . urlencode($category) : ''); }; ?>
<div class="flex gap-2 mb-3 flex-wrap">
  <a href="<?= $segUrl('') ?>" class="px-3 py-1.5 rounded-lg text-xs font-bold <?= $segment === '' ? 'bg-gray-800 text-white' : 'bg-white border text-gray-600 hover:border-gray-800' ?>">Alle Leads</a>
  <a href="<?= $segUrl('B2C') ?>" class="px-3 py-1.5 rounded-lg text-xs font-bold <?= $segment === 'B2C' ? 'bg-indigo-600 text-white' : 'bg-white border text-indigo-700 hover:border-indigo-600' ?>">👤 B2C (Privat + Airbnb + Co-Host)</a>
  <a href="<?= $segUrl('B2B') ?>" class="px-3 py-1.5 rounded-lg text-xs font-bold <?= $segment === 'B2B' ? 'bg-amber-600 text-white' : 'bg-white border text-amber-700 hover:border-amber-600' ?>">🏢 B2B (Büro + Event)</a>
</div>

<!-- Status filter tabs -->
<div class="flex gap-2 mb-4 flex-wrap">
  <a href="?filter=new" class="px-4 py-2 rounded-xl text-sm font-semibold <?= $filter === 'new' ? 'bg-brand text-white' : 'bg-white border text-gray-700 hover:border-brand' ?>">Neu (<?= $counts['new'] ?>)</a>
  <a href="?filter=contacted" class="px-4 py-2 rounded-xl text-sm font-semibold <?= $filter === 'contacted' ? 'bg-brand text-white' : 'bg-white border text-gray-700 hover:border-brand' ?>">Kontaktiert (<?= $counts['contacted'] ?>)</a>
  <a href="?filter=converted" class="px-4 py-2 rounded-xl text-sm font-semibold <?= $filter === 'converted' ? 'bg-emerald-600 text-white' : 'bg-white border text-gray-700 hover:border-emerald-600' ?>">Gewonnen (<?= $counts['converted'] ?>)</a>
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
              <span class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-indigo-100 text-indigo-700">👤 B2C</span>
            <?php elseif ($_seg === 'B2B'): ?>
              <span class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-amber-100 text-amber-700">🏢 B2B</span>
            <?php endif; ?>
            <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-brand/10 text-brand"><?= $catLabels[$l['category']] ?? $l['category'] ?></span>
            <span class="text-[10px] text-gray-400"><?= e($l['source']) ?></span>
            <?php
              // Posted-Date aus notes extrahieren (falls vorhanden)
              $postedAt = null;
              if (!empty($l['notes']) && preg_match('/\[POSTED:([\d-]+(?: [\d:]+)?)\]/', $l['notes'], $pm)) {
                  $postedAt = $pm[1];
              }
              if ($postedAt):
                $daysAgo = (int)((time() - strtotime($postedAt))/86400);
                $freshClass = $daysAgo <= 3 ? 'bg-green-100 text-green-800' : ($daysAgo <= 14 ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-600');
            ?>
            <span class="text-[10px] font-bold px-1.5 py-0.5 rounded <?= $freshClass ?>" title="Anzeige gepostet am <?= e($postedAt) ?>">
              📅 Anzeige: <?= $daysAgo === 0 ? 'heute' : ($daysAgo === 1 ? 'gestern' : "vor {$daysAgo} Tagen") ?>
            </span>
            <?php endif; ?>
            <span class="text-[10px] text-gray-400" title="Gefunden am">💾 <?= date('d.m. H:i', strtotime($l['created_at'])) ?></span>
          </div>
          <h3 class="font-semibold text-gray-900 line-clamp-2"><?= e($l['name']) ?></h3>
          <?php if ($l['raw_snippet']): ?>
          <p class="text-xs text-gray-600 mt-1 line-clamp-2"><?= e($l['raw_snippet']) ?></p>
          <?php endif; ?>

          <!-- Contact info (inline-editable) -->
          <div class="flex flex-wrap items-center gap-2 mt-3 text-xs">
            <a href="<?= e($l['source_url']) ?>" target="_blank" rel="noopener" class="text-brand hover:underline font-semibold" title="<?= e($l['source_url']) ?>">🔗 Anzeige öffnen</a>
            <span class="text-gray-300">·</span>
            <span class="inline-flex items-center gap-1">
              📧 <input type="email" value="<?= e($l['email'] ?? '') ?>" placeholder="email@..." onblur="saveLeadField(<?= $l['lead_id'] ?>,'email',this.value,this)" class="px-1 py-0.5 border border-transparent hover:border-gray-300 rounded text-xs w-48 focus:border-brand focus:outline-none"/>
            </span>
            <span class="inline-flex items-center gap-1">
              📞 <input type="tel" value="<?= e($l['phone'] ?? '') ?>" placeholder="+49..." onblur="saveLeadField(<?= $l['lead_id'] ?>,'phone',this.value,this)" class="px-1 py-0.5 border border-transparent hover:border-gray-300 rounded text-xs w-36 focus:border-brand focus:outline-none"/>
            </span>
            <?php if ($l['phone']): ?>
            <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $l['phone']) ?>" target="_blank" class="text-green-700 hover:underline">💬 WA</a>
            <?php endif; ?>
            <?php
              $district = null; $contactName = null;
              if (!empty($l['notes'])) {
                  if (preg_match('/\[BEZIRK:([^\]]+)\]/', $l['notes'], $dm)) $district = trim($dm[1]);
                  if (preg_match('/\[KONTAKT:([^\]]+)\]/', $l['notes'], $km)) $contactName = trim($km[1]);
              }
              if ($contactName): ?>
              <span class="text-gray-500">👤 <?= e($contactName) ?></span>
            <?php endif;
              if ($district): ?>
              <span class="text-gray-500">📍 <?= e($district) ?></span>
            <?php endif; ?>
            <?php if (!$l['email'] && !$l['phone']): ?>
            <span class="text-amber-600 font-semibold">⚠ OSINT nötig — klick "🔍 OSINT anreichern"</span>
            <?php endif; ?>
          </div>
        </div>

        <!-- Status actions -->
        <div class="flex flex-col gap-1 flex-shrink-0 min-w-[180px]">
          <?php if ($l['status'] !== 'converted'): ?>
          <!-- Primary: KI-Pitch (der Workflow den der User eigentlich will) -->
          <button type="button" onclick='openPitch(<?= e(json_encode(["lead_id"=>$l["lead_id"],"name"=>$l["name"],"email"=>$l["email"] ?? '',"phone"=>$l["phone"] ?? '',"category"=>$l["category"]])) ?>)' class="w-full px-3 py-2 bg-brand hover:bg-brand-dark text-white rounded-lg text-xs font-semibold flex items-center justify-center gap-1.5 shadow-sm">
            ✉️ KI-Pitch schreiben
          </button>
          <!-- Secondary: utility actions -->
          <div class="grid grid-cols-2 gap-1">
            <button type="button" onclick="enrichLead(<?= $l['lead_id'] ?>, this)" class="px-2 py-1.5 bg-white hover:bg-gray-50 text-gray-700 border border-gray-200 rounded-lg text-[11px] font-medium flex items-center justify-center gap-1">
              <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>🔍 OSINT
            </button>
            <button type="button" onclick="bgCheck(<?= $l['lead_id'] ?>)" class="px-2 py-1.5 bg-white hover:bg-gray-50 text-gray-700 border border-gray-200 rounded-lg text-[11px] font-medium flex items-center justify-center gap-1">
              <span class="w-1.5 h-1.5 rounded-full bg-indigo-500"></span>👤 Background
            </button>
          </div>
          <form method="POST" onsubmit="return confirm('Lead → Kunde umwandeln (ohne Email)?');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="convert"/>
            <input type="hidden" name="lead_id" value="<?= $l['lead_id'] ?>"/>
            <button type="submit" class="w-full px-3 py-1.5 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-200 rounded-lg text-[11px] font-semibold flex items-center justify-center gap-1">
              <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>✨ Nur umwandeln
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
        <button type="button" onclick="regenPitch()" id="pitchGenBtn" class="px-3 py-2 bg-brand text-white rounded-lg text-sm font-semibold hover:bg-brand-dark disabled:opacity-50">🔄 KI neu generieren</button>
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

<!-- Background-Check Modal -->
<div id="bgModal" class="hidden fixed inset-0 bg-black/50 z-50 items-center justify-center p-4">
  <div class="bg-white rounded-xl max-w-2xl w-full max-h-[90vh] overflow-auto">
    <div class="sticky top-0 bg-white border-b px-5 py-3 flex justify-between items-center">
      <h3 class="font-bold text-lg">👤 Background-Check <span id="bgTitle" class="text-xs font-normal text-gray-500 ml-2"></span></h3>
      <button type="button" onclick="closeBg()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
    </div>
    <div class="p-5 space-y-4" id="bgContent">
      <div class="text-gray-500 text-center py-8">🔍 OSINT-Recherche läuft…</div>
    </div>
  </div>
</div>

<!-- Toast-Container -->
<div id="toast" class="hidden fixed top-4 right-4 z-[60] max-w-sm px-4 py-3 rounded-xl shadow-lg text-sm font-medium"></div>

<script>
function toast(msg, kind = 'info') {
  const el = document.getElementById('toast');
  const palette = {
    ok:    'bg-emerald-50 border border-emerald-300 text-emerald-900',
    info:  'bg-indigo-50 border border-indigo-300 text-indigo-900',
    warn:  'bg-amber-50 border border-amber-300 text-amber-900',
    err:   'bg-rose-50 border border-rose-300 text-rose-900',
  };
  el.className = 'fixed top-4 right-4 z-[60] max-w-sm px-4 py-3 rounded-xl shadow-lg text-sm font-medium ' + (palette[kind] || palette.info);
  el.textContent = msg;
  el.classList.remove('hidden');
  clearTimeout(el._t);
  el._t = setTimeout(() => el.classList.add('hidden'), 4500);
}

// Inline Lead-Feld speichern (email, phone)
function saveLeadField(id, field, value, el) {
  const orig = el.defaultValue;
  if (value === orig) return;
  el.style.background = '#fef3c7';
  const fd = new FormData();
  fd.append('action', 'update_lead');
  fd.append('lead_id', id);
  fd.append('field', field);
  fd.append('value', value);
  fd.append('_csrf', '<?= csrfToken() ?>');
  fetch('/admin/leads.php', { method: 'POST', body: fd })
    .then(r => r.json()).then(d => {
      if (d.success) { el.style.background = '#dcfce7'; el.defaultValue = value; setTimeout(()=>{el.style.background='';}, 900); toast('✓ Gespeichert', 'ok'); }
      else { el.style.background = '#fee2e2'; toast(d.error || 'Fehler beim Speichern', 'err'); }
    });
}

// OSINT-Enrichment (Ad-Seite fetchen für Kontakt/Bezirk/Name)
function enrichLead(id, btn) {
  const origText = btn.textContent;
  btn.disabled = true; btn.textContent = '… lädt';
  const fd = new FormData();
  fd.append('action', 'enrich');
  fd.append('lead_id', id);
  fd.append('_csrf', '<?= csrfToken() ?>');
  fetch('/admin/leads.php', { method: 'POST', body: fd })
    .then(r => r.json()).then(d => {
      btn.disabled = false; btn.textContent = origText;
      if (d.error) { toast('OSINT: ' + d.error, 'err'); return; }
      const parts = [];
      if (d.email) parts.push('📧 ' + d.email);
      if (d.phone) parts.push('📞 ' + d.phone);
      if (d.contact_name) parts.push('👤 ' + d.contact_name);
      if (d.district) parts.push('📍 ' + d.district);
      if (parts.length === 0) { toast('⚠ Nix gefunden — Ad evtl. durch Cloudflare geschützt', 'warn'); return; }
      toast('✓ Angereichert: ' + parts.join(' · '), 'ok');
      setTimeout(() => location.reload(), 1200);
    }).catch(e => { btn.disabled = false; btn.textContent = origText; toast('Netzwerk-Fehler', 'err'); });
}

// Background-Check: Perplexity-OSINT + WA/Telegram-Detection
function bgCheck(id) {
  document.getElementById('bgModal').classList.remove('hidden');
  document.getElementById('bgModal').classList.add('flex');
  document.getElementById('bgTitle').textContent = 'Lead #' + id;
  document.getElementById('bgContent').innerHTML = '<div class="text-gray-500 text-center py-8">🔍 OSINT-Recherche läuft (5-15s)…</div>';
  const fd = new FormData();
  fd.append('action', 'background_check');
  fd.append('lead_id', id);
  fd.append('_csrf', '<?= csrfToken() ?>');
  fetch('/admin/leads.php', { method: 'POST', body: fd })
    .then(r => r.json()).then(d => {
      if (d.error) {
        document.getElementById('bgContent').innerHTML = '<div class="bg-red-50 border border-red-300 text-red-700 p-4 rounded-lg">❌ ' + d.error + '</div>';
        return;
      }
      const ch = d.channels || {};
      const waBtn = ch.whatsapp && ch.wa_link
        ? `<a href="${ch.wa_link}" target="_blank" class="inline-flex items-center gap-1 px-3 py-2 bg-emerald-600 text-white rounded-lg text-sm font-semibold hover:bg-emerald-700">💬 WhatsApp öffnen</a>`
        : '<span class="px-3 py-2 bg-gray-100 text-gray-500 rounded-lg text-sm">💬 WhatsApp nicht erkennbar</span>';
      const tgBtn = ch.telegram
        ? '<span class="inline-flex items-center gap-1 px-3 py-2 bg-sky-600 text-white rounded-lg text-sm font-semibold">✈️ Telegram erwähnt im Text</span>'
        : '<span class="px-3 py-2 bg-gray-100 text-gray-500 rounded-lg text-sm">✈️ Telegram nicht erkennbar</span>';
      const sigBtn = ch.signal
        ? '<span class="px-3 py-2 bg-blue-600 text-white rounded-lg text-sm font-semibold">📡 Signal erwähnt</span>'
        : '';
      const bizRow = d.business_url
        ? `<div class="flex items-center gap-2"><span class="text-xs font-semibold text-gray-600">🌐 BUSINESS-SEITE</span><a href="${d.business_url}" target="_blank" class="text-brand hover:underline text-sm truncate">${d.business_url}</a></div>`
        : '';
      const nameRow = d.contact_name ? `<div><span class="text-xs font-semibold text-gray-600">👤 KONTAKT</span><div class="text-sm font-semibold">${d.contact_name}</div></div>` : '';
      const distRow = d.district ? `<div><span class="text-xs font-semibold text-gray-600">📍 BEZIRK</span><div class="text-sm">${d.district}</div></div>` : '';
      const summary = (d.summary || '').replace(/</g, '&lt;').replace(/\n/g, '<br/>');
      document.getElementById('bgContent').innerHTML = `
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">${nameRow}${distRow}</div>
        ${bizRow}
        <div>
          <div class="text-xs font-semibold text-gray-600 mb-1">📱 KOMMUNIKATIONS-KANÄLE</div>
          <div class="flex flex-wrap gap-2">${waBtn}${tgBtn}${sigBtn}</div>
        </div>
        <div>
          <div class="text-xs font-semibold text-gray-600 mb-1">🧠 OSINT-ANALYSE</div>
          <div class="bg-gray-50 border rounded-lg p-3 text-sm leading-relaxed">${summary || '<em class="text-gray-400">Keine Analyse verfügbar</em>'}</div>
        </div>`;
    });
}
function closeBg() {
  document.getElementById('bgModal').classList.add('hidden');
  document.getElementById('bgModal').classList.remove('flex');
}

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
