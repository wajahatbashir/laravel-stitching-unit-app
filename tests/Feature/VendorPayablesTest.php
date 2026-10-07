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
        $this->get('/vendors')->assertOk()->assertSee('We owe')->assertSee('Rs 1,500.00');
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
}
