// js/main.js - BoardingFinder Main Scripts

document.addEventListener('DOMContentLoaded', function () {

    // ── Mobile Nav Toggle ──
    const navToggle = document.getElementById('navToggle');
    const navLinks = document.getElementById('navLinks');
    if (navToggle && navLinks) {
        navToggle.addEventListener('click', () => {
            navLinks.classList.toggle('open');
        });
        document.addEventListener('click', (e) => {
            if (!navToggle.contains(e.target) && !navLinks.contains(e.target)) {
                navLinks.classList.remove('open');
            }
        });
    }

    // ── User Dropdown ──
    const userBtn = document.getElementById('userBtn');
    const userDropdown = document.getElementById('userDropdown');
    if (userBtn && userDropdown) {
        userBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            userDropdown.classList.toggle('open');
        });
        document.addEventListener('click', () => userDropdown.classList.remove('open'));
    }

    // ── Custom Select (styled dropdown) ──
    (function initCustomSelects() {
        const isTouchLike =
            window.matchMedia?.('(pointer: coarse)')?.matches ||
            ('ontouchstart' in window) ||
            (navigator.maxTouchPoints || 0) > 0;

        if (isTouchLike) return; // keep native selects on touch devices

        const openSet = new Set();

        function close(cs) {
            if (!cs) return;
            cs.classList.remove('open');
            const btn = cs.querySelector('.cs-btn');
            if (btn) btn.setAttribute('aria-expanded', 'false');
            openSet.delete(cs);
        }

        function open(cs) {
            if (!cs) return;
            Array.from(openSet).forEach(other => { if (other !== cs) close(other); });
            cs.classList.add('open');
            const btn = cs.querySelector('.cs-btn');
            if (btn) btn.setAttribute('aria-expanded', 'true');
            openSet.add(cs);
        }

        function toggle(cs) {
            if (!cs) return;
            cs.classList.contains('open') ? close(cs) : open(cs);
        }

        function getEnabledOptions(menu) {
            return Array.from(menu.querySelectorAll('.cs-opt')).filter(b => !b.disabled);
        }

        function focusSelected(menu, preferLast = false) {
            const opts = getEnabledOptions(menu);
            if (!opts.length) return;
            const selected = menu.querySelector('.cs-opt[aria-selected=\"true\"]:not(:disabled)');
            (selected || (preferLast ? opts[opts.length - 1] : opts[0])).focus();
        }

        function moveFocus(menu, delta) {
            const opts = getEnabledOptions(menu);
            if (!opts.length) return;
            const active = document.activeElement;
            const idx = Math.max(0, opts.indexOf(active));
            const next = opts[(idx + delta + opts.length) % opts.length];
            next?.focus();
        }

        function enhanceSelect(select) {
            if (!select || select.closest('.cs')) return;
            if (select.multiple || (select.size && select.size > 1)) return;
            if (select.dataset.noCustomSelect !== undefined) return;

            const cs = document.createElement('div');
            cs.className = 'cs';
            const inlineStyle = select.getAttribute('style');
            if (inlineStyle) cs.setAttribute('style', inlineStyle);

            select.parentNode?.insertBefore(cs, select);
            cs.appendChild(select);

            select.classList.add('cs-native');
            select.tabIndex = -1;

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'cs-btn';
            btn.setAttribute('aria-haspopup', 'listbox');
            btn.setAttribute('aria-expanded', 'false');
            if (select.disabled) btn.disabled = true;

            const value = document.createElement('span');
            value.className = 'cs-value';

            const caret = document.createElement('span');
            caret.className = 'cs-caret';
            caret.setAttribute('aria-hidden', 'true');

            btn.appendChild(value);
            btn.appendChild(caret);

            const menu = document.createElement('div');
            menu.className = 'cs-menu';
            menu.setAttribute('role', 'listbox');

            const menuId = `cs-${Math.random().toString(36).slice(2)}`;
            menu.id = menuId;
            btn.setAttribute('aria-controls', menuId);

            function addOption(opt) {
                const o = document.createElement('button');
                o.type = 'button';
                o.className = 'cs-opt';
                o.textContent = opt.textContent || '';
                o.dataset.value = opt.value;
                o.disabled = !!opt.disabled;
                o.setAttribute('role', 'option');
                o.setAttribute('aria-selected', opt.selected ? 'true' : 'false');
                menu.appendChild(o);
            }

            function buildMenu() {
                menu.innerHTML = '';
                const children = Array.from(select.children);
                children.forEach(child => {
                    if (child.tagName === 'OPTGROUP') {
                        const g = document.createElement('div');
                        g.className = 'cs-group';
                        g.textContent = child.label || '';
                        menu.appendChild(g);
                        Array.from(child.children).forEach(opt => addOption(opt));
                        return;
                    }
                    if (child.tagName === 'OPTION') addOption(child);
                });
            }

            function syncFromSelect() {
                const opt = select.selectedOptions?.[0] || select.options?.[select.selectedIndex];
                const text = opt ? (opt.textContent || '') : '';
                value.textContent = text;

                const isPlaceholder = !!opt && (opt.disabled || opt.value === '');
                btn.classList.toggle('is-placeholder', isPlaceholder);

                menu.querySelectorAll('.cs-opt').forEach(o => {
                    o.setAttribute('aria-selected', o.dataset.value === select.value ? 'true' : 'false');
                });

                if (select.disabled) btn.disabled = true;
                cs.classList.remove('error');
                select.classList.remove('error');
            }

            buildMenu();
            cs.appendChild(btn);
            cs.appendChild(menu);
            syncFromSelect();

            select.addEventListener('change', syncFromSelect);

            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                toggle(cs);
                if (cs.classList.contains('open')) focusSelected(menu);
            });

            btn.addEventListener('keydown', (e) => {
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    open(cs);
                    focusSelected(menu);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    open(cs);
                    focusSelected(menu, true);
                } else if (e.key === 'Escape') {
                    close(cs);
                }
            });

            menu.addEventListener('click', (e) => {
                const optBtn = e.target.closest('.cs-opt');
                if (!optBtn || optBtn.disabled) return;
                select.value = optBtn.dataset.value;
                select.dispatchEvent(new Event('change', { bubbles: true }));
                close(cs);
                btn.focus();
            });

            menu.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    close(cs);
                    btn.focus();
                } else if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    moveFocus(menu, 1);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    moveFocus(menu, -1);
                } else if (e.key === 'Enter' || e.key === ' ') {
                    const active = document.activeElement;
                    if (active?.classList?.contains('cs-opt')) {
                        e.preventDefault();
                        active.click();
                    }
                }
            });
        }

        document.querySelectorAll('select.form-control, .search-field select').forEach(enhanceSelect);

        document.addEventListener('click', (e) => {
            Array.from(openSet).forEach(cs => {
                if (!cs.contains(e.target)) close(cs);
            });
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') Array.from(openSet).forEach(close);
        });
    })();

    // ── Auto-close Flash ──
    document.querySelectorAll('.flash').forEach(flash => {
        setTimeout(() => {
            flash.closest('.flash-container')?.remove();
        }, 5000);
    });

    // ── Role Selector ──
    document.querySelectorAll('.role-option').forEach(opt => {
        opt.addEventListener('click', function () {
            document.querySelectorAll('.role-option').forEach(o => o.classList.remove('selected'));
            this.classList.add('selected');
            const radio = this.querySelector('input[type="radio"]');
            if (radio) radio.checked = true;
        });
    });

    // ── Password Toggle ──
    document.querySelectorAll('[data-toggle-password]').forEach(btn => {
        btn.addEventListener('click', function () {
            const input = document.querySelector(this.dataset.togglePassword);
            if (input) {
                input.type = input.type === 'password' ? 'text' : 'password';
                const icon = this.querySelector('i');
                const shown = input.type === 'text';
                if (icon) icon.className = shown ? 'fas fa-eye-slash' : 'fas fa-eye';
                this.setAttribute('aria-pressed', String(shown));
                this.setAttribute('aria-label', shown ? 'Hide password' : 'Show password');
                this.setAttribute('title', shown ? 'Hide password' : 'Show password');
            }
        });
    });

    // ── File Upload Preview ──
    document.querySelectorAll('.file-upload').forEach(zone => {
        const input = zone.querySelector('input[type="file"]');
        const previewContainer = zone.nextElementSibling;

        zone.addEventListener('click', () => input?.click());

        zone.addEventListener('dragover', (e) => {
            e.preventDefault();
            zone.classList.add('dragover');
        });

        zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));

        zone.addEventListener('drop', (e) => {
            e.preventDefault();
            zone.classList.remove('dragover');
            if (input) {
                input.files = e.dataTransfer.files;
                input.dispatchEvent(new Event('change'));
            }
        });

        input?.addEventListener('change', function () {
            if (!previewContainer || !previewContainer.classList.contains('file-preview')) return;
            previewContainer.innerHTML = '';
            Array.from(this.files).forEach(file => {
                if (!file.type.startsWith('image/')) return;
                const reader = new FileReader();
                reader.onload = (e) => {
                    const div = document.createElement('div');
                    div.className = 'preview-item';
                    div.innerHTML = `<img src="${e.target.result}" alt="${file.name}">
                        <button type="button" class="preview-remove" onclick="this.parentElement.remove()">×</button>
                        <span class="preview-name">${file.name}</span>`;
                    previewContainer.appendChild(div);
                };
                reader.readAsDataURL(file);
            });
        });
    });

    // ── Gallery Thumbnails ──
    document.querySelectorAll('.gallery-thumb').forEach(thumb => {
        thumb.addEventListener('click', function () {
            const mainImg = document.querySelector('.gallery-main img');
            const src = this.dataset.src || this.querySelector('img')?.src;
            if (mainImg && src) mainImg.src = src;
        });
    });

    // ── Confirm Actions ──
    document.querySelectorAll('[data-confirm]').forEach(el => {
        el.addEventListener('click', function (e) {
            if (!confirm(this.dataset.confirm || 'Are you sure?')) {
                e.preventDefault();
            }
        });
    });

    // ── Price Range Inputs ──
    const minPrice = document.getElementById('minPrice');
    const maxPrice = document.getElementById('maxPrice');
    if (minPrice && maxPrice) {
        minPrice.addEventListener('input', function () {
            if (parseFloat(this.value) > parseFloat(maxPrice.value) && maxPrice.value !== '') {
                maxPrice.value = this.value;
            }
        });
    }

    // ── Character Count ──
    document.querySelectorAll('[data-maxlength]').forEach(el => {
        const max = parseInt(el.dataset.maxlength);
        const counter = document.querySelector(`[data-counter="${el.id}"]`);
        if (counter) {
            el.addEventListener('input', function () {
                const remaining = max - this.value.length;
                counter.textContent = `${this.value.length}/${max}`;
                counter.style.color = remaining < 20 ? 'var(--error)' : 'var(--text-muted)';
            });
        }
    });

    // ── Form validation helpers ──
    document.querySelectorAll('form[data-validate]').forEach(form => {
        form.addEventListener('submit', function (e) {
            let valid = true;
            this.querySelectorAll('[required]').forEach(field => {
                if (!field.value.trim()) {
                    field.classList.add('error');
                    if (field.matches('select.cs-native')) field.closest('.cs')?.classList.add('error');
                    valid = false;
                } else {
                    field.classList.remove('error');
                    if (field.matches('select.cs-native')) field.closest('.cs')?.classList.remove('error');
                }
            });
            if (!valid) {
                e.preventDefault();
                const firstError = this.querySelector('.error');
                firstError?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });
    });

    // ── Tab Switcher ──
    document.querySelectorAll('[data-tab]').forEach(tab => {
        tab.addEventListener('click', function () {
            const group = this.closest('[data-tab-group]');
            if (!group) return;
            const target = this.dataset.tab;
            group.querySelectorAll('[data-tab]').forEach(t => t.classList.remove('active'));
            group.querySelectorAll('[data-tab-content]').forEach(c => c.classList.add('d-none'));
            this.classList.add('active');
            group.querySelector(`[data-tab-content="${target}"]`)?.classList.remove('d-none');
        });
    });

    // ── Smooth number animation ──
    document.querySelectorAll('[data-count]').forEach(el => {
        const target = parseInt(el.dataset.count);
        let current = 0;
        const step = Math.ceil(target / 30);
        const timer = setInterval(() => {
            current = Math.min(current + step, target);
            el.textContent = current.toLocaleString();
            if (current >= target) clearInterval(timer);
        }, 40);
    });

    // Chat boxes
    document.querySelectorAll('.chat-box[data-thread-id]').forEach(chatBox => {
        const messagesWrap = chatBox.querySelector('.chat-messages');
        const form = chatBox.querySelector('form.chat-compose');
        const input = form ? form.querySelector('input[name="message"], textarea[name="message"]') : null;
        const sendBtn = form ? form.querySelector('button[type="submit"]') : null;
        const statusEl = chatBox.querySelector('.chat-compose-status');

        if (!messagesWrap || !form || !input) return;
        if (form.dataset.chatBound === '1') return;
        form.dataset.chatBound = '1';

        const threadId = parseInt(chatBox.dataset.threadId || '0', 10);
        const userId = parseInt(chatBox.dataset.userId || '0', 10);
        const messagesUrl = chatBox.dataset.messagesUrl || '';
        const sendUrl = chatBox.dataset.sendUrl || '';
        const pollMs = Math.max(parseInt(chatBox.dataset.pollMs || '2500', 10) || 2500, 1000);

        let lastId = 0;
        let isPolling = false;
        let isSending = false;

        messagesWrap.querySelectorAll('.chat-msg[data-mid]').forEach(el => {
            const mid = parseInt(el.dataset.mid || '0', 10);
            if (mid > lastId) lastId = mid;
        });

        function setStatus(message, isError = false) {
            if (!statusEl) return;
            statusEl.textContent = message || '';
            statusEl.classList.toggle('is-error', !!message && isError);
            statusEl.classList.toggle('is-success', !!message && !isError);
        }

        function clearEmptyState() {
            messagesWrap.querySelectorAll('.empty-state').forEach(el => el.remove());
        }

        function nearBottom() {
            return (messagesWrap.scrollHeight - messagesWrap.scrollTop - messagesWrap.clientHeight) < 90;
        }

        function scrollToBottom() {
            messagesWrap.scrollTop = messagesWrap.scrollHeight;
        }

        function esc(text) {
            const div = document.createElement('div');
            div.textContent = text == null ? '' : String(text);
            return div.innerHTML;
        }

        function formatText(text) {
            return esc(text).replace(/\r?\n/g, '<br>');
        }

        function formatTime(iso) {
            try {
                const d = new Date(String(iso).replace(' ', 'T'));
                if (Number.isNaN(d.getTime())) return '';
                return d.toLocaleString(undefined, {
                    month: 'short',
                    day: '2-digit',
                    hour: '2-digit',
                    minute: '2-digit'
                });
            } catch (e) {
                return '';
            }
        }

        function appendMessage(msg) {
            if (!msg || !msg.id) return false;
            const mid = parseInt(msg.id, 10);
            if (!mid || mid <= lastId) return false;

            clearEmptyState();

            const mine = parseInt(msg.sender_id || '0', 10) === userId;
            const row = document.createElement('div');
            row.className = 'chat-msg ' + (mine ? 'mine' : 'theirs');
            row.dataset.mid = String(mid);
            row.innerHTML = `
                <div class="chat-bubble">
                    <div class="chat-text">${formatText(msg.message || '')}</div>
                    <div class="chat-time">${esc(formatTime(msg.created_at || ''))}</div>
                </div>
            `;
            messagesWrap.appendChild(row);
            lastId = Math.max(lastId, mid);
            return true;
        }

        async function poll() {
            if (!threadId || !messagesUrl || isPolling) return;
            isPolling = true;
            const shouldScroll = nearBottom();

            try {
                const res = await fetch(`${messagesUrl}?thread_id=${encodeURIComponent(threadId)}&since_id=${encodeURIComponent(lastId)}`, {
                    credentials: 'same-origin',
                    cache: 'no-store'
                });
                if (!res.ok) return;

                const data = await res.json().catch(() => null);
                const msgs = Array.isArray(data?.messages) ? data.messages : [];
                let appendedAny = false;
                msgs.forEach(msg => {
                    appendedAny = appendMessage(msg) || appendedAny;
                });
                if (shouldScroll && appendedAny) scrollToBottom();
            } catch (e) {
                // Ignore transient polling failures.
            } finally {
                isPolling = false;
            }
        }

        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            if (isSending) return;

            const text = (input.value || '').trim();
            if (!text || !threadId || !sendUrl) return;

            if (form.dataset.submitMode === 'native') {
                form.dataset.submitMode = '';
                return;
            }

            isSending = true;
            if (sendBtn) sendBtn.disabled = true;
            setStatus('');

            function fallbackSubmit(message) {
                if (message) setStatus(message, true);
                form.dataset.submitMode = 'native';
                if (sendBtn) sendBtn.disabled = false;
                form.submit();
            }

            try {
                const fd = new FormData();
                fd.append('thread_id', String(threadId));
                fd.append('message', text);

                const res = await fetch(sendUrl, {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                });
                const data = await res.json().catch(() => null);

                if (!res.ok || !data?.message) {
                    fallbackSubmit(data?.error || 'Falling back to standard send...');
                    return;
                }

                appendMessage(data.message);
                input.value = '';
                setStatus('Message sent.');
                scrollToBottom();
            } catch (e) {
                fallbackSubmit('Connection issue. Sending with the standard form...');
            } finally {
                isSending = false;
                if (form.dataset.submitMode !== 'native' && sendBtn) sendBtn.disabled = false;
                input.focus();
            }
        });

        scrollToBottom();
        window.setInterval(poll, pollMs);
    });

    document.querySelectorAll('tr.room-row').forEach(row => {
        const form = row.querySelector('form');
        const editBtn = row.querySelector('.room-edit-toggle');
        const cancelBtn = row.querySelector('.room-cancel-btn');
        if (!editBtn || !cancelBtn) return;

        row.querySelectorAll('input, select, textarea').forEach(field => {
            if (field.type === 'file') return;
            if (field.type === 'checkbox' || field.type === 'radio') {
                field.dataset.originalChecked = field.checked ? '1' : '0';
            } else {
                field.dataset.originalValue = field.value;
            }
        });

        editBtn.addEventListener('click', function () {
            row.classList.add('is-editing');
        });

        cancelBtn.addEventListener('click', function () {
            row.classList.remove('is-editing');
            row.querySelectorAll('input, select, textarea').forEach(field => {
                if (field.type === 'file') {
                    field.value = '';
                    return;
                }
                if (field.type === 'checkbox' || field.type === 'radio') {
                    field.checked = field.dataset.originalChecked === '1';
                } else if (field.dataset.originalValue !== undefined) {
                    field.value = field.dataset.originalValue;
                }
            });
            row.querySelectorAll('.file-preview').forEach(preview => {
                preview.innerHTML = '';
            });
        });
    });
});
