<?php
// Fleckfrei Technical Book — Styled HTML (print as PDF)
$title = 'Fleckfrei.de — Technical Book 2026';
$md = file_get_contents(__DIR__ . '/Fleckfrei_de_Technical_Book.md');
// Simple markdown to HTML
$html = $md;
$html = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $html);
$html = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $html);
$html = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $html);
$html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);
$html = preg_replace('/`(.+?)`/', '<code>$1</code>', $html);
$html = preg_replace('/^- (.+)$/m', '<li>$1</li>', $html);
$html = preg_replace('/^(\d+)\. (.+)$/m', '<li>$2</li>', $html);
$html = preg_replace('/```(\w*)\n(.*?)```/s', '<pre><code>$2</code></pre>', $html);
$html = preg_replace('/\|(.+)\|/m', '<tr>' . preg_replace_callback('/\|/', fn() => '</td><td>', '$1') . '</tr>', $html);
$html = nl2br($html);
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<title><?= $title ?></title>
<style>
@page { margin: 2cm; size: A4; }
@media print { .no-print { display: none; } body { font-size: 11pt; } }
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; color: #1a1a1a; line-height: 1.6; max-width: 900px; margin: 0 auto; padding: 40px 20px; }
h1 { color: <?= BRAND ?>; font-size: 28px; border-bottom: 3px solid <?= BRAND ?>; padding-bottom: 10px; margin: 30px 0 15px; }
h2 { color: #235F53; font-size: 20px; border-bottom: 1px solid #ddd; padding-bottom: 6px; margin: 25px 0 10px; }
h3 { color: #333; font-size: 16px; margin: 20px 0 8px; }
p, li { font-size: 14px; margin-bottom: 4px; }
ul, ol { padding-left: 20px; }
code { background: #f0f4f3; padding: 2px 6px; border-radius: 3px; font-size: 13px; font-family: 'SF Mono', Monaco, monospace; }
pre { background: #1a1a2e; color: #e0e0e0; padding: 15px; border-radius: 8px; overflow-x: auto; margin: 10px 0; }
pre code { background: none; color: inherit; padding: 0; }
table { width: 100%; border-collapse: collapse; margin: 10px 0; font-size: 13px; }
td, th { border: 1px solid #ddd; padding: 6px 10px; text-align: left; }
tr:nth-child(even) { background: #f9f9f9; }
strong { color: <?= BRAND ?>; }
.cover { text-align: center; padding: 80px 0; page-break-after: always; }
.cover h1 { font-size: 42px; border: none; color: <?= BRAND ?>; }
.cover .subtitle { font-size: 18px; color: #666; margin-top: 10px; }
.cover .date { font-size: 14px; color: #999; margin-top: 30px; }
.cover .logo { width: 80px; height: 80px; background: <?= BRAND ?>; color: white; font-size: 40px; font-weight: bold; border-radius: 20px; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 20px; }
.btn { display: inline-block; padding: 12px 24px; background: <?= BRAND ?>; color: white; border-radius: 8px; text-decoration: none; font-weight: bold; margin: 10px 5px; cursor: pointer; border: none; font-size: 14px; }
.btn:hover { background: #235F53; }
</style>
</head>
<body>
<div class="cover">
  <div class="logo">F</div>
  <h1>Fleckfrei.de</h1>
  <div class="subtitle">Technical Book — Komplette Technische Dokumentation</div>
  <div class="date"><?= date('d.m.Y') ?> · Version 9.0</div>
</div>
<div class="no-print" style="text-align:center;margin-bottom:30px">
  <button class="btn" onclick="window.print()">Als PDF drucken</button>
  <a href="/admin/" class="btn" style="background:#666">Zurück zum Admin</a>
</div>
<?= $html ?>
<div style="margin-top:40px;padding-top:20px;border-top:2px solid <?= BRAND ?>;text-align:center;color:#999;font-size:12px">
  Fleckfrei.de Technical Book · <?= date('Y') ?> · Vertraulich
</div>
</body>
</html>
