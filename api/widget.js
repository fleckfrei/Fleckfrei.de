/**
 * Fleckfrei Chat Widget — Embeddable on any website
 * Usage: <script src="https://app.fleckfrei.de/api/widget.js"></script>
 */
(function() {
    const API = 'https://app.fleckfrei.de/api/chat-widget.php';
    const BRAND = '#2E7D6B';
    let isOpen = false;
    let chatId = null;
    let identified = false;

    // Create widget HTML
    const container = document.createElement('div');
    container.id = 'fleckfrei-chat';
    container.innerHTML = `
    <style>
    #ff-btn { position:fixed; bottom:24px; right:24px; width:60px; height:60px; border-radius:50%; background:${BRAND}; color:white; border:none; cursor:pointer; box-shadow:0 4px 12px rgba(0,0,0,0.15); z-index:9999; transition:transform 0.2s; display:flex; align-items:center; justify-content:center; }
    #ff-btn:hover { transform:scale(1.1); }
    #ff-btn svg { width:28px; height:28px; }
    #ff-badge { position:absolute; top:-2px; right:-2px; width:18px; height:18px; border-radius:50%; background:#ef4444; color:white; font-size:10px; display:none; align-items:center; justify-content:center; font-weight:700; }
    #ff-box { position:fixed; bottom:96px; right:24px; width:380px; max-height:520px; background:white; border-radius:16px; box-shadow:0 8px 30px rgba(0,0,0,0.12); z-index:9999; display:none; flex-direction:column; overflow:hidden; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; }
    #ff-header { background:${BRAND}; color:white; padding:16px 20px; display:flex; align-items:center; justify-content:space-between; }
    #ff-header h3 { font-size:16px; font-weight:600; margin:0; }
    #ff-header span { font-size:12px; opacity:0.8; }
    #ff-close { background:none; border:none; color:white; font-size:20px; cursor:pointer; padding:0 4px; }
    #ff-msgs { flex:1; overflow-y:auto; padding:16px; background:#f0f2f5; min-height:200px; max-height:320px; }
    .ff-msg { margin-bottom:8px; display:flex; }
    .ff-msg-me { justify-content:flex-end; }
    .ff-msg-team { justify-content:flex-start; }
    .ff-bubble { max-width:80%; padding:8px 12px; border-radius:12px; font-size:14px; line-height:1.4; }
    .ff-bubble-me { background:#d9fdd3; border-bottom-right-radius:4px; }
    .ff-bubble-team { background:white; border-bottom-left-radius:4px; box-shadow:0 1px 1px rgba(0,0,0,0.06); }
    .ff-time { font-size:10px; color:#999; margin-top:2px; text-align:right; }
    #ff-input-area { padding:12px; border-top:1px solid #e5e7eb; display:flex; gap:8px; }
    #ff-input { flex:1; padding:10px 14px; border:1px solid #e5e7eb; border-radius:24px; font-size:14px; outline:none; }
    #ff-input:focus { border-color:${BRAND}; }
    #ff-send { width:40px; height:40px; border-radius:50%; background:${BRAND}; border:none; color:white; cursor:pointer; display:flex; align-items:center; justify-content:center; }
    #ff-identify { padding:16px; background:white; }
    #ff-identify input { width:100%; padding:10px 14px; border:1px solid #e5e7eb; border-radius:10px; font-size:14px; margin-bottom:8px; outline:none; box-sizing:border-box; }
    #ff-identify button { width:100%; padding:10px; background:${BRAND}; color:white; border:none; border-radius:10px; font-size:14px; font-weight:600; cursor:pointer; }
    @media(max-width:480px) { #ff-box { right:0; left:0; bottom:0; width:100%; max-height:100vh; border-radius:16px 16px 0 0; } }
    </style>
    <button id="ff-btn" onclick="ffToggle()">
        <svg fill="currentColor" viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H5.17L4 17.17V4h16v12z"/><path d="M7 9h2v2H7zm4 0h2v2h-2zm4 0h2v2h-2z"/></svg>
        <span id="ff-badge">0</span>
    </button>
    <div id="ff-box">
        <div id="ff-header">
            <div><h3>Fleckfrei</h3><span>Online — Antwort in Minuten</span></div>
            <button id="ff-close" onclick="ffToggle()">&times;</button>
        </div>
        <div id="ff-identify" style="padding:20px">
            <div style="text-align:center;margin-bottom:16px">
                <div style="width:48px;height:48px;border-radius:50%;background:${BRAND};color:white;display:inline-flex;align-items:center;justify-content:center;font-size:20px;font-weight:700;margin-bottom:8px">F</div>
                <p style="font-size:15px;color:#1f2937;margin:0;font-weight:600">Willkommen bei Fleckfrei</p>
                <p style="font-size:12px;color:#9ca3af;margin:4px 0 0">Wir antworten in wenigen Minuten</p>
            </div>
            <div style="display:flex;flex-direction:column;gap:6px">
                <input type="text" id="ff-name" placeholder="Name *" style="padding:10px 14px;border:1px solid #e5e7eb;border-radius:10px;font-size:13px;outline:none;width:100%;box-sizing:border-box" />
                <div style="display:flex;gap:6px">
                    <input type="email" id="ff-email" placeholder="E-Mail *" style="flex:1;padding:10px 14px;border:1px solid #e5e7eb;border-radius:10px;font-size:13px;outline:none;min-width:0" />
                    <input type="tel" id="ff-phone" placeholder="Telefon *" style="flex:1;padding:10px 14px;border:1px solid #e5e7eb;border-radius:10px;font-size:13px;outline:none;min-width:0" />
                </div>
            </div>
            <div style="margin:10px 0;display:flex;flex-direction:column;gap:4px">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:11px;color:#6b7280">
                    <input type="checkbox" id="ff-gdpr" style="accent-color:${BRAND};width:14px;height:14px" />
                    <a href="https://fleckfrei.de/datenschutz.html" target="_blank" style="color:${BRAND}">Datenschutzerklärung</a> akzeptiert *
                </label>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:11px;color:#6b7280">
                    <input type="checkbox" id="ff-marketing" style="accent-color:${BRAND};width:14px;height:14px" checked />
                    E-Mail, WhatsApp & Telegram Kontakt erlaubt
                </label>
            </div>
            <button onclick="ffIdentify()" style="width:100%;padding:11px;background:${BRAND};color:white;border:none;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer">Chat starten</button>
            <p id="ff-error" style="color:#ef4444;font-size:11px;margin:6px 0 0;display:none;text-align:center"></p>
        </div>
        <div id="ff-msgs" style="display:none"></div>
        <div id="ff-input-area" style="display:none">
            <input type="text" id="ff-input" placeholder="Nachricht..." onkeydown="if(event.key==='Enter')ffSend()" />
            <button id="ff-send" onclick="ffSend()">
                <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
            </button>
        </div>
    </div>`;
    document.body.appendChild(container);

    // Init session
    fetch(API + '?action=init', { credentials: 'include' })
        .then(r => r.json())
        .then(d => { if (d.success) { chatId = d.data.chat_id; if (d.data.name) { identified = true; showChat(); } } });

    window.ffToggle = function() {
        isOpen = !isOpen;
        document.getElementById('ff-box').style.display = isOpen ? 'flex' : 'none';
        if (isOpen && identified) loadMessages();
    };

    window.ffIdentify = function() {
        const name = document.getElementById('ff-name').value.trim();
        const email = document.getElementById('ff-email').value.trim();
        const phone = document.getElementById('ff-phone').value.trim();
        const gdpr = document.getElementById('ff-gdpr').checked;
        const errEl = document.getElementById('ff-error');

        // Validation
        if (!name) { errEl.textContent = 'Bitte geben Sie Ihren Namen ein.'; errEl.style.display = 'block'; return; }
        if (!email || !email.includes('@')) { errEl.textContent = 'Bitte geben Sie eine gültige E-Mail ein.'; errEl.style.display = 'block'; return; }
        if (!phone || phone.length < 6) { errEl.textContent = 'Bitte geben Sie Ihre Telefonnummer ein.'; errEl.style.display = 'block'; return; }
        if (!gdpr) { errEl.textContent = 'Bitte stimmen Sie der Datenschutzerklärung zu.'; errEl.style.display = 'block'; return; }
        errEl.style.display = 'none';

        fetch(API + '?action=identify', {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                name, email, phone,
                marketing: document.getElementById('ff-marketing').checked,
                newsletter: document.getElementById('ff-marketing').checked,
                whatsapp: document.getElementById('ff-marketing').checked,
                telegram: document.getElementById('ff-marketing').checked,
                gdpr_consent: true,
                consent_time: new Date().toISOString()
            })
        }).then(r => r.json()).then(d => {
            if (d.success) { identified = true; showChat(); }
        });
    };

    function showChat() {
        document.getElementById('ff-identify').style.display = 'none';
        document.getElementById('ff-msgs').style.display = 'block';
        document.getElementById('ff-input-area').style.display = 'flex';
        loadMessages();
    }

    function loadMessages() {
        fetch(API + '?action=messages', { credentials: 'include' })
            .then(r => r.json())
            .then(d => {
                if (!d.success) return;
                const msgs = d.data;
                const container = document.getElementById('ff-msgs');
                if (msgs.length === 0) {
                    container.innerHTML = '<div style="text-align:center;color:#9ca3af;font-size:13px;margin-top:40px">Schreiben Sie uns! Wir antworten in wenigen Minuten.</div>';
                    return;
                }
                container.innerHTML = msgs.map(m =>
                    '<div class="ff-msg ff-msg-' + m.from + '">' +
                    '<div class="ff-bubble ff-bubble-' + m.from + '">' +
                    m.message.replace(/\n/g, '<br>') +
                    '<div class="ff-time">' + m.time + '</div>' +
                    '</div></div>'
                ).join('');
                container.scrollTop = container.scrollHeight;
            });
    }

    window.ffSend = function() {
        const input = document.getElementById('ff-input');
        const msg = input.value.trim();
        if (!msg) return;
        input.value = '';

        // Optimistic UI
        const msgs = document.getElementById('ff-msgs');
        msgs.innerHTML += '<div class="ff-msg ff-msg-me"><div class="ff-bubble ff-bubble-me">' + msg + '<div class="ff-time">jetzt</div></div></div>';
        msgs.scrollTop = msgs.scrollHeight;

        fetch(API + '?action=send', {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message: msg })
        }).then(r => r.json());
    };

    // Poll for new messages every 5s when open
    setInterval(() => { if (isOpen && identified) loadMessages(); }, 5000);
})();
