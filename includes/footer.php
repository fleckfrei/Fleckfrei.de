    </div><!-- /p-6 -->
    <!-- Footer -->
    <footer class="border-t bg-gray-50 px-6 py-3">
      <div class="flex items-center justify-between text-xs text-gray-400">
        <span class="font-medium text-gray-500"><?= SITE ?></span>
        <div class="flex items-center gap-4">
          <?php if (FEATURE_WHATSAPP): ?><a href="https://wa.me/<?= CONTACT_WA ?>" target="_blank" class="hover:text-green-600 transition">WhatsApp</a><?php endif; ?>
          <a href="mailto:<?= CONTACT_EMAIL ?>" class="hover:text-brand transition"><?= CONTACT_EMAIL ?></a>
          <a href="https://<?= SITE_DOMAIN ?>" target="_blank" class="hover:text-brand transition"><?= SITE_DOMAIN ?></a>
        </div>
        <span>&copy; <?= date('Y') ?> <?= SITE ?></span>
      </div>
    </footer>
  </main>
</div><!-- /flex -->

<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<?php if (!empty($script)): ?><script><?= $script ?></script><?php endif; ?>
<script>
// Auto-cron ping (triggers iCal + Smoobu sync in background)
new Image().src='/api/cron.php?t='+Date.now();
// PWA Service Worker
if('serviceWorker' in navigator){
  navigator.serviceWorker.register('/sw.js').then(function(reg){
    // Check for updates every 30 min
    setInterval(function(){ reg.update(); }, 1800000);
  }).catch(function(){});
}
// iOS PWA: fix back navigation
if(window.navigator.standalone){
  document.addEventListener('click',function(e){
    var a=e.target.closest('a');
    if(a && a.href && a.href.indexOf(location.origin)===0 && !a.target){
      e.preventDefault(); location.href=a.href;
    }
  });
}
// Pull-to-refresh
(function(){
  var startY=0,pulling=false;
  var ind=document.createElement('div');
  ind.id='ptrIndicator';
  ind.innerHTML='<div style="background:white;border:1px solid #e5e7eb;border-radius:50%;width:36px;height:36px;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 8px rgba(0,0,0,0.1)"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2E7D6B" stroke-width="2"><path d="M1 4v6h6M23 20v-6h-6"/><path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 0 1 3.51 15"/></svg></div>';
  document.body.appendChild(ind);
  document.addEventListener('touchstart',function(e){
    if(window.scrollY===0){startY=e.touches[0].clientY;pulling=true;}
  },{passive:true});
  document.addEventListener('touchmove',function(e){
    if(!pulling)return;
    var dy=e.touches[0].clientY-startY;
    if(dy>60){ind.classList.add('active');}
  },{passive:true});
  document.addEventListener('touchend',function(){
    if(ind.classList.contains('active')){location.reload();}
    ind.classList.remove('active');pulling=false;
  });
})();
// Install prompt (Android)
var deferredPrompt;
window.addEventListener('beforeinstallprompt',function(e){
  e.preventDefault(); deferredPrompt=e;
  // Show install banner after 30s
  setTimeout(function(){
    if(!deferredPrompt)return;
    var b=document.createElement('div');
    b.style.cssText='position:fixed;bottom:16px;left:16px;right:16px;background:#2E7D6B;color:white;padding:14px 16px;border-radius:12px;display:flex;align-items:center;justify-content:space-between;z-index:9999;box-shadow:0 4px 12px rgba(0,0,0,0.15);font-size:14px;font-family:Inter,system-ui,sans-serif';
    b.innerHTML='<span><b>Fleckfrei</b> als App installieren</span><div><button onclick="this.parentNode.parentNode.remove()" style="background:none;border:none;color:rgba(255,255,255,0.7);cursor:pointer;padding:8px;font-size:13px">Nein</button><button id="pwaInstall" style="background:white;color:#2E7D6B;border:none;padding:8px 16px;border-radius:8px;font-weight:600;cursor:pointer;font-size:13px">Installieren</button></div>';
    document.body.appendChild(b);
    document.getElementById('pwaInstall').addEventListener('click',function(){
      deferredPrompt.prompt();
      deferredPrompt.userChoice.then(function(){deferredPrompt=null;b.remove();});
    });
  },30000);
});
</script>
</body>
</html>
