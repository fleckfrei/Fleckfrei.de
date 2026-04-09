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
    $like = '%'.($scanName ?: $scanEmail).'%';
    $dbCustomers = all("SELECT c.*, COUNT(j.j_id) as jobs, MAX(j.j_date) as last_job FROM customer c LEFT JOIN jobs j ON j.customer_id_fk=c.customer_id AND j.status=1 WHERE c.email=? OR c.name LIKE ? OR c.phone LIKE ? GROUP BY c.customer_id LIMIT 10", [$scanEmail, $like, '%'.$scanPhone.'%']);
    $dbEmployees = all("SELECT * FROM employee WHERE email=? OR phone LIKE ? LIMIT 5", [$scanEmail, '%'.$scanPhone.'%']);
    if (!empty($dbCustomers)) {
        $cid = $dbCustomers[0]['customer_id'];
        $dbStats = one("SELECT COUNT(j.j_id) as total_jobs, SUM(CASE WHEN j.job_status='COMPLETED' THEN 1 ELSE 0 END) as done, SUM(CASE WHEN j.job_status='CANCELLED' THEN 1 ELSE 0 END) as cancelled, COALESCE(SUM(DISTINCT i.total_price),0) as inv_total, COALESCE(SUM(DISTINCT i.remaining_price),0) as inv_open FROM customer c LEFT JOIN jobs j ON j.customer_id_fk=c.customer_id AND j.status=1 LEFT JOIN invoices i ON i.customer_id_fk=c.customer_id WHERE c.customer_id=?", [$cid]);
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
            ['SMS','sms:'.$ph],['Anrufen','tel:'.$ph],['Email','mailto:'.$e],
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
  <div class="flex items-center gap-3"><div class="animate-spin w-5 h-5 border-2 border-brand border-t-transparent rounded-full"></div><span class="text-sm font-medium text-gray-700" id="deepScanStatus">Deep Scan läuft... 20+ Quellen</span></div>
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
        prog.classList.add('hidden');
        if (data.success) renderDeepResults(data.data, res);
        else res.innerHTML = '<div class="p-3 bg-red-50 text-red-700 rounded-lg">Fehler: '+(data.error||'Unbekannt')+'</div>';
        res.classList.remove('hidden');
    } catch(e) {
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

    // === TOP BAR: Risiko + Verifikation + Scan-Info ===
    html += `<div class="bg-white rounded-xl border mb-3 p-4">
        <div class="flex items-center gap-3 flex-wrap">
            <span class="px-3 py-1.5 rounded-lg text-sm font-bold bg-${rc}-100 text-${rc}-800">Risiko: ${dos.risk_level||'?'}</span>
            ${vf.overall_confidence !== undefined ? `<span class="px-3 py-1.5 rounded-lg text-sm font-bold bg-blue-100 text-blue-800">${vf.overall_confidence}% verifiziert</span>` : ''}
            <span class="text-xs text-gray-400 ml-auto">${d._meta?.scan_time_seconds||'?'}s · ${d._meta?.modules_run||'?'} Module · ${dos.data_sources?.length||0} Quellen</span>
        </div>
    </div>`;

    // === TABS ===
    const tabs = ['Ergebnis','Funde','Suche','Details'];
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
    if (di.impressum_mentions) fundeItems.push(...di.impressum_mentions.results.slice(0,3).map(r=>`<div class="p-2 bg-amber-50 rounded"><div class="text-xs font-semibold text-amber-700">Impressum</div><a href="${r.url}" target="_blank" class="text-sm text-brand hover:underline">${r.title}</a></div>`));
    if (di.insolvency) fundeItems.push(...di.insolvency.results.slice(0,3).map(r=>`<div class="p-2 bg-red-50 rounded border border-red-200"><div class="text-xs font-semibold text-red-700">INSOLVENZ</div><a href="${r.url}" target="_blank" class="text-sm text-red-700">${r.title}</a></div>`));
    if (di.paste_leaks) fundeItems.push(`<div class="p-2 bg-red-50 rounded"><div class="text-xs font-semibold text-red-700">Paste-Leaks (${di.paste_leaks.count})</div>${di.paste_leaks.results.slice(0,2).map(r=>`<a href="${r.url}" target="_blank" class="text-xs text-red-600 block">${r.title}</a>`).join('')}</div>`);
    if (di.leaked_docs) fundeItems.push(`<div class="p-2 bg-orange-50 rounded"><div class="text-xs font-semibold text-orange-700">Dokumente (${di.leaked_docs.count})</div>${di.leaked_docs.results.slice(0,2).map(r=>`<a href="${r.url}" target="_blank" class="text-xs text-brand block">${r.title}</a>`).join('')}</div>`);
    if (d.handelsregister) fundeItems.push(...d.handelsregister.companies.slice(0,3).map(c=>`<div class="p-2 bg-gray-50 rounded"><div class="text-xs font-semibold text-gray-500">Handelsregister</div><div class="text-sm font-medium">${c.name}</div><div class="text-xs text-gray-400">${c.register_type} ${c.register_number} · ${c.register_court}</div></div>`));
    if (d.plate_search) {
        const pl = d.plate_search;
        const allPlateHits = Object.values(pl.results).flat().slice(0,5);
        fundeItems.push(`<div class="p-2 bg-gray-800 text-white rounded"><div class="flex items-center gap-2 mb-1"><span class="px-2 py-0.5 bg-blue-600 rounded text-xs font-bold">D</span><span class="font-bold font-mono text-lg">${pl.plate}</span><span class="text-xs text-gray-400">${pl.region}</span></div>${allPlateHits.length?allPlateHits.map(r=>`<a href="${r.url}" target="_blank" class="text-xs text-blue-300 block hover:underline">${r.title}</a>`).join(''):'<div class="text-xs text-gray-400">Keine Treffer</div>'}${pl.links?'<div class="flex gap-2 mt-1">'+Object.entries(pl.links).map(([k,v])=>`<a href="${v}" target="_blank" class="text-[10px] text-gray-400 hover:text-white">${k}</a>`).join('')+'</div>':''}</div>`);
    }
    if (d.registration_search) {
        for (const [nr, data] of Object.entries(d.registration_search)) {
            const nrType = data._type || 'Nummer';
            const allHits = [...(data.google||[]),...(data.register||[]),...(data.legal||[])].slice(0,4);
            if (allHits.length) fundeItems.push(`<div class="p-2 bg-indigo-50 rounded"><div class="text-xs font-semibold text-indigo-700">${nrType}: ${nr}</div>${allHits.map(r=>`<a href="${r.url}" target="_blank" class="text-xs text-brand block hover:underline">${r.title}</a>`).join('')}</div>`);
        }
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
    html += fundeItems.length ? `<div class="grid grid-cols-1 md:grid-cols-2 gap-2">${fundeItems.join('')}</div>` : '<div class="text-sm text-gray-400">Keine besonderen Funde</div>';
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

    // --- TAB 3: Details (Verifikation, Netzwerk, Learning) ---
    html += '<div class="ds-panel hidden" data-panel="3">';
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
  <?php if ($scanPhone): $ph = preg_replace('/[^+0-9]/','',$scanPhone); $isDE = str_starts_with($ph,'+49')||str_starts_with($ph,'049')||str_starts_with($ph,'0'); ?>
  <div class="bg-white rounded-xl border overflow-hidden">
    <div class="px-4 py-2.5 border-b"><h4 class="font-semibold text-sm text-gray-900">Telefon-Analyse</h4></div>
    <div class="p-4 space-y-2 text-sm">
      <div class="flex justify-between"><span class="text-gray-500">Nummer</span><span class="font-mono font-medium"><?= e($scanPhone) ?></span></div>
      <div class="flex justify-between"><span class="text-gray-500">Format</span><span><?= $isDE?'Deutschland (+49)':'International' ?></span></div>
      <?php $phLocal = str_replace('+49','0',$ph); ?>
      <div class="grid grid-cols-4 gap-2 mt-3">
        <a href="https://wa.me/<?= ltrim($ph,'+') ?>" target="_blank" class="px-3 py-2 border border-gray-200 rounded-lg text-xs font-medium text-center text-gray-700 hover:bg-gray-50">WhatsApp</a>
        <a href="https://t.me/+<?= ltrim($ph,'+') ?>" target="_blank" class="px-3 py-2 border border-gray-200 rounded-lg text-xs font-medium text-center text-gray-700 hover:bg-gray-50">Telegram</a>
        <a href="sms:<?= $ph ?>" class="px-3 py-2 border border-gray-200 rounded-lg text-xs font-medium text-center text-gray-700 hover:bg-gray-50">SMS</a>
        <a href="tel:<?= $ph ?>" class="px-3 py-2 bg-brand text-white rounded-lg text-xs font-medium text-center">Anrufen</a>
      </div>
      <div class="grid grid-cols-3 sm:grid-cols-6 gap-2 mt-2">
        <?php if ($isDE): ?>
        <a href="https://www.dasoertliche.de/Themen/R%C3%BCckw%C3%A4rtssuche/<?= urlencode($phLocal) ?>" target="_blank" class="px-2 py-1.5 border border-gray-200 rounded-lg text-xs text-center text-gray-600 hover:bg-gray-50">Das Örtliche</a>
        <a href="https://www.11880.com/suche/<?= urlencode($phLocal) ?>/bundesweit" target="_blank" class="px-2 py-1.5 border border-gray-200 rounded-lg text-xs text-center text-gray-600 hover:bg-gray-50">11880</a>
        <?php endif; ?>
        <a href="https://www.tellows.de/num/<?= urlencode($phLocal) ?>" target="_blank" class="px-2 py-1.5 border border-gray-200 rounded-lg text-xs text-center text-gray-600 hover:bg-gray-50">Tellows</a>
        <a href="https://www.google.com/search?q=<?= urlencode($ph) ?>" target="_blank" class="px-2 py-1.5 border border-gray-200 rounded-lg text-xs text-center text-gray-600 hover:bg-gray-50">Google Nr.</a>
        <a href="https://www.google.com/search?q=<?= urlencode($phLocal) ?>" target="_blank" class="px-2 py-1.5 border border-gray-200 rounded-lg text-xs text-center text-gray-600 hover:bg-gray-50">Google Lokal</a>
        <a href="https://www.google.com/search?q=<?= urlencode($ph) ?>+OR+<?= urlencode($phLocal) ?>+Bewertung+OR+Spam" target="_blank" class="px-2 py-1.5 border border-gray-200 rounded-lg text-xs text-center text-gray-600 hover:bg-gray-50">Bewertung</a>
      </div>
      <!-- WhatsApp OSINT Check -->
      <div class="mt-3 border-t pt-3">
        <button onclick="checkWhatsApp('<?= ltrim($ph,'+') ?>')" class="px-3 py-1.5 border border-gray-200 rounded-lg text-xs font-medium text-gray-700 hover:bg-gray-50" id="waCheckBtn">WhatsApp Profil prüfen</button>
        <div id="waResult" class="hidden mt-2 p-3 bg-gray-50 rounded-lg text-xs"></div>
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

<!-- Recherche Links -->
<?php if ($scanName): ?>
<div class="bg-white rounded-xl border mb-4 overflow-hidden">
  <div class="px-5 py-3 border-b flex items-center justify-between">
    <h3 class="font-semibold text-gray-900">Recherche-Links</h3>
    <button onclick="saveScan()" class="px-4 py-1.5 bg-brand text-white rounded-lg text-xs font-medium" id="saveScanBtn">Ergebnis speichern</button>
  </div>
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
</div>
<?php endif; ?>

<!-- Deep Scan (auto-starts on scan) -->
<div class="bg-white rounded-xl border mb-4 overflow-hidden">
  <div class="px-5 py-3 border-b flex items-center justify-between">
    <h3 class="font-semibold text-gray-900">Deep Scan</h3>
    <button onclick="runDeepScan()" id="deepScanBtn" class="px-4 py-1.5 bg-brand text-white rounded-lg text-xs font-medium">Erneut scannen</button>
  </div>
  <div id="deepResults" class="p-4 hidden">
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4" id="deepCards"></div>
  </div>
  <div id="deepLoading" class="p-8 text-center text-gray-400 hidden">
    <div class="inline-block w-6 h-6 border-2 border-brand border-t-transparent rounded-full animate-spin mb-2"></div>
    <div>Scanne externe Quellen...</div>
  </div>
</div>

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
    <div style="border-bottom:3px solid #2E7D6B;padding-bottom:15px;margin-bottom:20px;">
      <h1 style="margin:0;color:#2E7D6B;">OSINT Personenbericht</h1>
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
