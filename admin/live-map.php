<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Live-Karte'; $page = 'live-map';

// Get today's running + confirmed jobs with GPS
$today = date('Y-m-d');
$activeJobs = all("SELECT j.j_id, j.j_date, j.j_time, j.j_hours, j.job_status, j.address, j.start_time, j.start_location, j.end_location,
    c.name as cname, c.customer_type as ctype,
    e.name as ename, e.surname as esurname, e.emp_id,
    s.title as stitle, s.street as sstreet, s.city as scity
    FROM jobs j
    LEFT JOIN customer c ON j.customer_id_fk=c.customer_id
    LEFT JOIN employee e ON j.emp_id_fk=e.emp_id
    LEFT JOIN services s ON j.s_id_fk=s.s_id
    WHERE j.j_date=? AND j.status=1 AND j.job_status IN ('PENDING','CONFIRMED','RUNNING','STARTED')
    ORDER BY j.j_time", [$today]);

$employees = all("SELECT emp_id, name, surname FROM employee WHERE status=1 ORDER BY name");

include __DIR__ . '/../includes/layout.php';
?>

<!-- Leaflet CSS + JS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<div class="grid grid-cols-1 lg:grid-cols-4 gap-4">
  <!-- Map -->
  <div class="lg:col-span-3">
    <div class="bg-white rounded-xl border overflow-hidden">
      <div class="p-3 border-b flex items-center justify-between">
        <h3 class="font-semibold">Partner & Jobs — <?= date('d.m.Y') ?></h3>
        <div class="flex items-center gap-2 text-xs">
          <span class="flex items-center gap-1"><span class="w-3 h-3 rounded-full bg-green-500 inline-block"></span> Laufend</span>
          <span class="flex items-center gap-1"><span class="w-3 h-3 rounded-full bg-blue-500 inline-block"></span> Bestätigt</span>
          <span class="flex items-center gap-1"><span class="w-3 h-3 rounded-full bg-amber-500 inline-block"></span> Offen</span>
          <span class="flex items-center gap-1"><span class="w-3 h-3 rounded-full bg-purple-500 inline-block"></span> Partner GPS</span>
        </div>
      </div>
      <div id="map" style="height:550px"></div>
    </div>
  </div>

  <!-- Job List Sidebar -->
  <div class="space-y-3" id="jobList">
    <div class="bg-white rounded-xl border p-4">
      <h4 class="font-semibold text-sm mb-2">Heute: <?= count($activeJobs) ?> aktive Jobs</h4>
      <div class="space-y-2" id="jobListItems">
        <?php foreach ($activeJobs as $j):
          $statusColor = match($j['job_status']) { 'RUNNING','STARTED' => 'green', 'CONFIRMED' => 'blue', default => 'amber' };
        ?>
        <div class="bg-<?= $statusColor ?>-50 rounded-lg p-2 cursor-pointer hover:ring-1 hover:ring-<?= $statusColor ?>-300 transition text-xs" onclick="focusJob(<?= $j['j_id'] ?>)">
          <div class="font-medium"><?= substr($j['j_time'],0,5) ?> — <?= e($j['cname']) ?></div>
          <div class="text-gray-500"><?= e($j['stitle'] ?: $j['address']) ?></div>
          <div class="flex justify-between mt-1">
            <span><?= e($j['ename'] ? $j['ename'].' '.($j['esurname']??'') : 'Kein MA') ?></span>
            <span class="font-medium text-<?= $statusColor ?>-700"><?= match($j['job_status']) { 'RUNNING','STARTED' => 'Laufend', 'CONFIRMED' => 'Bestätigt', default => 'Offen' } ?></span>
          </div>
          <?php if ($j['start_time']): ?><div class="text-gray-400 mt-0.5">Start: <?= substr($j['start_time'],0,5) ?></div><?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if (empty($activeJobs)): ?>
        <p class="text-gray-400 text-sm">Keine aktiven Jobs heute.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php
$jobsJson = json_encode(array_map(fn($j) => [
    'id' => $j['j_id'],
    'status' => $j['job_status'],
    'time' => substr($j['j_time'],0,5),
    'customer' => $j['cname'],
    'service' => $j['stitle'],
    'employee' => $j['ename'] ? $j['ename'].' '.($j['esurname']??'') : '',
    'emp_id' => $j['emp_id'],
    'address' => $j['address'] ?: ($j['sstreet'].' '.$j['scity']),
    'start_location' => $j['start_location'],
    'start_time' => $j['start_time'] ? substr($j['start_time'],0,5) : null,
], $activeJobs));
$apiKeyJs = API_KEY;

$script = <<<JS
const map = L.map('map').setView([52.52, 13.405], 12); // Berlin
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap',
    maxZoom: 19
}).addTo(map);

const jobs = $jobsJson;
const markers = {};
const gpsMarkers = {};

// Color icons
function colorIcon(color) {
    return L.divIcon({
        className: '',
        html: '<div style="width:28px;height:28px;border-radius:50%;background:' + color + ';border:3px solid white;box-shadow:0 2px 6px rgba(0,0,0,0.3);display:flex;align-items:center;justify-content:center;color:white;font-size:11px;font-weight:700"></div>',
        iconSize: [28, 28],
        iconAnchor: [14, 14],
        popupAnchor: [0, -16]
    });
}
function gpsIcon() {
    return L.divIcon({
        className: '',
        html: '<div style="width:20px;height:20px;border-radius:50%;background:#8b5cf6;border:3px solid white;box-shadow:0 0 0 3px rgba(139,92,246,0.3);animation:pulse 2s infinite"></div>',
        iconSize: [20, 20],
        iconAnchor: [10, 10],
        popupAnchor: [0, -12]
    });
}

const statusColors = { RUNNING: '#22c55e', STARTED: '#22c55e', CONFIRMED: '#3b82f6', PENDING: '#f59e0b' };

// Geocode addresses and place markers
async function geocodeAndPlace(job) {
    if (!job.address) return;

    // If we have start GPS, place that
    if (job.start_location && job.start_location.includes(',')) {
        const [lat, lng] = job.start_location.split(',').map(Number);
        const m = L.marker([lat, lng], { icon: colorIcon(statusColors[job.status] || '#999') }).addTo(map);
        m.bindPopup('<b>' + job.time + ' — ' + job.customer + '</b><br>' + (job.service||'') + '<br>Partner: ' + (job.employee||'—') + '<br>Status: ' + job.status + (job.start_time ? '<br>Start: ' + job.start_time : ''));
        markers[job.id] = m;
        return;
    }

    // Geocode address
    try {
        const resp = await fetch('https://nominatim.openstreetmap.org/search?q=' + encodeURIComponent(job.address + ', Berlin') + '&format=json&limit=1', {
            headers: { 'User-Agent': 'Fleckfrei/1.0' }
        });
        const data = await resp.json();
        if (data[0]) {
            const m = L.marker([data[0].lat, data[0].lon], { icon: colorIcon(statusColors[job.status] || '#999') }).addTo(map);
            m.bindPopup('<b>' + job.time + ' — ' + job.customer + '</b><br>' + (job.service||job.address) + '<br>Partner: ' + (job.employee||'—') + '<br>Status: ' + job.status);
            markers[job.id] = m;
        }
    } catch(e) {}
}

// Place all job markers (with small delay to respect Nominatim rate limit)
(async function() {
    for (let i = 0; i < jobs.length; i++) {
        await geocodeAndPlace(jobs[i]);
        if (i < jobs.length - 1) await new Promise(r => setTimeout(r, 300));
    }
    // Fit bounds if we have markers
    const allMarkers = Object.values(markers);
    if (allMarkers.length > 0) {
        const group = L.featureGroup(allMarkers);
        map.fitBounds(group.getBounds().pad(0.1));
    }
})();

// Focus on a job
function focusJob(jid) {
    const m = markers[jid] || gpsMarkers[jid];
    if (m) { map.setView(m.getLatLng(), 16); m.openPopup(); }
}

// Live GPS tracking — poll every 10s
function loadGPS() {
    fetch('/api/index.php?action=gps/live&key=$apiKeyJs')
        .then(r => r.json())
        .then(d => {
            if (!d.success) return;
            // Remove old GPS markers
            Object.values(gpsMarkers).forEach(m => map.removeLayer(m));

            (d.data || []).forEach(pos => {
                const m = L.marker([pos.lat, pos.lng], { icon: gpsIcon() }).addTo(map);
                const name = (pos.emp_name || '') + ' ' + (pos.emp_surname || '');
                const age = Math.round((Date.now() - new Date(pos.created_at).getTime()) / 60000);
                m.bindPopup('<b>📍 ' + name.trim() + '</b><br>Job #' + (pos.j_id||'—') + '<br>Genauigkeit: ' + (pos.accuracy ? Math.round(pos.accuracy) + 'm' : '?') + '<br>Vor ' + age + ' Min.');
                gpsMarkers[pos.emp_id] = m;
            });
        })
        .catch(() => {});
}
loadGPS();
setInterval(loadGPS, 10000);

// Add pulse animation
const style = document.createElement('style');
style.textContent = '@keyframes pulse{0%{box-shadow:0 0 0 0 rgba(139,92,246,0.4)}70%{box-shadow:0 0 0 10px rgba(139,92,246,0)}100%{box-shadow:0 0 0 0 rgba(139,92,246,0)}}';
document.head.appendChild(style);
JS;
include __DIR__ . '/../includes/footer.php';
?>
