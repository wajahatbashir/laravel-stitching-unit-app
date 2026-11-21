{{-- File input for a payment receipt that reads itself: choosing a bank-app screenshot fills the amount, date and "paid via" of the
     surrounding form (see resources/js/receipt-reader.js; endpoint POST /receipts/ocr). Extra attributes (id, class, x-ref, @change…) pass through. --}}
<input type="file" name="receipt" accept="image/*,application/pdf"
       data-ocr-url="{{ route('receipts.ocr', [], false) }}"
       data-ocr-reading="{{ __('Reading receipt…') }}"
       data-ocr-done="{{ __('Receipt read — please check the amount, date and the other fields.') }}"
       data-ocr-payee="{{ __('Paid to:') }}"
       data-ocr-fail="{{ __('Could not read this receipt — please enter the values.') }}"
       data-ocr-offline="{{ __('Offline: enter the amount and date yourself; the receipt is still saved.') }}"
       {{ $attributes->merge(['class' => 'input file:me-3 file:rounded-lg file:border-0 file:bg-brand-100 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-brand-800']) }}>
