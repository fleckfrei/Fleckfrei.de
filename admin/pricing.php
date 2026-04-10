<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Dynamic Pricing'; $page = 'pricing';
include __DIR__ . '/../includes/layout.php';
$apiKey = API_KEY;
?>

<div x-data="pricingApp()" x-init="load()">
  <!-- Stats -->
  <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl border p-4">
      <div class="text-xs text-gray-500">Aktive Regeln</div>
      <div class="text-2xl font-bold mt-1" x-text="rules.length"></div>
    </div>
    <div class="bg-white rounded-xl border p-4">
      <div class="text-xs text-gray-500">Preisanpassungen (90 Tage)</div>
      <div class="text-2xl font-bold mt-1" x-text="history.length"></div>
    </div>
    <div class="bg-white rounded-xl border p-4">
      <div class="text-xs text-gray-500">Akzeptanzrate</div>
      <div class="text-2xl font-bold mt-1" x-text="acceptRate + '%'"></div>
    </div>
    <div class="bg-white rounded-xl border p-4">
      <div class="text-xs text-gray-500">Avg Multiplikator</div>
      <div class="text-2xl font-bold mt-1" x-text="avgMult + 'x'"></div>
    </div>
  </div>

  <!-- Actions -->
  <div class="flex items-center justify-between mb-4">
    <h3 class="font-semibold">Preisregeln</h3>
    <div class="flex gap-2">
      <button @click="learn()" :disabled="learning" class="px-4 py-2 bg-purple-600 text-white rounded-xl text-sm font-semibold hover:opacity-90">
        <span x-text="learning ? 'Lerne...' : 'KI Optimieren'"></span>
      </button>
      <button @click="showAdd=!showAdd" class="px-4 py-2 bg-brand text-white rounded-xl text-sm font-semibold hover:opacity-90">+ Regel</button>
    </div>
  </div>

  <!-- Add Rule Form -->
  <div x-show="showAdd" x-transition class="bg-white rounded-xl border p-5 mb-4">
    <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
      <div><label class="block text-xs font-medium text-gray-500 mb-1">Typ</label>
        <select x-model="newRule.type" class="w-full px-3 py-2 border rounded-lg">
          <option value="season">Saison</option><option value="demand">Nachfrage</option>
          <option value="weekend">Wochenende</option><option value="customer">Kundentyp</option>
          <option value="time_of_day">Tageszeit</option><option value="occupancy">Auslastung</option>
        </select></div>
      <div><label class="block text-xs font-medium text-gray-500 mb-1">Bedingung</label>
        <input type="text" x-model="newRule.condition" placeholder="z.B. Sommer, >80%" class="w-full px-3 py-2 border rounded-lg"/></div>
      <div><label class="block text-xs font-medium text-gray-500 mb-1">Multiplikator</label>
        <input type="number" x-model="newRule.multiplier" step="0.05" min="0.5" max="3" class="w-full px-3 py-2 border rounded-lg"/></div>
      <div class="flex items-end">
        <button @click="addRule()" class="w-full px-4 py-2 bg-brand text-white rounded-lg font-medium">Speichern</button>
      </div>
    </div>
  </div>

  <!-- Learn Result -->
  <div x-show="learnResult" x-transition class="bg-purple-50 border border-purple-200 rounded-xl p-4 mb-4 text-sm" x-html="learnResult"></div>

  <!-- Rules Table -->
  <div class="bg-white rounded-xl border mb-4 overflow-hidden">
    <table class="w-full text-sm">
      <thead class="bg-gray-50"><tr>
        <th class="px-4 py-3 text-left">Typ</th><th class="px-4 py-3 text-left">Bedingung</th>
        <th class="px-4 py-3 text-right">Multiplikator</th><th class="px-4 py-3 text-right">Anwendungen</th>
        <th class="px-4 py-3">Status</th>
      </tr></thead>
      <tbody class="divide-y">
        <template x-for="r in rules" :key="r.pr_id">
          <tr class="hover:bg-gray-50">
            <td class="px-4 py-3"><span class="px-2 py-0.5 bg-brand/10 text-brand rounded text-xs font-medium" x-text="r.rule_type"></span></td>
            <td class="px-4 py-3" x-text="r.condition || r.description || '—'"></td>
            <td class="px-4 py-3 text-right font-bold" :class="r.multiplier > 1 ? 'text-red-600' : 'text-green-600'" x-text="r.multiplier + 'x'"></td>
            <td class="px-4 py-3 text-right" x-text="r.applied_count || 0"></td>
            <td class="px-4 py-3 text-center"><span class="px-2 py-0.5 rounded text-xs" :class="r.active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'" x-text="r.active ? 'Aktiv' : 'Inaktiv'"></span></td>
          </tr>
        </template>
      </tbody>
    </table>
  </div>

  <!-- Price Calculator -->
  <div class="bg-white rounded-xl border p-5">
    <h3 class="font-semibold mb-3">Preis-Rechner</h3>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
      <div><label class="block text-xs text-gray-500 mb-1">Basispreis</label>
        <input type="number" x-model="calc.base" class="w-full px-3 py-2 border rounded-lg"/></div>
      <div><label class="block text-xs text-gray-500 mb-1">Datum</label>
        <input type="date" x-model="calc.date" class="w-full px-3 py-2 border rounded-lg"/></div>
      <div><label class="block text-xs text-gray-500 mb-1">Kundentyp</label>
        <select x-model="calc.customer_type" class="w-full px-3 py-2 border rounded-lg">
          <option value="private">Privat</option><option value="commercial">Gewerbe</option><option value="hausverwaltung">Hausverwaltung</option>
        </select></div>
      <div class="flex items-end"><button @click="calculate()" class="w-full px-4 py-2 bg-gray-800 text-white rounded-lg font-medium">Berechnen</button></div>
    </div>
    <div x-show="calcResult" class="bg-gray-50 rounded-lg p-4">
      <div class="flex items-baseline gap-2">
        <span class="text-gray-500">Endpreis:</span>
        <span class="text-3xl font-bold text-brand" x-text="calcResult?.final_price?.toFixed(2) + ' EUR'"></span>
        <span class="text-sm text-gray-400" x-text="'(' + (calcResult?.multiplier || 1) + 'x)'"></span>
      </div>
      <div class="text-xs text-gray-400 mt-1" x-text="'Regeln: ' + (calcResult?.rules_applied || 'keine')"></div>
    </div>
  </div>
</div>

<?php
$script = <<<JS
function pricingApp() {
  return {
    rules: [], history: [], acceptRate: 0, avgMult: '1.00',
    showAdd: false, learning: false, learnResult: '',
    newRule: { type: 'season', condition: '', multiplier: 1.2 },
    calc: { base: 50, date: new Date().toISOString().slice(0,10), customer_type: 'private' },
    calcResult: null,

    load() {
      fetch('/api/pricing.php?action=rules', { headers: {'X-API-Key':'$apiKey'} })
        .then(r=>r.json()).then(d=>{ if(d.success) this.rules = d.rules || []; });
      fetch('/api/pricing.php?action=history&limit=100', { headers: {'X-API-Key':'$apiKey'} })
        .then(r=>r.json()).then(d=>{
          if(d.success) {
            this.history = d.history || [];
            var accepted = this.history.filter(h=>h.accepted==1).length;
            this.acceptRate = this.history.length ? Math.round(accepted/this.history.length*100) : 0;
            var mults = this.history.map(h=>parseFloat(h.multiplier)).filter(m=>m>0);
            this.avgMult = mults.length ? (mults.reduce((a,b)=>a+b,0)/mults.length).toFixed(2) : '1.00';
          }
        });
    },

    addRule() {
      fetch('/api/pricing.php', {
        method: 'POST', headers: {'Content-Type':'application/json','X-API-Key':'$apiKey'},
        body: JSON.stringify({action:'add_rule', ...this.newRule})
      }).then(r=>r.json()).then(d=>{ if(d.success) { this.load(); this.showAdd=false; } });
    },

    learn() {
      this.learning = true;
      fetch('/api/pricing.php?action=learn', { headers: {'X-API-Key':'$apiKey'} })
        .then(r=>r.json()).then(d=>{
          this.learning = false;
          if(d.success && d.insights) {
            this.learnResult = '<b>KI-Optimierung:</b><br>' + (d.insights || []).map(i=>'- ' + i).join('<br>');
          }
          this.load();
        });
    },

    calculate() {
      fetch('/api/pricing.php', {
        method: 'POST', headers: {'Content-Type':'application/json','X-API-Key':'$apiKey'},
        body: JSON.stringify({action:'calculate', base_price: this.calc.base, job_date: this.calc.date, customer_type: this.calc.customer_type})
      }).then(r=>r.json()).then(d=>{ this.calcResult = d.success ? d : null; });
    }
  };
}
JS;
?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
