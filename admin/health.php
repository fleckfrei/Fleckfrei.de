<?php
/**
 * Admin Health Check — live status of all subsystems + error log last 24h
 */
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'System Health'; $page = 'health';

// ============================================================
// Check functions
// ============================================================
function checkDb(): array {
    global $db;
    try {
        $v = $db->query("SELECT VERSION()")->fetchColumn();
        $c = (int) $db->query("SELECT COUNT(*) FROM customer")->fetchColumn();
        return ['ok' => true, 'version' => $v, 'customers' => $c];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function checkDbLocal(): array {
    global $dbLocal;
    try {
        $v = $dbLocal->query("SELECT VERSION()")->fetchColumn();
        $m = (int) $dbLocal->query("SELECT COUNT(*) FROM messages")->fetchColumn();
        return ['ok' => true, 'version' => $v, 'messages' => $m];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function checkCurl(string $url, int $timeoutMs = 3000): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY => true,
        CURLOPT_TIMEOUT_MS => $timeoutMs,
        CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'FleckfreiHealth/1.0',
    ]);
    $t0 = microtime(true);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ms = round((microtime(true) - $t0) * 1000);
    $err = curl_error($ch);
    curl_close($ch);
    return ['ok' => $code >= 200 && $code < 400, 'code' => $code, 'ms' => $ms, 'error' => $err];
}

function checkGroq(): array {
    if (!defined('GROQ_API_KEY') || !GROQ_API_KEY) return ['ok' => false, 'error' => 'key missing'];
    return checkCurl('https://api.groq.com/openai/v1/models', 5000) + ['key_set' => true];
}

function tailErrorLog(string $path, int $lines = 50): array {
    if (!is_readable($path) || !is_file($path)) return [];
    $allLines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$allLines) return [];
    return array_values(array_slice($allLines, -$lines));
}

// ============================================================
// Run checks
// ============================================================
$checks = [
    'Main DB (u860899303_la_renting)' => checkDb(),
    'Local DB (i10205616_zlzy1)'      => checkDbLocal(),
    'Groq LLM API'                    => checkGroq(),
    'Stripe API'                      => checkCurl('https://api.stripe.com/v1/health', 3000),
    'Nominatim (Geocoding)'           => checkCurl('https://nominatim.openstreetmap.org/', 3000),
    'Telegram Bot API'                => checkCurl('https://api.telegram.org', 3000),
];

// Feature flags status
$features = [
    'STRIPE'    => defined('FEATURE_STRIPE') && FEATURE_STRIPE,
    'PAYPAL'    => defined('FEATURE_PAYPAL') && FEATURE_PAYPAL,
    'WHATSAPP'  => defined('FEATURE_WHATSAPP') && FEATURE_WHATSAPP,
    'SMOOBU'    => defined('FEATURE_SMOOBU') && FEATURE_SMOOBU,
    'GROQ'      => defined('GROQ_API_KEY') && !empty(GROQ_API_KEY),
    'NUKI'      => defined('NUKI_CLIENT_ID') && !empty(NUKI_CLIENT_ID),
    'STRIPE_WH' => defined('STRIPE_WEBHOOK_SECRET') && !empty(STRIPE_WEBHOOK_SECRET),
];

// DB counts (quick health metrics)
$stats = [];
try {
    $stats['customers_active']   = (int) val("SELECT COUNT(*) FROM customer WHERE status=1");
    $stats['employees_active']   = (int) val("SELECT COUNT(*) FROM employee WHERE status=1");
    $stats['jobs_today']         = (int) val("SELECT COUNT(*) FROM jobs WHERE j_date=CURDATE() AND status=1");
    $stats['jobs_running']       = (int) val("SELECT COUNT(*) FROM jobs WHERE job_status='RUNNING'");
    $stats['unpaid_invoices']    = (int) val("SELECT COUNT(*) FROM invoices WHERE invoice_paid='no' AND remaining_price>0");
    $stats['unpaid_eur']         = (float) val("SELECT COALESCE(SUM(remaining_price),0) FROM invoices WHERE invoice_paid='no' AND remaining_price>0");
    $stats['services_total']     = (int) val("SELECT COUNT(*) FROM services WHERE status=1");
    $stats['checklists']         = (int) val("SELECT COUNT(*) FROM service_checklists WHERE is_active=1");
    $stats['osint_scans']        = (int) val("SELECT COUNT(*) FROM osint_scans");
} catch (Exception $e) {}

// Disk usage
$diskFree = disk_free_space('/');
$diskTotal = disk_total_space('/');
$diskPct = $diskTotal ? round(($diskTotal - $diskFree) / $diskTotal * 100) : 0;

// Error log last 50 lines
$errorLogPath = __DIR__ . '/../php_errors.log';
$errorLog = tailErrorLog($errorLogPath, 30);
$fatalLines = array_filter($errorLog, fn($l) => stripos($l, 'fatal') !== false);
$warningLines = array_filter($errorLog, fn($l) => stripos($l, 'warning') !== false);

// Backup listing
$backupDir = '/home/u860899303/backups/fleckfrei.de';
$backups = is_dir($backupDir) ? glob($backupDir . '/*') : [];

include __DIR__ . '/../includes/layout.php';
?>

<div class="mb-6">
  <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-2">🩺 System Health</h1>
  <p class="text-sm text-gray-500 mt-1">Live-Status aller Subsysteme, Feature-Flags, Fehler-Log und Backups.</p>
</div>

<!-- External checks -->
<div class="bg-white rounded-xl border mb-6">
  <div class="px-5 py-3 border-b bg-gray-50"><h2 class="font-bold text-gray-900">Subsystem-Checks</h2></div>
  <div class="divide-y">
    <?php foreach ($checks as $name => $res): ?>
    <div class="px-5 py-3 flex items-center justify-between gap-3">
      <div class="flex items-center gap-3">
        <?php if ($res['ok']): ?>
          <span class="w-3 h-3 rounded-full bg-green-500"></span>
        <?php else: ?>
          <span class="w-3 h-3 rounded-full bg-red-500 animate-pulse"></span>
        <?php endif; ?>
        <span class="font-semibold text-gray-900 text-sm"><?= e($name) ?></span>
      </div>
      <div class="text-xs text-gray-500 font-mono">
        <?php if (isset($res['version'])): ?>v<?= e(substr($res['version'], 0, 10)) ?><?php endif; ?>
        <?php if (isset($res['customers'])): ?> · <?= $res['customers'] ?> kunden<?php endif; ?>
        <?php if (isset($res['messages'])): ?> · <?= $res['messages'] ?> msgs<?php endif; ?>
        <?php if (isset($res['code'])): ?>HTTP <?= $res['code'] ?> · <?= $res['ms'] ?>ms<?php endif; ?>
        <?php if (isset($res['error']) && $res['error']): ?><span class="text-red-600"><?= e(substr($res['error'], 0, 60)) ?></span><?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Feature flags -->
<div class="bg-white rounded-xl border mb-6">
  <div class="px-5 py-3 border-b bg-gray-50"><h2 class="font-bold text-gray-900">Feature Flags</h2></div>
  <div class="p-4 flex flex-wrap gap-2">
    <?php foreach ($features as $name => $enabled): ?>
    <span class="px-3 py-1.5 rounded-lg text-xs font-bold <?= $enabled ? 'bg-green-100 text-green-700 border border-green-200' : 'bg-gray-100 text-gray-500 border border-gray-200' ?>">
      <?= $enabled ? '✓' : '✗' ?> <?= e($name) ?>
    </span>
    <?php endforeach; ?>
  </div>
</div>

<!-- DB Stats -->
<div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
  <div class="bg-white rounded-xl border p-3"><div class="text-2xl font-extrabold text-gray-900"><?= (int)($stats['customers_active'] ?? 0) ?></div><div class="text-[10px] uppercase text-gray-500 mt-1">Kunden aktiv</div></div>
  <div class="bg-white rounded-xl border p-3"><div class="text-2xl font-extrabold text-gray-900"><?= (int)($stats['employees_active'] ?? 0) ?></div><div class="text-[10px] uppercase text-gray-500 mt-1">Partner aktiv</div></div>
  <div class="bg-white rounded-xl border p-3"><div class="text-2xl font-extrabold text-brand"><?= (int)($stats['jobs_today'] ?? 0) ?></div><div class="text-[10px] uppercase text-gray-500 mt-1">Jobs heute</div></div>
  <div class="bg-white rounded-xl border p-3 <?= ($stats['jobs_running'] ?? 0) > 0 ? 'bg-amber-50 border-amber-200' : '' ?>"><div class="text-2xl font-extrabold <?= ($stats['jobs_running'] ?? 0) > 0 ? 'text-amber-600' : 'text-gray-900' ?>"><?= (int)($stats['jobs_running'] ?? 0) ?></div><div class="text-[10px] uppercase text-gray-500 mt-1">Running</div></div>
  <div class="bg-white rounded-xl border p-3"><div class="text-2xl font-extrabold <?= ($stats['unpaid_invoices'] ?? 0) > 0 ? 'text-red-600' : 'text-gray-900' ?>"><?= (int)($stats['unpaid_invoices'] ?? 0) ?></div><div class="text-[10px] uppercase text-gray-500 mt-1">Offen · <?= money($stats['unpaid_eur'] ?? 0) ?></div></div>
</div>

<!-- Disk usage -->
<div class="bg-white rounded-xl border p-4 mb-6">
  <div class="flex items-center justify-between mb-2">
    <h3 class="font-bold text-gray-900 text-sm">💾 Disk Usage</h3>
    <span class="text-xs text-gray-500 font-mono"><?= round($diskFree / 1024 / 1024 / 1024, 1) ?> GB frei / <?= round($diskTotal / 1024 / 1024 / 1024, 1) ?> GB</span>
  </div>
  <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
    <div class="h-full <?= $diskPct > 85 ? 'bg-red-500' : ($diskPct > 70 ? 'bg-amber-500' : 'bg-green-500') ?>" style="width: <?= $diskPct ?>%"></div>
  </div>
  <div class="text-[11px] text-gray-500 mt-1"><?= $diskPct ?> % belegt</div>
</div>

<!-- Error log -->
<div class="bg-white rounded-xl border mb-6">
  <div class="px-5 py-3 border-b bg-gray-50 flex items-center justify-between">
    <h2 class="font-bold text-gray-900">🔥 Fehler-Log (letzte 30 Zeilen)</h2>
    <div class="flex items-center gap-3 text-[11px]">
      <span class="px-2 py-0.5 rounded-full bg-red-100 text-red-700 font-bold"><?= count($fatalLines) ?> Fatal</span>
      <span class="px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 font-bold"><?= count($warningLines) ?> Warnings</span>
    </div>
  </div>
  <?php if (empty($errorLog)): ?>
  <div class="p-6 text-center text-sm text-gray-400">Kein Fehler-Log lesbar oder leer.</div>
  <?php else: ?>
  <div class="p-4 bg-gray-900 text-green-300 font-mono text-[10px] leading-relaxed overflow-x-auto max-h-96 overflow-y-auto">
    <?php foreach (array_reverse($errorLog) as $line):
      $isError = stripos($line, 'fatal') !== false || stripos($line, 'error') !== false;
      $isWarning = stripos($line, 'warning') !== false;
    ?>
    <div class="<?= $isError ? 'text-red-400' : ($isWarning ? 'text-amber-300' : '') ?>"><?= e($line) ?></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- Backups -->
<div class="bg-white rounded-xl border">
  <div class="px-5 py-3 border-b bg-gray-50"><h2 class="font-bold text-gray-900">💼 Backups</h2></div>
  <?php if (empty($backups)): ?>
  <div class="p-6 text-center text-sm text-gray-400">Keine Backups in <code><?= e($backupDir) ?></code></div>
  <?php else: ?>
  <div class="divide-y">
    <?php foreach ($backups as $b):
      $size = filesize($b);
      $mtime = filemtime($b);
    ?>
    <div class="px-5 py-3 flex items-center justify-between gap-3 text-sm">
      <div class="font-mono text-xs text-gray-700 truncate"><?= e(basename($b)) ?></div>
      <div class="flex items-center gap-3 text-[11px] text-gray-500 whitespace-nowrap">
        <span><?= round($size / 1024 / 1024, 1) ?> MB</span>
        <span><?= e(date('d.m.Y H:i', $mtime)) ?></span>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
