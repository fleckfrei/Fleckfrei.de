<?php
/**
 * Dynamic Pricing API — Self-Learning Algorithm
 * Calculates optimal price based on occupancy, season, demand, customer history.
 *
 * POST /api/pricing.php
 *   body: {"service_id": 123, "customer_id": 456, "date": "2026-04-15", "base_price": 120}
 *   returns: {"price": 138, "multiplier": 1.15, "rules": [...], "occupancy": 72}
 *
 * GET /api/pricing.php?action=rules     — list all rules
 * GET /api/pricing.php?action=history   — pricing history
 * GET /api/pricing.php?action=learn     — run learning algorithm
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/config.php';

$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['key'] ?? '';
session_start();
if (empty($_SESSION['uid']) && $apiKey !== API_KEY) {
    http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit;
}

$action = $_GET['action'] ?? '';

// GET: List rules
if ($action === 'rules') {
    echo json_encode(['success' => true, 'rules' => allLocal("SELECT * FROM pricing_rules WHERE active=1 ORDER BY rule_type, pr_id")]);
    exit;
}

// GET: History
if ($action === 'history') {
    $limit = (int)($_GET['limit'] ?? 50);
    echo json_encode(['success' => true, 'history' => allLocal("SELECT * FROM pricing_history ORDER BY created_at DESC LIMIT ?", [$limit])]);
    exit;
}

// GET: Learn — analyze past pricing and adjust multipliers
if ($action === 'learn') {
    $history = allLocal("SELECT rule_type, AVG(multiplier) as avg_mult, COUNT(*) as uses, SUM(CASE WHEN accepted=1 THEN 1 ELSE 0 END) as accepted, SUM(CASE WHEN accepted=0 THEN 1 ELSE 0 END) as rejected FROM pricing_history WHERE created_at > DATE_SUB(NOW(), INTERVAL 90 DAY) GROUP BY rule_type");
    $recommendations = [];
    foreach ($history as $h) {
        $acceptRate = $h['uses'] > 0 ? round($h['accepted'] / $h['uses'] * 100) : 0;
        if ($acceptRate < 50 && $h['avg_mult'] > 1.1) {
            $recommendations[] = ['rule_type' => $h['rule_type'], 'action' => 'reduce', 'reason' => "Nur {$acceptRate}% akzeptiert bei Faktor " . round($h['avg_mult'], 2), 'suggestion' => round($h['avg_mult'] * 0.95, 3)];
        } elseif ($acceptRate > 90 && $h['avg_mult'] < 1.2) {
            $recommendations[] = ['rule_type' => $h['rule_type'], 'action' => 'increase', 'reason' => "{$acceptRate}% akzeptiert — Preis kann steigen", 'suggestion' => round($h['avg_mult'] * 1.05, 3)];
        }
    }
    echo json_encode(['success' => true, 'analysis' => $history, 'recommendations' => $recommendations, 'data_points' => array_sum(array_column($history, 'uses'))]);
    exit;
}

// POST: Calculate price
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $serviceId = (int)($body['service_id'] ?? 0);
    $customerId = (int)($body['customer_id'] ?? 0);
    $date = $body['date'] ?? date('Y-m-d');
    $basePrice = (float)($body['base_price'] ?? 0);

    // Auto-detect base price from service
    if (!$basePrice && $serviceId) {
        $svc = one("SELECT total_price FROM services WHERE s_id=?", [$serviceId]);
        $basePrice = (float)($svc['total_price'] ?? 0);
    }
    if ($basePrice <= 0) {
        echo json_encode(['error' => 'base_price required']); exit;
    }

    // Calculate occupancy for the week
    $weekStart = date('Y-m-d', strtotime('monday this week', strtotime($date)));
    $weekEnd = date('Y-m-d', strtotime('sunday this week', strtotime($date)));
    $weekJobs = (int)val("SELECT COUNT(*) FROM jobs WHERE j_date BETWEEN ? AND ? AND status=1 AND job_status NOT IN ('CANCELLED')", [$weekStart, $weekEnd]);
    $avgWeekJobs = (float)val("SELECT AVG(cnt) FROM (SELECT COUNT(*) as cnt FROM jobs WHERE status=1 AND job_status NOT IN ('CANCELLED') AND j_date > DATE_SUB(NOW(), INTERVAL 90 DAY) GROUP BY YEARWEEK(j_date)) sub") ?: 10;
    $occupancyPct = min(100, round(($weekJobs / max($avgWeekJobs, 1)) * 100));

    // Customer history
    $customerJobs = $customerId ? (int)val("SELECT COUNT(*) FROM jobs WHERE customer_id_fk=? AND status=1", [$customerId]) : 0;

    // Get active rules
    $rules = allLocal("SELECT * FROM pricing_rules WHERE active=1");
    $appliedRules = [];
    $finalMultiplier = 1.0;
    $dayOfWeek = date('N', strtotime($date)); // 1=Mon, 7=Sun
    $month = (int)date('n', strtotime($date));

    foreach ($rules as $rule) {
        $apply = false;
        switch ($rule['rule_type']) {
            case 'weekend':
                $apply = ($dayOfWeek >= 6); // Sat/Sun
                break;
            case 'season':
                if ($rule['start_date'] && $rule['end_date']) {
                    $apply = ($date >= $rule['start_date'] && $date <= $rule['end_date']);
                } elseif (str_contains($rule['name'], 'Sommer')) {
                    $apply = ($month >= 6 && $month <= 8);
                } elseif (str_contains($rule['name'], 'Weihnacht')) {
                    $apply = ($month === 12);
                }
                break;
            case 'demand':
                if (str_contains($rule['name'], '>80')) $apply = ($occupancyPct > 80);
                elseif (str_contains($rule['name'], '<30')) $apply = ($occupancyPct < 30);
                break;
            case 'special':
                if (str_contains($rule['name'], 'Neukunde')) $apply = ($customerJobs === 0);
                elseif (str_contains($rule['name'], 'Stammkunde')) $apply = ($customerJobs > 10);
                break;
        }
        if ($apply) {
            $finalMultiplier *= (float)$rule['multiplier'];
            $appliedRules[] = ['name' => $rule['name'], 'multiplier' => (float)$rule['multiplier']];
        }
    }

    $finalPrice = round($basePrice * $finalMultiplier, 2);

    // Save to history for learning
    try {
        qLocal("INSERT INTO pricing_history (service_id, customer_id, base_price, final_price, multiplier, rules_applied, occupancy_pct, job_date) VALUES (?,?,?,?,?,?,?,?)",
            [$serviceId, $customerId, $basePrice, $finalPrice, $finalMultiplier, json_encode($appliedRules), $occupancyPct, $date]);
    } catch (Exception $e) {}

    echo json_encode([
        'success' => true,
        'base_price' => $basePrice,
        'final_price' => $finalPrice,
        'multiplier' => round($finalMultiplier, 3),
        'rules_applied' => $appliedRules,
        'occupancy_pct' => $occupancyPct,
        'customer_jobs' => $customerJobs,
        'date' => $date,
        'day' => ['Mo','Di','Mi','Do','Fr','Sa','So'][$dayOfWeek - 1],
    ]);
    exit;
}

echo json_encode(['error' => 'POST with body or GET with ?action=rules|history|learn']);
