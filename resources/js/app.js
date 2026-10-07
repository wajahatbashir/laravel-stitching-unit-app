import Alpine from 'alpinejs';
import './bootstrap';
import { initOffline } from './offline';
import { setupDatepickers } from './datepicker';
import { registerUi } from './ui';

window.Alpine = Alpine;

Alpine.store('sync', { online: navigator.onLine, pending: 0, failed: 0, syncing: false });

// Money / number helper for calculators in forms
window.num = (v) => parseFloat(v) || 0;

// Pressing Enter inside a field must not save the record — only the Save button does.
// (Search/filter forms use GET and the login form opts in with data-allow-enter.)
document.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter' || e.isComposing) return;
    const t = e.target;
    if (!(t instanceof HTMLInputElement) || ['submit', 'button', 'reset', 'checkbox', 'radio', 'file'].includes(t.type)) return;
    const f = t.form;
    if (!f || f.method.toLowerCase() !== 'post' || 'allowEnter' in f.dataset) return;
    e.preventDefault();
});

registerUi(Alpine); // theme store + header components must exist before Alpine starts
setupDatepickers(); // before Alpine so x-model writes go through the picker
Alpine.start();
initOffline(Alpine.store('sync'));

if ('serviceWorker' in navigator) {
    window.addEventListener('load', async () => {
        try {
            const reg = await navigator.serviceWorker.register('/sw.js');
            const meta = document.querySelector('meta[name="precache"]');
            if (meta && navigator.onLine) {
                const urls = JSON.parse(meta.content || '[]');
                const sw = reg.active || (await navigator.serviceWorker.ready).active;
                sw?.postMessage({ type: 'precache', urls });
            }
        } catch (e) {
            console.warn('SW registration failed', e);
        }
    });
}

// Click an image link marked data-lightbox → full-screen preview (Esc / click to close)
document.addEventListener('click', (e) => {
    const a = e.target.closest('a[data-lightbox]');
    if (!a) return;
    e.preventDefault();
    const ov = document.createElement('div');
    ov.className = 'fixed inset-0 z-[90] flex items-center justify-center bg-black/90 p-3';
    const img = document.createElement('img');
    img.src = a.href;
    img.className = 'max-h-full max-w-full rounded-lg object-contain';
    const close = document.createElement('button');
    close.type = 'button';
    close.textContent = '✕';
    close.className = 'absolute end-3 top-3 flex h-11 w-11 items-center justify-center rounded-full bg-white/15 text-xl text-white';
    const done = () => { ov.remove(); document.removeEventListener('keydown', onKey); };
    const onKey = (k) => { if (k.key === 'Escape') done(); };
    ov.addEventListener('click', done);
    document.addEventListener('keydown', onKey);
    ov.append(img, close);
    document.body.append(ov);
});
