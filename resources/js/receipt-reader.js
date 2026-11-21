/**
 * Reads a payment receipt (bank-app screenshot) the moment it is chosen and fills the form it sits in:
 * amount, date, "paid via" (a bank-app receipt means Bank) and the transaction id into empty Notes.
 * Works for any <input type=file data-ocr> — the worker "Record payment" box and the payment forms' receipt box.
 * The file is still submitted with the form as usual; only a small copy is sent to POST /receipts/ocr (local Tesseract).
 */
const shrink = (file, max = 1800) => new Promise((res) => {
    const img = new Image();
    img.onload = () => {
        const s = Math.min(1, max / Math.max(img.width, img.height));
        const c = document.createElement('canvas');
        c.width = img.width * s; c.height = img.height * s;
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
        URL.revokeObjectURL(img.src);
        c.toBlob((b) => res(b || file), 'image/jpeg', 0.85);
    };
    img.onerror = () => res(file);
    img.src = URL.createObjectURL(file);
});

// set a field the way a user would (date inputs go through the calendar picker's value setter)
const put = (el, v) => {
    if (!el || v === undefined || v === null || v === '') return false;
    el.value = v;
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
};

const noteEl = (input) => {
    let n = input.parentElement.querySelector('.ocr-note');
    if (!n) {
        n = document.createElement('p');
        n.className = 'ocr-note mt-2 text-sm';
        input.insertAdjacentElement('afterend', n);
    }
    return n;
};

async function read(input) {
    const form = input.form || input.closest('form');
    const note = noteEl(input);
    const f = input.files[0];
    const say = (text, cls) => { note.textContent = text; note.className = 'ocr-note mt-2 text-sm ' + cls; };
    if (!form || !f || !f.type.startsWith('image/')) { note.textContent = ''; return; }
    const d = input.dataset;
    if (!navigator.onLine) { say(d.ocrOffline, 'text-slate-500'); return; }

    say('⏳ ' + d.ocrReading, 'text-brand-700');
    try {
        const fd = new FormData();
        fd.append('receipt', await shrink(f), 'receipt.jpg');
        fd.append('_token', document.querySelector('meta[name=csrf-token]').content);
        const r = await fetch(d.ocrUrl, { method: 'POST', body: fd, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!r.ok) throw new Error();
        const j = await r.json();
        if (input.files[0] !== f) return;   // another file was chosen meanwhile

        const q = (n) => form.querySelector(`[name="${n}"]`);
        const filled = [];
        if (put(q('amount'), j.amount)) filled.push('amount');
        if (put(q('date'), j.date)) filled.push('date');
        const mode = q('mode');
        if ((j.amount || j.date || j.payee || j.reference) && mode && [...mode.options].some((o) => o.value === 'bank')) { put(mode, 'bank'); filled.push('mode'); }
        const notes = q('notes');
        if (j.reference && notes && !notes.value.trim()) put(notes, 'Txn ID: ' + j.reference);

        if (filled.length) {
            say('✓ ' + d.ocrDone + (j.payee ? ' ' + d.ocrPayee + ' ' + j.payee + '.' : ''), 'text-emerald-700');
        } else {
            say(d.ocrFail, 'text-amber-700');
        }
    } catch (_) {
        say(d.ocrFail, 'text-amber-700');
    }
}

export function setupReceiptReader() {
    document.addEventListener('change', (e) => {
        const t = e.target;
        if (t instanceof HTMLInputElement && t.type === 'file' && 'ocrUrl' in t.dataset) read(t);
    });
}
