<?php
/**
 * Universal Auto-Translate Helper — übersetzt freie Texte (Notizen, Beschreibungen)
 * mit Caching. Nutzt Groq (gleicher Stack wie checklist-helpers).
 *
 * Usage:
 *   require_once __DIR__ . '/translate-helper.php';
 *   echo autoTranslate('Lavete și cârpe pentru curățat:', 'de');
 *   // → "Lappen und Tücher zum Putzen:"
 */

if (!function_exists('autoTranslate')) {

/**
 * Translate text to target language. Auto-detects source. Cached forever.
 * @param string $text
 * @param string $targetLang ISO-639-1 (de/en/ro/ru/pl/tr/ar/fr/es/uk/vi/th/zh)
 * @return string
 */
function autoTranslate(string $text, string $targetLang = 'de'): string {
    $text = trim($text);
    if ($text === '' || strlen($text) < 2) return $text;
    if (!preg_match('/^[a-z]{2,3}$/', $targetLang)) $targetLang = 'de';

    // If text is already in target lang (heuristic: very short OR contains target-lang typical chars)
    // Skip translation for obvious non-text (numbers, urls)
    if (preg_match('/^[\d\s\.,€\-+\/]+$/', $text)) return $text;

    $hash = hash('sha256', $text);

    try {
        // Cache hit?
        $cached = one("SELECT translated FROM translation_cache WHERE source_hash=? AND target_lang=? LIMIT 1", [$hash, $targetLang]);
        if ($cached) {
            // bump used_count async (best-effort)
            try { q("UPDATE translation_cache SET used_count = used_count + 1 WHERE source_hash=? AND target_lang=?", [$hash, $targetLang]); } catch (Exception $e) {}
            return $cached['translated'];
        }
    } catch (Exception $e) {
        return $text; // DB error → return original
    }

    // Call Groq
    if (!defined('GROQ_API_KEY') || !GROQ_API_KEY) return $text;

    $payload = json_encode([
        'model' => 'llama-3.3-70b-versatile',
        'temperature' => 0.1,
        'max_tokens' => 800,
        'messages' => [
            ['role' => 'system', 'content' => "Du übersetzt Geschäfts-Notizen für eine Reinigungs-Plattform. Gib AUSSCHLIESSLICH die Übersetzung zurück, keine Anführungszeichen, keine Erklärung. Behalte Format (Zeilenumbrüche, Aufzählungen). Wenn Text bereits in Ziel-Sprache, gib ihn unverändert zurück."],
            ['role' => 'user', 'content' => "Übersetze nach '$targetLang':\n\n" . substr($text, 0, 4000)],
        ],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . GROQ_API_KEY]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $resp = curl_exec($ch);
    curl_close($ch);

    if (!$resp) return $text;
    $d = json_decode($resp, true);
    $translated = trim($d['choices'][0]['message']['content'] ?? '');
    if (!$translated) return $text;

    // Strip wrapping quotes if model added them
    $translated = trim($translated, " \"'\n\r\t");

    // Cache
    try {
        q("INSERT INTO translation_cache (source_hash, target_lang, source_text, translated)
            VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE used_count = used_count + 1",
           [$hash, $targetLang, substr($text, 0, 65000), substr($translated, 0, 65000)]);
    } catch (Exception $e) {}

    return $translated;
}

/**
 * HTML-safe wrapper. Returns translated text passed through htmlspecialchars.
 */
function autoTranslateHtml(string $text, string $targetLang = 'de'): string {
    return htmlspecialchars(autoTranslate($text, $targetLang), ENT_QUOTES, 'UTF-8');
}

/**
 * Get user's preferred lang (employee for partner pages, customer for customer pages).
 * Falls back to 'de'.
 */
function userLang(): string {
    if (empty($_SESSION['uid'])) return 'de';
    $type = $_SESSION['utype'] ?? '';
    try {
        if ($type === 'employee') {
            return val("SELECT COALESCE(language,'de') FROM employee WHERE emp_id=?", [(int)$_SESSION['uid']]) ?: 'de';
        }
        if ($type === 'customer') {
            return val("SELECT COALESCE(language,'de') FROM customer WHERE customer_id=?", [(int)$_SESSION['uid']]) ?: 'de';
        }
    } catch (Exception $e) {}
    return 'de';
}

}
