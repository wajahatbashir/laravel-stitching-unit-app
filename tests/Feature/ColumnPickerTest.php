<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Every list has a Columns picker; Orders shows the rate per piece. */
class ColumnPickerTest extends TestCase
{
    use RefreshDatabase;

    public function test_orders_list_shows_rate_per_piece_and_has_the_columns_picker(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'owner@example.com')->first());
        $c = Customer::create(['name' => 'Swaiba Rahman', 'order_code' => 'SWA']);
        Order::create(['order_no' => 'SWA-0007', 'date' => '2026-11-18', 'customer_id' => $c->id, 'qty' => 38, 'rate' => 15000, 'amount' => 570000, 'status' => 'pending']);

        $r = $this->get('/orders')->assertOk();
        $r->assertSee('Rate / pc')->assertSee('Rs 15,000.00')->assertSee('colPicker', false)->assertSee('data-list="orders"', false)->assertSee('data-col="4"', false);
        $r->assertSee('Columns');

        $this->get('/customers')->assertOk()->assertSee('colPicker', false);

        // exports keep every column
        \Maatwebsite\Excel\Facades\Excel::fake();
        \Maatwebsite\Excel\Facades\Excel::matchByRegex();
        $this->get('/orders?export=xlsx')->assertOk();
        \Maatwebsite\Excel\Facades\Excel::assertDownloaded('/orders-.*\.xlsx/', fn ($x) => in_array('Rate / pc', $x->headings(), true) && in_array('Brand', $x->headings(), true));
    }
}
