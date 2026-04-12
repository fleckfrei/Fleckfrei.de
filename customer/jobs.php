<?php
require_once __DIR__ . '/../includes/auth.php';
requireCustomer();
if (!customerCan('jobs')) { header('Location: /customer/'); exit; }
$title = 'Meine Termine'; $page = 'jobs';
$cid = me()['id'];

// ============================================================
// POST handlers — job management actions
// ============================================================
function ownJob(int $jid, int $cid): ?array {
    return one("SELECT * FROM jobs WHERE j_id=? AND customer_id_fk=? AND status=1", [$jid, $cid]) ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { header('Location: /customer/jobs.php?error=csrf'); exit; }
    $action = $_POST['action'] ?? '';
    $jid = (int)($_POST['j_id'] ?? 0);
    $job = $jid ? ownJob($jid, $cid) : null;

    // Job must be in the future for reschedule/cancel/edit
    $isPastJob = $job && $job['j_date'] < date('Y-m-d');
    if ($job && $isPastJob && in_array($action, ['reschedule', 'cancel', 'edit'])) {
        header('Location: /customer/jobs.php?error=locked_past'); exit;
    }

    if ($job && $action === 'reschedule') {
        $newDate = $_POST['new_date'] ?? '';
        $newTime = $_POST['new_time'] ?? '';
        $reason = trim($_POST['reschedule_reason'] ?? '');
        $note = trim($_POST['reschedule_note'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDate) && preg_match('/^\d{2}:\d{2}$/', $newTime)) {
            if ($newDate < date('Y-m-d')) {
                header('Location: /customer/jobs.php?error=past_date'); exit;
            }
            $oldLabel = date('d.m.Y', strtotime($job['j_date'])) . ' ' . substr($job['j_time'], 0, 5);
            // Append note to job_note if provided
            $combinedNote = $job['job_note'] ?: '';
            if ($note !== '') {
                $combinedNote = trim(($combinedNote ? $combinedNote . "\n" : '') . "[Umbuchung] $note");
            }
            q("UPDATE jobs SET j_date=?, j_time=?, job_note=?, updated_at=NOW() WHERE j_id=?", [$newDate, $newTime . ':00', $combinedNote, $jid]);
            audit('update', 'jobs', $jid, "Umgebucht: $oldLabel → " . date('d.m.Y', strtotime($newDate)) . " $newTime" . ($reason ? " (Grund: $reason)" : ''));
            telegramNotify("📅 Kunde #$cid hat Job #$jid umgebucht: $oldLabel → " . date('d.m.Y', strtotime($newDate)) . " $newTime" . ($reason ? " | $reason" : ''));
            header('Location: /customer/jobs.php?saved=reschedule'); exit;
        }
        header('Location: /customer/jobs.php?error=invalid_date'); exit;
    }

    if ($job && $action === 'cancel') {
        $reason = trim($_POST['reason'] ?? '');
        $googleOffer = !empty($_POST['google_review_offer']);
        if (!in_array($job['job_status'], ['PENDING', 'CONFIRMED'])) {
            header('Location: /customer/jobs.php?error=cannot_cancel'); exit;
        }
        $fullReason = ($reason ?: 'Vom Kunden storniert') . ($googleOffer ? ' [Google-Review-Angebot]' : '');
        q("UPDATE jobs SET job_status='CANCELLED', cancel_date=NOW(), cancelled_role='customer', cancelled_by=?, j_c_val=?, updated_at=NOW() WHERE j_id=?",
          [$cid, $fullReason, $jid]);
        audit('cancel', 'jobs', $jid, "Storniert vom Kunden. Grund: " . $fullReason);
        $emoji = $googleOffer ? '⭐✕' : '✕';
        telegramNotify("$emoji Kunde #$cid hat Job #$jid storniert. " . $fullReason);
        header('Location: /customer/jobs.php?saved=' . ($googleOffer ? 'cancel_google' : 'cancel')); exit;
    }

    if ($job && $action === 'edit') {
        $isCheckOnly = !empty($_POST['is_check_only']);
        $newPeople = $isCheckOnly ? 1 : max(1, min(20, (int)($_POST['no_people'] ?? 1)));
        $newChildren = max(0, min(20, (int)($_POST['no_children'] ?? 0)));
        $newPets = max(0, min(10, (int)($_POST['no_pets'] ?? 0)));
        $newHours = !empty($_POST['j_hours']) ? max(MIN_HOURS, min(12, (float)$_POST['j_hours'])) : null;
        $hasSeparateBeds = !empty($_POST['has_separate_beds']) ? 1 : 0;
        $hasSofaBed = !empty($_POST['has_sofa_bed']) ? 1 : 0;
        $extrasNote = trim(mb_substr($_POST['extras_note'] ?? '', 0, 500));
        $newNote = trim($_POST['job_note'] ?? '');
        $newEmpMsg = trim(mb_substr($_POST['emp_message'] ?? '', 0, 500));
        $newCode = trim($_POST['code_door'] ?? '');
        $newAddress = trim($_POST['address'] ?? $job['address']);
        // Gast-Zeiten (für Host/Airbnb-Kunden)
        $guestCheckoutDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['guest_checkout_date'] ?? '') ? $_POST['guest_checkout_date'] : null;
        $guestCheckoutTime = preg_match('/^\d{2}:\d{2}/', $_POST['guest_checkout_time'] ?? '') ? substr($_POST['guest_checkout_time'], 0, 5) . ':00' : null;
        $checkInDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['check_in_date'] ?? '') ? $_POST['check_in_date'] : null;
        $checkInTime = preg_match('/^\d{2}:\d{2}/', $_POST['check_in_time'] ?? '') ? substr($_POST['check_in_time'], 0, 5) . ':00' : null;
        // Optional products
        $optProducts = is_array($_POST['optional_products'] ?? null) ? implode(', ', array_map('trim', $_POST['optional_products'])) : '';

        $sql = "UPDATE jobs SET no_people=?, no_children=?, no_pets=?, has_separate_beds=?, has_sofa_bed=?, extras_note=?, job_note=?, emp_message=?, code_door=?, address=?, is_check_only=?, guest_checkout_date=?, guest_checkout_time=?, check_in_date=?, check_in_time=?, optional_products=?";
        $params = [$newPeople, $newChildren, $newPets, $hasSeparateBeds, $hasSofaBed, $extrasNote, $newNote, $newEmpMsg, $newCode, $newAddress, $isCheckOnly ? 1 : 0, $guestCheckoutDate, $guestCheckoutTime, $checkInDate, $checkInTime, $optProducts];
        if ($newHours !== null) { $sql .= ", j_hours=?"; $params[] = $newHours; }
        $sql .= ", updated_at=NOW() WHERE j_id=?";
        $params[] = $jid;
        q($sql, $params);

        audit('update', 'jobs', $jid, 'Job-Details vom Kunden geändert' . ($isCheckOnly ? ' (Kontrolle)' : ''));
        header('Location: /customer/jobs.php?saved=edit'); exit;
    }

    // Reklamation — 24-48h window after completion
    if ($job && $action === 'reklamation') {
        if ($job['job_status'] !== 'COMPLETED') {
            header('Location: /customer/jobs.php?error=cannot_reklamation'); exit;
        }
        $completedAt = $job['completed_at'] ?: $job['updated_at'];
        $hoursSince = (time() - strtotime($completedAt)) / 3600;
        if ($hoursSince < 24 || $hoursSince > 48) {
            header('Location: /customer/jobs.php?error=reklamation_window'); exit;
        }
        $text = trim($_POST['reklamation_text'] ?? '');
        if ($text === '') { header('Location: /customer/jobs.php?error=empty_reklamation'); exit; }
        q("UPDATE jobs SET reklamation_text=?, reklamation_at=NOW(), updated_at=NOW() WHERE j_id=?", [$text, $jid]);
        audit('reklamation', 'jobs', $jid, 'Reklamation eingereicht');
        telegramNotify("⚠ REKLAMATION Kunde #$cid Job #$jid: $text");
        header('Location: /customer/jobs.php?tab=vergangenheit&saved=reklamation'); exit;
    }
}

// Filter state
$tab = $_GET['tab'] ?? 'zukunft'; // 'vergangenheit' | 'zukunft'
$showCancelled = !empty($_GET['cancelled']);
$groupBy = $_GET['group'] ?? 'date'; // 'date' | 'property'
$today = date('Y-m-d');
$saved = $_GET['saved'] ?? '';
$error = $_GET['error'] ?? '';

// Customer's saved addresses (for multi-location dropdown in edit modal)
try {
    $customerAddresses = all("SELECT ca_id, street, number, postal_code, city, country, address_for FROM customer_address WHERE customer_id_fk=? ORDER BY ca_id DESC", [$cid]);
} catch (Exception $e) { $customerAddresses = []; }

// ============================================================
// Partner availability for next 60 days — pre-computed, no API
// ============================================================
$totalPartners = max(1, (int) val("SELECT COUNT(*) FROM employee WHERE status=1"));
$busyByDate = [];
$busyRows = all("
    SELECT j_date, COUNT(DISTINCT emp_id_fk) AS busy
    FROM jobs
    WHERE j_date BETWEEN ? AND DATE_ADD(?, INTERVAL 60 DAY)
      AND status = 1
      AND emp_id_fk IS NOT NULL
      AND job_status NOT IN ('CANCELLED','COMPLETED')
    GROUP BY j_date
", [$today, $today]);
foreach ($busyRows as $r) $busyByDate[$r['j_date']] = (int)$r['busy'];

// External bookings (from iCal feeds) — block these dates from rescheduling
$externalBlockDates = [];
try {
    $extRows = all("
        SELECT start_date, end_date, guest_name, source_platform
        FROM external_events
        WHERE customer_id_fk = ?
          AND end_date >= CURDATE()
    ", [$cid]);
    foreach ($extRows as $r) {
        $cur = strtotime($r['start_date']);
        $endTs = strtotime($r['end_date']);
        while ($cur <= $endTs) {
            $d = date('Y-m-d', $cur);
            $externalBlockDates[$d] = [
                'guest' => $r['guest_name'] ?: 'Externes Booking',
                'platform' => $r['source_platform'] ?: 'iCal',
            ];
            $cur = strtotime('+1 day', $cur);
        }
    }
} catch (Exception $e) {}

$availability = [];
for ($i = 0; $i < 60; $i++) {
    $d = date('Y-m-d', strtotime("+$i days"));
    $busy = $busyByDate[$d] ?? 0;
    $free = max(0, $totalPartners - $busy);
    $hasExt = isset($externalBlockDates[$d]);
    $availability[$d] = [
        'free' => $free,
        'total' => $totalPartners,
        'ok' => $free > 0 && !$hasExt,
        'blocked_external' => $hasExt,
        'guest' => $hasExt ? $externalBlockDates[$d]['guest'] : null,
        'platform' => $hasExt ? $externalBlockDates[$d]['platform'] : null,
    ];
}

// Query jobs based on tab + cancelled filter
$cancelledClause = $showCancelled ? "" : " AND j.job_status != 'CANCELLED' ";
if ($tab === 'zukunft') {
    $jobs = all("
        SELECT j.*, s.title as stitle, s.street, s.number AS s_number, s.postal_code AS s_postal, s.city, s.total_price, s.box_code AS s_box_code,
               e.display_name as edisplay, e.profile_pic as eavatar, e.phone as ephone,
               ev.start_date AS ext_checkin, ev.end_date AS ext_checkout,
               ev.source_platform AS ext_platform, ev.description AS ext_desc,
               f.label AS ext_property
        FROM jobs j
        LEFT JOIN services s ON j.s_id_fk = s.s_id
        LEFT JOIN employee e ON j.emp_id_fk = e.emp_id
        LEFT JOIN job_suggestions sg ON sg.resulting_job_id = j.j_id
        LEFT JOIN external_events ev ON ev.ev_id = sg.external_event_id
        LEFT JOIN ical_feeds f ON f.id = ev.ical_feed_id
        WHERE j.customer_id_fk = ?
          AND j.j_date >= ?
          AND j.status = 1
          $cancelledClause
        ORDER BY j.j_date ASC, j.j_time ASC
    ", [$cid, $today]);
} else {
    $jobs = all("
        SELECT j.*, s.title as stitle, s.street, s.number AS s_number, s.postal_code AS s_postal, s.city, s.total_price, s.box_code AS s_box_code,
               e.display_name as edisplay, e.profile_pic as eavatar, e.phone as ephone,
               ev.start_date AS ext_checkin, ev.end_date AS ext_checkout,
               ev.source_platform AS ext_platform, ev.description AS ext_desc,
               f.label AS ext_property
        FROM jobs j
        LEFT JOIN services s ON j.s_id_fk = s.s_id
        LEFT JOIN employee e ON j.emp_id_fk = e.emp_id
        LEFT JOIN job_suggestions sg ON sg.resulting_job_id = j.j_id
        LEFT JOIN external_events ev ON ev.ev_id = sg.external_event_id
        LEFT JOIN ical_feeds f ON f.id = ev.ical_feed_id
        WHERE j.customer_id_fk = ?
          AND j.j_date < ?
          AND j.status = 1
          $cancelledClause
        ORDER BY j.j_date DESC, j.j_time DESC
        LIMIT 100
    ", [$cid, $today]);
}

// Status color mapping
function statusColor(string $s): array {
    return match ($s) {
        'PENDING'   => ['bg-amber-50', 'text-amber-700', 'border-amber-200', 'Ausstehend'],
        'CONFIRMED' => ['bg-blue-50',  'text-blue-700',  'border-blue-200',  'Bestätigt'],
        'STARTED', 'RUNNING' => ['bg-purple-50', 'text-purple-700', 'border-purple-200', 'Läuft'],
        'COMPLETED' => ['bg-green-50', 'text-green-700', 'border-green-200', 'Erledigt'],
        'CANCELLED' => ['bg-red-50',   'text-red-700',   'border-red-200',   'Storniert'],
        default => ['bg-gray-50', 'text-gray-700', 'border-gray-200', $s],
    };
}

// German weekday
function weekdayDe(string $date): string {
    static $days = ['Sonntag','Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag'];
    return $days[(int) date('w', strtotime($date))];
}
function monthDe(string $date): string {
    static $months = [1=>'Jan', 2=>'Feb', 3=>'Mär', 4=>'Apr', 5=>'Mai', 6=>'Jun', 7=>'Jul', 8=>'Aug', 9=>'Sep', 10=>'Okt', 11=>'Nov', 12=>'Dez'];
    return $months[(int) date('n', strtotime($date))];
}

// Extract key fields from iCal description (Smoobu-formatted)
function parseIcalDesc(string $desc): array {
    $out = ['nights' => null, 'adults' => null, 'children' => null, 'portal' => null, 'price' => null];
    if (preg_match('/Nights:\s*(\d+)/i', $desc, $m)) $out['nights'] = (int)$m[1];
    if (preg_match('/Adults:\s*(\d+)/i', $desc, $m)) $out['adults'] = (int)$m[1];
    if (preg_match('/Children:\s*(\d+)/i', $desc, $m)) $out['children'] = (int)$m[1];
    if (preg_match('/Portal:\s*([^\n\r]+)/i', $desc, $m)) $out['portal'] = trim($m[1]);
    if (preg_match('/Price:\s*([\d.,]+)/i', $desc, $m)) $out['price'] = $m[1];
    return $out;
}

// Group jobs by service (Smoobu-folder-like structure)
function groupJobsByProperty(array $jobs): array {
    $groups = [];
    foreach ($jobs as $j) {
        $key = $j['s_id_fk'] ?: 0;
        $title = $j['stitle'] ?: 'Andere Unterkünfte';
        if (!isset($groups[$key])) $groups[$key] = ['title' => $title, 'jobs' => []];
        $groups[$key]['jobs'][] = $j;
    }
    return $groups;
}

// Countdown helper
function countdown(string $date, string $time): string {
    $target = strtotime("$date $time");
    $diff = $target - time();
    if ($diff < 0) return '';
    $days = floor($diff / 86400);
    $hours = floor(($diff % 86400) / 3600);
    if ($days > 0) return "in $days Tag" . ($days > 1 ? 'en' : '');
    if ($hours > 0) return "in $hours Std";
    $mins = floor(($diff % 3600) / 60);
    return "in $mins Min";
}

// Pre-load checklist data for all jobs (avoid N+1 queries)
$allServiceIds = array_unique(array_filter(array_column($jobs, 's_id_fk')));
$allJobIds = array_column($jobs, 'j_id');
$checklistByService = [];
$completionsByJob = [];
if (!empty($allServiceIds)) {
    $sidList = implode(',', array_map('intval', $allServiceIds));
    $clItems = all("SELECT checklist_id, s_id_fk, title, priority FROM service_checklists WHERE s_id_fk IN ($sidList) AND is_active=1 ORDER BY position");
    foreach ($clItems as $cl) $checklistByService[(int)$cl['s_id_fk']][] = $cl;
}
if (!empty($allJobIds)) {
    $jidList = implode(',', array_map('intval', $allJobIds));
    $comps = all("SELECT job_id_fk, checklist_id_fk, completed, photo FROM checklist_completions WHERE job_id_fk IN ($jidList)");
    foreach ($comps as $c) $completionsByJob[(int)$c['job_id_fk']][(int)$c['checklist_id_fk']] = $c;
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
  <a href="/customer/calendar.php" class="px-4 py-2 rounded-lg text-sm font-semibold text-gray-600 hover:bg-gray-50 flex items-center gap-1.5">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
    Kalender
  </a>
  <a href="/customer/jobs.php" class="px-4 py-2 rounded-lg text-sm font-semibold bg-brand text-white flex items-center gap-1.5">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
    Liste
  </a>
</div>

<!-- Page header -->
<div class="flex items-start justify-between mb-6 flex-wrap gap-4">
  <div>
    <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Meine Termine</h1>
    <p class="text-gray-500 mt-1 text-sm">Alle Reinigungstermine und deren Status im Überblick.</p>
  </div>
  <a href="/customer/booking.php" class="inline-flex items-center gap-2 px-5 py-3 bg-brand hover:bg-brand-dark text-white rounded-xl font-semibold text-sm shadow-sm transition">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
    Neuer Termin
  </a>
</div>

<!-- View controls: Group + Show cancelled -->
<div class="mb-4 flex items-center gap-2 flex-wrap">
  <!-- Group-by toggle -->
  <div class="inline-flex bg-white border border-gray-200 rounded-lg p-1 text-xs">
    <a href="?tab=<?= $tab ?>&group=date<?= $showCancelled ? '&cancelled=1' : '' ?>" class="px-3 py-1.5 rounded-md font-semibold <?= $groupBy === 'date' ? 'bg-brand text-white' : 'text-gray-600 hover:bg-gray-50' ?>">
      📅 Nach Datum
    </a>
    <a href="?tab=<?= $tab ?>&group=property<?= $showCancelled ? '&cancelled=1' : '' ?>" class="px-3 py-1.5 rounded-md font-semibold <?= $groupBy === 'property' ? 'bg-brand text-white' : 'text-gray-600 hover:bg-gray-50' ?>">
      🏠 Nach Unterkunft
    </a>
  </div>

  <a href="?tab=<?= $tab ?>&group=<?= $groupBy ?>&cancelled=<?= $showCancelled ? '0' : '1' ?>"
     class="inline-flex items-center gap-2 px-4 py-2 <?= $showCancelled ? 'bg-brand-light text-brand-dark border-brand' : 'bg-white text-gray-600 border-gray-200' ?> border rounded-lg text-xs font-medium hover:bg-brand-light hover:text-brand-dark hover:border-brand transition">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <?php if ($showCancelled): ?>
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
      <?php else: ?>
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.542 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
      <?php endif; ?>
    </svg>
    Abgesagte Termine <?= $showCancelled ? 'ausblenden' : 'anzeigen' ?>
  </a>
</div>

<!-- Tabs -->
<div class="border-b mb-6">
  <div class="flex gap-8">
    <a href="?tab=vergangenheit<?= $showCancelled ? '&cancelled=1' : '' ?>" class="tab-underline <?= $tab === 'vergangenheit' ? 'active' : '' ?>">
      Vergangenheit
    </a>
    <a href="?tab=zukunft<?= $showCancelled ? '&cancelled=1' : '' ?>" class="tab-underline <?= $tab === 'zukunft' ? 'active' : '' ?>">
      Zukunft
    </a>
  </div>
</div>

<?php if ($saved): ?>
<div class="mb-4 card-elev border-green-200 bg-green-50 p-4 text-sm text-green-800 flex items-center gap-2">
  <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
  <?= match($saved) {
      'reschedule'    => 'Termin wurde umgebucht.',
      'cancel'        => 'Termin wurde storniert.',
      'cancel_google' => '✓ Storniert. Bitte hinterlassen Sie jetzt eine Google-Bewertung — nach Prüfung erlassen wir Ihnen die Storno-Gebühr.',
      'edit'          => 'Änderungen gespeichert.',
      'reklamation'   => 'Reklamation eingereicht. Wir melden uns zeitnah bei Ihnen.',
      default         => 'Gespeichert.',
  } ?>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="mb-4 card-elev border-red-200 bg-red-50 p-4 text-sm text-red-800">
  <?= match($error) {
      'past_date'           => 'Das Datum darf nicht in der Vergangenheit liegen.',
      'invalid_date'        => 'Ungültiges Datum oder Uhrzeit.',
      'cannot_cancel'       => 'Dieser Termin kann nicht mehr storniert werden.',
      'locked_past'         => 'Vergangene Termine können nicht mehr bearbeitet werden.',
      'cannot_reklamation'  => 'Reklamation nicht möglich für diesen Job.',
      'reklamation_window'  => 'Reklamationsfenster vorbei. Reklamationen sind zwischen 24h und 48h nach Fertigstellung möglich.',
      'empty_reklamation'   => 'Bitte beschreiben Sie den Grund der Reklamation.',
      default               => 'Es ist ein Fehler aufgetreten.',
  } ?>
</div>
<?php endif; ?>

<?php if (empty($jobs)): ?>

<!-- EMPTY STATE -->
<div class="card-elev text-center py-16 px-4">
  <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-brand-light mb-5">
    <svg class="w-10 h-10 text-brand" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
  </div>
  <h3 class="text-lg font-semibold text-gray-900 mb-2">
    <?= $tab === 'zukunft' ? 'Keine anstehenden Termine' : 'Keine vergangenen Termine' ?>
  </h3>
  <p class="text-sm text-gray-500 max-w-md mx-auto mb-6">
    <?php if ($tab === 'zukunft'): ?>
      Buchen Sie jetzt Ihren nächsten Reinigungstermin. Unsere Partner stehen bereit.
    <?php else: ?>
      Hier erscheinen Ihre abgeschlossenen Termine sobald Sie einen ersten Auftrag hatten.
    <?php endif; ?>
  </p>
  <?php if ($tab === 'zukunft'): ?>
  <a href="/customer/booking.php" class="inline-flex items-center gap-2 px-6 py-3 bg-brand hover:bg-brand-dark text-white rounded-xl font-semibold text-sm shadow-sm transition">
    Ersten Termin buchen
  </a>
  <?php endif; ?>
</div>

<?php else: ?>

<?php
// Auto-open Edit-Modal via ?edit=JOBID
$autoEditId = (int)($_GET['edit'] ?? 0);
$autoEditJob = null;
if ($autoEditId) {
    $autoEditJob = one("SELECT j.*, s.title as stitle, s.box_code as service_code,
        CONCAT_WS(' ', s.street, s.number, s.postal_code, s.city) as service_address
        FROM jobs j LEFT JOIN services s ON j.s_id_fk=s.s_id
        WHERE j.j_id=? AND j.customer_id_fk=? AND j.status=1", [$autoEditId, $cid]);
}
?>

<!-- JOB CARDS -->
<div class="grid gap-4" x-data='{
  modal: <?= $autoEditJob ? '"edit"' : 'null' ?>,
  modalJob: <?= $autoEditJob ? json_encode($autoEditJob, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) : 'null' ?>,
  selectedDate: "",
  availability: <?= json_encode($availability, JSON_UNESCAPED_UNICODE) ?>,
  availInfo() {
    if (!this.selectedDate) return null;
    return this.availability[this.selectedDate] || { free: 0, total: <?= $totalPartners ?>, ok: false };
  }
}'>
<?php
// Sort jobs by property if group-mode = property (keeps simple flat loop)
if ($groupBy === 'property') {
    usort($jobs, function($a, $b) {
        $keyA = ($a['s_id_fk'] ?? 0) . ' ' . ($a['stitle'] ?? '');
        $keyB = ($b['s_id_fk'] ?? 0) . ' ' . ($b['stitle'] ?? '');
        return strcmp($keyA, $keyB) ?: strcmp($a['j_date'], $b['j_date']);
    });
}
$lastGroupKey = null;
?>
<?php foreach ($jobs as $j):
    // Emit group header when grouping by property
    if ($groupBy === 'property') {
        $groupKey = $j['s_id_fk'] ?: 0;
        if ($groupKey !== $lastGroupKey) {
            $lastGroupKey = $groupKey;
            $propTitle = $j['stitle'] ?: 'Andere Unterkünfte';
            echo '<div class="flex items-center gap-2 mb-1 mt-4 first:mt-0"><div class="w-8 h-8 rounded-lg bg-brand/10 flex items-center justify-center text-lg">🏠</div><h3 class="font-bold text-gray-900 text-base">' . e($propTitle) . '</h3></div>';
        }
    }
    [$badgeBg, $badgeText, $badgeBorder, $badgeLabel] = statusColor($j['job_status']);
    $cd = $tab === 'zukunft' ? countdown($j['j_date'], $j['j_time']) : '';
    $isFuture  = $j['j_date'] >= date('Y-m-d');
    $canEdit   = $isFuture && in_array($j['job_status'], ['PENDING', 'CONFIRMED']);
    $canCancel = customerCan('cancel') && $canEdit;
    // Notes are always editable — Customer can add a note even to running/completed jobs
    $canNote   = $j['job_status'] !== 'CANCELLED';

    // Time-based rating/reklamation windows (professional policy)
    $canRate = false;
    $canReklamation = false;
    $hasReklamation = !empty($j['reklamation_text']);
    $completedAt = $j['completed_at'] ?: $j['updated_at'];
    if ($j['job_status'] === 'COMPLETED' && $completedAt) {
        $hoursSince = (time() - strtotime($completedAt)) / 3600;
        $canRate = $hoursSince < 24;
        $canReklamation = $hoursSince >= 24 && $hoursSince < 48 && !$hasReklamation;
    }
    // Service-address: join from services record (the job belongs ONLY to this one service)
    $serviceAddress = trim(trim(($j['street'] ?? '') . ' ' . ($j['s_number'] ?? '')) . ', ' . trim(($j['s_postal'] ?? '') . ' ' . ($j['city'] ?? '')), ', ');
    $jobJson = htmlspecialchars(json_encode([
        'j_id' => (int)$j['j_id'],
        'j_date' => $j['j_date'],
        'j_time' => substr($j['j_time'] ?? '', 0, 5),
        'no_people' => (int)($j['no_people'] ?? 1),
        'hours' => max(MIN_HOURS, (float)($j['total_hours'] ?: $j['j_hours'] ?: MIN_HOURS)),
        'no_children' => (int)($j['no_children'] ?? 0),
        'no_pets' => (int)($j['no_pets'] ?? 0),
        'has_separate_beds' => (int)($j['has_separate_beds'] ?? 0),
        'has_sofa_bed' => (int)($j['has_sofa_bed'] ?? 0),
        'extras_note' => $j['extras_note'] ?? '',
        'address' => $j['address'] ?? '',
        'service_address' => $serviceAddress,  // nur diese eine Adresse, gehört zum Service
        'code_door' => $j['code_door'] ?? '',
        'service_code' => $j['s_box_code'] ?? '',  // master-code aus services, nicht überschrieben
        'job_note' => $j['job_note'] ?? '',
        'stitle' => $j['stitle'] ?? 'Reinigung',
        'ical_adults' => (int)($icalInfo['adults'] ?? 0) ?: null,
        'ical_children' => (int)($icalInfo['children'] ?? 0) ?: null,
    ]), ENT_QUOTES);
?>
<div class="card-elev p-5 flex flex-col sm:flex-row gap-5 transition">

  <!-- Date block left (Helpling-style prominent date) -->
  <div class="flex-shrink-0 flex sm:flex-col items-center sm:items-start gap-3 sm:gap-0 sm:w-24">
    <div class="text-center sm:text-left sm:px-3 sm:py-3 sm:bg-brand-light sm:rounded-xl sm:w-full">
      <div class="text-[11px] uppercase font-bold tracking-wider text-brand"><?= substr(weekdayDe($j['j_date']), 0, 2) ?></div>
      <div class="text-3xl font-extrabold text-gray-900 leading-none my-0.5"><?= (int) date('d', strtotime($j['j_date'])) ?></div>
      <div class="text-[11px] uppercase text-gray-500 font-semibold"><?= monthDe($j['j_date']) ?> <?= date('Y', strtotime($j['j_date'])) ?></div>
    </div>
    <?php if ($cd): ?>
      <div class="sm:mt-2 text-[11px] text-brand font-semibold text-center sm:w-full">⏱ <?= $cd ?></div>
    <?php endif; ?>
  </div>

  <!-- Main info -->
  <div class="flex-1 min-w-0">
    <div class="flex items-start justify-between gap-3 mb-2">
      <div class="min-w-0">
        <h3 class="font-bold text-gray-900 text-base sm:text-lg truncate"><?= e($j['stitle'] ?: 'Reinigungsservice') ?></h3>
        <div class="flex items-center gap-2 mt-1 text-xs text-gray-500">
          <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          <span><?= substr($j['j_time'], 0, 5) ?> · <?= max(MIN_HOURS, (float) ($j['total_hours'] ?: $j['j_hours'])) ?> Std</span>
        </div>
      </div>
      <span class="inline-flex items-center px-2.5 py-1 rounded-full border <?= $badgeBg ?> <?= $badgeText ?> <?= $badgeBorder ?> text-[11px] font-semibold whitespace-nowrap"><?= $badgeLabel ?></span>
    </div>

    <?php if (!empty($j['address']) || !empty($j['street'])): ?>
    <div class="flex items-start gap-2 mt-2 text-xs text-gray-600">
      <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
      <span class="truncate"><?= e($j['address'] ?: trim(($j['street'] ?? '') . ' ' . ($j['city'] ?? ''))) ?></span>
    </div>
    <?php endif; ?>

    <?php if (!empty($j['emp_id_fk'])):
      $pName = partnerDisplayName($j);
      $pAvatar = partnerAvatarUrl($j);
      $pInitial = partnerInitial($j);
      $hasName = !empty($j['edisplay']);
    ?>
    <div class="flex items-center gap-2 mt-2">
      <?php if ($pAvatar): ?>
      <img src="<?= e($pAvatar) ?>" alt="" class="w-7 h-7 rounded-full object-cover border border-gray-200"/>
      <?php else: ?>
      <div class="w-7 h-7 rounded-full bg-gradient-to-br from-brand to-brand-dark text-white flex items-center justify-center text-xs font-bold">
        <?= e($pInitial) ?>
      </div>
      <?php endif; ?>
      <div class="text-xs">
        <?php if ($hasName): ?>
          <span class="text-gray-500">Ihr Partner:</span>
          <a href="/customer/partner.php?id=<?= (int)$j['emp_id_fk'] ?>" class="font-semibold text-brand hover:underline"><?= e($pName) ?></a>
        <?php else: ?>
          <a href="/customer/partner.php?id=<?= (int)$j['emp_id_fk'] ?>" class="font-semibold text-brand hover:underline">Ihr Partner</a>
          <span class="text-gray-400 ml-1">zugewiesen</span>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php
    // Extras Summary — nur wenn mindestens eins gesetzt ist
    $hasExtras = !empty($j['no_children']) || !empty($j['no_pets']) || !empty($j['has_separate_beds']) || !empty($j['has_sofa_bed']) || !empty($j['extras_note']);
    if ($hasExtras): ?>
    <div class="mt-3 flex flex-wrap gap-1.5 text-[11px]">
      <?php if (!empty($j['no_children'])): ?><span class="px-2 py-0.5 bg-blue-50 border border-blue-200 text-blue-800 rounded-full font-semibold">👶 <?= (int)$j['no_children'] ?> Kind<?= (int)$j['no_children'] === 1 ? '' : 'er' ?></span><?php endif; ?>
      <?php if (!empty($j['no_pets'])): ?><span class="px-2 py-0.5 bg-amber-50 border border-amber-200 text-amber-800 rounded-full font-semibold">🐾 <?= (int)$j['no_pets'] ?> Tier<?= (int)$j['no_pets'] === 1 ? '' : 'e' ?></span><?php endif; ?>
      <?php if (!empty($j['has_separate_beds'])): ?><span class="px-2 py-0.5 bg-indigo-50 border border-indigo-200 text-indigo-800 rounded-full font-semibold">🛏️🛏️ Getrennte Betten</span><?php endif; ?>
      <?php if (!empty($j['has_sofa_bed'])): ?><span class="px-2 py-0.5 bg-purple-50 border border-purple-200 text-purple-800 rounded-full font-semibold">🛋️ Sofa-Bett</span><?php endif; ?>
      <?php if (!empty($j['extras_note'])): ?><span class="px-2 py-0.5 bg-gray-100 border border-gray-200 text-gray-700 rounded-full italic truncate max-w-[300px]">💬 <?= e($j['extras_note']) ?></span><?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($canEdit): ?>
    <!-- Inline quick-edit: door code + note -->
    <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-2" x-data="{ editCode: false, editNote: false, code: '<?= e($j['code_door'] ?? '') ?>', note: <?= htmlspecialchars(json_encode($j['job_note'] ?? ''), ENT_QUOTES) ?>, saving: false,
      async saveField(field) {
        this.saving = true;
        const fd = new FormData();
        fd.append('_csrf', '<?= csrfToken() ?>');
        fd.append('action', 'edit');
        fd.append('j_id', '<?= (int)$j['j_id'] ?>');
        fd.append('code_door', this.code);
        fd.append('job_note', this.note);
        fd.append('no_people', '<?= (int)($j['no_people'] ?? 1) ?>');
        fd.append('j_hours', '<?= max(MIN_HOURS, (float)($j['total_hours'] ?: $j['j_hours'] ?: MIN_HOURS)) ?>');
        fd.append('address', '<?= e($j['address'] ?? '') ?>');
        await fetch('/customer/jobs.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        this.editCode = false; this.editNote = false; this.saving = false;
      }
    }">
      <div class="flex items-center gap-2 text-xs">
        <svg class="w-3.5 h-3.5 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
        <template x-if="!editCode">
          <span class="flex items-center gap-1 cursor-pointer hover:text-brand" @click="editCode = true">
            <span x-text="code || 'Türcode'" :class="code ? 'text-gray-700 font-medium' : 'text-gray-400'"></span>
            <svg class="w-3 h-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
          </span>
        </template>
        <template x-if="editCode">
          <div class="flex items-center gap-1">
            <input type="text" x-model="code" placeholder="Türcode" class="w-24 px-2 py-1 border border-brand rounded text-xs" @keydown.enter="saveField('code')" @keydown.escape="editCode = false" x-ref="codeInput" x-init="$nextTick(() => $refs.codeInput?.focus())"/>
            <button @click="saveField('code')" :disabled="saving" class="text-brand font-bold text-[10px]">OK</button>
          </div>
        </template>
      </div>
      <div class="flex items-start gap-2 text-xs">
        <svg class="w-3.5 h-3.5 text-gray-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
        <template x-if="!editNote">
          <span class="flex items-center gap-1 cursor-pointer hover:text-brand" @click="editNote = true">
            <span x-text="note || 'Notiz hinzufügen'" :class="note ? 'text-gray-700' : 'text-gray-400'" class="italic truncate max-w-[200px]"></span>
            <svg class="w-3 h-3 text-gray-300 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
          </span>
        </template>
        <template x-if="editNote">
          <div class="flex-1">
            <textarea x-model="note" rows="2" class="w-full px-2 py-1 border border-brand rounded text-xs" placeholder="Hinweis für den Partner..." @keydown.escape="editNote = false"></textarea>
            <div class="flex gap-1 mt-1">
              <button @click="saveField('note')" :disabled="saving" class="px-2 py-0.5 bg-brand text-white rounded text-[10px] font-bold">Speichern</button>
              <button @click="editNote = false" class="px-2 py-0.5 text-gray-500 text-[10px]">Abbrechen</button>
            </div>
          </div>
        </template>
      </div>
    </div>
    <?php elseif (!empty($j['job_note'])): ?>
    <div class="mt-3 text-xs text-gray-600 bg-gray-50 border-l-2 border-gray-300 pl-3 py-1.5 italic">
      <?= e($j['job_note']) ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($j['ext_checkin']) || !empty($j['ext_platform'])):
      $icalInfo = parseIcalDesc($j['ext_desc'] ?? '');
      $platformColors = [
          'Airbnb' => 'bg-red-100 text-red-700 border-red-200',
          'Booking.com' => 'bg-blue-100 text-blue-700 border-blue-200',
          'Smoobu' => 'bg-purple-100 text-purple-700 border-purple-200',
          'VRBO' => 'bg-yellow-100 text-yellow-700 border-yellow-200',
      ];
      $platformClass = $platformColors[$j['ext_platform']] ?? 'bg-gray-100 text-gray-700 border-gray-200';
    ?>
    <div class="mt-3 p-3 rounded-lg border <?= $platformClass ?>">
      <div class="flex items-center justify-between flex-wrap gap-2 mb-2">
        <div class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider">
          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
          Gast-Buchung via <?= e($j['ext_platform'] ?? 'iCal') ?>
        </div>
        <?php if ($j['ext_property']): ?><span class="text-[10px] opacity-75"><?= e($j['ext_property']) ?></span><?php endif; ?>
      </div>
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-[11px]">
        <?php if ($j['ext_checkin']): ?>
        <div>
          <div class="text-[9px] opacity-60 uppercase font-semibold">↓ Check-in</div>
          <div class="font-semibold"><?= date('d.m.', strtotime($j['ext_checkin'])) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($j['ext_checkout']): ?>
        <div>
          <div class="text-[9px] opacity-60 uppercase font-semibold">↑ Check-out</div>
          <div class="font-semibold"><?= date('d.m.', strtotime($j['ext_checkout'])) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($icalInfo['nights']): ?>
        <div>
          <div class="text-[9px] opacity-60 uppercase font-semibold">🌙 Nächte</div>
          <div class="font-semibold"><?= $icalInfo['nights'] ?></div>
        </div>
        <?php endif; ?>
        <?php if ($icalInfo['adults']): ?>
        <div>
          <div class="text-[9px] opacity-60 uppercase font-semibold">👥 Gäste (laut Buchung)</div>
          <div class="font-semibold">
            <?= $icalInfo['adults'] ?><?= $icalInfo['children'] ? ' + ' . $icalInfo['children'] . ' 👶' : '' ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php
      $photos = !empty($j['job_photos']) ? json_decode($j['job_photos'], true) : [];
      if (!empty($photos)):
    ?>
    <div class="flex gap-2 mt-3">
      <?php foreach (array_slice($photos, 0, 6) as $p): ?>
      <a href="/uploads/jobs/<?= (int) $j['j_id'] ?>/<?= e($p) ?>" target="_blank">
        <img src="/uploads/jobs/<?= (int) $j['j_id'] ?>/<?= e($p) ?>" class="w-14 h-14 object-cover rounded-lg border border-gray-200 hover:border-brand transition"/>
      </a>
      <?php endforeach; ?>
      <?php if (count($photos) > 6): ?>
      <div class="w-14 h-14 rounded-lg border border-gray-200 flex items-center justify-center text-xs text-gray-500 bg-gray-50">+<?= count($photos) - 6 ?></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php
    // Checklist completion status — pre-loaded above (no N+1)
    if (!empty($j['s_id_fk'])):
        $checklistItems = $checklistByService[(int)$j['s_id_fk']] ?? [];
        if (!empty($checklistItems)):
            $compMap = $completionsByJob[(int)$j['j_id']] ?? [];
            $doneCount = count(array_filter($compMap, fn($c) => !empty($c['completed'])));
            $totalCount = count($checklistItems);
            $pctChk = $totalCount > 0 ? round(($doneCount / $totalCount) * 100) : 0;
    ?>
    <div class="mt-3 p-3 rounded-xl bg-brand-light/50 border border-brand/20">
      <div class="flex items-center justify-between gap-2 mb-2">
        <div class="flex items-center gap-2">
          <span class="text-sm">📋</span>
          <span class="text-xs font-bold text-brand">Check-Liste · <?= $doneCount ?> / <?= $totalCount ?> erledigt</span>
        </div>
        <div class="flex-1 max-w-[140px] h-1.5 bg-white rounded-full overflow-hidden">
          <div class="h-full bg-brand" style="width: <?= $pctChk ?>%"></div>
        </div>
      </div>
      <div class="space-y-1">
        <?php foreach ($checklistItems as $ci):
          $comp = $compMap[$ci['checklist_id']] ?? null;
          $isDone = !empty($comp['completed']);
        ?>
        <div class="flex items-center gap-2 text-xs">
          <span class="w-4 h-4 rounded border flex items-center justify-center flex-shrink-0 <?= $isDone ? 'bg-brand border-brand' : 'border-gray-300 bg-white' ?>">
            <?php if ($isDone): ?><svg class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg><?php endif; ?>
          </span>
          <span class="flex-1 min-w-0 truncate <?= $isDone ? 'text-gray-500 line-through' : 'text-gray-700' ?>"><?= e($ci['title']) ?></span>
          <?php if (!empty($comp['photo'])): ?>
          <a href="<?= e($comp['photo']) ?>" target="_blank" class="flex-shrink-0" title="Beweisfoto">
            <img src="<?= e($comp['photo']) ?>" class="w-6 h-6 object-cover rounded border border-brand/30"/>
          </a>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; endif; ?>
  </div>

  <!-- Right: price + actions -->
  <div class="flex sm:flex-col items-end justify-between sm:justify-start sm:w-32 flex-shrink-0 pt-2 sm:pt-0">
    <?php
    $displayHours = max(MIN_HOURS, (float)($j['total_hours'] ?: $j['j_hours']));
    $hourlyRate = (float)($j['total_price'] ?? 0);
    $jobTotal = $displayHours * $hourlyRate;
    if ($jobTotal > 0): ?>
    <div class="text-right">
      <div class="text-[11px] text-gray-400 uppercase font-semibold">Preis</div>
      <div class="text-lg font-bold text-gray-900"><?= money($jobTotal) ?></div>
      <div class="text-[10px] text-gray-400"><?= $displayHours ?>h × <?= money($hourlyRate) ?></div>
    </div>
    <?php endif; ?>

    <div class="flex flex-col gap-2 mt-0 sm:mt-4 items-end w-full">
      <?php if ($canEdit): ?>
      <button
        @click='modalJob = <?= $jobJson ?>; modal = "reschedule"'
        class="w-full px-3 py-1.5 border border-gray-200 hover:border-brand hover:bg-brand/5 rounded-lg text-xs font-semibold text-gray-700 hover:text-brand flex items-center justify-center gap-1.5 transition">
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
        Umbuchen
      </button>
      <button
        @click='modalJob = <?= $jobJson ?>; modal = "edit"'
        class="w-full px-3 py-1.5 border border-gray-200 hover:border-brand hover:bg-brand/5 rounded-lg text-xs font-semibold text-gray-700 hover:text-brand flex items-center justify-center gap-1.5 transition">
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
        Bearbeiten
      </button>
      <?php endif; ?>

      <?php if ($canCancel): ?>
      <button
        @click='modalJob = <?= $jobJson ?>; modal = "cancel"'
        class="w-full px-3 py-1.5 border border-red-200 hover:border-red-400 hover:bg-red-50 rounded-lg text-xs font-semibold text-red-600 flex items-center justify-center gap-1.5 transition">
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        Stornieren
      </button>
      <?php endif; ?>

      <?php if (!$canEdit && $canNote): ?>
      <!-- Note-only edit for running/completed jobs -->
      <button
        @click='modalJob = <?= $jobJson ?>; modal = "edit"'
        class="w-full px-3 py-1.5 border border-gray-200 hover:border-brand hover:bg-brand/5 rounded-lg text-xs font-semibold text-gray-700 hover:text-brand flex items-center justify-center gap-1.5 transition">
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
        Notiz
      </button>
      <?php endif; ?>

      <?php if ($canRate): ?>
      <a href="/customer/workhours.php?month=<?= date('Y-m', strtotime($j['j_date'])) ?>" class="w-full px-3 py-1.5 border border-amber-200 bg-amber-50 hover:bg-amber-100 text-amber-700 rounded-lg text-xs font-semibold flex items-center justify-center gap-1.5 transition">
        ⭐ Bewerten (24h Fenster)
      </a>
      <?php endif; ?>

      <?php if ($canReklamation): ?>
      <button
        @click='modalJob = <?= $jobJson ?>; modal = "reklamation"'
        class="w-full px-3 py-1.5 border border-orange-200 bg-orange-50 hover:bg-orange-100 text-orange-700 rounded-lg text-xs font-semibold flex items-center justify-center gap-1.5 transition">
        ⚠ Reklamation
      </button>
      <?php endif; ?>

      <?php if ($hasReklamation): ?>
      <div class="w-full px-3 py-1.5 bg-gray-100 rounded-lg text-xs font-semibold text-gray-500 text-center">
        Reklamation eingereicht
      </div>
      <?php endif; ?>

      <?php if ($j['job_status'] === 'COMPLETED' && customerCan('booking') && !$canRate && !$canReklamation): ?>
      <a href="/customer/booking.php?repeat=<?= (int) $j['j_id'] ?>" class="w-full px-3 py-1.5 text-xs font-semibold text-gray-500 hover:text-brand flex items-center justify-center gap-1.5 transition">
        ↻ Nochmal buchen
      </a>
      <?php endif; ?>

      <?php if (!$canEdit && !$canCancel && !$canRate && !$canReklamation && !$hasReklamation && $j['job_status'] !== 'COMPLETED' && $tab === 'vergangenheit'): ?>
      <div class="text-[10px] text-gray-400 text-right flex items-center gap-1">
        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
        Gesperrt
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endforeach; ?>

<!-- ============ MODALS ============ -->
<div x-show="modal" x-cloak @click.self="modal = null" class="fixed inset-0 bg-black/50 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4" x-transition.opacity>

  <!-- RESCHEDULE MODAL -->
  <form x-show="modal === 'reschedule'" method="POST" class="bg-white w-full sm:max-w-md rounded-t-2xl sm:rounded-2xl shadow-xl overflow-hidden" @click.stop x-transition>
    <?= csrfField() ?>
    <input type="hidden" name="action" value="reschedule"/>
    <input type="hidden" name="j_id" :value="modalJob?.j_id"/>
    <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
      <h3 class="font-semibold text-gray-900">Termin umbuchen</h3>
      <button type="button" @click="modal = null; selectedDate = ''" class="w-8 h-8 rounded-lg hover:bg-gray-100 flex items-center justify-center text-gray-500">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>
    <div class="p-5 space-y-4">
      <div class="text-xs text-gray-500">Aktueller Termin: <span class="font-semibold text-gray-900" x-text="modalJob?.j_date + ' ' + modalJob?.j_time"></span></div>

      <!-- Quick date shortcuts -->
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-2 uppercase tracking-wider">Schnellauswahl</label>
        <div class="grid grid-cols-3 gap-2">
          <button type="button" @click="selectedDate = new Date(Date.now() + 86400000).toISOString().split('T')[0]" class="px-2 py-2 border border-gray-200 hover:border-brand hover:bg-brand/5 rounded-lg text-xs font-semibold text-gray-700 transition">+1 Tag</button>
          <button type="button" @click="selectedDate = new Date(Date.now() + 7*86400000).toISOString().split('T')[0]" class="px-2 py-2 border border-gray-200 hover:border-brand hover:bg-brand/5 rounded-lg text-xs font-semibold text-gray-700 transition">Nächste Woche</button>
          <button type="button" @click="selectedDate = new Date(Date.now() + 14*86400000).toISOString().split('T')[0]" class="px-2 py-2 border border-gray-200 hover:border-brand hover:bg-brand/5 rounded-lg text-xs font-semibold text-gray-700 transition">In 2 Wochen</button>
        </div>
      </div>

      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Neues Datum</label>
        <input type="date" name="new_date" x-model="selectedDate" :min="new Date().toISOString().split('T')[0]" required class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none"/>

        <!-- Partner availability display -->
        <div x-show="selectedDate" x-cloak class="mt-2">
          <!-- External booking warning (highest priority) -->
          <div x-show="availInfo()?.blocked_external" class="flex items-start gap-2 px-3 py-2 bg-purple-50 border border-purple-300 rounded-lg">
            <svg class="w-4 h-4 text-purple-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
            <div class="text-xs text-purple-900">
              <strong>🏠 Externes Booking blockt diesen Tag</strong>
              <div class="text-purple-700" x-show="availInfo()?.guest">Gast: <span x-text="availInfo()?.guest"></span> · via <span x-text="availInfo()?.platform"></span></div>
              <div class="text-purple-600 mt-1">Bitte ein anderes Datum wählen — Doppelbuchungen werden vermieden.</div>
            </div>
          </div>
          <div x-show="!availInfo()?.blocked_external && availInfo()?.ok" class="flex items-center gap-2 px-3 py-2 bg-green-50 border border-green-200 rounded-lg">
            <svg class="w-4 h-4 text-green-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span class="text-xs text-green-800">
              <strong x-text="availInfo()?.free"></strong>
              <span>von <span x-text="availInfo()?.total"></span> Partnern verfügbar</span>
            </span>
          </div>
          <div x-show="!availInfo()?.blocked_external && !availInfo()?.ok" class="flex items-center gap-2 px-3 py-2 bg-red-50 border border-red-200 rounded-lg">
            <svg class="w-4 h-4 text-red-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            <span class="text-xs text-red-800">Alle Partner an diesem Tag ausgebucht. Bitte anderes Datum wählen.</span>
          </div>
        </div>
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Neue Uhrzeit</label>
        <input type="time" name="new_time" required class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none"/>
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Grund (optional)</label>
        <input type="text" name="reschedule_reason" placeholder="z.B. Gast verlängert, Geschäftsreise..." class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none"/>
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Notiz für den Partner (optional)</label>
        <textarea name="reschedule_note" rows="2" placeholder="z.B. Schlüssel beim Nachbarn, Hund anwesend..." class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none text-sm"></textarea>
      </div>
      <div class="text-[11px] text-gray-400 p-3 bg-amber-50 border border-amber-200 rounded-lg">
        ⚠ Umbuchungen weniger als 24 Stunden vor dem Termin können eine Gebühr nach sich ziehen.
      </div>
      <div class="flex gap-2 pt-2">
        <button type="button" @click="modal = null; selectedDate = ''" class="flex-1 px-4 py-2.5 border border-gray-200 rounded-lg text-sm font-semibold text-gray-700 hover:bg-gray-50">Abbrechen</button>
        <button type="submit" :disabled="selectedDate && !availInfo()?.ok" :class="selectedDate && !availInfo()?.ok ? 'opacity-50 cursor-not-allowed' : ''" class="flex-1 px-4 py-2.5 bg-brand hover:bg-brand-dark text-white rounded-lg text-sm font-semibold">Umbuchen</button>
      </div>
    </div>
  </form>

  <!-- EDIT MODAL -->
  <form x-show="modal === 'edit'" method="POST" class="bg-white w-full sm:max-w-md rounded-t-2xl sm:rounded-2xl shadow-xl overflow-hidden max-h-[90vh] overflow-y-auto" @click.stop x-transition x-data="{ checkOnly: false, people: 1, pets: 0, children: 0, hours: 2, separateBeds: false, sofaBed: false, extrasNote: '' }" x-effect="if (modal === 'edit' && modalJob) { people = modalJob.no_people || 1; children = modalJob.no_children || 0; pets = modalJob.no_pets || 0; hours = Math.max(2, modalJob.hours || 2); separateBeds = !!modalJob.has_separate_beds; sofaBed = !!modalJob.has_sofa_bed; extrasNote = modalJob.extras_note || ''; }">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="edit"/>
    <input type="hidden" name="j_id" :value="modalJob?.j_id"/>
    <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
      <h3 class="font-semibold text-gray-900">Details bearbeiten</h3>
      <button type="button" @click="modal = null" class="w-8 h-8 rounded-lg hover:bg-gray-100 flex items-center justify-center text-gray-500">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>
    <div class="p-5 space-y-4">

      <!-- Job type toggle -->
      <div class="grid grid-cols-2 gap-2">
        <button type="button" @click="checkOnly = false"
          :class="!checkOnly ? 'bg-brand text-white border-brand' : 'bg-white border-gray-200 text-gray-600 hover:border-brand'"
          class="px-3 py-2.5 border-2 rounded-lg text-xs font-semibold transition flex items-center justify-center gap-1.5">
          🧽 Vollreinigung
        </button>
        <button type="button" @click="checkOnly = true"
          :class="checkOnly ? 'bg-brand text-white border-brand' : 'bg-white border-gray-200 text-gray-600 hover:border-brand'"
          class="px-3 py-2.5 border-2 rounded-lg text-xs font-semibold transition flex items-center justify-center gap-1.5">
          🔍 Nur Kontrolle
        </button>
      </div>
      <input type="hidden" name="is_check_only" :value="checkOnly ? 1 : 0"/>
      <p x-show="checkOnly" x-cloak class="text-[11px] text-gray-500 p-2 bg-blue-50 border border-blue-200 rounded-lg">
        ℹ Bei "Nur Kontrolle" kommt der Partner um die Wohnung zu prüfen (z.B. nach Gast-Checkout). Wird als normaler Reinigungstermin abgerechnet.
      </p>

      <!-- Guest count from iCal (read-only) -->
      <template x-if="modalJob?.ical_adults">
        <div class="p-3 rounded-lg bg-orange-50 border border-orange-200">
          <div class="text-[10px] uppercase font-bold text-orange-700 tracking-wide mb-1">👥 Gäste laut Buchung</div>
          <div class="text-sm text-orange-900">
            <span x-text="modalJob.ical_adults"></span> Erwachsene
            <template x-if="modalJob.ical_children"> + <span x-text="modalJob.ical_children"></span> Kinder</template>
            <span class="text-[10px] opacity-70">· aus iCal-Feed (nicht änderbar)</span>
          </div>
        </div>
      </template>

      <!-- Dauer (min 2h) -->
      <div x-show="!checkOnly">
        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Dauer</label>
        <select name="j_hours" x-model.number="hours" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none bg-white">
          <option value="2">2 Stunden</option>
          <option value="3">3 Stunden</option>
          <option value="4">4 Stunden</option>
          <option value="5">5 Stunden</option>
          <option value="6">6 Stunden</option>
          <option value="8">8 Stunden (Ganztag)</option>
        </select>
        <p class="text-[10px] text-gray-400 mt-0.5">Minimum 2 Stunden</p>
      </div>

      <!-- People / Pets / Children — hidden when check-only -->
      <div x-show="!checkOnly" class="grid grid-cols-3 gap-3">
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Personen vor Ort</label>
          <input type="number" name="no_people" min="1" max="20" x-model.number="people" required class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none"/>
          <p class="text-[10px] text-gray-400 mt-0.5">Für die Reinigung</p>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Kinder</label>
          <input type="number" name="no_children" min="0" max="20" x-model.number="children" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none"/>
          <p class="text-[10px] text-gray-400 mt-0.5">optional</p>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Tiere</label>
          <input type="number" name="no_pets" min="0" max="10" x-model.number="pets" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none"/>
          <p class="text-[10px] text-gray-400 mt-0.5">🐕 🐈</p>
        </div>
      </div>

      <!-- Bett-Varianten (Airbnb Extras) -->
      <div x-show="!checkOnly" class="grid grid-cols-2 gap-2">
        <label class="flex items-center gap-2 p-2.5 rounded-lg border cursor-pointer hover:bg-gray-50"
               :class="separateBeds ? 'border-brand bg-brand/5' : 'border-gray-200'">
          <input type="checkbox" name="has_separate_beds" value="1" x-model="separateBeds" class="w-4 h-4 text-brand rounded focus:ring-brand"/>
          <span class="text-xs">🛏️🛏️ <strong>Getrennte Betten</strong></span>
        </label>
        <label class="flex items-center gap-2 p-2.5 rounded-lg border cursor-pointer hover:bg-gray-50"
               :class="sofaBed ? 'border-brand bg-brand/5' : 'border-gray-200'">
          <input type="checkbox" name="has_sofa_bed" value="1" x-model="sofaBed" class="w-4 h-4 text-brand rounded focus:ring-brand"/>
          <span class="text-xs">🛋️ <strong>Extra Sofa-Bett</strong></span>
        </label>
      </div>

      <div x-show="!checkOnly">
        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Extras für den Partner</label>
        <input type="text" name="extras_note" x-model="extrasNote" placeholder="z.B. Babybett aufbauen, Fenster öffnen, Gäste-Slippers bereitlegen..." class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-brand focus:border-brand outline-none"/>
        <p class="text-[10px] text-gray-400 mt-0.5">Kurze Hinweise die der Partner beim Job sehen soll</p>
      </div>

      <!-- Adresse: nur die des zugehörigen Services (z.B. ROD 2 = Rodenbergstraße 21) -->
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Adresse</label>
        <div class="px-3 py-2.5 bg-brand-light border border-brand/20 rounded-lg text-sm font-semibold text-brand-dark flex items-center gap-2">
          <span>📍</span>
          <span x-text="modalJob?.service_address || modalJob?.address || '—'"></span>
        </div>
        <input type="hidden" name="address" :value="modalJob?.service_address || modalJob?.address"/>
        <p class="text-[10px] text-gray-400 mt-1">Gehört zu <strong x-text="modalJob?.stitle"></strong> — aus Service-Einstellungen</p>
      </div>

      <!-- Türcode: prefill from service.box_code, editable, saved only to job -->
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Türcode / Schlüsselkasten</label>
        <input type="text" name="code_door" :value="modalJob?.code_door || modalJob?.service_code || ''" placeholder="z.B. 1234 oder Kasten Links" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none font-mono"/>
        <p class="text-[10px] text-gray-400 mt-1" x-show="modalJob?.service_code && !modalJob?.code_door">Aus Service-Profil übernommen · Sie können ihn hier ändern, das Service-Profil bleibt unverändert</p>
      </div>
      <!-- Check-in / Check-out Gast-Zeiten (Host/Airbnb-Kunden) -->
      <?php
        $showGuestTimes = in_array($customer['customer_type'] ?? '', ['Airbnb','Host','Co-Host','Short-Term Rental','Booking','Company','Business','B2B','Firma','GmbH']);
      ?>
      <?php if ($showGuestTimes): ?>
      <div class="border-t pt-3">
        <div class="text-[10px] uppercase font-bold text-gray-500 mb-2 tracking-wider">Gast-Wechsel (Turnover)</div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-[11px] font-semibold text-gray-600 mb-1">Check-out Gast</label>
            <div class="grid grid-cols-2 gap-1">
              <input type="date" name="guest_checkout_date" :value="modalJob?.guest_checkout_date && modalJob.guest_checkout_date !== '0000-00-00' ? modalJob.guest_checkout_date : ''" class="px-2 py-2 border rounded-lg text-xs"/>
              <input type="time" name="guest_checkout_time" :value="modalJob?.guest_checkout_time ? modalJob.guest_checkout_time.slice(0,5) : '11:00'" class="px-2 py-2 border rounded-lg text-xs"/>
            </div>
          </div>
          <div>
            <label class="block text-[11px] font-semibold text-gray-600 mb-1">Check-in neuer Gast</label>
            <div class="grid grid-cols-2 gap-1">
              <input type="date" name="check_in_date" :value="modalJob?.check_in_date && modalJob.check_in_date !== '0000-00-00' ? modalJob.check_in_date : ''" class="px-2 py-2 border rounded-lg text-xs"/>
              <input type="time" name="check_in_time" :value="modalJob?.check_in_time ? modalJob.check_in_time.slice(0,5) : '16:00'" class="px-2 py-2 border rounded-lg text-xs"/>
            </div>
          </div>
        </div>
        <p class="text-[10px] text-gray-500 mt-1">💡 Partner reinigt zwischen diesen Zeiten</p>
      </div>
      <?php endif; ?>

      <!-- Nachricht an Partner (emp_message — sichtbar für Partner beim Job) -->
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">💬 Nachricht an Partner</label>
        <textarea name="emp_message" rows="2" placeholder="z.B. Klingeln Sie zweimal, Schlüssel im Briefkasten..." class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none text-sm" x-text="modalJob?.emp_message || ''"></textarea>
        <p class="text-[10px] text-gray-500 mt-0.5">Kurze Info die der Partner direkt beim Start sieht</p>
      </div>

      <!-- Interne Notiz -->
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">📝 Interne Notiz</label>
        <textarea name="job_note" rows="2" x-text="modalJob?.job_note || ''" placeholder="Besonderheiten, Allergien, Zugang..." class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none text-sm"></textarea>
      </div>

      <!-- Optional Products (Zusatzleistungen) — dynamisch aus DB -->
      <?php
        try {
            $visFilter = $showGuestTimes ? "('all','host','business')" : "('all','private')";
            $optionalProducts = all("SELECT op_id, name, description, pricing_type, customer_price, icon FROM optional_products WHERE is_active=1 AND visibility IN $visFilter ORDER BY sort_order ASC, op_id ASC");
        } catch (Exception $e) { $optionalProducts = []; }
      ?>
      <?php if (!empty($optionalProducts)): ?>
      <div class="border-t pt-3">
        <label class="block text-xs font-semibold text-gray-600 mb-2 uppercase tracking-wider">Zusatzleistungen</label>
        <div class="space-y-1.5">
          <?php foreach ($optionalProducts as $op): ?>
          <label class="flex items-center gap-2 p-2 border border-gray-200 rounded-lg hover:border-brand cursor-pointer text-sm">
            <input type="checkbox" name="optional_products[]" value="<?= e($op['name']) ?>" class="w-4 h-4 rounded text-brand focus:ring-brand" x-bind:checked="(modalJob?.optional_products || '').includes(<?= json_encode($op['name']) ?>)"/>
            <div class="flex-1 flex items-center justify-between">
              <span><?= $op['icon'] ? e($op['icon']) . ' ' : '' ?><?= e($op['name']) ?></span>
              <?php if ($op['customer_price'] > 0): ?>
              <span class="text-xs font-semibold text-brand-dark">+<?= number_format($op['customer_price'], 2, ',', '.') ?> €<?= $op['pricing_type']==='per_hour' ? '/h' : '' ?></span>
              <?php endif; ?>
            </div>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Service-Checkliste (read-only preview) -->
      <?php
        // Alle Checklisten dieses Kunden pro Service cachen
        try {
            $allChecklists = all("SELECT cl.checklist_id, cl.s_id_fk, cl.room, cl.title, cl.priority, cl.position,
                (SELECT completed FROM checklist_completions WHERE job_id_fk IS NULL LIMIT 0) AS x
                FROM service_checklists cl
                WHERE cl.customer_id_fk=? AND cl.is_active=1
                ORDER BY cl.s_id_fk, cl.room, cl.position", [$cid]);
            $checklistBySvc = [];
            foreach ($allChecklists as $c) $checklistBySvc[(int)$c['s_id_fk']][] = $c;
        } catch (Exception $e) { $checklistBySvc = []; }
      ?>
      <div x-show="modalJob && <?= json_encode($checklistBySvc, JSON_UNESCAPED_UNICODE) ?>[modalJob.s_id_fk]" class="border-t pt-3">
        <div class="flex items-center justify-between mb-2">
          <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wider">✅ Service-Checkliste</label>
          <a :href="'/customer/checklist.php?s=' + modalJob?.s_id_fk" class="text-[10px] text-brand hover:underline">bearbeiten →</a>
        </div>
        <div class="space-y-1 text-xs bg-gray-50 rounded-lg p-2 max-h-40 overflow-y-auto">
          <template x-for="item in (<?= json_encode($checklistBySvc, JSON_UNESCAPED_UNICODE) ?>[modalJob?.s_id_fk] || [])" :key="item.checklist_id">
            <div class="flex items-start gap-2 py-1">
              <span class="inline-block w-2 h-2 rounded-full mt-1.5 flex-shrink-0"
                :class="item.priority === 'critical' ? 'bg-red-500' : (item.priority === 'high' ? 'bg-amber-500' : 'bg-green-500')"></span>
              <div class="flex-1 min-w-0">
                <span class="text-gray-900 font-medium" x-text="item.title"></span>
                <span x-show="item.room" class="text-[10px] text-gray-500 ml-1" x-text="'· ' + item.room"></span>
              </div>
            </div>
          </template>
        </div>
        <p class="text-[10px] text-gray-500 mt-1">💡 Partner bestätigt diese Punkte beim Job</p>
      </div>

      <!-- Uploads: Fotos / Videos / Dokumente vom Host → Partner -->
      <div class="border-t pt-3" x-data="jobUpload(modalJob?.j_id)">
        <label class="block text-xs font-semibold text-gray-600 mb-2 uppercase tracking-wider">📎 Anweisungen für Partner (Foto / Video / PDF)</label>
        <!-- Bestehende Uploads -->
        <div x-show="existingFiles.length > 0" class="grid grid-cols-4 gap-2 mb-2">
          <template x-for="f in existingFiles" :key="f.url">
            <a :href="f.url" target="_blank" class="block aspect-square rounded-lg overflow-hidden border border-gray-200 bg-gray-50 relative group">
              <template x-if="f.type === 'image'">
                <img :src="f.url" class="w-full h-full object-cover"/>
              </template>
              <template x-if="f.type === 'video'">
                <div class="w-full h-full flex items-center justify-center bg-gray-800 text-white text-xl">▶</div>
              </template>
              <template x-if="f.type === 'document'">
                <div class="w-full h-full flex items-center justify-center text-xl">📄</div>
              </template>
              <div class="absolute bottom-0 left-0 right-0 bg-black/60 text-white text-[9px] px-1 py-0.5 truncate" x-text="f.original_name"></div>
            </a>
          </template>
        </div>
        <!-- Upload-Button -->
        <label class="flex items-center justify-center gap-2 px-3 py-2.5 border-2 border-dashed border-gray-300 hover:border-brand rounded-lg text-xs text-gray-600 hover:text-brand cursor-pointer">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3M3 17V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
          <span x-show="!uploading">+ Foto / Video / PDF (max 10 MB)</span>
          <span x-show="uploading && compressProgress === 0">Bild wird komprimiert…</span>
          <span x-show="uploading && compressProgress > 0">
            Video komprimiert: <span x-text="compressProgress"></span>%
          </span>
          <input type="file" accept="image/*,video/*,application/pdf" @change="handleFile($event)" class="hidden" :disabled="uploading"/>
        </label>
        <div x-show="uploading && compressProgress > 0" class="w-full h-1 bg-gray-200 rounded-full mt-1 overflow-hidden">
          <div class="h-full bg-brand transition-all" :style="'width:' + compressProgress + '%'"></div>
        </div>
        <p class="text-[10px] text-gray-500 mt-1">🖼 Bilder: auto 1280px · 🎥 Videos: auto 720p h264 · Nach 30 Tagen → Google Drive Archiv.</p>
        <p x-show="uploadError" x-cloak class="text-[11px] text-red-600 mt-1" x-text="uploadError"></p>
      </div>

      <!-- Status-Anzeige (read-only für Kunden) -->
      <div class="bg-gray-50 border border-gray-200 rounded-lg px-3 py-2 text-xs flex items-center justify-between">
        <span class="text-gray-600">Status</span>
        <span class="font-semibold" :class="{
          'text-blue-700': modalJob?.job_status === 'PENDING',
          'text-brand-dark': modalJob?.job_status === 'CONFIRMED',
          'text-amber-700': ['RUNNING','STARTED'].includes(modalJob?.job_status),
          'text-green-700': modalJob?.job_status === 'COMPLETED',
          'text-red-600': modalJob?.job_status === 'CANCELLED'
        }" x-text="({PENDING:'Geplant',CONFIRMED:'Bestätigt',RUNNING:'Läuft',STARTED:'Läuft',COMPLETED:'Erledigt',CANCELLED:'Storniert'})[modalJob?.job_status] || modalJob?.job_status"></span>
      </div>

      <div class="flex gap-2 pt-2">
        <button type="button" @click="modal = null" class="flex-1 px-4 py-2.5 border border-gray-200 rounded-lg text-sm font-semibold text-gray-700 hover:bg-gray-50">Abbrechen</button>
        <button type="submit" class="flex-1 px-4 py-2.5 bg-brand hover:bg-brand-dark text-white rounded-lg text-sm font-semibold">Speichern</button>
      </div>
    </div>
  </form>

  <!-- REKLAMATION MODAL -->
  <form x-show="modal === 'reklamation'" method="POST" class="bg-white w-full sm:max-w-md rounded-t-2xl sm:rounded-2xl shadow-xl overflow-hidden" @click.stop x-transition>
    <?= csrfField() ?>
    <input type="hidden" name="action" value="reklamation"/>
    <input type="hidden" name="j_id" :value="modalJob?.j_id"/>
    <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
      <h3 class="font-semibold text-orange-700">Reklamation einreichen</h3>
      <button type="button" @click="modal = null" class="w-8 h-8 rounded-lg hover:bg-gray-100 flex items-center justify-center text-gray-500">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>
    <div class="p-5 space-y-4">
      <div class="text-sm text-gray-700">
        <span class="font-semibold" x-text="modalJob?.stitle"></span>
        <span class="text-gray-500">am</span>
        <span class="font-semibold" x-text="modalJob?.j_date"></span>
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Was war das Problem?</label>
        <textarea name="reklamation_text" rows="4" required placeholder="Beschreiben Sie bitte detailliert was nicht gut war..." class="w-full px-3 py-2.5 border border-gray-200 rounded-lg outline-none focus:ring-2 focus:ring-orange-500 focus:border-orange-500"></textarea>
      </div>
      <div class="text-xs p-3 bg-orange-50 border border-orange-200 rounded-lg text-orange-800">
        ℹ Reklamationen sind nur zwischen 24 und 48 Stunden nach Job-Ende möglich. Wir prüfen Ihre Beschwerde und melden uns zeitnah.
      </div>
      <div class="flex gap-2 pt-2">
        <button type="button" @click="modal = null" class="flex-1 px-4 py-2.5 border border-gray-200 rounded-lg text-sm font-semibold text-gray-700 hover:bg-gray-50">Abbrechen</button>
        <button type="submit" class="flex-1 px-4 py-2.5 bg-orange-600 hover:bg-orange-700 text-white rounded-lg text-sm font-semibold">Einreichen</button>
      </div>
    </div>
  </form>

  <!-- CANCEL MODAL -->
  <form x-show="modal === 'cancel'" method="POST" class="bg-white w-full sm:max-w-md rounded-t-2xl sm:rounded-2xl shadow-xl overflow-hidden max-h-[90vh] overflow-y-auto" @click.stop x-transition x-data="{ confirmed: false, googleAlternative: false }">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="cancel"/>
    <input type="hidden" name="j_id" :value="modalJob?.j_id"/>
    <input type="hidden" name="google_review_offer" :value="googleAlternative ? 1 : 0"/>
    <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
      <h3 class="font-semibold text-red-700">Termin stornieren</h3>
      <button type="button" @click="modal = null; confirmed = false; googleAlternative = false" class="w-8 h-8 rounded-lg hover:bg-gray-100 flex items-center justify-center text-gray-500">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>
    <div class="p-5 space-y-4">
      <div class="text-sm text-gray-700">
        <span class="font-semibold" x-text="modalJob?.stitle"></span>
        <span class="text-gray-500">am</span>
        <span class="font-semibold" x-text="modalJob?.j_date + ' ' + modalJob?.j_time"></span>
      </div>

      <!-- Cost calculation banner -->
      <div class="p-3 bg-amber-50 border border-amber-200 rounded-lg space-y-1.5">
        <div class="flex items-center justify-between text-xs">
          <span class="text-gray-600">Geplanter Umsatz (brutto):</span>
          <span class="font-semibold text-gray-900" x-text="(48.58 * 1.19).toFixed(2).replace('.', ',') + ' €'"></span>
        </div>
        <div class="flex items-center justify-between text-xs">
          <span class="text-gray-600">Gebühr bei Storno &lt; 24h:</span>
          <span class="font-semibold text-red-700">50 % = <span x-text="(48.58 * 1.19 * 0.5).toFixed(2).replace('.', ',') + ' €'"></span></span>
        </div>
      </div>

      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Grund (optional)</label>
        <select name="reason" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg outline-none focus:ring-2 focus:ring-brand focus:border-brand bg-white">
          <option value="">— Grund wählen —</option>
          <option>Ich bin nicht zu Hause</option>
          <option>Zeit passt nicht mehr</option>
          <option>Service nicht mehr benötigt</option>
          <option>Kurzfristiger Termin</option>
          <option>Anderer Grund</option>
        </select>
      </div>

      <!-- AGB-Confirmation (German Kleinunternehmer-Regelung) -->
      <label class="flex items-start gap-2 p-3 border border-gray-200 rounded-lg cursor-pointer hover:bg-gray-50">
        <input type="checkbox" x-model="confirmed" required class="mt-0.5 w-4 h-4 accent-brand flex-shrink-0"/>
        <span class="text-xs text-gray-700">
          Ich bestätige, dass bei einer Stornierung weniger als 24 Stunden vor dem Termin <strong>kein Anspruch auf Rückerstattung</strong> besteht und die Gebühr fällig wird (gem. unseren AGB nach §§ 631, 648 BGB).
        </span>
      </label>

      <!-- Google review alternative -->
      <div class="p-4 bg-gradient-to-br from-blue-50 to-transparent border border-blue-200 rounded-lg">
        <div class="flex items-start gap-2 mb-2">
          <span class="text-xl">⭐</span>
          <div>
            <h4 class="font-semibold text-gray-900 text-sm">Alternative: Google-Bewertung statt Gebühr</h4>
            <p class="text-xs text-gray-600 mt-0.5">Hinterlassen Sie eine ehrliche, positive Bewertung auf Google und wir <strong>erlassen Ihnen die Storno-Gebühr</strong> nach Prüfung. Win-Win 🤝</p>
          </div>
        </div>
        <label class="flex items-center gap-2 text-xs cursor-pointer mt-2">
          <input type="checkbox" x-model="googleAlternative" class="w-4 h-4 accent-blue-600"/>
          <span class="text-blue-800 font-semibold">Ja, ich gebe Google-Bewertung ab statt Gebühr zu zahlen</span>
        </label>
      </div>

      <div class="flex gap-2 pt-2">
        <button type="button" @click="modal = null; confirmed = false; googleAlternative = false" class="flex-1 px-4 py-2.5 border border-gray-200 rounded-lg text-sm font-semibold text-gray-700 hover:bg-gray-50">Doch nicht</button>
        <button type="submit" :disabled="!confirmed" :class="!confirmed ? 'opacity-50 cursor-not-allowed' : ''" class="flex-1 px-4 py-2.5 bg-red-600 hover:bg-red-700 text-white rounded-lg text-sm font-semibold">Stornieren</button>
      </div>
    </div>
  </form>
</div>
</div>

<!-- Count footer -->
<div class="mt-6 text-center text-xs text-gray-500">
  <?= count($jobs) ?> Termin<?= count($jobs) === 1 ? '' : 'e' ?> <?= $showCancelled ? '(inkl. abgesagte)' : '' ?>
</div>

<?php endif; ?>

<script type="module">
// Lazy-load ffmpeg.wasm nur wenn Video-Kompression gebraucht
let ffmpegInstance = null;
async function getFfmpeg() {
  if (ffmpegInstance) return ffmpegInstance;
  const { FFmpeg } = await import('https://unpkg.com/@ffmpeg/ffmpeg@0.12.10/dist/esm/index.js');
  const { toBlobURL } = await import('https://unpkg.com/@ffmpeg/util@0.12.1/dist/esm/index.js');
  ffmpegInstance = new FFmpeg();
  const baseURL = 'https://unpkg.com/@ffmpeg/core@0.12.6/dist/esm';
  await ffmpegInstance.load({
    coreURL: await toBlobURL(`${baseURL}/ffmpeg-core.js`, 'text/javascript'),
    wasmURL: await toBlobURL(`${baseURL}/ffmpeg-core.wasm`, 'application/wasm')
  });
  return ffmpegInstance;
}
window.compressVideo = async function(file, onProgress) {
  const ffmpeg = await getFfmpeg();
  if (onProgress) ffmpeg.on('progress', ({ progress }) => onProgress(Math.round(progress * 100)));
  const inputName = 'in.' + (file.name.split('.').pop() || 'mp4');
  const outputName = 'out.mp4';
  const buf = new Uint8Array(await file.arrayBuffer());
  await ffmpeg.writeFile(inputName, buf);
  // Encode: 720p max, 1Mbps bitrate, h264, mp4
  await ffmpeg.exec(['-i', inputName, '-vf', 'scale=\'min(1280,iw)\':\'-2\'', '-b:v', '1M', '-c:v', 'libx264', '-preset', 'fast', '-movflags', '+faststart', '-c:a', 'aac', '-b:a', '128k', outputName]);
  const data = await ffmpeg.readFile(outputName);
  return new File([data.buffer], file.name.replace(/\.[^.]+$/, '.mp4'), { type: 'video/mp4' });
};
</script>
<script>
function jobUpload(jobId) {
  return {
    uploading: false, uploadError: null, existingFiles: [], compressProgress: 0,
    init() {
      this.$watch('$root.modalJob', (job) => {
        if (!job) return;
        try { this.existingFiles = JSON.parse(job.job_file || '[]') || []; } catch(e) { this.existingFiles = []; }
      });
    },
    async handleFile(ev) {
      const file = ev.target.files?.[0];
      if (!file) return;
      this.uploading = true; this.uploadError = null; this.compressProgress = 0;
      try {
        let toSend = file;
        if (file.type.startsWith('image/')) {
          toSend = await this.compressImage(file, 1280, 0.7);
        } else if (file.type.startsWith('video/')) {
          // Video > 5MB → komprimieren
          if (file.size > 5 * 1024 * 1024) {
            if (!window.compressVideo) throw new Error('Video-Kompression lädt... bitte nochmal klicken');
            this.compressProgress = 1;
            toSend = await window.compressVideo(file, p => this.compressProgress = p);
          }
        }
        const fd = new FormData();
        fd.append('j_id', jobId);
        fd.append('file', toSend, toSend.name || file.name);
        fd.append('_csrf', '<?= csrfToken() ?>');
        const r = await fetch('/customer/job-file-upload.php', { method:'POST', body: fd });
        const d = await r.json();
        if (!d.success) throw new Error(d.error || 'Upload fehlgeschlagen');
        this.existingFiles.push({
          url: d.data.url, type: d.data.type, original_name: file.name,
          size: toSend.size, uploaded_at: new Date().toISOString()
        });
        ev.target.value = '';
      } catch (e) {
        this.uploadError = e.message;
      } finally {
        this.uploading = false; this.compressProgress = 0;
      }
    },
    compressImage(file, maxWidth, quality) {
      return new Promise((resolve, reject) => {
        const img = new Image();
        const reader = new FileReader();
        reader.onload = e => { img.src = e.target.result; };
        img.onload = () => {
          const scale = Math.min(1, maxWidth / img.width);
          const canvas = document.createElement('canvas');
          canvas.width = img.width * scale;
          canvas.height = img.height * scale;
          canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
          canvas.toBlob(b => b ? resolve(new File([b], file.name, { type: 'image/jpeg' })) : reject(new Error('Compression failed')), 'image/jpeg', quality);
        };
        img.onerror = () => reject(new Error('Bild konnte nicht gelesen werden'));
        reader.readAsDataURL(file);
      });
    }
  };
}
</script>

<?php include __DIR__ . '/../includes/footer-customer.php'; ?>
