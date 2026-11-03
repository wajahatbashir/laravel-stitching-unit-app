{{-- Shows how much is due to the chosen vendor, with its unpaid bills, right under the form fields --}}
<div class="mt-4" x-data="vendorDue(@js($extra['dues'] ?? []), @js($extra['self'] ?? null), @js(['paid_ahead' => __('Paid ahead (we hold credit with this vendor)'), 'due' => __('Due to this vendor'), 'due_before' => __('Due before this payment'), 'clear' => __('Nothing due to this vendor'), 'bills' => __('Unpaid bills (oldest first)'), 'late' => __('overdue'), 'fill' => __('Fill the full due amount'), 'due_on' => __('due')]))" x-cloak x-show="info">
    <div class="rounded-xl p-3 ring-1" :class="amountDue > 0 ? 'bg-rose-50 ring-rose-200' : (amountDue < 0 ? 'bg-emerald-50 ring-emerald-200' : 'bg-slate-50 ring-slate-200')">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <p class="text-xs text-slate-500" x-text="amountDue > 0 ? (self ? t.due_before : t.due) : (amountDue < 0 ? t.paid_ahead : t.clear)"></p>
                <p class="text-xl font-bold tabular-nums" :class="amountDue > 0 ? 'text-rose-700' : 'text-emerald-700'" x-text="amountDue === 0 ? '' : money"></p>
            </div>
            <button type="button" x-show="amountDue > 0" @click="fill()" class="btn btn-ghost btn-sm" x-text="t.fill"></button>
        </div>
        <template x-if="info && info.bills.length">
            <div class="mt-2 border-t border-slate-200/70 pt-2">
                <p class="mb-1 text-xs font-semibold text-slate-500" x-text="t.bills"></p>
                <ul class="space-y-1 text-sm">
                    <template x-for="(b, i) in info.bills" :key="i">
                        <li class="flex justify-between gap-2"><span class="min-w-0 truncate"><span x-text="b.title"></span> <span class="text-xs" :class="b.late ? 'font-semibold text-rose-600' : 'text-slate-400'" x-text="'· ' + (b.late ? t.late : t.due_on) + ' ' + b.due"></span></span><span class="tabular-nums" x-text="b.left"></span></li>
                    </template>
                </ul>
            </div>
        </template>
    </div>
</div>
@once
@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('vendorDue', (dues, self, t) => ({
        dues, self, t, vendor: '',
        init() {
            const sel = document.querySelector('select[name=vendor_id]');
            if (!sel) return;
            this.vendor = sel.value;
            sel.addEventListener('change', () => { this.vendor = sel.value; });
        },
        get info() { return this.vendor ? this.dues[this.vendor] || null : null; },
        // when editing, this payment is already deducted from the vendor's due, so add it back
        get amountDue() { return this.info ? Math.round((this.info.due + (this.self && String(this.self.vendor) === String(this.vendor) ? this.self.amount : 0)) * 100) / 100 : 0; },
        get money() { return this.info ? (this.amountDue === this.info.due ? this.info.text : (this.info.text.replace(/[\d.,]+$/, '') + Math.abs(this.amountDue).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }))) : ''; },
        fill() { const a = document.querySelector('input[name=amount]'); if (a) { a.value = this.amountDue.toFixed(2); a.dispatchEvent(new Event('input', { bubbles: true })); } },
    }));
});
</script>
@endpush
@endonce
