<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** One material purchase shared by several orders: the amount is split between them, nothing else moves. */
class ExpenseSplitTest extends TestCase
{
    use RefreshDatabase;

    private Order $a;   // 100 pcs, Swaiba
    private Order $b;   // 300 pcs, Huma
    private Order $c;   // 50 pcs, Huma

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'owner@example.com')->first());
        $s = Customer::create(['name' => 'Swaiba Rahman', 'order_code' => 'SWA']);
        $h = Customer::create(['name' => 'Huma Qadeer', 'order_code' => 'HUM']);
        $mk = fn ($c, $no, $qty, $st = 'in_progress') => Order::create(['order_no' => $no, 'date' => '2026-11-01', 'customer_id' => $c->id, 'qty' => $qty, 'status' => $st]);
        $this->a = $mk($s, 'SWA-0006', 100);
        $this->b = $mk($h, 'HUM-0001', 300);
        $this->c = $mk($h, 'HUM-0002', 50);
    }

    private function buy(array $x = [])
    {
        return $this->postJson('/expenses', $x + ['type' => 'order', 'date' => '2026-11-05', 'amount' => 1000, 'payment_mode' => 'cash']);
    }

    public function test_single_order_still_works_the_old_way_and_one_ticked_order_is_not_a_split(): void
    {
        $this->buy(['order_id' => $this->a->id])->assertOk();
        $this->buy(['order_ids' => [$this->b->id], 'amount' => 250])->assertOk();

        [$e1, $e2] = Expense::orderBy('id')->get()->all();
        $this->assertSame($this->a->id, $e1->order_id);
        $this->assertSame($this->b->id, $e2->order_id);
        $this->assertSame(0, \DB::table('expense_orders')->count());
        $this->assertEquals(1000, $this->a->materialCost());
        $this->assertEquals(250, $this->b->materialCost());
    }

    public function test_a_shared_purchase_is_split_between_orders_and_nothing_else_changes(): void
    {
        $this->buy(['order_ids' => [$this->a->id, $this->b->id], 'shares' => [$this->a->id => 600, $this->b->id => 400]])->assertOk();

        $e = Expense::first();
        $this->assertNull($e->order_id);
        $this->assertEquals(600, $this->a->materialCost());
        $this->assertEquals(400, $this->b->materialCost());
        $this->assertEquals(0, $this->c->materialCost());
        $this->assertEquals(1000, $e->base_amount);
        $this->assertSame('HUM-0001, SWA-0006', $e->fresh()->orderNumbers());

        // the company's books still see ONE expense of 1,000 (no double counting)
        $this->assertEquals(1000, Expense::sum('base_amount'));
    }

    public function test_shares_must_add_up_and_every_order_needs_an_amount(): void
    {
        $two = ['order_ids' => [$this->a->id, $this->b->id]];
        $this->buy($two + ['shares' => [$this->a->id => 600, $this->b->id => 300]])->assertStatus(422)->assertJsonValidationErrors('order_ids');
        $this->buy($two + ['shares' => [$this->a->id => 1000, $this->b->id => 0]])->assertStatus(422)->assertJsonValidationErrors('order_ids');
        $this->buy($two)->assertStatus(422)->assertJsonValidationErrors('order_ids');
        $this->buy([])->assertStatus(422)->assertJsonValidationErrors('order_id');
        $this->assertSame(0, Expense::count());
    }

    public function test_foreign_currency_shares_add_up_to_the_base_amount(): void
    {
        $usd = Currency::where('code', 'USD')->first();
        $usd->update(['rate' => 278.33]);
        $this->buy(['currency_id' => $usd->id, 'amount' => 100, 'order_ids' => [$this->a->id, $this->b->id, $this->c->id],
            'shares' => [$this->a->id => 33.33, $this->b->id => 33.33, $this->c->id => 33.34]])->assertOk();

        $e = Expense::first();
        $this->assertEquals(27833.00, $e->base_amount);
        $this->assertEquals(27833.00, round((float) \DB::table('expense_orders')->where('expense_id', $e->id)->sum('base_amount'), 2));
        $this->assertEquals(round($this->a->materialCost() + $this->b->materialCost() + $this->c->materialCost(), 2), 27833.00);
    }

    public function test_editing_moves_between_single_shared_and_other_types(): void
    {
        $this->buy(['order_id' => $this->a->id])->assertOk();
        $e = Expense::first();

        $this->putJson("/expenses/{$e->id}", ['type' => 'order', 'date' => '2026-11-05', 'amount' => 1000, 'payment_mode' => 'cash',
            'order_ids' => [$this->a->id, $this->c->id], 'shares' => [$this->a->id => 800, $this->c->id => 200]])->assertOk();
        $this->assertNull($e->fresh()->order_id);
        $this->assertEquals(800, $this->a->materialCost());
        $this->assertEquals(200, $this->c->materialCost());

        $this->putJson("/expenses/{$e->id}", ['type' => 'order', 'date' => '2026-11-05', 'amount' => 1000, 'payment_mode' => 'cash', 'order_ids' => [$this->c->id]])->assertOk();
        $this->assertSame($this->c->id, $e->fresh()->order_id);
        $this->assertSame(0, \DB::table('expense_orders')->count());
        $this->assertEquals(0, $this->a->materialCost());

        $this->putJson("/expenses/{$e->id}", ['type' => 'unit', 'date' => '2026-11-05', 'amount' => 1000, 'payment_mode' => 'cash'])->assertOk();
        $this->assertNull($e->fresh()->order_id);
        $this->assertEquals(0, $this->c->materialCost());
    }

    public function test_order_page_list_filter_report_and_delete_rules(): void
    {
        $this->buy(['order_ids' => [$this->a->id, $this->b->id], 'shares' => [$this->a->id => 600, $this->b->id => 400], 'title' => 'Lining cloth'])->assertOk();
        $this->buy(['order_id' => $this->c->id, 'amount' => 70, 'title' => 'Zip fasteners'])->assertOk();
        $e = Expense::where('title', 'Lining cloth')->first();

        // order page: its own share, the full price and who it was bought with
        $this->get("/orders/{$this->a->id}")->assertOk()->assertSee('Lining cloth')->assertSee('600.00')->assertSee('HUM-0001, SWA-0006')->assertSee('1,000.00');
        // list shows both orders; the Order filter finds shared purchases too
        $this->get('/expenses')->assertOk()->assertSee('HUM-0001, SWA-0006');
        $this->get('/expenses?order_id='.$this->b->id)->assertOk()->assertSee('Lining cloth')->assertDontSee('Zip fasteners');
        $this->get('/expenses?q=SWA-0006')->assertOk()->assertSee('Lining cloth');
        $this->get("/expenses/{$e->id}")->assertOk()->assertSee('Shared by orders')->assertSee('400.00');
        $this->get('/reports/expenses?order_id='.$this->a->id)->assertOk()->assertSee('Lining cloth')->assertDontSee('Zip fasteners');
        $this->get('/reports/orders')->assertOk()->assertSee('SWA-0006');

        // an order that has a share cannot be deleted; deleting the expense removes the shares
        $this->delete("/orders/{$this->a->id}")->assertSessionHasErrors('delete');
        $this->delete("/expenses/{$e->id}")->assertRedirect();
        $this->assertSame(0, \DB::table('expense_orders')->count());
        $this->assertEquals(0, $this->a->materialCost());
    }

    public function test_the_form_lists_only_in_progress_orders_and_keeps_ones_already_on_the_expense(): void
    {
        $pending = Order::create(['order_no' => 'HUM-0007', 'date' => '2026-11-01', 'customer_id' => $this->b->customer_id, 'qty' => 5, 'status' => 'pending']);
        Order::create(['order_no' => 'HUM-0009', 'date' => '2026-11-01', 'customer_id' => $this->b->customer_id, 'qty' => 5, 'status' => 'delivered']);

        $this->get('/expenses/create')->assertOk()->assertSee('SWA-0006')->assertSee('HUM-0001')->assertSee('Select all in-progress orders')
            ->assertDontSee('HUM-0007')->assertDontSee('HUM-0009');
        $this->get('/expenses/create?type=order&order_id='.$this->a->id)->assertOk()
            ->assertViewHas('extra', fn ($x) => $x['orderPicker']['picked'] === [$this->a->id]);

        // an expense already booked to a pending order still shows that order when edited
        $this->buy(['order_id' => $pending->id])->assertOk();
        $e = Expense::first();
        $this->get("/expenses/{$e->id}/edit")->assertOk()->assertSee('HUM-0007');
    }

    public function test_paid_via_defaults_to_bank_for_a_new_expense_but_keeps_the_saved_value_on_edit(): void
    {
        $this->get('/expenses/create')->assertOk()->assertSee('\\u0022mode\\u0022:\\u0022bank\\u0022', false);

        $this->buy(['order_id' => $this->a->id, 'payment_mode' => 'cash'])->assertOk();
        $e = Expense::first();
        $this->get("/expenses/{$e->id}/edit")->assertOk()->assertSee('\\u0022mode\\u0022:\\u0022cash\\u0022', false);
    }

    public function test_expense_export_ends_with_a_total_per_type_and_a_grand_total(): void
    {
        $this->buy(['order_id' => $this->a->id, 'amount' => 1000])->assertOk();
        $this->buy(['order_id' => $this->b->id, 'amount' => 500])->assertOk();
        $this->buy(['type' => 'unit', 'amount' => 250])->assertOk();
        $this->buy(['type' => 'other', 'amount' => 100, 'date' => '2026-10-01'])->assertOk();

        \Maatwebsite\Excel\Facades\Excel::fake();
        \Maatwebsite\Excel\Facades\Excel::matchByRegex();
        $this->get('/expenses?export=xlsx')->assertOk();
        \Maatwebsite\Excel\Facades\Excel::assertDownloaded('/expenses-.*\.xlsx/', function (\App\Exports\TableExport $x) {
            $lines = collect($x->array())->filter(fn ($r) => in_array($r[0], ['Order (2)', 'Unit (1)', 'Other (1)', 'Total expenses (4)'], true))->mapWithKeys(fn ($r) => [$r[0] => $r[1]]);
            $this->assertSame(['Order (2)' => 'Rs 1,500.00', 'Unit (1)' => 'Rs 250.00', 'Other (1)' => 'Rs 100.00', 'Total expenses (4)' => 'Rs 1,850.00'], $lines->all());

            return true;
        });

        // the filter applies: only October → just the "other" expense
        \Maatwebsite\Excel\Facades\Excel::fake();
        \Maatwebsite\Excel\Facades\Excel::matchByRegex();
        $this->get('/expenses?export=xlsx&date_from=2026-10-01&date_to=2026-10-31')->assertOk();
        \Maatwebsite\Excel\Facades\Excel::assertDownloaded('/expenses-.*\.xlsx/', fn ($x) => collect($x->array())->contains(fn ($r) => $r[0] === 'Total expenses (1)' && $r[1] === 'Rs 100.00'));

        // PDF: totals are drawn under the table
        $html = view('exports.table', ['title' => 'Expenses', 'head' => ['a'], 'rows' => [['x']], 'totals' => ['Order (2)' => 'Rs 1,500.00', 'Total expenses (4)' => 'Rs 1,850.00']])->render();
        $this->assertStringContainsString('Total expenses (4)', $html);
        $this->assertStringContainsString('Rs 1,850.00', $html);
        $this->get('/expenses?export=pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
    }
}
