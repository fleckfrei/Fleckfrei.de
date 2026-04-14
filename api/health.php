<?php
/**
 * Health Check API — lightweight (no full-syntax scan!)
 * GET /api/health.php?key=HEALTH_KEY
 */
define("HEALTH_KEY", "flk_health_2026_c9a3f1e7d4b2");
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-cache");

if (($_GET["key"] ?? "") !== HEALTH_KEY) {
    http_response_code(403);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

$t0 = microtime(true);
$results = ["timestamp" => date("c"), "checks" => [], "latency_ms" => []];

// 1) DB ping
$t = microtime(true);
try {
    require_once __DIR__ . "/../includes/config.php";
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
    $pdo->query("SELECT 1");
    $results["checks"]["db"] = "ok";
} catch (Exception $e) {
    $results["checks"]["db"] = "error";
    $results["error_db"] = $e->getMessage();
}
$results["latency_ms"]["db"] = round((microtime(true) - $t) * 1000);

// 2) Disk free
$free = @disk_free_space(__DIR__);
$total = @disk_total_space(__DIR__);
$results["checks"]["disk_gb_free"] = round($free / 1024 / 1024 / 1024, 1);
$results["checks"]["disk_pct_used"] = round((1 - $free / $total) * 100);
$results["checks"]["disk"] = $results["checks"]["disk_pct_used"] < 95 ? "ok" : "warning";

// 3) Secret files present
$secrets = ["stripe-keys", "paypal-keys", "llm-keys"];
foreach ($secrets as $s) {
    $results["checks"]["secret_$s"] = file_exists(__DIR__ . "/../includes/$s.php") ? "ok" : "missing";
}

// 4) Groq reachable
$t = microtime(true);
$ch = curl_init("https://api.groq.com/openai/v1/models");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>3, CURLOPT_HTTPHEADER=>["Authorization: Bearer " . (defined("GROQ_API_KEY") ? GROQ_API_KEY : "")]]);
curl_exec($ch);
$gcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$results["checks"]["groq"] = $gcode === 200 ? "ok" : "error:$gcode";
$results["latency_ms"]["groq"] = round((microtime(true) - $t) * 1000);

$results["latency_ms"]["total"] = round((microtime(true) - $t0) * 1000);
$allOk = !in_array("error", $results["checks"], true) && !in_array("missing", $results["checks"], true);
http_response_code($allOk ? 200 : 503);
echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
