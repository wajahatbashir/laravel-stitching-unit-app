<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The invoice PDF shows the same Paid / Balance / status as the invoice page. */
class InvoicePdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_pdf_view_shows_paid_balance_and_status_and_the_download_works(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'owner@example.com')->first());
        $c = Customer::create(['name' => 'Swaiba Rahman', 'order_code' => 'SWA']);
        $inv = Invoice::create(['number' => 'INV-0002', 'customer_id' => $c->id, 'date' => '2026-11-19', 'subtotal' => 234000, 'total' => 234000]);
        CustomerPayment::create(['customer_id' => $c->id, 'invoice_id' => $inv->id, 'date' => '2026-11-19', 'amount' => 120000]);

        $html = view('invoices.pdf', ['inv' => Invoice::with('customer', 'order', 'items')->find($inv->id)])->render();
        $this->assertStringContainsString('src="data:image/png;base64,', $html);   // logo
        $this->assertStringContainsString('PARTIAL', $html);
        $this->assertStringContainsString('Paid', $html);
        $this->assertStringContainsString('120,000.00', $html);
        $this->assertStringContainsString('Balance', $html);
        $this->assertStringContainsString('114,000.00', $html);

        $this->get("/invoices/{$inv->id}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_invoice_logo_can_be_uploaded_in_settings_and_is_used_on_the_pdf(): void
    {
        \Storage::fake('public');
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'owner@example.com')->first());
        $c = Customer::create(['name' => 'Swaiba Rahman', 'order_code' => 'SWA']);
        $inv = Invoice::create(['number' => 'INV-0003', 'customer_id' => $c->id, 'date' => '2026-11-19', 'subtotal' => 10, 'total' => 10]);

        $this->get('/settings')->assertOk()->assertSee('name="invoice_logo"', false);
        $this->put('/settings', ['business_name' => 'Lumiere Premium', 'order_prefix' => 'ORD-', 'invoice_prefix' => 'INV-',
            'invoice_logo' => \Illuminate\Http\UploadedFile::fake()->image('inv.png', 300, 100)])->assertSessionHasNoErrors();
        $this->assertNotEmpty(biz('brand_invoice_logo'));
        \Storage::disk('public')->assertExists(biz('brand_invoice_logo'));

        $own = base64_encode(\Storage::disk('public')->get(biz('brand_invoice_logo')));
        $this->assertStringContainsString($own, view('invoices.pdf', ['inv' => Invoice::with('customer', 'order', 'items')->find($inv->id)])->render());
        $this->get("/invoices/{$inv->id}/pdf")->assertOk();

        // removing it falls back to the default logo; the PDF keeps working
        $this->put('/settings', ['business_name' => 'Lumiere Premium', 'order_prefix' => 'ORD-', 'invoice_prefix' => 'INV-', 'reset_invoice_logo' => 1]);
        $this->assertEmpty(biz('brand_invoice_logo'));
        $this->get("/invoices/{$inv->id}/pdf")->assertOk();
    }
}
