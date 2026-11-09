<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Every customer numbers its orders on its own sequence: SWA-0006, SWA-0007 … and HUM-0001, HUM-0002 …. */
class OrderNumberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'owner@example.com')->first());
    }

    private function customer(string $name, ?string $code = null): Customer
    {
        return Customer::create(['name' => $name, 'order_code' => $code]);
    }

    private function order(Customer $c, array $x = [])
    {
        return $this->postJson('/orders', $x + ['date' => '2026-11-01', 'customer_id' => $c->id, 'qty' => 10, 'status' => 'pending']);
    }

    public function test_new_customer_gets_a_code_from_the_name_and_codes_stay_unique(): void
    {
        $this->post('/customers', ['name' => 'Swaiba Rahman'])->assertSessionHasNoErrors();
        $this->post('/customers', ['name' => 'Swaiba Khan'])->assertSessionHasNoErrors();   // same first letters
        $this->post('/customers', ['name' => 'Huma Qadeer', 'order_code' => 'hq'])->assertSessionHasNoErrors();

        $this->assertSame('SWA', Customer::where('name', 'Swaiba Rahman')->value('order_code'));
        $this->assertSame('SWA2', Customer::where('name', 'Swaiba Khan')->value('order_code'));
        $this->assertSame('HQ', Customer::where('name', 'Huma Qadeer')->value('order_code'));

        $this->post('/customers', ['name' => 'Someone', 'order_code' => 'HQ'])->assertSessionHasErrors('order_code');
        $this->post('/customers', ['name' => 'Someone', 'order_code' => 'a-b'])->assertSessionHasErrors('order_code');
    }

    public function test_each_customer_has_its_own_sequence_and_continues_from_the_highest_number(): void
    {
        $swa = $this->customer('Swaiba Rahman', 'SWA');
        $hum = $this->customer('Huma Qadeer', 'HUM');
        Order::create(['order_no' => 'SWA-0006', 'date' => '2026-11-01', 'customer_id' => $swa->id, 'qty' => 5]);   // her earlier orders were outside the app

        $this->assertSame('SWA-0007', Order::nextNumber($swa->id));
        $this->assertSame('HUM-0001', Order::nextNumber($hum->id));

        $this->order($hum)->assertOk();
        $this->order($hum)->assertOk();
        $this->order($swa)->assertOk();
        $this->order($swa)->assertOk();

        $this->assertSame(['HUM-0001', 'HUM-0002'], Order::where('customer_id', $hum->id)->orderBy('id')->pluck('order_no')->all());
        $this->assertSame(['SWA-0006', 'SWA-0007', 'SWA-0008'], Order::where('customer_id', $swa->id)->orderBy('id')->pluck('order_no')->all());
    }

    public function test_a_stale_or_duplicate_number_is_replaced_and_a_typed_number_is_kept(): void
    {
        $swa = $this->customer('Swaiba Rahman', 'SWA');
        $this->order($swa, ['order_no' => 'SWA-0001'])->assertOk();
        $this->order($swa, ['order_no' => 'SWA-0001'])->assertOk();         // offline replay with a stale number
        $this->order($swa, ['order_no' => 'SWA-0042'])->assertOk();         // typed on purpose
        $this->order($swa)->assertOk();                                       // left empty

        $this->assertSame(['SWA-0001', 'SWA-0002', 'SWA-0042', 'SWA-0043'], Order::orderBy('id')->pluck('order_no')->all());
    }

    public function test_customer_without_a_code_falls_back_to_the_shared_prefix(): void
    {
        $c = $this->customer('No Code');
        $this->assertStringStartsWith('ORD-', Order::nextNumber($c->id));
        $this->assertStringStartsWith('ORD-', Order::nextNumber());
    }

    public function test_next_number_endpoint_and_form_wiring(): void
    {
        $swa = $this->customer('Swaiba Rahman', 'SWA');
        Order::create(['order_no' => 'SWA-0006', 'date' => '2026-11-01', 'customer_id' => $swa->id, 'qty' => 5]);

        $this->getJson('/orders/next-number?customer_id='.$swa->id)->assertOk()->assertJson(['order_no' => 'SWA-0007']);
        $this->get('/orders/create')->assertOk()->assertSee('orders\\/next-number', false)->assertSee('f_order_no', false);
        $this->get('/orders/'.Order::first()->id.'/edit')->assertOk()->assertSee('value="SWA-0006"', false)->assertDontSee('orders\\/next-number', false);
    }

    public function test_editing_keeps_numbers_unique(): void
    {
        $swa = $this->customer('Swaiba Rahman', 'SWA');
        $this->order($swa)->assertOk();
        $this->order($swa)->assertOk();
        [$a, $b] = Order::orderBy('id')->get()->all();

        $this->put('/orders/'.$b->id, ['order_no' => $a->order_no, 'date' => '2026-11-01', 'customer_id' => $swa->id, 'qty' => 10, 'status' => 'pending'])
            ->assertSessionHasErrors('order_no');
    }
}
