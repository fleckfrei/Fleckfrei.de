<?php
/**
 * Admin: KI Foto-Score Dashboard — all AI cleanliness ratings at a glance
 */
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'KI Foto-Scores'; $page = 'photo-scores';

// Save custom AI rules
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_rules') {
    if (verifyCsrf()) {
        $rules = trim($_POST['photo_ai_rules'] ?? '');
        $existing = val("SELECT COUNT(*) FROM app_config WHERE config_key='photo_ai_rules'");
        if ($existing) {
            q("UPDATE app_config SET config_value=? WHERE config_key='photo_ai_rules'", [$rules]);
        } else {
            q("INSERT INTO app_config (config_key, config_value) VALUES ('photo_ai_rules', ?)", [$rules]);
        }
        header('Location: /admin/photo-scores.php?saved=1'); exit;
    }
}

$currentRules = '';
try { $currentRules = val("SELECT config_value FROM app_config WHERE config_key='photo_ai_rules'") ?: ''; } catch (Exception $e) {}

// Ensure table exists
try {
    $scores = all("SELECT pa.*, j.j_date, j.j_time, j.job_status, j.customer_id_fk,
                    c.name as cname, e.display_name as ename, s.title as stitle
                    FROM photo_analyses pa
                    LEFT JOIN jobs j ON pa.job_id_fk=j.j_id
                    LEFT JOIN customer c ON j.customer_id_fk=c.customer_id
                    LEFT JOIN employee e ON j.emp_id_fk=e.emp_id
                    LEFT JOIN services s ON j.s_id_fk=s.s_id
                    ORDER BY pa.created_at DESC LIMIT 50");
} catch (Exception $e) {
    $scores = [];
}

$avgScore = !empty($scores) ? round(array_sum(array_column($scores, 'score')) / count($scores), 1) : 0;
$passCount = count(array_filter($scores, fn($s) => $s['score'] >= 7));
$failCount = count($scores) - $passCount;

include __DIR__ . '/../includes/layout.php';
?>

<div class="mb-6">
  <h1 class="text-2xl font-bold text-gray-900">KI Foto-Scores</h1>
  <p class="text-sm text-gray-500 mt-1">Automatische Sauberkeits-Bewertung via Groq Vision AI</p>
</div>

<!-- Stats -->
<div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
  <div class="bg-white rounded-xl border p-5 text-center">
    <div class="text-3xl font-bold text-brand"><?= $avgScore ?>/10</div>
    <div class="text-xs text-gray-500 mt-1 uppercase font-semibold">Ø Score</div>
  </div>
  <div class="bg-white rounded-xl border p-5 text-center">
    <div class="text-3xl font-bold text-gray-900"><?= count($scores) ?></div>
    <div class="text-xs text-gray-500 mt-1 uppercase font-semibold">Analysen</div>
  </div>
  <div class="bg-white rounded-xl border p-5 text-center">
    <div class="text-3xl font-bold text-green-600"><?= $passCount ?></div>
    <div class="text-xs text-gray-500 mt-1 uppercase font-semibold">Bestanden</div>
  </div>
  <div class="bg-white rounded-xl border p-5 text-center">
    <div class="text-3xl font-bold text-red-600"><?= $failCount ?></div>
    <div class="text-xs text-gray-500 mt-1 uppercase font-semibold">Nachbessern</div>
  </div>
</div>

<!-- KI-Regeln Einstellungen -->
<div class="bg-white rounded-xl border mb-6" x-data="{ open: false }">
  <div class="px-5 py-3 border-b bg-gradient-to-r from-purple-50 to-transparent flex items-center justify-between cursor-pointer" @click="open = !open">
    <div class="flex items-center gap-3">
      <span class="text-lg">🤖</span>
      <div>
        <h3 class="font-semibold text-gray-900 text-sm">KI-Bewertungsregeln anpassen</h3>
        <p class="text-[10px] text-gray-500">Definieren Sie was die KI bei der Foto-Analyse beachten soll</p>
      </div>
    </div>
    <svg class="w-4 h-4 text-gray-400 transition" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
  </div>
  <div x-show="open" x-cloak class="p-5">
    <?php if (!empty($_GET['saved'])): ?>
    <div class="mb-3 p-2 bg-green-50 border border-green-200 rounded-lg text-sm text-green-800">Regeln gespeichert.</div>
    <?php endif; ?>

    <form method="POST" class="space-y-4">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save_rules"/>

      <div>
        <label class="block text-xs font-bold text-gray-600 uppercase tracking-wider mb-2">Ihre Bewertungs-Regeln</label>
        <textarea name="photo_ai_rules" rows="8" class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl focus:border-brand focus:ring-4 focus:ring-brand/10 outline-none text-sm font-mono" placeholder="Beispiele:
- Handtücher müssen IMMER als Schwan gefaltet sein
- Bett muss mit weisser Bettwäsche bezogen sein
- Toilettenpapier muss zu einem Dreieck gefaltet sein
- Willkommenskarte muss auf dem Kissen liegen
- Küche: Spüle muss komplett leer und trocken sein
- Bad: Spiegel muss streifenfrei sein
- Score unter 7 = Partner muss nochmal kommen
- Bei Schäden sofort Telegram-Alarm"><?= e($currentRules) ?></textarea>
        <p class="text-[10px] text-gray-400 mt-1">Diese Regeln werden bei JEDER Foto-Analyse an die KI gesendet. Schreiben Sie einfach auf Deutsch was Ihnen wichtig ist — die KI versteht natürliche Sprache.</p>
      </div>

      <div class="bg-gray-50 rounded-xl p-4">
        <div class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">So funktioniert es</div>
        <div class="text-xs text-gray-600 space-y-1">
          <div class="flex gap-2"><span class="text-brand font-bold">1.</span> KI bekommt das Foto + Ihre Regeln + die Service-Checkliste des Kunden</div>
          <div class="flex gap-2"><span class="text-brand font-bold">2.</span> Prüft jedes Checklist-Item + Ihre Regeln einzeln</div>
          <div class="flex gap-2"><span class="text-brand font-bold">3.</span> Gibt Score 1-10 + konkrete Issues die nicht stimmen</div>
          <div class="flex gap-2"><span class="text-brand font-bold">4.</span> Partner sieht sofort was nachgebessert werden muss</div>
        </div>
      </div>

      <button type="submit" class="px-6 py-2.5 bg-brand hover:bg-brand-dark text-white rounded-xl font-semibold text-sm transition">Regeln speichern</button>
    </form>
  </div>
</div>

<?php if (empty($scores)): ?>
<div class="bg-white rounded-xl border p-16 text-center">
  <div class="text-5xl mb-4">🤖</div>
  <h3 class="text-lg font-bold text-gray-900 mb-2">Noch keine Foto-Analysen</h3>
  <p class="text-sm text-gray-500">Partner können Fotos beim Job-Abschluss analysieren lassen. Die Ergebnisse erscheinen hier.</p>
</div>
<?php else: ?>

<!-- Scores Table -->
<div class="bg-white rounded-xl border overflow-hidden">
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead>
        <tr class="bg-gray-50 text-left text-xs text-gray-500 uppercase tracking-wider">
          <th class="px-4 py-3">Score</th>
          <th class="px-4 py-3">Typ</th>
          <th class="px-4 py-3">Job</th>
          <th class="px-4 py-3">Kunde</th>
          <th class="px-4 py-3">Partner</th>
          <th class="px-4 py-3">Foto</th>
          <th class="px-4 py-3">Details</th>
          <th class="px-4 py-3">Datum</th>
        </tr>
      </thead>
      <tbody class="divide-y">
        <?php foreach ($scores as $s):
          $scoreColor = $s['score'] >= 8 ? 'bg-green-500' : ($s['score'] >= 6 ? 'bg-amber-500' : 'bg-red-500');
          $analysis = json_decode($s['analysis_json'] ?? '', true) ?: [];
        ?>
        <tr class="hover:bg-gray-50">
          <td class="px-4 py-3">
            <div class="flex items-center gap-2">
              <div class="w-10 h-10 rounded-lg <?= $scoreColor ?> text-white flex items-center justify-center font-bold text-lg">
                <?= (int)$s['score'] ?>
              </div>
              <span class="text-xs font-semibold <?= $s['score'] >= 7 ? 'text-green-700' : 'text-red-700' ?>">
                <?= $s['score'] >= 7 ? '✓ OK' : '✕ Fail' ?>
              </span>
            </div>
          </td>
          <td class="px-4 py-3">
            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase
              <?= match($s['photo_type']) { 'before' => 'bg-blue-100 text-blue-700', 'after' => 'bg-green-100 text-green-700', 'damage' => 'bg-red-100 text-red-700', default => 'bg-gray-100 text-gray-600' } ?>">
              <?= e($s['photo_type']) ?>
            </span>
          </td>
          <td class="px-4 py-3 font-mono text-xs">
            <?php if ($s['job_id_fk']): ?>
            <a href="/admin/jobs.php?highlight=<?= $s['job_id_fk'] ?>" class="text-brand hover:underline">#<?= $s['job_id_fk'] ?></a>
            <div class="text-[10px] text-gray-400"><?= date('d.m.', strtotime($s['j_date'] ?? '')) ?></div>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td class="px-4 py-3 text-xs"><?= e($s['cname'] ?? '—') ?></td>
          <td class="px-4 py-3 text-xs"><?= e($s['ename'] ?? '—') ?></td>
          <td class="px-4 py-3">
            <?php if ($s['photo_path']): ?>
            <a href="<?= e($s['photo_path']) ?>" target="_blank">
              <img src="<?= e($s['photo_path']) ?>" class="w-12 h-12 object-cover rounded-lg border hover:scale-150 transition"/>
            </a>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td class="px-4 py-3 text-xs max-w-[200px]">
            <?php if (!empty($analysis['verdict'])): ?>
            <div class="text-gray-700 truncate"><?= e($analysis['verdict']) ?></div>
            <?php endif; ?>
            <?php if (!empty($analysis['issues'])): ?>
            <div class="text-[10px] text-red-600 mt-0.5"><?= count($analysis['issues']) ?> Issues</div>
            <?php endif; ?>
          </td>
          <td class="px-4 py-3 text-xs text-gray-400 font-mono"><?= date('d.m. H:i', strtotime($s['created_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
