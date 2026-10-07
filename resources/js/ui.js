/** Header / shell behaviours: theme (dark mode), global search, notification panel. */
export function registerUi(Alpine) {
    // ---- theme: 'light' | 'dark' | 'auto' (follows the device), remembered per device ----
    const mq = window.matchMedia('(prefers-color-scheme: dark)');
    const apply = (mode) => document.documentElement.classList.toggle('dark', mode === 'dark' || (mode === 'auto' && mq.matches));
    Alpine.store('theme', {
        mode: localStorage.getItem('theme') || 'auto',
        set(m) { this.mode = m; localStorage.setItem('theme', m); apply(m); },
        init() { apply(this.mode); mq.addEventListener('change', () => this.mode === 'auto' && apply('auto')); },
    });

    // ---- global search (header) ----
    Alpine.data('globalSearch', (url) => ({
        q: '', open: false, mobile: false, groups: [], flat: [], active: -1, loading: false, offline: false, ctl: null,
        focus() { this.$refs.q?.focus(); this.$refs.q?.select(); },
        openMobile() { this.mobile = true; this.$nextTick(() => this.$refs.mq?.focus()); },
        closeAll() { this.open = false; this.mobile = false; },
        hotkey(e) {
            const t = document.activeElement?.tagName;
            if (e.key === '/' && !['INPUT', 'TEXTAREA', 'SELECT'].includes(t) && !document.activeElement?.isContentEditable) { e.preventDefault(); this.focus(); }
        },
        idx(gi, ii) { return this.groups.slice(0, gi).reduce((s, g) => s + g.items.length, 0) + ii; },
        async run() {
            const q = this.q.trim();
            if (q.length < 2) { this.groups = []; this.flat = []; this.open = false; this.offline = false; return; }
            if (!navigator.onLine) { this.offline = true; this.open = true; return; }
            this.offline = false; this.loading = true;
            this.ctl?.abort(); this.ctl = new AbortController();
            try {
                const r = await fetch(`${url}?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: this.ctl.signal });
                const j = await r.json();
                this.groups = j.groups; this.flat = j.groups.flatMap((g) => g.items);
                this.active = this.flat.length ? 0 : -1; this.open = true;
            } catch (e) { if (e.name !== 'AbortError') { this.offline = true; this.open = true; } }
            finally { this.loading = false; }
        },
        move(d) {
            if (!this.flat.length) return;
            this.active = (this.active + d + this.flat.length) % this.flat.length;
            this.$nextTick(() => this.$root.querySelector('[data-active=true]')?.scrollIntoView({ block: 'nearest' }));
        },
        go() { const it = this.flat[this.active]; if (it) location.href = it.url; },
    }));

    // ---- notification bell ----
    Alpine.data('notifPanel', (latestUrl, readUrl, unread) => ({
        open: false, unread, items: [], loading: false, failed: false,
        async toggle() { this.open = !this.open; if (this.open) await this.load(); },
        async load() {
            this.loading = true; this.failed = false;
            try {
                const r = await fetch(latestUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                const j = await r.json();
                this.items = j.items; this.unread = j.unread;
            } catch { this.failed = true; } finally { this.loading = false; }
        },
        async markAll() {
            const token = document.querySelector('meta[name=csrf-token]').content;
            try {
                await fetch(readUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' }, credentials: 'same-origin' });
                this.unread = 0; this.items = this.items.map((i) => ({ ...i, read: true }));
            } catch { /* offline: keep as is */ }
        },
    }));
}
