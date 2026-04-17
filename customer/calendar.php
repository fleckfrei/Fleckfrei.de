<?php
require_once __DIR__ . '/../includes/auth.php';
requireCustomer();
$title = 'Kalender'; $page = 'calendar';
$cid = me()['id'];
$customer = one("SELECT * FROM customer WHERE customer_id=?", [$cid]);

// ============================================================
// POST: approve/reject job suggestion + manual event create
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { header('Location: /customer/calendar.php'); exit; }
    $action = $_POST['action'] ?? '';

    if ($action === 'approve_suggestion') {
        $sgId = (int)($_POST['sg_id'] ?? 0);
        $sg = one("SELECT * FROM job_suggestions WHERE sg_id=? AND customer_id_fk=? AND status='pending'", [$sgId, $cid]);
        if ($sg) {
            // Find a service for this customer (use first available)
            $svc = one("SELECT s_id, total_price FROM services WHERE customer_id_fk=? AND status=1 LIMIT 1", [$cid]);
            if (!$svc) $svc = one("SELECT s_id, total_price FROM services WHERE status=1 LIMIT 1");
            $sId = (int)($svc['s_id'] ?? 0);

            try {
                // Normalize platform name from iCal source for jobs.platform field
                $platform = match(true) {
                    stripos($sg['source_platform'], 'airbnb') !== false  => 'airbnb',
                    stripos($sg['source_platform'], 'booking') !== false => 'booking',
                    stripos($sg['source_platform'], 'smoobu') !== false  => 'smoobu',
                    stripos($sg['source_platform'], 'vrbo') !== false    => 'vrbo',
                    default => $sg['source_platform'] ?: 'ical',
                };
                // All NOT NULL fields on jobs: customer_id_fk, j_date, j_time, j_hours, job_for,
                // s_id_fk, address, optional_products, emp_message, no_people, code_door, status
                q("INSERT INTO jobs (customer_id_fk, j_date, j_time, j_hours, job_for, s_id_fk, address, optional_products, emp_message, no_people, code_door, job_status, job_note, platform, status, total_hours, created_at)
                   VALUES (?, ?, ?, 3, 'Reinigung', ?, ?, '', '', 1, '', 'CONFIRMED', ?, ?, 1, 3, NOW())",
                  [$cid, $sg['suggested_date'], $sg['suggested_time'], $sId, $sg['property_label'] ?: '', "Auto-generiert nach Check-out (via {$sg['source_platform']})", $platform]);
                $newJobId = (int) lastInsertId();
                q("UPDATE job_suggestions SET status='approved', approved_at=NOW(), resulting_job_id=? WHERE sg_id=?", [$newJobId, $sgId]);
                audit('approve', 'job_suggestion', $sgId, "Reinigung nach Check-out genehmigt (Job #$newJobId)");
                telegramNotify("✅ Kunde #$cid hat Auto-Job #$newJobId genehmigt für {$sg['suggested_date']}");
            } catch (Exception $e) {
                error_log("approve_suggestion failed: " . $e->getMessage());
                header('Location: /customer/calendar.php?error=approve_failed&msg=' . urlencode(substr($e->getMessage(), 0, 100))); exit;
            }
        }
        header('Location: /customer/calendar.php?saved=approved'); exit;
    }

    if ($action === 'reject_suggestion') {
        $sgId = (int)($_POST['sg_id'] ?? 0);
        q("UPDATE job_suggestions SET status='rejected' WHERE sg_id=? AND customer_id_fk=?", [$sgId, $cid]);
        header('Location: /customer/calendar.php?saved=rejected'); exit;
    }

    if ($action === 'add_manual') {
        $title2 = trim($_POST['title'] ?? '');
        $date = $_POST['event_date'] ?? '';
        $time = $_POST['event_time'] ?? null;
        $type = in_array($_POST['type'] ?? '', ['blocked','reminder','custom'], true) ? $_POST['type'] : 'custom';
        if ($title2 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            q("INSERT INTO manual_events (customer_id_fk, title, event_date, event_time, type, notes) VALUES (?,?,?,?,?,?)",
              [$cid, $title2, $date, $time ?: null, $type, $_POST['notes'] ?? '']);
            audit('create', 'manual_events', (int)lastInsertId(), "Manual: $title2");
        }
        header('Location: /customer/calendar.php?saved=manual'); exit;
    }
}

$savedMsg = $_GET['saved'] ?? '';

// Month param
$month = $_GET['m'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
$view = ($_GET['view'] ?? 'month');
if (!in_array($view, ['month','week'], true)) $view = 'month';

// Week view needs its own anchor date
$weekAnchor = $_GET['w'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekAnchor)) $weekAnchor = date('Y-m-d');
$weekTs = strtotime($weekAnchor);
$weekDow = (int) date('N', $weekTs);
$weekStartTs = strtotime("-" . ($weekDow - 1) . " days", $weekTs);
$weekEndTs = strtotime("+6 days", $weekStartTs);
$weekStart = date('Y-m-d', $weekStartTs);
$weekEnd = date('Y-m-d', $weekEndTs);
$weekLabel = date('d.m.', $weekStartTs) . ' – ' . date('d.m.Y', $weekEndTs);
$prevWeek = date('Y-m-d', strtotime('-7 days', $weekStartTs));
$nextWeek = date('Y-m-d', strtotime('+7 days', $weekStartTs));

$firstDay = strtotime("$month-01");
$daysInMonth = (int) date('t', $firstDay);
$firstDow = (int) date('N', $firstDay); // 1 (Mon) ... 7 (Sun)
$gridStart = strtotime("-" . ($firstDow - 1) . " days", $firstDay);

// Fetch 6 weeks of jobs (covers all month-grid cells)
$gridEnd = strtotime("+41 days", $gridStart);
$jobs = all("
    SELECT j.j_id, j.j_date, j.j_time, j.job_status, j.total_hours, j.j_hours, j.emp_id_fk, j.s_id_fk,
           j.no_people, j.no_children, j.no_pets, j.address, j.code_door, j.emp_message, j.job_note, j.platform,
           s.title AS stitle, e.display_name AS edisplay, e.profile_pic AS eavatar
    FROM jobs j
    LEFT JOIN services s ON j.s_id_fk = s.s_id
    LEFT JOIN employee e ON j.emp_id_fk = e.emp_id
    WHERE j.customer_id_fk = ?
      AND j.status = 1
      AND j.j_date >= ?
      AND j.j_date <= ?
    ORDER BY j.j_date ASC, j.j_time ASC
", [$cid, date('Y-m-d', $gridStart), date('Y-m-d', $gridEnd)]);

// External events from iCal feeds (Smoobu, Airbnb, Booking.com)
$externalEvents = [];
try {
    $extRows = all("
        SELECT ev.*, f.label AS feed_label, f.platform AS feed_platform
        FROM external_events ev
        LEFT JOIN ical_feeds f ON ev.ical_feed_id = f.id
        WHERE ev.customer_id_fk = ?
          AND ev.start_date <= ?
          AND ev.end_date >= ?
        ORDER BY ev.start_date
    ", [$cid, date('Y-m-d', $gridEnd), date('Y-m-d', $gridStart)]);

    // Auto-trigger sync if last sync is older than 30 minutes
    $needsSync = (int) val("SELECT COUNT(*) FROM ical_feeds WHERE customer_id_fk=? AND active=1 AND (last_sync IS NULL OR last_sync < NOW() - INTERVAL 30 MINUTE)", [$cid]);
    if ($needsSync > 0 && empty($_GET['no_sync'])) {
        // Async trigger via background curl (non-blocking)
        $ctx = stream_context_create(['http' => ['timeout' => 1]]);
        @file_get_contents("https://app.fleckfrei.de/api/ical-sync.php?cid=$cid&cron=flk_scrape_2026", false, $ctx);
    }

    foreach ($extRows as $ev) {
        // Expand multi-day events into per-day entries
        $cur = strtotime($ev['start_date']);
        $endTs = strtotime($ev['end_date']);
        while ($cur <= $endTs) {
            $d = date('Y-m-d', $cur);
            $externalEvents[$d][] = $ev;
            $cur = strtotime('+1 day', $cur);
        }
    }
} catch (Exception $e) { /* table missing */ }

// Index jobs by date
$jobsByDate = [];
foreach ($jobs as $j) {
    $jobsByDate[$j['j_date']][] = $j;
}

// Customer vacations overlapping this month → mark each covered day
$myVacs = all("SELECT cv_id, from_date, to_date, reason FROM customer_vacations
               WHERE customer_id_fk = ? AND status = 'active'
                 AND to_date >= ? AND from_date <= ?
               ORDER BY from_date",
              [$cid, date('Y-m-d', $gridStart), date('Y-m-d', $gridEnd)]);
$vacsByDate = [];
foreach ($myVacs as $v) {
    $d = max(strtotime($v['from_date']), $gridStart);
    $eEnd = min(strtotime($v['to_date']), $gridEnd);
    while ($d <= $eEnd) {
        $vacsByDate[date('Y-m-d', $d)][] = $v;
        $d = strtotime('+1 day', $d);
    }
}

// AVAILABILITY: capacity per day (all customers, not just this one)
$totalPartners = max(1, (int) val("SELECT COUNT(*) FROM employee WHERE status=1"));
$busyByDate = [];
$busyRows = all("SELECT j_date, COUNT(DISTINCT emp_id_fk) AS busy, COUNT(*) as total_jobs
    FROM jobs WHERE j_date BETWEEN ? AND ? AND status=1 AND job_status NOT IN ('CANCELLED','COMPLETED') AND emp_id_fk IS NOT NULL
    GROUP BY j_date", [date('Y-m-d', $gridStart), date('Y-m-d', $gridEnd)]);
foreach ($busyRows as $r) {
    $busyByDate[$r['j_date']] = ['busy' => (int)$r['busy'], 'jobs' => (int)$r['total_jobs']];
}

// Pending job suggestions (auto-generated from iCal check-outs)
$pendingSuggestions = [];
try {
    $pendingSuggestions = all("
        SELECT * FROM job_suggestions
        WHERE customer_id_fk=? AND status='pending' AND suggested_date >= CURDATE()
        ORDER BY suggested_date ASC LIMIT 50
    ", [$cid]);
} catch (Exception $e) {}

// Index suggestions by date
$suggestionsByDate = [];
foreach ($pendingSuggestions as $s) {
    $suggestionsByDate[$s['suggested_date']][] = $s;
}

// Manual events
$manualByDate = [];
try {
    $manualEvents = all("SELECT * FROM manual_events WHERE customer_id_fk=? AND event_date BETWEEN ? AND ?", [$cid, date('Y-m-d', $gridStart), date('Y-m-d', $gridEnd)]);
    foreach ($manualEvents as $m) $manualByDate[$m['event_date']][] = $m;
} catch (Exception $e) {}

// Month stats (current month only)
$monthStart = date('Y-m-01', $firstDay);
$monthEnd = date('Y-m-t', $firstDay);
$monthJobs = array_filter($jobs, fn($j) => $j['j_date'] >= $monthStart && $j['j_date'] <= $monthEnd);
$upcomingCount = 0;
$completedCount = 0;
foreach ($monthJobs as $j) {
    if ($j['job_status'] === 'COMPLETED') $completedCount++;
    elseif (!in_array($j['job_status'], ['CANCELLED'])) $upcomingCount++;
}

// German month names
$monthNames = [
    '01' => 'Januar', '02' => 'Februar', '03' => 'März', '04' => 'April',
    '05' => 'Mai', '06' => 'Juni', '07' => 'Juli', '08' => 'August',
    '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Dezember'
];
$monthLabel = $monthNames[date('m', $firstDay)] . ' ' . date('Y', $firstDay);

$prevMonth = date('Y-m', strtotime("$month-01 -1 month"));
$nextMonth = date('Y-m', strtotime("$month-01 +1 month"));
$today = date('Y-m-d');

function statusBadgeClass(string $status): string {
    return match($status) {
        'COMPLETED' => 'bg-green-500',
        'CANCELLED' => 'bg-gray-400',
        'IN_PROGRESS' => 'bg-amber-500',
        'CONFIRMED' => 'bg-brand',
        default => 'bg-blue-400',
    };
}

include __DIR__ . '/../includes/layout-customer.php';
?>

<!-- Back button -->
<div class="mb-4">
  <a href="/customer/" class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-brand transition">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
    Zurück
  </a>
</div>

<!-- Unified view tabs (Kalender / Liste) -->
<div class="mb-4 inline-flex bg-white border border-gray-200 rounded-xl p-1">
  <a href="/customer/calendar.php" class="px-4 py-2 rounded-lg text-sm font-semibold bg-brand text-white flex items-center gap-1.5">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
    Kalender
  </a>
  <a href="/customer/jobs.php" class="px-4 py-2 rounded-lg text-sm font-semibold text-gray-600 hover:bg-gray-50 flex items-center gap-1.5">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
    Liste
  </a>
</div>

<?php
$custFullName2 = trim($customer['name'] ?? '');
$custSurname2 = trim($customer['surname'] ?? '');
$isBusiness2 = in_array($customer['customer_type'] ?? '', ['Airbnb', 'B2B', 'Host', 'Business', 'Booking', 'Short-Term Rental', 'Firma', 'GmbH'], true);
$greetingName = $isBusiness2 ? $custFullName2 : ($custSurname2 ?: ($custFullName2 ?: 'Willkommen'));
// Stammkunden-Status
$completedJobsCount = (int) val("SELECT COUNT(*) FROM jobs WHERE customer_id_fk=? AND status=1 AND job_status='COMPLETED'", [$cid]);
$isStammkunde = $completedJobsCount >= 5 || !empty($customer['legacy_pricing']);
$firstJob = val("SELECT MIN(j_date) FROM jobs WHERE customer_id_fk=? AND status=1 AND job_status='COMPLETED'", [$cid]);
$monthsActive = $firstJob ? max(0, floor((time() - strtotime($firstJob)) / (30*86400))) : 0;
?>
<!-- Header -->
<div class="mb-6 flex flex-wrap items-center justify-between gap-4" x-data="{ showManual: false, showVacation: false }">
  <div>
    <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Guten Tag, <?= e($greetingName) ?> 👋</h1>
    <p class="text-gray-500 mt-1 text-sm">Hier sind Ihre kommenden Termine — wir kümmern uns um den Rest.</p>
  </div>
  <div class="flex items-center gap-2 flex-wrap">
    <button @click="showManual = true" class="px-3 py-2 border border-gray-200 hover:border-brand hover:bg-brand/5 rounded-xl text-sm font-semibold text-gray-700 hover:text-brand flex items-center gap-2 transition">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
      Eintrag
    </button>
    <button @click="showVacation = true" class="px-3 py-2 border border-amber-300 bg-amber-50 hover:bg-amber-100 rounded-xl text-sm font-semibold text-amber-900 flex items-center gap-2 transition">
      🏖 Urlaub
    </button>
    <a href="/customer/booking.php" class="px-4 py-2 bg-brand hover:bg-brand/90 text-white rounded-xl text-sm font-semibold flex items-center gap-2 transition">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
      Reinigung buchen
    </a>
  </div>

  <!-- Vacation inline modal — saves via POST /customer/vacations.php -->
  <div x-show="showVacation" x-cloak @click.self="showVacation = false" class="fixed inset-0 bg-black/40 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4">
    <form method="POST" action="/customer/vacations.php" class="bg-white w-full sm:max-w-md rounded-t-2xl sm:rounded-2xl shadow-xl overflow-hidden" @click.stop>
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add_vacation"/>
      <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between bg-amber-50">
        <h3 class="font-semibold text-gray-900">🏖 Urlaub anmelden</h3>
        <button type="button" @click="showVacation = false" class="w-8 h-8 rounded-lg hover:bg-amber-100 flex items-center justify-center text-gray-500">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>
      <div class="p-5 space-y-4">
        <p class="text-xs text-gray-600">Während dieser Zeit pausieren wir alle geplanten Reinigungen automatisch.</p>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Von</label>
            <input type="date" name="from_date" required min="<?= date('Y-m-d') ?>" class="w-full px-3 py-2.5 border rounded-lg"/>
          </div>
          <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Bis</label>
            <input type="date" name="to_date" required min="<?= date('Y-m-d') ?>" class="w-full px-3 py-2.5 border rounded-lg"/>
          </div>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Grund (optional)</label>
          <input name="reason" placeholder="z.B. Familienurlaub, Geschäftsreise..." class="w-full px-3 py-2.5 border rounded-lg"/>
        </div>
        <label class="flex items-center gap-2 text-sm text-gray-700">
          <input type="checkbox" name="auto_skip_jobs" value="1" checked class="rounded"/>
          Geplante Reinigungen in diesem Zeitraum automatisch pausieren
        </label>
      </div>
      <div class="px-5 py-4 bg-gray-50 border-t flex gap-3 justify-end">
        <button type="button" @click="showVacation = false" class="px-4 py-2 rounded-lg text-sm text-gray-600 hover:bg-gray-200">Abbrechen</button>
        <button type="submit" class="px-5 py-2 rounded-lg bg-amber-500 hover:bg-amber-600 text-white text-sm font-semibold">Speichern</button>
      </div>
    </form>
  </div>

  <!-- Manual event modal -->
  <div x-show="showManual" x-cloak @click.self="showManual = false" class="fixed inset-0 bg-black/40 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4">
    <form method="POST" class="bg-white w-full sm:max-w-md rounded-t-2xl sm:rounded-2xl shadow-xl overflow-hidden" @click.stop>
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add_manual"/>
      <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
        <h3 class="font-semibold text-gray-900">Eigener Eintrag</h3>
        <button type="button" @click="showManual = false" class="w-8 h-8 rounded-lg hover:bg-gray-100 flex items-center justify-center text-gray-500">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>
      <div class="p-5 space-y-4">
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Titel</label>
          <input name="title" required placeholder="z.B. Eigene Putzaktion, Sperrung, Notiz..." class="w-full px-3 py-2.5 border rounded-lg"/>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Typ</label>
          <select name="type" class="w-full px-3 py-2.5 border rounded-lg bg-white">
            <option value="custom">📝 Eigener Termin</option>
            <option value="blocked">🚫 Tag sperren</option>
            <option value="reminder">⏰ Erinnerung</option>
          </select>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Datum</label>
            <input type="date" name="event_date" required class="w-full px-3 py-2.5 border rounded-lg"/>
          </div>
          <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Uhrzeit (optional)</label>
            <input type="time" name="event_time" class="w-full px-3 py-2.5 border rounded-lg"/>
          </div>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Notiz</label>
          <textarea name="notes" rows="2" class="w-full px-3 py-2.5 border rounded-lg"></textarea>
        </div>
        <div class="flex gap-2">
          <button type="button" @click="showManual = false" class="flex-1 px-4 py-2.5 border rounded-lg text-sm font-semibold text-gray-700 hover:bg-gray-50">Abbrechen</button>
          <button type="submit" class="flex-1 px-4 py-2.5 bg-brand hover:bg-brand-dark text-white rounded-lg text-sm font-semibold">Speichern</button>
        </div>
      </div>
    </form>
  </div>
</div>

<?php
// Last sync timestamp for trust signal
$lastSync = null;
try {
    $lastSync = val("SELECT MAX(last_sync) FROM ical_feeds WHERE customer_id_fk=? AND active=1", [$cid]);
} catch (Exception $e) {}
$syncMinAgo = $lastSync ? round((time() - strtotime($lastSync)) / 60) : null;
?>

<!-- Real-time sync status -->
<?php if ($lastSync): ?>
<div class="mb-4 flex items-center justify-between text-xs text-gray-500">
  <div class="flex items-center gap-2">
    <span class="w-2 h-2 rounded-full <?= $syncMinAgo < 30 ? 'bg-green-500 animate-pulse' : 'bg-amber-500' ?>"></span>
    <span>
      <?php if ($syncMinAgo < 1): ?>
        Live · gerade synchronisiert
      <?php elseif ($syncMinAgo < 60): ?>
        Letzte Synchronisation vor <?= $syncMinAgo ?> Min
      <?php else: ?>
        Letzte Synchronisation vor <?= round($syncMinAgo/60, 1) ?> Std
      <?php endif; ?>
    </span>
  </div>
  <a href="?m=<?= $month ?>&force_sync=1" class="text-brand hover:underline">Jetzt aktualisieren</a>
</div>
<?php endif; ?>

<?php if ($savedMsg): ?>
<div class="mb-4 card-elev border-green-200 bg-green-50 p-4 text-sm text-green-800 flex items-center gap-2">
  ✓ <?= match($savedMsg) {
      'approved' => 'Reinigungs-Vorschlag bestätigt — Job wurde erstellt.',
      'rejected' => 'Vorschlag abgelehnt.',
      'manual'   => 'Eintrag gespeichert.',
      default    => 'Gespeichert.',
  } ?>
</div>
<?php endif; ?>

<?php if (!empty($_GET['error'])): ?>
<div class="mb-4 card-elev border-red-200 bg-red-50 p-4 text-sm text-red-800">
  ⚠ <?= match($_GET['error']) {
      'approve_failed' => 'Vorschlag konnte nicht gebucht werden. Details: ' . e($_GET['msg'] ?? ''),
      default => 'Es ist ein Fehler aufgetreten: ' . e($_GET['error']),
  } ?>
</div>
<?php endif; ?>

<?php if (!empty($pendingSuggestions)): ?>
<!-- Pending suggestions banner — REQUIRES HOST APPROVAL -->
<div class="mb-6 card-elev border-2 border-amber-300 bg-amber-50 p-5">
  <div class="flex items-start gap-3 mb-3">
    <div class="w-10 h-10 rounded-full bg-amber-200 text-amber-800 flex items-center justify-center text-xl flex-shrink-0">⏳</div>
    <div class="flex-1">
      <h3 class="font-bold text-gray-900"><?= count($pendingSuggestions) ?> Reinigungs-Vorschläge zur Prüfung</h3>
      <p class="text-xs text-gray-600 mt-0.5">Auto-generiert aus Ihren iCal-Feeds nach Gast-Check-outs. <strong>Bitte prüfen und bestätigen Sie</strong> — sonst werden keine Termine angelegt.</p>
    </div>
  </div>
  <div class="space-y-2 max-h-64 overflow-y-auto">
    <?php foreach ($pendingSuggestions as $sg): ?>
    <div class="bg-white border border-amber-200 rounded-lg p-3 flex items-center justify-between gap-3">
      <div class="min-w-0 flex-1 text-sm">
        <div class="font-semibold text-gray-900">
          🧹 <?= date('D, d.m.Y', strtotime($sg['suggested_date'])) ?> · <?= substr($sg['suggested_time'], 0, 5) ?>
        </div>
        <div class="text-xs text-gray-500 mt-0.5">
          <?= e($sg['property_label']) ?> · nach Check-out
          <span class="text-gray-400">(via <?= e($sg['source_platform']) ?>)</span>
        </div>
      </div>
      <div class="flex gap-1 flex-shrink-0">
        <form method="POST" class="inline">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="approve_suggestion"/>
          <input type="hidden" name="sg_id" value="<?= $sg['sg_id'] ?>"/>
          <button type="submit" class="px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-lg text-xs font-semibold">✓ Buchen</button>
        </form>
        <form method="POST" class="inline">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="reject_suggestion"/>
          <input type="hidden" name="sg_id" value="<?= $sg['sg_id'] ?>"/>
          <button type="submit" class="px-3 py-1.5 border border-gray-200 hover:bg-gray-50 text-gray-600 rounded-lg text-xs font-semibold">✕</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="text-[11px] text-amber-800 mt-3 pt-3 border-t border-amber-200">
    ⚠ <strong>Haftungsausschluss:</strong> Die iCal-Daten werden automatisch von Ihren externen Plattformen (Smoobu/Airbnb/Booking) übertragen. Fleckfrei haftet nicht für Übertragungsfehler oder fehlerhafte Buchungen — bitte prüfen Sie jeden Vorschlag selbst, bevor Sie ihn bestätigen. Nicht-bestätigte Vorschläge werden NICHT als Termine angelegt.
  </div>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="grid grid-cols-2 sm:grid-cols-3 gap-4 mb-6">
  <div class="card-elev p-4">
    <div class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Termine diesen Monat</div>
    <div class="text-2xl font-bold text-gray-900"><?= count($monthJobs) ?></div>
  </div>
  <div class="card-elev p-4">
    <div class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Geplant</div>
    <div class="text-2xl font-bold text-brand"><?= $upcomingCount ?></div>
  </div>
  <div class="card-elev p-4 col-span-2 sm:col-span-1">
    <div class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Abgeschlossen</div>
    <div class="text-2xl font-bold text-green-600"><?= $completedCount ?></div>
  </div>
</div>

<!-- View switcher -->
<div class="flex items-center gap-2 mb-4 flex-wrap">
  <a href="?view=week&w=<?= e($weekAnchor) ?>" class="px-4 py-2 rounded-xl text-sm font-semibold transition <?= $view === 'week' ? 'bg-brand text-white shadow' : 'bg-white border border-gray-200 text-gray-600 hover:border-brand' ?>">
    📆 Woche
  </a>
  <a href="?view=month&m=<?= e($month) ?>" class="px-4 py-2 rounded-xl text-sm font-semibold transition <?= $view === 'month' ? 'bg-brand text-white shadow' : 'bg-white border border-gray-200 text-gray-600 hover:border-brand' ?>">
    📅 Monat
  </a>
</div>

<!-- Calendar -->
<div class="card-elev overflow-hidden" x-data="{
  selected: null, selectedDate: null, selectedDateIso: null,
  messageJobId: null, messageText: '',
  saveMessage() {
    fetch('/api/index.php?action=jobs/update', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-API-Key': '<?= API_KEY ?>' },
      body: JSON.stringify({ j_id: this.messageJobId, field: 'emp_message', value: this.messageText })
    }).then(r => r.json()).then(d => {
      if (d.success) {
        const j = this.selected?.find(x => x.id === this.messageJobId);
        if (j) j.emp_message = this.messageText;
        this.messageJobId = null;
      } else {
        alert('Fehler: ' + (d.error || 'Unbekannt'));
      }
    }).catch(() => alert('Netzwerkfehler'));
  }
}">

  <?php if ($view === 'week'): ?>
  <!-- WEEK VIEW NAVIGATOR -->
  <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
    <h3 class="font-semibold text-gray-900 text-lg"><?= $weekLabel ?></h3>
    <div class="flex items-center gap-1">
      <a href="?view=week&w=<?= $prevWeek ?>" class="w-9 h-9 rounded-lg flex items-center justify-center hover:bg-gray-100 text-gray-600 transition" title="Vorherige Woche">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
      </a>
      <a href="?view=week&w=<?= date('Y-m-d') ?>" class="px-3 h-9 rounded-lg flex items-center justify-center hover:bg-gray-100 text-gray-700 text-xs font-medium transition">Heute</a>
      <a href="?view=week&w=<?= $nextWeek ?>" class="w-9 h-9 rounded-lg flex items-center justify-center hover:bg-gray-100 text-gray-600 transition" title="Nächste Woche">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
      </a>
    </div>
  </div>
  <?php else: ?>
  <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
    <h3 class="font-semibold text-gray-900 text-lg"><?= $monthLabel ?></h3>
    <div class="flex items-center gap-1">
      <a href="?view=month&m=<?= $prevMonth ?>" class="w-9 h-9 rounded-lg flex items-center justify-center hover:bg-gray-100 text-gray-600 transition" title="Vorheriger Monat">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
      </a>
      <a href="?view=month&m=<?= date('Y-m') ?>" class="px-3 h-9 rounded-lg flex items-center justify-center hover:bg-gray-100 text-gray-700 text-xs font-medium transition">Heute</a>
      <a href="?view=month&m=<?= $nextMonth ?>" class="w-9 h-9 rounded-lg flex items-center justify-center hover:bg-gray-100 text-gray-600 transition" title="Nächster Monat">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
      </a>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($view === 'week'):
    // Need to load jobs/events for week range if different from month range
    $weekJobs = all("SELECT j.*, s.title as stitle, s.street, s.city, e.display_name as edisplay, e.profile_pic as eavatar
        FROM jobs j LEFT JOIN services s ON j.s_id_fk=s.s_id LEFT JOIN employee e ON j.emp_id_fk=e.emp_id
        WHERE j.customer_id_fk=? AND j.j_date BETWEEN ? AND ? AND j.status=1 ORDER BY j.j_date, j.j_time", [$cid, $weekStart, $weekEnd]);
    $weekJobsByDate = [];
    foreach ($weekJobs as $j) $weekJobsByDate[$j['j_date']][] = $j;
    // Week check-outs (host only)
    $weekCheckouts = [];
    try {
        $rows = all("SELECT ev.*, f.label as feed_label, f.source_platform FROM external_events ev LEFT JOIN ical_feeds f ON ev.ical_feed_id=f.feed_id WHERE f.customer_id_fk=? AND ev.end_date BETWEEN ? AND ?", [$cid, $weekStart, $weekEnd]);
        foreach ($rows as $ev) $weekCheckouts[$ev['end_date']][] = $ev;
    } catch (Exception $e) {}
  ?>
  <!-- WEEK VIEW: 7 prominent cards, one per day -->
  <div class="p-3 sm:p-4 space-y-2 sm:space-y-3">
    <?php for ($d = 0; $d < 7; $d++):
      $dayTs = strtotime("+$d days", $weekStartTs);
      $dayDate = date('Y-m-d', $dayTs);
      $isToday = $dayDate === $today;
      $dayJobs = $weekJobsByDate[$dayDate] ?? [];
      $dayCheckouts = $weekCheckouts[$dayDate] ?? [];
      $isPast = $dayDate < $today;
      $weekdayDe = ['Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag','Sonntag'][date('N', $dayTs) - 1];
    ?>
    <div class="rounded-xl border <?= $isToday ? 'border-brand bg-brand/5' : 'border-gray-100 bg-white' ?> <?= $isPast ? 'opacity-60' : '' ?> overflow-hidden">
      <div class="px-4 py-2 border-b border-gray-100 flex items-center justify-between <?= $isToday ? 'bg-brand/10' : 'bg-gray-50/50' ?>">
        <div class="flex items-center gap-3">
          <div class="text-center">
            <div class="text-[10px] uppercase font-bold text-gray-500"><?= $weekdayDe ?></div>
            <div class="text-xl font-extrabold <?= $isToday ? 'text-brand' : 'text-gray-900' ?>"><?= (int) date('j', $dayTs) ?></div>
          </div>
          <?php if ($isToday): ?><span class="text-[10px] px-2 py-0.5 rounded-full bg-brand text-white font-bold uppercase">Heute</span><?php endif; ?>
        </div>
        <div class="text-xs text-gray-400">
          <?php $cnt = count($dayJobs) + count($dayCheckouts); ?>
          <?php if ($cnt > 0): ?><?= $cnt ?> Termin<?= $cnt === 1 ? '' : 'e' ?><?php else: ?>Frei<?php endif; ?>
        </div>
      </div>

      <div class="p-3 space-y-2">
        <?php if (empty($dayJobs) && empty($dayCheckouts)): ?>
        <div class="text-xs text-gray-400 italic text-center py-3">Keine Termine</div>
        <?php endif; ?>

        <?php foreach ($dayJobs as $j):
          $status = $j['job_status'] ?? 'NEW';
          $bgCls = match($status) {
            'COMPLETED' => 'bg-green-50 border-green-200 text-green-900',
            'RUNNING'   => 'bg-amber-50 border-amber-300 text-amber-900 ring-2 ring-amber-200',
            'CANCELLED' => 'bg-gray-50 border-gray-200 text-gray-400 line-through',
            default     => 'bg-brand-light border-brand/20 text-gray-900',
          };
        ?>
        <div class="flex items-center gap-3 p-2.5 rounded-lg border <?= $bgCls ?>">
          <div class="flex-shrink-0 text-center w-14">
            <div class="text-base font-bold"><?= e(substr($j['j_time'] ?? '', 0, 5)) ?></div>
            <div class="text-[9px] uppercase text-gray-500"><?= (float)($j['total_hours'] ?: $j['j_hours']) ?>h</div>
          </div>
          <div class="flex-1 min-w-0">
            <div class="font-semibold text-sm truncate"><?= e($j['stitle'] ?? 'Reinigung') ?></div>
            <div class="text-[11px] text-gray-500 truncate">
              <?php if ($status === 'RUNNING'): ?>🟠 Läuft gerade<?php endif; ?>
              <?php if ($status === 'COMPLETED'): ?>✓ Erledigt<?php endif; ?>
              <?php if ($status === 'PENDING' || $status === 'NEW'): ?>🕐 Geplant<?php endif; ?>
              <?php if (!empty($j['emp_id_fk'])): ?> · <?= e(partnerDisplayName($j)) ?><?php endif; ?>
            </div>
          </div>
          <a href="/customer/jobs.php" class="text-[11px] font-semibold text-brand flex-shrink-0 hover:underline">Details →</a>
        </div>
        <?php endforeach; ?>

        <?php foreach ($dayCheckouts as $ev): ?>
        <div class="flex items-center gap-3 p-2.5 rounded-lg bg-orange-50 border border-orange-200">
          <div class="flex-shrink-0 text-center w-14">
            <div class="text-base">🚪</div>
          </div>
          <div class="flex-1 min-w-0">
            <div class="font-semibold text-sm text-orange-900">Check-out</div>
            <div class="text-[11px] text-orange-700 truncate"><?= e($ev['feed_label'] ?: 'Property') ?> · <?= e($ev['source_platform'] ?: 'iCal') ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endfor; ?>
  </div>
  <?php else: ?>

  <!-- Farb-Legende -->
  <div class="flex items-center flex-wrap gap-3 px-4 py-2 text-[10px] font-medium text-gray-700 bg-white border-b border-gray-100">
    <div class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded bg-yellow-400"></span>Kein Partner</div>
    <div class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded bg-blue-500"></span>Partner zugewiesen</div>
    <div class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded bg-orange-500"></span>Läuft</div>
    <div class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded bg-green-500"></span>Erledigt</div>
    <div class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded bg-gray-200"></span>Storniert</div>
  </div>

  <!-- Weekday header -->
  <div class="grid grid-cols-7 border-b border-gray-100 bg-gray-50">
    <?php foreach (['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'] as $wd): ?>
    <div class="px-1 sm:px-2 py-2 text-center text-[10px] sm:text-xs font-semibold text-gray-700 uppercase tracking-wider"><?= $wd ?></div>
    <?php endforeach; ?>
  </div>

  <!-- Calendar grid — 6 weeks × 7 days -->
  <div class="grid grid-cols-7">
    <?php
    for ($i = 0; $i < 42; $i++):
        $cellTs = strtotime("+$i days", $gridStart);
        $cellDate = date('Y-m-d', $cellTs);
        $isCurrentMonth = date('Y-m', $cellTs) === $month;
        $isToday = $cellDate === $today;
        $cellJobs = $jobsByDate[$cellDate] ?? [];
        $cellExt = $externalEvents[$cellDate] ?? [];
        $cellSugg = $suggestionsByDate[$cellDate] ?? [];
        $cellManual = $manualByDate[$cellDate] ?? [];
        $cellVacs = $vacsByDate[$cellDate] ?? [];
        $hasJobs = !empty($cellJobs);
        $hasExt = !empty($cellExt);
        $hasSugg = !empty($cellSugg);
        $hasManual = !empty($cellManual);
        $hasVac = !empty($cellVacs);
        $isClickable = $hasJobs || $hasExt || $hasSugg || $hasManual;

        // For each external event, determine if THIS day is check-in or check-out
        $extWithDirection = [];
        foreach ($cellExt as $ev) {
            $type = 'middle'; // mid-stay
            if ($ev['start_date'] === $cellDate) $type = 'checkin';
            if ($ev['end_date'] === $cellDate) $type = 'checkout';
            $extWithDirection[] = ['ev' => $ev, 'type' => $type];
        }

        // Modal payload: ONLY actionable events for customer
        // - Internal jobs (excluding CANCELLED — those have their own section)
        // - Check-outs (actionable: cleaning needed)
        // SKIP: check-ins, mid-stay "Belegt" — irrelevant for cleaning planning
        $relevantJobs = array_filter($cellJobs, fn($j) => ($j['job_status'] ?? '') !== 'CANCELLED');
        // A: Pre-load proofs (Beweisfotos+Videos vom Partner) per job in this cell
        $jobIdsForProofs = array_column($relevantJobs, 'j_id');
        $proofsByJob = [];
        if (!empty($jobIdsForProofs)) {
            $_ph = implode(',', array_fill(0, count($jobIdsForProofs), '?'));
            try {
                $_proofs = all("SELECT cc.job_id_fk, cc.photo, cc.video, cc.note, cc.completed, cc.completed_at, cl.title FROM checklist_completions cc LEFT JOIN service_checklists cl ON cc.checklist_id_fk=cl.checklist_id WHERE cc.job_id_fk IN ($_ph) AND (cc.photo IS NOT NULL OR cc.video IS NOT NULL OR (cc.note IS NOT NULL AND cc.note <> '')) ORDER BY cc.completed_at DESC", $jobIdsForProofs);
                foreach ($_proofs as $_p) $proofsByJob[$_p['job_id_fk']][] = $_p;
                // Merge LR jobs.job_file (Partner uploaded via la-renting.de)
                try {
                    $_jobFiles = all("SELECT j_id, job_file, job_file2 FROM jobs WHERE j_id IN ($_ph) AND ((job_file IS NOT NULL AND job_file != '' AND job_file != '[]') OR (job_file2 IS NOT NULL AND job_file2 != '' AND job_file2 != '[]'))", $jobIdsForProofs);
                    foreach ($_jobFiles as $_jf) {
                        foreach (['job_file','job_file2'] as $_fld) {
                            $_arr = json_decode($_jf[$_fld] ?: '[]', true) ?: [];
                            foreach ($_arr as $_url) {
                                if (!$_url) continue;
                                $_isVid = preg_match('/\.(mp4|mov|webm|m4v)$/i', $_url);
                                $proofsByJob[$_jf['j_id']][] = [
                                    'job_id_fk' => $_jf['j_id'],
                                    'photo' => $_isVid ? null : $_url,
                                    'video' => $_isVid ? $_url : null,
                                    'note' => null,
                                    'completed' => 1,
                                    'completed_at' => null,
                                    'title' => 'Beweisfoto vom Partner (LR)',
                                ];
                            }
                        }
                    }
                } catch (Exception $e) {}
            } catch (Exception $e) {}
        }
        $checkouts = array_filter($cellExt, fn($ev) => $ev['end_date'] === $cellDate);

        // Best-effort: match checkout feed_label to a customer service by name containment
        static $customerServicesCache = null;
        if ($customerServicesCache === null) {
            $customerServicesCache = all("SELECT s_id, title FROM services WHERE customer_id_fk=? AND status=1", [$cid]) ?: [];
        }
        $matchServiceId = function($label) use ($customerServicesCache) {
            if (!$label) return 0;
            $lbl = strtolower($label);
            foreach ($customerServicesCache as $s) {
                $st = strtolower($s['title']);
                if ($st === $lbl || strpos($st, $lbl) !== false || strpos($lbl, $st) !== false) return (int)$s['s_id'];
            }
            // Fallback: first service
            return isset($customerServicesCache[0]) ? (int)$customerServicesCache[0]['s_id'] : 0;
        };

        $payload = array_merge(
            array_map(fn($j) => [
                'id' => $j['j_id'],
                'type' => 'internal',
                'time' => substr($j['j_time'] ?? '', 0, 5),
                'service' => $j['stitle'] ?? 'Reinigung',
                'service_id' => (int)($j['s_id_fk'] ?? 0),
                'partner' => !empty($j['emp_id_fk']) ? partnerDisplayName($j) : '',
                'status' => $j['job_status'] ?? 'NEW',
                'hours' => $j['total_hours'] ?: $j['j_hours'] ?: 0,
                'planned_hours' => $j['j_hours'] ?? 0,
                'no_people' => (int)($j['no_people'] ?? 1),
                'no_children' => (int)($j['no_children'] ?? 0),
                'no_pets' => (int)($j['no_pets'] ?? 0),
                'address' => $j['address'] ?? '',
                'code_door' => $j['code_door'] ?? '',
                'emp_message' => $j['emp_message'] ?? '',
                'job_note' => $j['job_note'] ?? '',
                'platform' => $j['platform'] ?? '',
                'proofs' => $proofsByJob[$j['j_id']] ?? [], 'booked_by' => (in_array($j['platform'] ?? '', ['airbnb','booking','smoobu','vrbo','ical']) || (in_array(strtolower(trim($j['platform'] ?? '', "' ")), ['whatsapp']) && !empty($j['guest_name']) && trim($j['guest_name']) !== '.')) ? 'host' : 'customer',
            ], $relevantJobs),
            array_map(fn($ev) => [
                'id' => 'ext_' . $ev['ev_id'],
                'type' => 'checkout',
                'service' => $ev['feed_label'] ?: 'Property',
                'service_id' => $matchServiceId($ev['feed_label'] ?? ''),
                'platform' => $ev['source_platform'] ?: 'iCal',
            ], $checkouts)
        );

        $hasCheckout = !empty($checkouts);
        $hasJobsRelevant = !empty($relevantJobs);
        $hasEvents = $hasCheckout || $hasJobsRelevant || !empty($cellSugg) || !empty($cellManual);
        $isPast = $cellDate < $today;
        // Empty future/today cells → direct booking link with prefilled date
        $bookingUrl = '/customer/booking.php?date=' . $cellDate;
    ?>
    <div class="min-h-[100px] sm:min-h-[110px] border-r border-b border-gray-100 last:border-r-0 p-1 sm:p-2 relative group <?= $hasVac ? 'bg-amber-50' : ($isCurrentMonth ? 'bg-white' : 'bg-gray-50/50') ?> <?= !$isPast ? 'cursor-pointer hover:bg-brand/5 transition' : '' ?>"
         <?php if (!$isPast): ?>
         <?php if ($hasEvents): ?>
         @click="selected = <?= htmlspecialchars(json_encode($payload), ENT_QUOTES) ?>; selectedDate = '<?= date('d.m.Y', $cellTs) ?>'; selectedDateIso = '<?= $cellDate ?>'"
         <?php else: ?>
         onclick="window.location.href='<?= $bookingUrl ?>'"
         <?php endif; ?>
         <?php endif; ?>>

      <!-- Top: day number + external indicator strip -->
      <div class="flex items-center justify-between mb-1">
        <span class="text-xs sm:text-sm font-semibold <?= $isToday ? 'w-6 h-6 sm:w-7 sm:h-7 rounded-full bg-brand text-white flex items-center justify-center' : ($isCurrentMonth ? 'text-gray-900' : 'text-gray-400') ?>">
          <?= (int) date('j', $cellTs) ?>
        </span>
        <?php if ($hasVac): ?>
          <span class="text-[9px] px-1 py-0.5 bg-amber-200 text-amber-900 rounded font-bold" title="<?= e($cellVacs[0]['reason'] ?? 'Urlaub') ?>">🏖 Urlaub</span>
        <?php endif; ?>
        <?php
        // Availability indicator for future days
        if (!$isPast && $isCurrentMonth) {
            $dayBusy = $busyByDate[$cellDate] ?? ['busy' => 0, 'jobs' => 0];
            $freePartners = max(0, $totalPartners - $dayBusy['busy']);
            $capacityPct = $totalPartners > 0 ? round($dayBusy['busy'] / $totalPartners * 100) : 0;
            if ($capacityPct >= 90) {
                echo '<span class="text-[8px] px-1 py-0.5 bg-red-100 text-red-600 rounded font-bold">voll</span>';
            } elseif ($capacityPct >= 60) {
                echo '<span class="text-[8px] px-1 py-0.5 bg-amber-100 text-amber-600 rounded font-bold">' . $freePartners . ' frei</span>';
            } elseif (!$hasEvents) {
                echo '<span class="opacity-0 group-hover:opacity-100 transition text-[8px] px-1 py-0.5 bg-green-100 text-green-600 rounded font-bold">frei</span>';
            }
        }
        ?>
      </div>

      <!-- INTERNAL JOBS: prominent (Fleckfrei work) -->
      <div class="space-y-1">
        <?php
        $shown = 0;
        foreach ($cellJobs as $j):
            if ($shown >= 2) break; $shown++;
            $status = $j['job_status'] ?? '';
            $isCompleted = $status === 'COMPLETED';
            $isCancelled = $status === 'CANCELLED';
            $isRunning = in_array($status, ['RUNNING','STARTED']);
            // Farblogik wie Admin-Kalender
            if ($isCompleted) {
                $bgClass = 'bg-green-500 text-white border-green-600';
                $icon = '✓';
            } elseif ($isCancelled) {
                $bgClass = 'bg-gray-100 text-gray-400 line-through border-gray-200';
                $icon = '✕';
            } elseif ($isRunning) {
                $bgClass = 'bg-orange-500 text-white border-orange-600 animate-pulse';
                $icon = '▶';
            } elseif (empty($j['emp_id_fk'])) {
                // Kein Partner zugewiesen → Gelb (Max Spec 2026-04-13)
                $bgClass = 'bg-yellow-400 text-yellow-900 border-yellow-500';
                $icon = '⏳';
            } else {
                // Partner zugewiesen, noch nicht gestartet → Blau
                $bgClass = 'bg-blue-500 text-white border-blue-600';
                $icon = '🧹';
            }
            $jTime = substr($j['j_time'] ?? '', 0, 5);
            $jTitle = $j['stitle'] ?? 'Reinigung';
            $jPeople = (int)($j['no_people'] ?? 1);
            $jChildren = (int)($j['no_children'] ?? 0);
            $jPets = (int)($j['no_pets'] ?? 0);
            $hasMsg = !empty($j['emp_message']);
            $badges = [];
            if ($jPeople > 1) $badges[] = '👥' . $jPeople;
            if ($jChildren > 0) $badges[] = '👶' . $jChildren;
            if ($jPets > 0) $badges[] = '🐾' . $jPets;
            if ($hasMsg) $badges[] = '💬';
        ?>
        <div class="text-[10px] sm:text-[11px] px-1.5 py-0.5 rounded border <?= $bgClass ?> font-semibold flex items-center gap-1 overflow-hidden shadow-sm">
          <span class="flex-shrink-0"><?= $icon ?></span>
          <span class="font-bold flex-shrink-0"><?= e($jTime) ?></span>
          <span class="truncate flex-1 min-w-0"><?= e(mb_substr($jTitle, 0, 15)) ?></span>
          <?php if ($badges): ?>
          <span class="flex-shrink-0 text-[9px] opacity-90"><?= implode(' ', $badges) ?></span>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <!-- Pending suggestions (need approval) -->
        <?php foreach ($cellSugg as $sg):
            if ($shown >= 3) break; $shown++;
        ?>
        <div class="text-[10px] sm:text-[11px] px-1.5 py-0.5 rounded bg-amber-100 text-amber-800 font-semibold truncate flex items-center gap-1 border border-dashed border-amber-300">
          <span>⏳</span>
          <span class="hidden sm:inline">Vorschlag</span>
        </div>
        <?php endforeach; ?>

        <!-- Manual events -->
        <?php foreach ($cellManual as $me):
            if ($shown >= 3) break; $shown++;
            $meBg = $me['type'] === 'blocked' ? 'bg-red-100 text-red-700' : 'bg-blue-100 text-blue-700';
        ?>
        <div class="text-[10px] sm:text-[11px] px-1.5 py-0.5 rounded <?= $meBg ?> font-medium truncate flex items-center gap-1">
          <span><?= $me['type'] === 'blocked' ? '🚫' : ($me['type'] === 'reminder' ? '⏰' : '📝') ?></span>
          <span class="hidden sm:inline truncate"><?= e($me['title']) ?></span>
        </div>
        <?php endforeach; ?>

        <!-- External: ONLY Check-outs are actionable (= cleaning needed) -->
        <?php if ($hasExt && $shown < 3):
            $checkoutCount = count(array_filter($extWithDirection, fn($x) => $x['type'] === 'checkout'));
        ?>
        <?php if ($checkoutCount > 0): ?>
        <div class="text-[10px] sm:text-[11px] px-1.5 py-0.5 rounded bg-orange-100 text-orange-800 font-bold flex items-center gap-1 mt-0.5">
          <span>↑</span>
          <span>Check-out<?= $checkoutCount > 1 ? ' (' . $checkoutCount . ')' : '' ?></span>
        </div>
        <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php endfor; ?>
  </div>
  <?php endif; /* view === month */ ?>

  <!-- Legend (vereinfacht: nur was actionable ist) -->
  <div class="px-5 py-3 border-t border-gray-100 bg-gray-50 flex flex-wrap items-center gap-x-5 gap-y-2 text-[11px] text-gray-600">
    <div class="flex items-center gap-1"><span class="px-1.5 py-0 rounded bg-brand/15 text-brand font-bold">🧹</span>Reinigung gebucht</div>
    <div class="flex items-center gap-1"><span class="px-1.5 py-0 rounded bg-green-100 text-green-800 font-bold">🧹</span>Erledigt</div>
    <div class="flex items-center gap-1"><span class="px-1.5 py-0 rounded bg-orange-100 text-orange-800 font-bold">↑</span>Gast Check-out (Reinigung nötig)</div>
    <div class="flex items-center gap-1"><span class="px-1.5 py-0 rounded bg-amber-100 text-amber-800 font-bold border border-dashed border-amber-300">⏳</span>Vorschlag</div>
    <div class="flex items-center gap-1"><span class="px-1.5 py-0 rounded bg-blue-100 text-blue-700">📝</span>Eigener Eintrag</div>
  </div>

  <!-- Day detail modal -->
  <div x-show="selected" x-cloak @click.self="selected = null" class="fixed inset-0 bg-black/40 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4" x-transition.opacity>
    <div class="bg-white w-full sm:max-w-md rounded-t-2xl sm:rounded-2xl shadow-xl max-h-[80vh] overflow-hidden flex flex-col" @click.stop x-transition>
      <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between bg-gradient-to-r from-brand/5 to-transparent">
        <div>
          <h3 class="font-bold text-gray-900 text-lg" x-text="selectedDate"></h3>
          <p class="text-xs text-gray-500">
            <span x-show="selected?.length === 1">1 Termin</span>
            <span x-show="selected?.length > 1"><span x-text="selected?.length"></span> Termine an diesem Tag</span>
          </p>
        </div>
        <button @click="selected = null" class="w-8 h-8 rounded-lg hover:bg-gray-100 flex items-center justify-center text-gray-500">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>
      <div class="flex-1 overflow-y-auto p-5 space-y-3">

        <?php $firstName = explode(' ', trim($customer['name'] ?? 'Hallo'))[0]; ?>

        <!-- CHECK-OUTS section first (most actionable for hosts) -->
        <template x-if="selected?.some(j => j.type === 'checkout')">
          <div>
            <div class="mb-3">
              <div class="font-bold text-gray-900 text-base flex items-center gap-2">
                <span class="text-orange-600 text-xl">↑</span>
                <span>Hier checkt ein Gast aus</span>
              </div>
              <p class="text-xs text-gray-500 mt-0.5">Frau/Herr <?= e($firstName) ?>, Ihre Wohnung wird wieder frei — möchten Sie eine Reinigung buchen?</p>
            </div>
            <div class="space-y-2">
              <template x-for="job in selected.filter(j => j.type === 'checkout')" :key="job.id">
                <div class="border-2 border-orange-300 bg-orange-50 rounded-xl p-4">
                  <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0 flex-1">
                      <div class="font-bold text-orange-900 text-sm" x-text="job.service"></div>
                      <div class="text-[10px] text-orange-600 mt-0.5">Bereit für die nächsten Gäste 🌟</div>
                    </div>
                    <a :href="'/customer/booking.php' + (job.service_id ? ('?service_id=' + job.service_id + '&date=' + encodeURIComponent(selectedDateIso || '')) : '')" class="px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded-lg text-xs font-bold whitespace-nowrap transition flex items-center gap-1.5 shadow-sm">
                      🧹 Buchen
                    </a>
                  </div>
                </div>
              </template>
            </div>
          </div>
        </template>

        <!-- INTERNAL JOBS section — erweiterte Transparenz -->
        <template x-if="selected?.some(j => j.type === 'internal')">
          <div>
            <div class="mb-3 mt-4 flex items-center justify-between gap-3">
              <div>
                <div class="font-bold text-gray-900 text-base flex items-center gap-2">
                  <span class="text-xl">🧹</span>
                  <span>Ihre Reinigung</span>
                </div>
                <p class="text-xs text-gray-600 mt-0.5">Wir kümmern uns für Sie.</p>
              </div>
              <!-- Kunden-Status Badge -->
              <?php if ($isStammkunde): ?>
              <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-gradient-to-r from-amber-400 to-amber-300 text-amber-900 whitespace-nowrap shadow-sm">⭐ Stammkunde · <?= $completedJobsCount ?> Aufträge</span>
              <?php else: ?>
              <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-blue-100 text-blue-700 whitespace-nowrap">🆕 Neukunde</span>
              <?php endif; ?>
            </div>
            <div class="space-y-2.5">
              <template x-for="job in selected.filter(j => j.type === 'internal')" :key="job.id">
                <div class="border border-gray-200 rounded-xl p-4 hover:border-brand transition bg-white">
                  <!-- Header: Service + Status -->
                  <div class="flex items-start justify-between gap-3 mb-2">
                    <div class="min-w-0 flex-1">
                      <div class="font-bold text-gray-900 text-sm" x-text="job.service"></div>
                      <div class="text-xs text-gray-600 mt-0.5 flex items-center gap-3 flex-wrap">
                        <span x-show="job.time" class="flex items-center gap-1 font-semibold text-gray-900">
                          <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                          <span x-text="job.time + ' Uhr'"></span>
                        </span>
                        <span x-show="job.hours > 0" x-text="'· ' + parseFloat(Math.max(2, parseFloat(job.hours))).toFixed(1) + 'h'"></span>
                        <span x-show="parseFloat(job.hours) < 2 || parseFloat(job.planned_hours) < 2" class="text-amber-700 text-[10px] font-semibold">⚠ min. 2h berechnet</span>
                      </div>
                    </div>
                    <template x-if="job.status === 'RUNNING' || job.status === 'STARTED'">
                      <span class="px-3 py-1.5 rounded-full text-xs font-bold whitespace-nowrap bg-orange-500 text-white shadow-lg flex items-center gap-1.5 animate-pulse">
                        <span class="w-2 h-2 bg-white rounded-full"></span>
                        🟠 LIVE · Läuft gerade
                      </span>
                    </template>
                    <template x-if="job.status !== 'RUNNING' && job.status !== 'STARTED'">
                      <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold whitespace-nowrap"
                        :class="{
                          'bg-green-100 text-green-700': job.status === 'COMPLETED',
                          'bg-brand/10 text-brand': job.status === 'CONFIRMED',
                          'bg-blue-100 text-blue-700': job.status === 'PENDING' || job.status === 'NEW',
                          'bg-red-100 text-red-700': job.status === 'CANCELLED'
                        }"
                        x-text="{COMPLETED: 'Erledigt ✓', CONFIRMED: 'Bestätigt', PENDING: 'Geplant', NEW: 'Neu', CANCELLED: 'Storniert'}[job.status] || job.status"></span>
                    </template>
                  </div>

                  <!-- Gebucht von: Kunde oder Host/Platform -->
                  <div class="text-[11px] text-gray-600 mb-2 flex items-center gap-1.5">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    <span x-show="job.booked_by === 'host'">Buchung über <strong x-text="job.platform.toUpperCase()"></strong> (Host-Plattform)</span>
                    <span x-show="job.booked_by === 'customer'">Gebucht von Ihnen</span>
                  </div>
                  <!-- A: Beweisfoto-Galerie wenn COMPLETED -->
                  <template x-if="job.proofs && job.proofs.length > 0">
                    <div class="mt-3 p-3 bg-green-50 border border-green-200 rounded-lg">
                      <div class="text-xs font-semibold text-green-800 mb-2">📸 Updates vom Partner (<span x-text="job.proofs.length"></span>)</div>
                      <div class="grid grid-cols-3 gap-1.5">
                        <template x-for="p in job.proofs" :key="p.photo + (p.video || '')">
                          <div>
                            <template x-if="p.photo"><a :href="p.photo" target="_blank"><img :src="p.photo" :alt="p.title" class="w-full h-20 object-cover rounded border"/></a></template>
                            <template x-if="p.video"><a :href="'/video360.php?file=' + encodeURIComponent(p.video)" target="_blank" class="flex items-center justify-center h-20 bg-black text-white rounded text-2xl">▶</a></template>
                            <div class="text-[9px] text-gray-500 truncate" x-text="p.title"></div>
                          </div>
                        </template>
                      </div>
                    </div>
                  </template>
                  <div x-show="job.proofs && job.proofs.length === 0 && job.booked_by" style="display:none">
                  </div>

                  <!-- Details: Personen / Kinder / Haustiere -->
                  <div class="flex items-center gap-3 flex-wrap mb-2 text-[11px]">
                    <span class="flex items-center gap-1 px-2 py-1 bg-gray-50 rounded-lg">
                      <svg class="w-3.5 h-3.5 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                      <span class="font-semibold text-gray-900" x-text="(job.no_people || 1) + ' Person' + ((job.no_people || 1) > 1 ? 'en' : '')"></span>
                    </span>
                    <span x-show="job.no_children > 0" class="flex items-center gap-1 px-2 py-1 bg-blue-50 rounded-lg text-blue-800 font-semibold">
                      👶 <span x-text="job.no_children + ' Kind' + (job.no_children > 1 ? 'er' : '')"></span>
                    </span>
                    <span x-show="job.no_pets > 0" class="flex items-center gap-1 px-2 py-1 bg-amber-50 rounded-lg text-amber-800 font-semibold">
                      🐾 <span x-text="job.no_pets + ' Haustier' + (job.no_pets > 1 ? 'e' : '')"></span>
                    </span>
                    <span x-show="job.code_door" class="flex items-center gap-1 px-2 py-1 bg-brand-light rounded-lg text-brand-dark font-semibold">
                      🔑 <span x-text="'Code: ' + job.code_door"></span>
                    </span>
                  </div>

                  <!-- Adresse -->
                  <div x-show="job.address" class="text-[11px] text-gray-700 mb-2 flex items-start gap-1.5">
                    <svg class="w-3 h-3 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    <span x-text="job.address"></span>
                  </div>

                  <!-- Partner -->
                  <div x-show="job.partner" class="text-[11px] mb-2 flex items-center gap-1.5 text-brand-dark font-semibold">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    Partner: <span x-text="job.partner"></span>
                  </div>

                  <!-- Nachricht an Partner -->
                  <div class="mt-3 pt-3 border-t border-gray-100">
                    <div x-show="job.emp_message" class="mb-2">
                      <div class="text-[10px] uppercase font-bold text-gray-500 mb-1">Ihre Nachricht an Partner:</div>
                      <div class="text-[11px] text-gray-800 bg-amber-50 border-l-2 border-amber-400 px-2 py-1.5 rounded" x-text="job.emp_message"></div>
                    </div>
                    <button x-show="!['COMPLETED','CANCELLED'].includes(job.status)" type="button"
                            @click="messageJobId = job.id; messageText = job.emp_message || ''"
                            class="w-full flex items-center justify-center gap-2 px-3 py-2 border border-brand text-brand hover:bg-brand hover:text-white rounded-lg text-xs font-semibold transition">
                      <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                      <span x-text="job.emp_message ? 'Nachricht bearbeiten' : 'Nachricht an Partner schreiben'"></span>
                    </button>
                  </div>

                  <!-- Quick-Actions -->
                  <div x-show="['PENDING','CONFIRMED'].includes(job.status)" class="flex gap-2 mt-2">
                    <a :href="'/customer/jobs.php?edit=' + job.id" class="flex-1 text-center text-[11px] px-2 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-800 rounded-lg font-semibold">Details / Ändern</a>
                  </div>
                </div>
              </template>
            </div>
          </div>
        </template>

        <!-- Nachricht-Modal -->
        <div x-show="messageJobId" x-cloak
             class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4"
             @click.self="messageJobId = null">
          <div class="bg-white rounded-xl p-5 w-full max-w-md shadow-2xl">
            <h3 class="font-bold text-gray-900 mb-1">Nachricht an Partner</h3>
            <p class="text-xs text-gray-600 mb-4">Hinweise/Wünsche zum Termin. Der Partner sieht diese vor dem Job.</p>
            <textarea x-model="messageText" rows="4" class="w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-brand focus:border-brand outline-none" placeholder="z.B. Klingeln Sie zweimal, Schlüssel liegt im Briefkasten..."></textarea>
            <div class="flex gap-2 mt-4">
              <button type="button" @click="messageJobId = null" class="flex-1 px-4 py-2 border rounded-lg text-gray-700 text-sm">Abbrechen</button>
              <button type="button" @click="saveMessage()" class="flex-1 px-4 py-2 bg-brand hover:bg-brand-dark text-white rounded-lg text-sm font-semibold">Speichern</button>
            </div>
          </div>
        </div>

        <!-- BOOKING BUTTON — always visible at bottom -->
        <div class="mt-4 pt-4 border-t border-gray-100">
          <a :href="'/customer/booking.php?date=' + (selectedDateIso || '')"
             class="w-full flex items-center justify-center gap-2 px-5 py-3 bg-brand hover:bg-brand-dark text-white rounded-xl font-bold text-sm transition shadow-lg shadow-brand/20">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            <span x-text="selected?.length > 0 ? 'Weitere Reinigung buchen' : 'Reinigung buchen'"></span>
          </a>
          <p class="text-[10px] text-gray-400 text-center mt-2" x-text="'Datum ' + selectedDate + ' wird automatisch vorausgefüllt'"></p>
        </div>

      </div>
    </div>
  </div>
</div>
