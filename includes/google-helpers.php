<?php
/**
 * Google API Helpers — Gmail, Drive, Contacts, Sheets
 * Uses OAuth tokens stored in settings table
 */

// Loaded from google-keys.php (gitignored) OR from app_config DB table
if (file_exists(__DIR__.'/google-keys.php')) require_once __DIR__.'/google-keys.php';
if (!defined('GOOG_CLIENT_ID') || !defined('GOOG_CLIENT_SECRET')) {
    try {
        $_ggId  = val("SELECT config_value FROM app_config WHERE config_key='google_client_id'");
        $_ggSec = val("SELECT config_value FROM app_config WHERE config_key='google_client_secret'");
        if ($_ggId  && !defined('GOOG_CLIENT_ID'))     define('GOOG_CLIENT_ID',     $_ggId);
        if ($_ggSec && !defined('GOOG_CLIENT_SECRET')) define('GOOG_CLIENT_SECRET', $_ggSec);
    } catch (Exception $e) {}
}

/**
 * Get valid Google access token (auto-refreshes if expired)
 */
function google_token(): ?string {
    try { $raw = val("SELECT config_value FROM app_config WHERE config_key='google_oauth_tokens'"); }
    catch (Exception $e) { return null; }
    if (!$raw) return null;
    $tokens = json_decode($raw, true);
    if (!$tokens || empty($tokens['access_token'])) return null;

    // Check if expired
    if (time() >= ($tokens['expires_at'] ?? 0) - 60) {
        if (empty($tokens['refresh_token'])) return null;
        // Refresh — requires OAuth keys. If not defined (google-keys.php missing), bail gracefully.
        if (!defined('GOOG_CLIENT_ID') || !defined('GOOG_CLIENT_SECRET')) return null;
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id' => GOOG_CLIENT_ID,
                'client_secret' => GOOG_CLIENT_SECRET,
                'refresh_token' => $tokens['refresh_token'],
                'grant_type' => 'refresh_token',
            ]),
            CURLOPT_TIMEOUT => 10,
        ]);
        $resp = json_decode(curl_exec($ch), true);
        curl_close($ch);
        if (!empty($resp['access_token'])) {
            $tokens['access_token'] = $resp['access_token'];
            $tokens['expires_at'] = time() + ($resp['expires_in'] ?? 3600);
            q("UPDATE app_config SET config_value=? WHERE config_key='google_oauth_tokens'",
              [json_encode($tokens)]);
        } else {
            return null;
        }
    }
    return $tokens['access_token'];
}

/**
 * Make authenticated Google API call
 */
function google_api(string $url, string $method = 'GET', ?array $body = null): ?array {
    $token = google_token();
    if (!$token) return ['error' => 'Google not connected'];

    $ch = curl_init($url);
    $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => $headers];

    if ($method === 'POST' && $body) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_HTTPHEADER] = $headers;
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    curl_close($ch);
    return json_decode($resp, true);
}

/**
 * Search Gmail for messages matching a query
 */
function google_gmail_search(string $query, int $maxResults = 10): array {
    $result = google_api('https://gmail.googleapis.com/gmail/v1/users/me/messages?' . http_build_query([
        'q' => $query,
        'maxResults' => $maxResults,
    ]));
    if (empty($result['messages'])) return [];

    $messages = [];
    foreach (array_slice($result['messages'], 0, $maxResults) as $msg) {
        $detail = google_api('https://gmail.googleapis.com/gmail/v1/users/me/messages/' . $msg['id'] . '?format=metadata&metadataHeaders=Subject&metadataHeaders=From&metadataHeaders=Date');
        if (!$detail || !empty($detail['error'])) continue;

        $headers = [];
        foreach ($detail['payload']['headers'] ?? [] as $h) {
            $headers[strtolower($h['name'])] = $h['value'];
        }
        $messages[] = [
            'id' => $msg['id'],
            'subject' => $headers['subject'] ?? '(no subject)',
            'from' => $headers['from'] ?? '',
            'date' => $headers['date'] ?? '',
            'snippet' => $detail['snippet'] ?? '',
        ];
    }
    return $messages;
}

/**
 * Search Google Contacts for a name/email/phone
 */
function google_contacts_search(string $query, int $maxResults = 10): array {
    $result = google_api('https://people.googleapis.com/v1/people:searchContacts?' . http_build_query([
        'query' => $query,
        'readMask' => 'names,emailAddresses,phoneNumbers,organizations',
        'pageSize' => $maxResults,
    ]));
    if (empty($result['results'])) return [];

    $contacts = [];
    foreach ($result['results'] as $r) {
        $person = $r['person'] ?? [];
        $name = $person['names'][0]['displayName'] ?? '';
        $email = $person['emailAddresses'][0]['value'] ?? '';
        $phone = $person['phoneNumbers'][0]['value'] ?? '';
        $org = $person['organizations'][0]['name'] ?? '';
        $contacts[] = ['name' => $name, 'email' => $email, 'phone' => $phone, 'org' => $org];
    }
    return $contacts;
}

/**
 * Search Google Drive for files matching a query
 */
function google_drive_search(string $query, int $maxResults = 10): array {
    $result = google_api('https://www.googleapis.com/drive/v3/files?' . http_build_query([
        'q' => "fullText contains '" . addslashes($query) . "'",
        'pageSize' => $maxResults,
        'fields' => 'files(id,name,mimeType,webViewLink,modifiedTime,size)',
        'orderBy' => 'modifiedTime desc',
    ]));
    return $result['files'] ?? [];
}

/**
 * Check if Google is connected
 */
function google_is_connected(): bool {
    return google_token() !== null;
}
