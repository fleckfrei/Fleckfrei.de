<?php
/**
 * Partner-Automation-Endpoint — für iOS Shortcuts / Android Tasker / NFC-Tags / Geofencing
 *
 * Nutzung: GET/POST /api/partner-auto.php?token=XXX&action=start|stop|arrived&j_id=123
 * Token = `employee.auto_token` (separat von Passwort, nur für Automation)
 *
 * Beispiel iOS Shortcut:
 *   1. "When I arrive at [Job-Adresse]"
 *   2. → "Get Contents of URL: https://app.fleckfrei.de/api/partner-auto.php?token=abc123&action=arrived"
 *   3. Optional: "Show Notification: Job gestartet"
 */
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

try {
    // Token-based Auth (nicht Session)
    $token = $_GET['token'] ?? $_POST['token'] ?? '';
    if (!$token) throw new Exception('Need token');

    $emp = one("SELECT emp_id, name FROM employee WHERE auto_token=? AND status=1 LIMIT 1", [$token]);
    if (!$emp) throw new Exception('Invalid token');
    $empId = (int)$emp['emp_id'];

    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    $jobId = (int)($_GET['j_id'] ?? $_POST['j_id'] ?? 0);
    $lat = (float)($_GET['lat'] ?? $_POST['lat'] ?? 0);
    $lng = (float)($_GET['lng'] ?? $_POST['lng'] ?? 0);
    $location = $lat && $lng ? "$lat,$lng" : '';

    // Auto-find current job if no j_id given (nächster Job heute)
    if (!$jobId) {
        $job = one("SELECT j_id, j_time, job_status FROM jobs WHERE emp_id_fk=? AND j_date=CURDATE() AND status=1 AND job_status NOT IN ('CANCELLED','COMPLETED') ORDER BY FIELD(job_status,'RUNNING','STARTED','CONFIRMED','PENDING'), j_time LIMIT 1", [$empId]);
        if (!$job) throw new Exception('Keine offenen Jobs heute');
        $jobId = (int)$job['j_id'];
    } else {
        // Sicherstellen dass Job dem Partner gehört
        $job = one("SELECT j_id, job_status FROM jobs WHERE j_id=? AND emp_id_fk=? AND status=1", [$jobId, $empId]);
        if (!$job) throw new Exception('Job nicht zugewiesen');
    }

    $result = [];
    switch ($action) {
        case 'start':
        case 'arrived':
            if ($job['job_status'] === 'RUNNING') {
                $result = ['status' => 'already_running', 'message' => 'Job läuft bereits'];
                break;
            }
            q("UPDATE jobs SET job_status='RUNNING', start_time=?, start_location=? WHERE j_id=?",
              [date('H:i:s'), $location, $jobId]);
            audit('auto_start', 'job', $jobId, "Partner: {$emp['name']} (via automation)");
            telegramNotify("▶️ <b>Auto-Start</b>\n\n👷 {$emp['name']} · Job #{$jobId}\n⏰ " . date('H:i') . ($location ? "\n📍 $location" : ''));
            $result = ['status' => 'started', 'j_id' => $jobId, 'start_time' => date('H:i'), 'message' => 'Job gestartet'];
            break;

        case 'stop':
        case 'complete':
            if ($job['job_status'] === 'COMPLETED') {
                $result = ['status' => 'already_completed', 'message' => 'Job bereits erledigt'];
                break;
            }
            $endTime = date('H:i:s');
            $jobData = one("SELECT start_time, j_date FROM jobs WHERE j_id=?", [$jobId]);
            $totalHours = null;
            if (!empty($jobData['start_time'])) {
                $s = new DateTime($jobData['j_date'] . ' ' . $jobData['start_time']);
                $e = new DateTime($jobData['j_date'] . ' ' . $endTime);
                $diff = $s->diff($e);
                $totalHours = round(($diff->h + $diff->i / 60 + $diff->s / 3600), 2);
            }
            q("UPDATE jobs SET job_status='COMPLETED', end_time=?, end_location=?, total_hours=? WHERE j_id=?",
              [$endTime, $location, $totalHours, $jobId]);
            audit('auto_stop', 'job', $jobId, "Partner: {$emp['name']} · {$totalHours}h (via automation)");
            telegramNotify("✅ <b>Auto-Stop</b>\n\n👷 {$emp['name']} · Job #{$jobId}\n⏰ " . date('H:i') . " ({$totalHours}h)");
            $result = ['status' => 'completed', 'j_id' => $jobId, 'end_time' => date('H:i'), 'total_hours' => $totalHours, 'message' => 'Job abgeschlossen'];
            break;

        case 'gps':
            // Nur GPS-Update ohne Status-Change — für Live-Tracking
            global $dbLocal;
            try {
                $dbLocal->exec("CREATE TABLE IF NOT EXISTS gps_tracking (
                    id INT AUTO_INCREMENT PRIMARY KEY, emp_id INT NOT NULL, lat DOUBLE, lng DOUBLE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_emp (emp_id))");
                $dbLocal->prepare("INSERT INTO gps_tracking (emp_id, lat, lng) VALUES (?,?,?)")->execute([$empId, $lat, $lng]);
            } catch (Exception $e) {}
            $result = ['status' => 'gps_updated', 'emp_id' => $empId];
            break;

        default:
            throw new Exception('Unknown action: ' . $action);
    }

    echo json_encode(['success' => true] + $result);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
