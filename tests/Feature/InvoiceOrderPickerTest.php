<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The invoice's Order list shows the chosen customer's orders only, and the server refuses a mismatch. */
class InvoiceOrderPickerTest extends TestCase
{
    use RefreshDatabase;

    private Customer $swa;
    private Customer $hum;
    private Order $a;
    private Order $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'owner@example.com')->first());
        $this->swa = Customer::create(['name' => 'Swaiba Rahman', 'order_code' => 'SWA']);
        $this->hum = Customer::create(['name' => 'Huma Qadeer', 'order_code' => 'HUM']);
        $this->a = Order::create(['order_no' => 'SWA-0006', 'date' => '2026-11-01', 'customer_id' => $this->swa->id, 'qty' => 24, 'rate' => 8500]);
        $this->b = Order::create(['order_no' => 'HUM-0001', 'date' => '2026-11-01', 'customer_id' => $this->hum->id, 'qty' => 16, 'rate' => 9000]);
    }

    private function save(Customer $c, ?Order $o)
    {
        return $this->postJson('/invoices', ['number' => 'T-'.uniqid(), 'date' => '2026-11-21', 'customer_id' => $c->id, 'order_id' => $o?->id,
            'items' => [['description' => 'Stitching', 'qty' => 1, 'rate' => 100]]]);
    }

    public function test_form_embeds_each_orders_customer_so_the_list_can_be_filtered(): void
    {
        $r = $this->get('/invoices/create?customer_id='.$this->hum->id)->assertOk();
        $orders = $r->viewData('extra')['orders'];
        $this->assertSame($this->hum->id, $orders[$this->b->id]['customer_id']);
        $this->assertSame('Huma Qadeer', $orders[$this->b->id]['customer']);
        $r->assertSee('orderList()', false)->assertSee('Pick a customer to see only their orders');
        // the old unfiltered server-side option list is gone
        $r->assertDontSee('>SWA-0006</option>', false);
    }

    public function test_an_order_of_another_customer_is_rejected_and_the_matching_one_saves(): void
    {
        $this->save($this->hum, $this->a)->assertStatus(422)->assertJsonValidationErrors('order_id');
        $this->save($this->swa, $this->b)->assertStatus(422)->assertJsonValidationErrors('order_id');
        $this->assertSame(0, Invoice::count());

        $this->save($this->swa, $this->a)->assertOk();
        $this->save($this->hum, null)->assertOk();          // order stays optional
        $this->assertSame(2, Invoice::count());
        $this->assertSame($this->a->id, Invoice::where('customer_id', $this->swa->id)->first()->order_id);
    }

    public function test_editing_keeps_the_saved_order_selected(): void
    {
        $this->save($this->swa, $this->a)->assertOk();
        $inv = Invoice::first();
        $this->get("/invoices/{$inv->id}/edit")->assertOk()->assertSee('\\u0022order\\u0022:\\u0022'.$this->a->id.'\\u0022', false);
    }
}
