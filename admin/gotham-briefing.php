<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
require_once __DIR__ . '/../includes/ontology.php';

$objId = (int)($_GET['obj_id'] ?? 0);
if ($objId <= 0) { http_response_code(400); exit('obj_id required'); }

$obj = ontology_get_object($objId);
if (!$obj) { http_response_code(404); exit('not found'); }

$graph = ontology_get_graph($objId, 2);
$relatedCount = count($graph['nodes']);

// Pull the most recent vulture_scan event narrative if present
$lastNarrative = '';
$lastConfidence = 0;
foreach ($obj['events'] as $e) {
    if ($e['event_type'] === 'vulture_scan' && !empty($e['data']['narrative'])) {
        $lastNarrative = $e['data']['narrative'];
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title>Briefing Book — <?= htmlspecialchars($obj['display_name']) ?></title>
<style>
  @page { margin: 20mm; size: A4; }
  body { font-family: 'Inter',Georgia,serif; max-width: 750px; margin: 30px auto; padding: 0 30px; color: #1a202c; line-height: 1.5; }
  h1 { border-bottom: 3px solid #0f172a; padding-bottom: 8px; margin: 0 0 4px; font-size: 24px; }
  h2 { border-bottom: 1px solid #cbd5e1; padding-bottom: 4px; margin-top: 24px; font-size: 16px; color: #0f172a; }
  h3 { font-size: 13px; color: #475569; margin-top: 16px; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px; }
  .meta { color: #64748b; font-size: 11px; }
  .badge { display: inline-block; padding: 2px 8px; border-radius: 3px; background: #f1f5f9; color: #475569; font-size: 10px; font-family: monospace; text-transform: uppercase; margin-right: 4px; }
  .badge.verified { background: #dcfce7; color: #166534; }
  .stat-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin: 16px 0; }
  .stat { background: #f8fafc; border-left: 3px solid #0f172a; padding: 10px; }
  .stat-label { font-size: 9px; color: #64748b; text-transform: uppercase; font-family: monospace; letter-spacing: 0.5px; }
  .stat-value { font-size: 18px; font-weight: 700; color: #0f172a; margin-top: 4px; }
  .narrative { background: #f8fafc; border-left: 4px solid #f59e0b; padding: 14px; margin: 12px 0; font-style: italic; }
  table { width: 100%; border-collapse: collapse; font-size: 12px; margin: 8px 0; }
  td, th { padding: 6px 8px; border-bottom: 1px solid #e2e8f0; text-align: left; }
  th { background: #f1f5f9; font-size: 10px; text-transform: uppercase; color: #475569; letter-spacing: 0.5px; }
  .event { border-left: 2px solid #cbd5e1; padding: 6px 12px; margin: 6px 0; }
  .event-date { font-family: monospace; font-size: 10px; color: #64748b; }
  .footer { margin-top: 40px; padding-top: 12px; border-top: 1px solid #cbd5e1; color: #64748b; font-size: 10px; text-align: center; }
  @media print {
    body { max-width: none; margin: 0; padding: 0; }
    .no-print { display: none; }
  }
  .no-print { position: fixed; top: 12px; right: 12px; }
  .no-print button { background: #0f172a; color: #fff; border: 0; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-size: 12px; }
</style>
</head>
<body>

<div class="no-print">
  <button onclick="window.print()">🖨 Print / Save PDF</button>
</div>

<h1>GOTHAM BRIEFING BOOK</h1>
<div class="meta">
  Subject: <strong><?= htmlspecialchars($obj['display_name']) ?></strong> ·
  Generated <?= date('Y-m-d H:i') ?> ·
  Classification: <strong>INTERNAL — AUTHORIZED USE ONLY</strong>
</div>

<h2>Subject Identity</h2>
<div>
  <span class="badge"><?= htmlspecialchars($obj['obj_type']) ?></span>
  <?php if ($obj['verified']): ?><span class="badge verified">✓ 3-source verified</span><?php endif; ?>
</div>

<div class="stat-grid">
  <div class="stat">
    <div class="stat-label">Confidence</div>
    <div class="stat-value"><?= round($obj['confidence'] * 100) ?>%</div>
  </div>
  <div class="stat">
    <div class="stat-label">Sources</div>
    <div class="stat-value"><?= $obj['source_count'] ?></div>
  </div>
  <div class="stat">
    <div class="stat-label">Linked objects</div>
    <div class="stat-value"><?= $relatedCount ?></div>
  </div>
  <div class="stat">
    <div class="stat-label">Events</div>
    <div class="stat-value"><?= count($obj['events']) ?></div>
  </div>
</div>

<?php if ($lastNarrative): ?>
<h2>Synthetic Truth Report</h2>
<div class="narrative"><?= htmlspecialchars($lastNarrative) ?></div>
<?php endif; ?>

<?php if (!empty($obj['properties'])): ?>
<h2>Properties</h2>
<table>
  <?php foreach ($obj['properties'] as $k => $v): ?>
  <tr>
    <th style="width: 30%;"><?= htmlspecialchars($k) ?></th>
    <td><?= htmlspecialchars(is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE)) ?></td>
  </tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>

<h2>Known Associations</h2>
<?php if (empty($obj['links_out'])): ?>
<p style="color: #64748b;">No outgoing links recorded.</p>
<?php else: ?>
<table>
  <thead><tr><th>Relation</th><th>Target</th><th>Type</th><th>Confidence</th></tr></thead>
  <tbody>
  <?php foreach ($obj['links_out'] as $l): ?>
  <tr>
    <td><?= htmlspecialchars($l['relation']) ?></td>
    <td><?= htmlspecialchars($l['target_name']) ?></td>
    <td><span class="badge"><?= htmlspecialchars($l['target_type']) ?></span></td>
    <td><?= round($l['confidence'] * 100) ?>%</td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<h2>Incoming References</h2>
<?php if (empty($obj['links_in'])): ?>
<p style="color: #64748b;">No incoming references.</p>
<?php else: ?>
<table>
  <thead><tr><th>Source</th><th>Type</th><th>Relation</th><th>Confidence</th></tr></thead>
  <tbody>
  <?php foreach ($obj['links_in'] as $l): ?>
  <tr>
    <td><?= htmlspecialchars($l['source_name']) ?></td>
    <td><span class="badge"><?= htmlspecialchars($l['source_type']) ?></span></td>
    <td><?= htmlspecialchars($l['relation']) ?></td>
    <td><?= round($l['confidence'] * 100) ?>%</td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<h2>Event Timeline</h2>
<?php if (empty($obj['events'])): ?>
<p style="color: #64748b;">No events recorded.</p>
<?php else: ?>
<?php foreach ($obj['events'] as $e): ?>
<div class="event">
  <div class="event-date">
    <?= htmlspecialchars($e['event_date'] ?? substr($e['created_at'], 0, 10)) ?> ·
    <?= htmlspecialchars($e['event_type']) ?>
    <?php if ($e['source']): ?>· <?= htmlspecialchars($e['source']) ?><?php endif; ?>
  </div>
  <div><?= htmlspecialchars($e['title']) ?></div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<div class="footer">
  GOTHAM · Fleckfrei Investigation OS · obj_id=<?= $obj['obj_id'] ?> ·
  First seen <?= substr($obj['first_seen'], 0, 10) ?> ·
  Generated by <?= htmlspecialchars($_SESSION['username'] ?? 'system') ?>
</div>

</body>
</html>
