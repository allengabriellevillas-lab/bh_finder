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
                if (icon) icon.className = input.type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
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
});
