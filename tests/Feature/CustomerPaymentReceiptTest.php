<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CustomerPaymentReceiptTest extends TestCase
{
    use RefreshDatabase;

    private Customer $c;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'owner@example.com')->first());
        Storage::fake('public');
        $this->c = Customer::create(['name' => 'Rose Garden']);
    }

    private function pay(array $x = [])
    {
        return $this->post('/customer-payments', $x + ['customer_id' => $this->c->id, 'date' => '2026-11-03', 'amount' => 5000, 'mode' => 'bank']);
    }

    public function test_form_offers_a_receipt_upload_on_add_and_edit(): void
    {
        $this->get('/customer-payments/create')->assertOk()->assertSee('name="receipt"', false)->assertSee('Payment receipt (bank app screenshot / photo)')->assertSee('border-dashed', false);
        $this->pay(['receipt' => UploadedFile::fake()->image('slip.jpg', 800, 600)])->assertSessionHasNoErrors();
        $p = CustomerPayment::first();
        $this->get("/customer-payments/{$p->id}/edit")->assertOk()->assertSee('Saved receipt')->assertSee('Remove receipt')->assertSee('remove_receipt', false)->assertSee(Storage::url($p->receipt_path), false);
    }

    public function test_advance_is_saved_with_its_receipt_and_it_shows_in_list_and_customer_page(): void
    {
        $this->pay(['receipt' => UploadedFile::fake()->image('nayapay.png', 600, 900)])->assertSessionHasNoErrors();   // no invoice → an advance
        $p = CustomerPayment::first();
        $this->assertNull($p->invoice_id);
        $this->assertStringStartsWith('uploads/customer_payments/', $p->receipt_path);
        Storage::disk('public')->assertExists($p->receipt_path);

        $this->get('/customer-payments')->assertOk()->assertSee('Receipt')->assertSee('View')->assertSee(Storage::url($p->receipt_path), false);
        $this->get("/customers/{$this->c->id}")->assertOk()->assertSee(Storage::url($p->receipt_path), false);
        $this->get('/customer-payments?export=xlsx')->assertOk();
    }

    public function test_receipt_is_optional_and_only_images_or_pdf_are_accepted(): void
    {
        $this->pay()->assertSessionHasNoErrors();
        $this->assertNull(CustomerPayment::first()->receipt_path);

        $this->pay(['receipt' => UploadedFile::fake()->create('virus.exe', 10, 'application/octet-stream')])->assertSessionHasErrors('receipt');
        $this->pay(['receipt' => UploadedFile::fake()->create('big.pdf', 11000, 'application/pdf')])->assertSessionHasErrors('receipt');   // > 10 MB
        $this->pay(['receipt' => UploadedFile::fake()->create('slip.pdf', 100, 'application/pdf')])->assertSessionHasNoErrors();
        $this->assertSame(2, CustomerPayment::count());
        $this->assertStringEndsWith('.pdf', CustomerPayment::orderByDesc('id')->first()->receipt_path);
    }

    public function test_replacing_removing_and_deleting_cleans_up_the_files(): void
    {
        $this->pay(['receipt' => UploadedFile::fake()->image('first.jpg', 400, 300)])->assertSessionHasNoErrors();
        $p = CustomerPayment::first();
        $first = $p->receipt_path;
        $base = ['customer_id' => $this->c->id, 'date' => '2026-11-03', 'amount' => 5000, 'mode' => 'bank'];

        // editing without touching the receipt keeps it
        $this->put("/customer-payments/{$p->id}", $base)->assertSessionHasNoErrors();
        $this->assertSame($first, $p->fresh()->receipt_path);

        // a new file replaces it and the old one is deleted
        $this->put("/customer-payments/{$p->id}", $base + ['receipt' => UploadedFile::fake()->image('second.jpg', 400, 300)])->assertSessionHasNoErrors();
        $second = $p->fresh()->receipt_path;
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);

        // "Remove" clears it
        $this->put("/customer-payments/{$p->id}", $base + ['remove_receipt' => 1])->assertSessionHasNoErrors();
        $this->assertNull($p->fresh()->receipt_path);
        Storage::disk('public')->assertMissing($second);

        // deleting the payment deletes its receipt
        $this->put("/customer-payments/{$p->id}", $base + ['receipt' => UploadedFile::fake()->image('third.jpg', 400, 300)])->assertSessionHasNoErrors();
        $third = $p->fresh()->receipt_path;
        $this->delete("/customer-payments/{$p->id}")->assertRedirect();
        Storage::disk('public')->assertMissing($third);
    }

    public function test_receipt_can_be_added_while_recording_a_payment_against_an_invoice(): void
    {
        $inv = \App\Models\Invoice::create(['number' => 'T-1', 'customer_id' => $this->c->id, 'date' => '2026-11-01', 'subtotal' => 5000, 'total' => 5000]);
        $this->pay(['invoice_id' => $inv->id, 'receipt' => UploadedFile::fake()->image('proof.jpg', 500, 500)])->assertSessionHasNoErrors();
        $p = CustomerPayment::first();
        $this->assertSame($inv->id, $p->invoice_id);
        Storage::disk('public')->assertExists($p->receipt_path);
    }
}
