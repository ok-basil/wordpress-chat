(function(){
    const root = document.getElementById('wcchat-root');
    if (!root) return;

    const REST = WCCHAT.rest;
    const NONCE = WCCHAT.nonce;
    const isLogged = ! !WCCHAT.is_logged;
    const me = parseInt(WCCHAT.user_id || 0, 10);

    const sessionIdAttr = root.dataset.sessionId;
    const productId = parseInt(root.dataset.productId || 0, 10);

    const messages = document.getElementById('wcchat-messages');
    const typing = document.getElementById('wcchat-typing');
    const form = document.getElementById('wcchat-form');
    const text = document.getElementById('wcchat-text');
    const attachBtn = document.getElementById('wcchat-attach');
    const file = document.getElementById('wcchat-file');
    const presence = document.getElementById('wcchat-presence');

    let sessionId = parseInt(sessionIdAttr || 0, 10);
    let lastId = 0;
    let autoStickBottom = true;
    let pollTimer = null;
    let presenceTimer = null;
    let typingTimer = null;
    let otherIds = [];

    if (!isLogged) {
        messages.innerHTML += '<div class="wcchat-info">Please log in to use chat.</div>';
        return;
    }

    // Theme toggle
    const toggle = document.querySelector('.wcchat-theme-toggle');
    const storedTheme = localStorage.getItem('wcchat-theme');
    if (storedTheme === 'dark') document.documentElement.classList.add('wcchat-dark');

    if (toggle) {
        toggle.addEventListener('click', () => {
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
            },
            credentials: 'same-origin'
        }).then(async (response) => {
            const contentType = response.headers.get('content-type') || '';
            const parse = contentType.includes('application/json')
                ? response.json()
                : response.text().then(text => ({ message: text || response.statusText }));

            const payload = await parse;
            if (!response.ok) throw payload;
            return payload;
        });
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
        const linkText = m.attachment_name || 'Attachment';
        const att = m.attachment_id ? `<div class="wcchat-attachment"><a href="${m.attachment_url || '#'}" target="_blank" rel="noopener">${linkText}</a></div>` : '';
        const body = m.message ? `<div class="wcchat-bubble-text">${m.message}</div>` : '';
        const read = m.is_read ? '✓✓' : '✓';
        return `<div class="wcchat-row ${mime ? 'me' : 'them'}" data-id="${m.id}">
            <div class="wcchat-bubble">
                ${body}${att}
            <div class="wcchat-meta">${m.created_at || ''} ${mime ? `<span class="wcchat-ticks">${read}</span>` : ''}</div>
        </div>`
    }

    function isNearBottom() {
        const el = messages;
        if (!el) return false;
        const delta = el.scrollHeight - el.scrollTop - el.clientHeight;
        return delta < 24;
    }

    // update the flag whenever the user scrolls the pane
    messages.addEventListener('scroll', () => {
        autoStickBottom = isNearBottom();
    });

    function scrollToBottom() {
        const el = messages;
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
                messages.innerHTML += renderMessage(r);
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
    text.addEventListener('input', () => {
        clearTimeout(typingTimer);
        typingTimer = setTimeout(sendTyping, 120);
    });

    function checkTyping() {
        return api( 'GET', `typing?session_id=${sessionId}`).then(res => {
            if (res && Array.isArray(res.others_typing) && res.others_typing.length) {
                typing.textContent = 'Typing...';
                typing.hidden = false;
            } else {
                typing.hidden = true;
                typing.textContent = '';
            }
        });
    }

    function presencePing() {
        api('POST', 'presence', {}).catch(()=>{});
    }

    // Helper function to format file sizes
    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    if (text) {
        text.addEventListener('input', () => {
            clearTimeout(typingTimer);
            typingTimer = setTimeout(sendTyping, 100);
        });
    }

    if (attachBtn && file) {
        attachBtn.addEventListener('click', () => file.click());

        file.addEventListener('change', async function (e) {
            const selectedFile = e.target.files && e.target.files[0];
            if (!selectedFile) return;

            // Ensure a session exists
            if (!sessionId) {
                await ensureSession();
            }

            const maxSizeBytes = Number(WCCHAT?.max_file_size) || (5 * 1024 * 1024);
            if (selectedFile.size > maxSizeBytes) {
                alert(`File is too large. Please select a file less than ${formatFileSize(maxSizeBytes)}.`);
                file.value = '';
                return;
            }

            const fd = new FormData();
            fd.append('session_id', String(sessionId));
            fd.append('file', selectedFile);

            const originalText = attachBtn.textContent;
            attachBtn.textContent = 'Uploading...';
            attachBtn.disabled = true;

            try {
                const up = await api('POST', 'upload', fd, true);
                if (up && up.attachment_id) {
                    await api('POST', 'messages', { session_id: sessionId, attachment_id: up.attachment_id });
                    await fetchMessages();
                }
            } catch (err) {
                console.error('Upload failed:', err);
                alert('Upload failed: ' + (err?.message || 'Please try again.'));
            } finally {
                file.value = '';
                attachBtn.textContent = originalText;
                attachBtn.disabled = false;
            }
        });
    }


    if (form && text) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            const msg = text.value.trim();
            if (!msg) return;
            api('POST', 'messages', { session_id: sessionId, message: msg }).then(() => {
                text.value = '';
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
