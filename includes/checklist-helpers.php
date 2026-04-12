<?php
/**
 * Checklist helpers — translate customer checklist to partner language via Groq.
 * Result is cached in DB column `translated_cache` until the item is edited.
 */
require_once __DIR__ . '/llm-helpers.php';

/**
 * Fetch a checklist for a service + translate to target language.
 * Returns items with `title_tr` and `description_tr` keys.
 */
function getChecklistForPartner(int $serviceId, string $targetLang = 'de'): array {
    global $db;
    $items = all("SELECT * FROM service_checklists WHERE s_id_fk=? AND is_active=1 ORDER BY position, checklist_id", [$serviceId]);
    if (empty($items)) return [];

    // German partner? No translation needed
    $targetLang = strtolower(substr($targetLang, 0, 2));
    if ($targetLang === 'de' || $targetLang === '') {
        foreach ($items as &$it) {
            $it['title_tr'] = $it['title'];
            $it['description_tr'] = $it['description'];
        }
        return $items;
    }

    // Use cached translation if language matches
    $needsTranslation = [];
    foreach ($items as $k => $it) {
        if ($it['translated_lang'] === $targetLang && !empty($it['translated_cache'])) {
            $cached = json_decode($it['translated_cache'], true);
            if (is_array($cached) && isset($cached['title'])) {
                $items[$k]['title_tr'] = $cached['title'];
                $items[$k]['description_tr'] = $cached['description'] ?? '';
                continue;
            }
        }
        $needsTranslation[] = $k;
    }

    if (empty($needsTranslation)) return $items;

    // Batch-translate uncached items in a single Groq call
    $langNames = [
        'ro' => 'Romanian',
        'en' => 'English',
        'pl' => 'Polish',
        'tr' => 'Turkish',
        'ru' => 'Russian',
        'uk' => 'Ukrainian',
        'bg' => 'Bulgarian',
        'es' => 'Spanish',
        'ar' => 'Arabic',
        'vi' => 'Vietnamese',
    ];
    $langName = $langNames[$targetLang] ?? $targetLang;

    $toTranslate = [];
    foreach ($needsTranslation as $k) {
        $toTranslate[] = [
            'i' => $k,
            't' => $items[$k]['title'],
            'd' => $items[$k]['description'] ?: '',
        ];
    }
    $json = json_encode($toTranslate, JSON_UNESCAPED_UNICODE);

    $prompt = "Translate the following cleaning task list from German to $langName. "
            . "Return ONLY a JSON array with the same structure (keys: i, t, d). "
            . "Keep the translation short, practical and direct — suited for a cleaner reading at work. "
            . "Input:\n$json\n\nOutput (JSON only, no markdown):";

    if (!function_exists('groq_chat')) {
        // Fallback: return originals
        foreach ($needsTranslation as $k) {
            $items[$k]['title_tr'] = $items[$k]['title'];
            $items[$k]['description_tr'] = $items[$k]['description'];
        }
        return $items;
    }

    $resp = groq_chat($prompt, 1500);
    $content = $resp['content'] ?? '';
    // Extract JSON array from response (model may wrap in ```json ... ```)
    if (preg_match('/\[[\s\S]*\]/', $content, $m)) {
        $translated = json_decode($m[0], true);
        if (is_array($translated)) {
            foreach ($translated as $entry) {
                $idx = $entry['i'] ?? null;
                if ($idx === null || !isset($items[$idx])) continue;
                $items[$idx]['title_tr'] = $entry['t'] ?? $items[$idx]['title'];
                $items[$idx]['description_tr'] = $entry['d'] ?? $items[$idx]['description'];
                // Cache in DB
                try {
                    q("UPDATE service_checklists SET translated_cache=?, translated_lang=? WHERE checklist_id=?",
                      [json_encode(['title' => $items[$idx]['title_tr'], 'description' => $items[$idx]['description_tr']], JSON_UNESCAPED_UNICODE), $targetLang, $items[$idx]['checklist_id']]);
                } catch (Exception $e) {}
            }
        }
    }

    // Fill in any that didn't get translated
    foreach ($needsTranslation as $k) {
        if (!isset($items[$k]['title_tr'])) {
            $items[$k]['title_tr'] = $items[$k]['title'];
            $items[$k]['description_tr'] = $items[$k]['description'];
        }
    }
    return $items;
}
