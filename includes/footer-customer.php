</main>

<!-- ============ FLOATING CONTACT BUTTONS ============ -->
<div class="fixed bottom-5 right-5 z-40 flex flex-col gap-3" x-data="{ open: false }">
  <!-- Expanded buttons -->
  <div x-show="open" x-cloak x-transition class="flex flex-col gap-2 items-end">
    <a href="<?= CONTACT_WHATSAPP_URL ?>" target="_blank" rel="noopener" class="flex items-center gap-2 bg-white shadow-lg border border-gray-200 hover:border-green-500 rounded-full pl-3 pr-4 py-2.5 group">
      <div class="w-8 h-8 rounded-full bg-green-500 text-white flex items-center justify-center flex-shrink-0">
        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413"/></svg>
      </div>
      <span class="text-sm font-semibold text-gray-700 group-hover:text-green-600">WhatsApp</span>
    </a>
    <a href="https://t.me/<?= str_replace('@', '', CONTACT_TELEGRAM) ?>" target="_blank" rel="noopener" class="flex items-center gap-2 bg-white shadow-lg border border-gray-200 hover:border-blue-500 rounded-full pl-3 pr-4 py-2.5 group">
      <div class="w-8 h-8 rounded-full bg-blue-500 text-white flex items-center justify-center flex-shrink-0">
        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg>
      </div>
      <span class="text-sm font-semibold text-gray-700 group-hover:text-blue-600">Telegram</span>
    </a>
    <a href="mailto:<?= CONTACT_EMAIL ?>" class="flex items-center gap-2 bg-white shadow-lg border border-gray-200 hover:border-brand rounded-full pl-3 pr-4 py-2.5 group">
      <div class="w-8 h-8 rounded-full bg-brand text-white flex items-center justify-center flex-shrink-0">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
      </div>
      <span class="text-sm font-semibold text-gray-700 group-hover:text-brand">E-Mail</span>
    </a>
    <?php if (CONTACT_PHONE): ?>
    <a href="tel:<?= CONTACT_PHONE ?>" class="flex items-center gap-2 bg-white shadow-lg border border-gray-200 hover:border-brand rounded-full pl-3 pr-4 py-2.5 group">
      <div class="w-8 h-8 rounded-full bg-brand text-white flex items-center justify-center flex-shrink-0">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
      </div>
      <span class="text-sm font-semibold text-gray-700 group-hover:text-brand">Anruf</span>
    </a>
    <?php endif; ?>
  </div>
  <!-- Toggle button -->
  <button @click="open = !open" :class="open ? 'rotate-45' : ''" class="w-14 h-14 rounded-full bg-brand hover:bg-brand-dark text-white shadow-xl flex items-center justify-center transition-transform self-end">
    <svg x-show="!open" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
    <svg x-show="open" x-cloak class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
  </button>
</div>

<footer class="mt-16 py-8 border-t bg-white">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 flex flex-col sm:flex-row justify-between items-center gap-4 text-xs text-gray-500">
    <div>© <?= date('Y') ?> <?= SITE ?> — Alle Rechte vorbehalten</div>
    <div class="flex gap-4">
      <a href="/customer/help.php" class="hover:text-gray-700">Hilfe</a>
      <a href="https://fleckfrei.de/agb" class="hover:text-gray-700">AGB</a>
      <a href="https://fleckfrei.de/datenschutz" class="hover:text-gray-700">Datenschutz</a>
      <span class="text-gray-400">·</span>
      <span class="text-gray-400">1 % unserer Einnahmen → <strong class="text-gray-600">Rumänien-Hilfe</strong> 🇷🇴</span>
    </div>
  </div>
</footer>

</body>
</html>
