<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Live-Karte'; $page = 'live-map';

$today = date('Y-m-d');
$activeJobs = all("SELECT j.j_id, j.j_date, j.j_time, j.j_hours, j.job_status, j.address, j.start_time, j.end_time, j.start_location, j.end_location,
    c.name as cname, c.customer_type as ctype,
    e.name as ename, e.surname as esurname, e.emp_id, e.phone as ephone,
    s.title as stitle, s.street as sstreet, s.city as scity, s.total_price as sprice
    FROM jobs j
    LEFT JOIN customer c ON j.customer_id_fk=c.customer_id
    LEFT JOIN employee e ON j.emp_id_fk=e.emp_id
    LEFT JOIN services s ON j.s_id_fk=s.s_id
    WHERE j.j_date=? AND j.status=1 AND j.job_status IN ('PENDING','CONFIRMED','RUNNING','STARTED')
    ORDER BY j.j_time", [$today]);

$employees = all("SELECT emp_id, name, surname, phone FROM employee WHERE status=1 ORDER BY name");

// Stats
$completedToday = one("SELECT COUNT(*) as cnt FROM jobs WHERE j_date=? AND status=1 AND job_status='COMPLETED'", [$today])['cnt'] ?? 0;
$totalToday = count($activeJobs) + $completedToday;
$runningCount = count(array_filter($activeJobs, fn($j) => in_array($j['job_status'], ['RUNNING','STARTED'])));
$pendingCount = count(array_filter($activeJobs, fn($j) => $j['job_status'] === 'PENDING'));

include __DIR__ . '/../includes/layout.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<style>
  #map { border-radius: 0 0 12px 12px; }
  .leaflet-control-zoom a { width: 32px !important; height: 32px !important; line-height: 32px !important; font-size: 16px !important; border-radius: 8px !important; color: #374151 !important; border: 1px solid #e5e7eb !important; background: white !important; box-shadow: 0 1px 3px rgba(0,0,0,0.1) !important; }
  .leaflet-control-zoom { border: none !important; box-shadow: none !important; }
  .leaflet-control-zoom a + a { margin-top: 4px !important; }
  .leaflet-control-attribution { font-size: 10px; background: rgba(255,255,255,0.8) !important; border-radius: 6px 0 0 0; padding: 2px 8px !important; }
  .job-card { transition: all 0.2s ease; }
  .job-card:hover { transform: translateX(2px); box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
  .job-card.active { ring: 2px; }
  .partner-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
  .partner-dot.online { background: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,0.2); animation: dotPulse 2s infinite; }
  .partner-dot.away { background: #f59e0b; box-shadow: 0 0 0 3px rgba(245,158,11,0.2); }
  .partner-dot.offline { background: #d1d5db; }
  @keyframes dotPulse { 0%,100% { box-shadow: 0 0 0 3px rgba(34,197,94,0.2); } 50% { box-shadow: 0 0 0 6px rgba(34,197,94,0.1); } }
  @keyframes markerPulse { 0%,100% { box-shadow: 0 0 0 4px rgba(139,92,246,0.3); } 50% { box-shadow: 0 0 0 8px rgba(139,92,246,0.1); } }
  .stat-card { background: white; border-radius: 12px; border: 1px solid #e5e7eb; padding: 12px 16px; text-align: center; }
</style>

<!-- Top Stats Bar -->
<div class="grid grid-cols-4 gap-3 mb-4">
  <div class="stat-card">
    <div class="text-2xl font-bold text-brand"><?= $totalToday ?></div>
    <div class="text-xs text-gray-500">Jobs heute</div>
  </div>
  <div class="stat-card">
    <div class="text-2xl font-bold text-green-600"><?= $runningCount ?></div>
    <div class="text-xs text-gray-500">Laufend</div>
  </div>
  <div class="stat-card">
    <div class="text-2xl font-bold text-amber-600"><?= $pendingCount ?></div>
    <div class="text-xs text-gray-500">Offen</div>
  </div>
  <div class="stat-card">
    <div class="text-2xl font-bold text-blue-600"><?= $completedToday ?></div>
    <div class="text-xs text-gray-500">Erledigt</div>
  </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-12 gap-4">
  <!-- Map: 8 columns -->
  <div class="lg:col-span-8">
    <div class="bg-white rounded-xl border overflow-hidden shadow-sm">
      <div class="px-5 py-3 border-b flex items-center justify-between bg-white">
        <div class="flex items-center gap-3">
          <h3 class="font-semibold text-gray-900">Berlin — <?= date('d.m.Y') ?></h3>
          <span class="text-xs text-gray-400" id="lastUpdate">Aktualisiert: <?= date('H:i') ?></span>
        </div>
        <div class="flex items-center gap-4 text-xs">
          <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-green-500 inline-block"></span> Laufend</span>
          <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-blue-500 inline-block"></span> Bestätigt</span>
          <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-amber-500 inline-block"></span> Offen</span>
          <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-purple-500 inline-block" style="animation:dotPulse 2s infinite"></span> GPS</span>
        </div>
      </div>
      <div id="map" style="height:calc(100vh - 280px); min-height:450px"></div>
    </div>
  </div>

  <!-- Sidebar: 4 columns -->
  <div class="lg:col-span-4 space-y-4">

    <!-- Partner Status -->
    <div class="bg-white rounded-xl border shadow-sm">
      <div class="px-4 py-3 border-b">
        <h4 class="font-semibold text-sm text-gray-900 flex items-center gap-2">
          Partner
          <span class="text-xs font-normal text-gray-400"><?= count($employees) ?> aktiv</span>
        </h4>
      </div>
      <div class="p-3 space-y-1" id="partnerStatus">
        <?php foreach ($employees as $emp):
          $runningJob = one("SELECT j_id, job_status, start_time, j_time FROM jobs WHERE emp_id_fk=? AND j_date=? AND status=1 AND job_status IN ('RUNNING','STARTED') LIMIT 1", [$emp['emp_id'], $today]);
          $confirmedJob = one("SELECT j_id, j_time FROM jobs WHERE emp_id_fk=? AND j_date=? AND status=1 AND job_status='CONFIRMED' ORDER BY j_time LIMIT 1", [$emp['emp_id'], $today]);
          $status = $runningJob ? 'online' : ($confirmedJob ? 'away' : 'offline');
          $statusText = $runningJob ? 'Arbeitet seit '.substr($runningJob['start_time'] ?: $runningJob['j_time'],0,5) : ($confirmedJob ? 'Nächster: '.substr($confirmedJob['j_time'],0,5) : 'Kein Job');
        ?>
        <div class="flex items-center gap-2.5 px-2 py-1.5 rounded-lg hover:bg-gray-50 transition cursor-default">
          <span class="partner-dot <?= $status ?>"></span>
          <div class="flex-1 min-w-0">
            <div class="text-sm font-medium text-gray-900 truncate"><?= e($emp['name'].' '.($emp['surname']??'')) ?></div>
            <div class="text-xs text-gray-400"><?= $statusText ?></div>
          </div>
          <?php if ($emp['phone']): ?>
          <a href="tel:<?= e($emp['phone']) ?>" class="text-gray-300 hover:text-brand transition" title="Anrufen">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
          </a>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Job List -->
    <div class="bg-white rounded-xl border shadow-sm">
      <div class="px-4 py-3 border-b flex items-center justify-between">
        <h4 class="font-semibold text-sm text-gray-900">Heutige Jobs</h4>
        <span class="text-xs font-medium px-2 py-0.5 rounded-full bg-brand-light text-brand"><?= count($activeJobs) ?> aktiv</span>
      </div>
      <div class="p-3 space-y-2 max-h-[calc(100vh-520px)] overflow-y-auto" id="jobListItems">
        <?php if (empty($activeJobs)): ?>
        <div class="text-center py-6">
          <svg class="w-10 h-10 text-gray-200 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
          <p class="text-sm text-gray-400">Keine aktiven Jobs heute</p>
        </div>
        <?php else: ?>
        <?php foreach ($activeJobs as $j):
          $isRunning = in_array($j['job_status'], ['RUNNING','STARTED']);
          $isConfirmed = $j['job_status'] === 'CONFIRMED';
          $borderColor = $isRunning ? 'border-l-green-500' : ($isConfirmed ? 'border-l-blue-500' : 'border-l-amber-400');
          $bgColor = $isRunning ? 'bg-green-50/50' : ($isConfirmed ? 'bg-blue-50/30' : 'bg-amber-50/30');
          $statusLabel = $isRunning ? 'Laufend' : ($isConfirmed ? 'Bestätigt' : 'Offen');
          $statusBadge = $isRunning ? 'bg-green-100 text-green-700' : ($isConfirmed ? 'bg-blue-100 text-blue-700' : 'bg-amber-100 text-amber-700');
          $timeDisplay = substr($j['j_time'],0,5);
          $duration = $j['j_hours'] ? $j['j_hours'].'h' : '';
        ?>
        <div class="job-card border-l-[3px] <?= $borderColor ?> <?= $bgColor ?> rounded-lg p-3 cursor-pointer" id="jobCard<?= $j['j_id'] ?>">
          <div class="flex items-start justify-between mb-1.5">
            <div class="flex items-center gap-2 cursor-pointer" onclick="focusJob(<?= $j['j_id'] ?>)">
              <span class="text-sm font-semibold text-gray-900"><?= $timeDisplay ?></span>
              <?php if ($duration): ?><span class="text-xs text-gray-400"><?= $duration ?></span><?php endif; ?>
            </div>
            <div class="flex items-center gap-1">
              <span class="text-[11px] font-medium px-1.5 py-0.5 rounded <?= $statusBadge ?>"><?= $statusLabel ?></span>
              <button onclick="openJobEdit(<?= $j['j_id'] ?>)" class="text-gray-300 hover:text-brand transition" title="Bearbeiten">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
              </button>
            </div>
          </div>
          <div class="text-sm font-medium text-gray-800 mb-0.5 cursor-pointer" onclick="focusJob(<?= $j['j_id'] ?>)"><?= e($j['cname']) ?></div>
          <div class="text-xs text-gray-500 mb-1.5"><?= e($j['stitle'] ?: $j['address']) ?></div>
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-1.5">
              <?php if ($j['ename']): ?>
              <div class="w-5 h-5 rounded-full bg-brand flex items-center justify-center text-white text-[10px] font-bold"><?= mb_substr($j['ename'],0,1) ?></div>
              <span class="text-xs text-gray-600"><?= e($j['ename'].' '.mb_substr($j['esurname']??'',0,1).'.') ?></span>
              <?php else: ?>
              <span class="text-xs text-gray-400 italic">Kein Partner</span>
              <?php endif; ?>
            </div>
            <?php if ($j['start_time']): ?>
            <span class="text-[11px] text-green-600 font-mono">▶ <?= substr($j['start_time'],0,5) ?></span>
            <?php endif; ?>
          </div>
          <?php if ($j['sprice']): ?>
          <div class="text-[11px] text-gray-400 mt-1"><?= number_format($j['sprice'],2,',','.') ?> €/h</div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Quick Edit Modal -->
<div id="jobEditModal" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center">
  <div class="bg-white rounded-2xl p-6 w-full max-w-md shadow-2xl m-4">
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-lg font-semibold">Job bearbeiten</h3>
      <button onclick="closeJobEdit()" class="text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
    </div>
    <input type="hidden" id="editJobId"/>
    <div class="space-y-3">
      <div class="grid grid-cols-2 gap-3">
        <div><label class="block text-xs font-medium text-gray-500 mb-1">Status</label>
          <select id="editStatus" class="w-full px-3 py-2 border rounded-lg text-sm">
            <option value="PENDING">Offen</option>
            <option value="CONFIRMED">Bestätigt</option>
            <option value="RUNNING">Laufend</option>
            <option value="COMPLETED">Erledigt</option>
            <option value="CANCELLED">Storniert</option>
          </select>
        </div>
        <div><label class="block text-xs font-medium text-gray-500 mb-1">Partner</label>
          <select id="editPartner" class="w-full px-3 py-2 border rounded-lg text-sm">
            <option value="">— Kein Partner —</option>
            <?php foreach ($employees as $emp): ?>
            <option value="<?= $emp['emp_id'] ?>"><?= e($emp['name'].' '.($emp['surname']??'')) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div><label class="block text-xs font-medium text-gray-500 mb-1">Uhrzeit</label>
          <input type="time" id="editTime" class="w-full px-3 py-2 border rounded-lg text-sm"/>
        </div>
        <div><label class="block text-xs font-medium text-gray-500 mb-1">Stunden</label>
          <input type="number" id="editHours" step="0.5" min="0.5" class="w-full px-3 py-2 border rounded-lg text-sm"/>
        </div>
      </div>
      <div><label class="block text-xs font-medium text-gray-500 mb-1">Adresse</label>
        <input type="text" id="editAddress" class="w-full px-3 py-2 border rounded-lg text-sm"/>
      </div>
      <div><label class="block text-xs font-medium text-gray-500 mb-1">Notiz</label>
        <input type="text" id="editNote" class="w-full px-3 py-2 border rounded-lg text-sm" placeholder="Interne Notiz..."/>
      </div>
      <div class="flex gap-3 pt-2">
        <button onclick="closeJobEdit()" class="flex-1 px-4 py-2.5 border rounded-xl">Abbrechen</button>
        <button onclick="saveJobEdit()" class="flex-1 px-4 py-2.5 bg-brand text-white rounded-xl font-medium" id="saveJobBtn">Speichern</button>
      </div>
      <div id="editFeedback" class="hidden text-sm text-center py-2 rounded-lg"></div>
    </div>
  </div>
</div>

<?php
$empsJson = json_encode(array_map(fn($e) => ['id'=>$e['emp_id'],'name'=>$e['name'].' '.($e['surname']??'')], $employees));
$jobsJson = json_encode(array_map(fn($j) => [
    'id' => $j['j_id'],
    'status' => $j['job_status'],
    'time' => substr($j['j_time'],0,5),
    'hours' => $j['j_hours'],
    'customer' => $j['cname'],
    'ctype' => $j['ctype'],
    'service' => $j['stitle'],
    'employee' => $j['ename'] ? $j['ename'].' '.($j['esurname']??'') : '',
    'emp_id' => $j['emp_id'],
    'address' => $j['address'] ?: ($j['sstreet'].' '.$j['scity']),
    'start_location' => $j['start_location'],
    'start_time' => $j['start_time'] ? substr($j['start_time'],0,5) : null,
    'price' => $j['sprice'],
], $activeJobs));
$apiKeyJs = API_KEY;

$script = <<<JS
const map = L.map('map', {
    zoomControl: false
}).setView([52.52, 13.405], 12);

L.control.zoom({ position: 'topright' }).addTo(map);

L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
    attribution: '© <a href="https://www.openstreetmap.org">OSM</a> © <a href="https://carto.com">CARTO</a>',
    maxZoom: 19,
    subdomains: 'abcd'
}).addTo(map);

const jobs = {$jobsJson};
const markers = {};
const gpsMarkers = {};

const statusColors = { RUNNING: '#22c55e', STARTED: '#22c55e', CONFIRMED: '#3b82f6', PENDING: '#f59e0b' };
const statusIcons = { RUNNING: '▶', STARTED: '▶', CONFIRMED: '●', PENDING: '○' };

function colorIcon(color, label) {
    return L.divIcon({
        className: '',
        html: '<div style="width:32px;height:32px;border-radius:10px;background:' + color + ';border:2.5px solid white;box-shadow:0 2px 8px rgba(0,0,0,0.25);display:flex;align-items:center;justify-content:center;color:white;font-size:12px;font-weight:700">' + (label||'') + '</div>',
        iconSize: [32, 32],
        iconAnchor: [16, 16],
        popupAnchor: [0, -18]
    });
}

function gpsIcon(initial) {
    return L.divIcon({
        className: '',
        html: '<div style="width:24px;height:24px;border-radius:50%;background:#8b5cf6;border:2.5px solid white;box-shadow:0 0 0 4px rgba(139,92,246,0.3);display:flex;align-items:center;justify-content:center;color:white;font-size:10px;font-weight:700;animation:markerPulse 2s infinite">' + (initial||'') + '</div>',
        iconSize: [24, 24],
        iconAnchor: [12, 12],
        popupAnchor: [0, -14]
    });
}

function buildPopup(job) {
    const status = {RUNNING:'Laufend',STARTED:'Laufend',CONFIRMED:'Bestätigt',PENDING:'Offen'}[job.status]||job.status;
    const color = statusColors[job.status]||'#999';
    return '<div style="min-width:200px;font-family:Inter,sans-serif">' +
        '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">' +
            '<div style="width:8px;height:8px;border-radius:50%;background:' + color + '"></div>' +
            '<span style="font-weight:600;font-size:14px;color:#111">' + job.customer + '</span>' +
        '</div>' +
        '<div style="font-size:12px;color:#6b7280;margin-bottom:6px">' + (job.service||'') + '</div>' +
        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:4px;font-size:12px">' +
            '<div><span style="color:#9ca3af">Zeit:</span> <b>' + job.time + '</b>' + (job.hours ? ' (' + job.hours + 'h)' : '') + '</div>' +
            '<div><span style="color:#9ca3af">Status:</span> <span style="color:' + color + ';font-weight:600">' + status + '</span></div>' +
            '<div><span style="color:#9ca3af">Partner:</span> ' + (job.employee||'<em style="color:#d1d5db">—</em>') + '</div>' +
            (job.price ? '<div><span style="color:#9ca3af">Preis:</span> ' + parseFloat(job.price).toFixed(2) + ' €/h</div>' : '') +
        '</div>' +
        (job.start_time ? '<div style="margin-top:6px;font-size:11px;color:#22c55e">▶ Gestartet um ' + job.start_time + '</div>' : '') +
        '<div style="margin-top:8px;border-top:1px solid #f3f4f6;padding-top:6px">' +
            '<a href="https://www.google.com/maps/dir/?api=1&destination=' + encodeURIComponent(job.address) + '" target="_blank" style="font-size:11px;color:#3b82f6;text-decoration:none">→ Google Maps Route</a>' +
        '</div>' +
    '</div>';
}

function extractCoords(loc) {
    if (!loc) return null;
    const raw = loc.match(/^(-?\\d+\\.?\\d*),\\s*(-?\\d+\\.?\\d*)$/);
    if (raw) return [parseFloat(raw[1]), parseFloat(raw[2])];
    const gm = loc.match(/query=(-?\\d+\\.?\\d*)[,%20]+(-?\\d+\\.?\\d*)/);
    if (gm) return [parseFloat(gm[1]), parseFloat(gm[2])];
    return null;
}

async function geocodeAndPlace(job) {
    if (!job.address && !job.start_location) return;
    const color = statusColors[job.status] || '#999';
    const initial = job.employee ? job.employee.charAt(0) : (statusIcons[job.status]||'');

    const coords = extractCoords(job.start_location);
    if (coords && !isNaN(coords[0]) && !isNaN(coords[1]) && Math.abs(coords[0]) > 1) {
        const m = L.marker(coords, { icon: colorIcon(color, initial) }).addTo(map);
        m.bindPopup(buildPopup(job));
        markers[job.id] = m;
        return;
    }

    if (!job.address) return;
    try {
        const addr = job.address.replace(/,/g, ' ').replace(/\\s+/g, ' ').trim();
        const resp = await fetch('https://nominatim.openstreetmap.org/search?q=' + encodeURIComponent(addr) + '&format=json&limit=1&countrycodes=de', {
            headers: { 'User-Agent': 'Fleckfrei/1.0' }
        });
        const data = await resp.json();
        if (data[0]) {
            const m = L.marker([data[0].lat, data[0].lon], { icon: colorIcon(color, initial) }).addTo(map);
            m.bindPopup(buildPopup(job));
            markers[job.id] = m;
        }
    } catch(e) {}
}

(async function() {
    for (let i = 0; i < jobs.length; i++) {
        await geocodeAndPlace(jobs[i]);
        if (i < jobs.length - 1) await new Promise(r => setTimeout(r, 300));
    }
    const allMarkers = Object.values(markers);
    if (allMarkers.length > 0) {
        const group = L.featureGroup(allMarkers);
        map.fitBounds(group.getBounds().pad(0.15));
    }
})();

function focusJob(jid) {
    document.querySelectorAll('.job-card').forEach(c => c.classList.remove('ring-2','ring-brand'));
    const card = document.getElementById('jobCard' + jid);
    if (card) { card.classList.add('ring-2','ring-brand'); card.scrollIntoView({behavior:'smooth',block:'nearest'}); }
    const m = markers[jid] || gpsMarkers[jid];
    if (m) { map.setView(m.getLatLng(), 15); m.openPopup(); }
}

function loadGPS() {
    fetch('/api/index.php?action=gps/live&key={$apiKeyJs}')
        .then(r => r.json())
        .then(d => {
            if (!d.success) return;
            Object.values(gpsMarkers).forEach(m => map.removeLayer(m));
            (d.data || []).forEach(pos => {
                const name = (pos.emp_name || '') + ' ' + (pos.emp_surname || '');
                const initial = (pos.emp_name || '?').charAt(0);
                const m = L.marker([pos.lat, pos.lng], { icon: gpsIcon(initial) }).addTo(map);
                const age = Math.round((Date.now() - new Date(pos.created_at).getTime()) / 60000);
                m.bindPopup('<div style="font-family:Inter,sans-serif">' +
                    '<div style="font-weight:600;font-size:13px;margin-bottom:4px">📍 ' + name.trim() + '</div>' +
                    '<div style="font-size:12px;color:#6b7280">Job #' + (pos.j_id||'—') + '</div>' +
                    '<div style="font-size:11px;color:#9ca3af;margin-top:2px">Genauigkeit: ' + (pos.accuracy ? Math.round(pos.accuracy) + 'm' : '?') + ' · vor ' + age + ' Min.</div>' +
                '</div>');
                gpsMarkers[pos.emp_id] = m;
            });
            document.getElementById('lastUpdate').textContent = 'Aktualisiert: ' + new Date().toLocaleTimeString('de-DE',{hour:'2-digit',minute:'2-digit'});
        })
        .catch(() => {});
}
loadGPS();
setInterval(loadGPS, 10000);

// Quick Edit
const allJobs = jobs;
function openJobEdit(jid) {
    const j = allJobs.find(x => x.id === jid);
    if (!j) return;
    document.getElementById('editJobId').value = jid;
    document.getElementById('editStatus').value = j.status;
    document.getElementById('editPartner').value = j.emp_id || '';
    document.getElementById('editTime').value = j.time || '';
    document.getElementById('editHours').value = j.hours || '';
    document.getElementById('editAddress').value = j.address || '';
    document.getElementById('editNote').value = '';
    document.getElementById('editFeedback').classList.add('hidden');
    document.getElementById('jobEditModal').classList.remove('hidden');
}
function closeJobEdit() {
    document.getElementById('jobEditModal').classList.add('hidden');
}
function saveJobEdit() {
    const jid = document.getElementById('editJobId').value;
    const btn = document.getElementById('saveJobBtn');
    const fb = document.getElementById('editFeedback');
    btn.textContent = 'Speichern...'; btn.disabled = true;

    const updates = [
        {field: 'job_status', value: document.getElementById('editStatus').value},
        {field: 'j_time', value: document.getElementById('editTime').value},
        {field: 'j_hours', value: document.getElementById('editHours').value},
        {field: 'address', value: document.getElementById('editAddress').value},
    ];
    const note = document.getElementById('editNote').value;
    if (note) updates.push({field: 'job_note', value: note});

    const empId = document.getElementById('editPartner').value;

    // Send all updates
    const promises = updates.map(u =>
        fetch('/api/index.php?action=jobs/update', {
            method: 'POST',
            headers: {'Content-Type':'application/json','X-API-Key':'{$apiKeyJs}'},
            body: JSON.stringify({j_id: jid, field: u.field, value: u.value})
        }).then(r => r.json())
    );
    // Assign partner
    promises.push(
        fetch('/api/index.php?action=jobs/assign', {
            method: 'POST',
            headers: {'Content-Type':'application/json','X-API-Key':'{$apiKeyJs}'},
            body: JSON.stringify({j_id: jid, emp_id_fk: empId || null})
        }).then(r => r.json())
    );

    Promise.all(promises).then(results => {
        btn.textContent = 'Speichern'; btn.disabled = false;
        const errors = results.filter(r => !r.success);
        if (errors.length === 0) {
            fb.textContent = 'Gespeichert!';
            fb.className = 'text-sm text-center py-2 rounded-lg bg-green-50 text-green-700';
            fb.classList.remove('hidden');
            setTimeout(() => location.reload(), 1000);
        } else {
            fb.textContent = 'Fehler: ' + (errors[0].error || 'Unbekannt');
            fb.className = 'text-sm text-center py-2 rounded-lg bg-red-50 text-red-700';
            fb.classList.remove('hidden');
        }
    }).catch(() => {
        btn.textContent = 'Speichern'; btn.disabled = false;
        fb.textContent = 'Netzwerk-Fehler';
        fb.className = 'text-sm text-center py-2 rounded-lg bg-red-50 text-red-700';
        fb.classList.remove('hidden');
    });
}
JS;
include __DIR__ . '/../includes/footer.php';
?>
