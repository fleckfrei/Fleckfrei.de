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
</body>
</html>
