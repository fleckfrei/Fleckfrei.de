<?php
// Fleckfrei Investment Pitch — Styled HTML (print as PDF)
$title = 'Fleckfrei.de — Investment Pitch 2026';
$md = file_get_contents(__DIR__ . '/Fleckfrei_Investment_Pitch.md');
$html = $md;
$html = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $html);
$html = preg_replace('/^## (.+)$/m', '<h2 class="slide-title">$1</h2>', $html);
$html = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $html);
$html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);
$html = preg_replace('/`(.+?)`/', '<code>$1</code>', $html);
$html = preg_replace('/^- (.+)$/m', '<li>$1</li>', $html);
$html = preg_replace('/```(\w*)\n(.*?)```/s', '<pre><code>$2</code></pre>', $html);
$html = preg_replace('/^---$/m', '<hr class="slide-break">', $html);
$html = nl2br($html);
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<title><?= $title ?></title>
<style>
@page { margin: 1.5cm; size: A4 landscape; }
@media print { .no-print { display: none; } .slide-break { page-break-before: always; } body { font-size: 12pt; } }
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Segoe UI', system-ui, sans-serif; color: #1a1a1a; line-height: 1.5; max-width: 1000px; margin: 0 auto; padding: 40px 20px; }
h1 { color: <?= BRAND ?>; font-size: 36px; text-align: center; margin: 40px 0; }
h2.slide-title { color: white; background: linear-gradient(135deg, <?= BRAND ?>, #235F53); font-size: 22px; padding: 15px 20px; border-radius: 12px; margin: 30px 0 15px; }
h3 { color: <?= BRAND ?>; font-size: 16px; margin: 15px 0 8px; }
li { font-size: 14px; margin: 4px 0; padding-left: 5px; }
ul { padding-left: 20px; }
code { background: <?= BRAND_LIGHT ?>; color: <?= BRAND ?>; padding: 2px 6px; border-radius: 3px; font-size: 13px; }
strong { color: <?= BRAND ?>; }
table { width: 100%; border-collapse: collapse; margin: 10px 0; }
td, th { border: 1px solid #ddd; padding: 8px 12px; font-size: 13px; }
tr:nth-child(even) { background: #f0f4f3; }
hr.slide-break { border: none; margin: 30px 0; }
.btn { display: inline-block; padding: 12px 24px; background: <?= BRAND ?>; color: white; border-radius: 8px; text-decoration: none; font-weight: bold; margin: 10px 5px; cursor: pointer; border: none; font-size: 14px; }
pre { background: #1a1a2e; color: #e0e0e0; padding: 12px; border-radius: 8px; margin: 10px 0; }
pre code { background: none; color: inherit; }
</style>
</head>
<body>
<div class="no-print" style="text-align:center;margin-bottom:20px">
  <button class="btn" onclick="window.print()">Als PDF drucken</button>
  <a href="/docs/technical-book.php" class="btn" style="background:#666">Technical Book</a>
</div>
<?= $html ?>
<div style="margin-top:40px;text-align:center;color:#999;font-size:11px">
  Vertraulich · Fleckfrei.de · <?= date('Y') ?>
</div>
</body>
</html>
