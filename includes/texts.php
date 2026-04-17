<?php
// ============================================================
// Simple CMS-lite for editable text snippets.
// Values live in DB table `site_content` (auto-created on first use).
// Usage in any PHP page:
//   echo siteText('home.hero.title', 'Fallback title');
//   echo siteText('home.hero.subtitle');   // empty string if missing
// Admin editor:  /admin/texts.php
// ============================================================

if (!function_exists('siteTextEnsureTable')) {
    function siteTextEnsureTable(): void {
        static $done = false;
        if ($done) return;
        try {
            q("CREATE TABLE IF NOT EXISTS site_content (
                key_name VARCHAR(128) NOT NULL PRIMARY KEY,
                value_text LONGTEXT NULL,
                description VARCHAR(255) NULL,
                category VARCHAR(64) NOT NULL DEFAULT 'general',
                is_html TINYINT(1) NOT NULL DEFAULT 0,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                updated_by VARCHAR(128) NULL,
                INDEX idx_category (category)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $done = true;
        } catch (Exception $e) { /* silent — page still renders via defaults */ }
    }
}

if (!function_exists('siteText')) {
    /**
     * Fetch editable text by key. Returns $default if not set.
     * Result is cached per-request.
     * If is_html=1, returns HTML raw (don't escape). If is_html=0, returns plain text
     * (caller must escape with e() for HTML contexts).
     */
    function siteText(string $key, string $default = ''): string {
        static $cache = null;
        if ($cache === null) {
            siteTextEnsureTable();
            $cache = [];
            try {
                foreach (all("SELECT key_name, value_text FROM site_content") as $r) {
                    $cache[$r['key_name']] = $r['value_text'] ?? '';
                }
            } catch (Exception $e) { /* fall through to default */ }
        }
        if (array_key_exists($key, $cache) && $cache[$key] !== '') return $cache[$key];
        return $default;
    }
}

if (!function_exists('siteTextIsHtml')) {
    function siteTextIsHtml(string $key): bool {
        static $cache = null;
        if ($cache === null) {
            $cache = [];
            try {
                foreach (all("SELECT key_name, is_html FROM site_content") as $r) {
                    $cache[$r['key_name']] = (int) $r['is_html'];
                }
            } catch (Exception $e) {}
        }
        return !empty($cache[$key]);
    }
}
