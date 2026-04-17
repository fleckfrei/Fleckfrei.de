<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'OSI'; $page = 'scanner';

$disposable = ['guerrillamail.com','tempmail.com','mailinator.com','10minutemail.com','throwaway.email','yopmail.com','sharklasers.com','trashmail.com','guerrillamailblock.com','grr.la','dispostable.com','maildrop.cc','temp-mail.org','fakeinbox.com','mohmal.com','getnada.com','tempail.com','emailondeck.com','33mail.com','spam4.me'];

function analyzeEmail($email) {
    if (!$email || strpos($email,'@')===false) return null;
    $domain = strtolower(substr($email, strpos($email,'@')+1));
    $local = strtolower(substr($email, 0, strpos($email,'@')));
    $mx = @dns_get_record($domain, DNS_MX);
    $free = in_array($domain, ['gmail.com','yahoo.com','hotmail.com','outlook.com','gmx.de','web.de','t-online.de','aol.com','icloud.com','protonmail.com','mail.de','freenet.de','live.de','live.com','googlemail.com','posteo.de','mailbox.org']);
    global $disposable;
    $isDisposable = in_array($domain, $disposable);
    // Pattern analysis
    if (preg_match('/^[a-z]+\.[a-z]+$/', $local)) $pattern = ['Professionell','firstname.lastname','green'];
    elseif (preg_match('/^[a-z]+[._][a-z]+\d*$/', $local)) $pattern = ['Normal','name+digits','blue'];
    elseif (preg_match('/^(info|contact|office|admin|hello|team|support)@/', $email)) $pattern = ['Business-Rolle','generic inbox','purple'];
    elseif (preg_match('/\d{4,}/', $local)) $pattern = ['Verdächtig','viele Zahlen','red'];
    else $pattern = ['Casual','gemischt','gray'];
    $gravatar = 'https://www.gravatar.com/avatar/'.md5(strtolower(trim($email))).'?d=404&s=80';
    return ['domain'=>$domain,'local'=>$local,'mx'=>$mx?count($mx):0,'mx_host'=>!empty($mx)?$mx[0]['target']:null,'free'=>$free,'disposable'=>$isDisposable,'pattern'=>$pattern,'gravatar'=>$gravatar,'business'=>!$free&&!$isDisposable];
}

function scanDomain($web) {
    $r = [];
    $dns = @dns_get_record($web, DNS_A);
    $r['ip'] = !empty($dns)?$dns[0]['ip']:null;
    $ctx = stream_context_create(['ssl'=>['capture_peer_cert'=>true,'verify_peer'=>false]]);
    $s = @stream_socket_client("ssl://$web:443", $e, $es, 5, STREAM_CLIENT_CONNECT, $ctx);
    if ($s) { $c=openssl_x509_parse(stream_context_get_params($s)['options']['ssl']['peer_certificate']); $r['ssl']=['issuer'=>$c['issuer']['O']??'?','expires'=>date('Y-m-d',$c['validTo_time_t']),'days'=>floor(($c['validTo_time_t']-time())/86400)]; fclose($s); }
    $ch=curl_init("https://$web"); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>1,CURLOPT_TIMEOUT=>8,CURLOPT_FOLLOWLOCATION=>1,CURLOPT_SSL_VERIFYPEER=>0]); $html=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    $r['http'] = $code;
    if ($html) { preg_match('/<title[^>]*>([^<]+)</',$html,$m); $r['title']=$m[1]??''; $t=[]; if(stripos($html,'wp-content')!==false)$t[]='WordPress'; if(stripos($html,'shopify')!==false)$t[]='Shopify'; if(stripos($html,'wix.com')!==false)$t[]='Wix'; if(stripos($html,'bootstrap')!==false)$t[]='Bootstrap'; if(stripos($html,'tailwind')!==false)$t[]='Tailwind'; if(stripos($html,'next')!==false)$t[]='Next.js'; if(stripos($html,'gtag')!==false||stripos($html,'google-analytics')!==false)$t[]='Analytics'; if(stripos($html,'elementor')!==false)$t[]='Elementor'; if(stripos($html,'react')!==false)$t[]='React'; $r['tech']=$t; }
    return $r;
}

$scan = null; $scanEmail = ''; $scanName = ''; $scanPhone = ''; $scanAddress = '';
$scanDob = ''; $scanIdNr = ''; $scanPassport = ''; $scanSerial = ''; $scanTaxId = '';

// Scan by customer ID
if ($_SERVER['REQUEST_METHOD']==='POST' && !empty($_POST['scan_id'])) {
    if (!verifyCsrf()) { header('Location: /admin/scanner.php'); exit; }
    $cust = one("SELECT c.*, ca.street, ca.number as addr_nr, ca.postal_code as addr_plz, ca.city as addr_city FROM customer c LEFT JOIN customer_address ca ON ca.customer_id_fk=c.customer_id WHERE c.customer_id=? LIMIT 1", [(int)$_POST['scan_id']]);
    if (!$cust) $cust = one("SELECT * FROM customer WHERE customer_id=?", [(int)$_POST['scan_id']]);
    if ($cust) {
        $scanEmail = $cust['email']??''; $scanName = $cust['name']??''; $scanPhone = $cust['phone']??'';
        $scanAddress = trim(($cust['street']??'').' '.($cust['addr_nr']??'').', '.($cust['addr_plz']??'').' '.($cust['addr_city']??''));
        if ($scanAddress === ', ') $scanAddress = $cust['address']??'';
        $scan = true;
    }
}
// Scan manual
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['scan_email'])) {
    if (!verifyCsrf()) { header('Location: /admin/scanner.php'); exit; }
    $scanEmail = trim($_POST['scan_email']??''); $scanName = trim($_POST['scan_name']??''); $scanPhone = trim($_POST['scan_phone']??''); $scanAddress = trim($_POST['scan_address']??'');
    $scanSerial = trim($_POST['scan_serial']??''); $scanPlate = trim($_POST['scan_plate']??''); $scanDob = trim($_POST['scan_dob']??''); $scanIdNr = trim($_POST['scan_id_nr']??''); $scanPassport = trim($_POST['scan_passport']??''); $scanTaxId = trim($_POST['scan_tax_id']??'');
    $scan = ($scanEmail || $scanName || $scanPhone || $scanSerial || $scanTaxId || $scanPlate) ? true : false;
}

$emailInfo = $scan ? analyzeEmail($scanEmail) : null;
$domainInfo = ($emailInfo && $emailInfo['business']) ? scanDomain($emailInfo['domain']) : null;

// DB lookups — comprehensive cross-reference
$dbCustomers = []; $dbEmployees = []; $dbStats = null; $dbServices = []; $dbInvoices = []; $dbJobs = []; $dbAddresses = []; $dbNotes = [];
if ($scan) {
    // Build WHERE conditions only for provided fields (avoid empty LIKE '%%' matching everything)
    $where = []; $params = [];
    if ($scanEmail) { $where[] = 'c.email=?'; $params[] = $scanEmail; }
    if ($scanName) { $where[] = 'c.name LIKE ?'; $params[] = '%'.$scanName.'%'; }
    if ($scanPhone && strlen(preg_replace('/[^0-9]/', '', $scanPhone)) >= 8) {
        $phoneClean = preg_replace('/[^0-9]/', '', $scanPhone);
        $phoneLast = substr($phoneClean, -10); // Match last 10 digits
        $where[] = 'c.phone LIKE ?'; $params[] = '%'.$phoneLast.'%';
    }
    $dbCustomers = [];
    if (!empty($where)) {
        $dbCustomers = all("SELECT c.*, COUNT(j.j_id) as jobs, MAX(j.j_date) as last_job FROM customer c LEFT JOIN jobs j ON j.customer_id_fk=c.customer_id AND j.status=1 WHERE " . implode(' OR ', $where) . " GROUP BY c.customer_id LIMIT 10", $params);
    }
    $empWhere = []; $empParams = [];
    if ($scanEmail) { $empWhere[] = 'email=?'; $empParams[] = $scanEmail; }
    if ($scanPhone && strlen(preg_replace('/[^0-9]/', '', $scanPhone)) >= 8) { $empWhere[] = 'phone LIKE ?'; $empParams[] = '%'.substr(preg_replace('/[^0-9]/', '', $scanPhone), -10).'%'; }
    $dbEmployees = !empty($empWhere) ? all("SELECT * FROM employee WHERE " . implode(' OR ', $empWhere) . " LIMIT 5", $empParams) : [];
    if (!empty($dbCustomers)) {
        $cid = $dbCustomers[0]['customer_id'];
        $dbStats = one("SELECT
            (SELECT COUNT(*) FROM jobs WHERE customer_id_fk=? AND status=1) as total_jobs,
            (SELECT COUNT(*) FROM jobs WHERE customer_id_fk=? AND status=1 AND job_status='COMPLETED') as done,
            (SELECT COUNT(*) FROM jobs WHERE customer_id_fk=? AND status=1 AND job_status='CANCELLED') as cancelled,
            (SELECT COALESCE(SUM(total_price),0) FROM invoices WHERE customer_id_fk=?) as inv_total,
            (SELECT COALESCE(SUM(remaining_price),0) FROM invoices WHERE customer_id_fk=?) as inv_open",
            [$cid, $cid, $cid, $cid, $cid]);
        $dbServices = all("SELECT s.s_id, s.title, s.price, s.total_price, s.street, s.city FROM services s WHERE s.customer_id_fk=? AND s.status=1", [$cid]);
        $dbInvoices = all("SELECT inv_id, invoice_number, issue_date, total_price, remaining_price, invoice_paid FROM invoices WHERE customer_id_fk=? ORDER BY issue_date DESC LIMIT 10", [$cid]);
        $dbJobs = all("SELECT j.j_id, j.j_date, j.j_time, j.job_status, j.j_hours, s.title as stitle, e.name as ename FROM jobs j LEFT JOIN services s ON j.s_id_fk=s.s_id LEFT JOIN employee e ON j.emp_id_fk=e.emp_id WHERE j.customer_id_fk=? AND j.status=1 ORDER BY j.j_date DESC LIMIT 15", [$cid]);
        $dbAddresses = all("SELECT * FROM customer_address WHERE customer_id_fk=?", [$cid]);
        try { $dbNotes = all("SELECT * FROM customer_notes WHERE customer_id_fk=? ORDER BY created_at DESC LIMIT 5", [$cid]); } catch (Exception $e) { $dbNotes = []; }
    }
}

// Geocode address for map
$geoLat = ''; $geoLng = '';
if ($scanAddress) {
    $geoUrl = 'https://nominatim.openstreetmap.org/search?q='.urlencode($scanAddress).'&format=json&limit=1';
    $ctx = stream_context_create(['http'=>['header'=>'User-Agent: Fleckfrei/1.0','timeout'=>5]]);
    $geoData = @file_get_contents($geoUrl, false, $ctx);
    if ($geoData) {
        $geoJson = json_decode($geoData, true);
        if (!empty($geoJson[0])) {
            $geoLat = $geoJson[0]['lat'];
            $geoLng = $geoJson[0]['lon'];
        }
    }
}

$customers = all("SELECT customer_id, name, email, phone FROM customer WHERE status=1 AND email IS NOT NULL AND email!='' ORDER BY name");
include __DIR__ . '/../includes/layout.php';

// Social links helper
function socialLinks($name, $email='', $phone='') {
    $ne = urlencode($name);
    $ee = urlencode($email); // email encoded (no quotes!)
    $ph = preg_replace('/[^+0-9]/','',$phone);
    $pe = urlencode($ph); // phone encoded (no quotes!)
    $phClean = str_replace('+49','0',$ph); // German local format
    $phInt = ltrim($ph,'+'); // International without +
    // Smart search: name + email + phone WITHOUT forced quotes (Google finds more)
    $all = $ne.($email ? '+OR+'.$ee : '').($phone ? '+OR+'.$pe : '');
    return [
        // === KONTAKT ===
        'Kontakt' => [
            ['WhatsApp','https://wa.me/'.$phInt],['Telegram','https://t.me/+'.$phInt],
            ['SMS','sms:'.$ph],['Anrufen','tel:'.$ph],['Email','mailto:'.$email],
        ],
        // === SOCIAL MEDIA ===
        'Social Media' => [
            ['Facebook','https://www.facebook.com/search/top/?q='.$ne],
            ['FB+Email','https://www.facebook.com/search/top/?q='.($email?urlencode($email):$ne)],
            ['Instagram','https://www.instagram.com/'.$ne.'/'],
            ['TikTok','https://www.tiktok.com/search?q='.$ne],
            ['X/Twitter','https://x.com/search?q='.$ne.'&f=user'],
            ['YouTube','https://www.youtube.com/results?search_query='.$ne],
            ['LinkedIn','https://www.google.com/search?q=site:linkedin.com/in+'.$ne.''],
            ['XING','https://www.xing.com/search/members?keywords='.$ne],
            ['Reddit','https://www.reddit.com/search/?q='.$ne.''],
            ['Pinterest','https://www.pinterest.com/search/users/?q='.$ne],
            ['Snapchat','https://www.snapchat.com/add/'.$ne],
            ['Threads','https://www.threads.net/search?q='.$ne],
        ],
        // === PERSONEN & TELEFONBUCH ===
        'Personen & Telefonbuch' => [
            ['Das Örtliche (Tel)','https://www.dasoertliche.de/Themen/R%C3%BCckw%C3%A4rtssuche/'.urlencode($phClean)],
            ['Das Örtliche (Name)','https://www.dasoertliche.de/Themen/'.$ne],
            ['Gelbe Seiten','https://www.gelbeseiten.de/suche/'.$ne],
            ['11880','https://www.11880.com/suche/'.$ne.'/bundesweit'],
            ['Tellows','https://www.tellows.de/num/'.urlencode($phClean)],
            ['Google Tel','https://www.google.com/search?q='.$pe],
            ['Google Tel (lokal)','https://www.google.com/search?q='.urlencode($phClean)],
            ['Google Name+Tel','https://www.google.com/search?q='.$ne.'+'.$pe],
            ['Personensuche','https://www.google.com/search?q='.$ne.'+Berlin+OR+Deutschland+OR+Adresse'],
            ['Namens-Check','https://www.google.com/search?q='.$ne.'+Lebenslauf+OR+CV+OR+Profil'],
        ],
        // === EMAIL OSINT (NO quotes — Google finds more without exact match) ===
        'Email OSINT' => [
            ['Google Email','https://www.google.com/search?q='.$ee],
            ['Google +Name','https://www.google.com/search?q='.$ee.'+'.$ne],
            ['HaveIBeenPwned','https://haveibeenpwned.com/account/'.urlencode($email)],
            ['Epieos','https://epieos.com/?q='.urlencode($email)],
            ['Hunter.io','https://hunter.io/email-verifier/'.urlencode($email)],
            ['EmailRep','https://emailrep.io/'.urlencode($email)],
            ['Gravatar','https://www.gravatar.com/'.md5(strtolower(trim($email)))],
            ['Google Pastes','https://www.google.com/search?q='.$ee.'+site:pastebin.com+OR+site:ghostbin.com'],
            ['Social Media','https://www.google.com/search?q='.$ee.'+site:facebook.com+OR+site:instagram.com+OR+site:linkedin.com'],
            ['Registrierungen','https://www.google.com/search?q='.$ee.'+site:github.com+OR+site:stackoverflow.com+OR+site:reddit.com'],
        ],
        // === BILDER & GESICHTSERKENNUNG ===
        'Bilder & Gesicht' => [
            ['Google Images','https://www.google.com/search?q='.$ne.'&tbm=isch'],
            ['TinEye','https://tineye.com/search?url='],
            ['PimEyes','https://pimeyes.com/en'],
            ['Yandex Images','https://yandex.com/images/search?text='.$ne],
            ['Social Catfish','https://socialcatfish.com/'],
            ['FaceCheck.ID','https://facecheck.id/'],
        ],
        // === FIRMEN & FINANZEN ===
        'Firmen & Finanzen' => [
            ['Northdata','https://www.northdata.de/'.$ne],
            ['OpenCorporates','https://opencorporates.com/companies?q='.$ne],
            ['Handelsregister','https://www.handelsregister.de/rp_web/search.xhtml?s=searchform&registerArt=&registerNummer=&schlagwoerter='.$ne.'&schlagwortOptionen=like'],
            ['Unternehmensreg.','https://www.unternehmensregister.de/ureg/result.html;?submitaction=search&searchtype=quick&query='.$ne],
            ['Bundesanzeiger','https://www.bundesanzeiger.de/pub/de/to_nlp_start?destatis_nlp_q='.$ne],
            ['Creditreform','https://www.google.com/search?q=site:creditreform.de+'.$ne.''],
            ['Wer-zu-Wem','https://www.wer-zu-wem.de/suche/?query='.$ne],
            ['Firmenwissen','https://www.firmenwissen.de/az/firmeneintrag/'.$ne.'.html'],
            ['LEI/GLEIF','https://search.gleif.org/#/search/simpleSearch='.$ne],
            ['Geschäftsführer','https://www.google.com/search?q='.$ne.'+Gesch%C3%A4ftsf%C3%BChrer+OR+Inhaber+OR+Gesellschafter'],
            ['EU Company','https://e-justice.europa.eu/489/EN/business_registers__search_for_a_company_in_the_eu'],
        ],
        // === BEWERTUNGEN ===
        'Bewertungen & Reputation' => [
            ['Google Reviews','https://www.google.com/search?q='.$ne.'+Bewertung+OR+Review+OR+Erfahrung'],
            ['Trustpilot','https://www.trustpilot.com/search?query='.$ne],
            ['Kununu','https://www.kununu.com/search?q='.$ne],
            ['ProvenExpert','https://www.google.com/search?q=site:provenexpert.com+'.$ne.''],
            ['Yelp','https://www.yelp.com/search?find_desc='.$ne],
            ['Google News','https://www.google.com/search?q='.$ne.'&tbm=nws'],
            ['Scamadviser','https://www.scamadviser.com/check-website/'.($email?urlencode(substr($email,strpos($email,'@')+1)):'')],
        ],
        // === IMMOBILIEN & TOURISMUS ===
        'Immobilien & Tourismus' => [
            ['Airbnb','https://www.airbnb.com/s/'.$ne.'/homes'],
            ['Booking.com','https://www.booking.com/searchresults.html?ss='.$ne],
            ['ImmoScout','https://www.immobilienscout24.de/Suche/?searchQuery='.$ne],
            ['Immowelt','https://www.immowelt.de/suche/?searchQuery='.$ne],
            ['Kleinanzeigen','https://www.kleinanzeigen.de/s-'.$ne.'/k0'],
            ['Google Maps','https://www.google.com/maps/search/'.$ne],
            ['Grundbuch','https://www.google.com/search?q='.$ne.'+Grundbuch+OR+Eigent%C3%BCmer'],
        ],
        // === RECHT & COMPLIANCE ===
        'Recht & Compliance' => [
            ['Insolvenz','https://neu.insolvenzbekanntmachungen.de/ap/suche.jsf'],
            ['Vollstreckung','https://www.vollstreckungsportal.de/bekanntmachungen/suche'],
            ['Insolvenz (Google)','https://www.google.com/search?q='.$ne.'+Insolvenz+OR+insolvent'],
            ['Schuldner','https://www.google.com/search?q='.$ne.'+Schuldner+OR+Vollstreckung+OR+Pfändung'],
            ['EU Sanctions','https://www.sanctionsmap.eu/#/main?search=%7B%22value%22:'.$ne.'%7D'],
            ['OFAC','https://sanctionssearch.ofac.treas.gov/Details.aspx?id=0&search='.$ne],
            ['Gericht/Klage','https://www.google.com/search?q='.$ne.'+Gericht+OR+Urteil+OR+Klage'],
            ['Betrug/Scam','https://www.google.com/search?q='.$ne.'+Betrug+OR+Scam+OR+Warnung+OR+fraud'],
            ['Polizei','https://www.google.com/search?q='.$ne.'+Polizei+OR+Festnahme+OR+Anzeige'],
            ['INTERPOL','https://www.interpol.int/How-we-work/Notices/View-Red-Notices'],
            ['Court Records','https://www.google.com/search?q='.$ne.'+Gericht+OR+Verfahren+OR+Urteil+OR+court'],
        ],
        // === ONLINE SPUREN ===
        'Online Spuren' => [
            ['Google Alles','https://www.google.com/search?q='.$ne.($email ? '+'.$ee : '')],
            ['Google Bilder','https://www.google.com/search?q='.$ne.'&tbm=isch'],
            ['Google News','https://www.google.com/search?q='.$ne.'&tbm=nws'],
            ['Google Dokumente','https://www.google.com/search?q='.$ne.'+filetype:pdf+OR+filetype:doc+OR+filetype:xls'],
            ['Wayback Machine','https://web.archive.org/web/*/'.$ne],
            ['Archive.org','https://archive.org/search?query='.$ne],
            ['Pastebin','https://www.google.com/search?q=site:pastebin.com+'.$ne],
            ['GitHub','https://github.com/search?q='.($email?$ee:$ne).'&type=users'],
        ],
        // === LEAKS & BREACHES ===
        'Leaks & Breaches' => [
            ['HIBP Email','https://haveibeenpwned.com/account/'.urlencode($email)],
            ['DeHashed','https://www.dehashed.com/search?query='.$ne],
            ['IntelX','https://intelx.io/?s='.$ne],
            ['BreachDirectory','https://breachdirectory.org/search?query='.urlencode($email)],
            ['Google Leaks','https://www.google.com/search?q='.$ne.'+leak+OR+breach+OR+password+OR+database+OR+dump'],
        ],
        // === BEHÖRDEN & REGISTER (DE) ===
        'Behörden & Register' => [
            ['Grundbuch Berlin','https://www.google.com/search?q='.$ne.'+Grundbuch+Berlin+OR+Grundbuchamt'],
            ['Gewerberegister','https://www.google.com/search?q='.$ne.'+Gewerberegister+OR+Gewerbeanmeldung+Berlin'],
            ['Finanzamt','https://www.google.com/search?q='.$ne.'+Finanzamt+OR+Steuernummer+OR+USt-IdNr'],
            ['Bauamt','https://www.google.com/search?q='.$ne.'+Bauamt+OR+Baugenehmigung+OR+Bauantrag+Berlin'],
            ['Vereinsregister','https://www.google.com/search?q='.$ne.'+site:registerportal.de+OR+Vereinsregister'],
            ['Transparenzregister','https://www.google.com/search?q='.$ne.'+Transparenzregister+OR+wirtschaftlich+Berechtigter'],
            ['Grundstückseigentümer','https://www.google.com/search?q='.$ne.'+Eigent%C3%BCmer+OR+Grundst%C3%BCck+OR+Immobilie'],
        ],
        // === ANZEIGEN & MARKTPLATZ ===
        'Anzeigen & Marktplatz' => [
            ['Kleinanzeigen','https://www.kleinanzeigen.de/s-'.$ne.'/k0'],
            ['Quoka','https://www.quoka.de/suche/?q='.$ne],
            ['Markt.de','https://www.markt.de/suche/?keywords='.$ne],
            ['Tutti.ch','https://www.tutti.ch/de/q/'.$ne],
            ['Willhaben.at','https://www.willhaben.at/iad/kaufen-und-verkaufen/marktplatz?keyword='.$ne],
            ['eBay DE','https://www.ebay.de/sch/i.html?_nkw='.$ne],
            ['Amazon Seller','https://www.google.com/search?q=site:amazon.de+'.$ne.'+Verk%C3%A4ufer+OR+Händler'],
        ],
    ];
}
?>

<!-- Scan Form -->
<div class="bg-white rounded-xl border mb-4">
  <form method="post" class="p-4" id="scanForm">
    <?= csrfField() ?>
    <div class="grid grid-cols-2 md:grid-cols-7 gap-3">
      <div><label class="block text-xs font-semibold text-gray-500 mb-1">E-Mail</label>
        <input type="text" name="scan_email" value="<?= e($scanEmail) ?>" placeholder="name@firma.de" class="w-full px-3 py-2.5 border rounded-lg" id="fEmail"/></div>
      <div><label class="block text-xs font-semibold text-gray-500 mb-1">Name</label>
        <input type="text" name="scan_name" value="<?= e($scanName) ?>" placeholder="Max Mustermann" class="w-full px-3 py-2.5 border rounded-lg" id="fName"/></div>
      <div><label class="block text-xs font-semibold text-gray-500 mb-1">Telefon</label>
        <input type="text" name="scan_phone" value="<?= e($scanPhone) ?>" placeholder="+49..." class="w-full px-3 py-2.5 border rounded-lg" id="fPhone"/></div>
      <div><label class="block text-xs font-semibold text-gray-500 mb-1">Adresse</label>
        <input type="text" name="scan_address" value="<?= e($scanAddress) ?>" placeholder="Straße Nr, PLZ Stadt" class="w-full px-3 py-2.5 border rounded-lg" id="fAddress"/></div>
      <div><label class="block text-xs font-semibold text-gray-500 mb-1">Nr (HRB/Steuer/Az)</label>
        <input type="text" name="scan_serial" value="<?= e($scanSerial) ?>" placeholder="132/571/00584" class="w-full px-3 py-2.5 border rounded-lg" id="fSerial"/></div>
      <div><label class="block text-xs font-semibold text-gray-500 mb-1">Kennzeichen</label>
        <input type="text" name="scan_plate" value="<?= e($scanPlate ?? '') ?>" placeholder="B-AB 1234" class="w-full px-3 py-2.5 border rounded-lg" id="fPlate"/></div>
      <div class="flex items-end">
        <button class="w-full px-4 py-2.5 bg-brand text-white font-medium rounded-lg">Scan</button>
      </div>
    </div>
    <!-- Extended identifiers (collapsible) -->
    <details class="mt-3" id="extFields">
      <summary class="text-xs font-semibold text-gray-500 cursor-pointer hover:text-brand">+ Erweiterte Identifier (Geburtsdatum, Ausweis, Steuer-Nr...)</summary>
      <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mt-2">
        <div><label class="block text-xs text-gray-400 mb-1">Geburtsdatum</label>
          <input type="text" name="scan_dob" value="<?= e($scanDob) ?>" placeholder="TT.MM.JJJJ" class="w-full px-3 py-2 border rounded-lg text-sm" id="fDob"/></div>
        <div><label class="block text-xs text-gray-400 mb-1">Ausweis-Nr</label>
          <input type="text" name="scan_id_nr" value="<?= e($scanIdNr) ?>" placeholder="T220001293" class="w-full px-3 py-2 border rounded-lg text-sm" id="fIdNr"/></div>
        <div><label class="block text-xs text-gray-400 mb-1">Reisepass-Nr</label>
          <input type="text" name="scan_passport" value="<?= e($scanPassport) ?>" placeholder="C01X00T47" class="w-full px-3 py-2 border rounded-lg text-sm" id="fPassport"/></div>
        <div><label class="block text-xs text-gray-400 mb-1">Serien/Gewerbe-Nr</label>
          <input type="text" name="scan_serial" value="<?= e($scanSerial) ?>" placeholder="GewA-2024-12345" class="w-full px-3 py-2 border rounded-lg text-sm" id="fSerial"/></div>
        <div><label class="block text-xs text-gray-400 mb-1">Steuer/USt-IdNr</label>
          <input type="text" name="scan_tax_id" value="<?= e($scanTaxId) ?>" placeholder="DE123456789" class="w-full px-3 py-2 border rounded-lg text-sm" id="fTaxId"/></div>
      </div>
    </details>
  </form>
</div>

<!-- Deep Scan — auto-triggers after Quick Scan -->
<div id="deepScanProgress" class="hidden mb-4 p-4 bg-white rounded-xl border">
  <div class="flex items-center gap-3"><div class="animate-spin w-5 h-5 border-2 border-brand border-t-transparent rounded-full"></div><span class="text-sm font-medium text-gray-700" id="deepScanStatus">Deep Scan läuft...</span></div>
  <div class="mt-2 bg-gray-200 rounded-full h-1.5"><div id="deepScanBar" class="h-1.5 rounded-full bg-brand transition-all" style="width:5%"></div></div>
  <div class="text-xs text-gray-400 mt-1" id="deepScanEta">40+ Quellen werden parallel abgefragt</div>
</div>
<div id="deepScanResults" class="hidden mb-4"></div>
<?php if ($scan): ?>
<script>document.addEventListener('DOMContentLoaded', () => setTimeout(startDeepScan, 300));</script>
<?php endif; ?>

<script>
async function startDeepScan() {
    const btn = document.getElementById('deepScanBtn');
    const prog = document.getElementById('deepScanProgress');
    const res = document.getElementById('deepScanResults');
    const email = document.getElementById('fEmail')?.value || '';
    const name = document.getElementById('fName')?.value || '';
    const phone = document.getElementById('fPhone')?.value || '';
    const address = document.getElementById('fAddress')?.value || '';
    const serial = document.getElementById('fSerial')?.value || '';
    const taxid = document.getElementById('fTaxId')?.value || '';
    const plate = document.getElementById('fPlate')?.value || '';
    if (!email && !name && !phone && !serial && !taxid && !plate) { alert('Mindestens ein Feld ausfüllen'); return; }
    btn.disabled = true; btn.classList.add('opacity-50');
    prog.classList.remove('hidden'); res.classList.add('hidden'); res.innerHTML = '';
    // Animate progress bar
    let pct = 5;
    const stages = ['DB-Abfrage...','DNS & Email...','Social Media...','Handelsregister...','Deep Intelligence...','Korrelation...','Fertig!'];
    let stage = 0;
    const progTimer = setInterval(() => {
        pct = Math.min(pct + 3, 95);
        document.getElementById('deepScanBar').style.width = pct+'%';
        if (pct > stage * 14 + 10 && stage < stages.length) {
            document.getElementById('deepScanStatus').textContent = stages[stage++];
        }
    }, 800);

    try {
        const resp = await fetch('/api/osint-deep.php', {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({
                email, name, phone, address,
                dob: document.getElementById('fDob')?.value || '',
                id_number: document.getElementById('fIdNr')?.value || '',
                passport: document.getElementById('fPassport')?.value || '',
                serial: document.getElementById('fSerial')?.value || '',
                tax_id: document.getElementById('fTaxId')?.value || '',
                plate: document.getElementById('fPlate')?.value || '',
            })
        });
        const data = await resp.json();
        clearInterval(progTimer);
        document.getElementById('deepScanBar').style.width = '100%';
        document.getElementById('deepScanStatus').textContent = 'Fertig!';
        setTimeout(() => prog.classList.add('hidden'), 500);
        if (data.success) {
            renderDeepResults(data.data, res);
            // Also populate the main deep results section
            var mainRes = document.getElementById('deepResults');
            var mainCards = document.getElementById('deepCards');
            if (mainRes && mainCards) {
                renderDeepCards(data.data, mainCards);
                mainRes.classList.remove('hidden');
            }
        }
        else res.innerHTML = '<div class="p-3 bg-red-50 text-red-700 rounded-lg">Fehler: '+(data.error||'Unbekannt')+'</div>';
        res.classList.remove('hidden');
    } catch(e) {
        clearInterval(progTimer);
        prog.classList.add('hidden');
        res.innerHTML = '<div class="p-3 bg-red-50 text-red-700 rounded-lg">Netzwerkfehler: '+e.message+'</div>';
        res.classList.remove('hidden');
    }
    btn.disabled = false; btn.classList.remove('opacity-50');
}

function renderDeepResults(d, container) {
    let html = '';
    const dos = d.dossier || {};
    const vf = dos.verification || {};
    const rc = {LOW:'green',MEDIUM:'yellow',HIGH:'red'}[dos.risk_level] || 'gray';

    // === TOP BAR: Risiko + Verifikation + Scan-Info + Quick Actions ===
    const totalFindings = (dos.findings?.length||0) + (d.holehe?.count||0) + (d.maigret?.found||0) + (d.socialscan?.taken_on||0);
    html += `<div class="bg-white rounded-xl border mb-3 p-4">
        <div class="flex items-center gap-3 flex-wrap">
            <span class="px-3 py-1.5 rounded-lg text-sm font-bold bg-${rc}-100 text-${rc}-800">Risiko: ${dos.risk_level||'?'}</span>
            ${vf.overall_confidence !== undefined ? `<span class="px-3 py-1.5 rounded-lg text-sm font-bold bg-blue-100 text-blue-800">${vf.overall_confidence}% verifiziert</span>` : ''}
            ${totalFindings > 0 ? `<span class="px-3 py-1.5 rounded-lg text-sm font-bold bg-green-100 text-green-800">${totalFindings} Treffer</span>` : ''}
            <span class="text-xs text-gray-400 ml-auto">${d._meta?.scan_time_seconds||'?'}s · ${d._meta?.modules_run||'?'} Module · ${dos.data_sources?.length||0} Quellen</span>
        </div>
        ${dos.findings?.length ? `<div class="mt-3 pt-3 border-t"><div class="grid grid-cols-1 sm:grid-cols-2 gap-1">${dos.findings.slice(0,6).map(f=>`<div class="flex items-start gap-1.5 text-sm"><span class="text-green-500 shrink-0 mt-0.5">&#10003;</span><span>${f}</span></div>`).join('')}</div></div>` : ''}
        ${dos.risk_factors?.length ? `<div class="mt-2"><div class="grid grid-cols-1 sm:grid-cols-2 gap-1">${dos.risk_factors.map(f=>`<div class="flex items-start gap-1.5 text-sm text-red-700"><span class="shrink-0 mt-0.5">&#9888;</span><span>${f}</span></div>`).join('')}</div></div>` : ''}
    </div>`;

    // === TABS ===
    const tabs = ['Ergebnis','Funde','Suche','SearXNG','Details'];
    html += `<div class="bg-white rounded-xl border mb-3 overflow-hidden">
        <div class="flex border-b" id="dsTabs">
            ${tabs.map((t,i)=>`<button onclick="dsTab(${i})" class="px-4 py-2.5 text-sm font-medium ${i===0?'text-brand border-b-2 border-brand':'text-gray-500 hover:text-gray-700'}" data-tab="${i}">${t}</button>`).join('')}
        </div>
        <div class="p-4">`;

    // --- TAB 0: Ergebnis (Dossier summary) ---
    html += '<div class="ds-panel" data-panel="0">';
    if (dos.findings?.length || dos.risk_factors?.length) {
        html += '<div class="grid grid-cols-1 md:grid-cols-2 gap-4">';
        html += `<div><h4 class="font-semibold text-sm text-gray-600 mb-2">Erkenntnisse (${dos.findings?.length||0})</h4><ul class="text-sm space-y-1">${(dos.findings||[]).map(f=>`<li class="flex gap-1"><span class="text-green-500 shrink-0">&#10003;</span><span>${f}</span></li>`).join('')}</ul></div>`;
        html += `<div><h4 class="font-semibold text-sm text-gray-600 mb-2">Risiken (${dos.risk_factors?.length||0})</h4><ul class="text-sm space-y-1">${(dos.risk_factors||[]).map(f=>`<li class="flex gap-1"><span class="text-red-500 shrink-0">&#9888;</span><span>${f}</span></li>`).join('')}</ul></div>`;
        html += '</div>';
    }
    // Identity Graph (compact)
    if (d.correlation?.identity_graph?.length) {
        html += `<div class="mt-3 pt-3 border-t"><h4 class="font-semibold text-xs text-gray-500 mb-1">Verifizierte Accounts</h4><div class="flex flex-wrap gap-1">${d.correlation.identity_graph.map(ig=>`<a href="${ig.url}" target="_blank" class="px-2 py-0.5 bg-brand/10 text-brand rounded text-xs hover:underline">${ig.platform}</a>`).join('')}</div></div>`;
    }
    html += '</div>';

    // --- TAB 1: Funde (Deep Intel, compact) ---
    html += '<div class="ds-panel hidden" data-panel="1">';
    const di = d.deep_intel || {};
    const fundeItems = [];
    if (di.gravatar?.found) fundeItems.push(`<div class="flex items-center gap-2 p-2 bg-purple-50 rounded"><img src="${di.gravatar.avatar}" class="w-8 h-8 rounded-full"><div><div class="text-sm font-medium">${di.gravatar.display_name||'Gravatar'}</div><div class="text-xs text-gray-500">${di.gravatar.about||di.gravatar.location||'Profil gefunden'}</div></div></div>`);
    if (di.telegram_profiles?.length) fundeItems.push(...di.telegram_profiles.map(t=>`<div class="p-2 bg-blue-50 rounded"><a href="${t.url}" target="_blank" class="text-sm text-brand font-medium">Telegram: @${t.username}</a> <span class="text-xs text-gray-500">${t.display_name||''}</span></div>`));
    if (di.social_deep) {
        const sdLabels = {instagram_profile:'Instagram',instagram_tagged:'Insta Tagged',linkedin_profile:'LinkedIn',linkedin_company:'LinkedIn Firma',xing_profile:'XING',facebook_profile:'Facebook',tiktok_profile:'TikTok',social_email:'Social (Email)'};
        const sdColors = {instagram_profile:'#e1306c',linkedin_profile:'#0a66c2',facebook_profile:'#1877f2',tiktok_profile:'#000',xing_profile:'#006567'};
        for (const [key, hits] of Object.entries(di.social_deep)) {
            if (!Array.isArray(hits) || !hits.length) continue;
            const color = sdColors[key] || '#666';
            fundeItems.push(`<div class="p-2 rounded" style="background:${color}11;border:1px solid ${color}33"><div class="text-xs font-semibold" style="color:${color}">${sdLabels[key]||key} (${hits.length})</div>${hits.slice(0,3).map(r=>`<a href="${r.url}" target="_blank" class="text-xs text-brand block hover:underline truncate">${r.title}</a>`).join('')}</div>`);
        }
    }
    if (di.impressum_mentions) fundeItems.push(...di.impressum_mentions.results.slice(0,3).map(r=>`<div class="p-2 bg-amber-50 rounded"><div class="text-xs font-semibold text-amber-700">Impressum</div><a href="${r.url}" target="_blank" class="text-sm text-brand hover:underline">${r.title}</a></div>`));
    if (di.insolvency) fundeItems.push(...di.insolvency.results.slice(0,3).map(r=>`<div class="p-2 bg-red-50 rounded border border-red-200"><div class="text-xs font-semibold text-red-700">INSOLVENZ</div><a href="${r.url}" target="_blank" class="text-sm text-red-700">${r.title}</a></div>`));
    if (di.paste_leaks) fundeItems.push(`<div class="p-2 bg-red-50 rounded"><div class="text-xs font-semibold text-red-700">Paste-Leaks (${di.paste_leaks.count})</div>${di.paste_leaks.results.slice(0,2).map(r=>`<a href="${r.url}" target="_blank" class="text-xs text-red-600 block">${r.title}</a>`).join('')}</div>`);
    if (di.leaked_docs) fundeItems.push(`<div class="p-2 bg-orange-50 rounded"><div class="text-xs font-semibold text-orange-700">Dokumente (${di.leaked_docs.count})</div>${di.leaked_docs.results.slice(0,2).map(r=>`<a href="${r.url}" target="_blank" class="text-xs text-brand block">${r.title}</a>`).join('')}</div>`);
    if (d.handelsregister) fundeItems.push(...d.handelsregister.companies.slice(0,3).map(c=>`<div class="p-2 bg-gray-50 rounded"><div class="text-xs font-semibold text-gray-500">Handelsregister</div><div class="text-sm font-medium">${c.name}</div><div class="text-xs text-gray-400">${c.register_type} ${c.register_number} · ${c.register_court}</div></div>`));
    if (d.plate_search) {
        const pl = d.plate_search;
        const allPlateHits = Object.values(pl.results||{}).flat().slice(0,5);
        const vd = pl.vehicle_data||{};
        let plateHtml = `<div class="p-3 bg-gray-800 text-white rounded-lg"><div class="flex items-center gap-2 mb-2"><span class="px-2 py-1 bg-blue-600 rounded text-sm font-bold">D</span><span class="font-bold font-mono text-xl tracking-wider">${pl.plate}</span><span class="text-xs text-gray-400">${pl.region}</span></div>`;
        // Vehicle data from APIs
        if (vd.autodna) plateHtml += `<div class="text-xs text-green-400 mb-1">AutoDNA: Fahrzeugdaten gefunden</div>`;
        if (vd.vin_guru?.make) plateHtml += `<div class="text-xs text-green-400 mb-1">Fahrzeug: ${vd.vin_guru.make} ${vd.vin_guru.model||''} ${vd.vin_guru.year||''}</div>`;
        if (vd.mobile_de_results) plateHtml += `<div class="text-xs text-yellow-400 mb-1">mobile.de: ${vd.mobile_de_results} Ergebnisse</div>`;
        // Country detection
        if (pl.country) plateHtml = plateHtml.replace('>D<', `>${pl.country}<`);
        // EU search results
        const euHits = Object.entries(pl.eu_searches||{}).filter(([k,v])=>Array.isArray(v)&&v.length);
        const euLabels = {eu_exact:'Exakt',eu_car:'Auto',eu_images:'Bilder',eu_forums:'Foren',eu_repair:'Werkstatt',eu_accident:'Unfall',eu_sale:'Verkauf',eu_social:'Social',ro_olx:'OLX.ro',md_search:'999.md',ch_search:'AutoScout CH',pl_search:'OtoMoto PL',at_search:'willhaben AT',ro_registrul:'ONRC RO',ch_strassenverkehr:'SVA CH',pl_history:'CEPiK PL'};
        if (euHits.length) {
            plateHtml += '<div class="mt-1 space-y-0.5">';
            euHits.forEach(([k,hits])=>{ plateHtml += `<details class=""><summary class="text-xs text-gray-400 cursor-pointer hover:text-white">${euLabels[k]||k} (${hits.length})</summary>${hits.slice(0,3).map(r=>`<a href="${r.url}" target="_blank" class="text-[11px] text-blue-300 block hover:underline truncate pl-2">${r.title}</a>`).join('')}</details>`; });
            plateHtml += '</div>';
        }
        // Image results
        if (pl.plate_images?.length) plateHtml += `<div class="text-xs text-purple-300 mt-1">Bilder gefunden: ${pl.plate_images.map(r=>`<a href="${r.url}" target="_blank" class="hover:underline">${r.title}</a>`).join(', ')}</div>`;
        // Search results fallback
        if (!euHits.length && allPlateHits.length) plateHtml += allPlateHits.map(r=>`<a href="${r.url}" target="_blank" class="text-xs text-blue-300 block hover:underline truncate">${r.title}</a>`).join('');
        // Links
        if (pl.links) plateHtml += `<div class="flex flex-wrap gap-2 mt-2 pt-2 border-t border-gray-600">${Object.entries(pl.links).map(([k,v])=>`<a href="${v}" target="_blank" class="text-[10px] px-1.5 py-0.5 bg-gray-700 rounded text-gray-300 hover:text-white hover:bg-gray-600">${k.replace('_',' ')}</a>`).join('')}</div>`;
        // Insurance data
        if (pl.insurance?.gdv_api) plateHtml += `<div class="text-xs text-green-400 mt-1">Versicherung: ${JSON.stringify(pl.insurance.gdv_api).substring(0,150)}</div>`;
        if (pl.insurance?.web_results?.length) plateHtml += pl.insurance.web_results.map(r=>`<a href="${r.url}" target="_blank" class="text-xs text-yellow-300 block hover:underline truncate">${r.title}</a>`).join('');
        // Halter services
        if (pl.halter_services?.length) {
            plateHtml += '<div class="mt-2 pt-2 border-t border-gray-600 space-y-1">';
            plateHtml += '<div class="text-[10px] text-gray-400 font-semibold">Halter ermitteln:</div>';
            pl.halter_services.forEach(s => {
                const badge = s.type==='free' ? '<span class="text-[9px] bg-green-700 px-1 rounded">GRATIS</span>' : '<span class="text-[9px] bg-yellow-700 px-1 rounded">KOSTENPFLICHTIG</span>';
                plateHtml += `<a href="${s.url}" target="_blank" class="flex items-center gap-2 text-xs text-gray-300 hover:text-white">${badge} <span>${s.name}</span> <span class="text-gray-500">— ${s.info}</span></a>`;
            });
            plateHtml += '</div>';
        }
        plateHtml += '</div>';
        fundeItems.push(plateHtml);
    }
    if (d.registration_search) {
        for (const [nr, data] of Object.entries(d.registration_search)) {
            const nrType = data._type || 'Nummer';
            const allHits = [...(data.google||[]),...(data.register||[]),...(data.legal||[])].slice(0,4);
            if (allHits.length) fundeItems.push(`<div class="p-2 bg-indigo-50 rounded"><div class="text-xs font-semibold text-indigo-700">${nrType}: ${nr}</div>${allHits.map(r=>`<a href="${r.url}" target="_blank" class="text-xs text-brand block hover:underline">${r.title}</a>`).join('')}</div>`);
        }
    }
    // Holehe — email on 120+ sites
    if (d.holehe?.registered_on?.length) {
        fundeItems.push(`<div class="p-2 bg-violet-50 rounded"><div class="text-xs font-semibold text-violet-700">Holehe: Email auf ${d.holehe.count} Sites registriert</div><div class="flex flex-wrap gap-1 mt-1">${d.holehe.registered_on.map(s=>`<span class="px-1.5 py-0.5 bg-violet-100 text-violet-800 rounded text-[10px]">${s}</span>`).join('')}</div></div>`);
    }
    // Maigret — 2500+ sites username scan
    if (d.maigret?.profiles?.length) {
        fundeItems.push(`<div class="p-2 bg-emerald-50 rounded"><div class="text-xs font-semibold text-emerald-700">Maigret: ${d.maigret.found} Profile gefunden</div>${d.maigret.profiles.slice(0,8).map(p=>`<a href="${p.url}" target="_blank" class="text-xs text-brand block hover:underline">${p.site}</a>`).join('')}${d.maigret.found>8?`<div class="text-[10px] text-gray-400">+${d.maigret.found-8} weitere</div>`:''}</div>`);
    }
    // SocialScan
    if (d.socialscan?.platforms?.length) {
        fundeItems.push(`<div class="p-2 bg-cyan-50 rounded"><div class="text-xs font-semibold text-cyan-700">SocialScan: ${d.socialscan.taken_on} Plattformen</div><div class="flex flex-wrap gap-1 mt-1">${d.socialscan.platforms.slice(0,15).map(p=>`<span class="px-1.5 py-0.5 bg-cyan-100 text-cyan-800 rounded text-[10px]">${p.platform}</span>`).join('')}</div></div>`);
    }
    // Whois Deep
    if (d.whois_deep?.registrant || d.whois_deep?.org) {
        const w = d.whois_deep;
        fundeItems.push(`<div class="p-2 bg-gray-50 rounded"><div class="text-xs font-semibold text-gray-700">WHOIS Deep</div><div class="text-xs">${w.registrant?'Registrant: <b>'+w.registrant+'</b>':''}${w.org?' · Org: '+w.org:''}${w.registrar?' · '+w.registrar:''}${w.created?' · '+w.created:''}${w.expires?' · exp: '+w.expires:''}</div></div>`);
    }
    // PhoneInfoga
    if (d.phoneinfoga?.country) {
        const pi = d.phoneinfoga;
        fundeItems.push(`<div class="p-2 bg-teal-50 rounded"><div class="text-xs font-semibold text-teal-700">PhoneInfoga</div><div class="text-xs">Land: ${pi.country||'?'} · Carrier: ${pi.carrier||'?'} · Typ: ${pi.type||'?'}</div>${pi.international?`<div class="text-xs text-gray-500">${pi.international}</div>`:''}</div>`);
    }
    // SearXNG — real search engine results
    if (d.searxng) {
        const sxLabels = {searx_person:'Person',searx_business:'Firma',searx_reviews:'Bewertungen',searx_email:'Email',searx_phone:'Telefon'};
        for (const [sxKey, sx] of Object.entries(d.searxng)) {
            if (!sx.results?.length) continue;
            fundeItems.push(`<div class="p-3 bg-orange-50 rounded border border-orange-200"><div class="text-xs font-semibold text-orange-700 mb-1">SearXNG: ${sxLabels[sxKey]||sxKey} (${sx.count})</div>${sx.results.slice(0,4).map(r=>`<a href="${r.url}" target="_blank" class="text-xs text-brand block hover:underline">${r.title}</a>${r.snippet?`<div class="text-[10px] text-gray-400 truncate">${r.snippet}</div>`:''}`).join('')}</div>`);
        }
    }
    // GHunt — Google Account Intel
    if (d.ghunt?.found) {
        const gh = d.ghunt.data || {};
        let ghHtml = `<div class="p-3 bg-blue-50 rounded border border-blue-200"><div class="text-xs font-semibold text-blue-700 mb-1">GHunt — Google Account</div>`;
        if (gh.name) ghHtml += `<div class="text-xs">Name: <b>${gh.name}</b></div>`;
        if (gh.last_edit) ghHtml += `<div class="text-xs">Letzte Änderung: ${gh.last_edit}</div>`;
        if (gh.photo) ghHtml += `<a href="${gh.photo}" target="_blank" class="text-xs text-brand">Profilbild</a>`;
        if (gh.maps) ghHtml += `<div class="text-xs">${gh.maps}</div>`;
        ghHtml += '</div>';
        fundeItems.push(ghHtml);
    }
    // Airbnb
    if (d.airbnb_scan?.results) {
        const allAbnb = Object.values(d.airbnb_scan.results).flat().slice(0,4);
        if (allAbnb.length) fundeItems.push(`<div class="p-2 bg-pink-50 rounded"><div class="text-xs font-semibold text-pink-700">Airbnb/Booking Listings</div>${allAbnb.map(r=>`<a href="${r.url}" target="_blank" class="text-xs text-brand block hover:underline">${r.title}</a>`).join('')}${d.airbnb_scan.links?'<div class="flex gap-2 mt-1">'+Object.entries(d.airbnb_scan.links).map(([k,v])=>`<a href="${v}" target="_blank" class="text-[10px] text-gray-400 hover:text-brand">${k}</a>`).join('')+'</div>':''}</div>`);
    }
    // Impressum Validation
    if (d.impressum_validation) {
        const iv = d.impressum_validation;
        const ivColor = iv.valid ? 'green' : (iv.found ? 'red' : 'gray');
        let ivHtml = `<div class="p-2 bg-${ivColor}-50 rounded border border-${ivColor}-200"><div class="text-xs font-semibold text-${ivColor}-700">Impressum ${iv.valid?'OK':'PROBLEM'}</div>`;
        if (iv.extracted) {
            const ex = iv.extracted;
            if (ex.owner) ivHtml += `<div class="text-xs">Inhaber: <b>${ex.owner}</b></div>`;
            if (ex.hrb) ivHtml += `<div class="text-xs">HRB: ${ex.hrb}</div>`;
            if (ex.vat) ivHtml += `<div class="text-xs">USt: ${ex.vat}</div>`;
            if (ex.email) ivHtml += `<div class="text-xs">Email: ${ex.email}</div>`;
            if (ex.phone) ivHtml += `<div class="text-xs">Tel: ${ex.phone}</div>`;
        }
        if (iv.issues?.length) ivHtml += `<div class="text-xs text-red-600 mt-1">${iv.issues.join(' · ')}</div>`;
        if (iv.url) ivHtml += `<a href="${iv.url}" target="_blank" class="text-[10px] text-brand">Impressum ansehen</a>`;
        ivHtml += '</div>';
        fundeItems.push(ivHtml);
    }
    // Website Deep
    if (d.website_deep) {
        const wd = d.website_deep;
        let wdHtml = `<div class="p-2 bg-cyan-50 rounded"><div class="text-xs font-semibold text-cyan-700">Website (${wd.pages?.length||0} Seiten)</div>`;
        if (wd.emails_found?.length) wdHtml += `<div class="text-xs">Emails: ${wd.emails_found.slice(0,3).join(', ')}</div>`;
        if (wd.phones_found?.length) wdHtml += `<div class="text-xs">Tel: ${wd.phones_found.slice(0,3).join(', ')}</div>`;
        if (wd.social_links?.length) wdHtml += `<div class="text-xs">Social: ${wd.social_links.slice(0,3).map(u=>`<a href="${u}" target="_blank" class="text-brand">${new URL(u).hostname.replace('www.','')}</a>`).join(', ')}</div>`;
        wdHtml += '</div>';
        fundeItems.push(wdHtml);
    }
    // Business Intel (NorthData, Bewertungen, Versicherung etc.)
    if (d.business_intel) {
        const bi = d.business_intel;
        const biLabels = {northdata:'NorthData',bundesanzeiger:'Bundesanzeiger',creditreform:'Creditreform',firmenwissen:'Firmenwissen',werzuwem:'Wer-zu-Wem',versicherung:'Versicherung',bewertungen:'Bewertungen',transparency:'Transparenzreg.',genios:'Genios',unternehmensreg:'Unternehmensreg.'};
        for (const [key, hits] of Object.entries(bi)) {
            if (!Array.isArray(hits) || !hits.length) continue;
            fundeItems.push(`<div class="p-2 bg-gray-50 rounded"><div class="text-xs font-semibold text-gray-700">${biLabels[key]||key} (${hits.length})</div>${hits.slice(0,2).map(r=>`<a href="${r.url}" target="_blank" class="text-xs text-brand block hover:underline truncate">${r.title}</a>`).join('')}</div>`);
        }
    }
    if (d.correlation?.connections?.length) fundeItems.push(...d.correlation.connections.map(c=>`<div class="p-2 ${c.type.includes('warning')?'bg-red-50':'bg-blue-50'} rounded text-sm">${c.detail}</div>`));
    // IntelX — Leaked Data / Dark Web
    if (d.vulture_web) {
        for (const [key, data] of Object.entries(d.vulture_web)) {
            if (!key.startsWith('intelx_') || !data.results?.length) continue;
            fundeItems.push(`<div class="p-3 bg-red-900 text-white rounded"><div class="text-xs font-bold text-red-300 mb-1">Intelligence X: ${data.query} (${data.count} Treffer)</div><div class="space-y-0.5">${data.results.slice(0,8).map(r=>`<div class="text-xs"><span class="text-red-400">${r.type}:</span> ${r.value} <span class="text-red-500 text-[10px]">${r.source}</span></div>`).join('')}</div>${data.total>8?`<div class="text-[10px] text-red-400 mt-1">+${data.total-8} weitere in IntelX</div>`:''}</div>`);
        }
    }
    // Perplexity AI Search Results (from Vulture)
    if (d.vulture_web) {
        for (const [key, data] of Object.entries(d.vulture_web)) {
            if (!data.answer) continue;
            fundeItems.push(`<div class="p-3 bg-indigo-50 rounded border border-indigo-200"><div class="text-xs font-semibold text-indigo-700 mb-1">Perplexity AI: ${key}</div><div class="text-xs text-gray-700 whitespace-pre-line">${data.answer.substring(0,400)}${data.answer.length>400?'...':''}</div>${data.citations?.length?'<div class="mt-1 text-[10px] text-gray-400">Quellen: '+data.citations.slice(0,3).map((c,i)=>`<a href="${c}" target="_blank" class="text-brand hover:underline">[${i+1}]</a>`).join(' ')+'</div>':''}</div>`);
        }
    }
    // Legacy Vulture Web Search Results (DDG-based, may be empty)
    if (d.vulture_web) {
        const vLabels = {gelbe_seiten:'Gelbe Seiten',das_oertliche:'Das Örtliche',pagini_aurii:'Pagini Aurii (RO)',northdata:'NorthData',bundesanzeiger:'Bundesanzeiger',firmenwissen:'Firmenwissen',insolvenz:'Insolvenz',impressum:'Impressum',airbnb:'Airbnb',booking:'Booking.com',kleinanzeigen:'Kleinanzeigen',social:'Social Media',bewertungen:'Bewertungen',gericht:'Gericht/Polizei',dokumente:'Dokumente',email_trace:'Email Spur',email_social:'Email Social',email_paste:'Email Leaks',phone_trace:'Telefon Spur',phone_tellows:'Tellows',plate_exact:'Kennzeichen',plate_auto:'KFZ',address_immo:'Immobilien','11880':'11880'};
        for (const [key, data] of Object.entries(d.vulture_web)) {
            if (!data.results?.length) continue;
            const label = vLabels[key] || key;
            const isRisk = ['insolvenz','gericht','email_paste'].includes(key);
            fundeItems.push(`<div class="p-2 ${isRisk?'bg-red-50 border border-red-200':'bg-gray-50'} rounded"><div class="text-xs font-semibold ${isRisk?'text-red-700':'text-gray-700'}">${label} (${data.count})</div>${data.results.slice(0,3).map(r=>`<a href="${r.url}" target="_blank" class="text-xs text-brand block hover:underline truncate">${r.title}</a>`).join('')}</div>`);
        }
    }
    // Prioritize items with actual data (not just links)
    const dataItems = fundeItems.filter(f => !f.includes('hover:underline') || f.includes('bg-red-900') || f.includes('bg-indigo-50') || f.includes('Holehe') || f.includes('Maigret') || f.includes('PhoneInfoga') || f.includes('Gravatar') || f.includes('Telegram') || f.includes('WHOIS') || f.includes('Impressum'));
    const linkItems = fundeItems.filter(f => !dataItems.includes(f));
    if (dataItems.length) {
        html += `<div class="mb-3"><h4 class="text-xs font-semibold text-gray-500 mb-2 uppercase tracking-wider">Verifizierte Funde (${dataItems.length})</h4><div class="grid grid-cols-1 md:grid-cols-2 gap-2">${dataItems.join('')}</div></div>`;
    }
    if (linkItems.length) {
        html += `<details class="mt-2"><summary class="text-xs font-semibold text-gray-400 cursor-pointer hover:text-gray-600">+ ${linkItems.length} weitere Suchergebnisse</summary><div class="grid grid-cols-1 md:grid-cols-2 gap-2 mt-2">${linkItems.join('')}</div></details>`;
    }
    if (!fundeItems.length) html += '<div class="text-sm text-gray-400">Keine besonderen Funde</div>';
    html += '</div>';

    // --- TAB 2: Suche (Permutationen + Usernames) ---
    html += '<div class="ds-panel hidden" data-panel="2">';
    if (d.permutation_search) {
        html += '<div class="space-y-1 mb-3">';
        for (const [perm, data] of Object.entries(d.permutation_search)) {
            html += `<details class="border rounded"><summary class="px-3 py-1.5 text-sm cursor-pointer hover:bg-gray-50 flex justify-between"><span>"${perm}"</span><span class="text-xs text-gray-400">${data.count}</span></summary><div class="px-3 pb-2 text-xs space-y-1">${data.results.map(r=>`<div><a href="${r.url}" target="_blank" class="text-brand hover:underline">${r.title}</a></div>`).join('')}</div></details>`;
        }
        html += '</div>';
    }
    if (d.generated_usernames) html += `<div class="flex flex-wrap gap-1">${d.generated_usernames.usernames.map(u=>`<span class="px-2 py-0.5 bg-gray-100 rounded text-xs font-mono">${u}</span>`).join('')}</div>`;
    html += '</div>';

    // --- TAB 3: SearXNG Live-Suche ---
    html += '<div class="ds-panel hidden" data-panel="3">';
    // Pre-loaded SearXNG results from Deep Scan
    if (d.searxng) {
        const sxLabels = {searx_person:'Person',searx_business:'Firma',searx_reviews:'Bewertungen',searx_email:'Email',searx_phone:'Telefon'};
        for (const [sxKey, sx] of Object.entries(d.searxng)) {
            if (!sx.results?.length) continue;
            html += `<div class="mb-3"><h4 class="text-xs font-semibold text-orange-700 mb-1">${sxLabels[sxKey]||sxKey} (${sx.count} Treffer)</h4><div class="space-y-1.5">`;
            sx.results.forEach(r => {
                html += `<div class="p-2 bg-orange-50 rounded hover:bg-orange-100"><a href="${r.url}" target="_blank" class="text-sm font-medium text-brand hover:underline block">${r.title}</a>`;
                if (r.snippet) html += `<div class="text-xs text-gray-500 mt-0.5">${r.snippet}</div>`;
                html += `<div class="text-[10px] text-gray-400 truncate">${r.url}</div></div>`;
            });
            html += '</div></div>';
        }
    }
    // Live search form
    html += `<div class="mt-4 pt-4 border-t">
        <h4 class="text-xs font-semibold text-gray-500 mb-2">Live-Suche (SearXNG)</h4>
        <div class="flex gap-2 mb-3">
            <input type="text" id="sxQuery" placeholder="Beliebigen Suchbegriff eingeben..." class="flex-1 px-3 py-2 border rounded-lg text-sm" value="${d._meta?.target||''}"/>
            <select id="sxCategory" class="px-3 py-2 border rounded-lg text-sm">
                <option value="general">Allgemein</option>
                <option value="news">Nachrichten</option>
                <option value="images">Bilder</option>
                <option value="social media">Social Media</option>
                <option value="science">Wissenschaft</option>
                <option value="files">Dateien</option>
            </select>
            <button onclick="searxngLive()" class="px-4 py-2 bg-orange-500 text-white rounded-lg text-sm font-semibold hover:bg-orange-600" id="sxBtn">Suchen</button>
        </div>
        <div id="sxResults" class="space-y-2"></div>
    </div>`;
    html += '</div>';

    // --- TAB 4: Details (Verifikation, Netzwerk, Learning) ---
    html += '<div class="ds-panel hidden" data-panel="4">';
    if (vf.modules) {
        html += '<div class="mb-3"><h4 class="text-xs font-semibold text-gray-500 mb-2">Verifikation</h4><div class="space-y-1">';
        for (const [mod, info] of Object.entries(vf.modules)) {
            if (!info.confidence) continue;
            const mc = {EXACT:'green',FUZZY:'yellow',HEURISTIC:'orange'}[info.match]||'gray';
            html += `<div class="flex items-center gap-2 text-xs"><span class="w-28 truncate">${mod}</span><div class="flex-1 bg-gray-100 rounded-full h-1.5"><div class="h-1.5 rounded-full bg-${mc}-500" style="width:${info.confidence}%"></div></div><span class="w-8 text-right">${info.confidence}%</span></div>`;
        }
        html += '</div></div>';
    }
    if (d.bgp_asn) html += `<div class="mb-3 p-2 bg-gray-50 rounded text-xs"><b>Netzwerk:</b> AS${d.bgp_asn.asn} ${d.bgp_asn.asn_name} · ${d.bgp_asn.ip} · ${d.bgp_asn.country}</div>`;
    if (d.dns_services?.detected_services?.length) html += `<div class="mb-3 p-2 bg-gray-50 rounded text-xs"><b>Dienste:</b> ${d.dns_services.detected_services.join(', ')}</div>`;
    if (d.learning?.past_scans > 0) {
        html += `<div class="p-2 bg-gray-50 rounded text-xs"><b>KI-Algorithmus:</b> ${d.learning.past_scans} Scans analysiert · Typ: ${d.learning.detected_person_type||'?'}<div class="text-gray-400 mt-1">${d.learning.type_advice||''}</div></div>`;
    }
    html += '</div>';

    html += '</div></div>';
    container.innerHTML = html;
}
function dsTab(n) {
    document.querySelectorAll('#dsTabs button').forEach((b,i) => {
        b.classList.toggle('text-brand', i===n);
        b.classList.toggle('border-b-2', i===n);
        b.classList.toggle('border-brand', i===n);
        b.classList.toggle('text-gray-500', i!==n);
    });
    document.querySelectorAll('.ds-panel').forEach((p,i) => p.classList.toggle('hidden', i!==n));
}
function searxngLive() {
    var q = document.getElementById('sxQuery').value;
    var cat = document.getElementById('sxCategory').value;
    var btn = document.getElementById('sxBtn');
    var res = document.getElementById('sxResults');
    if (!q) return;
    btn.textContent = 'Suche...'; btn.disabled = true;
    res.innerHTML = '<div class="text-center text-gray-400 py-4"><div class="inline-block w-5 h-5 border-2 border-orange-500 border-t-transparent rounded-full animate-spin"></div></div>';
    fetch('/api/osint-deep.php', {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({searxng_live: true, query: q, categories: cat})
    }).then(function(r){return r.json()}).then(function(d){
        btn.textContent = 'Suchen'; btn.disabled = false;
        if (!d.success || !d.data || !d.data.results) { res.innerHTML = '<div class="text-red-500 text-sm">Fehler: '+(d.error||'Keine Ergebnisse')+'</div>'; return; }
        var items = d.data.results;
        if (!items.length) { res.innerHTML = '<div class="text-gray-400 text-sm">Keine Ergebnisse</div>'; return; }
        res.innerHTML = '<div class="text-xs text-gray-400 mb-2">'+items.length+' Ergebnisse via SearXNG</div>' +
            items.map(function(r){
                return '<div class="p-2.5 bg-white border rounded-lg hover:shadow-sm">' +
                    '<a href="'+r.url+'" target="_blank" class="text-sm font-medium text-brand hover:underline">'+r.title+'</a>' +
                    (r.snippet ? '<div class="text-xs text-gray-500 mt-0.5">'+r.snippet+'</div>' : '') +
                    '<div class="text-[10px] text-gray-400 truncate mt-0.5">'+r.url+'</div></div>';
            }).join('');
    }).catch(function(e){
        btn.textContent = 'Suchen'; btn.disabled = false;
        res.innerHTML = '<div class="text-red-500 text-sm">Netzwerkfehler</div>';
    });
}
function renderDeepCards(data, container) {
    container.innerHTML = '';
    var d = data;
    var cards = [];
    if(d.holehe && d.holehe.registered_on && d.holehe.registered_on.length) {
        cards.push('<div class="bg-violet-50 rounded-xl border border-violet-200 p-4"><h4 class="font-bold text-sm mb-2 text-violet-700">Email registriert auf '+d.holehe.count+' Sites</h4><div class="flex flex-wrap gap-1">'+d.holehe.registered_on.map(function(s){return '<span class="px-2 py-0.5 bg-violet-100 text-violet-800 rounded text-xs font-medium">'+s+'</span>';}).join('')+'</div></div>');
    }
    if(d.maigret && d.maigret.profiles && d.maigret.profiles.length) {
        cards.push('<div class="bg-emerald-50 rounded-xl border border-emerald-200 p-4"><h4 class="font-bold text-sm mb-2 text-emerald-700">Social Profile: '+d.maigret.found+' gefunden</h4><div class="space-y-1">'+d.maigret.profiles.slice(0,10).map(function(p){return '<a href="'+p.url+'" target="_blank" class="flex items-center justify-between text-sm py-0.5 hover:bg-emerald-100 px-1 rounded"><span class="font-medium">'+p.site+'</span><span class="text-xs text-emerald-600">'+p.url.split('/').pop()+'</span></a>';}).join('')+(d.maigret.found>10?'<div class="text-xs text-gray-400 mt-1">+'+(d.maigret.found-10)+' weitere</div>':'')+'</div></div>');
    }
    if(d.phoneinfoga && d.phoneinfoga.country) {
        var pi = d.phoneinfoga;
        cards.push('<div class="bg-teal-50 rounded-xl border border-teal-200 p-4"><h4 class="font-bold text-sm mb-2 text-teal-700">Telefon-Analyse (VPS)</h4><div class="space-y-1 text-sm"><div class="flex justify-between"><span class="text-gray-500">Land</span><span class="font-medium">'+pi.country+'</span></div><div class="flex justify-between"><span class="text-gray-500">Carrier</span><span>'+(pi.carrier||'?')+'</span></div><div class="flex justify-between"><span class="text-gray-500">Typ</span><span>'+(pi.type||'?')+'</span></div>'+(pi.international?'<div class="text-xs text-gray-400">'+pi.international+'</div>':'')+'</div></div>');
    }
    if(d.socialscan && d.socialscan.platforms && d.socialscan.platforms.length) {
        cards.push('<div class="bg-cyan-50 rounded-xl border border-cyan-200 p-4"><h4 class="font-bold text-sm mb-2 text-cyan-700">SocialScan: '+d.socialscan.taken_on+' Plattformen</h4><div class="flex flex-wrap gap-1">'+d.socialscan.platforms.slice(0,20).map(function(p){return '<span class="px-2 py-0.5 '+(p.taken?'bg-green-100 text-green-800':'bg-gray-100 text-gray-500')+' rounded text-xs">'+p.platform+(p.taken?' ✓':'')+'</span>';}).join('')+'</div></div>');
    }
    if(d.vulture_web) {
        for (var vkey in d.vulture_web) {
            var vdata = d.vulture_web[vkey];
            if (vkey.indexOf('intelx_')===0 && vdata.results && vdata.results.length) {
                cards.push('<div class="bg-red-900 text-white rounded-xl p-4"><h4 class="font-bold text-sm mb-2 text-red-300">Intelligence X: '+vdata.count+' Treffer</h4><div class="space-y-0.5">'+vdata.results.slice(0,5).map(function(r){return '<div class="text-xs"><span class="text-red-400">'+r.type+':</span> '+r.value+'</div>';}).join('')+'</div></div>');
            }
            if (vdata.answer) {
                cards.push('<div class="bg-indigo-50 rounded-xl border border-indigo-200 p-4"><h4 class="font-bold text-sm mb-2 text-indigo-700">AI Recherche</h4><div class="text-sm text-gray-700 whitespace-pre-line">'+vdata.answer.substring(0,300)+(vdata.answer.length>300?'...':'')+'</div>'+(vdata.citations && vdata.citations.length?'<div class="mt-2 text-xs text-gray-400">'+vdata.citations.slice(0,3).map(function(c,i){return '<a href="'+c+'" target="_blank" class="text-brand hover:underline">['+(i+1)+']</a>';}).join(' ')+'</div>':'')+'</div>');
            }
        }
    }
    if(d.whois_deep && d.whois_deep.registrant) {
        var w = d.whois_deep;
        cards.push('<div class="bg-gray-50 rounded-xl border p-4"><h4 class="font-bold text-sm mb-2 text-gray-700">WHOIS</h4><div class="space-y-1 text-sm">'+(w.registrant?'<div class="flex justify-between"><span class="text-gray-500">Registrant</span><b>'+w.registrant+'</b></div>':'')+(w.org?'<div class="flex justify-between"><span class="text-gray-500">Org</span><span>'+w.org+'</span></div>':'')+(w.registrar?'<div class="flex justify-between"><span class="text-gray-500">Registrar</span><span>'+w.registrar+'</span></div>':'')+(w.created?'<div class="flex justify-between"><span class="text-gray-500">Erstellt</span><span>'+w.created+'</span></div>':'')+'</div></div>');
    }
    if(d.correlation && d.correlation.identity_graph && d.correlation.identity_graph.length) {
        cards.push('<div class="rounded-xl border p-4" style="background:rgba(46,125,107,0.05);border-color:rgba(46,125,107,0.2)"><h4 class="font-bold text-sm mb-2" style="color:<?= BRAND ?>">Verifizierte Accounts ('+d.correlation.identity_graph.length+')</h4><div class="flex flex-wrap gap-1.5">'+d.correlation.identity_graph.map(function(ig){return '<a href="'+ig.url+'" target="_blank" class="px-2.5 py-1 rounded-lg text-xs font-medium hover:opacity-80" style="background:rgba(46,125,107,0.1);color:<?= BRAND ?>">'+ig.platform+'</a>';}).join('')+'</div></div>');
    }
    if(d.impressum_validation && d.impressum_validation.extracted && d.impressum_validation.extracted.owner) {
        var iv = d.impressum_validation, ex = iv.extracted;
        var ivc = iv.valid ? 'green' : 'red';
        cards.push('<div class="bg-'+ivc+'-50 rounded-xl border border-'+ivc+'-200 p-4"><h4 class="font-bold text-sm mb-2 text-'+ivc+'-700">Impressum '+(iv.valid?'OK':'PROBLEM')+'</h4><div class="space-y-1 text-sm">'+(ex.owner?'<div>Inhaber: <b>'+ex.owner+'</b></div>':'')+(ex.hrb?'<div>HRB: '+ex.hrb+'</div>':'')+(ex.vat?'<div>USt: '+ex.vat+'</div>':'')+(ex.email?'<div>Email: '+ex.email+'</div>':'')+'</div>'+(iv.issues && iv.issues.length?'<div class="mt-1 text-xs text-red-600">'+iv.issues.join(' · ')+'</div>':'')+'</div>');
    }
    // SearXNG — real search results
    if(d.searxng) {
        var sxLabels = {searx_person:'Person',searx_business:'Firma',searx_reviews:'Bewertungen',searx_email:'Email',searx_phone:'Telefon'};
        for (var sxKey in d.searxng) {
            var sx = d.searxng[sxKey];
            if (!sx.results || !sx.results.length) continue;
            cards.push('<div class="bg-orange-50 rounded-xl border border-orange-200 p-4"><div class="flex items-center gap-2 mb-2"><svg class="w-4 h-4 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg><h4 class="font-bold text-sm text-orange-700">SearXNG: '+(sxLabels[sxKey]||sxKey)+' ('+sx.count+')</h4></div><div class="space-y-1">'+sx.results.slice(0,5).map(function(r){return '<div><a href="'+r.url+'" target="_blank" class="text-sm text-brand font-medium hover:underline">'+r.title+'</a>'+(r.snippet?'<div class="text-xs text-gray-500 truncate">'+r.snippet+'</div>':'')+'</div>';}).join('')+'</div></div>');
        }
    }
    // GHunt — Google Account Intel
    if(d.ghunt && d.ghunt.found) {
        var gh = d.ghunt.data || {};
        var ghHtml = '<div class="bg-blue-50 rounded-xl border border-blue-200 p-4"><div class="flex items-center gap-2 mb-2"><svg class="w-4 h-4 text-blue-600" fill="currentColor" viewBox="0 0 24 24"><path d="M12.48 10.92v3.28h7.84c-.24 1.84-.853 3.187-1.787 4.133-1.147 1.147-2.933 2.4-6.053 2.4-4.827 0-8.6-3.893-8.6-8.72s3.773-8.72 8.6-8.72c2.6 0 4.507 1.027 5.907 2.347l2.307-2.307C18.747 1.44 16.133 0 12.48 0 5.867 0 .307 5.387.307 12s5.56 12 12.173 12c3.573 0 6.267-1.173 8.373-3.36 2.16-2.16 2.84-5.213 2.84-7.667 0-.76-.053-1.467-.173-2.053H12.48z"/></svg><h4 class="font-bold text-sm text-blue-700">GHunt — Google Account</h4></div><div class="space-y-1 text-sm">';
        if(gh.name) ghHtml += '<div class="flex justify-between"><span class="text-gray-500">Name</span><b>'+gh.name+'</b></div>';
        if(gh.last_edit) ghHtml += '<div class="flex justify-between"><span class="text-gray-500">Letzte Änderung</span><span>'+gh.last_edit+'</span></div>';
        if(gh.photo) ghHtml += '<div class="flex justify-between"><span class="text-gray-500">Profilbild</span><a href="'+gh.photo+'" target="_blank" class="text-brand">Ansehen</a></div>';
        if(gh.maps) ghHtml += '<div class="flex justify-between"><span class="text-gray-500">Maps/Reviews</span><span>'+gh.maps+'</span></div>';
        if(gh.youtube) ghHtml += '<div class="flex justify-between"><span class="text-gray-500">YouTube</span><span>'+gh.youtube+'</span></div>';
        ghHtml += '</div></div>';
        cards.push(ghHtml);
    }
    if (cards.length === 0) {
        container.innerHTML = '<div class="col-span-2 text-center text-gray-400 py-4">Scan läuft — Ergebnisse erscheinen hier</div>';
    } else {
        container.innerHTML = cards.join('');
    }
}
</script>

<?php if ($scan): ?>
<!-- Person Overview -->
<div class="bg-white rounded-xl border mb-4 overflow-hidden">
  <div class="bg-gradient-to-r from-brand to-brand-dark text-white p-5 flex items-center gap-4">
    <?php if ($emailInfo): ?>
    <img src="<?= $emailInfo['gravatar'] ?>" class="w-14 h-14 rounded-full border-2 border-white/50" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%2256%22 height=%2256%22><rect width=%2256%22 height=%2256%22 rx=%2228%22 fill=%22%23ffffff30%22/><text x=%2228%22 y=%2236%22 text-anchor=%22middle%22 fill=%22white%22 font-size=%2222%22 font-family=%22sans-serif%22><?= strtoupper(mb_substr($scanName?:$scanEmail,0,1)) ?></text></svg>'"/>
    <?php endif; ?>
    <div>
      <h2 class="text-xl font-bold"><?= e($scanName ?: $scanEmail ?: $scanPhone) ?></h2>
      <div class="text-white/70 text-sm"><?= e($scanEmail) ?> <?= $scanPhone ? '· '.e($scanPhone) : '' ?></div>
    </div>
    <?php if ($dbStats): ?>
    <div class="ml-auto flex gap-4 text-center">
      <div><div class="text-2xl font-bold"><?= $dbStats['total_jobs'] ?></div><div class="text-xs text-white/60">Jobs</div></div>
      <div><div class="text-2xl font-bold"><?= $dbStats['done'] ?></div><div class="text-xs text-white/60">Erledigt</div></div>
      <div><div class="text-2xl font-bold"><?= number_format($dbStats['inv_total'],0,',','.') ?>€</div><div class="text-xs text-white/60">Umsatz</div></div>
      <?php if ($dbStats['inv_open']>0): ?><div><div class="text-2xl font-bold text-red-300"><?= number_format($dbStats['inv_open'],0,',','.') ?>€</div><div class="text-xs text-white/60">Offen</div></div><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
  <!-- Email Intelligence -->
  <?php if ($emailInfo): ?>
  <div class="bg-white rounded-xl border overflow-hidden">
    <div class="px-4 py-2.5 border-b"><h4 class="font-semibold text-sm text-gray-900">Email-Analyse</h4></div>
    <div class="p-4 space-y-2 text-sm">
      <div class="flex justify-between"><span class="text-gray-500">Domain</span><span class="font-medium"><?= e($emailInfo['domain']) ?></span></div>
      <div class="flex justify-between"><span class="text-gray-500">MX Records</span><span><?= $emailInfo['mx'] ?> <?= $emailInfo['mx_host']?'('.e($emailInfo['mx_host']).')':'' ?></span></div>
      <div class="flex justify-between"><span class="text-gray-500">Typ</span>
        <span class="px-2 py-0.5 rounded text-xs font-medium <?= $emailInfo['disposable']?'bg-red-100 text-red-700':($emailInfo['free']?'bg-yellow-100 text-yellow-700':'bg-green-100 text-green-700') ?>">
          <?= $emailInfo['disposable']?'WEGWERF-EMAIL':($emailInfo['free']?'Free Provider':'Business') ?>
        </span></div>
      <div class="flex justify-between"><span class="text-gray-500">Pattern</span>
        <span class="px-2 py-0.5 rounded text-xs font-medium bg-<?= $emailInfo['pattern'][2] ?>-100 text-<?= $emailInfo['pattern'][2] ?>-700"><?= $emailInfo['pattern'][0] ?> (<?= $emailInfo['pattern'][1] ?>)</span></div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Phone Intelligence -->
  <?php if ($scanPhone):
    $ph = preg_replace('/[^+0-9]/','',$scanPhone);
    // Normalize: 4915... → +4915..., 015... → +4915...
    if (!str_starts_with($ph, '+') && str_starts_with($ph, '00')) $ph = '+' . substr($ph, 2);
    elseif (!str_starts_with($ph, '+') && str_starts_with($ph, '0')) $ph = '+49' . substr($ph, 1);
    elseif (!str_starts_with($ph, '+') && strlen($ph) > 10) $ph = '+' . $ph;
    // Country detection
    $phCountries = ['+49'=>'Deutschland','+40'=>'Rumänien','+373'=>'Moldawien','+41'=>'Schweiz','+43'=>'Österreich','+48'=>'Polen','+420'=>'Tschechien','+36'=>'Ungarn','+31'=>'Niederlande','+33'=>'Frankreich','+39'=>'Italien','+34'=>'Spanien','+44'=>'UK','+1'=>'USA/Kanada','+90'=>'Türkei','+380'=>'Ukraine','+7'=>'Russland'];
    $phCountry = 'International'; $phCC = '';
    foreach ($phCountries as $pfx => $cname) { if (str_starts_with($ph, $pfx)) { $phCountry = $cname; $phCC = $pfx; break; } }
    $isDE = ($phCC === '+49'); $isRO = ($phCC === '+40');
    $phLocal = $isDE ? '0' . substr($ph, 3) : $ph;
    $phInt = ltrim($ph, '+');
  ?>
  <div class="bg-white rounded-xl border overflow-hidden">
    <div class="px-4 py-2.5 border-b"><h4 class="font-semibold text-sm text-gray-900">Telefon-Analyse</h4></div>
    <div class="p-4 space-y-2 text-sm">
      <div class="flex justify-between"><span class="text-gray-500">Nummer</span><span class="font-mono font-medium"><?= e($ph) ?></span></div>
      <div class="flex justify-between"><span class="text-gray-500">Land</span><span class="font-medium"><?= $phCountry ?> (<?= $phCC ?>)</span></div>
      <div class="grid grid-cols-4 gap-2 mt-3">
        <a href="https://wa.me/<?= $phInt ?>" target="_blank" class="px-3 py-2 border border-gray-200 rounded-lg text-xs font-medium text-center text-gray-700 hover:bg-gray-50">WhatsApp</a>
        <a href="https://t.me/+<?= $phInt ?>" target="_blank" class="px-3 py-2 border border-gray-200 rounded-lg text-xs font-medium text-center text-gray-700 hover:bg-gray-50">Telegram</a>
        <a href="sms:<?= $ph ?>" class="px-3 py-2 border border-gray-200 rounded-lg text-xs font-medium text-center text-gray-700 hover:bg-gray-50">SMS</a>
        <a href="tel:<?= $ph ?>" class="px-3 py-2 bg-brand text-white rounded-lg text-xs font-medium text-center">Anrufen</a>
      </div>
      <div class="grid grid-cols-3 sm:grid-cols-6 gap-2 mt-2">
        <?php if ($isDE): ?>
        <a href="https://www.dasoertliche.de/Themen/R%C3%BCckw%C3%A4rtssuche/<?= urlencode($phLocal) ?>" target="_blank" class="px-2 py-1.5 border border-gray-200 rounded-lg text-xs text-center text-gray-600 hover:bg-gray-50">Das Örtliche</a>
        <a href="https://www.11880.com/suche/<?= urlencode($phLocal) ?>/bundesweit" target="_blank" class="px-2 py-1.5 border border-gray-200 rounded-lg text-xs text-center text-gray-600 hover:bg-gray-50">11880</a>
        <?php elseif ($isRO): ?>
        <a href="https://www.paginiaurii.ro/cautare/<?= urlencode($phInt) ?>" target="_blank" class="px-2 py-1.5 border border-gray-200 rounded-lg text-xs text-center text-gray-600 hover:bg-gray-50">Pagini Aurii</a>
        <a href="https://www.olx.ro/oferte/q-<?= urlencode($ph) ?>/" target="_blank" class="px-2 py-1.5 border border-gray-200 rounded-lg text-xs text-center text-gray-600 hover:bg-gray-50">OLX.ro</a>
        <?php endif; ?>
        <a href="https://www.tellows.de/num/<?= urlencode($ph) ?>" target="_blank" class="px-2 py-1.5 border border-gray-200 rounded-lg text-xs text-center text-gray-600 hover:bg-gray-50">Tellows</a>
        <a href="https://www.google.com/search?q=%22<?= urlencode($ph) ?>%22" target="_blank" class="px-2 py-1.5 border border-gray-200 rounded-lg text-xs text-center text-gray-600 hover:bg-gray-50">Google</a>
        <a href="https://sync.me/search/?number=<?= urlencode($ph) ?>" target="_blank" class="px-2 py-1.5 border border-gray-200 rounded-lg text-xs text-center text-gray-600 hover:bg-gray-50">Sync.me</a>
        <a href="https://www.truecaller.com/search/<?= $isDE?'de':($isRO?'ro':'de') ?>/<?= urlencode($phInt) ?>" target="_blank" class="px-2 py-1.5 border border-gray-200 rounded-lg text-xs text-center text-gray-600 hover:bg-gray-50">Truecaller</a>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Address Intelligence -->
  <?php if ($scanAddress): $addr = urlencode($scanAddress); ?>
  <div class="bg-white rounded-xl border overflow-hidden lg:col-span-2">
    <div class="px-4 py-2.5 border-b flex items-center justify-between">
      <h4 class="font-semibold text-sm text-gray-900">Adresse: <?= e($scanAddress) ?></h4>
      <?php if ($geoLat): ?><span class="text-xs text-gray-400 font-mono"><?= $geoLat ?>, <?= $geoLng ?></span><?php endif; ?>
    </div>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-0">
      <!-- Map (left) -->
      <div class="border-r border-b lg:border-b-0" style="min-height:320px">
        <?php if ($geoLat): ?>
        <iframe src="https://maps.google.com/maps?q=<?= $geoLat ?>,<?= $geoLng ?>&t=k&z=18&output=embed" width="100%" height="320" frameborder="0" style="border:0" allowfullscreen></iframe>
        <?php else: ?>
        <iframe src="https://maps.google.com/maps?q=<?= $addr ?>&t=k&z=18&output=embed" width="100%" height="320" frameborder="0" style="border:0" allowfullscreen></iframe>
        <?php endif; ?>
      </div>
      <!-- Quick Links (right) -->
      <div class="p-4 space-y-3">
        <div class="grid grid-cols-3 gap-1.5">
          <a href="https://www.google.com/maps/search/<?= $addr ?>" target="_blank" class="px-2 py-1.5 border border-gray-200 rounded-lg text-xs text-center text-gray-600 hover:bg-gray-50">Maps</a>
          <a href="https://www.google.com/maps/@?api=1&map_action=pano&viewpoint=<?= $addr ?>" target="_blank" class="px-2 py-1.5 border border-gray-200 rounded-lg text-xs text-center text-gray-600 hover:bg-gray-50">Street View</a>
          <?php if ($geoLat): ?><a href="https://www.google.com/maps/@<?= $geoLat ?>,<?= $geoLng ?>,18z/data=!3m1!1e3" target="_blank" class="px-2 py-1.5 border border-gray-200 rounded-lg text-xs text-center text-gray-600 hover:bg-gray-50">Satellit</a>
          <?php else: ?><a href="https://www.google.com/maps/place/<?= $addr ?>/@0,0,18z/data=!3m1!1e3" target="_blank" class="px-2 py-1.5 border border-gray-200 rounded-lg text-xs text-center text-gray-600 hover:bg-gray-50">Satellit</a><?php endif; ?>
        </div>
        <div>
          <h5 class="text-xs font-semibold text-gray-500 mb-1">Immobilien</h5>
          <div class="grid grid-cols-2 gap-1.5">
            <a href="https://www.immobilienscout24.de/Suche/?searchQuery=<?= $addr ?>" target="_blank" class="px-2 py-1.5 border border-gray-200 rounded-lg text-xs text-center text-gray-600 hover:bg-gray-50">ImmoScout24</a>
            <a href="https://www.immowelt.de/suche/?searchQuery=<?= $addr ?>" target="_blank" class="px-2 py-1.5 border border-gray-200 rounded-lg text-xs text-center text-gray-600 hover:bg-gray-50">Immowelt</a>
            <a href="https://www.google.com/search?q=%22<?= $addr ?>%22+Grundbuch+OR+Eigent%C3%BCmer+OR+Grundst%C3%BCck" target="_blank" class="px-2 py-1.5 border border-gray-200 rounded-lg text-xs text-center text-gray-600 hover:bg-gray-50">Grundbuch</a>
            <a href="https://www.google.com/search?q=%22<?= $addr ?>%22+Mietspiegel+OR+qm+OR+Quadratmeter" target="_blank" class="px-2 py-1.5 border border-gray-200 rounded-lg text-xs text-center text-gray-600 hover:bg-gray-50">Mietspiegel</a>
          </div>
        </div>
        <div>
          <h5 class="text-xs font-semibold text-gray-500 mb-1">Tourismus</h5>
          <div class="grid grid-cols-2 gap-1.5">
            <button onclick="searchAirbnb()" class="px-2 py-1.5 border border-gray-200 rounded-lg text-xs text-center text-gray-600 hover:bg-gray-50 cursor-pointer" id="airbnbBtn">Airbnb suchen</button>
            <a href="https://www.booking.com/searchresults.html?ss=<?= $addr ?>" target="_blank" class="px-2 py-1.5 border border-gray-200 rounded-lg text-xs text-center text-gray-600 hover:bg-gray-50">Booking.com</a>
          </div>
        </div>
        <div>
          <h5 class="text-xs font-semibold text-gray-500 mb-1">Firmen & Personen</h5>
          <div class="grid grid-cols-2 gap-1.5">
            <a href="https://www.northdata.de/<?= $addr ?>" target="_blank" class="px-2 py-1.5 border border-gray-200 rounded-lg text-xs text-center text-gray-600 hover:bg-gray-50">Northdata</a>
            <a href="https://www.dasoertliche.de/Suche?kw=<?= $addr ?>" target="_blank" class="px-2 py-1.5 border border-gray-200 rounded-lg text-xs text-center text-gray-600 hover:bg-gray-50">Das Örtliche</a>
            <a href="https://www.google.com/search?q=%22<?= $addr ?>%22+Firma+OR+GmbH+OR+UG+OR+Gewerbe" target="_blank" class="px-2 py-1.5 border border-gray-200 rounded-lg text-xs text-center text-gray-600 hover:bg-gray-50">Firmen</a>
            <a href="https://www.google.com/search?q=%22<?= $addr ?>%22+Polizei+OR+Vorfall" target="_blank" class="px-2 py-1.5 border border-gray-200 rounded-lg text-xs text-center text-gray-600 hover:bg-gray-50">Polizei</a>
          </div>
        </div>
        <div>
          <h5 class="text-xs font-semibold text-gray-500 mb-1">Geschichte</h5>
          <div class="grid grid-cols-2 gap-1.5">
            <a href="https://www.google.com/search?q=%22<?= $addr ?>%22+Bewertung+OR+Review+OR+Erfahrung" target="_blank" class="px-2 py-1.5 border border-gray-200 rounded-lg text-xs text-center text-gray-600 hover:bg-gray-50">Bewertungen</a>
            <a href="https://www.google.com/search?q=%22<?= $addr ?>%22" target="_blank" class="px-2 py-1.5 border border-gray-200 rounded-lg text-xs text-center text-gray-600 hover:bg-gray-50">Alles (Google)</a>
          </div>
        </div>
      </div>
    </div>
    <!-- Airbnb Results (inline) -->
    <div id="airbnbResults" class="hidden border-t">
      <div class="px-4 py-2.5 bg-gray-50 flex items-center justify-between">
        <h5 class="font-semibold text-sm text-gray-900">Airbnb Ergebnisse</h5>
        <span class="text-xs text-gray-400" id="airbnbCount"></span>
      </div>
      <div id="airbnbCards" class="p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3"></div>
    </div>
  </div>
  <?php endif; ?>

  <!-- DB data moved to Deep Scan dossier (not shown separately) -->

  <!-- Domain/Website -->
  <?php if ($domainInfo): ?>
  <div class="bg-white rounded-xl border overflow-hidden">
    <div class="px-4 py-2.5 border-b"><h4 class="font-semibold text-sm text-gray-900">Website: <?= e($emailInfo['domain']) ?></h4></div>
    <div class="p-4 space-y-2 text-sm">
      <div class="flex justify-between"><span class="text-gray-500">IP</span><span class="font-mono"><?= e($domainInfo['ip']??'—') ?></span></div>
      <div class="flex justify-between"><span class="text-gray-500">HTTP</span>
        <span class="font-bold <?= ($domainInfo['http']??0)>=200&&($domainInfo['http']??0)<400?'text-green-600':'text-red-600' ?>"><?= $domainInfo['http']??'—' ?></span></div>
      <?php if (!empty($domainInfo['ssl'])): ?>
      <div class="flex justify-between"><span class="text-gray-500">SSL</span><span><?= e($domainInfo['ssl']['issuer']) ?> (<?= $domainInfo['ssl']['days'] ?>d)</span></div>
      <?php endif; ?>
      <?php if (!empty($domainInfo['title'])): ?>
      <div class="flex justify-between"><span class="text-gray-500">Titel</span><span class="truncate ml-4"><?= e($domainInfo['title']) ?></span></div>
      <?php endif; ?>
      <?php if (!empty($domainInfo['tech'])): ?>
      <div class="flex gap-1 mt-2 flex-wrap"><?php foreach($domainInfo['tech'] as $t): ?><span class="px-2 py-0.5 bg-purple-100 text-purple-700 rounded text-xs"><?= $t ?></span><?php endforeach; ?></div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- DB Records -->
  <?php if (!empty($dbCustomers)): ?>
  <div class="bg-white rounded-xl border overflow-hidden">
    <div class="px-4 py-2.5 border-b"><h4 class="font-semibold text-sm text-gray-900">Datenbank (<?= count($dbCustomers) ?> Treffer)</h4></div>
    <div class="divide-y">
      <?php foreach ($dbCustomers as $dc): ?>
      <div class="p-3 flex items-center justify-between">
        <div>
          <a href="/admin/view-customer.php?id=<?= $dc['customer_id'] ?>" class="font-medium text-brand hover:underline"><?= e($dc['name']) ?></a>
          <div class="text-xs text-gray-400"><?= e($dc['email']) ?> · <?= e($dc['phone']) ?> · <?= $dc['customer_type'] ?></div>
        </div>
        <div class="text-right text-xs">
          <div><span class="text-green-600 font-medium"><?= $dc['jobs'] ?> Jobs</span></div>
          <?php if ($dc['last_job']): ?><div class="text-gray-400">Letzter: <?= date('d.m.Y',strtotime($dc['last_job'])) ?></div><?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Deep Scan (auto-starts on scan) — PROMINENT -->
<div class="bg-white rounded-xl border mb-4 overflow-hidden">
  <div class="px-5 py-3 border-b flex items-center justify-between bg-gradient-to-r from-brand/5 to-transparent">
    <div class="flex items-center gap-2">
      <svg class="w-5 h-5 text-brand" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
      <h3 class="font-semibold text-gray-900">Deep Scan Ergebnisse</h3>
    </div>
    <div class="flex items-center gap-2">
      <button onclick="saveScan()" class="px-3 py-1.5 border border-gray-200 text-gray-600 rounded-lg text-xs font-medium hover:bg-gray-50" id="saveScanBtn">Speichern</button>
      <button onclick="runDeepScan()" id="deepScanBtn" class="px-4 py-1.5 bg-brand text-white rounded-lg text-xs font-medium">Erneut scannen</button>
    </div>
  </div>
  <div id="deepResults" class="p-4 hidden">
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4" id="deepCards"></div>
  </div>
  <div id="deepLoading" class="p-8 text-center text-gray-400 hidden">
    <div class="inline-block w-6 h-6 border-2 border-brand border-t-transparent rounded-full animate-spin mb-2"></div>
    <div>Scanne externe Quellen...</div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════ -->
<!-- ONTOLOGY GRAPH — integriert, Standard-Theme      -->
<!-- ═══════════════════════════════════════════════ -->
<div class="bg-white rounded-xl border mb-4 overflow-hidden">
  <div class="px-5 py-3 border-b flex items-center justify-between bg-gradient-to-r from-brand/5 to-transparent">
    <div class="flex items-center gap-2">
      <svg class="w-5 h-5 text-brand" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
      </svg>
      <h3 class="font-semibold text-gray-900">Ontology Graph <span class="text-[9px] text-gray-400 font-mono">v3.3</span></h3>
      <span class="text-xs text-gray-400 ml-1" id="ontoStatsMini">lade…</span>
    </div>
  </div>
  <div class="p-4">
    <!-- Search row -->
    <div class="flex gap-2 mb-3">
      <input type="text" id="ontoQuery" placeholder="Name, Email, Telefon, Domain, Firma…"
             class="flex-1 px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-brand"
             autocomplete="off">
      <label class="flex items-center gap-1 text-xs text-gray-500 px-2">
        <input type="checkbox" id="ontoLive" class="accent-brand">LIVE
      </label>
      <button onclick="ontoSearch()" class="px-4 py-2 bg-brand text-white rounded-lg text-xs font-medium hover:bg-brand-dark">Suchen</button>
    </div>
    <!-- Type filter chips -->
    <div class="flex flex-wrap gap-1.5 mb-3" id="ontoChips">
      <span class="px-2.5 py-1 border border-gray-300 bg-brand text-white rounded-full text-[11px] font-medium cursor-pointer" data-type="all">ALLE</span>
      <span class="px-2.5 py-1 border border-gray-200 text-gray-600 rounded-full text-[11px] font-medium cursor-pointer hover:bg-gray-50" data-type="person">Person</span>
      <span class="px-2.5 py-1 border border-gray-200 text-gray-600 rounded-full text-[11px] font-medium cursor-pointer hover:bg-gray-50" data-type="company">Firma</span>
      <span class="px-2.5 py-1 border border-gray-200 text-gray-600 rounded-full text-[11px] font-medium cursor-pointer hover:bg-gray-50" data-type="email">Email</span>
      <span class="px-2.5 py-1 border border-gray-200 text-gray-600 rounded-full text-[11px] font-medium cursor-pointer hover:bg-gray-50" data-type="phone">Tel</span>
      <span class="px-2.5 py-1 border border-gray-200 text-gray-600 rounded-full text-[11px] font-medium cursor-pointer hover:bg-gray-50" data-type="domain">Domain</span>
      <span class="px-2.5 py-1 border border-gray-200 text-gray-600 rounded-full text-[11px] font-medium cursor-pointer hover:bg-gray-50" data-type="address">Adresse</span>
      <span class="px-2.5 py-1 border border-gray-200 text-gray-600 rounded-full text-[11px] font-medium cursor-pointer hover:bg-gray-50" data-type="handle">Handle</span>
    </div>
    <!-- Activity Heatmap (last 90 days) -->
    <div class="mb-3 bg-gray-50 border border-gray-200 rounded-lg p-3">
      <div class="flex items-center justify-between mb-2">
        <div class="text-[10px] font-semibold text-gray-500 uppercase tracking-wider">Aktivität · letzte 90 Tage</div>
        <button type="button" onclick="document.getElementById('ontoImportPanel').classList.toggle('hidden')"
                class="text-[10px] text-brand hover:underline">+ CSV / Sheet Import</button>
      </div>
      <div id="ontoHeatmap" class="flex items-end gap-0.5 h-12"></div>
      <div id="ontoHeatmapLegend" class="text-[9px] font-mono text-gray-400 mt-1"></div>
    </div>

    <!-- Watchlist + Merge panels row -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-3">
      <!-- Watchlist -->
      <div class="bg-gray-50 border border-gray-200 rounded-lg p-3">
        <div class="flex items-center justify-between mb-2">
          <div class="text-[10px] font-semibold text-gray-500 uppercase tracking-wider">👁 Watchlist</div>
          <button onclick="ontoLoadWatchlist()" class="text-[10px] text-brand hover:underline">refresh</button>
        </div>
        <div class="flex gap-1 mb-2">
          <input type="text" id="ontoWatchLabel" placeholder="Label" class="flex-1 px-2 py-1 border border-gray-200 rounded text-[11px]">
          <input type="text" id="ontoWatchQuery" placeholder="Query" class="flex-1 px-2 py-1 border border-gray-200 rounded text-[11px]">
          <button onclick="ontoAddWatch()" class="px-2 py-1 bg-brand text-white rounded text-[10px]">+</button>
        </div>
        <div id="ontoWatchlist" class="space-y-1 max-h-32 overflow-y-auto"></div>
      </div>
      <!-- Merge candidates -->
      <div class="bg-gray-50 border border-gray-200 rounded-lg p-3">
        <div class="flex items-center justify-between mb-2">
          <div class="text-[10px] font-semibold text-gray-500 uppercase tracking-wider">🔗 Merge Candidates</div>
          <button onclick="ontoLoadMerges()" class="text-[10px] text-brand hover:underline">scan</button>
        </div>
        <div id="ontoMerges" class="space-y-1 max-h-32 overflow-y-auto text-[11px]">
          <div class="text-[10px] text-gray-400">Click scan to find duplicate entities…</div>
        </div>
      </div>
    </div>

    <!-- 🤖 KI Verify Panel — multi-LLM lookup (Groq + Perplexity + Grok + SearXNG) -->
    <div class="bg-gradient-to-br from-indigo-50 to-blue-50 border border-indigo-200 rounded-lg p-3 mb-3">
      <div class="flex items-center justify-between mb-2">
        <div class="flex items-center gap-2">
          <span class="text-sm">🤖</span>
          <h4 class="text-xs font-bold text-indigo-900 uppercase tracking-wider">KI Verify</h4>
          <span class="text-[10px] text-indigo-500">Groq · Perplexity · SearXNG · Grok</span>
        </div>
        <div class="flex items-center gap-2">
          <label class="text-[10px] text-indigo-700 flex items-center gap-1 cursor-pointer">
            <input type="checkbox" id="aiAutoToggle" checked class="accent-indigo-600">
            Auto
          </label>
          <span class="text-[10px] text-indigo-500 font-mono" id="aiVerifyTiming"></span>
        </div>
      </div>
      <div class="flex gap-2 mb-2">
        <input type="text" id="aiVerifyQuery" placeholder="Name, Firma, Adresse… (auto-triggered aus Haupt-Suche)"
               class="flex-1 px-3 py-1.5 border border-indigo-200 rounded text-xs bg-white focus:outline-none focus:border-indigo-500">
        <button onclick="runAiVerify()" id="aiVerifyBtn" class="px-4 py-1.5 bg-indigo-600 text-white rounded text-xs font-medium hover:bg-indigo-700 whitespace-nowrap">🤖 Verify</button>
      </div>
      <div id="aiVerifyResult" class="hidden grid grid-cols-1 md:grid-cols-2 gap-2 mt-3"></div>
    </div>

    <!-- 📎 OCR Document Drop -->
    <div class="bg-purple-50 border border-purple-200 rounded-lg p-3 mb-3">
      <div class="flex items-center justify-between mb-2">
        <div class="text-[10px] font-bold text-purple-800 uppercase tracking-wider">📎 OCR Drop</div>
        <span class="text-[10px] text-purple-500">Visitenkarte / Brief / Screenshot → Entities</span>
      </div>
      <div class="flex gap-2 items-center">
        <input type="file" id="ocrFile" accept="image/*,application/pdf,text/plain"
               class="text-xs flex-1">
        <input type="text" id="ocrLabel" placeholder="Label (optional)"
               class="px-2 py-1 border border-purple-200 rounded text-xs w-40">
        <button onclick="runOcr()" id="ocrBtn"
                class="px-3 py-1 bg-purple-600 text-white rounded text-[11px] font-medium hover:bg-purple-700">📎 Extract</button>
      </div>
      <div id="ocrResult" class="text-[10px] font-mono text-purple-700 mt-2"></div>
    </div>

    <!-- CSV / Sheet Import Panel (collapsed by default) -->
    <div id="ontoImportPanel" class="hidden mb-3 bg-amber-50 border border-amber-200 rounded-lg p-3">
      <div class="text-[10px] font-semibold text-amber-700 uppercase tracking-wider mb-2">CSV / Google Sheet Import</div>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div>
          <label class="text-[10px] text-gray-600 block mb-1">Datei hochladen (CSV)</label>
          <input type="file" id="ontoCsvFile" accept=".csv,text/csv" class="text-xs">
          <div class="text-[10px] text-gray-500 mt-1">Header-Zeile required. Auto-detect für Name/Email/Telefon/Adresse.</div>
        </div>
        <div>
          <label class="text-[10px] text-gray-600 block mb-1">Oder: Public Google Sheet URL</label>
          <input type="text" id="ontoSheetUrl" placeholder="https://docs.google.com/spreadsheets/d/ID/export?format=csv"
                 class="w-full px-2 py-1 border border-gray-200 rounded text-xs">
          <div class="text-[10px] text-gray-500 mt-1">Sheet muss "Anyone with link = Viewer" sein</div>
        </div>
      </div>
      <div class="mt-2 flex items-center gap-2">
        <input type="text" id="ontoImportLabel" placeholder="Label (z.B. 'Master Credentials')"
               class="flex-1 px-2 py-1 border border-gray-200 rounded text-xs">
        <button onclick="ontoImport()" id="ontoImportBtn"
                class="px-3 py-1 bg-brand text-white rounded text-[11px] font-medium">Importieren</button>
      </div>
      <div id="ontoImportResult" class="text-[10px] font-mono text-gray-600 mt-2"></div>
    </div>

    <!-- Results + graph split -->
    <div class="grid grid-cols-1 lg:grid-cols-5 gap-3">
      <div class="lg:col-span-2">
        <div class="text-[10px] font-semibold text-gray-400 uppercase tracking-wider mb-2" id="ontoResultsHeader">Ergebnisse</div>
        <div id="ontoResults" class="space-y-1.5 max-h-96 overflow-y-auto pr-1">
          <div class="text-xs text-gray-400 py-8 text-center">Tippe einen Namen oder Email um zu suchen.</div>
        </div>
      </div>
      <div class="lg:col-span-3">
        <div class="text-[10px] font-semibold text-gray-400 uppercase tracking-wider mb-2 flex items-center justify-between">
          <span id="ontoGraphHeader">Link-Graph — wähle ein Ergebnis</span>
          <div id="ontoGraphActions" class="hidden flex items-center gap-1">
            <button onclick="ontoCascade()" class="px-2 py-0.5 text-[10px] bg-brand text-white rounded">🦅 Cascade</button>
            <button onclick="ontoLineage()" class="px-2 py-0.5 text-[10px] border border-gray-200 text-gray-600 rounded hover:bg-gray-50">🔍 Lineage</button>
            <a id="ontoFullLink" href="#" class="px-2 py-0.5 text-[10px] border border-gray-200 text-gray-600 rounded hover:bg-gray-50">⛶ Full</a>
            <a id="ontoBriefingLink" href="#" target="_blank" class="px-2 py-0.5 text-[10px] border border-gray-200 text-gray-600 rounded hover:bg-gray-50">📄 Briefing</a>
          </div>
        </div>
        <div id="ontoGraph" class="bg-gray-50 border border-gray-200 rounded-lg h-96"></div>
        <div id="ontoDetail" class="hidden mt-2 p-3 bg-gray-50 border border-gray-200 rounded-lg text-xs"></div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/cytoscape@3.28.1/dist/cytoscape.min.js"></script>
<script>
const ONTO = { query:'', typeFilter:'all', currentObjId:null, cy:null, debounce:null };

// Load stats on page load — dedicated endpoint, no query needed
fetch('/api/gotham-search.php?action=stats').then(r=>r.json()).then(j=>{
  if (j && j.success) {
    const d = j.data;
    document.getElementById('ontoStatsMini').textContent =
      `${d.total_objects} Objects · ${d.verified} verified · ${d.total_links} Links · ${d.total_scans} Scans`;
  } else {
    document.getElementById('ontoStatsMini').textContent = 'Stats unavailable';
  }
}).catch(()=>{ document.getElementById('ontoStatsMini').textContent = 'Stats offline'; });

document.querySelectorAll('#ontoChips span').forEach(chip => {
  chip.onclick = () => {
    document.querySelectorAll('#ontoChips span').forEach(c => {
      c.classList.remove('bg-brand','text-white','border-gray-300');
      c.classList.add('border-gray-200','text-gray-600');
    });
    chip.classList.remove('border-gray-200','text-gray-600');
    chip.classList.add('bg-brand','text-white','border-gray-300');
    ONTO.typeFilter = chip.dataset.type;
    if (ONTO.query) ontoSearch();
  };
});

document.getElementById('ontoQuery').addEventListener('input', e => {
  clearTimeout(ONTO.debounce);
  const v = e.target.value.trim();
  if (v.length === 1) return; // wait for 2nd char
  ONTO.debounce = setTimeout(ontoSearch, 350);
});

// Auto-load recent objects on first interaction with the section
setTimeout(() => {
  if (!ONTO.query && document.getElementById('ontoQuery').value === '') {
    ontoSearch();
  }
}, 600);

// ──────────────────────────────────────────────────────────────
// HEATMAP — 90-day event activity
// ──────────────────────────────────────────────────────────────
fetch('/api/gotham-search.php?action=heatmap&days=90').then(r=>r.json()).then(j=>{
  if (!j.success) return;
  const byDate = {};
  (j.data.by_date || []).forEach(r => byDate[r.d] = parseInt(r.n,10));
  const container = document.getElementById('ontoHeatmap');
  if (!container) return;
  const max = Math.max(1, ...Object.values(byDate));
  const today = new Date();
  let cells = '';
  for (let i = 89; i >= 0; i--) {
    const d = new Date(today); d.setDate(today.getDate() - i);
    const key = d.toISOString().substring(0, 10);
    const n = byDate[key] || 0;
    const intensity = n === 0 ? 0 : Math.min(1, n / max);
    const bg = n === 0 ? '#e5e7eb' : `rgba(46,125,107,${0.25 + intensity*0.75})`;
    const tip = `${key}: ${n} Events`;
    cells += `<div style="width:4px;height:${6+intensity*42}px;background:${bg};border-radius:1px" title="${tip}"></div>`;
  }
  container.innerHTML = cells;
  const total = Object.values(byDate).reduce((a,b)=>a+b,0);
  const peakDay = Object.entries(byDate).sort((a,b)=>b[1]-a[1])[0];
  document.getElementById('ontoHeatmapLegend').textContent =
    `${total} Events gesamt · Peak: ${peakDay ? peakDay[0] + ' (' + peakDay[1] + ')' : '—'} · ${Object.keys(byDate).length} aktive Tage`;
}).catch(()=>{});

// ──────────────────────────────────────────────────────────────
// CSV / Sheet Import
// ──────────────────────────────────────────────────────────────
async function ontoImport() {
  const file = document.getElementById('ontoCsvFile').files[0];
  const url  = document.getElementById('ontoSheetUrl').value.trim();
  const label = document.getElementById('ontoImportLabel').value.trim() || 'import';
  const result = document.getElementById('ontoImportResult');
  const btn = document.getElementById('ontoImportBtn');

  if (!file && !url) { result.textContent = '⚠ Datei oder Sheet-URL angeben'; return; }
  btn.disabled = true; btn.textContent = 'Importiere…';
  result.textContent = '';

  try {
    let r;
    if (file) {
      const fd = new FormData();
      fd.append('file', file);
      fd.append('source_label', label);
      fd.append('mapping', '{}');
      r = await fetch('/api/gotham-import.php', {method:'POST', body: fd});
    } else {
      r = await fetch('/api/gotham-import.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({sheet_url: url, mapping: {}, source_label: label}),
      });
    }
    const j = await r.json();
    if (!j.success) throw new Error(j.error || 'import fehlgeschlagen');
    const s = j.stats;
    result.innerHTML = `✓ ${s.rows} rows → ${s.objects} objects · ${s.links} links · ${s.errors} errors · ${s.elapsed}s<br>Cols: ${Object.entries(s.resolved_columns).map(([k,v])=>k+'='+v).join(', ')}`;
    // Refresh stats
    setTimeout(() => {
      fetch('/api/gotham-search.php?action=stats').then(r=>r.json()).then(j=>{
        if (j && j.success) {
          const d = j.data;
          document.getElementById('ontoStatsMini').textContent =
            `${d.total_objects} Objects · ${d.verified} verified · ${d.total_links} Links · ${d.total_scans} Scans`;
        }
      });
      ontoSearch();
    }, 500);
  } catch(e) {
    result.innerHTML = '<span class="text-red-600">✗ ' + e.message + '</span>';
  } finally {
    btn.disabled = false; btn.textContent = 'Importieren';
  }
}
document.getElementById('ontoQuery').addEventListener('keydown', e => {
  if (e.key === 'Enter') { clearTimeout(ONTO.debounce); ontoSearch(); }
});

async function ontoSearch() {
  const q = document.getElementById('ontoQuery').value.trim();
  if (q.length === 1) {
    document.getElementById('ontoResults').innerHTML = '<div class="text-xs text-amber-600 py-4 text-center">Bitte mindestens 2 Zeichen eingeben.</div>';
    return;
  }
  ONTO.query = q;
  document.getElementById('ontoResults').innerHTML = '<div class="text-xs text-gray-400 py-4 text-center">' + (q ? 'suche…' : 'lade zuletzt hinzugefügte…') + '</div>';
  const j = await ontoFetchJson('/api/gotham-search.php', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({
      query: q,
      type_filter: ONTO.typeFilter,
      include_live: document.getElementById('ontoLive').checked,
    }),
  });
  if (!j.success) {
    document.getElementById('ontoResults').innerHTML =
      '<div class="text-xs text-red-500 py-4 text-center">Fehler: ' + (j.error || 'unbekannt') +
      '<br><button onclick="ontoSearch()" class="mt-2 px-2 py-1 bg-brand text-white rounded text-[10px]">↻ Retry</button></div>';
    return;
  }
  renderOntoResults(j.data);
  // ✨ Auto-trigger KI Verify on meaningful queries (not browse mode)
  if (q.length >= 3 && !j.data.browse) {
    const auto = document.getElementById('aiAutoToggle');
    if (auto && auto.checked) {
      const aiInput = document.getElementById('aiVerifyQuery');
      if (aiInput) { aiInput.value = q; aiInput.dataset.userTyped = ''; }
      // Debounce: wait 500ms so user can still type without firing on each char
      clearTimeout(window._aiAutoTimer);
      window._aiAutoTimer = setTimeout(() => { runAiVerify(); }, 500);
    }
  }
}

function ontoTypeBadge(t) {
  const colors = {
    person:'bg-blue-50 text-blue-700',
    company:'bg-purple-50 text-purple-700',
    email:'bg-green-50 text-green-700',
    phone:'bg-amber-50 text-amber-700',
    domain:'bg-rose-50 text-rose-700',
    handle:'bg-pink-50 text-pink-700',
    address:'bg-teal-50 text-teal-700',
  };
  return `<span class="px-1.5 py-0.5 rounded text-[9px] font-mono ${colors[t]||'bg-gray-100 text-gray-600'}">${t}</span>`;
}

function renderOntoResults(d) {
  const list = document.getElementById('ontoResults');
  document.getElementById('ontoResultsHeader').textContent =
    `Ergebnisse (${d.counts.total}) · ${d.counts.ontology} Ontology · ${d.counts.scans} Scans · ${d.counts.live} Live`;

  let html = '';
  if (d.ontology.length) {
    html += '<div class="text-[9px] font-bold text-brand uppercase tracking-wider px-1">Ontology</div>';
    d.ontology.forEach(o => {
      const v = o.verified == 1 ? '<span class="text-green-600 ml-1">✓</span>' : '';
      html += `<div class="border border-gray-200 rounded-lg p-2 hover:border-brand hover:bg-brand/5 cursor-pointer transition" onclick="ontoSelect(${o.obj_id})">
        <div class="flex items-center justify-between gap-2">
          <span class="text-sm text-gray-900 font-medium truncate">${o.display_name}${v}</span>
          ${ontoTypeBadge(o.obj_type)}
        </div>
        <div class="text-[10px] text-gray-400 font-mono mt-0.5">${Math.round(o.confidence*100)}% · ${o.source_count} sources</div>
      </div>`;
    });
  }
  if (d.scans.length) {
    html += '<div class="text-[9px] font-bold text-brand uppercase tracking-wider px-1 mt-3">Vergangene Scans</div>';
    d.scans.forEach(s => {
      const name = s.scan_name || s.scan_email || s.scan_phone || s.scan_address || '(kein Identifier)';
      const sub = [s.scan_email, s.scan_phone].filter(Boolean).join(' · ') || (s.created_at||'').substring(0,10);
      html += `<div class="border border-gray-200 rounded-lg p-2 hover:border-brand hover:bg-brand/5 cursor-pointer transition" onclick="ontoIngestScan(${s.scan_id})">
        <div class="flex items-center justify-between gap-2">
          <span class="text-sm text-gray-900 font-medium truncate">${name}</span>
          <span class="text-[9px] font-mono text-gray-400">#${s.scan_id}</span>
        </div>
        <div class="text-[10px] text-gray-500 mt-0.5 truncate">${sub}</div>
      </div>`;
    });
  }
  if (d.live.length) {
    html += '<div class="text-[9px] font-bold text-brand uppercase tracking-wider px-1 mt-3">Live (SearXNG)</div>';
    d.live.forEach(l => {
      html += `<a href="${l.url}" target="_blank" class="block border border-gray-200 rounded-lg p-2 hover:border-brand hover:bg-brand/5 transition no-underline">
        <div class="text-sm text-gray-900 font-medium truncate">${l.title}</div>
        <div class="text-[10px] text-gray-500 truncate">${l.url}</div>
      </a>`;
    });
  }
  if (!html) html = '<div class="text-xs text-gray-400 py-8 text-center">Keine Treffer. Probiere LIVE einzuschalten.</div>';
  list.innerHTML = html;
}

// Click on past scan — ingest into ontology on demand then select
async function ontoIngestScan(scanId) {
  const j = await ontoFetchJson(`/api/gotham-expand.php?action=ingest_scan&scan_id=${scanId}`, {method:'POST'});
  if (!j.success || !j.obj_id) {
    alert('Import fehlgeschlagen: ' + (j.error || 'unbekannt'));
    return;
  }
  await ontoSelect(j.obj_id);
  setTimeout(ontoSearch, 300);
}

// Defensive JSON fetch — catches empty/truncated responses so the UI
// never throws "Unexpected end of JSON input" into the user's face.
async function ontoFetchJson(url, opts = {}) {
  try {
    const r = await fetch(url, opts);
    const text = await r.text();
    if (!text || !text.trim()) return { success: false, error: `empty response (HTTP ${r.status})` };
    try { return JSON.parse(text); }
    catch (e) { return { success: false, error: 'invalid JSON: ' + text.substring(0, 120) }; }
  } catch (e) {
    return { success: false, error: 'network: ' + e.message };
  }
}

async function ontoSelect(objId) {
  ONTO.currentObjId = objId;
  document.getElementById('ontoGraphActions').classList.remove('hidden');
  document.getElementById('ontoGraphActions').style.display = 'flex';
  document.getElementById('ontoBriefingLink').href = '/admin/gotham-briefing.php?obj_id=' + objId;
  document.getElementById('ontoFullLink').href = '/admin/gotham-detail.php?obj_id=' + objId;
  document.getElementById('ontoGraphHeader').textContent = 'Link-Graph — lade…';

  const [gr, dt] = await Promise.all([
    ontoFetchJson(`/api/gotham-expand.php?action=graph&obj_id=${objId}&depth=2`),
    ontoFetchJson(`/api/gotham-expand.php?action=detail&obj_id=${objId}`),
  ]);
  if (gr.success) {
    renderOntoGraph(gr.data);
  } else {
    document.getElementById('ontoGraphHeader').textContent = 'Graph error: ' + gr.error;
  }
  if (dt.success) {
    renderOntoDetail(dt.data);
    ONTO.currentObj = dt.data;
    // Auto-fill KI Verify input with object's name (if user hasn't typed anything)
    const qInput = document.getElementById('aiVerifyQuery');
    if (qInput && !qInput.dataset.userTyped) { qInput.value = dt.data.display_name || ''; }
    // Cache-only AI probe — show AI insights inline if we have them already
    const cached = await ontoLoadCachedAi(dt.data.display_name);
    // Auto-trigger full KI Verify if nothing cached AND auto toggle is on
    const auto = document.getElementById('aiAutoToggle');
    if (!cached && auto && auto.checked && dt.data.display_name) {
      clearTimeout(window._aiAutoTimer);
      window._aiAutoTimer = setTimeout(() => { runAiVerify(); }, 400);
    }
  } else {
    const d = document.getElementById('ontoDetail');
    d.classList.remove('hidden');
    d.innerHTML = '<div class="text-red-600 text-xs">Detail konnte nicht geladen werden: ' + dt.error + '</div>';
  }
}

async function ontoLoadCachedAi(name) {
  if (!name) return false;
  const j = await ontoFetchJson('/api/gotham-ai-verify.php', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({query: name, cache_only: true}),
  });
  if (!j.success) return false;
  const d = j.data;
  // Detect which sources have cached data
  const haveGroq = d.groq && d.groq.content && !d.groq.error;
  const havePplx = d.perplexity && d.perplexity.content && !d.perplexity.error;
  const haveSx   = d.searxng && (d.searxng.hits || 0) > 0;
  if (!haveGroq && !havePplx && !haveSx) {
    // Nothing cached — add a 1-click button below the detail panel
    const detail = document.getElementById('ontoDetail');
    if (detail && !detail.querySelector('.onto-ai-nudge')) {
      detail.insertAdjacentHTML('beforeend',
        '<div class="onto-ai-nudge mt-2 pt-2 border-t border-gray-200 text-[10px]">' +
        '<button onclick="runAiVerify()" class="text-indigo-600 hover:underline font-semibold">🤖 KI Verify laufen lassen</button> ' +
        '<span class="text-gray-400">— kein Cache für dieses Object</span></div>');
    }
    return false;
  }
  // Render cached insights in a collapsible strip inside detail panel
  const esc = s => String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;');
  let html = '<div class="mt-2 pt-2 border-t border-indigo-200 space-y-1.5">' +
             '<div class="text-[10px] font-bold text-indigo-600 uppercase">🤖 Cached AI Insights</div>';
  if (haveGroq) {
    html += '<div class="text-[10px] bg-amber-50 border border-amber-200 rounded p-1.5">' +
            '<b class="text-amber-700">Groq:</b> ' + esc(d.groq.content.substring(0, 220)) +
            (d.groq.content.length > 220 ? '…' : '') + '</div>';
  }
  if (havePplx) {
    html += '<div class="text-[10px] bg-purple-50 border border-purple-200 rounded p-1.5">' +
            '<b class="text-purple-700">Perplexity:</b> ' + esc(d.perplexity.content.substring(0, 220)) +
            (d.perplexity.content.length > 220 ? '…' : '') + '</div>';
  }
  if (haveSx) {
    html += '<div class="text-[10px] bg-cyan-50 border border-cyan-200 rounded p-1.5">' +
            '<b class="text-cyan-700">SearXNG:</b> ' + d.searxng.hits + ' hits (cached)</div>';
  }
  html += '<button onclick="runAiVerify()" class="text-[10px] text-indigo-600 hover:underline">↻ Refresh AI insights</button>';
  html += '</div>';
  const detail = document.getElementById('ontoDetail');
  if (detail) detail.insertAdjacentHTML('beforeend', html);
  return true;  // had cached data
}

function renderOntoGraph(data) {
  document.getElementById('ontoGraphHeader').textContent =
    `Link-Graph — ${data.nodes.length} Nodes · ${data.edges.length} Edges`;
  if (ONTO.cy) ONTO.cy.destroy();
  ONTO.cy = cytoscape({
    container: document.getElementById('ontoGraph'),
    elements: [...data.nodes, ...data.edges],
    style: [
      { selector:'node', style:{
          'label':'data(label)','color':'#374151','font-size':'10px',
          'text-valign':'bottom','text-margin-y':5,
          'background-color':'<?= BRAND ?>','width':24,'height':24,
          'border-width':2,'border-color':'#fff',
      }},
      { selector:'node[type="person"]', style:{'background-color':'#3b82f6'} },
      { selector:'node[type="company"]', style:{'background-color':'#a855f7'} },
      { selector:'node[type="email"]', style:{'background-color':'#10b981'} },
      { selector:'node[type="phone"]', style:{'background-color':'#f59e0b'} },
      { selector:'node[type="domain"]', style:{'background-color':'#f43f5e'} },
      { selector:'node[type="handle"]', style:{'background-color':'#ec4899'} },
      { selector:'node[type="address"]', style:{'background-color':'#14b8a6'} },
      { selector:'node[verified = 1]', style:{'border-color':'#fbbf24','border-width':3} },
      { selector:'edge', style:{
          'width':1,'line-color':'#cbd5e1','target-arrow-color':'#cbd5e1',
          'target-arrow-shape':'triangle','curve-style':'bezier',
          'label':'data(label)','font-size':'8px','color':'#94a3b8',
          'text-rotation':'autorotate','text-margin-y':-5,
      }},
    ],
    layout:{ name:'cose', animate:true, animationDuration:400, nodeRepulsion:6000, idealEdgeLength:80 },
  });
  ONTO.cy.on('tap','node', e => ontoSelect(e.target.data('obj_id')));
}

function renderOntoDetail(o) {
  const d = document.getElementById('ontoDetail');
  d.classList.remove('hidden');
  let html = `<div class="flex items-center justify-between mb-2">
    <span class="font-semibold text-gray-900">${o.display_name} ${ontoTypeBadge(o.obj_type)}</span>
    <span class="text-[10px] text-gray-400 font-mono">${Math.round(o.confidence*100)}% · ${o.source_count} sources · ${(o.last_updated||'').substring(0,10)}</span>
  </div>`;
  if (o.events && o.events.length) {
    html += '<div class="text-[9px] font-bold text-gray-500 uppercase mb-1">Timeline</div><div class="space-y-0.5 mb-2">';
    o.events.slice(0,5).forEach(e => {
      html += `<div class="text-[10px] text-gray-600"><span class="font-mono text-gray-400">${e.event_date||(e.created_at||'').substring(0,10)}</span> ${e.title}</div>`;
    });
    html += '</div>';
  }
  if (o.links_out && o.links_out.length) {
    html += '<div class="text-[9px] font-bold text-gray-500 uppercase mb-1">Links</div><div class="flex flex-wrap gap-1">';
    o.links_out.slice(0,12).forEach(l => {
      html += `<span class="px-1.5 py-0.5 bg-white border border-gray-200 rounded text-[10px] text-gray-700 cursor-pointer hover:border-brand" onclick="ontoSelect(${l.to_obj})">${l.relation} → ${l.target_name}</span>`;
    });
    html += '</div>';
  }
  d.innerHTML = html;
}

// ──────────────────────────────────────────────────────────────
// 🤖 KI VERIFY — multi-LLM lookup with inline rendering
// ──────────────────────────────────────────────────────────────
async function runAiVerify() {
  const q = document.getElementById('aiVerifyQuery').value.trim() || (ONTO.query || '');
  if (!q && !ONTO.currentObjId) {
    alert('Gib eine Query ein oder wähle ein Ergebnis aus.');
    return;
  }
  const btn = document.getElementById('aiVerifyBtn');
  const resultDiv = document.getElementById('aiVerifyResult');
  const timing = document.getElementById('aiVerifyTiming');
  btn.disabled = true; btn.textContent = '🤖 Läuft…';
  timing.textContent = 'querying 4 sources…';
  resultDiv.classList.remove('hidden');
  resultDiv.innerHTML = '<div class="col-span-full text-[11px] text-indigo-700 py-6 text-center">' +
    '<div class="inline-block w-4 h-4 border-2 border-indigo-500 border-t-transparent rounded-full animate-spin mr-2"></div>' +
    'Querying Groq · Perplexity · Grok · SearXNG in parallel…</div>';

  const body = q ? { query: q } : { obj_id: ONTO.currentObjId };
  const j = await ontoFetchJson('/api/gotham-ai-verify.php', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify(body),
  });
  if (!j.success) {
    resultDiv.innerHTML = '<div class="col-span-full text-xs text-red-600 py-4">Fehler: ' + (j.error || 'unbekannt') + '</div>';
    btn.disabled = false; btn.textContent = '🤖 Verify';
    timing.textContent = 'error';
    return;
  }
  const d = j.data;
  timing.textContent = 'total: ' + d.total_elapsed + 's';
  const esc = s => String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

  function card(title, iconColor, content, elapsed, cached, error, citations) {
    if (error) {
      return '<div class="bg-white border border-red-200 rounded p-2.5">' +
        '<div class="flex items-center justify-between mb-1">' +
        '<span class="text-[10px] font-bold uppercase ' + iconColor + '">' + title + '</span>' +
        '<span class="text-[9px] text-red-500">' + esc(error) + '</span></div></div>';
    }
    if (!content) {
      return '<div class="bg-white border border-gray-200 rounded p-2.5 opacity-60">' +
        '<div class="flex items-center justify-between mb-1">' +
        '<span class="text-[10px] font-bold uppercase ' + iconColor + '">' + title + '</span>' +
        '<span class="text-[9px] text-gray-400">no result</span></div></div>';
    }
    const cacheTag = cached ? '<span class="text-[9px] text-amber-600 ml-1">cached</span>' : '';
    const citLinks = (citations || []).slice(0, 6).map((c, i) =>
      '<a href="' + esc(c) + '" target="_blank" class="text-[9px] text-indigo-500 hover:underline mr-1">[' + (i+1) + ']</a>'
    ).join('');
    return '<div class="bg-white border border-gray-200 rounded p-2.5">' +
      '<div class="flex items-center justify-between mb-1">' +
      '<span class="text-[10px] font-bold uppercase ' + iconColor + '">' + title + '</span>' +
      '<span class="text-[9px] text-gray-400 font-mono">' + elapsed + 's' + cacheTag + '</span></div>' +
      '<div class="text-[11px] text-gray-800 leading-relaxed whitespace-pre-wrap">' + esc(content) + '</div>' +
      (citLinks ? '<div class="mt-1 pt-1 border-t border-gray-100">' + citLinks + '</div>' : '') +
      '</div>';
  }

  function sxCard() {
    const sx = d.searxng || {};
    if (sx.error) {
      return '<div class="bg-white border border-red-200 rounded p-2.5 col-span-full">' +
        '<div class="text-[10px] font-bold uppercase text-red-600 mb-1">SearXNG error</div>' +
        '<div class="text-[10px] text-red-500">' + esc(sx.error) + '</div></div>';
    }
    if (!sx.hits) {
      return '<div class="bg-white border border-gray-200 rounded p-2.5 col-span-full opacity-60">' +
        '<div class="text-[10px] font-bold uppercase text-cyan-700">SearXNG (250 engines)</div>' +
        '<div class="text-[10px] text-gray-400">no hits</div></div>';
    }
    const items = sx.results.map(r =>
      '<a href="' + esc(r.url) + '" target="_blank" class="block border border-gray-100 rounded p-1.5 hover:bg-cyan-50 transition no-underline">' +
      '<div class="text-[11px] font-semibold text-gray-900 truncate">' + esc(r.title) + '</div>' +
      '<div class="text-[9px] text-cyan-700 truncate">' + esc(r.url) + '</div>' +
      '<div class="text-[10px] text-gray-600 line-clamp-2">' + esc(r.snippet) + '</div></a>'
    ).join('');
    const cacheTag = sx.cached ? '<span class="text-[9px] text-amber-600 ml-1">cached</span>' : '';
    return '<div class="bg-white border border-gray-200 rounded p-2.5 col-span-full">' +
      '<div class="flex items-center justify-between mb-2">' +
      '<span class="text-[10px] font-bold uppercase text-cyan-700">🔎 SearXNG · ' + sx.hits + ' hits</span>' +
      '<span class="text-[9px] text-gray-400 font-mono">' + sx.elapsed + 's' + cacheTag + '</span></div>' +
      '<div class="grid grid-cols-1 md:grid-cols-2 gap-1.5">' + items + '</div></div>';
  }

  resultDiv.innerHTML =
    card('⚡ Groq · llama-3.3-70b', 'text-amber-600', d.groq.content, d.groq.elapsed, d.groq.cached, d.groq.error) +
    card('🔮 Perplexity · sonar', 'text-purple-600', d.perplexity.content, d.perplexity.elapsed, d.perplexity.cached, d.perplexity.error, d.perplexity.citations) +
    (d.grok && d.grok.configured ? card('🤖 Grok · x.ai', 'text-slate-700', d.grok.content, d.grok.elapsed, d.grok.cached, d.grok.error) : '') +
    sxCard();

  btn.disabled = false; btn.textContent = '🤖 Verify';
}

// Auto-fill AI verify input when user types in the main search box
document.getElementById('ontoQuery').addEventListener('input', e => {
  const ai = document.getElementById('aiVerifyQuery');
  if (ai && !ai.dataset.userTyped) ai.value = e.target.value.trim();
});
document.getElementById('aiVerifyQuery').addEventListener('input', e => {
  e.target.dataset.userTyped = '1';
});

// ──────────────────────────────────────────────────────────────
// WATCHLIST UI
// ──────────────────────────────────────────────────────────────
async function ontoLoadWatchlist() {
  const j = await ontoFetchJson('/api/gotham-watchlist.php?action=list');
  const el = document.getElementById('ontoWatchlist');
  if (!j.success) { el.innerHTML = '<div class="text-[10px] text-red-500">'+j.error+'</div>'; return; }
  const rows = j.data || [];
  if (!rows.length) { el.innerHTML = '<div class="text-[10px] text-gray-400">no watches yet</div>'; return; }
  el.innerHTML = rows.map(w => `
    <div class="flex items-center justify-between bg-white border border-gray-200 rounded px-2 py-1 text-[11px]">
      <div class="flex-1 min-w-0 cursor-pointer" onclick="document.getElementById('ontoQuery').value='${w.query.replace(/'/g,"\\'")}'; ontoSearch();">
        <span class="font-semibold truncate">${w.label}</span>
        <span class="text-gray-400 ml-1">· ${w.query}</span>
        <span class="text-[9px] text-gray-400 ml-1 font-mono">[${w.last_hit_count} hits]</span>
      </div>
      <button onclick="ontoRemoveWatch(${w.watch_id})" class="text-red-400 hover:text-red-600 ml-1 text-[10px]">✕</button>
    </div>
  `).join('');
}

async function ontoAddWatch() {
  const label = document.getElementById('ontoWatchLabel').value.trim();
  const query = document.getElementById('ontoWatchQuery').value.trim();
  if (!label || !query) { alert('Label + Query required'); return; }
  const j = await ontoFetchJson('/api/gotham-watchlist.php?action=add', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({label, query}),
  });
  if (!j.success) { alert('Add failed: ' + j.error); return; }
  document.getElementById('ontoWatchLabel').value = '';
  document.getElementById('ontoWatchQuery').value = '';
  ontoLoadWatchlist();
}

async function ontoRemoveWatch(id) {
  if (!confirm('Remove watch?')) return;
  const j = await ontoFetchJson('/api/gotham-watchlist.php?action=remove', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({watch_id: id}),
  });
  if (j.success) ontoLoadWatchlist();
}

// ──────────────────────────────────────────────────────────────
// MERGE CANDIDATES UI
// ──────────────────────────────────────────────────────────────
async function ontoLoadMerges() {
  const el = document.getElementById('ontoMerges');
  el.innerHTML = '<div class="text-[10px] text-gray-400">scanning…</div>';
  const j = await ontoFetchJson('/api/gotham-expand.php?action=merge_candidates');
  if (!j.success) { el.innerHTML = '<div class="text-[10px] text-red-500">'+j.error+'</div>'; return; }
  const cands = j.data || [];
  if (!cands.length) { el.innerHTML = '<div class="text-[10px] text-gray-400">no duplicates found</div>'; return; }
  el.innerHTML = cands.slice(0, 20).map((c, i) => `
    <div class="bg-white border border-gray-200 rounded p-1.5">
      <div class="flex items-center justify-between gap-1">
        <span class="truncate flex-1">
          <span class="text-gray-900 font-medium">${c.person_a.name}</span>
          <span class="text-gray-400">≡</span>
          <span class="text-gray-900 font-medium">${c.person_b.name}</span>
        </span>
        <div class="flex gap-1 shrink-0">
          <button onclick="ontoMerge(${c.person_a.obj_id},${c.person_b.obj_id})" class="px-1.5 py-0.5 bg-brand text-white rounded text-[9px]">merge</button>
          <button onclick="ontoSelect(${c.person_a.obj_id})" class="px-1.5 py-0.5 border border-gray-200 rounded text-[9px]">view</button>
        </div>
      </div>
      <div class="text-[9px] text-gray-500 mt-0.5">${c.reason} · ${Math.round(c.confidence*100)}%</div>
    </div>
  `).join('');
}

async function ontoMerge(winnerId, loserId) {
  if (!confirm(`Merge #${loserId} → #${winnerId}? Irreversible.`)) return;
  const j = await ontoFetchJson('/api/gotham-expand.php?action=merge', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({winner_id: winnerId, loser_id: loserId}),
  });
  if (!j.success) { alert('Merge failed: ' + j.error); return; }
  alert(`✓ Merged #${loserId} into #${winnerId}`);
  ontoLoadMerges();
  ontoSearch();
}

// Auto-load watchlist on first load
setTimeout(ontoLoadWatchlist, 800);

// ──────────────────────────────────────────────────────────────
// 📎 OCR DOCUMENT DROP — upload image/pdf/text → entities → ontology
// ──────────────────────────────────────────────────────────────
async function runOcr() {
  const file = document.getElementById('ocrFile').files[0];
  const label = document.getElementById('ocrLabel').value.trim();
  const result = document.getElementById('ocrResult');
  const btn = document.getElementById('ocrBtn');
  if (!file) { result.textContent = '⚠ Wähle eine Datei'; return; }
  btn.disabled = true; btn.textContent = '📎 Läuft…';
  result.textContent = 'OCR + entity extraction…';
  try {
    const fd = new FormData();
    fd.append('file', file);
    if (label) fd.append('label', label);
    const r = await fetch('/api/gotham-ocr.php', { method: 'POST', body: fd });
    const j = await r.json();
    if (!j.success) throw new Error(j.error || 'OCR failed');
    const ent = j.entities || {};
    result.innerHTML = '✓ <b>' + (j.label || 'doc') + '</b> · ' + j.text_length + ' chars · ' +
      'emails:' + (ent.emails||0) + ' phones:' + (ent.phones||0) +
      ' urls:' + (ent.urls||0) + ' handles:' + (ent.handles||0) +
      ' ibans:' + (ent.ibans||0) + '<br>' +
      '<span class="text-purple-500">→ doc obj #' + j.doc_obj_id + ' created · ' + j.stats.objects_created + ' objects · ' + j.stats.links_created + ' links</span>';
    // Clear file input
    document.getElementById('ocrFile').value = '';
    document.getElementById('ocrLabel').value = '';
    // Refresh stats
    setTimeout(() => {
      fetch('/api/gotham-search.php?action=stats').then(r => r.json()).then(j => {
        if (j && j.success) {
          const d = j.data;
          document.getElementById('ontoStatsMini').textContent =
            d.total_objects + ' Objects · ' + d.verified + ' verified · ' + d.total_links + ' Links · ' + d.total_scans + ' Scans';
        }
      });
    }, 300);
  } catch(e) {
    result.innerHTML = '<span class="text-red-600">✗ ' + e.message + '</span>';
  } finally {
    btn.disabled = false; btn.textContent = '📎 Extract';
  }
}

// ──────────────────────────────────────────────────────────────
// LINEAGE — source audit trail for selected object
// ──────────────────────────────────────────────────────────────
async function ontoLineage() {
  if (!ONTO.currentObjId) return;
  const j = await ontoFetchJson(`/api/gotham-expand.php?action=lineage&obj_id=${ONTO.currentObjId}`);
  const d = document.getElementById('ontoDetail');
  d.classList.remove('hidden');
  if (!j.success) {
    d.innerHTML = '<div class="text-red-600 text-xs">Lineage error: ' + (j.error || 'unbekannt') + '</div>';
    return;
  }
  const data = j.data;
  const o = data.object;
  let html = `<div class="flex items-center justify-between mb-2">
    <span class="font-semibold text-gray-900">🔍 Lineage: ${o.display_name} ${ontoTypeBadge(o.obj_type)}</span>
    <span class="text-[10px] text-gray-400 font-mono">${Math.round(o.confidence*100)}% · first ${o.first_seen.substring(0,10)}</span>
  </div>`;
  html += `<div class="text-[10px] text-gray-600 mb-2 italic">${data.summary}</div>`;
  if (data.sources && data.sources.length) {
    html += '<div class="text-[9px] font-bold text-gray-500 uppercase mb-1">Sources by origin</div>';
    html += '<div class="space-y-1 mb-2">';
    data.sources.forEach(s => {
      const rel = (s.relations || []).join(', ');
      html += `<div class="flex items-center justify-between text-[10px] bg-white border border-gray-200 rounded px-2 py-1">
        <span class="font-mono text-gray-700 truncate flex-1">${s.source}</span>
        <span class="text-gray-500 ml-2">${rel}</span>
        <span class="text-gray-400 ml-2 font-mono">${(s.first_seen||'').substring(5,16)}</span>
      </div>`;
    });
    html += '</div>';
  }
  if (data.scan_rows && data.scan_rows.length) {
    html += '<div class="text-[9px] font-bold text-gray-500 uppercase mb-1">Contributing scans</div>';
    html += '<div class="flex flex-wrap gap-1 mb-2">';
    data.scan_rows.slice(0, 10).forEach(s => {
      html += `<span class="text-[10px] bg-brand/10 text-brand border border-brand/30 rounded px-1.5 py-0.5">
        #${s.scan_id} ${(s.name||s.email||'').substring(0,20)} <span class="opacity-60">${(s.created_at||'').substring(5,10)}</span>
      </span>`;
    });
    html += '</div>';
  }
  d.innerHTML = html;
}

async function ontoCascade() {
  if (!ONTO.currentObjId) return;
  if (!confirm('Click-Expand Cascade starten? Das dauert 15-30s und sendet einen VULTURE Scan vom aktuellen Node aus.')) return;
  const fd = new FormData();
  fd.append('action','cascade');
  fd.append('obj_id', ONTO.currentObjId);
  fd.append('depth','2');
  fd.append('mode','fast');
  const j = await ontoFetchJson('/api/gotham-expand.php', {method:'POST', body:fd});
  if (!j.success) {
    alert('Cascade Fehler: ' + (j.error || 'unbekannt'));
    return;
  }
  alert(`✓ ${j.cascade_stats?.objects_created || 0} neue Objects · Confidence ${Math.round((j.confidence||0)*100)}%`);
  await ontoSelect(ONTO.currentObjId);
}
</script>

<!-- Recherche Links (collapsible) -->
<?php if ($scanName): ?>
<details class="bg-white rounded-xl border mb-4 overflow-hidden group">
  <summary class="px-5 py-3 border-b flex items-center justify-between cursor-pointer hover:bg-gray-50">
    <div class="flex items-center gap-2">
      <svg class="w-4 h-4 text-gray-400 transition-transform group-open:rotate-90" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
      <h3 class="font-semibold text-gray-900">Manuelle Recherche-Links</h3>
      <span class="text-xs text-gray-400">(<?= array_sum(array_map('count', socialLinks($scanName, $scanEmail, $scanPhone))) ?> Links)</span>
    </div>
  </summary>
  <div class="p-5 space-y-5">
    <?php foreach (socialLinks($scanName, $scanEmail, $scanPhone) as $group => $links): ?>
    <div>
      <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2 border-b pb-1"><?= $group ?></h4>
      <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-1.5">
        <?php foreach ($links as $link):
          $label = $link[0]; $url = $link[1]; ?>
        <a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="px-3 py-2 rounded-lg text-xs font-medium transition border border-gray-200 text-gray-700 hover:bg-gray-50 hover:border-gray-300 text-center truncate"><?= $label ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</details>
<?php endif; ?>

<!-- Report Generator -->
<div class="bg-white rounded-xl border mb-4 overflow-hidden" id="reportSection">
  <div class="p-4 flex items-center justify-between">
    <h3 class="font-semibold">OSINT Report</h3>
    <button onclick="generateReport()" class="px-4 py-2 bg-gray-800 text-white rounded-lg text-sm font-medium">Report als PDF drucken</button>
  </div>
</div>

<!-- Hidden printable report -->
<div id="printReport" class="hidden">
  <div style="font-family:Arial,sans-serif;max-width:800px;margin:0 auto;padding:40px;">
    <div style="border-bottom:3px solid <?= BRAND ?>;padding-bottom:15px;margin-bottom:20px;">
      <h1 style="margin:0;color:<?= BRAND ?>;">OSINT Personenbericht</h1>
      <p style="color:#666;margin:5px 0 0;">Erstellt: <?= date('d.m.Y H:i') ?> — <?= SITE ?></p>
      <p style="color:#999;font-size:12px;">Vertraulich — Nur für autorisierte interne Verwendung</p>
    </div>
    <h2 style="color:#333;border-bottom:1px solid #ddd;padding-bottom:8px;">Person</h2>
    <table style="width:100%;border-collapse:collapse;margin-bottom:20px;">
      <tr><td style="padding:6px 0;color:#666;width:150px;">Name</td><td style="padding:6px 0;font-weight:bold;"><?= e($scanName) ?></td></tr>
      <tr><td style="padding:6px 0;color:#666;">E-Mail</td><td style="padding:6px 0;"><?= e($scanEmail) ?></td></tr>
      <tr><td style="padding:6px 0;color:#666;">Telefon</td><td style="padding:6px 0;"><?= e($scanPhone) ?></td></tr>
      <?php if ($emailInfo): ?>
      <tr><td style="padding:6px 0;color:#666;">Email-Domain</td><td style="padding:6px 0;"><?= e($emailInfo['domain']) ?> (<?= $emailInfo['free']?'Free Provider':($emailInfo['disposable']?'WEGWERF':'Business') ?>)</td></tr>
      <tr><td style="padding:6px 0;color:#666;">MX Records</td><td style="padding:6px 0;"><?= $emailInfo['mx'] ?> <?= $emailInfo['mx_host']?'('.e($emailInfo['mx_host']).')':'' ?></td></tr>
      <tr><td style="padding:6px 0;color:#666;">Pattern</td><td style="padding:6px 0;"><?= $emailInfo['pattern'][0] ?> (<?= $emailInfo['pattern'][1] ?>)</td></tr>
      <?php endif; ?>
    </table>
    <?php if ($dbStats): ?>
    <h2 style="color:#333;border-bottom:1px solid #ddd;padding-bottom:8px;">Interne Daten</h2>
    <table style="width:100%;border-collapse:collapse;margin-bottom:20px;">
      <tr><td style="padding:6px 0;color:#666;width:150px;">Gesamt Jobs</td><td style="padding:6px 0;"><?= $dbStats['total_jobs'] ?></td></tr>
      <tr><td style="padding:6px 0;color:#666;">Davon erledigt</td><td style="padding:6px 0;"><?= $dbStats['done'] ?></td></tr>
      <tr><td style="padding:6px 0;color:#666;">Rechnungen gesamt</td><td style="padding:6px 0;"><?= number_format($dbStats['inv_total'],2,',','.') ?> EUR</td></tr>
      <tr><td style="padding:6px 0;color:#666;">Offen</td><td style="padding:6px 0;<?= $dbStats['inv_open']>0?'color:red;font-weight:bold':'' ?>"><?= number_format($dbStats['inv_open'],2,',','.') ?> EUR</td></tr>
    </table>
    <?php endif; ?>
    <?php if ($domainInfo): ?>
    <h2 style="color:#333;border-bottom:1px solid #ddd;padding-bottom:8px;">Website: <?= e($emailInfo['domain']) ?></h2>
    <table style="width:100%;border-collapse:collapse;margin-bottom:20px;">
      <tr><td style="padding:6px 0;color:#666;width:150px;">IP</td><td style="padding:6px 0;"><?= e($domainInfo['ip']??'—') ?></td></tr>
      <tr><td style="padding:6px 0;color:#666;">HTTP Status</td><td style="padding:6px 0;"><?= $domainInfo['http']??'—' ?></td></tr>
      <?php if (!empty($domainInfo['ssl'])): ?><tr><td style="padding:6px 0;color:#666;">SSL</td><td style="padding:6px 0;"><?= e($domainInfo['ssl']['issuer']) ?> (<?= $domainInfo['ssl']['days'] ?> Tage)</td></tr><?php endif; ?>
      <?php if (!empty($domainInfo['title'])): ?><tr><td style="padding:6px 0;color:#666;">Titel</td><td style="padding:6px 0;"><?= e($domainInfo['title']) ?></td></tr><?php endif; ?>
      <?php if (!empty($domainInfo['tech'])): ?><tr><td style="padding:6px 0;color:#666;">Technologien</td><td style="padding:6px 0;"><?= implode(', ',$domainInfo['tech']) ?></td></tr><?php endif; ?>
    </table>
    <?php endif; ?>
    <?php if (!empty($dbCustomers)): ?>
    <h2 style="color:#333;border-bottom:1px solid #ddd;padding-bottom:8px;">Datenbank-Treffer</h2>
    <table style="width:100%;border-collapse:collapse;margin-bottom:20px;border:1px solid #ddd;">
      <tr style="background:#f5f5f5;"><th style="padding:8px;text-align:left;border:1px solid #ddd;">Name</th><th style="padding:8px;text-align:left;border:1px solid #ddd;">Email</th><th style="padding:8px;text-align:left;border:1px solid #ddd;">Typ</th><th style="padding:8px;text-align:left;border:1px solid #ddd;">Jobs</th></tr>
      <?php foreach ($dbCustomers as $dc): ?>
      <tr><td style="padding:8px;border:1px solid #ddd;"><?= e($dc['name']) ?></td><td style="padding:8px;border:1px solid #ddd;"><?= e($dc['email']) ?></td><td style="padding:8px;border:1px solid #ddd;"><?= e($dc['customer_type']) ?></td><td style="padding:8px;border:1px solid #ddd;"><?= $dc['jobs'] ?></td></tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
    <div style="margin-top:30px;padding-top:15px;border-top:1px solid #ddd;color:#999;font-size:11px;">
      <p>Dieser Bericht wurde automatisch generiert von <?= SITE ?> OSINT Scanner am <?= date('d.m.Y') ?> um <?= date('H:i') ?> Uhr.</p>
      <p>Alle Daten stammen aus öffentlich zugänglichen Quellen und internen Datenbanken. Keine Gewähr auf Vollständigkeit oder Richtigkeit.</p>
      <p>Vertraulich — Weitergabe an Dritte nur mit ausdrücklicher Genehmigung.</p>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Customer Table -->
<div class="bg-white rounded-xl border">
  <div class="p-5 border-b flex items-center justify-between">
    <h3 class="font-semibold">Kunden (<?= count($customers) ?>)</h3>
    <input type="text" placeholder="Suchen..." class="px-3 py-2 border rounded-lg text-sm w-64" oninput="filterRows(this.value)"/>
  </div>
  <div class="overflow-x-auto">
    <table class="w-full text-sm" id="tbl">
      <thead class="bg-gray-50"><tr><th class="px-4 py-3 text-left">#</th><th class="px-4 py-3 text-left">Name</th><th class="px-4 py-3 text-left">Email</th><th class="px-4 py-3 text-left">Telefon</th><th class="px-4 py-3">Scan</th></tr></thead>
      <tbody class="divide-y">
      <?php foreach ($customers as $i=>$c): ?>
      <tr class="hover:bg-gray-50"><td class="px-4 py-3 text-gray-400"><?= $i+1 ?></td><td class="px-4 py-3 font-medium"><?= e($c['name']) ?></td><td class="px-4 py-3"><?= e($c['email']) ?></td><td class="px-4 py-3"><?= e($c['phone']??'') ?></td>
      <td class="px-4 py-3 text-center"><form method="post" class="inline"><?= csrfField() ?><input type="hidden" name="scan_id" value="<?= $c['customer_id'] ?>"><button class="px-3 py-1 bg-brand text-white text-xs rounded-lg font-medium">Scan</button></form></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php
$apiKey = API_KEY;
$script = <<<JS
function filterRows(q){q=q.toLowerCase();document.querySelectorAll('#tbl tbody tr').forEach(r=>{r.style.display=r.textContent.toLowerCase().includes(q)?'':'none'})}
function searchAirbnb(){
    var btn=document.getElementById('airbnbBtn');
    var addr=document.querySelector('[name=scan_address]')?.value||'';
    if(!addr){alert('Keine Adresse');return;}
    btn.textContent='Suche...'; btn.disabled=true;
    // Use Airbnb search page directly
    var results=document.getElementById('airbnbResults');
    var cards=document.getElementById('airbnbCards');
    // Open search in background and show inline
    var city=addr.split(',').pop().trim();
    var searchUrl='https://www.airbnb.com/s/'+encodeURIComponent(addr)+'/homes';
    cards.innerHTML='<div class="col-span-3 text-center py-4"><p class="text-sm text-gray-500 mb-3">Airbnb-Suche für: <b>'+addr+'</b></p><a href="'+searchUrl+'" target="_blank" class="px-4 py-2 bg-brand text-white rounded-lg text-sm font-medium inline-block">Auf Airbnb.com suchen</a><p class="text-xs text-gray-400 mt-2">Direktlink — Ergebnisse werden auf airbnb.com angezeigt</p></div>';
    results.classList.remove('hidden');
    document.getElementById('airbnbCount').textContent=addr;
    btn.textContent='Airbnb suchen'; btn.disabled=false;
}
function checkWhatsApp(phone){
    var btn=document.getElementById('waCheckBtn');
    var res=document.getElementById('waResult');
    btn.textContent='Prüfe...'; btn.disabled=true;
    res.classList.remove('hidden');
    // Check WhatsApp profile via wa.me redirect + profile photo API
    var waLink='https://wa.me/'+phone;
    var profilePic='https://api.whatsapp.com/send?phone='+phone;
    res.innerHTML='<div class="space-y-2">'+
        '<div class="flex items-center gap-3">'+
            '<img src="https://ui-avatars.com/api/?name='+phone+'&background=25d366&color=fff&size=48" class="w-12 h-12 rounded-full"/>'+
            '<div>'+
                '<div class="font-medium text-sm">+'+phone+'</div>'+
                '<div class="text-gray-400">WhatsApp Account</div>'+
            '</div>'+
        '</div>'+
        '<div class="grid grid-cols-3 gap-2">'+
            '<a href="'+waLink+'" target="_blank" class="px-2 py-1 border border-gray-200 rounded text-center hover:bg-gray-100">Chat öffnen</a>'+
            '<a href="https://www.google.com/search?q=%22'+phone+'%22+OR+%22+'+phone.replace(/(\d{3})(\d+)/,'$1 $2')+'%22+whatsapp" target="_blank" class="px-2 py-1 border border-gray-200 rounded text-center hover:bg-gray-100">Google WA</a>'+
            '<a href="https://www.google.com/search?q=%22'+phone+'%22+site:wa.me+OR+site:chat.whatsapp.com" target="_blank" class="px-2 py-1 border border-gray-200 rounded text-center hover:bg-gray-100">WA Links</a>'+
        '</div>'+
        '<div class="text-gray-400 text-[11px]">WhatsApp-Profilbild und Status können nur mit gespeichertem Kontakt geprüft werden. RapidAPI-Integration für automatische Prüfung möglich.</div>'+
    '</div>';
    btn.textContent='WhatsApp Profil prüfen'; btn.disabled=false;
}
function saveScan(){
    var btn=document.getElementById('saveScanBtn');
    btn.textContent='Speichern...'; btn.disabled=true;
    fetch('/api/index.php?action=osint/save',{method:'POST',headers:{'Content-Type':'application/json','X-API-Key':'$apiKey'},body:JSON.stringify({
        name:document.querySelector('[name=scan_name]')?.value||'',
        email:document.querySelector('[name=scan_email]')?.value||'',
        phone:document.querySelector('[name=scan_phone]')?.value||'',
        address:document.querySelector('[name=scan_address]')?.value||'',
        scan_data:{timestamp:new Date().toISOString(),source:'manual'},
        deep_data:null
    })}).then(r=>r.json()).then(d=>{
        btn.disabled=false;
        if(d.success){btn.textContent='Gespeichert!';btn.className='px-4 py-1.5 bg-green-600 text-white rounded-lg text-xs font-medium';setTimeout(()=>{btn.textContent='Ergebnis speichern';btn.className='px-4 py-1.5 bg-brand text-white rounded-lg text-xs font-medium';},2000);}
        else{btn.textContent='Fehler';setTimeout(()=>{btn.textContent='Ergebnis speichern';},2000);}
    }).catch(()=>{btn.textContent='Fehler';btn.disabled=false;});
}
function generateReport(){
    var report = document.getElementById('printReport');
    if (!report) { alert('Erst einen Scan durchführen!'); return; }
    // Also include deep scan results
    var deep = document.getElementById('deepResults');
    var extra = deep && !deep.classList.contains('hidden') ? '<h2 style="color:#333;border-bottom:1px solid #ddd;padding-bottom:8px;margin-top:20px;">Deep Scan Ergebnisse</h2>' + deep.innerHTML : '';
    var win = window.open('','_blank','width=900,height=700');
    win.document.write('<html><head><title>OSINT Report</title><style>body{font-family:Arial,sans-serif;padding:40px;max-width:800px;margin:0 auto} table{width:100%;border-collapse:collapse} td,th{padding:6px;border:1px solid #ddd;text-align:left} .tag{display:inline-block;padding:2px 8px;border-radius:4px;font-size:12px;margin:2px}</style></head><body>' + report.innerHTML + extra + '</body></html>');
    win.document.close();
    setTimeout(function(){ win.print(); }, 500);
}
function runDeepScan(){
    var btn = document.getElementById('deepScanBtn');
    btn.textContent = 'Scanne...'; btn.disabled = true;
    document.getElementById('deepLoading').classList.remove('hidden');
    document.getElementById('deepResults').classList.add('hidden');
    fetch('/api/osint-deep.php',{
        method:'POST',
        headers:{'Content-Type':'application/json','X-API-Key':'$apiKey'},
        body:JSON.stringify({
            email: document.querySelector('[name=scan_email]').value,
            name: document.querySelector('[name=scan_name]').value,
            phone: document.querySelector('[name=scan_phone]').value,
            address: document.querySelector('[name=scan_address]')?.value || ''
        })
    }).then(r=>r.json()).then(d=>{
        document.getElementById('deepLoading').classList.add('hidden');
        btn.textContent = 'Erneut scannen'; btn.disabled = false;
        if(!d.success){alert(d.error||'Fehler');return;}
        var cards = document.getElementById('deepCards');
        cards.innerHTML = '';
        var data = d.data;
        renderDeepCards(data, cards);
        var detailCards = document.createElement('div');
        detailCards.className = 'col-span-2 mt-4 pt-4 border-t';
        detailCards.innerHTML = '<h4 class="font-semibold text-sm text-gray-500 mb-3">Detaillierte Ergebnisse</h4><div class="grid grid-cols-1 lg:grid-cols-2 gap-4" id="detailGrid"></div>';
        cards.parentNode.appendChild(detailCards);
        cards = document.getElementById('detailGrid');

        // WHOIS
        if(data.whois){
            var w = data.whois;
            cards.innerHTML += '<div class="bg-gray-50 rounded-xl border p-4"><h4 class="font-bold text-sm mb-2 text-purple-700">WHOIS Domain</h4>'+
                (w.registrant?'<div class="flex justify-between text-sm"><span class="text-gray-500">Registrant</span><b>'+w.registrant+'</b></div>':'')+
                (w.org?'<div class="flex justify-between text-sm"><span class="text-gray-500">Organisation</span><b>'+w.org+'</b></div>':'')+
                (w.registrar?'<div class="flex justify-between text-sm"><span class="text-gray-500">Registrar</span><span>'+w.registrar+'</span></div>':'')+
                (w.created?'<div class="flex justify-between text-sm"><span class="text-gray-500">Erstellt</span><span>'+w.created+'</span></div>':'')+
                (w.country?'<div class="flex justify-between text-sm"><span class="text-gray-500">Land</span><span>'+w.country+'</span></div>':'')+
                '</div>';
        }

        // Subdomains
        if(data.subdomains && data.subdomains.count>0){
            var subs = data.subdomains.data.map(s=>'<span class="inline-block px-2 py-0.5 bg-blue-100 text-blue-800 rounded text-xs m-0.5">'+s+'</span>').join('');
            cards.innerHTML += '<div class="bg-gray-50 rounded-xl border p-4"><h4 class="font-bold text-sm mb-2 text-blue-700">Subdomains ('+data.subdomains.count+' gefunden)</h4><div>'+subs+'</div></div>';
        }

        // Email Security
        if(data.email_security){
            var es = data.email_security;
            cards.innerHTML += '<div class="bg-gray-50 rounded-xl border p-4"><h4 class="font-bold text-sm mb-2 text-green-700">Email Security</h4>'+
                '<div class="flex justify-between text-sm"><span>SPF</span><span class="'+(es.has_spf?'text-green-600':'text-red-600')+' font-bold">'+(es.has_spf?'Ja':'FEHLT')+'</span></div>'+
                '<div class="flex justify-between text-sm"><span>DMARC</span><span class="'+(es.has_dmarc?'text-green-600':'text-red-600')+' font-bold">'+(es.has_dmarc?'Ja':'FEHLT')+'</span></div>'+
                (es.spf.length?'<div class="text-xs text-gray-400 mt-1 break-all">'+es.spf[0]+'</div>':'')+
                '</div>';
        }

        // Reverse IP
        if(data.reverse_ip){
            var hosts = data.reverse_ip.hosts.map(h=>'<div class="text-xs py-0.5">'+h+'</div>').join('');
            cards.innerHTML += '<div class="bg-gray-50 rounded-xl border p-4"><h4 class="font-bold text-sm mb-2 text-orange-700">Gleiche IP: '+data.reverse_ip.ip+' ('+data.reverse_ip.count+' Sites)</h4>'+hosts+'</div>';
        }

        // Wayback
        if(data.wayback){
            cards.innerHTML += '<div class="bg-gray-50 rounded-xl border p-4"><h4 class="font-bold text-sm mb-2 text-amber-700">Wayback Machine</h4>'+
                '<div class="text-sm">Älteste Version: <a href="'+data.wayback.url+'" target="_blank" class="text-brand underline">'+data.wayback.timestamp+'</a></div></div>';
        }

        // Username Search
        if(data.username_search && data.username_search.length>0){
            var us = data.username_search.map(function(u){
                return '<div class="flex items-center justify-between text-sm py-1 border-b border-gray-100">'+
                    '<span class="font-medium">'+u.platform+'</span>'+
                    '<a href="'+u.url+'" target="_blank" class="text-brand underline text-xs">'+u.username+'</a></div>';
            }).join('');
            cards.innerHTML += '<div class="bg-gray-50 rounded-xl border p-4"><h4 class="font-bold text-sm mb-2 text-cyan-700">Username Search ('+data.username_search.length+' gefunden)</h4>'+us+'</div>';
        }

        // Breach Check
        if(data.breach_check){
            var bc = data.breach_check;
            var bcClass = bc.breached ? 'border-red-200' : '';
            var bcIcon = bc.breached ? '<span class="text-red-600 font-bold text-lg">WARNUNG — Passwort in '+bc.count+' Breaches gefunden</span>' : '<span class="text-green-600 font-bold">Nicht in bekannten Breaches gefunden</span>';
            cards.innerHTML += '<div class="bg-gray-50 rounded-xl border p-4 '+bcClass+'"><h4 class="font-bold text-sm mb-2 text-red-700">Breach Check (HIBP)</h4>'+bcIcon+'</div>';
        }

        // Phone OSINT
        if(data.phone_osint){
            var po = data.phone_osint;
            var poHtml = '';
            if(po.formatted) poHtml += '<div class="flex justify-between text-sm"><span class="text-gray-500">Nummer</span><span class="font-mono">'+po.formatted+'</span></div>';
            if(po.country) poHtml += '<div class="flex justify-between text-sm"><span class="text-gray-500">Land</span><span>'+po.country+'</span></div>';
            if(po.type) poHtml += '<div class="flex justify-between text-sm"><span class="text-gray-500">Typ</span><span>'+po.type+'</span></div>';
            if(po.carrier) poHtml += '<div class="flex justify-between text-sm"><span class="text-gray-500">Provider</span><span>'+po.carrier+'</span></div>';
            if(po.search_links){
                poHtml += '<div class="flex gap-1.5 flex-wrap mt-2">';
                for(var sl in po.search_links){
                    poHtml += '<a href="'+po.search_links[sl]+'" target="_blank" class="px-3 py-1 bg-green-600 text-white rounded-lg text-xs font-medium hover:opacity-80">'+sl+'</a>';
                }
                poHtml += '</div>';
            }
            cards.innerHTML += '<div class="bg-gray-50 rounded-xl border p-4"><h4 class="font-bold text-sm mb-2 text-green-700">Phone OSINT</h4>'+poHtml+'</div>';
        }

        // Geocoding / Address
        if(data.geocoding && data.geocoding.lat){
            var geo = data.geocoding;
            var geoHtml = '';
            if(geo.display_name) geoHtml += '<div class="text-sm mb-2"><span class="text-gray-500">Adresse:</span> <span class="font-medium">'+geo.display_name+'</span></div>';
            geoHtml += '<div class="rounded-lg overflow-hidden border" style="height:200px">'+
                '<iframe src="https://www.openstreetmap.org/export/embed.html?bbox='+(parseFloat(geo.lon)-0.005)+','+(parseFloat(geo.lat)-0.003)+','+(parseFloat(geo.lon)+0.005)+','+(parseFloat(geo.lat)+0.003)+'&layer=mapnik&marker='+geo.lat+','+geo.lon+'" width="100%" height="200" frameborder="0"></iframe></div>';
            cards.innerHTML += '<div class="bg-gray-50 rounded-xl border p-4"><h4 class="font-bold text-sm mb-2 text-indigo-700">Geocoding</h4>'+geoHtml+'</div>';
        }

        // VirusTotal
        if(data.virustotal){
            var vt = data.virustotal;
            var vtScore = vt.malicious > 0 ? 'text-red-600' : 'text-green-600';
            var vtHtml = '<div class="space-y-1 text-sm">';
            vtHtml += '<div class="flex justify-between"><span class="text-gray-500">Domain</span><span class="font-medium">'+vt.domain+'</span></div>';
            vtHtml += '<div class="flex justify-between"><span class="text-gray-500">Reputation</span><span class="font-bold '+vtScore+'">'+vt.reputation+'</span></div>';
            vtHtml += '<div class="flex justify-between"><span class="text-gray-500">Malicious</span><span class="'+(vt.malicious>0?'text-red-600 font-bold':'text-green-600')+'">'+vt.malicious+'</span></div>';
            vtHtml += '<div class="flex justify-between"><span class="text-gray-500">Suspicious</span><span>'+vt.suspicious+'</span></div>';
            vtHtml += '<div class="flex justify-between"><span class="text-gray-500">Harmless</span><span class="text-green-600">'+vt.harmless+'</span></div>';
            if(vt.categories.length>0) vtHtml += '<div class="flex justify-between"><span class="text-gray-500">Kategorien</span><span>'+vt.categories.join(', ')+'</span></div>';
            if(vt.registrar) vtHtml += '<div class="flex justify-between"><span class="text-gray-500">Registrar</span><span>'+vt.registrar+'</span></div>';
            if(vt.creation_date) vtHtml += '<div class="flex justify-between"><span class="text-gray-500">Erstellt</span><span>'+vt.creation_date+'</span></div>';
            vtHtml += '</div>';
            cards.innerHTML += '<div class="bg-gray-50 rounded-xl border p-4"><h4 class="font-bold text-sm mb-2">VirusTotal — Domain Reputation</h4>'+vtHtml+'</div>';
        }

        // Hunter.io Email
        if(data.hunter){
            var hu = data.hunter;
            var huColor = hu.result==='deliverable'?'text-green-600':(hu.result==='risky'?'text-amber-600':'text-red-600');
            var huHtml = '<div class="space-y-1 text-sm">';
            huHtml += '<div class="flex justify-between"><span class="text-gray-500">Email</span><span class="font-mono">'+hu.email+'</span></div>';
            huHtml += '<div class="flex justify-between"><span class="text-gray-500">Status</span><span class="font-bold '+huColor+'">'+hu.result.toUpperCase()+'</span></div>';
            huHtml += '<div class="flex justify-between"><span class="text-gray-500">Score</span><span class="font-bold">'+hu.score+'%</span></div>';
            if(hu.first_name) huHtml += '<div class="flex justify-between"><span class="text-gray-500">Name</span><span>'+hu.first_name+' '+hu.last_name+'</span></div>';
            huHtml += '<div class="flex justify-between"><span class="text-gray-500">Webmail</span><span>'+(hu.webmail?'Ja':'Nein')+'</span></div>';
            huHtml += '<div class="flex justify-between"><span class="text-gray-500">Wegwerf</span><span class="'+(hu.disposable?'text-red-600 font-bold':'')+(hu.disposable?'">JA':'">Nein')+'</span></div>';
            huHtml += '<div class="flex justify-between"><span class="text-gray-500">SMTP</span><span>'+(hu.smtp_check?'OK':'Fehlt')+'</span></div>';
            huHtml += '<div class="flex justify-between"><span class="text-gray-500">Quellen</span><span>'+hu.sources+' gefunden</span></div>';
            huHtml += '</div>';
            cards.innerHTML += '<div class="bg-gray-50 rounded-xl border p-4"><h4 class="font-bold text-sm mb-2">Hunter.io — Email Verifikation</h4>'+huHtml+'</div>';
        }

        // Hunter Domain (other emails at same company)
        if(data.hunter_domain){
            var hd = data.hunter_domain;
            var hdHtml = '<div class="text-sm mb-2"><b>'+hd.organization+'</b> — '+hd.emails_found+' Emails gefunden</div>';
            hd.emails.forEach(function(e){
                hdHtml += '<div class="text-xs flex justify-between border-b border-gray-100 py-1"><span class="font-mono">'+e.email+'</span><span class="text-gray-400">'+e.name+(e.position?' · '+e.position:'')+'</span></div>';
            });
            cards.innerHTML += '<div class="bg-gray-50 rounded-xl border p-4"><h4 class="font-bold text-sm mb-2">Hunter.io — Firmen-Emails</h4>'+hdHtml+'</div>';
        }

        // Shodan
        if(data.shodan){
            var sh = data.shodan;
            var shHtml = '<div class="space-y-1 text-sm">';
            shHtml += '<div class="flex justify-between"><span class="text-gray-500">IP</span><span class="font-mono">'+sh.ip+'</span></div>';
            shHtml += '<div class="flex justify-between"><span class="text-gray-500">ISP</span><span>'+sh.isp+'</span></div>';
            shHtml += '<div class="flex justify-between"><span class="text-gray-500">Org</span><span>'+sh.org+'</span></div>';
            shHtml += '<div class="flex justify-between"><span class="text-gray-500">Land</span><span>'+sh.country+' — '+sh.city+'</span></div>';
            if(sh.os) shHtml += '<div class="flex justify-between"><span class="text-gray-500">OS</span><span>'+sh.os+'</span></div>';
            shHtml += '<div class="flex justify-between"><span class="text-gray-500">Ports</span><span class="font-mono">'+sh.ports.join(', ')+'</span></div>';
            if(sh.services.length>0){
                shHtml += '<div class="mt-2 space-y-0.5">';
                sh.services.forEach(function(s){
                    shHtml += '<div class="text-xs flex justify-between"><span>Port '+s.port+'/'+s.transport+'</span><span class="text-gray-500">'+s.product+(s.version?' '+s.version:'')+'</span></div>';
                });
                shHtml += '</div>';
            }
            if(sh.vulns && sh.vulns.length>0){
                shHtml += '<div class="mt-2 p-2 bg-red-50 rounded-lg"><div class="text-xs text-red-700 font-bold">Schwachstellen ('+sh.vulns.length+')</div>';
                sh.vulns.forEach(function(v){shHtml += '<span class="inline-block px-1.5 py-0.5 bg-red-100 text-red-800 rounded text-xs m-0.5">'+v+'</span>';});
                shHtml += '</div>';
            }
            shHtml += '</div>';
            cards.innerHTML += '<div class="bg-gray-50 rounded-xl border p-4"><h4 class="font-bold text-sm mb-2">Shodan — Server Scan</h4>'+shHtml+'</div>';
        }

        // Social Profiles (search links)
        if(data.profiles){
            var prof = '';
            for(var p in data.profiles){
                var pr = data.profiles[p];
                if(pr.type === 'search_url'){
                    prof += '<a href="'+pr.url+'" target="_blank" class="px-3 py-1.5 rounded-lg text-xs font-medium text-white hover:opacity-80 inline-block m-0.5" style="background:'+pr.color+'">'+p+'</a>';
                } else {
                    prof += '<div class="flex items-center justify-between text-sm py-1 border-b border-gray-100"><span class="font-medium capitalize">'+p+'</span>'+
                        '<span class="'+(pr.exists?'text-green-600 font-bold':'text-gray-400')+'">'+(pr.exists?'GEFUNDEN':'—')+'</span>'+
                        (pr.exists?'<a href="'+pr.url+'" target="_blank" class="text-xs text-brand underline ml-2">Oeffnen</a>':'')+'</div>';
                }
            }
            cards.innerHTML += '<div class="bg-gray-50 rounded-xl border p-4"><h4 class="font-bold text-sm mb-2 text-pink-700">Social Media Suche</h4><div class="flex flex-wrap">'+prof+'</div></div>';
        }

        // Email exposure
        if(data.email_exposure && data.email_exposure.count>0){
            var emails = data.email_exposure.emails.map(function(e){return '<div class="text-xs py-0.5 font-mono">'+e+'</div>'}).join('');
            cards.innerHTML += '<div class="bg-gray-50 rounded-xl border p-4 border-red-200"><h4 class="font-bold text-sm mb-2 text-red-700">Email Exposure ('+data.email_exposure.count+' gefunden)</h4>'+emails+'</div>';
        }

        // GLEIF LEI
        if(data.gleif_lei && data.gleif_lei.count>0){
            var lei = data.gleif_lei.records.map(function(r){
                return '<div class="border-b border-gray-100 py-2 text-sm">'+
                    '<div class="flex justify-between"><span class="text-gray-500">LEI</span><span class="font-mono font-medium">'+r.lei+'</span></div>'+
                    '<div class="flex justify-between"><span class="text-gray-500">Name</span><b>'+r.name+'</b></div>'+
                    '<div class="flex justify-between"><span class="text-gray-500">Status</span><span class="'+(r.status==='ACTIVE'?'text-green-600':'text-red-600')+' font-medium">'+r.status+'</span></div>'+
                    '<div class="flex justify-between"><span class="text-gray-500">Land</span><span>'+r.jurisdiction+'</span></div>'+
                    '<div class="flex justify-between"><span class="text-gray-500">Adresse</span><span class="text-xs">'+r.address+'</span></div>'+
                    '<div class="flex justify-between"><span class="text-gray-500">Registriert</span><span>'+r.registered+'</span></div>'+
                '</div>';
            }).join('');
            cards.innerHTML += '<div class="bg-gray-50 rounded-xl border p-4"><h4 class="font-bold text-sm mb-2">GLEIF LEI Register ('+data.gleif_lei.count+')</h4>'+lei+'</div>';
        }

        // OpenCorporates
        if(data.opencorporates && data.opencorporates.count>0){
            var oc = data.opencorporates.companies.map(function(c){
                return '<div class="border-b border-gray-100 py-2 text-sm">'+
                    '<div class="flex justify-between"><b>'+(c.name||'')+'</b><span class="text-xs '+(c.status==='Active'?'text-green-600':'text-gray-400')+'">'+c.status+'</span></div>'+
                    '<div class="text-xs text-gray-500">'+c.jurisdiction+' · '+c.type+' · Nr: '+c.number+'</div>'+
                    (c.incorporated?'<div class="text-xs text-gray-400">Gegründet: '+c.incorporated+'</div>':'')+
                    (c.address?'<div class="text-xs text-gray-400">'+c.address+'</div>':'')+
                    (c.url?'<a href="'+c.url+'" target="_blank" class="text-xs text-brand hover:underline">Details</a>':'')+
                '</div>';
            }).join('');
            cards.innerHTML += '<div class="bg-gray-50 rounded-xl border p-4"><h4 class="font-bold text-sm mb-2">OpenCorporates ('+data.opencorporates.count+')</h4>'+oc+'</div>';
        }

        // DB Profile
        if(data.db_profile && data.db_profile.found){
            var db = data.db_profile;
            var dbHtml = '<div class="space-y-1 text-sm">';
            dbHtml += '<div class="flex justify-between"><span class="text-gray-500">Kunde</span><b>'+db.customer.name+'</b></div>';
            dbHtml += '<div class="flex justify-between"><span class="text-gray-500">Typ</span><span>'+db.customer.type+'</span></div>';
            dbHtml += '<div class="flex justify-between"><span class="text-gray-500">Status</span><span class="font-medium '+(db.customer.status==='Aktiv'?'text-green-600':'text-red-600')+'">'+db.customer.status+'</span></div>';
            dbHtml += '<div class="flex justify-between"><span class="text-gray-500">Jobs</span><span>'+db.stats.total_jobs+' ('+db.stats.completed+' erledigt, '+db.stats.cancelled+' storniert)</span></div>';
            dbHtml += '<div class="flex justify-between"><span class="text-gray-500">Stornoquote</span><span class="'+(db.stats.cancel_rate>20?'text-red-600 font-bold':'')+'">'+db.stats.cancel_rate+'%</span></div>';
            dbHtml += '<div class="flex justify-between"><span class="text-gray-500">Umsatz</span><span class="font-medium text-green-600">'+db.stats.total_revenue.toFixed(2)+' €</span></div>';
            if(db.stats.open_balance>0) dbHtml += '<div class="flex justify-between"><span class="text-gray-500">Offen</span><span class="font-bold text-red-600">'+db.stats.open_balance.toFixed(2)+' €</span></div>';
            dbHtml += '<div class="flex justify-between"><span class="text-gray-500">Erster Job</span><span>'+db.stats.first_job+'</span></div>';
            dbHtml += '<div class="flex justify-between"><span class="text-gray-500">Letzter Job</span><span>'+db.stats.last_job+'</span></div>';
            if(db.risk_flags.length>0){
                dbHtml += '<div class="mt-2 p-2 bg-red-50 rounded-lg text-xs text-red-700">';
                db.risk_flags.forEach(function(f){dbHtml += '<div>⚠ '+f+'</div>';});
                dbHtml += '</div>';
            }
            dbHtml += '</div>';
            cards.innerHTML += '<div class="bg-gray-50 rounded-xl border p-4"><h4 class="font-bold text-sm mb-2">Interne Datenbank</h4>'+dbHtml+'</div>';
        }

        // DOSSIER — Intelligence Summary
        if(data.dossier){
            var dos = data.dossier;
            var riskColor = dos.risk_level==='HIGH'?'bg-red-600':dos.risk_level==='MEDIUM'?'bg-amber-500':'bg-green-600';
            var dosHtml = '<div class="flex items-center justify-between mb-3">';
            dosHtml += '<div><div class="text-lg font-bold">'+dos.subject+'</div><div class="text-xs text-gray-400">'+dos.scan_date+' · '+dos.data_sources.length+' Quellen</div></div>';
            dosHtml += '<div class="px-3 py-1 '+riskColor+' text-white rounded-lg text-xs font-bold">RISIKO: '+dos.risk_level+'</div></div>';
            if(dos.findings.length>0){
                dosHtml += '<div class="space-y-1 mb-2">';
                dos.findings.forEach(function(f){
                    // Highlight platform names with badges
                    if(f.indexOf('Plattformen:') > -1) {
                        var parts = f.split(': ');
                        var platforms = (parts[1]||'').split(', ');
                        var colors = {TikTok:'bg-gray-900 text-white',Pinterest:'bg-red-100 text-red-700',Telegram:'bg-blue-100 text-blue-700',Instagram:'bg-pink-100 text-pink-700','Twitter/X':'bg-gray-100 text-gray-800',Reddit:'bg-orange-100 text-orange-700',YouTube:'bg-red-100 text-red-700',LinkedIn:'bg-blue-100 text-blue-800',GitHub:'bg-gray-100 text-gray-800',Medium:'bg-gray-100 text-gray-700',Twitch:'bg-purple-100 text-purple-700',SoundCloud:'bg-orange-100 text-orange-700',Keybase:'bg-blue-100 text-blue-700','HackerNews':'bg-amber-100 text-amber-700',Vimeo:'bg-cyan-100 text-cyan-700'};
                        var badges = platforms.map(function(p){
                            var c = colors[p.trim()] || 'bg-gray-100 text-gray-700';
                            return '<span class="inline-block px-2 py-0.5 rounded-md text-xs font-medium '+c+' mr-1 mb-1">'+p.trim()+'</span>';
                        }).join('');
                        dosHtml += '<div class="text-sm text-gray-700 mb-1">• '+parts[0]+':</div><div class="ml-4 flex flex-wrap">'+badges+'</div>';
                    } else {
                        dosHtml += '<div class="text-sm text-gray-700">• '+f+'</div>';
                    }
                });
                dosHtml += '</div>';
            }
            if(dos.risk_factors.length>0){
                dosHtml += '<div class="p-2 bg-red-50 rounded-lg text-xs text-red-700 space-y-0.5">';
                dos.risk_factors.forEach(function(f){dosHtml += '<div>⚠ '+f+'</div>';});
                dosHtml += '</div>';
            }
            dosHtml += '<div class="text-xs text-gray-400 mt-2">Quellen: '+dos.data_sources.join(', ')+'</div>';
            // Full-width dossier card at top
            cards.insertAdjacentHTML('afterbegin', '<div class="col-span-2 bg-white rounded-xl border-2 border-brand p-4 mb-2"><h4 class="font-bold text-sm mb-2 text-brand">INTELLIGENCE DOSSIER</h4>'+dosHtml+'</div>');
        }

        // WEB INTELLIGENCE — Actual search results inline
        if(data.web_intel){
            var wi = data.web_intel;
            var catLabels = {person:'Person',email:'Email',phone:'Telefon',social:'Social Media',business:'Firmen',legal:'Recht & Compliance',property:'Immobilien & Airbnb',reviews:'Bewertungen'};
            var wiHtml = '';
            for(var cat in wi){
                var c = wi[cat];
                wiHtml += '<div class="mb-4"><h5 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2 flex items-center justify-between">'+(catLabels[cat]||cat)+' <span class="text-gray-300 font-normal">'+c.count+' Treffer</span></h5>';
                c.results.forEach(function(r){
                    var domain = '';
                    try { domain = new URL(r.url).hostname.replace('www.',''); } catch(e){}
                    wiHtml += '<div class="border-b border-gray-100 py-2 last:border-0">'+
                        '<a href="'+r.url+'" target="_blank" class="text-sm font-medium text-brand hover:underline block truncate">'+r.title+'</a>'+
                        '<div class="text-xs text-green-700 truncate">'+domain+'</div>'+
                        '<div class="text-xs text-gray-500 line-clamp-2 mt-0.5">'+r.snippet+'</div>'+
                    '</div>';
                });
                wiHtml += '</div>';
            }
            cards.insertAdjacentHTML('beforeend', '<div class="col-span-2 bg-white rounded-xl border-2 border-gray-200 p-5"><h4 class="font-bold text-sm mb-3 flex items-center gap-2">WEB INTELLIGENCE <span class="text-xs font-normal text-gray-400">'+Object.keys(wi).length+' Kategorien durchsucht</span></h4>'+wiHtml+'</div>');
        }

        // Extended Search Links (deep investigation)
        if(data.search_links){
            var sl = data.search_links;
            var labels = {classifieds:'Kleinanzeigen',obituary:'Nachruf/Tod',crypto:'Bitcoin/Krypto',court:'Gericht/Klage',police:'Polizei',images:'Bilder',news:'News',videos:'Videos',reviews:'Bewertungen',documents:'Dokumente (PDF)',social_all:'Alle Social Media',phone_trace:'Telefon überall',phone_ads:'Telefon in Anzeigen',email_trace:'Email überall',email_registrations:'Email auf Plattformen'};
            var slHtml = '<div class="grid grid-cols-3 sm:grid-cols-5 gap-1.5">';
            for(var key in sl){
                slHtml += '<a href="'+sl[key]+'" target="_blank" class="px-2 py-1.5 border border-gray-200 rounded-lg text-xs text-center text-gray-600 hover:bg-gray-50 truncate">'+(labels[key]||key)+'</a>';
            }
            slHtml += '</div>';
            cards.innerHTML += '<div class="col-span-2 bg-gray-50 rounded-xl border p-4"><h4 class="font-bold text-sm mb-2">Deep Investigation Links</h4>'+slHtml+'</div>';
        }

        if(cards.innerHTML === '') cards.innerHTML = '<div class="col-span-2 text-center text-gray-400 py-4">Keine Daten gefunden</div>';
        document.getElementById('deepResults').classList.remove('hidden');
    }).catch(e=>{
        document.getElementById('deepLoading').classList.add('hidden');
        btn.textContent = 'Deep Scan starten'; btn.disabled = false;
        alert('Fehler: '+e.message);
    });
}
// Auto-start Deep Scan when scan results are present
if (document.getElementById('deepScanBtn')) {
    setTimeout(function(){ runDeepScan(); }, 500);
}
JS;
include __DIR__ . '/../includes/footer.php';
?>
