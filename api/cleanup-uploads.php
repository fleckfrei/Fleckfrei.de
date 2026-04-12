<?php
/**
 * Cron: Uploads älter als 30 Tage → Google Drive synchronisieren + lokal löschen.
 * Fallback: lokales Archive wenn Drive nicht verfügbar.
 */
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json');

$authOk = ($_GET['key'] ?? '') === API_KEY || php_sapi_name() === 'cli';
if (!$authOk) { http_response_code(403); exit; }

$uploadsRoot = __DIR__ . '/../uploads/jobs';
$archiveRoot = __DIR__ . '/../uploads/archive';
if (!is_dir($uploadsRoot)) { echo json_encode(['archived' => 0]); exit; }

$cutoff = time() - (30 * 86400);
$archivedCount = 0; $driveCount = 0; $totalBytes = 0; $errors = [];

// === Google Drive Helper ===
function getDriveAccessToken(): ?string {
    static $cached = null;
    if ($cached) return $cached;
    try {
        $tokenJson = val("SELECT config_value FROM app_config WHERE config_key='google_oauth_tokens'");
        if (!$tokenJson) return null;
        $tokens = json_decode($tokenJson, true);
        if (!$tokens) return null;
        // Check ob access_token abgelaufen
        if (!empty($tokens['expires_at']) && $tokens['expires_at'] > time() + 60) {
            return $cached = $tokens['access_token'];
        }
        // Refresh
        if (empty($tokens['refresh_token'])) return null;
        $clientId = val("SELECT config_value FROM app_config WHERE config_key='google_client_id'");
        $clientSecret = val("SELECT config_value FROM app_config WHERE config_key='google_client_secret'");
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id' => $clientId, 'client_secret' => $clientSecret,
                'refresh_token' => $tokens['refresh_token'], 'grant_type' => 'refresh_token'
            ]),
            CURLOPT_TIMEOUT => 10
        ]);
        $resp = curl_exec($ch); curl_close($ch);
        $new = json_decode($resp, true);
        if (empty($new['access_token'])) return null;
        $tokens['access_token'] = $new['access_token'];
        $tokens['expires_at'] = time() + ($new['expires_in'] ?? 3600);
        q("UPDATE app_config SET config_value=? WHERE config_key='google_oauth_tokens'", [json_encode($tokens)]);
        return $cached = $tokens['access_token'];
    } catch (Exception $e) { return null; }
}

function driveEnsureFolder(string $token, string $name, ?string $parentId = null): ?string {
    // Search first
    $q = "name='" . addslashes($name) . "' and mimeType='application/vnd.google-apps.folder' and trashed=false";
    if ($parentId) $q .= " and '" . $parentId . "' in parents";
    $url = 'https://www.googleapis.com/drive/v3/files?' . http_build_query(['q' => $q, 'fields' => 'files(id,name)']);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token], CURLOPT_TIMEOUT => 10]);
    $resp = curl_exec($ch); curl_close($ch);
    $data = json_decode($resp, true);
    if (!empty($data['files'][0]['id'])) return $data['files'][0]['id'];
    // Create
    $payload = ['name' => $name, 'mimeType' => 'application/vnd.google-apps.folder'];
    if ($parentId) $payload['parents'] = [$parentId];
    $ch = curl_init('https://www.googleapis.com/drive/v3/files');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT => 10
    ]);
    $resp = curl_exec($ch); curl_close($ch);
    $data = json_decode($resp, true);
    return $data['id'] ?? null;
}

function driveUploadFile(string $token, string $localPath, string $parentId): bool {
    if (!is_file($localPath)) return false;
    $name = basename($localPath);
    $mime = mime_content_type($localPath) ?: 'application/octet-stream';
    // Metadata
    $metadata = ['name' => $name, 'parents' => [$parentId]];
    $boundary = '--boundary_' . bin2hex(random_bytes(8));
    $body = "--$boundary\r\n"
          . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
          . json_encode($metadata) . "\r\n"
          . "--$boundary\r\n"
          . "Content-Type: $mime\r\n\r\n"
          . file_get_contents($localPath) . "\r\n"
          . "--$boundary--";
    $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: multipart/related; boundary=' . $boundary],
        CURLOPT_TIMEOUT => 60
    ]);
    $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return $code >= 200 && $code < 300;
}

// === Main cleanup ===
$driveToken = getDriveAccessToken();
$driveRootId = null;
if ($driveToken) {
    $driveRootId = driveEnsureFolder($driveToken, 'Fleckfrei-Archive');
}

foreach (glob($uploadsRoot . '/*', GLOB_ONLYDIR) as $jobDir) {
    $jobId = basename($jobDir);
    foreach (glob($jobDir . '/*') as $file) {
        if (!is_file($file)) continue;
        if (filemtime($file) > $cutoff) continue;

        $month = date('Y-m', filemtime($file));
        $size = filesize($file);
        $uploaded = false;

        // Try Drive first
        if ($driveToken && $driveRootId) {
            $monthFolderId = driveEnsureFolder($driveToken, $month, $driveRootId);
            if ($monthFolderId) {
                $jobFolderId = driveEnsureFolder($driveToken, 'job_' . $jobId, $monthFolderId);
                if ($jobFolderId && driveUploadFile($driveToken, $file, $jobFolderId)) {
                    @unlink($file);
                    $uploaded = true; $driveCount++; $totalBytes += $size;
                }
            }
        }

        // Fallback: lokales Archive
        if (!$uploaded) {
            $dst = $archiveRoot . '/' . $month . '/job_' . $jobId;
            if (!is_dir($dst)) @mkdir($dst, 0755, true);
            if (@rename($file, $dst . '/' . basename($file))) {
                $archivedCount++; $totalBytes += $size;
            } else {
                $errors[] = basename($file);
            }
        }
    }
    if (count(glob($jobDir . '/*')) === 0) @rmdir($jobDir);
}

echo json_encode([
    'drive_synced' => $driveCount,
    'local_archived' => $archivedCount,
    'total_files' => $driveCount + $archivedCount,
    'bytes' => $totalBytes,
    'mb' => round($totalBytes / 1024 / 1024, 2),
    'drive_active' => $driveToken !== null,
    'errors' => $errors
]);
