(function(){
    const root = document.getElementById('wcchat-root');
    if (!root) return;

    const REST = WCCHAT.rest;
    const NONCE = WCCHAT.nonce;
    const isLogged = ! !WCCHAT.is_logged;
    const me = parseInt(WCCHAT.user_id || 0, 10);

    const sessionIdAttr = root.dataset.sessionId;
    const productId = parseInt(root.dataset.productId || 0, 10);

    const $messages = document.getElementById('wcchat-messages');
    const $typing = document.getElementById('wcchat-typing');
    const $form = document.getElementById('wcchat-form');
    const $text = document.getElementById('wcchat-text');
    const $attachBtn = document.getElementById('wcchat-attach');
    const $file = document.getElementById('wcchat-file');
    const $presence = document.getElementById('wcchat-presence');

    let sessionId = parseInt(sessionIdAttr || 0, 10);
    let lastId = 0;
    let autoStickBottom = true;
    let pollTimer = null;
    let presenceTimer = null;
    let typingTimer = null;
    let otherIds = [];

    if (!isLogged) {
        $messages.innerHTML += '<div class="wcchat-info">Please log in to use chat.</div>';
        return;
    }

    // Theme toggle
    const $toggle = document.querySelector('.wcchat-theme-toggle');
    const storedTheme = localStorage.getItem('wcchat-theme');
    if (storedTheme === 'dark') document.documentElement.classList.add('wcchat-dark');

    if ($toggle) {
        $toggle.addEventListener('click', () => {
            document.documentElement.classList.toggle('wcchat-dark');
            localStorage.setItem('wcchat-theme',
                document.documentElement.classList.contains('wcchat-dark') ? 'dark' : 'light');
            }
        );
    }

    function api(method, path, data, isMultipart) {
        return fetch(REST + path, {
            method: method,
            body: isMultipart ? data : (data ? JSON.stringify(data) : null),
            headers: {
                'X-WP-Nonce': NONCE,
                ...(isMultipart ? {} : { 'Content-Type': 'application/json' })
            }
        }).then(response => response.json());
    }

    function ensureSession() {
        if (sessionId) return Promise.resolve(sessionId);
        return api('POST', 'sessions', { product_id: productId }).then(response => {
            sessionId = parseInt(response.session_id, 10);
            return sessionId;
            }
        );
    }

    function fetchParticipants() {
        return api('GET', `participants?session_id=${sessionId}`).then(list => {
            if (!Array.isArray(list)) return;
            otherIds = list.map(p => parseInt(p.user_id, 10)).filter(id => id != me);
        });
    }

    function presenceLookup() {
        if (!otherIds.length) return Promise.resolve()

        const qs = otherIds.map(id => `user_ids[]=${encodeURIComponent(id)}`).join('&');
        return api('GET', `presence?${qs}`).then(map => {
            const anyOnline = Object.values(map || {}).some(Boolean);

            const presence = document.querySelector('#wcchat-presence');
            const label = presence.querySelector('.label');

            if (anyOnline) {
                presence.removeAttribute('hidden');
                presence.classList.add('online');
                label.textContent = 'Online';
            } else {
                presence.removeAttribute('hidden');
                presence.classList.remove('online');
                label.textContent = 'Offline';
            }
        });
    }

    function renderMessage(m) {
        const mime = parseInt(m.sender_id, 10) === me;
        const att = m.attachment_id ? `<div class="wcchat-attachment"><a href="${m.attachment_url || '#'}" target="_blank">Attachment</a></div>` : '';
        const body = m.message ? `<div class="wcchat-bubble-text">${m.message}</div>` : '';
        const read = m.is_read ? '✓✓' : '✓';
        return `<div class="wcchat-row ${mime ? 'me' : 'them'}" data-id="${m.id}">
            <div class="wcchat-bubble">
                ${body}${att}
            <div class="wcchat-meta">${m.created_at || ''} ${mime ? `<span class="wcchat-ticks">${read}</span>` : ''}</div>
        </div>`
    }

    function isNearBottom() {
        const el = $messages;
        if (!el) return false;
        const delta = el.scrollHeight - el.scrollTop - el.clientHeight;
        return delta < 24;
    }

    // update the flag whenever the user scrolls the pane
    $messages.addEventListener('scroll', () => {
        autoStickBottom = isNearBottom();
    });

    function scrollToBottom() {
        const el = $messages;
        if (el) el.scrollTop = el.scrollHeight;
    }

    function fetchMessages() {
        return api('GET', `messages?session_id=${sessionId}&after_id=${lastId}`).then(rows => {
            if (!Array.isArray(rows)) return;
            const wasNearBottom = autoStickBottom;
            rows.forEach(r => {
                if (r.attachment_id && !r.attachment_url) {
                    r.attachment_url = window.wp && wp.media ? wp.media.attachment(r.attachment_id)?.attributes?.url : null;
                }
                $messages.innerHTML += renderMessage(r);
                lastId = Math.max(lastId, parseInt(r.id, 10));
            });
            if (rows.length) {
                if (wasNearBottom) scrollToBottom();
                markRead();
            }
        })
    }

    function startPolling() {
        if (pollTimer) return;
        pollTimer = setInterval( () => {
            fetchMessages().catch(() => {});
            checkTyping().catch(() => {});
        }, 2000)
    }

    function markRead() {
        api('POST', 'messages/read', { session_id: sessionId }).catch(() => {});
    }

    let typingPostCooldown = null;

    function sendTyping() {
        // Ensure we're not triggering this on every keypress
        if (typingPostCooldown) return;
        typingPostCooldown = setTimeout(() => { typingPostCooldown = null;}, 1500);
        api('POST', 'typing', { session_id: sessionId }).catch(() => {});
    }

    // When the user types, we set the typing
    $text.addEventListener('input', () => {
        clearTimeout(typingTimer);
        typingTimer = setTimeout(sendTyping, 120);
    });
    function checkTyping() {
        return api( 'GET', `typing?session_id=${sessionId}`).then(res => {
            if (res && Array.isArray(res.others_typing) && res.others_typing.length) {
                $typing.textContent = 'Someone is typing...';
                $typing.hidden = false;
            } else {
                $typing.hidden = true;
                $typing.textContent = '';
            }
        });
    }

    function presencePing() {
        api('POST', 'presence', {}).catch(()=>{});
    }

    if ($text) {
        $text.addEventListener('input', () => {
            clearTimeout(typingTimer);
            typingTimer = setTimeout(sendTyping, 100);
        });
    }

    if ($attachBtn && $file) {
        $attachBtn.addEventListener('click', () => $file.click());
        $file.addEventListener('change', function() {
            if (!this.files || !this.files[0]) return;
            const fd = new FormData();
            fd.append('file', this.files[0]);
            api('POST', 'upload', fd, true).then(up => {
                if (up && up.attachment_id) {
                    // send the message with attachment only
                    api ('POST', 'messages', { session_id: sessionId, attachment_id: up.attachment_id }).then(fetchMessages);
                }
            }).finally(() => { $file.value = ''; });
        });
    }

    if ($form && $text) {
        $form.addEventListener('submit', function(e) {
            e.preventDefault();
            const msg = $text.value.trim();
            if (!msg) return;
            api('POST', 'messages', { session_id: sessionId, message: msg }).then(() => {
                $text.value = '';
                fetchMessages();
            });
        });
    }

    ensureSession().then(() => {
        // initial load
        fetchParticipants().then(() => presenceLookup());
        fetchMessages().then(scrollToBottom);
        startPolling();
        presencePing();
        presenceTimer = setInterval(() => { presencePing(); presenceLookup(); }, 20000);
    });
})();
