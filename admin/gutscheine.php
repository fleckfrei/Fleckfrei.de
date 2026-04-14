<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Gutscheine'; $page = 'gutscheine';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { header('Location: /admin/gutscheine.php'); exit; }
    $act = $_POST['action'] ?? '';
    $me  = $_SESSION['uemail'] ?? 'admin';

    if ($act === 'add_voucher') {
        $code = strtoupper(trim($_POST['code'] ?? ''));
        if ($code === '') { header('Location: /admin/gutscheine.php?err=code'); exit; }
        $exists = val("SELECT v_id FROM vouchers WHERE code=?", [$code]);
        if ($exists) { header('Location: /admin/gutscheine.php?err=dup'); exit; }
        q("INSERT INTO vouchers (code,description,type,value,min_amount,valid_from,valid_until,max_uses,max_per_customer,customer_type,active,created_by,internal_notes,block_until_time) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)", [
            $code,
            $_POST['description'] ?? '',
            $_POST['type'] ?? 'percent',
            (float)($_POST['value'] ?? 0),
            (float)($_POST['min_amount'] ?? 0),
            !empty($_POST['valid_from']) ? $_POST['valid_from'] : null,
            !empty($_POST['valid_until']) ? $_POST['valid_until'] : null,
            (int)($_POST['max_uses'] ?? 0),
            (int)($_POST['max_per_customer'] ?? 1),
            $_POST['customer_type'] ?? '',
            isset($_POST['active']) ? 1 : 0,
            $me,
            trim($_POST['internal_notes'] ?? '') ?: null,
            trim($_POST['block_until_time'] ?? '') ?: null
        ]);
        header("Location: /admin/gutscheine.php?added=1"); exit;
    }
    if ($act === 'edit_voucher') {
        $code = strtoupper(trim($_POST['code'] ?? ''));
        $vid  = (int)($_POST['v_id'] ?? 0);
        $dup  = val("SELECT v_id FROM vouchers WHERE code=? AND v_id<>?", [$code, $vid]);
        if ($dup) { header('Location: /admin/gutscheine.php?err=dup'); exit; }
        q("UPDATE vouchers SET code=?,description=?,type=?,value=?,min_amount=?,valid_from=?,valid_until=?,max_uses=?,max_per_customer=?,customer_type=?,active=?,internal_notes=?,block_until_time=? WHERE v_id=?", [
            $code,
            $_POST['description'] ?? '',
            $_POST['type'] ?? 'percent',
            (float)($_POST['value'] ?? 0),
            (float)($_POST['min_amount'] ?? 0),
            !empty($_POST['valid_from']) ? $_POST['valid_from'] : null,
            !empty($_POST['valid_until']) ? $_POST['valid_until'] : null,
            (int)($_POST['max_uses'] ?? 0),
            (int)($_POST['max_per_customer'] ?? 1),
            $_POST['customer_type'] ?? '',
            isset($_POST['active']) ? 1 : 0,
            trim($_POST['internal_notes'] ?? '') ?: null,
            trim($_POST['block_until_time'] ?? '') ?: null,
            $vid
        ]);
        header("Location: /admin/gutscheine.php?saved=1"); exit;
    }
    if ($act === 'toggle_voucher') {
        q("UPDATE vouchers SET active=1-active WHERE v_id=?", [(int)$_POST['v_id']]);
        header('Location: /admin/gutscheine.php?saved=1'); exit;
    }
    if ($act === 'delete_voucher') {
        q("UPDATE vouchers SET active=0 WHERE v_id=?", [(int)$_POST['v_id']]);
        header('Location: /admin/gutscheine.php'); exit;
    }
}

$tab = $_GET['tab'] ?? 'active';
$activeFilter = $tab === 'archive' ? 0 : 1;

$vouchers = all("SELECT v.*,
    (SELECT COUNT(*) FROM voucher_redemptions vr WHERE vr.voucher_id_fk=v.v_id) AS redemptions,
    (SELECT COALESCE(SUM(vr.discount_amount),0) FROM voucher_redemptions vr WHERE vr.voucher_id_fk=v.v_id) AS discount_total
    FROM vouchers v WHERE v.active=? ORDER BY v.created_at DESC", [$activeFilter]);

$activeCount  = (int)val("SELECT COUNT(*) FROM vouchers WHERE active=1");
$archiveCount = (int)val("SELECT COUNT(*) FROM vouchers WHERE active=0");

$recent = all("SELECT vr.*, v.code, COALESCE(c.name,'') AS cname
    FROM voucher_redemptions vr
    LEFT JOIN vouchers v ON vr.voucher_id_fk=v.v_id
    LEFT JOIN customer c ON vr.customer_id_fk=c.customer_id
    ORDER BY vr.redeemed_at DESC LIMIT 25");

include __DIR__ . '/../includes/layout.php';
?>

<?php if (!empty($_GET['saved'])||!empty($_GET['added'])): ?><div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4">Gespeichert.</div><?php endif; ?>
<?php if (($_GET['err'] ?? '') === 'dup'): ?><div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-xl mb-4">Code existiert bereits.</div><?php endif; ?>
<?php if (($_GET['err'] ?? '') === 'code'): ?><div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-xl mb-4">Code darf nicht leer sein.</div><?php endif; ?>

<!-- Tabs -->
<div class="flex gap-1 mb-4 bg-white rounded-xl border p-1 w-fit">
  <a href="/admin/gutscheine.php?tab=active" class="px-4 py-2 rounded-lg text-sm font-medium transition <?= $tab==='active' ? 'bg-brand text-white' : 'text-gray-500 hover:bg-gray-100' ?>">Aktiv <span class="ml-1 text-xs opacity-75">(<?= $activeCount ?>)</span></a>
  <a href="/admin/gutscheine.php?tab=archive" class="px-4 py-2 rounded-lg text-sm font-medium transition <?= $tab==='archive' ? 'bg-gray-700 text-white' : 'text-gray-500 hover:bg-gray-100' ?>">Archiv <span class="ml-1 text-xs opacity-75">(<?= $archiveCount ?>)</span></a>
</div>

<div x-data="voucherForm()" class="bg-white rounded-xl border">
  <div class="p-5 border-b flex items-center justify-between">
    <h3 class="font-semibold"><?= $tab==='archive' ? 'Archivierte' : 'Aktive' ?> Gutscheine (<?= count($vouchers) ?>)</h3>
    <div class="flex gap-3">
      <input type="text" placeholder="Suchen..." class="px-3 py-2 border rounded-lg text-sm w-64" oninput="filterRows(this.value)"/>
      <?php if ($tab === 'active'): ?>
      <button @click="v={code:'',description:'',type:'percent',value:10,min_amount:0,valid_from:'',valid_until:'',max_uses:0,max_per_customer:1,customer_type:'',active:1}; editOpen=true" class="px-4 py-2 bg-brand text-white rounded-xl text-sm font-medium">+ Neuer Gutschein</button>
      <?php endif; ?>
    </div>
  </div>
  <div class="overflow-x-auto">
    <table class="w-full text-sm" id="tbl">
      <thead class="bg-gray-50"><tr>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Code</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Typ</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Wert</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Min.-Bestellung</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Gültig</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Limit</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Genutzt</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Rabatt &Sigma;</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Aktionen</th>
      </tr></thead>
      <tbody class="divide-y">
      <?php foreach ($vouchers as $vc): ?>
        <?php
          $expired = ($vc['valid_until'] && $vc['valid_until'] < date('Y-m-d'));
          $exhausted = ($vc['max_uses'] > 0 && $vc['used_count'] >= $vc['max_uses']);
        ?>
        <tr class="hover:bg-gray-50">
          <td class="px-4 py-3"><span class="font-mono font-semibold text-brand"><?= e($vc['code']) ?></span><?php if ($vc['description']): ?><div class="text-xs text-gray-400"><?= e($vc['description']) ?></div><?php endif; ?></td>
          <td class="px-4 py-3">
            <?= ['percent'=>'Prozent','fixed'=>'Fix-Betrag','free'=>'Kostenlos','target'=>'🎯 Zielpreis','hourly_target'=>'⏱ Std-Override'][$vc['type']] ?? $vc['type'] ?>
            <?php if (!empty($vc['internal_notes'])): ?><div class="text-[10px] text-yellow-700 mt-0.5" title="<?= e($vc['internal_notes']) ?>">🔒 <?= e(mb_strimwidth($vc['internal_notes'],0,30,'…')) ?></div><?php endif; ?>
          </td>
          <?php $vatDiv = in_array($vc['customer_type'], ['host','b2b']) ? 1.0 : 1.19; $vatLabel = $vatDiv > 1 ? 'netto' : 'netto = brutto'; ?>
          <td class="px-4 py-3 font-medium">
            <?php if ($vc['type']==='percent'): ?>
              <?= rtrim(rtrim(number_format($vc['value'],2,',','.'),'0'),',').' %' ?>
            <?php elseif ($vc['type']==='free'): ?>
              —
            <?php else: ?>
              <?= money($vc['value']) ?>
              <?php if ($vatDiv > 1): ?><div class="text-xs text-gray-400 font-normal"><?= money($vc['value']/$vatDiv) ?> netto</div><?php endif; ?>
            <?php endif; ?>
          </td>
          <td class="px-4 py-3 text-gray-500">
            <?php if ($vc['min_amount'] > 0): ?>
              <?= money($vc['min_amount']) ?>
              <?php if ($vatDiv > 1): ?><div class="text-xs text-gray-400"><?= money($vc['min_amount']/$vatDiv) ?> netto</div><?php endif; ?>
            <?php else: ?>
              —
            <?php endif; ?>
          </td>
          <td class="px-4 py-3 text-xs text-gray-500"><?= $vc['valid_from'] ? date('d.m.y',strtotime($vc['valid_from'])) : '∞' ?> → <?= $vc['valid_until'] ? date('d.m.y',strtotime($vc['valid_until'])) : '∞' ?></td>
          <td class="px-4 py-3 text-xs"><?= $vc['max_uses']>0 ? $vc['max_uses'].' total' : '∞' ?> · <?= $vc['max_per_customer']>0 ? $vc['max_per_customer'].'/Kunde' : '∞/Kunde' ?></td>
          <td class="px-4 py-3"><?= (int)$vc['used_count'] ?> / <?= (int)$vc['redemptions'] ?></td>
          <td class="px-4 py-3 text-gray-600"><?= money($vc['discount_total']) ?></td>
          <td class="px-4 py-3"><div class="flex gap-1 items-center">
            <?php if ($expired): ?><span class="px-2 py-0.5 text-xs bg-red-50 text-red-600 rounded">Abgelaufen</span><?php endif; ?>
            <?php if ($exhausted): ?><span class="px-2 py-0.5 text-xs bg-orange-50 text-orange-600 rounded">Aufgebraucht</span><?php endif; ?>
            <?php if ($tab === 'archive'): ?>
              <form method="POST" class="inline"><?= csrfField() ?><input type="hidden" name="action" value="toggle_voucher"/><input type="hidden" name="v_id" value="<?= $vc['v_id'] ?>"/><button class="px-2 py-1 text-xs bg-green-100 text-green-700 rounded-lg font-medium">Aktivieren</button></form>
            <?php else: ?>
              <button @click='v=<?= json_encode($vc, JSON_HEX_APOS) ?>; v.active=Number(v.active); editOpen=true' class="px-2 py-1 text-xs bg-brand/10 text-brand rounded-lg">Edit</button>
              <form method="POST" class="inline" onsubmit="return confirm('Gutschein archivieren?')"><?= csrfField() ?><input type="hidden" name="action" value="delete_voucher"/><input type="hidden" name="v_id" value="<?= $vc['v_id'] ?>"/><button class="px-2 py-1 text-xs bg-red-50 text-red-600 rounded-lg">Archiv</button></form>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($vouchers)): ?>
        <tr><td colspan="9" class="px-4 py-12 text-center text-gray-400">Keine Gutscheine. Erstelle den ersten mit "+ Neuer Gutschein".</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <template x-if="editOpen">
    <div class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center overflow-y-auto py-4">
      <div class="bg-white rounded-2xl p-6 w-full max-w-2xl shadow-2xl m-4">
        <h3 class="text-lg font-semibold mb-4" x-text="v.v_id ? 'Gutschein bearbeiten' : 'Neuer Gutschein'"></h3>
        <form method="POST" class="grid grid-cols-2 gap-4">
          <?= csrfField() ?>
          <input type="hidden" name="action" :value="v.v_id ? 'edit_voucher' : 'add_voucher'"/>
          <input type="hidden" name="v_id" :value="v.v_id"/>

          <div><label class="block text-xs font-medium text-gray-500 mb-1">Code *</label><input name="code" x-model="v.code" required style="text-transform:uppercase" class="w-full px-3 py-2 border rounded-xl text-sm font-mono uppercase" placeholder="WELCOME10"/></div>
          <div><label class="block text-xs font-medium text-gray-500 mb-1">Beschreibung (intern)</label><input name="description" x-model="v.description" class="w-full px-3 py-2 border rounded-xl text-sm" placeholder="Neukunden-Aktion"/></div>

          <div><label class="block text-xs font-medium text-gray-500 mb-1">Typ *</label>
            <select name="type" x-model="v.type" class="w-full px-3 py-2 border-2 rounded-xl text-sm" :class="v.type==='target' ? 'border-brand bg-brand-light/20' : 'border-gray-200'">
              <option value="percent">📊 Prozent (% Rabatt)</option>
              <option value="fixed">💶 Fix-Betrag (€ Rabatt-Abzug)</option>
              <option value="free">🎁 Kostenlose Buchung</option>
              <option value="target">🎯 Zielpreis — Kunde zahlt MAX. X €</option>
              <option value="hourly_target">⏱ Stundenpreis-Override — X €/h statt Normalpreis</option>
            </select>
            <div class="text-[11px] mt-1 leading-snug"
                 :class="v.type==='target' ? 'text-brand font-medium' : 'text-gray-400'">
              <span x-show="v.type==='percent'">z.B. 10 → 10% vom Preis wird abgezogen</span>
              <span x-show="v.type==='fixed'">z.B. 20 → 20 € fixer Rabatt auf jeden Preis</span>
              <span x-show="v.type==='free'">Buchung ist komplett gratis</span>
              <span x-show="v.type==='target'">z.B. 50 → egal ob Preis 60 € oder 2340 €, Kunde zahlt <b>immer nur 50 €</b></span>
              <span x-show="v.type==='hourly_target'">z.B. 25 → egal ob Stundensatz 40 €/h oder 60 €/h, Kunde zahlt <b>nur 25 €/h × Stunden</b></span>
            </div>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Wert <span x-text="v.type==='percent' ? '(%)' : (v.type==='fixed' ? '(€ brutto Rabatt)' : (v.type==='target' ? '(€ brutto · Zielpreis)' : (v.type==='hourly_target' ? '(€/h brutto)' : '')))"></span></label>
            <input type="number" name="value" x-model="v.value" step="0.01" :disabled="v.type==='free'" class="w-full px-3 py-2 border rounded-xl text-sm disabled:bg-gray-100"/>
            <div class="text-xs text-gray-500 mt-1" x-text="wertHint()"></div>
          </div>

          <div class="col-span-2 -my-2 p-3 bg-gray-50 rounded-xl border border-gray-100 text-xs">
            <div class="grid grid-cols-2 gap-3 mb-3">
              <div>
                <label class="block text-gray-500 mb-1">Referenz Brutto (€)</label>
                <input type="number" step="0.01" :value="(Number(ref)||0).toFixed(2)" @input="setBrutto($event.target.value)" class="w-full px-2 py-1.5 border rounded-lg bg-white"/>
              </div>
              <div>
                <label class="block text-gray-500 mb-1">Referenz Netto (€) <span class="text-gray-300" x-text="'('+ vatLabel() +')'"></span></label>
                <input type="number" step="0.01" :value="nettoOf(Number(ref)||0).toFixed(2)" @input="setNetto($event.target.value)" :disabled="vatRate()===0" class="w-full px-2 py-1.5 border rounded-lg bg-white disabled:bg-gray-100"/>
              </div>
            </div>
            <div class="grid grid-cols-3 gap-3">
              <div><div class="text-gray-500 mb-1">Rabatt brutto</div><div class="font-semibold text-brand" x-text="money(discountBrutto())"></div></div>
              <div><div class="text-gray-500 mb-1">Rabatt netto</div><div class="font-semibold text-brand" x-text="money(discountNetto())"></div></div>
              <div><div class="text-gray-500 mb-1">Kunde zahlt / Umsatz netto</div><div class="font-semibold" x-text="money(finalBrutto()) + ' / ' + money(finalNetto())"></div></div>
            </div>
          </div>

          <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Mindestbestellwert (€ brutto)</label>
            <input type="number" name="min_amount" x-model="v.min_amount" step="0.01" class="w-full px-3 py-2 border rounded-xl text-sm"/>
            <div class="text-xs text-gray-500 mt-1" x-text="minHint()"></div>
          </div>
          <div><label class="block text-xs font-medium text-gray-500 mb-1">Kundentyp</label>
            <select name="customer_type" x-model="v.customer_type" class="w-full px-3 py-2 border rounded-xl text-sm">
              <option value="">Alle</option>
              <option value="private">Privat</option>
              <option value="host">Host (STR)</option>
              <option value="b2b">B2B</option>
            </select>
          </div>

          <div><label class="block text-xs font-medium text-gray-500 mb-1">Gültig ab</label><input type="date" name="valid_from" x-model="v.valid_from" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
          <div><label class="block text-xs font-medium text-gray-500 mb-1">Gültig bis</label><input type="date" name="valid_until" x-model="v.valid_until" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>

          <div><label class="block text-xs font-medium text-gray-500 mb-1">Max. Nutzungen total <span class="text-gray-300">(0 = unbegrenzt)</span></label><input type="number" name="max_uses" x-model="v.max_uses" min="0" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
          <div><label class="block text-xs font-medium text-gray-500 mb-1">Max. pro Kunde (Email) <span class="text-gray-300">(0 = unbegrenzt)</span></label><input type="number" name="max_per_customer" x-model="v.max_per_customer" min="0" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>

          <div class="col-span-2">
            <label class="block text-xs font-medium text-gray-500 mb-1">🚗 Partner-Tag blockieren bis <span class="text-gray-400">(Premium/Anfahrt · HH:MM · leer = kein Block)</span></label>
            <input type="time" name="block_until_time" x-model="v.block_until_time" class="w-full md:w-48 px-3 py-2 border rounded-xl text-sm"/>
            <div class="text-[11px] text-gray-500 mt-1">z.B. <code>15:00</code> → Partner darf an dem Tag bis 15:00 keinen anderen Job annehmen.</div>
          </div>
          <div class="col-span-2">
            <label class="block text-xs font-medium text-gray-500 mb-1">🔒 Interne Notiz (nie dem Kunden angezeigt)</label>
            <textarea name="internal_notes" x-model="v.internal_notes" rows="2" placeholder="z.B.: Kulanz für Frau Müller · Partner-Aktion Max · Reklamation #1234 …" class="w-full px-3 py-2 border rounded-xl text-sm bg-yellow-50 border-yellow-200 focus:bg-white"></textarea>
          </div>
          <div class="col-span-2"><label class="flex items-center gap-2"><input type="checkbox" name="active" value="1" :checked="v.active==1" @change="v.active=$event.target.checked?1:0"/> Aktiv</label></div>

          <div class="col-span-2 flex gap-3 mt-2">
            <button type="button" @click="editOpen=false" class="flex-1 px-4 py-2.5 border rounded-xl">Abbrechen</button>
            <button type="submit" class="flex-1 px-4 py-2.5 bg-brand text-white rounded-xl font-medium">Speichern</button>
          </div>
        </form>
      </div>
    </div>
  </template>
</div>

<?php if (!empty($recent)): ?>
<div class="bg-white rounded-xl border mt-6">
  <div class="p-5 border-b"><h3 class="font-semibold">Letzte Einlösungen</h3></div>
  <div class="overflow-x-auto"><table class="w-full text-sm"><thead class="bg-gray-50"><tr>
    <th class="px-4 py-3 text-left font-medium text-gray-600">Datum</th>
    <th class="px-4 py-3 text-left font-medium text-gray-600">Code</th>
    <th class="px-4 py-3 text-left font-medium text-gray-600">Kunde / Email</th>
    <th class="px-4 py-3 text-left font-medium text-gray-600">Buchung</th>
    <th class="px-4 py-3 text-left font-medium text-gray-600">Rabatt</th>
  </tr></thead><tbody class="divide-y">
    <?php foreach ($recent as $r): ?>
    <tr class="hover:bg-gray-50">
      <td class="px-4 py-3 text-xs text-gray-500"><?= date('d.m.y H:i', strtotime($r['redeemed_at'])) ?></td>
      <td class="px-4 py-3 font-mono text-brand"><?= e($r['code']) ?></td>
      <td class="px-4 py-3"><?= e($r['cname'] ?: $r['customer_email']) ?></td>
      <td class="px-4 py-3 text-xs text-gray-500"><?= $r['booking_id_fk'] ? '#'.$r['booking_id_fk'] : '—' ?> <?= $r['invoice_id_fk'] ? '/ Inv #'.$r['invoice_id_fk'] : '' ?></td>
      <td class="px-4 py-3 font-medium"><?= money($r['discount_amount']) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody></table></div>
</div>
<?php endif; ?>

<?php
$script = <<<JS
function filterRows(q){q=q.toLowerCase();document.querySelectorAll('#tbl tbody tr').forEach(r=>{r.style.display=r.textContent.toLowerCase().includes(q)?'':'none'})}
function voucherForm(){
  return {
    editOpen: false,
    v: {},
    ref: 58.29,
    vatRate(){ return ['host','b2b'].includes(this.v.customer_type) ? 0 : 0.19; },
    vatLabel(){ return this.vatRate() === 0 ? 'ohne MwSt' : '19% MwSt'; },
    nettoOf(brutto){ return +(brutto / (1 + this.vatRate())).toFixed(2); },
    setBrutto(v){ this.ref = +(Number(v)||0).toFixed(2); },
    setNetto(v){ this.ref = +((Number(v)||0) * (1 + this.vatRate())).toFixed(2); },
    money(n){ return (Number(n)||0).toFixed(2).replace('.',',') + ' €'; },
    discountBrutto(){
      const val = parseFloat(this.v.value)||0;
      const ref = Number(this.ref)||0;
      if (this.v.type === 'percent') return +(ref * val/100).toFixed(2);
      if (this.v.type === 'fixed')   return +Math.min(val, ref).toFixed(2);
      if (this.v.type === 'free')    return +ref.toFixed(2);
      if (this.v.type === 'target')  return Math.max(0, +(ref - val).toFixed(2));
      if (this.v.type === 'hourly_target') {
        // Referenz-Stundensatz default 2h (Pauschal-Basis)
        const hrs = 2;
        const newTotal = val * hrs;
        return Math.max(0, +(ref - newTotal).toFixed(2));
      }
      return 0;
    },
    discountNetto(){ return this.nettoOf(this.discountBrutto()); },
    finalBrutto(){ return Math.max(0, +((Number(this.ref)||0) - this.discountBrutto()).toFixed(2)); },
    finalNetto(){ return this.nettoOf(this.finalBrutto()); },
    wertHint(){
      const val = parseFloat(this.v.value)||0;
      const lbl = this.vatLabel();
      if (this.v.type === 'fixed') {
        return this.vatRate() === 0
          ? 'Netto = Brutto ('+ lbl +')'
          : '≈ ' + this.money(this.nettoOf(val)) + ' netto ('+ lbl +')';
      }
      if (this.v.type === 'percent') return val + '% → Rabatt '+ this.money(this.discountBrutto()) +' · netto '+ this.money(this.discountNetto()) +' ('+ lbl +')';
      if (this.v.type === 'free')    return 'Buchung zu 100% gratis';
      if (this.v.type === 'target')  return 'Kunde zahlt max. ' + this.money(val) + ' (Rabatt = Differenz)';
      if (this.v.type === 'hourly_target') return this.money(val) + '/h × Stunden · Rabatt = Normalpreis − neuer Satz';
      return '';
    },
    minHint(){
      const val = parseFloat(this.v.min_amount)||0;
      if (val <= 0) return 'Kein Minimum';
      return this.vatRate() === 0 ? 'Netto = Brutto' : '≈ ' + this.money(this.nettoOf(val)) + ' netto';
    }
  };
}
JS;
include __DIR__.'/../includes/footer.php'; ?>
