<?php
/**
 * Gotham Mass Ingest — import EVERY known identity into the ontology.
 *
 * Sources:
 *   1. customer table           → person + has_email + has_phone + lives_at
 *   2. employee table           → person + has_email + has_phone
 *   3. customer_address table   → address objects + lives_at links
 *   4. past osint_scans         → person + identifiers + deep_scan_data parsing
 *   5. messages / chat_messages → person mentions (if present)
 *
 * Run:
 *   Web:  /admin/scripts/run-ingest.php?key=<INGEST_KEY>   (optional wrapper)
 *   CLI:  php scripts/gotham_ingest_all.php [--dry] [--source=customer|employee|scans|all]
 *
 * Safe to re-run — upsert semantics merge sources, inflate source_count,
 * and recalculate confidence without duplicating rows.
 */

// Support both CLI and admin-web invocation
$isCli = (php_sapi_name() === 'cli');

if ($isCli) {
    define('RUNNING_FROM_CLI', true);
    chdir(__DIR__);
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/ontology.php';
} else {
    require_once __DIR__ . '/../includes/auth.php';
    requireAdmin();
    require_once __DIR__ . '/../includes/ontology.php';
    header('Content-Type: text/plain; charset=utf-8');
}

// Parse args
$dryRun = false;
$source = 'all';
if ($isCli) {
    foreach ($argv as $a) {
        if ($a === '--dry') $dryRun = true;
        if (strpos($a, '--source=') === 0) $source = substr($a, 9);
    }
} else {
    $dryRun = !empty($_GET['dry']);
    $source = $_GET['source'] ?? 'all';
}

$stats = [
    'customer'  => ['rows' => 0, 'objects' => 0, 'links' => 0, 'events' => 0, 'errors' => 0],
    'employee'  => ['rows' => 0, 'objects' => 0, 'links' => 0, 'events' => 0, 'errors' => 0],
    'addresses' => ['rows' => 0, 'objects' => 0, 'links' => 0, 'errors' => 0],
    'scans'     => ['rows' => 0, 'objects' => 0, 'links' => 0, 'events' => 0, 'errors' => 0],
];
$startTs = microtime(true);

function out(string $msg): void {
    echo $msg . "\n";
    @ob_flush(); @flush();
}

out("== GOTHAM MASS INGEST ==");
out("Mode: " . ($dryRun ? "DRY RUN (no writes)" : "LIVE"));
out("Source filter: $source");
out("");

// ============================================================
// 1. CUSTOMER TABLE
// ============================================================
if ($source === 'all' || $source === 'customer') {
    out(">> Customers");
    try {
        $rows = all("SELECT c.customer_id, c.name, c.email, c.phone, c.customer_type,
                            c.created_at,
                            ca.street, ca.number as addr_nr, ca.postal_code, ca.city, ca.country
                     FROM customer c
                     LEFT JOIN customer_address ca ON ca.customer_id_fk = c.customer_id
                     WHERE c.status = 1 AND c.name IS NOT NULL AND c.name != ''");
        $seen = [];
        foreach ($rows as $r) {
            $stats['customer']['rows']++;
            if (isset($seen[$r['customer_id']])) continue;
            $seen[$r['customer_id']] = true;
            if ($dryRun) continue;

            try {
                $personId = ontology_upsert_object('person', $r['name'], [
                    'customer_id' => (int)$r['customer_id'],
                    'customer_type' => $r['customer_type'] ?? '',
                    'source' => 'customer_table',
                ], null, 0.85);
                if (!$personId) continue;
                $stats['customer']['objects']++;

                if (!empty($r['email'])) {
                    $eId = ontology_upsert_object('email', $r['email'], [], null, 0.9);
                    ontology_upsert_link($personId, $eId, 'has_email', 'customer_table', 0.95);
                    $stats['customer']['objects']++;
                    $stats['customer']['links']++;
                }
                if (!empty($r['phone'])) {
                    $pId = ontology_upsert_object('phone', $r['phone'], [], null, 0.9);
                    ontology_upsert_link($personId, $pId, 'has_phone', 'customer_table', 0.95);
                    $stats['customer']['objects']++;
                    $stats['customer']['links']++;
                }
                if (!empty($r['street']) || !empty($r['city'])) {
                    $addr = trim(($r['street'] ?? '') . ' ' . ($r['addr_nr'] ?? '') . ', ' .
                                 ($r['postal_code'] ?? '') . ' ' . ($r['city'] ?? '') . ' ' . ($r['country'] ?? ''));
                    $addr = preg_replace('/\s*,\s*$/', '', trim($addr, ', '));
                    if (strlen($addr) > 5) {
                        $aId = ontology_upsert_object('address', $addr, [], null, 0.85);
                        ontology_upsert_link($personId, $aId, 'lives_at', 'customer_table', 0.9);
                        $stats['customer']['objects']++;
                        $stats['customer']['links']++;
                    }
                }

                // Enrollment event
                if (!empty($r['created_at'])) {
                    ontology_add_event($personId, 'customer_enrolled',
                        substr($r['created_at'], 0, 10),
                        'Customer enrolled in Fleckfrei',
                        ['customer_id' => $r['customer_id']], 'customer_table');
                    $stats['customer']['events']++;
                }
            } catch (Throwable $e) {
                $stats['customer']['errors']++;
            }
        }
        out("  rows={$stats['customer']['rows']} objects={$stats['customer']['objects']} links={$stats['customer']['links']} events={$stats['customer']['events']} errors={$stats['customer']['errors']}");
    } catch (Exception $e) {
        out("  ERROR: " . $e->getMessage());
    }
}

// ============================================================
// 2. EMPLOYEE TABLE
// ============================================================
if ($source === 'all' || $source === 'employee') {
    out(">> Employees");
    try {
        $rows = all("SELECT emp_id, name, email, phone, created_at FROM employee
                     WHERE status = 1 AND name IS NOT NULL AND name != ''");
        foreach ($rows as $r) {
            $stats['employee']['rows']++;
            if ($dryRun) continue;
            try {
                $personId = ontology_upsert_object('person', $r['name'], [
                    'employee_id' => (int)$r['emp_id'],
                    'role' => 'partner',
                    'source' => 'employee_table',
                ], null, 0.9);
                if (!$personId) continue;
                $stats['employee']['objects']++;

                if (!empty($r['email'])) {
                    $eId = ontology_upsert_object('email', $r['email'], [], null, 0.95);
                    ontology_upsert_link($personId, $eId, 'has_email', 'employee_table', 0.95);
                    $stats['employee']['objects']++;
                    $stats['employee']['links']++;
                }
                if (!empty($r['phone'])) {
                    $pId = ontology_upsert_object('phone', $r['phone'], [], null, 0.95);
                    ontology_upsert_link($personId, $pId, 'has_phone', 'employee_table', 0.95);
                    $stats['employee']['objects']++;
                    $stats['employee']['links']++;
                }
                // Link to Fleckfrei as organization
                $orgId = ontology_upsert_object('company', '<?= SITE ?>', ['internal' => true], null, 0.99);
                ontology_upsert_link($personId, $orgId, 'works_at', 'employee_table', 0.99);
                $stats['employee']['links']++;

                if (!empty($r['created_at'])) {
                    ontology_add_event($personId, 'employee_joined',
                        substr($r['created_at'], 0, 10),
                        'Employee joined Fleckfrei', [], 'employee_table');
                    $stats['employee']['events']++;
                }
            } catch (Throwable $e) {
                $stats['employee']['errors']++;
            }
        }
        out("  rows={$stats['employee']['rows']} objects={$stats['employee']['objects']} links={$stats['employee']['links']} events={$stats['employee']['events']} errors={$stats['employee']['errors']}");
    } catch (Exception $e) {
        out("  ERROR: " . $e->getMessage());
    }
}

// ============================================================
// 3. PAST osint_scans — bulk import every historical scan
// ============================================================
if ($source === 'all' || $source === 'scans') {
    out(">> Past osint_scans");
    try {
        $scanRows = all("SELECT scan_id, scan_name, scan_email, scan_phone, scan_address, deep_scan_data
                         FROM osint_scans
                         WHERE (scan_name IS NOT NULL AND scan_name != '')
                            OR (scan_email IS NOT NULL AND scan_email != '')
                         LIMIT 500");
        foreach ($scanRows as $scan) {
            $stats['scans']['rows']++;
            if ($dryRun) continue;
            try {
                $primary = $scan['scan_name'] ?: $scan['scan_email'] ?: $scan['scan_phone'] ?: $scan['scan_address'];
                if (!$primary) continue;
                $rootId = ontology_upsert_object('person', $primary,
                    ['from_scan_id' => (int)$scan['scan_id']], (int)$scan['scan_id'], 0.7);
                if (!$rootId) continue;
                $stats['scans']['objects']++;

                if (!empty($scan['scan_email'])) {
                    $eId = ontology_upsert_object('email', $scan['scan_email'], [], (int)$scan['scan_id'], 0.85);
                    ontology_upsert_link($rootId, $eId, 'has_email', 'osint_scans', 0.85);
                    $stats['scans']['objects']++; $stats['scans']['links']++;
                }
                if (!empty($scan['scan_phone'])) {
                    $pId = ontology_upsert_object('phone', $scan['scan_phone'], [], (int)$scan['scan_id'], 0.85);
                    ontology_upsert_link($rootId, $pId, 'has_phone', 'osint_scans', 0.85);
                    $stats['scans']['objects']++; $stats['scans']['links']++;
                }
                if (!empty($scan['scan_address'])) {
                    $aId = ontology_upsert_object('address', $scan['scan_address'], [], (int)$scan['scan_id'], 0.75);
                    ontology_upsert_link($rootId, $aId, 'lives_at', 'osint_scans', 0.8);
                    $stats['scans']['objects']++; $stats['scans']['links']++;
                }

                ontology_add_event($rootId, 'osint_scan_imported', null,
                    'Historical scan #' . $scan['scan_id'] . ' imported',
                    ['scan_id' => (int)$scan['scan_id']], 'mass_ingest');
                $stats['scans']['events']++;
            } catch (Throwable $e) {
                $stats['scans']['errors']++;
            }
        }
        out("  rows={$stats['scans']['rows']} objects={$stats['scans']['objects']} links={$stats['scans']['links']} events={$stats['scans']['events']} errors={$stats['scans']['errors']}");
    } catch (Exception $e) {
        out("  ERROR: " . $e->getMessage());
    }
}

// ============================================================
// Summary
// ============================================================
$elapsed = round(microtime(true) - $startTs, 2);
$totalObj = array_sum(array_column($stats, 'objects'));
$totalLinks = array_sum(array_column($stats, 'links'));
$totalEvents = array_sum(array_map(fn($s) => $s['events'] ?? 0, $stats));
out("");
out("================================================================");
out("TOTAL: $totalObj objects · $totalLinks links · $totalEvents events · {$elapsed}s");
out("================================================================");

if (!$dryRun && !$isCli) {
    // Quick current DB totals
    try {
        $total = (int)valLocal("SELECT COUNT(*) FROM ontology_objects");
        $verified = (int)valLocal("SELECT COUNT(*) FROM ontology_objects WHERE verified=1");
        out("Ontology now contains: $total objects ($verified verified)");
    } catch (Exception $e) {}
}
