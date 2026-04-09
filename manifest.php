<?php
require_once __DIR__ . '/includes/config.php';
header('Content-Type: application/manifest+json');
header('Cache-Control: public, max-age=86400');

// Role-based start URL
$startUrl = '/admin/';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!empty($_SESSION['user_type'])) {
    $startUrl = match($_SESSION['user_type']) {
        'employee' => '/employee/',
        'customer' => '/customer/',
        default => '/admin/',
    };
}

echo json_encode([
    'name' => SITE . ' — ' . SITE_TAGLINE,
    'short_name' => SITE,
    'description' => SITE_TAGLINE,
    'start_url' => $startUrl,
    'scope' => '/',
    'display' => 'standalone',
    'orientation' => 'any',
    'theme_color' => BRAND,
    'background_color' => '#f9fafb',
    'icons' => [
        ['src' => '/icons/icon.php?s=48', 'sizes' => '48x48', 'type' => 'image/png'],
        ['src' => '/icons/icon.php?s=72', 'sizes' => '72x72', 'type' => 'image/png'],
        ['src' => '/icons/icon.php?s=96', 'sizes' => '96x96', 'type' => 'image/png'],
        ['src' => '/icons/icon.php?s=144', 'sizes' => '144x144', 'type' => 'image/png'],
        ['src' => '/icons/icon.php?s=192', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/icons/icon.php?s=512', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
    ],
    'shortcuts' => [
        ['name' => 'Dashboard', 'url' => $startUrl, 'icons' => [['src' => '/icons/icon.php?s=96', 'sizes' => '96x96']]],
        ['name' => 'Jobs', 'url' => '/admin/jobs.php', 'icons' => [['src' => '/icons/icon.php?s=96', 'sizes' => '96x96']]],
        ['name' => 'Nachrichten', 'url' => '/admin/messages.php', 'icons' => [['src' => '/icons/icon.php?s=96', 'sizes' => '96x96']]],
    ],
    'categories' => ['business', 'productivity'],
    'lang' => LOCALE,
    'prefer_related_applications' => false,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
