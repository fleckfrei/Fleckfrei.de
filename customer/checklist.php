<?php
/**
 * Customer Checklist Editor — per service
 * Customer defines what to clean (with photos, priority, description).
 * Partner sees it auto-translated in their language at job time.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/checklist-templates.php';
requireCustomer();
$title = 'Reinigungs-Checkliste';
$page = 'checklist';
$cid = me()['id'];
$cid_for_handlers = $cid ?? me()['id'];
// === Checklist Media Handlers ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action']??''), ['upload_media','delete_media'], true)) {
    $itemId = (int)($_POST['checklist_id'] ?? 0);
    // ownership check
    $owns = val("SELECT COUNT(*) FROM service_checklists WHERE checklist_id=? AND customer_id_fk=?", [$itemId, $cid_for_handlers]);
    if (!$owns) { header('Location: ' . $_SERVER['REQUEST_URI']); exit; }

    if ($_POST['action'] === 'upload_media' && !empty($_FILES['mediafile']['tmp_name'])) {
        $mime = mime_content_type($_FILES['mediafile']['tmp_name']);
        $isImg = str_starts_with($mime, 'image/');
        $isVid = str_starts_with($mime, 'video/');
        if (($isImg || $isVid) && $_FILES['mediafile']['size'] < 50*1024*1024) {
            $type = $isImg ? 'image' : 'video';
            $ext = pathinfo($_FILES['mediafile']['name'], PATHINFO_EXTENSION);
            $dir = __DIR__ . '/../uploads/checklist-media/' . $itemId . '/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $fname = $type . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (move_uploaded_file($_FILES['mediafile']['tmp_name'], $dir . $fname)) {
                q("INSERT INTO checklist_media (checklist_id_fk, media_type, file_path, file_name, file_size, mime_type, caption, uploaded_by) VALUES (?,?,?,?,?,?,?,?)",
                  [$itemId, $type, '/uploads/checklist-media/' . $itemId . '/' . $fname,
                   $_FILES['mediafile']['name'], $_FILES['mediafile']['size'], $mime, trim($_POST['caption'] ?? ''), 'customer']);
            }
        }
    }
    if ($_POST['action'] === 'delete_media') {
        $mid = (int)($_POST['media_id'] ?? 0);
        $m = one("SELECT file_path FROM checklist_media WHERE media_id=? AND checklist_id_fk=?", [$mid, $itemId]);
        if ($m) {
            @unlink(__DIR__ . '/..' . $m['file_path']);
            q("DELETE FROM checklist_media WHERE media_id=? AND checklist_id_fk=?", [$mid, $itemId]);
        }
    }
    header('Location: ' . $_SERVER['REQUEST_URI']); exit;
}

// Pre-load media for all items
$mediaByItem = [];
$mediaRows = all("SELECT m.* FROM checklist_media m INNER JOIN service_checklists c ON m.checklist_id_fk=c.checklist_id WHERE c.customer_id_fk=?", [$cid_for_handlers]);
foreach ($mediaRows as $m) $mediaByItem[$m['checklist_id_fk']][] = $m;



// Service selection
$serviceId = (int)($_GET['service_id'] ?? 0);
$services = all("SELECT s_id, title, street, city FROM services WHERE customer_id_fk=? AND status=1 ORDER BY title", [$cid]);

// Default to first service if none selected
if (!$serviceId && !empty($services)) $serviceId = (int)$services[0]['s_id'];
$activeService = $serviceId ? one("SELECT * FROM services WHERE s_id=? AND customer_id_fk=?", [$serviceId, $cid]) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { header('Location: /customer/checklist.php?service_id=' . $serviceId); exit; }
    $act = $_POST['action'] ?? '';

    if ($act === 'add_item' && $activeService) {
        $title = trim($_POST['title'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $room  = trim($_POST['room'] ?? '');
        $priority = in_array($_POST['priority'] ?? '', ['normal','high','critical'], true) ? $_POST['priority'] : 'normal';

        // Photo upload
        $photoPath = null;
        if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $tmp = $_FILES['photo']['tmp_name'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($tmp);
            $allowedMimes = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
            if (isset($allowedMimes[$mime]) && $_FILES['photo']['size'] < 10*1024*1024) {
                $dir = __DIR__ . '/../uploads/checklists/' . $serviceId . '/';
                if (!is_dir($dir)) @mkdir($dir, 0755, true);
                $fname = bin2hex(random_bytes(8)) . '.' . $allowedMimes[$mime];
                if (move_uploaded_file($tmp, $dir . $fname)) {
                    $photoPath = '/uploads/checklists/' . $serviceId . '/' . $fname;
                }
            }
        }

        if ($title !== '') {
            $maxPos = (int) val("SELECT COALESCE(MAX(position),0) FROM service_checklists WHERE s_id_fk=?", [$serviceId]);
            q("INSERT INTO service_checklists (s_id_fk, customer_id_fk, room, position, title, description, photo, priority) VALUES (?,?,?,?,?,?,?,?)",
              [$serviceId, $cid, $room, $maxPos + 10, $title, $desc, $photoPath, $priority]);
        }
        header('Location: /customer/checklist.php?service_id=' . $serviceId . '&saved=1'); exit;
    }

    if ($act === 'delete_item') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        // Soft delete — keep history
        q("UPDATE service_checklists SET is_active=0 WHERE checklist_id=? AND customer_id_fk=?", [$itemId, $cid]);
        header('Location: /customer/checklist.php?service_id=' . $serviceId . '&deleted=1'); exit;
    }

    if ($act === 'add_ai_ideas' && $activeService) {
        $picked = json_decode($_POST['ideas'] ?? '[]', true);
        $count = 0;
        if (is_array($picked)) {
            $maxPos = (int) val("SELECT COALESCE(MAX(position),0) FROM service_checklists WHERE s_id_fk=?", [$serviceId]);
            $pos = $maxPos + 10;
            foreach ($picked as $idea) {
                if (empty($idea['title'])) continue;
                $prio = in_array($idea['priority'] ?? '', ['normal','high','critical'], true) ? $idea['priority'] : 'normal';
                q("INSERT INTO service_checklists (s_id_fk, customer_id_fk, room, position, title, description, priority) VALUES (?,?,?,?,?,?,?)",
                  [$serviceId, $cid, mb_substr($idea['room'] ?? '', 0, 100), $pos, mb_substr($idea['title'], 0, 200), mb_substr($idea['description'] ?? '', 0, 500), $prio]);
                $pos += 10;
                $count++;
            }
        }
        header('Location: /customer/checklist.php?service_id=' . $serviceId . '&ai_added=' . $count); exit;
    }

    if ($act === 'import_template' && $activeService) {
        $templateKey = $_POST['template'] ?? '';
        $templates = getChecklistTemplates();
        if (isset($templates[$templateKey])) {
            $maxPos = (int) val("SELECT COALESCE(MAX(position),0) FROM service_checklists WHERE s_id_fk=?", [$serviceId]);
            $pos = $maxPos + 10;
            $count = 0;
            foreach ($templates[$templateKey]['items'] as $item) {
                q("INSERT INTO service_checklists (s_id_fk, customer_id_fk, room, position, title, description, priority) VALUES (?,?,?,?,?,?,?)",
                  [$serviceId, $cid, $item['room'] ?? '', $pos, $item['title'], $item['description'] ?? '', $item['priority'] ?? 'normal']);
                $pos += 10;
                $count++;
            }
        }
        header('Location: /customer/checklist.php?service_id=' . $serviceId . '&imported=' . ($count ?? 0)); exit;
    }

    if ($act === 'update_item') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $priority = in_array($_POST['priority'] ?? '', ['normal','high','critical'], true) ? $_POST['priority'] : 'normal';
        if ($title !== '') {
            q("UPDATE service_checklists SET title=?, description=?, priority=?, translated_cache=NULL WHERE checklist_id=? AND customer_id_fk=?",
              [$title, $desc, $priority, $itemId, $cid]);
        }
        header('Location: /customer/checklist.php?service_id=' . $serviceId . '&updated=1'); exit;
    }
}

$items = $activeService
    ? all("SELECT * FROM service_checklists WHERE s_id_fk=? AND is_active=1 ORDER BY position, checklist_id", [$serviceId])
    : [];

// Group by room
$byRoom = [];
foreach ($items as $it) {
    $r = $it['room'] ?: 'Allgemein';
    $byRoom[$r][] = $it;
}

include __DIR__ . '/../includes/layout-customer.php';
?>

<!-- Back button -->
<div class="mb-4">
  <a href="/customer/" class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-brand">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
    Zurück
  </a>
</div>

<!-- Header -->
<div class="mb-6">
  <h1 class="text-2xl font-bold text-gray-900">Reinigungs-Checkliste</h1>
  <p class="text-gray-500 text-sm mt-1">Definieren Sie pro Service was gereinigt werden soll — mit Fotos und Beschreibungen. Der Partner sieht alles in seiner Sprache, automatisch übersetzt.</p>
</div>

<!-- Service selector -->
<?php if (count($services) > 1): ?>
<div class="mb-6">
  <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wide mb-1.5">Service auswählen</label>
  <select onchange="window.location='?service_id=' + this.value" class="w-full max-w-md px-4 py-3 border-2 border-gray-100 rounded-xl bg-white focus:border-brand focus:ring-4 focus:ring-brand/10 outline-none font-medium">
    <?php foreach ($services as $svc): ?>
    <option value="<?= $svc['s_id'] ?>" <?= $svc['s_id'] == $serviceId ? 'selected' : '' ?>>
      <?= e($svc['title']) ?> — <?= e($svc['street']) ?>, <?= e($svc['city']) ?>
    </option>
    <?php endforeach; ?>
  </select>
</div>
<?php endif; ?>

<?php if (!$activeService): ?>
<div class="card-elev text-center py-16 px-4">
  <div class="text-5xl mb-3">📋</div>
  <h3 class="font-bold text-gray-900 mb-2">Noch keine Services</h3>
  <p class="text-sm text-gray-500">Erstellen Sie zuerst einen Service unter <a href="/customer/services.php" class="text-brand font-semibold">Meine Services</a>.</p>
</div>
<?php else: ?>

<div class="mb-6 p-4 bg-brand-light rounded-xl border border-brand/20 flex items-center gap-3 flex-wrap">
  <div class="flex-1 min-w-0">
    <div class="font-bold text-brand"><?= e($activeService['title']) ?></div>
    <div class="text-xs text-gray-600"><?= e($activeService['street']) ?>, <?= e($activeService['city']) ?></div>
  </div>
  <div class="text-xs bg-white px-3 py-1.5 rounded-full border border-brand/20 font-semibold text-brand">
    <?= count($items) ?> Aufgabe<?= count($items) === 1 ? '' : 'n' ?>
  </div>
</div>

<?php if (!empty($_GET['imported'])): ?>
<div class="mb-4 p-3 rounded-xl bg-green-50 border border-green-200 text-sm text-green-800 flex items-center justify-between gap-3" x-data="{ undone: false }">
  <span x-show="!undone">✓ Vorlage importiert — <?= (int)$_GET['imported'] ?> Aufgaben hinzugefügt.</span>
  <span x-show="undone" x-cloak>Rückgängig gemacht.</span>
  <button type="button" x-show="!undone" @click="fetch('/api/checklist-undo.php?service_id=<?= $serviceId ?>&count=<?= (int)$_GET['imported'] ?>', {credentials:'same-origin'}).then(() => { undone = true; setTimeout(() => location.reload(), 800); })" class="text-xs font-semibold text-green-700 hover:text-green-900 underline whitespace-nowrap">Rückgängig</button>
</div>
<?php endif; ?>
<?php if (!empty($_GET['ai_added'])): ?>
<div class="mb-4 p-3 rounded-xl bg-brand-light border border-brand/30 text-sm text-brand-dark">
  ✨ <?= (int)$_GET['ai_added'] ?> AI-Vorschläge hinzugefügt
</div>
<?php endif; ?>

<!-- AI Ideas Generator -->
<?php if ($activeService): ?>
<div class="card-elev p-5 mb-6 bg-brand-light border border-brand/30" x-data="aiIdeas()">
  <div class="flex items-start gap-3 mb-3">
    <span class="text-2xl">🤖</span>
    <div class="flex-1 min-w-0">
      <h3 class="font-bold text-gray-900">AI-Ideen aus Airbnb Host Best Practices</h3>
      <p class="text-xs text-gray-600 mt-0.5">Basiert auf echten Host-Erfahrungen, Reddit /r/airbnb_hosts, Superhost-Standards und häufigen Gast-Beschwerden. Findet Aufgaben die Sie vielleicht übersehen haben.</p>
    </div>
    <button @click="loadIdeas()" :disabled="loading"
            class="flex-shrink-0 px-4 py-2 bg-brand hover:bg-brand-dark text-white rounded-xl text-xs font-bold shadow-lg shadow-brand/20 disabled:opacity-50 whitespace-nowrap">
      <span x-show="!loading">✨ Ideen vorschlagen</span>
      <span x-show="loading" x-cloak>⏳ Denke nach...</span>
    </button>
  </div>

  <!-- Ideas list -->
  <div x-show="ideas.length > 0" x-cloak class="mt-4 space-y-2">
    <div class="text-[11px] font-bold text-brand-dark uppercase tracking-wide flex items-center justify-between">
      <span x-text="ideas.length + ' Vorschläge · ' + source"></span>
      <div class="flex gap-1">
        <button type="button" @click="ideas.forEach(i => i.selected = true)" class="text-brand hover:text-brand-dark normal-case font-semibold">Alle auswählen</button>
        <span class="text-brand/30">·</span>
        <button type="button" @click="ideas.forEach(i => i.selected = false)" class="text-brand hover:text-brand-dark normal-case font-semibold">Keine</button>
      </div>
    </div>

    <template x-for="(idea, i) in ideas" :key="i">
      <label class="flex items-start gap-3 p-3 rounded-lg bg-white border hover:border-brand cursor-pointer transition"
             :class="idea.selected ? 'border-brand ring-2 ring-brand/30' : 'border-gray-200'">
        <input type="checkbox" x-model="idea.selected" class="mt-0.5 w-4 h-4 text-brand rounded focus:ring-brand"/>
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2 flex-wrap">
            <span class="font-semibold text-sm text-gray-900" x-text="idea.title"></span>
            <span class="text-[10px] px-1.5 py-0.5 rounded uppercase font-bold"
                  :class="{
                    'bg-red-100 text-red-700': idea.priority === 'critical',
                    'bg-amber-100 text-amber-700': idea.priority === 'high',
                    'bg-gray-100 text-gray-600': idea.priority === 'normal'
                  }"
                  x-text="idea.priority === 'critical' ? '🔴 Kritisch' : (idea.priority === 'high' ? '🟠 Wichtig' : 'Normal')"></span>
            <template x-if="idea.room"><span class="text-[10px] text-gray-400" x-text="'· ' + idea.room"></span></template>
          </div>
          <div class="text-xs text-gray-600 mt-1" x-text="idea.description"></div>
        </div>
      </label>
    </template>

    <!-- Import button -->
    <form method="POST" class="mt-3">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add_ai_ideas"/>
      <input type="hidden" name="ideas" :value="JSON.stringify(ideas.filter(i => i.selected))"/>
      <button type="submit" :disabled="!ideas.some(i => i.selected)"
              class="w-full py-2.5 bg-brand hover:bg-brand-dark disabled:bg-gray-300 text-white rounded-xl text-sm font-bold transition">
        <span x-text="ideas.filter(i => i.selected).length + ' Aufgaben hinzufügen'"></span>
      </button>
    </form>
  </div>

  <div x-show="error" x-cloak class="mt-3 text-xs text-red-600" x-text="error"></div>
</div>

<script>
function aiIdeas() {
  return {
    ideas: [],
    loading: false,
    error: null,
    source: '',
    async loadIdeas() {
      this.loading = true;
      this.error = null;
      try {
        const r = await fetch('/api/checklist-ideas.php?service_id=<?= (int)$serviceId ?>', { credentials: 'same-origin' });
        const d = await r.json();
        if (d.success && d.ideas && d.ideas.length > 0) {
          this.ideas = d.ideas.map(i => ({ ...i, selected: true }));
          this.source = d.source || '';
        } else {
          this.error = d.error || 'Keine Ideen gefunden';
        }
      } catch (e) {
        this.error = 'Netzwerk-Fehler: ' + e.message;
      }
      this.loading = false;
    }
  };
}
</script>
<?php endif; ?>

<!-- Template picker — only show if few or no items -->
<?php if (count($items) < 5): $templates = getChecklistTemplates(); ?>
<div class="card-elev p-5 mb-6">
  <div class="flex items-start gap-3 mb-4">
    <span class="text-2xl">✨</span>
    <div class="flex-1">
      <h3 class="font-bold text-gray-900">Schneller starten: Vorlage importieren</h3>
      <p class="text-xs text-gray-500 mt-0.5">Fertige Checklisten für typische Reinigungs-Szenarien. Nach dem Import können Sie einzelne Punkte anpassen oder löschen.</p>
    </div>
  </div>
  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
    <?php foreach ($templates as $key => $tpl): ?>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="import_template"/>
      <input type="hidden" name="template" value="<?= e($key) ?>"/>
      <button type="submit" class="w-full text-left p-4 rounded-xl border-2 border-gray-100 hover:border-brand hover:bg-brand/5 transition group">
        <div class="text-2xl mb-1"><?= $tpl['icon'] ?></div>
        <div class="font-bold text-sm text-gray-900 group-hover:text-brand"><?= e($tpl['label']) ?></div>
        <div class="text-[11px] text-gray-500 mt-0.5 line-clamp-2"><?= e($tpl['description']) ?></div>
        <div class="mt-2 text-[10px] font-semibold text-brand flex items-center gap-1">
          <?= count($tpl['items']) ?> Aufgaben importieren
          <svg class="w-3 h-3 group-hover:translate-x-1 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
        </div>
      </button>
    </form>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Add new item form -->
<div class="card-elev p-5 mb-6" x-data="{ open: <?= empty($items) ? 'true' : 'false' ?> }">
  <button @click="open = !open" class="w-full flex items-center justify-between mb-0" type="button">
    <span class="font-bold text-gray-900 flex items-center gap-2">
      <svg class="w-5 h-5 text-brand" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
      Neue Aufgabe hinzufügen
    </span>
    <svg class="w-5 h-5 text-gray-400 transition" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
  </button>

  <form x-show="open" x-cloak method="POST" enctype="multipart/form-data" class="mt-4 space-y-3">
    <input type="hidden" name="_csrf" value="<?= csrfToken() ?>"/>
    <input type="hidden" name="action" value="add_item"/>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
      <div>
        <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wide mb-1">Raum / Bereich</label>
        <input type="text" name="room" placeholder="z.B. Badezimmer, Küche, Wohnzimmer" class="w-full px-3 py-2 border-2 border-gray-100 rounded-xl focus:border-brand outline-none" list="room-suggestions"/>
        <datalist id="room-suggestions">
          <option value="Wohnzimmer"/>
          <option value="Schlafzimmer"/>
          <option value="Badezimmer"/>
          <option value="Küche"/>
          <option value="Flur"/>
          <option value="Balkon / Terrasse"/>
          <option value="Waschraum"/>
        </datalist>
      </div>
      <div>
        <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wide mb-1">Priorität</label>
        <select name="priority" class="w-full px-3 py-2 border-2 border-gray-100 rounded-xl focus:border-brand outline-none">
          <option value="normal">Normal</option>
          <option value="high">Wichtig</option>
          <option value="critical">Unbedingt beachten</option>
        </select>
      </div>
    </div>

    <div>
      <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wide mb-1">Aufgabe / Titel*</label>
      <input type="text" name="title" required placeholder="z.B. Backofen innen reinigen" class="w-full px-3 py-2 border-2 border-gray-100 rounded-xl focus:border-brand outline-none"/>
    </div>

    <div>
      <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wide mb-1">Beschreibung / Anleitung</label>
      <textarea name="description" rows="3" placeholder="Genaue Erklärung was zu tun ist, welche Mittel verwendet werden sollen, worauf zu achten ist..." class="w-full px-3 py-2 border-2 border-gray-100 rounded-xl focus:border-brand outline-none"></textarea>
    </div>

    <div>
      <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wide mb-1">Foto (optional)</label>
      <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="w-full px-3 py-2 border-2 border-gray-100 rounded-xl file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:bg-brand/10 file:text-brand file:font-semibold"/>
      <div class="text-[10px] text-gray-400 mt-1">JPG/PNG/WebP, max. 10 MB. Hilft dem Partner genau zu sehen was gemeint ist.</div>
    </div>

    <button type="submit" class="w-full bg-brand hover:bg-brand-dark text-white font-bold py-3 rounded-xl transition shadow">Aufgabe hinzufügen</button>
  </form>
</div>

<!-- Existing items, grouped by room -->
<?php foreach ($byRoom as $roomName => $roomItems): ?>
<div class="mb-6">
  <h3 class="font-bold text-gray-900 text-sm uppercase tracking-wide mb-3 flex items-center gap-2">
    <span class="w-1.5 h-4 bg-brand rounded-full"></span>
    <?= e($roomName) ?>
    <span class="text-xs font-normal text-gray-400">(<?= count($roomItems) ?>)</span>
  </h3>
  <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
    <?php foreach ($roomItems as $item):
      $prColor = match($item['priority']) {
        'critical' => 'border-red-300 bg-red-50',
        'high'     => 'border-amber-300 bg-amber-50',
        default    => 'border-gray-200 bg-white',
      };
      $prBadge = match($item['priority']) {
        'critical' => ['🔴','text-red-700 bg-red-100','Unbedingt'],
        'high'     => ['🟠','text-amber-700 bg-amber-100','Wichtig'],
        default    => ['🟢','text-gray-600 bg-gray-100','Normal'],
      };
    ?>
    <div class="card-elev p-4 border <?= $prColor ?>">
      <?php if ($item['photo']): ?>
      <a href="<?= e($item['photo']) ?>" target="_blank" class="block mb-3">
        <img src="<?= e($item['photo']) ?>" class="w-full h-32 object-cover rounded-lg" alt=""/>
      </a>
      <?php endif; ?>

      <!-- Multi-Media Gallery (Customer → Partner instructions) -->
      <?php $itemMedia = $mediaByItem[$item['checklist_id']] ?? []; ?>
      <?php if ($itemMedia): ?>
      <div class="grid grid-cols-3 gap-1.5 mb-2">
        <?php foreach ($itemMedia as $m): ?>
        <div class="relative group">
          <?php if ($m['media_type'] === 'video'): ?>
            <video src="<?= e($m['file_path']) ?>" controls class="w-full h-20 object-cover rounded-md bg-black"></video>
          <?php else: ?>
            <a href="<?= e($m['file_path']) ?>" target="_blank">
              <img src="<?= e($m['file_path']) ?>" class="w-full h-20 object-cover rounded-md" alt=""/>
            </a>
          <?php endif; ?>
          <?php if ($m['caption']): ?><div class="text-[10px] text-gray-500 mt-0.5 truncate" title="<?= e($m['caption']) ?>"><?= e($m['caption']) ?></div><?php endif; ?>
          <form method="POST" class="absolute top-1 right-1 opacity-0 group-hover:opacity-100 transition" onsubmit="return confirm('Datei löschen?')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete_media"/>
            <input type="hidden" name="checklist_id" value="<?= $item['checklist_id'] ?>"/>
            <input type="hidden" name="media_id" value="<?= $m['media_id'] ?>"/>
            <button class="bg-red-500 text-white text-[10px] w-5 h-5 rounded-full flex items-center justify-center" title="Löschen">×</button>
          </form>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- Upload Bilder/Videos für Partner-Anweisungen -->
      <details class="mb-2 text-xs">
        <summary class="cursor-pointer text-gray-500 hover:text-brand">📎 Bild/Video für Partner hochladen</summary>
        <form method="POST" enctype="multipart/form-data" class="mt-2 p-2 bg-gray-50 rounded-lg">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="upload_media"/>
          <input type="hidden" name="checklist_id" value="<?= $item['checklist_id'] ?>"/>
          <input type="file" name="mediafile" accept="image/*,video/*" required class="block w-full text-xs mb-1.5"/>
          <input name="caption" placeholder="Kurze Beschreibung (optional)" class="block w-full px-2 py-1 border rounded text-xs mb-1.5"/>
          <button type="submit" class="px-3 py-1 bg-brand text-white rounded text-xs">📤 Hochladen</button>
          <span class="text-[10px] text-gray-400">max 50MB · Bilder oder Videos</span>
        </form>
      </details>

      <div class="flex items-start justify-between gap-2 mb-1">
        <h4 class="font-bold text-gray-900 text-sm flex-1"><?= e($item['title']) ?></h4>
        <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold whitespace-nowrap <?= $prBadge[1] ?>"><?= $prBadge[0] ?> <?= $prBadge[2] ?></span>
      </div>
      <?php if ($item['description']): ?>
      <p class="text-xs text-gray-600 mb-3"><?= nl2br(e($item['description'])) ?></p>
      <?php endif; ?>
      <form method="POST" onsubmit="return confirm('Aufgabe entfernen?')">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="delete_item"/>
        <input type="hidden" name="item_id" value="<?= $item['checklist_id'] ?>"/>
        <button type="submit" class="text-[11px] text-red-500 hover:text-red-700 font-semibold">× Entfernen</button>
      </form>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>

<?php if (empty($items)): ?>
<div class="card-elev text-center py-12 px-4">
  <div class="text-4xl mb-3">📝</div>
  <h3 class="font-bold text-gray-900 mb-1">Noch keine Aufgaben</h3>
  <p class="text-sm text-gray-500">Fügen Sie oben die erste Aufgabe hinzu — der Partner sieht sie beim nächsten Job.</p>
</div>
<?php endif; ?>

<?php endif; /* activeService */ ?>

<?php include __DIR__ . '/../includes/footer-customer.php'; ?>
