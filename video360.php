<?php
/**
 * 360° Video Player — A-Frame WebVR.
 * URL: /video360.php?file=/uploads/checklists/completed/123/v_xxx_360.mp4
 * Auth: customer or partner who owns/sees the file (light check via session)
 */
require_once __DIR__ . '/includes/auth.php';
if (empty($_SESSION['uid'])) { header('Location: /login.php'); exit; }

$file = $_GET['file'] ?? '';
// Sanitize path: only /uploads/ allowed, no traversal
$file = '/' . ltrim(preg_replace('#\.\.+#', '', $file), '/');
if (!preg_match('#^/uploads/[\w\-/.]+\.(mp4|mov|webm|m4v)$#i', $file)) {
    http_response_code(400);
    exit('Invalid file path');
}

$absPath = __DIR__ . $file;
if (!file_exists($absPath)) { http_response_code(404); exit('File not found'); }

$is360 = stripos($file, '360') !== false;
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>360° Video Player · <?= htmlspecialchars(basename($file)) ?></title>
<?php if ($is360): ?>
<script src="https://aframe.io/releases/1.5.0/aframe.min.js"></script>
<?php endif; ?>
<style>
  body { margin: 0; background: #000; color: #fff; font-family: -apple-system, Inter, sans-serif; }
  header { padding: 12px 20px; display: flex; justify-content: space-between; align-items: center; background: rgba(0,0,0,.8); position: fixed; top: 0; left: 0; right: 0; z-index: 100; }
  header a { color: #fff; text-decoration: none; padding: 6px 12px; border-radius: 6px; background: rgba(255,255,255,.1); font-size: 12px; }
  .badge { background: #7c3aed; color: #fff; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 700; margin-left: 8px; }
  main { padding-top: 50px; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
  video { max-width: 100%; max-height: 90vh; }
  a-scene { width: 100% !important; height: calc(100vh - 50px) !important; display: block !important; }
  .hint { position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); background: rgba(0,0,0,.7); padding: 8px 16px; border-radius: 20px; font-size: 12px; }
</style>
</head>
<body>
<header>
  <div>
    🎥 <?= htmlspecialchars(basename($file)) ?>
    <?php if ($is360): ?><span class="badge">360°</span><?php endif; ?>
  </div>
  <div>
    <a href="<?= htmlspecialchars($file) ?>" download>📥 Download</a>
    <a href="javascript:history.back()">✕ Schließen</a>
  </div>
</header>

<main>
<?php if ($is360): ?>
  <a-scene embedded vr-mode-ui="enabled: true">
    <a-assets><video id="vid360" autoplay loop crossorigin playsinline webkit-playsinline src="<?= htmlspecialchars($file) ?>"></video></a-assets>
    <a-videosphere src="#vid360" rotation="0 -90 0"></a-videosphere>
    <a-camera><a-cursor color="#FFC107"></a-cursor></a-camera>
  </a-scene>
  <div class="hint">🖱️ Maus zum Drehen · 📱 VR-Brille via Button rechts unten</div>
<?php else: ?>
  <video controls autoplay src="<?= htmlspecialchars($file) ?>"></video>
<?php endif; ?>
</main>
</body>
</html>
