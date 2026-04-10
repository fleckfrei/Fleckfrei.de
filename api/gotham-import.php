<?php
/**
 * Gotham CSV / Sheet Importer
 *
 * Ingests a CSV file (uploaded multipart, OR fetched from a public
 * Google Sheet export URL) into the ontology, using a flexible
 * column mapping that the caller provides.
 *
 * POST multipart:
 *   file:   the CSV (max 5MB)
 *   mapping: JSON { "person_name_col": "Name", "email_col": "Email", ... }
 *   source_label: "Master Credentials Sheet"
 *
 * POST JSON (for public Google Sheets):
 *   {
 *     "sheet_url":    "https://docs.google.com/spreadsheets/d/ID/export?format=csv",
 *     "mapping":      { ... },
 *     "source_label": "..."
 *   }
 *
 * Column mapping fields (all optional, at least one required):
 *   name_col        → person display_name
 *   email_col       → linked email object
 *   phone_col       → linked phone object
 *   address_col     → linked address object
 *   company_col     → linked company object
 *   domain_col      → linked domain
 *   handle_col      → linked social handle
 *   title_col       → stored as property on person
 *   notes_col       → stored as property on person
 */

ini_set('max_execution_time', 300);
ini_set('memory_limit', '256M');
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/ontology.php';

db_ping_reconnect();
session_start();
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (empty($_SESSION['uid']) && $apiKey !== API_KEY) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

// ============================================================
// Read CSV data — either from uploaded file or fetched URL
// ============================================================
$csvRaw = '';
$mapping = [];
$sourceLabel = 'csv_import';

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'multipart/form-data') !== false) {
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['error' => 'file upload failed or missing']);
        exit;
    }
    if ($_FILES['file']['size'] > 5 * 1024 * 1024) {
        echo json_encode(['error' => 'file too large (max 5MB)']);
        exit;
    }
    $csvRaw = file_get_contents($_FILES['file']['tmp_name']);
    $mapping = json_decode($_POST['mapping'] ?? '{}', true) ?: [];
    $sourceLabel = trim($_POST['source_label'] ?? 'csv_import');
} else {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $sheetUrl = trim($body['sheet_url'] ?? '');
    $mapping = $body['mapping'] ?? [];
    $sourceLabel = trim($body['source_label'] ?? 'sheet_import');

    if ($sheetUrl === '') {
        echo json_encode(['error' => 'sheet_url required (or upload a file via multipart)']);
        exit;
    }
    // Only allow docs.google.com + direct CSV URLs — prevent SSRF
    if (!preg_match('#^https://(docs\.google\.com|raw\.githubusercontent\.com)/#i', $sheetUrl)) {
        echo json_encode(['error' => 'sheet_url must be a Google Sheets export or raw GitHub URL']);
        exit;
    }
    $ch = curl_init($sheetUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $csvRaw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$csvRaw) {
        echo json_encode(['error' => "fetch failed (HTTP $code) — ensure sheet is shared publicly"]);
        exit;
    }
}

// ============================================================
// Parse CSV — handle commas + semicolons + quoted fields
// ============================================================
if (trim($csvRaw) === '') {
    echo json_encode(['error' => 'empty CSV data']);
    exit;
}

// Detect delimiter (count commas vs semicolons in first line)
$firstLine = substr($csvRaw, 0, strpos($csvRaw, "\n") ?: strlen($csvRaw));
$delim = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

$lines = preg_split('/\r\n|\r|\n/', $csvRaw);
$headers = [];
$records = [];
foreach ($lines as $i => $line) {
    if (trim($line) === '') continue;
    $fields = str_getcsv($line, $delim);
    if (empty($headers)) {
        $headers = array_map('trim', $fields);
        continue;
    }
    if (count($fields) < count($headers)) {
        $fields = array_pad($fields, count($headers), '');
    }
    $records[] = array_combine($headers, array_slice($fields, 0, count($headers)));
}

if (empty($records)) {
    echo json_encode(['error' => 'no data rows found']);
    exit;
}

// ============================================================
// Resolve column mapping — try provided names first, then autodetect
// ============================================================
// find_col: if explicit mapping provided, honor it exactly (skip auto-detect).
// When auto-detecting, skip columns already claimed by another explicit mapping
// to prevent the same header matching multiple roles (e.g. "Name" being picked
// as both name_col and company_col).
function find_col(array $headers, ?string $wanted, array $fallbacks, array $claimed, bool $hasExplicit): ?string {
    if ($wanted !== null && $wanted !== '') {
        return in_array($wanted, $headers, true) ? $wanted : null;
    }
    // Caller gave no explicit mapping for this role — try auto-detect
    foreach ($fallbacks as $fb) {
        foreach ($headers as $h) {
            if (in_array($h, $claimed, true)) continue; // already used elsewhere
            if (stripos($h, $fb) !== false) return $h;
        }
    }
    return null;
}

// Collect all explicitly-claimed columns first so auto-detect doesn't
// steal them for another role
$claimed = array_filter([
    $mapping['name_col']    ?? null,
    $mapping['email_col']   ?? null,
    $mapping['phone_col']   ?? null,
    $mapping['address_col'] ?? null,
    $mapping['company_col'] ?? null,
    $mapping['domain_col']  ?? null,
    $mapping['handle_col']  ?? null,
    $mapping['title_col']   ?? null,
    $mapping['notes_col']   ?? null,
]);
$hasExplicit = !empty($mapping);

$cols = [
    'name'    => find_col($headers, $mapping['name_col']    ?? null, ['name', 'firstname', 'full name', 'customer', 'kunde'], $claimed, $hasExplicit),
    'email'   => find_col($headers, $mapping['email_col']   ?? null, ['email', 'mail', 'e-mail'], $claimed, $hasExplicit),
    'phone'   => find_col($headers, $mapping['phone_col']   ?? null, ['phone', 'telefon', 'mobil', 'handy', 'tel'], $claimed, $hasExplicit),
    'address' => find_col($headers, $mapping['address_col'] ?? null, ['address', 'adresse', 'street', 'straße'], $claimed, $hasExplicit),
    'company' => find_col($headers, $mapping['company_col'] ?? null, ['company', 'firma', 'organization', 'organisation'], $claimed, $hasExplicit),
    'domain'  => find_col($headers, $mapping['domain_col']  ?? null, ['domain', 'website', 'url', 'site'], $claimed, $hasExplicit),
    'handle'  => find_col($headers, $mapping['handle_col']  ?? null, ['handle', 'username', 'login', 'screen name', 'nick'], $claimed, $hasExplicit),
    'title'   => find_col($headers, $mapping['title_col']   ?? null, ['title', 'role', 'position', 'job'], $claimed, $hasExplicit),
    'notes'   => find_col($headers, $mapping['notes_col']   ?? null, ['notes', 'comment', 'description'], $claimed, $hasExplicit),
];

if (!$cols['name'] && !$cols['email'] && !$cols['phone'] && !$cols['company']) {
    echo json_encode([
        'error'       => 'no usable columns found — need at least one of name/email/phone/company',
        'headers'     => $headers,
        'resolved'    => $cols,
        'hint'        => 'Provide mapping: {"name_col":"Exact Header", ...}',
    ]);
    exit;
}

// ============================================================
// Ingest — iterate rows, upsert objects, link them
// ============================================================
$stats = ['rows' => 0, 'objects' => 0, 'links' => 0, 'events' => 0, 'errors' => 0, 'skipped' => 0];
$sourceTag = 'csv:' . $sourceLabel;
$startTs = microtime(true);
// Synthetic scan_id for the ENTIRE import operation: all rows share
// this ID so dedup inside ontology_upsert_object works correctly
// (otherwise 432 rows inflate source_count by 432 for shared emails,
// or worse: null-scanId calls skip increment entirely when the object
// already has any scan_id in its source_scans array).
$importScanId = (int)(time() * 1000 + mt_rand(0, 999));

foreach ($records as $rIdx => $row) {
    $stats['rows']++;
    try {
        $name    = isset($cols['name'])    && $cols['name']    ? trim((string)($row[$cols['name']]    ?? '')) : '';
        $email   = isset($cols['email'])   && $cols['email']   ? trim((string)($row[$cols['email']]   ?? '')) : '';
        $phone   = isset($cols['phone'])   && $cols['phone']   ? trim((string)($row[$cols['phone']]   ?? '')) : '';
        $address = isset($cols['address']) && $cols['address'] ? trim((string)($row[$cols['address']] ?? '')) : '';
        $company = isset($cols['company']) && $cols['company'] ? trim((string)($row[$cols['company']] ?? '')) : '';
        $domain  = isset($cols['domain'])  && $cols['domain']  ? trim((string)($row[$cols['domain']]  ?? '')) : '';
        $handle  = isset($cols['handle'])  && $cols['handle']  ? trim((string)($row[$cols['handle']]  ?? '')) : '';
        $title   = isset($cols['title'])   && $cols['title']   ? trim((string)($row[$cols['title']]   ?? '')) : '';
        $notes   = isset($cols['notes'])   && $cols['notes']   ? trim((string)($row[$cols['notes']]   ?? '')) : '';

        // Pick a root identifier
        $rootType = $name ? 'person' : ($company ? 'company' : ($email ? 'email' : ($phone ? 'phone' : null)));
        $rootValue = $name ?: $company ?: $email ?: $phone;
        if (!$rootType || !$rootValue) { $stats['skipped']++; continue; }

        $props = array_filter([
            'imported_from'  => $sourceLabel,
            'import_row'     => $rIdx + 2, // +2 because header is row 1
            'title'          => $title ?: null,
            'notes'          => $notes ?: null,
        ]);

        $rootId = ontology_upsert_object($rootType, $rootValue, $props, $importScanId, 0.75);
        if (!$rootId) { $stats['skipped']++; continue; }
        $stats['objects']++;

        if ($email && $rootType !== 'email') {
            $eId = ontology_upsert_object('email', $email, [], $importScanId, 0.85);
            ontology_upsert_link($rootId, $eId, 'has_email', $sourceTag, 0.9);
            $stats['objects']++; $stats['links']++;
        }
        if ($phone && $rootType !== 'phone') {
            $pId = ontology_upsert_object('phone', $phone, [], $importScanId, 0.85);
            ontology_upsert_link($rootId, $pId, 'has_phone', $sourceTag, 0.9);
            $stats['objects']++; $stats['links']++;
        }
        if ($address) {
            $aId = ontology_upsert_object('address', $address, [], $importScanId, 0.7);
            ontology_upsert_link($rootId, $aId, 'lives_at', $sourceTag, 0.75);
            $stats['objects']++; $stats['links']++;
        }
        if ($company && $rootType !== 'company') {
            $cId = ontology_upsert_object('company', $company, [], $importScanId, 0.8);
            ontology_upsert_link($rootId, $cId, 'associated_with', $sourceTag, 0.85);
            $stats['objects']++; $stats['links']++;
        }
        if ($domain) {
            $dId = ontology_upsert_object('domain', $domain, [], $importScanId, 0.7);
            ontology_upsert_link($rootId, $dId, 'owns_domain', $sourceTag, 0.75);
            $stats['objects']++; $stats['links']++;
        }
        if ($handle) {
            $hId = ontology_upsert_object('handle', $handle, [], $importScanId, 0.7);
            ontology_upsert_link($rootId, $hId, 'uses_handle', $sourceTag, 0.75);
            $stats['objects']++; $stats['links']++;
        }
    } catch (Throwable $e) {
        $stats['errors']++;
    }
}

// Add a single import event summary on a meta-object
try {
    $metaId = ontology_upsert_object('event', 'Import: ' . $sourceLabel . ' @ ' . date('Y-m-d H:i'), [
        'rows'    => $stats['rows'],
        'objects' => $stats['objects'],
        'links'   => $stats['links'],
    ], null, 0.99);
    if ($metaId) {
        ontology_add_event($metaId, 'csv_import', date('Y-m-d'),
            "Imported {$stats['rows']} rows from {$sourceLabel}",
            $stats, $sourceTag);
        $stats['events']++;
    }
} catch (Exception $e) {}

$stats['elapsed'] = round(microtime(true) - $startTs, 2);
$stats['resolved_columns'] = array_filter($cols);
$stats['detected_delimiter'] = $delim;

echo json_encode(['success' => true, 'stats' => $stats]);
