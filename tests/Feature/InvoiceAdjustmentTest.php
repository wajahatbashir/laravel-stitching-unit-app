<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\InvoiceAdjustment;
use App\Models\PeriodClosure;
use App\Models\User;
use App\Support\Finance;
use App\Support\PeriodLock;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Invoice adjustment: the customer settled part of the balance with services instead of money.
 * Hand-calculated: invoice Rs 10,000, Rs 3,000 received in cash/bank, Rs 7,000 adjusted -> balance 0, no cash moved,
 * revenue (net of the adjustment) 3,000, receivables 0, balance sheet still ties to the P&L.
 */
class InvoiceAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private Customer $c;
    private Invoice $inv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        PeriodLock::flush();
        $this->actingAs(User::where('email', 'owner@example.com')->first());
        $this->c = Customer::create(['name' => 'Swaiba Rahman', 'order_code' => 'SWA']);
        $this->inv = Invoice::create(['number' => 'INV-0001', 'customer_id' => $this->c->id, 'date' => '2026-11-10', 'subtotal' => 10000, 'total' => 10000]);
        CustomerPayment::create(['customer_id' => $this->c->id, 'invoice_id' => $this->inv->id, 'date' => '2026-11-11', 'amount' => 3000, 'mode' => 'bank']);
    }

    private function adjust(float $amount = 7000, array $x = [])
    {
        return $this->post("/invoices/{$this->inv->id}/adjustments", $x + ['amount' => $amount, 'date' => '2026-11-20', 'reason' => 'Services provided by the client']);
    }

    public function test_adjusting_the_balance_settles_the_invoice_without_moving_cash(): void
    {
        $cash = Finance::cashBank();
        $this->assertEquals(7000, $this->inv->balance());
        $this->assertSame('partial', $this->inv->status());

        $this->adjust()->assertSessionHasNoErrors();

        $inv = $this->inv->fresh();
        $this->assertEquals(0, $inv->balance());
        $this->assertEquals(3000, $inv->paid());          // paid is only real money
        $this->assertEquals(7000, $inv->adjusted());
        $this->assertSame('paid', $inv->status());
        $this->assertEquals(0, $this->c->receivable());
        $this->assertEquals(0, Finance::receivables());
        $this->assertEquals(0, Finance::invoiceBalances()[$inv->id]['balance']);
        $this->assertEquals($cash, Finance::cashBank());   // no money moved

        // revenue is net of the adjustment: 10,000 invoiced - 7,000 = 3,000; the P&L shows both lines
        $pl = Finance::profitLoss('2026-11-01', '2026-11-30');
        $this->assertEquals(3000, $pl['revenue']);
        $this->assertSame(['Revenue (invoiced)', 10000.0], [$pl['rows'][0][0], (float) $pl['rows'][0][1]]);
        $this->assertEquals(-7000, $pl['rows'][1][1]);
        $this->assertEquals(0, Finance::balanceSheet()['recon']['difference']);   // balance sheet still ties to the P&L
    }

    public function test_cannot_adjust_more_than_the_balance_or_without_a_reason(): void
    {
        $this->adjust(7000.01)->assertSessionHasErrors('amount');
        $this->adjust(100, ['reason' => ''])->assertSessionHasErrors('reason');
        $this->adjust(0)->assertSessionHasErrors('amount');
        $this->assertSame(0, InvoiceAdjustment::count());

        $this->adjust(2000)->assertSessionHasNoErrors();
        $this->adjust(5000.01)->assertSessionHasErrors('amount');      // balance is now 5,000
        $this->adjust(5000)->assertSessionHasNoErrors();
        $this->assertEquals(0, $this->inv->fresh()->balance());
        $this->adjust(1)->assertSessionHasErrors('amount');            // nothing left to adjust
    }

    public function test_removing_an_adjustment_makes_the_amount_due_again(): void
    {
        $this->adjust()->assertSessionHasNoErrors();
        $a = InvoiceAdjustment::first();

        $this->delete("/invoices/{$this->inv->id}/adjustments/{$a->id}")->assertSessionHasNoErrors();
        $this->assertEquals(7000, $this->inv->fresh()->balance());
        $this->assertSame('partial', $this->inv->fresh()->status());
        $this->assertEquals(10000, Finance::profitLoss('2026-11-01', '2026-11-30')['revenue']);
    }

    public function test_page_pdf_statement_and_reports_show_the_adjustment(): void
    {
        $this->adjust()->assertSessionHasNoErrors();

        $this->get("/invoices/{$this->inv->id}")->assertOk()->assertSee('Adjustment')->assertSee('Services provided by the client')->assertSee('7,000.00');
        $html = view('invoices.pdf', ['inv' => Invoice::with('customer', 'order', 'items')->find($this->inv->id)])->render();
        $this->assertStringContainsString('Adjustment', $html);
        $this->assertStringContainsString('7,000.00', $html);
        $this->get("/invoices/{$this->inv->id}/pdf")->assertOk();

        $this->get('/reports/customer_ledger?customer_id='.$this->c->id)->assertOk()->assertSee('Services provided by the client');
        $this->get("/documents/statement/{$this->c->id}")->assertOk();
        $this->get('/reports/receivables')->assertOk();
        $this->get('/reports/profit_loss?date_from=2026-11-01&date_to=2026-11-30')->assertOk()->assertSee('customer adjustments');
        $this->get('/invoices')->assertOk();
        $this->get('/dashboard')->assertOk();
    }

    public function test_invoice_with_an_adjustment_cannot_be_deleted_and_a_closed_month_blocks_changes(): void
    {
        $this->adjust()->assertSessionHasNoErrors();
        $this->delete("/invoices/{$this->inv->id}")->assertSessionHasErrors('delete');

        PeriodClosure::create(['month' => '2026-11-01']);
        PeriodLock::flush();
        $a = InvoiceAdjustment::first();
        $this->delete("/invoices/{$this->inv->id}/adjustments/{$a->id}")->assertSessionHasErrors('period');
        $this->assertSame(1, InvoiceAdjustment::count());
        // an admin can override a closed month with a written reason
        $this->delete("/invoices/{$this->inv->id}/adjustments/{$a->id}", ['override_reason' => 'Entered by mistake'])->assertSessionHasNoErrors();
        $this->assertSame(0, InvoiceAdjustment::count());
    }

    public function test_customer_advance_cannot_be_applied_beyond_the_balance_after_an_adjustment(): void
    {
        $this->adjust(6000)->assertSessionHasNoErrors();   // balance 1,000
        CustomerPayment::create(['customer_id' => $this->c->id, 'date' => '2026-11-12', 'amount' => 5000, 'mode' => 'bank']);   // an advance

        $this->post("/invoices/{$this->inv->id}/apply-advance", ['amount' => 1500])->assertSessionHasErrors('amount');
        $this->post("/invoices/{$this->inv->id}/apply-advance", ['amount' => 1000])->assertSessionHasNoErrors();
        $this->assertEquals(0, $this->inv->fresh()->balance());
    }
}
