<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Investment;
use App\Models\Investor;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Support\Finance;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorPayablesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Vendor $lace;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-07 12:00:00');
        \App\Support\PeriodLock::flush();
        $this->seed(DatabaseSeeder::class);
        $this->owner = User::where('email', 'owner@example.com')->first();
        $this->lace = Vendor::create(['name' => 'Lace House', 'phone' => '0300']);
        $this->actingAs($this->owner);
        // some cash in the bank so balances are easy to read
        Investment::create(['investor_id' => Investor::create(['name' => 'I'])->id, 'type' => 'investment', 'mode' => 'bank', 'date' => '2026-09-01', 'amount' => 100000]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function bill(float $amt, string $date, ?string $due = null, array $x = [])
    {
        return $this->postJson('/expenses', ['type' => 'other', 'date' => $date, 'amount' => $amt, 'payment_mode' => 'credit', 'vendor_id' => $this->lace->id, 'due_date' => $due] + $x);
    }

    public function test_credit_purchase_needs_a_vendor_and_keeps_its_due_date(): void
    {
        $this->postJson('/expenses', ['type' => 'other', 'date' => '2026-10-01', 'amount' => 500, 'payment_mode' => 'credit'])
            ->assertStatus(422)->assertJsonValidationErrors('vendor_id');
        $this->bill(1000, '2026-09-01', '2026-10-01')->assertOk();
        $e = Expense::first();
        $this->assertSame('credit', $e->payment_mode);
        $this->assertSame('2026-10-01', $e->due_date->toDateString());

        // a due date is dropped when the purchase is not on credit
        $this->putJson("/expenses/{$e->id}", ['type' => 'other', 'date' => '2026-09-01', 'amount' => 1000, 'payment_mode' => 'cash', 'due_date' => '2026-10-01', 'vendor_id' => $this->lace->id])->assertOk();
        $this->assertNull($e->fresh()->due_date);
        $this->get('/expenses/create')->assertOk()->assertSee('On credit');
    }

    public function test_money_effects_cost_now_cash_when_paid_liability_in_between(): void
    {
        $this->bill(1000, '2026-10-02')->assertOk();
        $before = Finance::cashBank()['bank']['balance'];
        $this->assertEquals(100000, $before);                                  // no cash left yet
        $this->assertEquals(1000, Finance::profitLoss('2026-10-01', '2026-10-31')['costs']);   // …but it is already a cost
        $this->assertEquals(1000, Finance::vendorPayables());
        $this->assertEquals(1000, $this->lace->payable());

        $net = Finance::balanceSheet()['net'];
        $this->postJson('/vendor-payments', ['vendor_id' => $this->lace->id, 'date' => '2026-10-05', 'amount' => 400, 'mode' => 'bank', 'reference' => 'TX1'])->assertOk();
        $this->assertEquals(99600, Finance::cashBank()['bank']['balance']);     // cash left when we paid
        $this->assertEquals(600, $this->lace->payable());
        $this->assertEquals($net, Finance::balanceSheet()['net']);              // paying a bill does not change net worth
        $this->assertEquals(600, Finance::balanceSheet()['vendors']);

        // a cash purchase still works as before
        $this->postJson('/expenses', ['type' => 'other', 'date' => '2026-10-03', 'amount' => 50, 'payment_mode' => 'cash'])->assertOk();
        $this->assertEquals(600, Finance::vendorPayables());
    }

    public function test_oldest_bills_are_paid_first_and_aging_buckets(): void
    {
        $this->bill(1000, '2026-08-01', '2026-09-01')->assertOk();   // 36 days late
        $this->bill(500, '2026-09-20', '2026-10-20')->assertOk();    // not yet due
        $this->postJson('/vendor-payments', ['vendor_id' => $this->lace->id, 'date' => '2026-10-05', 'amount' => 1200, 'mode' => 'cash'])->assertOk();

        $open = $this->lace->openBills();
        $this->assertCount(1, $open);
        $this->assertEquals(300, $open[0]['left']);                            // first bill fully paid, second has 300 left
        $this->assertSame('2026-10-20', $open[0]['due']->toDateString());
        $this->assertEquals(300, $this->lace->payable());

        // paying more than we owe = paid ahead
        $this->postJson('/vendor-payments', ['vendor_id' => $this->lace->id, 'date' => '2026-10-06', 'amount' => 500, 'mode' => 'cash'])->assertOk();
        $this->assertEquals(-200, $this->lace->payable());
        $this->assertSame([], $this->lace->openBills());

        $this->get("/vendors/{$this->lace->id}")->assertOk()->assertSee('Paid ahead')->assertSee('Ledger');
    }

    public function test_pages_reports_exports_and_overdue_aging(): void
    {
        $this->bill(1000, '2026-08-01', '2026-09-01')->assertOk();            // 36 days late
        $this->bill(500, '2026-09-20', '2026-10-20')->assertOk();
        $this->get("/vendors/{$this->lace->id}")->assertOk()->assertSee('Unpaid bills')->assertSee('days late')->assertSee('1–30')->assertSee('Rs 1,000.00');
        $this->get("/vendors/{$this->lace->id}?export=xlsx")->assertOk();
        $this->get("/vendors/{$this->lace->id}?export=pdf")->assertOk();
        $this->get('/vendors')->assertOk()->assertSee('Remaining')->assertSee('Rs 1,500.00');
        $this->get('/vendor-payments')->assertOk();
        $this->get('/reports/vendor_payables')->assertOk()->assertSee('Lace House')->assertSee('OVERDUE')->assertSee('Rs 1,500.00');
        $this->get('/reports/vendor_payables?export=xlsx')->assertOk();
        $this->get('/reports/balance_sheet')->assertOk()->assertSee('Payable to vendors');
        $this->get('/dashboard')->assertOk()->assertSee('vendors');
    }

    public function test_vendor_with_bills_or_payments_cannot_be_deleted_and_month_close_applies(): void
    {
        $this->bill(100, '2026-10-01')->assertOk();
        $this->delete("/vendors/{$this->lace->id}")->assertSessionHasErrors('delete');

        \App\Models\PeriodClosure::create(['month' => '2026-08-01']);
        \App\Support\PeriodLock::flush();
        $this->postJson('/vendor-payments', ['vendor_id' => $this->lace->id, 'date' => '2026-08-10', 'amount' => 10, 'mode' => 'cash'])
            ->assertStatus(422)->assertJsonValidationErrors('period');
        $this->assertSame(0, VendorPayment::count());
    }

    public function test_due_bill_alert_is_sent_once_per_run(): void
    {
        $this->bill(1000, '2026-08-01', '2026-10-09')->assertOk();            // due in 2 days
        $this->bill(700, '2026-08-02', '2026-12-01')->assertOk();             // far away → no alert
        $this->artisan('lumiere:due-orders')->assertSuccessful();
        $notes = $this->owner->notifications()->get()->filter(fn ($n) => str_contains($n->data['title'], 'Vendor bill'));
        $this->assertCount(1, $notes);
        $this->assertStringContainsString('Lace House', $notes->first()->data['title']);
    }

    public function test_partly_on_credit_splits_cash_paid_now_from_the_vendor_bill(): void
    {
        // Rs 1,000 purchase: 400 paid now from the bank, 600 on credit
        $this->postJson('/expenses', ['type' => 'other', 'date' => '2026-10-02', 'amount' => 1000, 'payment_mode' => 'partial', 'paid_now' => 400, 'paid_via' => 'bank',
            'vendor_id' => $this->lace->id, 'due_date' => '2026-10-20', 'payee' => 'Lace House counter'])->assertOk();
        $e = Expense::first();
        $this->assertSame('partial', $e->payment_mode);
        $this->assertEquals(400, $e->paid_now);
        $this->assertSame('Lace House counter', $e->payee);             // "Paid to" stays available
        $this->assertSame('2026-10-20', $e->due_date->toDateString());

        $this->assertEquals(99600, Finance::cashBank()['bank']['balance']);          // only the paid-now part left the bank
        $this->assertEquals(1000, Finance::profitLoss('2026-10-01', '2026-10-31')['costs']); // the whole purchase is a cost
        $this->assertEquals(600, $this->lace->payable());
        $this->assertEquals(600, Finance::vendorPayables());
        $this->assertEquals(600, $this->lace->openBills()[0]['left']);

        $this->postJson('/vendor-payments', ['vendor_id' => $this->lace->id, 'date' => '2026-10-05', 'amount' => 600, 'mode' => 'cash'])->assertOk();
        $this->assertEquals(0, $this->lace->payable());
        $this->assertSame([], $this->lace->openBills());

        $this->get("/vendors/{$this->lace->id}")->assertOk()->assertSee('credit part of');
        $this->get("/expenses/{$e->id}")->assertOk()->assertSee('Partly on credit')->assertSee('Rs 400.00')->assertSee('Rs 600.00');
        $this->get('/expenses')->assertOk()->assertSee('Partly on credit');

        // switching back to a plain cash expense forgets the split
        $this->putJson("/expenses/{$e->id}", ['type' => 'other', 'date' => '2026-10-02', 'amount' => 1000, 'payment_mode' => 'cash', 'paid_now' => 400, 'paid_via' => 'bank'])->assertOk();
        $this->assertNull($e->fresh()->paid_now);
        $this->assertEquals(-600, Finance::vendorPayables());   // no bill left, but the 600 already paid now counts as paid ahead
    }

    public function test_partly_on_credit_needs_a_sensible_paid_now_and_a_vendor(): void
    {
        $base = ['type' => 'other', 'date' => '2026-10-02', 'amount' => 1000, 'payment_mode' => 'partial', 'paid_via' => 'cash', 'vendor_id' => $this->lace->id];
        $this->postJson('/expenses', $base)->assertStatus(422)->assertJsonValidationErrors('paid_now');                      // nothing paid
        $this->postJson('/expenses', $base + ['paid_now' => 1000])->assertStatus(422)->assertJsonValidationErrors('paid_now'); // everything paid
        $this->postJson('/expenses', $base + ['paid_now' => 1500])->assertStatus(422)->assertJsonValidationErrors('paid_now');
        $this->postJson('/expenses', ['vendor_id' => null, 'paid_now' => 200] + $base)->assertStatus(422)->assertJsonValidationErrors('vendor_id');
        $this->assertSame(0, Expense::count());
    }

    public function test_fully_on_credit_clears_paid_to_and_the_form_switches_it_off(): void
    {
        $this->postJson('/expenses', ['type' => 'other', 'date' => '2026-10-02', 'amount' => 500, 'payment_mode' => 'credit', 'vendor_id' => $this->lace->id, 'payee' => 'Someone'])->assertOk();
        $this->assertNull(Expense::first()->payee);
        $this->get('/expenses/create')->assertOk()->assertSee('Partly on credit')->assertSee(":disabled=\"mode === 'credit'\"", false);
    }

    public function test_vendor_payment_form_shows_what_is_due_and_lists_are_aligned(): void
    {
        $this->bill(1000, '2026-08-01', '2026-09-01')->assertOk();
        $this->bill(500, '2026-09-20', '2026-10-20')->assertOk();
        $this->postJson('/vendor-payments', ['vendor_id' => $this->lace->id, 'date' => '2026-10-05', 'amount' => 300, 'mode' => 'cash'])->assertOk();

        $res = $this->get('/vendor-payments/create?vendor_id='.$this->lace->id)->assertOk();
        $res->assertSee('Rs 1,200.00')->assertSee('Unpaid bills')->assertSee('Fill the full due amount');      // 1500 billed − 300 paid
        $p = \App\Models\VendorPayment::first();
        $this->get("/vendor-payments/{$p->id}/edit")->assertOk()->assertSee('Due before this payment');

        // money columns: right-aligned header over right-aligned cells; vendor list shows paid + remaining
        $this->get('/vendors')->assertOk()->assertSee('th text-end', false)->assertSee('Paid')->assertSee('Remaining')->assertSee('Rs 1,500.00')->assertSee('Rs 300.00')->assertSee('Rs 1,200.00');
        $this->get('/vendor-payments')->assertOk()->assertSee('th text-end', false);
    }

    public function test_credit_bills_show_paid_partly_paid_unpaid_or_overdue_status(): void
    {
        $this->bill(1000, '2026-08-01', '2026-09-01')->assertOk();   // due date already passed
        $this->bill(500, '2026-09-20', '2026-10-20')->assertOk();    // not due yet
        [$a, $b] = [Expense::orderBy('id')->first(), Expense::orderByDesc('id')->first()];
        $this->postJson('/expenses', ['type' => 'other', 'date' => '2026-10-01', 'amount' => 80, 'payment_mode' => 'cash'])->assertOk();   // not a credit bill
        $status = fn () => collect(\App\Support\VendorBills::map())->map->status->all();

        $this->assertSame([$a->id => 'overdue', $b->id => 'unpaid'], $status());
        $this->postJson('/vendor-payments', ['vendor_id' => $this->lace->id, 'date' => '2026-10-05', 'amount' => 300, 'mode' => 'cash'])->assertOk();
        $this->assertSame('overdue', $status()[$a->id]);                    // part paid but still late
        $this->postJson('/vendor-payments', ['vendor_id' => $this->lace->id, 'date' => '2026-10-06', 'amount' => 900, 'mode' => 'cash'])->assertOk();
        $this->assertSame([$a->id => 'paid', $b->id => 'partial'], $status()); // 1,200 paid: bill 1 cleared, bill 2 has 300 left
        $this->postJson('/vendor-payments', ['vendor_id' => $this->lace->id, 'date' => '2026-10-07', 'amount' => 300, 'mode' => 'cash'])->assertOk();
        $this->assertSame([$a->id => 'paid', $b->id => 'paid'], $status());

        $this->get('/expenses')->assertOk()->assertSee('Credit status')->assertSee('Paid');
        $this->get("/expenses/{$b->id}")->assertOk()->assertSee('Vendor bill')->assertSee('Paid to the vendor')->assertSee('Rs 500.00');
        $this->get("/vendors/{$this->lace->id}")->assertOk()->assertSee('badge', false);
        $this->get('/expenses?export=xlsx')->assertOk();

        // a part-paid-at-purchase bill is judged on its credit part only
        $this->postJson('/expenses', ['type' => 'other', 'date' => '2026-10-02', 'amount' => 1000, 'payment_mode' => 'partial', 'paid_now' => 400, 'paid_via' => 'cash',
            'vendor_id' => $this->lace->id, 'due_date' => '2026-12-01'])->assertOk();
        $c = Expense::orderByDesc('id')->first();
        $this->assertSame('unpaid', $status()[$c->id]);
        $this->assertEquals(600, \App\Support\VendorBills::map()[$c->id]['credit']);
        $this->get("/expenses/{$c->id}")->assertOk()->assertSee('Unpaid');
    }
}
