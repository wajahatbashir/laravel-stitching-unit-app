/**
 * Offline outbox.
 * Forms marked data-offline are submitted via fetch; if the network is down (or the server
 * unreachable) the submission is stored in IndexedDB and replayed automatically when the
 * connection returns. Every submission carries a fresh client-generated uuid so the server can
 * ignore duplicates (safe to retry).
 */
const DB = 'lumiere-outbox';
const STORE = 'items';

function open() {
    return new Promise((res, rej) => {
        const r = indexedDB.open(DB, 1);
        r.onupgradeneeded = () => r.result.createObjectStore(STORE, { keyPath: 'id', autoIncrement: true });
        r.onsuccess = () => res(r.result);
        r.onerror = () => rej(r.error);
    });
}

async function tx(mode, fn) {
    const db = await open();
    return new Promise((res, rej) => {
        const t = db.transaction(STORE, mode);
        const out = fn(t.objectStore(STORE));
        t.oncomplete = () => res(out?.result);
        t.onerror = () => rej(t.error);
    });
}

export const outbox = {
    add: (item) => tx('readwrite', (s) => s.add(item)),
    all: () => tx('readonly', (s) => s.getAll()),
    put: (item) => tx('readwrite', (s) => s.put(item)),
    del: (id) => tx('readwrite', (s) => s.delete(id)),
};
window.outbox = outbox;

const uuid = () => (crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
}));

function toast(msg, kind = 'ok') {
    const el = document.createElement('div');
    el.className = `fixed inset-x-4 bottom-24 z-[100] mx-auto max-w-md rounded-xl px-4 py-3 text-sm font-medium text-white shadow-lg md:bottom-6 ${kind === 'ok' ? 'bg-emerald-600' : kind === 'warn' ? 'bg-amber-600' : 'bg-rose-600'}`;
    el.textContent = msg;
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 4000);
}
window.toast = toast;

async function refreshCounts(store) {
    const all = await outbox.all();
    store.pending = all.filter((i) => i.status !== 'failed').length;
    store.failed = all.filter((i) => i.status === 'failed').length;
}

async function csrf() {
    const r = await fetch('/csrf', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    if (!r.ok) throw new Error('auth');
    return (await r.json()).token;
}

function buildBody(fields, token) {
    const fd = new FormData();
    fields.forEach(([k, v]) => fd.append(k, v));
    fd.set('_token', token);
    return fd;
}

async function send(url, fields, token) {
    const ctl = new AbortController();
    const timer = setTimeout(() => ctl.abort(), 20000);
    try {
        return await fetch(url, {
            method: 'POST',
            body: buildBody(fields, token),
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            signal: ctl.signal,
        });
    } finally {
        clearTimeout(timer);
    }
}

function errorText(json) {
    if (!json) return 'Rejected by server';
    if (json.errors) return Object.values(json.errors).flat().join(' ');
    return json.message || 'Rejected by server';
}

let syncing = false;
export async function syncAll(store) {
    if (syncing || !navigator.onLine) return;
    syncing = true;
    store.syncing = true;
    try {
        const items = (await outbox.all()).filter((i) => i.status !== 'failed');
        if (!items.length) return;
        let token;
        try { token = await csrf(); } catch { return; } // logged out / offline
        let done = 0;
        for (const it of items) {
            try {
                const r = await send(it.url, it.fields, token);
                if (r.ok) { await outbox.del(it.id); done++; }
                else if (r.status === 419) { token = await csrf(); }
                else if (r.status === 401 || r.status === 302) { break; }
                else {
                    it.status = 'failed';
                    it.error = errorText(await r.json().catch(() => null));
                    await outbox.put(it);
                }
            } catch { break; } // network dropped again — keep the rest queued
        }
        if (done) toast(`${done} offline record(s) synced`, 'ok');
    } finally {
        syncing = false;
        store.syncing = false;
        await refreshCounts(store);
    }
}

function showErrors(form, json) {
    let box = form.querySelector('[data-errors]');
    if (!box) {
        box = document.createElement('div');
        box.dataset.errors = '';
        box.className = 'mb-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-800 ring-1 ring-rose-200';
        form.prepend(box);
    }
    box.innerHTML = '';
    const list = json?.errors ? Object.values(json.errors).flat() : [json?.message || 'Could not save'];
    list.forEach((m) => { const p = document.createElement('p'); p.textContent = m; box.appendChild(p); });
    box.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

export function initOffline(store) {
    const update = () => { store.online = navigator.onLine; if (navigator.onLine) syncAll(store); };
    window.addEventListener('online', update);
    window.addEventListener('offline', () => { store.online = false; });
    refreshCounts(store).then(() => navigator.onLine && syncAll(store));
    setInterval(() => navigator.onLine && syncAll(store), 30000);
    window.addEventListener('outbox-changed', () => refreshCounts(store));

    document.addEventListener('submit', async (e) => {
        const form = e.target;
        if (!(form instanceof HTMLFormElement) || !('offline' in form.dataset)) return;
        e.preventDefault();
        const btn = form.querySelector('[type=submit]');
        if (btn) btn.disabled = true;

        const fd = new FormData(form);
        const fields = [];
        for (const [k, v] of fd.entries()) {
            if (k === '_token') continue;
            if (v instanceof File && !v.size) continue;
            fields.push([k, k === 'uuid' ? uuid() : v]);
        }
        const url = form.action;
        const after = (e.submitter?.name === 'again' && form.dataset.again) || form.dataset.after || '/';

        try {
            if (!navigator.onLine) throw new TypeError('offline');
            const r = await send(url, fields, fd.get('_token'));
            if (r.ok) { sessionStorage.setItem('flash', (await r.json()).message || 'Saved'); location.href = after; return; }
            if (r.status === 422) { showErrors(form, await r.json()); return; }
            if (r.status === 419) {
                const r2 = await send(url, fields, await csrf());
                if (r2.ok) { sessionStorage.setItem('flash', 'Saved'); location.href = after; return; }
            }
            showErrors(form, await r.json().catch(() => null));
        } catch (err) {
            if (err instanceof TypeError || err.name === 'AbortError') {
                await outbox.add({ url, fields, label: form.dataset.label || url, created: Date.now(), status: 'queued' });
                await refreshCounts(store);
                // stay on the (cached) form: the list page may not be available offline
                sessionStorage.setItem('flash', 'Saved offline — will sync automatically when internet is back');
                location.reload(); // cached copy of the same form → clean form for the next entry
                return;
            }
            showErrors(form, null);
        } finally {
            if (btn) btn.disabled = false;
        }
    });

    const flash = sessionStorage.getItem('flash');
    if (flash) { sessionStorage.removeItem('flash'); toast(flash, flash.includes('offline') ? 'warn' : 'ok'); }
}
