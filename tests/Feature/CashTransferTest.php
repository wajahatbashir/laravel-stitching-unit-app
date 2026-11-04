<?php

namespace Tests\Feature;

use App\Models\CashTransfer;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\GarmentType;
use App\Models\PeriodClosure;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkEntry;
use App\Models\WorkerPayment;
use App\Support\Finance;
use App\Support\PeriodLock;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The situation reported from production: advance received in the bank, some expenses paid in cash. */
class CashTransferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 12:00:00');
        PeriodLock::flush();
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'owner@example.com')->first());

        $c = Customer::create(['name' => 'Brand']);
        CustomerPayment::create(['customer_id' => $c->id, 'date' => '2026-11-02', 'amount' => 100000, 'mode' => 'bank']);        // advance
        foreach ([['cash', 3970], ['bank', 51825]] as [$mode, $amt]) {
            Expense::create(['type' => 'other', 'date' => '2026-11-02', 'amount' => $amt, 'base_amount' => $amt, 'payment_mode' => $mode]);
        }
        $w = Worker::create(['name' => 'Ayesha', 'type' => 'freelancer', 'pay_cycle' => 'weekly']);
        WorkerPayment::create(['worker_id' => $w->id, 'type' => 'payment', 'date' => '2026-11-02', 'amount' => 16500, 'mode' => 'bank']);
        WorkEntry::create(['worker_id' => $w->id, 'garment_type_id' => GarmentType::first()->id, 'date' => '2026-11-03', 'qty' => 2, 'rate' => 2000, 'amount' => 4000]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        PeriodLock::flush();
        parent::tearDown();
    }

    public function test_without_a_transfer_cash_is_negative_and_the_dashboard_explains_why(): void
    {
        $cb = Finance::cashBank();
        $this->assertEquals(-3970, $cb['cash']['balance']);
        $this->assertEquals(31675, $cb['bank']['balance']);
        $this->assertEquals(27705, $cb['cash']['balance'] + $cb['bank']['balance']);      // the total is right; only the split is not

        // the dashboard shows ONE number (total money) and only explains the split because cash went negative
        $this->get('/dashboard')->assertOk()->assertSee('Money available')->assertSee('Rs 27,705.00')->assertDontSee('Cash in hand')
            ->assertSee('bank Rs 31,675.00')->assertSee('cash Rs -3,970.00')->assertSee('record the cash you took from the bank');
    }

    public function test_recording_the_cash_withdrawal_fixes_cash_and_bank_without_touching_costs_or_total(): void
    {
        $costsBefore = Finance::profitLoss('2026-11-01', '2026-11-30')['costs'];
        $net = Finance::balanceSheet()['net'];

        $this->post('/cash-transfers', ['direction' => 'bank_to_cash', 'date' => '2026-11-02', 'amount' => 3970, 'reference' => 'ATM'])->assertSessionHasNoErrors();
        $this->assertSame(1, CashTransfer::count());

        $cb = Finance::cashBank();
        $this->assertEquals(0, $cb['cash']['balance']);
        $this->assertEquals(27705, $cb['bank']['balance']);                                // bank is reduced by what became cash
        $this->assertEquals($costsBefore, Finance::profitLoss('2026-11-01', '2026-11-30')['costs']);   // not an expense
        $this->assertEquals($net, Finance::balanceSheet()['net']);

        // take some extra cash for tomorrow, then bring part of it back
        $this->post('/cash-transfers', ['direction' => 'bank_to_cash', 'date' => '2026-11-03', 'amount' => 1000])->assertSessionHasNoErrors();
        $this->post('/cash-transfers', ['direction' => 'cash_to_bank', 'date' => '2026-11-04', 'amount' => 400])->assertSessionHasNoErrors();
        $cb = Finance::cashBank();
        $this->assertEquals(600, $cb['cash']['balance']);
        $this->assertEquals(27105, $cb['bank']['balance']);
        $this->assertEquals(27705, $cb['cash']['balance'] + $cb['bank']['balance']);       // total money never changes

        $this->get('/dashboard')->assertOk()->assertSee('Rs 27,705.00')->assertSee('bank Rs 27,105.00 · cash Rs 600.00')->assertDontSee('record the cash you took from the bank');

        // no cash held at all → just the one number, no split
        $this->post('/cash-transfers', ['direction' => 'cash_to_bank', 'date' => '2026-11-04', 'amount' => 600])->assertSessionHasNoErrors();
        $this->get('/dashboard')->assertOk()->assertSee('Rs 27,705.00')->assertDontSee('cash Rs');
        $this->get('/reports/cash')->assertOk();
    }

    public function test_cash_as_of_a_date_only_counts_transfers_up_to_that_date(): void
    {
        $this->post('/cash-transfers', ['direction' => 'bank_to_cash', 'date' => '2026-11-04', 'amount' => 3970])->assertSessionHasNoErrors();
        $this->assertEquals(-3970, Finance::cashBank('2026-11-03')['cash']['balance']);
        $this->assertEquals(0, Finance::cashBank('2026-11-04')['cash']['balance']);
    }

    public function test_total_costs_tile_explains_the_difference_from_the_expenses_page(): void
    {
        $s = Finance::periodStats(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));
        // labour counts when PAID: the 4,000 of work entered but unpaid is not a cost yet; the 16,500 paid to workers is
        $this->assertEquals(72295, $s['costs']);                                           // 55,795 expenses + 16,500 wages paid
        $this->assertEquals(55795, $s['expense_costs']);
        $this->assertEquals(16500, $s['labour_costs']);

        $this->get('/dashboard')->assertOk()->assertSee('Rs 72,295.00')->assertSee('Rs 55,795.00')->assertSee('Rs 16,500.00');
        $this->get('/expenses')->assertOk()->assertSee('Rs 55,795.00');                    // the Expenses page lists expense entries only
    }

    public function test_form_validation_permissions_and_month_close(): void
    {
        $this->get('/cash-transfers/create')->assertOk()->assertSee('Took cash out of the bank')->assertSee('Only moves money between cash and bank');
        $this->post('/cash-transfers', ['direction' => 'sideways', 'date' => '2026-11-02', 'amount' => 10])->assertSessionHasErrors('direction');
        $this->post('/cash-transfers', ['direction' => 'bank_to_cash', 'date' => '2026-11-02', 'amount' => 0])->assertSessionHasErrors('amount');
        $this->assertSame(0, CashTransfer::count());

        $this->post('/cash-transfers', ['direction' => 'bank_to_cash', 'date' => '2026-11-02', 'amount' => 500])->assertSessionHasNoErrors();
        $t = CashTransfer::first();
        $this->put("/cash-transfers/{$t->id}", ['direction' => 'bank_to_cash', 'date' => '2026-11-02', 'amount' => 700])->assertSessionHasNoErrors();
        $this->assertEquals(700, $t->fresh()->amount);
        $this->get('/cash-transfers')->assertOk()->assertSee('Bank → cash in hand')->assertSee('Rs 700.00');
        $this->get('/dashboard')->assertSee('/cash-transfers', false);                      // menu link

        PeriodClosure::create(['month' => '2026-11-01']);
        PeriodLock::flush();
        $this->postJson('/cash-transfers', ['direction' => 'bank_to_cash', 'date' => '2026-11-05', 'amount' => 10])->assertStatus(422)->assertJsonValidationErrors('period');

        $u = User::create(['name' => 'Worker', 'email' => 'w@x.test', 'password' => 'secret123']);
        $u->assignRole('User');
        $this->actingAs($u)->get('/cash-transfers')->assertForbidden();
    }
}
