<?php
require_once __DIR__ . '/includes/config.php';
header('Content-Type: application/manifest+json');
echo json_encode([
    'name' => SITE . ' — ' . SITE_TAGLINE,
    'short_name' => SITE,
    'description' => SITE_TAGLINE,
    'start_url' => '/employee/',
    'display' => 'standalone',
    'orientation' => 'portrait',
    'theme_color' => BRAND,
    'background_color' => '#ffffff',
    'icons' => [
        ['src' => '/icons/icon.php?s=192', 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => '/icons/icon.php?s=512', 'sizes' => '512x512', 'type' => 'image/png'],
        ['src' => '/icons/icon.php?s=180', 'sizes' => '180x180', 'type' => 'image/png', 'purpose' => 'apple touch icon'],
    ],
    'categories' => ['business', 'productivity'],
    'lang' => LOCALE,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
