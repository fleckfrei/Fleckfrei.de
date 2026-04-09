<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Verfügbarkeit'; $page = 'availability';
include __DIR__ . '/../includes/layout.php';
$apiKey = API_KEY;
?>

<div x-data="availabilityApp()" x-init="init()">
  <!-- Header -->
  <div class="flex items-center justify-between mb-4">
    <div class="flex items-center gap-3">
      <button @click="prevMonth()" class="p-2 rounded-lg border hover:bg-gray-50">&larr;</button>
      <h2 class="text-lg font-bold" x-text="monthLabel"></h2>
      <button @click="nextMonth()" class="p-2 rounded-lg border hover:bg-gray-50">&rarr;</button>
      <button @click="goToday()" class="px-3 py-1.5 text-sm border rounded-lg hover:bg-gray-50">Heute</button>
    </div>
    <div class="flex items-center gap-3">
      <span class="text-xs text-gray-400" x-text="lastSync ? 'Sync: ' + lastSync : ''"></span>
      <button @click="syncSmoobu()" :disabled="syncing" class="px-4 py-2 bg-brand text-white rounded-xl text-sm font-semibold hover:opacity-90 transition">
        <span x-text="syncing ? 'Sync...' : 'Smoobu Sync'"></span>
      </button>
    </div>
  </div>

  <!-- Legend -->
  <div class="flex items-center gap-4 mb-4 text-xs text-gray-500">
    <span class="flex items-center gap-1"><span class="w-3 h-3 rounded bg-green-200 border border-green-300"></span> Frei</span>
    <span class="flex items-center gap-1"><span class="w-3 h-3 rounded bg-red-200 border border-red-300"></span> Belegt</span>
    <span class="flex items-center gap-1"><span class="w-3 h-3 rounded bg-yellow-200 border border-yellow-300"></span> Check-in/out</span>
    <span class="flex items-center gap-1"><span class="w-3 h-3 rounded bg-gray-200 border border-gray-300"></span> Blockiert</span>
  </div>

  <!-- Loading -->
  <div x-show="loading" class="bg-white rounded-xl border p-8 text-center text-gray-400">
    <div class="inline-block w-6 h-6 border-2 border-brand border-t-transparent rounded-full animate-spin mb-2"></div>
    <div>Lade Verfügbarkeit...</div>
  </div>

  <!-- No Smoobu -->
  <template x-if="!loading && !hasSmoobu">
    <div class="max-w-xl mx-auto mt-8 bg-white rounded-xl border p-8 text-center">
      <h2 class="text-xl font-bold mb-2">Smoobu nicht konfiguriert</h2>
      <p class="text-gray-500 mb-4">Die Availability Matrix benötigt eine Smoobu-Verbindung.</p>
      <a href="/admin/bookings.php" class="px-4 py-2 bg-brand text-white rounded-lg text-sm font-medium">Zu Buchungen</a>
    </div>
  </template>

  <!-- Matrix Grid -->
  <div x-show="!loading && hasSmoobu" class="bg-white rounded-xl border overflow-hidden">
    <!-- Day Headers -->
    <div class="overflow-x-auto">
      <table class="w-full text-xs border-collapse min-w-[900px]">
        <thead>
          <tr class="bg-gray-50">
            <th class="px-3 py-2 text-left font-semibold text-gray-700 sticky left-0 bg-gray-50 z-10 w-40 border-r">Property</th>
            <template x-for="day in days" :key="day.date">
              <th class="px-1 py-2 text-center font-medium min-w-[36px] border-r border-gray-100"
                  :class="{'bg-blue-50': day.isToday, 'text-red-500': day.isWeekend}">
                <div x-text="day.dow" class="text-[10px] text-gray-400"></div>
                <div x-text="day.num" class="font-bold"></div>
              </th>
            </template>
          </tr>
        </thead>
        <tbody>
          <template x-for="prop in properties" :key="prop.id">
            <tr class="border-t hover:bg-gray-50/50">
              <td class="px-3 py-2 font-medium text-gray-800 sticky left-0 bg-white z-10 border-r truncate max-w-[160px]" :title="prop.name">
                <div x-text="prop.name" class="truncate"></div>
                <div class="text-[10px] text-gray-400" x-text="prop.type || ''"></div>
              </td>
              <template x-for="day in days" :key="prop.id + '-' + day.date">
                <td class="px-0 py-0 border-r border-gray-100 relative group cursor-pointer"
                    :class="cellClass(prop.id, day.date)"
                    @click="cellClick(prop.id, day.date, $event)"
                    :title="cellTitle(prop.id, day.date)">
                  <div class="h-8 flex items-center justify-center">
                    <span x-text="cellPrice(prop.id, day.date)" class="text-[9px] font-mono opacity-70"></span>
                  </div>
                  <!-- Booking bar -->
                  <template x-if="isBookingStart(prop.id, day.date)">
                    <div class="absolute top-0.5 left-0 right-0 h-1.5 rounded-full"
                         :class="bookingBarColor(prop.id, day.date)"
                         :style="'width:' + bookingBarWidth(prop.id, day.date)"></div>
                  </template>
                </td>
              </template>
            </tr>
          </template>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Booking Detail Popup -->
  <div x-show="popup.show" x-transition @click.outside="popup.show=false"
       class="fixed z-50 bg-white rounded-xl border shadow-xl p-4 w-80"
       :style="'top:' + popup.y + 'px;left:' + popup.x + 'px'">
    <div class="flex items-center justify-between mb-3">
      <h4 class="font-bold text-sm" x-text="popup.title"></h4>
      <button @click="popup.show=false" class="text-gray-400 hover:text-gray-600">&times;</button>
    </div>
    <template x-if="popup.booking">
      <div class="space-y-2 text-sm">
        <div class="flex justify-between"><span class="text-gray-500">Gast</span><span class="font-medium" x-text="popup.booking.guest_name"></span></div>
        <div class="flex justify-between"><span class="text-gray-500">Kanal</span>
          <span class="px-2 py-0.5 rounded-full text-xs" :class="channelColor(popup.booking.channel)" x-text="popup.booking.channel"></span></div>
        <div class="flex justify-between"><span class="text-gray-500">Check-in</span><span x-text="fmtDate(popup.booking.check_in)"></span></div>
        <div class="flex justify-between"><span class="text-gray-500">Check-out</span><span x-text="fmtDate(popup.booking.check_out)"></span></div>
        <div class="flex justify-between"><span class="text-gray-500">Preis</span><span class="font-bold" x-text="(popup.booking.price||0) + ' €'"></span></div>
        <template x-if="popup.booking.guest_email">
          <div class="flex justify-between"><span class="text-gray-500">Email</span><span class="text-xs" x-text="popup.booking.guest_email"></span></div>
        </template>
        <div class="flex gap-2 mt-3">
          <template x-if="popup.booking.guest_phone">
            <a :href="'https://wa.me/' + popup.booking.guest_phone.replace(/[^0-9]/g,'')" target="_blank" class="px-3 py-1.5 bg-green-500 text-white rounded-lg text-xs">WhatsApp</a>
          </template>
          <template x-if="popup.booking.guest_email">
            <a :href="'/admin/scanner.php'" @click.prevent="scanGuest(popup.booking)" class="px-3 py-1.5 bg-brand text-white rounded-lg text-xs">OSINT Scan</a>
          </template>
        </div>
      </div>
    </template>
    <template x-if="!popup.booking">
      <div class="text-sm text-gray-500">
        <p class="mb-2" x-text="popup.date + ' — Frei'"></p>
        <p class="text-xs text-gray-400">Zum Blockieren oder Buchen verwende Smoobu.</p>
      </div>
    </template>
  </div>

  <!-- Stats -->
  <div x-show="!loading && hasSmoobu" class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-4">
    <div class="bg-white rounded-xl border p-4">
      <div class="text-xs text-gray-500">Properties</div>
      <div class="text-xl font-bold mt-1" x-text="properties.length"></div>
    </div>
    <div class="bg-white rounded-xl border p-4">
      <div class="text-xs text-gray-500">Buchungen (Monat)</div>
      <div class="text-xl font-bold mt-1" x-text="monthBookings"></div>
    </div>
    <div class="bg-white rounded-xl border p-4">
      <div class="text-xs text-gray-500">Auslastung</div>
      <div class="text-xl font-bold mt-1" x-text="occupancyPct + '%'"></div>
    </div>
    <div class="bg-white rounded-xl border p-4">
      <div class="text-xs text-gray-500">Umsatz (Monat)</div>
      <div class="text-xl font-bold mt-1" x-text="monthRevenue + ' €'"></div>
    </div>
  </div>
</div>

<?php
$script = <<<JS
function availabilityApp() {
  return {
    loading: true,
    hasSmoobu: true,
    syncing: false,
    lastSync: '',
    properties: [],
    bookings: [],
    days: [],
    currentMonth: new Date().getMonth(),
    currentYear: new Date().getFullYear(),
    monthLabel: '',
    monthBookings: 0,
    occupancyPct: 0,
    monthRevenue: 0,
    popup: { show: false, x: 0, y: 0, title: '', booking: null, date: '' },

    async init() {
      this.buildDays();
      try {
        const [aptRes, bookRes] = await Promise.all([
          fetch('/api/index.php?action=smoobu/apartments', { headers: {'X-API-Key': '$apiKey'} }).then(r=>r.json()),
          this.fetchBookings()
        ]);
        if (aptRes.success && aptRes.data) {
          const apts = aptRes.data.apartments || aptRes.data || [];
          this.properties = Array.isArray(apts) ? apts.map(a => ({
            id: a.id, name: a.name || a.title || 'Property ' + a.id, type: a.type || ''
          })) : [];
        }
      } catch(e) {
        this.hasSmoobu = false;
      }
      this.loading = false;
      this.calcStats();
    },

    async fetchBookings() {
      const start = this.currentYear + '-' + String(this.currentMonth+1).padStart(2,'0') + '-01';
      const endDate = new Date(this.currentYear, this.currentMonth+1, 0);
      const end = endDate.toISOString().slice(0,10);
      const res = await fetch('/api/index.php?action=channel/bookings&from=' + start + '&to=' + end, {
        headers: {'X-API-Key': '$apiKey'}
      }).then(r=>r.json());
      if (res.success) this.bookings = res.data || [];
      // Also get last sync
      const last = this.bookings.reduce((max, b) => {
        const s = b.synced_at || '';
        return s > max ? s : max;
      }, '');
      if (last) this.lastSync = last.substring(0,16).replace('T',' ');
      return res;
    },

    buildDays() {
      const days = [];
      const daysInMonth = new Date(this.currentYear, this.currentMonth+1, 0).getDate();
      const dow = ['So','Mo','Di','Mi','Do','Fr','Sa'];
      const months = ['Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];
      this.monthLabel = months[this.currentMonth] + ' ' + this.currentYear;
      const today = new Date().toISOString().slice(0,10);
      for (let i = 1; i <= daysInMonth; i++) {
        const d = new Date(this.currentYear, this.currentMonth, i);
        const dateStr = d.toISOString().slice(0,10);
        days.push({
          date: dateStr,
          num: i,
          dow: dow[d.getDay()],
          isToday: dateStr === today,
          isWeekend: d.getDay() === 0 || d.getDay() === 6
        });
      }
      this.days = days;
    },

    prevMonth() {
      this.currentMonth--;
      if (this.currentMonth < 0) { this.currentMonth = 11; this.currentYear--; }
      this.buildDays();
      this.loading = true;
      this.fetchBookings().then(() => { this.loading = false; this.calcStats(); });
    },

    nextMonth() {
      this.currentMonth++;
      if (this.currentMonth > 11) { this.currentMonth = 0; this.currentYear++; }
      this.buildDays();
      this.loading = true;
      this.fetchBookings().then(() => { this.loading = false; this.calcStats(); });
    },

    goToday() {
      const now = new Date();
      this.currentMonth = now.getMonth();
      this.currentYear = now.getFullYear();
      this.buildDays();
      this.loading = true;
      this.fetchBookings().then(() => { this.loading = false; this.calcStats(); });
    },

    getBooking(propId, date) {
      return this.bookings.find(b => {
        const pid = b.property_id || b.apartment_id || 0;
        return pid == propId && date >= b.check_in && date < b.check_out;
      });
    },

    cellClass(propId, date) {
      const b = this.getBooking(propId, date);
      if (!b) return 'bg-green-50 hover:bg-green-100';
      if (date === b.check_in) return 'bg-yellow-100 hover:bg-yellow-200';
      if (date === b.check_out) return 'bg-yellow-50 hover:bg-yellow-100';
      return 'bg-red-100 hover:bg-red-200';
    },

    cellTitle(propId, date) {
      const b = this.getBooking(propId, date);
      if (!b) return date + ' — Frei';
      return b.guest_name + ' (' + b.channel + ') ' + b.check_in + ' → ' + b.check_out;
    },

    cellPrice(propId, date) {
      const b = this.getBooking(propId, date);
      if (!b) return '';
      const nights = Math.max(1, Math.round((new Date(b.check_out) - new Date(b.check_in)) / 86400000));
      const perNight = Math.round((b.price || 0) / nights);
      return perNight > 0 ? perNight : '';
    },

    isBookingStart(propId, date) {
      return this.bookings.some(b => {
        const pid = b.property_id || b.apartment_id || 0;
        return pid == propId && b.check_in === date;
      });
    },

    bookingBarColor(propId, date) {
      const b = this.getBooking(propId, date);
      if (!b) return '';
      const ch = (b.channel || '').toLowerCase();
      if (ch.includes('airbnb')) return 'bg-red-500';
      if (ch.includes('booking')) return 'bg-blue-500';
      if (ch.includes('vrbo')) return 'bg-purple-500';
      return 'bg-gray-500';
    },

    bookingBarWidth(propId, date) {
      const b = this.getBooking(propId, date);
      if (!b) return '0';
      const nights = Math.max(1, Math.round((new Date(b.check_out) - new Date(b.check_in)) / 86400000));
      return (nights * 100) + '%';
    },

    cellClick(propId, date, event) {
      const b = this.getBooking(propId, date);
      const prop = this.properties.find(p => p.id == propId);
      const rect = event.target.getBoundingClientRect();
      this.popup = {
        show: true,
        x: Math.min(rect.left, window.innerWidth - 340),
        y: Math.min(rect.bottom + 5, window.innerHeight - 300),
        title: (prop?.name || 'Property') + ' — ' + this.fmtDate(date),
        booking: b || null,
        date: date
      };
    },

    scanGuest(b) {
      const form = document.createElement('form');
      form.method = 'POST'; form.action = '/admin/scanner.php';
      ['scan_email','scan_name','scan_phone'].forEach((n, i) => {
        const input = document.createElement('input');
        input.type = 'hidden'; input.name = n;
        input.value = [b.guest_email, b.guest_name, b.guest_phone][i] || '';
        form.appendChild(input);
      });
      document.body.appendChild(form);
      form.submit();
    },

    channelColor(ch) {
      const c = (ch || '').toLowerCase();
      if (c.includes('airbnb')) return 'bg-red-100 text-red-700';
      if (c.includes('booking')) return 'bg-blue-100 text-blue-700';
      if (c.includes('vrbo')) return 'bg-purple-100 text-purple-700';
      return 'bg-gray-100 text-gray-700';
    },

    fmtDate(d) {
      if (!d) return '—';
      const p = d.split('-');
      return p.length === 3 ? p[2] + '.' + p[1] + '.' + p[0] : d;
    },

    async syncSmoobu() {
      this.syncing = true;
      try {
        const res = await fetch('/api/index.php?action=smoobu/sync', {
          method: 'POST', headers: {'Content-Type':'application/json', 'X-API-Key':'$apiKey'}
        }).then(r=>r.json());
        if (res.success) {
          await this.fetchBookings();
          this.calcStats();
        }
      } catch(e) {}
      this.syncing = false;
    },

    calcStats() {
      this.monthBookings = this.bookings.length;
      this.monthRevenue = Math.round(this.bookings.reduce((s,b) => s + (parseFloat(b.price)||0), 0));
      // Occupancy: booked days / (properties * days in month)
      const totalSlots = this.properties.length * this.days.length;
      if (totalSlots === 0) { this.occupancyPct = 0; return; }
      let booked = 0;
      this.properties.forEach(p => {
        this.days.forEach(d => {
          if (this.getBooking(p.id, d.date)) booked++;
        });
      });
      this.occupancyPct = Math.round(booked / totalSlots * 100);
    }
  };
}
JS;
?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
