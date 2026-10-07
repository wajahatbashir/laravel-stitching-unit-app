{{-- PDF download + share (phone share sheet, else save PDF + open WhatsApp chat) · params: kind, id, from, to, phone, text, email (optional) --}}
@php
    $q = array_filter(['date_from' => $from ?? null, 'date_to' => $to ?? null]);
    $pdfUrl = route('documents.pdf', ['kind' => $kind, 'id' => $id] + $q, false);
@endphp
<div x-data="shareDoc(@js(['pdf' => $pdfUrl, 'wa' => \App\Http\Controllers\DocumentController::waNumber($phone ?? null), 'text' => $text, 'name' => $kind.'-'.$id.'.pdf', 'fail' => __('Could not prepare the PDF — check your connection.')]))" class="flex flex-wrap items-center gap-2">
    <a href="{{ $pdfUrl }}" class="btn btn-ghost btn-sm"><x-icon name="download" class="h-4 w-4" />PDF</a>
    <button type="button" @click="share()" :disabled="busy" class="btn btn-gold btn-sm">{{ __('Share / WhatsApp') }}</button>
    @isset($email)
        <form method="POST" action="{{ route('customers.statement-email', $id) }}" class="flex items-center gap-1" onsubmit="return confirm('{{ __('Email the statement?') }}')">
            @csrf
            <input type="hidden" name="date_from" value="{{ $from ?? '' }}"><input type="hidden" name="date_to" value="{{ $to ?? '' }}">
            <input type="email" name="to" value="{{ $email }}" placeholder="{{ __('Email') }}" required class="input !h-9 !w-44 !py-1 text-sm">
            <button class="btn btn-ghost btn-sm">{{ __('Email') }}</button>
        </form>
    @endisset
</div>
@once
@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('shareDoc', (c) => ({
        busy: false,
        async share() {
            this.busy = true;
            try {
                const blob = await (await fetch(c.pdf, { credentials: 'same-origin' })).blob();
                const file = new File([blob], c.name, { type: 'application/pdf' });
                if (navigator.canShare && navigator.canShare({ files: [file] })) {
                    await navigator.share({ files: [file], text: c.text });
                    return;
                }
                // file sharing unsupported (desktop): save the PDF, then open the chat so it can be attached by hand
                const a = document.createElement('a');
                a.href = URL.createObjectURL(blob); a.download = c.name; a.click();
                window.open('https://wa.me/' + c.wa + '?text=' + encodeURIComponent(c.text), '_blank');
            } catch (e) {
                if (e.name !== 'AbortError') alert(c.fail);
            } finally {
                this.busy = false;
            }
        },
    }));
});
</script>
@endpush
@endonce
