<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'OSINT Scanner'; $page = 'scanner';

// Scan function
function doScan($email, $name = '') {
    $r = ['customer'=>$name, 'email'=>$email, 'time'=>date('H:i:s'), 'checks'=>[]];
    $web = '';
    if ($email) {
        $domain = substr($email, strpos($email,'@')+1);
        $mx = @dns_get_record($domain, DNS_MX);
        $free = in_array(strtolower($domain), ['gmail.com','yahoo.com','hotmail.com','outlook.com','gmx.de','web.de','t-online.de','aol.com','icloud.com','protonmail.com','mail.de','freenet.de','live.de','live.com','googlemail.com','posteo.de','mailbox.org']);
        $r['checks']['email'] = ['domain'=>$domain, 'mx'=>$mx?count($mx):0, 'mx_host'=>!empty($mx)?$mx[0]['target']:null, 'free'=>$free];
        $web = $free ? '' : $domain;
    }
    return ['result' => $r, 'web' => $web];
}

function scanWeb($web, &$r) {
    $dns = @dns_get_record($web, DNS_A);
    $r['checks']['dns'] = ['domain'=>$web, 'ip'=>!empty($dns)?$dns[0]['ip']:null];
    $ctx = stream_context_create(['ssl'=>['capture_peer_cert'=>true,'verify_peer'=>false]]);
    $s = @stream_socket_client("ssl://$web:443", $e, $es, 5, STREAM_CLIENT_CONNECT, $ctx);
    if ($s) { $c=openssl_x509_parse(stream_context_get_params($s)['options']['ssl']['peer_certificate']); $r['checks']['ssl']=['issuer'=>$c['issuer']['O']??'?','expires'=>date('Y-m-d',$c['validTo_time_t']),'days'=>floor(($c['validTo_time_t']-time())/86400)]; fclose($s); }
    $ch=curl_init("https://$web"); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>1,CURLOPT_TIMEOUT=>8,CURLOPT_FOLLOWLOCATION=>1,CURLOPT_SSL_VERIFYPEER=>0]); $html=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    $r['checks']['http'] = $code;
    if ($html) { preg_match('/<title[^>]*>([^<]+)</',$html,$m); $r['checks']['title']=$m[1]??''; $t=[]; if(stripos($html,'wp-content')!==false)$t[]='WordPress'; if(stripos($html,'shopify')!==false)$t[]='Shopify'; if(stripos($html,'wix.com')!==false)$t[]='Wix'; if(stripos($html,'bootstrap')!==false)$t[]='Bootstrap'; if(stripos($html,'gtag')!==false||stripos($html,'google-analytics')!==false)$t[]='Analytics'; if(stripos($html,'elementor')!==false)$t[]='Elementor'; if(stripos($html,'react')!==false)$t[]='React'; $r['checks']['tech']=$t; }
}

$scan_result = null;

// Scan by customer ID
if ($_SERVER['REQUEST_METHOD']==='POST' && !empty($_POST['scan_id'])) {
    $cust = one("SELECT * FROM customer WHERE customer_id=?", [(int)$_POST['scan_id']]);
    if ($cust) {
        $scan = doScan($cust['email'], $cust['name']);
        $r = $scan['result']; $web = $scan['web'];
        if (!empty($web)) scanWeb($web, $r);
        $scan_result = $r;
    }
}

// Scan by manual URL/domain
if ($_SERVER['REQUEST_METHOD']==='POST' && !empty($_POST['scan_url'])) {
    $url = trim($_POST['scan_url']);
    $url = preg_replace('#^https?://#', '', $url);
    $url = rtrim($url, '/');
    $r = ['customer'=>$url, 'email'=>'', 'time'=>date('H:i:s'), 'checks'=>[]];
    // Check if it's an email
    if (strpos($url, '@') !== false) {
        $scan = doScan($url, $url);
        $r = $scan['result']; $web = $scan['web'];
    } else {
        $web = $url;
    }
    if (!empty($web)) scanWeb($web, $r);
    $scan_result = $r;
}

$customers = all("SELECT customer_id, name, email FROM customer WHERE status=1 AND email IS NOT NULL AND email!='' ORDER BY name");

include __DIR__ . '/../includes/layout.php';
?>

<!-- Manual scan -->
<div class="bg-white rounded-xl border mb-4">
  <form method="post" class="p-4 flex gap-3 items-end">
    <div class="flex-1">
      <label class="block text-xs font-medium text-gray-500 mb-1">Domain oder E-Mail scannen</label>
      <input type="text" name="scan_url" placeholder="example.com oder name@firma.de" class="w-full px-3 py-2 border rounded-lg text-sm" required/>
    </div>
    <button class="px-4 py-2 bg-brand text-white text-sm font-medium rounded-lg whitespace-nowrap">Scan</button>
  </form>
</div>

<?php if ($scan_result): $s=$scan_result; ?>
<div class="bg-white rounded-xl border border-brand/30 mb-6">
  <div class="bg-brand text-white p-4 rounded-t-xl"><h3 class="font-semibold">Scan: <?= e($s['customer']) ?></h3></div>
  <div class="p-5 grid grid-cols-2 md:grid-cols-4 gap-4">
    <?php if ($c=$s['checks']['email']??null): ?>
    <div class="bg-gray-50 rounded-lg p-3"><h4 class="text-xs font-semibold text-gray-400 mb-2">EMAIL</h4><p class="font-medium"><?= e($s['email']) ?></p><p class="text-sm">MX: <?= $c['mx'] ?> <?= $c['mx_host']?"({$c['mx_host']})":'' ?></p><p class="text-sm"><?= $c['free']?'Free Provider':'Business' ?></p></div>
    <?php endif; ?>
    <?php if ($c=$s['checks']['dns']??null): ?>
    <div class="bg-gray-50 rounded-lg p-3"><h4 class="text-xs font-semibold text-gray-400 mb-2">DNS</h4><p class="font-medium"><?= e($c['domain']) ?></p><p class="text-sm">IP: <?= e($c['ip']??'none') ?></p></div>
    <?php endif; ?>
    <?php if ($c=$s['checks']['ssl']??null): ?>
    <div class="bg-gray-50 rounded-lg p-3"><h4 class="text-xs font-semibold text-gray-400 mb-2">SSL</h4><p class="text-sm"><?= e($c['issuer']) ?></p><p class="text-sm">Expires: <?= $c['expires'] ?> (<?= $c['days'] ?>d)</p></div>
    <?php endif; ?>
    <?php if (isset($s['checks']['http'])): ?>
    <div class="bg-gray-50 rounded-lg p-3"><h4 class="text-xs font-semibold text-gray-400 mb-2">HTTP</h4><p class="text-2xl font-bold <?= $s['checks']['http']>=200&&$s['checks']['http']<400?'text-green-600':'text-red-600' ?>"><?= $s['checks']['http'] ?></p>
    <?php if (!empty($s['checks']['title'])): ?><p class="text-xs text-gray-500 truncate"><?= e($s['checks']['title']) ?></p><?php endif; ?>
    <?php if (!empty($s['checks']['tech'])): ?><div class="mt-1"><?php foreach($s['checks']['tech'] as $t): ?><span class="text-xs bg-gray-200 px-1 rounded mr-1"><?= $t ?></span><?php endforeach; ?></div><?php endif; ?>
    </div>
    <?php elseif (!empty($s['checks']['email']['free'])): ?>
    <div class="bg-gray-50 rounded-lg p-3"><h4 class="text-xs font-semibold text-gray-400 mb-2">WEBSITE</h4><p class="text-sm text-gray-400">Kein Business-Domain</p><p class="text-xs text-gray-300">Free E-Mail Provider</p></div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="bg-white rounded-xl border">
  <div class="p-5 border-b flex items-center justify-between">
    <h3 class="font-semibold">Kunden (<?= count($customers) ?>)</h3>
    <input type="text" placeholder="Suchen..." class="px-3 py-2 border rounded-lg text-sm w-64" oninput="filterRows(this.value)"/>
  </div>
  <div class="overflow-x-auto">
    <table class="w-full text-sm" id="tbl">
      <thead class="bg-gray-50"><tr><th class="px-4 py-3 text-left">#</th><th class="px-4 py-3 text-left">Name</th><th class="px-4 py-3 text-left">Email</th><th class="px-4 py-3 text-left">Domain</th><th class="px-4 py-3">Scan</th></tr></thead>
      <tbody class="divide-y">
      <?php foreach ($customers as $i=>$c): $d=strpos($c['email'],'@')!==false?substr($c['email'],strpos($c['email'],'@')+1):''; ?>
      <tr class="hover:bg-gray-50"><td class="px-4 py-3 text-gray-400"><?= $i+1 ?></td><td class="px-4 py-3"><?= e($c['name']) ?></td><td class="px-4 py-3 text-gray-600"><?= e($c['email']) ?></td><td class="px-4 py-3 text-gray-400"><?= e($d) ?></td>
      <td class="px-4 py-3 text-center"><form method="post" class="inline"><input type="hidden" name="scan_id" value="<?= $c['customer_id'] ?>"><button class="px-3 py-1 bg-brand text-white text-xs rounded-lg">Scan</button></form></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php
$script = "function filterRows(q){q=q.toLowerCase();document.querySelectorAll('#tbl tbody tr').forEach(r=>{r.style.display=r.textContent.toLowerCase().includes(q)?'':'none'})}";
include __DIR__ . '/../includes/footer.php';
?>
