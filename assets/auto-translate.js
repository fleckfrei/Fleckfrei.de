/**
 * Auto-Translate DOM text — walks the page, batches uncached text-nodes,
 * sends to /api/translate-batch.php, replaces inline. Caches in localStorage.
 *
 * Skip rules:
 *   - <script>, <style>, <code>, <pre>, inputs
 *   - elements with [data-no-translate]
 *   - text < 2 chars OR pure numbers/dates
 *   - already translated (data-tr-done)
 */
(function () {
  if (window.__autoTranslateLoaded) return;
  window.__autoTranslateLoaded = true;

  const TARGET_LANG = window.__userLang || document.documentElement.lang || 'de';
  if (TARGET_LANG === 'de') return; // German is source — no translate needed

  const CACHE_KEY = 'flk_tr_v1_' + TARGET_LANG;
  const cache = JSON.parse(localStorage.getItem(CACHE_KEY) || '{}');
  const SKIP_TAGS = new Set(['SCRIPT','STYLE','CODE','PRE','INPUT','TEXTAREA','SELECT','BUTTON']);
  const SKIP_TEXT = /^[\d\s.,€$%\-+\/():#@\[\]]+$/;

  function shouldTranslate(node) {
    if (!node || node.nodeType !== 3) return false;
    const txt = node.nodeValue.trim();
    if (txt.length < 2) return false;
    if (SKIP_TEXT.test(txt)) return false;
    let p = node.parentNode;
    while (p && p !== document.body) {
      if (SKIP_TAGS.has(p.tagName)) return false;
      if (p.hasAttribute && p.hasAttribute('data-no-translate')) return false;
      if (p.hasAttribute && p.hasAttribute('data-tr-done')) return false;
      p = p.parentNode;
    }
    return true;
  }

  function collectNodes(root) {
    const out = [];
    const walker = document.createTreeWalker(root || document.body, NodeFilter.SHOW_TEXT, {
      acceptNode: (n) => shouldTranslate(n) ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_SKIP,
    });
    let n;
    while ((n = walker.nextNode())) out.push(n);
    return out;
  }

  function applyCached(node) {
    const key = node.nodeValue.trim();
    if (cache[key]) {
      node.nodeValue = node.nodeValue.replace(key, cache[key]);
      if (node.parentNode) node.parentNode.setAttribute('data-tr-done', '1');
      return true;
    }
    return false;
  }

  async function translateBatch(nodes) {
    const uniqueTexts = [];
    const idxMap = []; // node-index → text-index
    const textIdx = {};
    nodes.forEach(n => {
      const t = n.nodeValue.trim();
      if (textIdx[t] === undefined) {
        textIdx[t] = uniqueTexts.length;
        uniqueTexts.push(t);
      }
      idxMap.push(textIdx[t]);
    });
    if (!uniqueTexts.length) return;

    try {
      const r = await fetch('/api/translate-batch.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        credentials: 'same-origin',
        body: JSON.stringify({texts: uniqueTexts, lang: TARGET_LANG}),
      });
      const d = await r.json();
      if (!d.translations) return;
      uniqueTexts.forEach((src, i) => { cache[src] = d.translations[i] || src; });
      try { localStorage.setItem(CACHE_KEY, JSON.stringify(cache)); } catch (e) {}
      nodes.forEach((n, i) => {
        const tIdx = idxMap[i];
        const tr = d.translations[tIdx];
        if (tr && tr !== uniqueTexts[tIdx]) {
          n.nodeValue = n.nodeValue.replace(uniqueTexts[tIdx], tr);
          if (n.parentNode) n.parentNode.setAttribute('data-tr-done', '1');
        }
      });
    } catch (e) { console.warn('[auto-translate]', e); }
  }

  function run() {
    const all = collectNodes(document.body);
    const need = all.filter(n => !applyCached(n));
    if (!need.length) return;
    // Batch in chunks of 50
    for (let i = 0; i < need.length; i += 50) {
      translateBatch(need.slice(i, i + 50));
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run);
  } else {
    run();
  }
})();
