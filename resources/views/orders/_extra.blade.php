{{-- New order: fill the order number from the chosen customer's own sequence (SWA-0007). Typing your own number stops the auto-fill. --}}
@unless ($model)
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const cust = document.getElementById('f_customer_id'), no = document.getElementById('f_order_no');
    if (!cust || !no) return;
    let auto = no.value.trim() === '';
    no.addEventListener('input', () => { auto = no.value.trim() === ''; });
    const fill = async () => {
        if (!cust.value || !auto) return;
        try {
            const r = await fetch(@js(route('orders.next-number', [], false)) + '?customer_id=' + encodeURIComponent(cust.value), {headers: {'Accept': 'application/json'}, credentials: 'same-origin'});
            if (!r.ok) return;
            const j = await r.json();
            if (auto && j.order_no) no.value = j.order_no;   // still untouched by the user
        } catch (e) { /* offline: the server numbers the order when it syncs */ }
    };
    cust.addEventListener('change', fill);
    fill();
});
</script>
@endpush
@endunless
