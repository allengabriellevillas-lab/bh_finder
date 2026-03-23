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
                    valid = false;
                } else {
                    field.classList.remove('error');
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