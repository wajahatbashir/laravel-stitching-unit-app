/**
 * Replaces native <input type="date|month"> with Vanilla Calendar Pro.
 * The original input stays in the form (hidden) and keeps holding the ISO value (YYYY-MM-DD / YYYY-MM),
 * so server code, Alpine x-model and the offline queue keep working unchanged.
 * A read-only text input shows a friendly value ("06 Oct 2026") and opens the calendar.
 */
import { Calendar } from 'vanilla-calendar-pro';
import 'vanilla-calendar-pro/styles/index.css';

const lang = document.documentElement.lang === 'ur' ? 'ur' : 'en';
const proto = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
const pad = (n) => String(n).padStart(2, '0');
const todayIso = () => { const d = new Date(); return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`; };

const T = {
    en: { today: 'Today', clear: 'Clear', date: 'Select date', month: 'Select month', required: 'Please choose a date' },
    ur: { today: 'آج', clear: 'صاف کریں', date: 'تاریخ منتخب کریں', month: 'مہینہ منتخب کریں', required: 'براہ کرم تاریخ منتخب کریں' },
}[lang];

function pretty(v, isMonth) {
    if (!v) return '';
    const [y, m, d] = v.split('-').map(Number);
    const dt = new Date(y, m - 1, d || 1);
    const loc = lang === 'ur' ? 'ur-PK-u-nu-latn' : 'en-GB';
    return dt.toLocaleDateString(loc, isMonth ? { month: 'short', year: 'numeric' } : { day: '2-digit', month: 'short', year: 'numeric' });
}

const ICON = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" class="h-5 w-5"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>';

function enhance(orig) {
    if (orig._dp) return;
    orig._dp = true;
    const isMonth = orig.type === 'month';
    const initial = orig.value;
    const required = orig.required;
    const min = orig.min || undefined;
    const max = orig.max || undefined;

    const display = document.createElement('input');
    display.type = 'text';
    display.readOnly = true;
    display.autocomplete = 'off';
    display.setAttribute('inputmode', 'none');
    display.className = orig.className + ' cursor-pointer pe-10';
    display.placeholder = isMonth ? T.month : T.date;
    if (orig.id) { display.id = orig.id; orig.removeAttribute('id'); }
    if (orig.hasAttribute('x-show')) { /* wrapper carries visibility instead */ }

    const wrap = document.createElement('div');
    wrap.className = 'relative';
    const icon = document.createElement('span');
    icon.className = 'pointer-events-none absolute inset-y-0 end-3 flex items-center text-slate-400';
    icon.innerHTML = ICON;
    orig.before(wrap);
    orig.type = 'hidden';
    wrap.append(display, orig, icon);
    if (required) orig.dataset.dpRequired = '1';

    const sync = () => { display.value = pretty(proto.get.call(orig), isMonth); };
    const emit = () => { orig.dispatchEvent(new Event('input', { bubbles: true })); orig.dispatchEvent(new Event('change', { bubbles: true })); };

    const parts = (iso) => { const [y, m] = (iso || todayIso()).split('-').map(Number); return { y, m: m - 1 }; };
    const toCal = (iso) => {
        const { y, m } = parts(iso);
        return { selectedDates: iso && !isMonth ? [iso] : [], selectedMonth: m, selectedYear: y };
    };

    let cal;
    const setIso = (iso, fromUser = true) => {
        proto.set.call(orig, iso);
        sync();
        if (cal) cal.set(toCal(iso), { dates: true, month: true, year: true });
        if (fromUser) emit();
    };

    // programmatic writes (Alpine x-model, OCR pre-fill, form resets) keep the display in sync
    Object.defineProperty(orig, 'value', {
        configurable: true,
        get() { return proto.get.call(this); },
        set(v) { proto.set.call(this, v); sync(); if (cal) cal.set(toCal(v), { dates: true, month: true, year: true }); },
    });

    const quick = (self) => {
        const el = self.context.mainElement;
        if (el.querySelector('.dp-quick')) return;
        const bar = document.createElement('div');
        bar.className = 'dp-quick';
        bar.innerHTML = (isMonth ? '<button type="button" data-dp="prev-year" aria-label="Previous year">‹ </button><button type="button" data-dp="next-year" aria-label="Next year"> ›</button>' : '')
            + `<button type="button" data-dp="today">${T.today}</button>${required ? '' : `<button type="button" data-dp="clear">${T.clear}</button>`}`;
        bar.addEventListener('click', (e) => {
            const b = e.target.closest('button[data-dp]');
            if (!b) return;
            if (b.dataset.dp.endsWith('-year')) {
                const y = self.context.selectedYear + (b.dataset.dp === 'next-year' ? 1 : -1);
                self.set({ selectedYear: y }, { year: true });
                return;
            }
            const t = todayIso();
            setIso(b.dataset.dp === 'today' ? (isMonth ? t.slice(0, 7) : t) : '');
            self.hide();
        });
        el.append(bar);
    };

    cal = new Calendar(display, {
        inputMode: true,
        positionToInput: 'auto',
        type: isMonth ? 'month' : 'default',
        selectionDatesMode: 'single',
        selectionMonthsMode: true,
        selectionYearsMode: true,
        enableDateToggle: false,
        firstWeekday: 1,
        locale: lang,
        selectedTheme: 'light',
        dateMin: min,
        dateMax: max,
        ...toCal(initial),
        onClickDate(self) {
            const d = self.context.selectedDates[0];
            if (d) { setIso(d); self.hide(); }
        },
        onClickMonth(self) {
            if (!isMonth) return;
            const { selectedYear: y, selectedMonth: m } = self.context;
            setIso(`${y}-${pad(m + 1)}`);
            self.hide();
        },
        onShow: quick,
    });
    cal.init();
    sync();
    orig._dpCal = cal;
}

export function initDatepickers(root = document) {
    root.querySelectorAll('input[type=date], input[type=month]').forEach(enhance);
}

export function setupDatepickers() {
    initDatepickers();
    // inputs created later (Alpine templates, cached pages)
    new MutationObserver((muts) => {
        for (const m of muts) for (const n of m.addedNodes) {
            if (n.nodeType === 1) initDatepickers(n.matches?.('input') ? n.parentNode : n);
        }
    }).observe(document.body, { childList: true, subtree: true });

    // required dates: read-only inputs skip browser validation, so check here (before the offline handler)
    document.addEventListener('submit', (e) => {
        const form = e.target;
        if (!(form instanceof HTMLFormElement)) return;
        const bad = [...form.querySelectorAll('input[data-dp-required]')].find((i) => !i.disabled && !proto.get.call(i));
        if (bad) {
            e.preventDefault();
            e.stopImmediatePropagation();
            const disp = bad.parentElement.querySelector('input[type=text]');
            disp?.focus();
            window.toast?.(T.required, 'err');
        }
    }, true);
}
