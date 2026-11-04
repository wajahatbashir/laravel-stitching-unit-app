<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\CashTransfer;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\GarmentType;
use App\Models\Investment;
use App\Models\Investor;
use App\Models\Invoice;
use App\Models\PaymentAllocation;
use App\Models\SalaryEntry;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Models\Worker;
use App\Models\WorkerPayment;
use App\Models\WorkEntry;
use App\Support\Finance;
use App\Support\PeriodLock;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Senior Finance Manager" audit: one realistic month, every expected figure worked out by hand (see the numbers in the
 * comments), then the dashboard, every report and the balance sheet must agree with them and with each other.
 *
 *   BANK  in : investment 500,000 + customer payments 100,000 (advance) + 80,000 + transfer in 2,000   = 682,000
 *         out: expenses 30,000+40,000+20,000 + vendor payment 10,000 + wages 5,000+10,000 + transfer 15,000 = 130,000  → 552,000
 *   CASH  in : investment 50,000 + transfer 15,000                                                       =  65,000
 *         out: expenses 3,000 + 4,000 (paid now) + wages 1,000+4,000 + transfer 2,000                     =  14,000  →  51,000
 *   Receivable 130,000 · customer advances 60,000 · fixed assets 40,000 · vendors owed 21,000
 *   Workers owed 30,000 (W1 8,000 due + 12,000 work − 6,000 paid = 14,000; W2 30,000 salary − 14,000 = 16,000)
 *   Net worth 662,000 · capital 550,000 · earned 112,000.
 *   P&L (labour counted when PAID, old dues included) Nov: revenue 200,000 − (expenses 128,000 + wages paid 20,000) = 52,000;
 *   6,000 of the wages paid settled Ayesha's old due (information only).
 */
class FinanceAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 12:00:00');
        PeriodLock::flush();
        $this->seed(DatabaseSeeder::class);
        $this->owner = User::where('email', 'owner@example.com')->first();
        $this->actingAs($this->owner);
        $this->scenario();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        PeriodLock::flush();
        parent::tearDown();
    }

    private function exp(string $type, string $date, float $amt, string $mode, array $x = []): Expense
    {
        return Expense::create($x + ['type' => $type, 'date' => $date, 'amount' => $amt, 'base_amount' => $amt, 'payment_mode' => $mode, 'title' => ucfirst($type)]);
    }

    private function scenario(): void
    {
        $inv = Investor::create(['name' => 'Founder']);
        Investment::create(['investor_id' => $inv->id, 'type' => 'investment', 'mode' => 'bank', 'date' => '2026-11-01', 'amount' => 500000]);
        Investment::create(['investor_id' => $inv->id, 'type' => 'investment', 'mode' => 'cash', 'date' => '2026-11-01', 'amount' => 50000]);

        // customers: A pays an advance, gets invoiced, part direct payment + advance applied; B owes an old invoice
        $a = Customer::create(['name' => 'Brand A']);
        $b = Customer::create(['name' => 'Brand B']);
        $inv1 = Invoice::create(['number' => 'INV-1', 'customer_id' => $a->id, 'date' => '2026-11-05', 'subtotal' => 200000, 'total' => 200000]);
        Invoice::create(['number' => 'INV-2', 'customer_id' => $b->id, 'date' => '2026-10-20', 'subtotal' => 50000, 'total' => 50000]);
        $adv = CustomerPayment::create(['customer_id' => $a->id, 'date' => '2026-11-02', 'amount' => 100000, 'mode' => 'bank']);
        CustomerPayment::create(['customer_id' => $a->id, 'invoice_id' => $inv1->id, 'date' => '2026-11-06', 'amount' => 80000, 'mode' => 'bank']);
        PaymentAllocation::create(['customer_payment_id' => $adv->id, 'invoice_id' => $inv1->id, 'date' => '2026-11-07', 'amount' => 40000]);

        // expenses (incl. a machine that is also an asset, a credit purchase and a part-credit purchase)
        $this->exp('order', '2026-11-03', 30000, 'bank');
        $this->exp('unit', '2026-11-04', 40000, 'bank');
        Asset::create(['type' => 'machine', 'name' => 'Overlock', 'cost' => 40000, 'status' => 'active']);
        $this->exp('labour', '2026-11-04', 3000, 'cash');
        $this->exp('other', '2026-11-05', 20000, 'bank');
        $v = Vendor::create(['name' => 'Lace House']);
        $this->exp('order', '2026-11-08', 25000, 'credit', ['vendor_id' => $v->id, 'due_date' => '2026-12-01']);
        $this->exp('order', '2026-11-09', 10000, 'partial', ['vendor_id' => $v->id, 'paid_now' => 4000, 'paid_via' => 'cash', 'due_date' => '2026-12-05']);
        VendorPayment::create(['vendor_id' => $v->id, 'date' => '2026-11-12', 'amount' => 10000, 'mode' => 'bank']);

        CashTransfer::create(['date' => '2026-11-10', 'direction' => 'bank_to_cash', 'amount' => 15000]);
        CashTransfer::create(['date' => '2026-11-11', 'direction' => 'cash_to_bank', 'amount' => 2000]);

        // workers
        $g = GarmentType::first()->id;
        $w1 = Worker::create(['name' => 'Ayesha', 'type' => 'freelancer', 'pay_cycle' => 'weekly', 'opening_due' => 8000, 'opening_date' => '2026-10-31']);
        WorkEntry::create(['worker_id' => $w1->id, 'garment_type_id' => $g, 'date' => '2026-11-03', 'qty' => 6, 'rate' => 2000, 'amount' => 12000]);
        WorkerPayment::create(['worker_id' => $w1->id, 'type' => 'payment', 'date' => '2026-11-14', 'amount' => 5000, 'mode' => 'bank']);
        WorkerPayment::create(['worker_id' => $w1->id, 'type' => 'advance', 'date' => '2026-11-15', 'amount' => 1000, 'mode' => 'cash']);
        $w2 = Worker::create(['name' => 'Sana', 'type' => 'salaried', 'pay_cycle' => 'monthly', 'monthly_salary' => 30000]);
        SalaryEntry::create(['worker_id' => $w2->id, 'period_month' => '2026-11-01', 'amount' => 30000, 'bonus' => 0, 'deduction' => 0]);
        WorkerPayment::create(['worker_id' => $w2->id, 'type' => 'advance', 'date' => '2026-11-01', 'amount' => 4000, 'mode' => 'cash']);
        WorkerPayment::create(['worker_id' => $w2->id, 'type' => 'payment', 'date' => '2026-11-18', 'amount' => 10000, 'mode' => 'bank']);
    }

    public function test_cash_and_bank_balances_match_the_hand_calculation(): void
    {
        $cb = Finance::cashBank();
        $this->assertEquals([682000, 130000, 552000], [$cb['bank']['in'], $cb['bank']['out'], $cb['bank']['balance']]);
        $this->assertEquals([65000, 14000, 51000], [$cb['cash']['in'], $cb['cash']['out'], $cb['cash']['balance']]);
        $this->assertEquals(603000, $cb['bank']['balance'] + $cb['cash']['balance']);

        // as of a date: before the transfers and payments of mid-November
        $early = Finance::cashBank('2026-11-09');
        $this->assertEquals(500000 + 100000 + 80000 - 30000 - 40000 - 20000, $early['bank']['balance']);   // 590,000
        $this->assertEquals(39000, $early['cash']['balance']);               // 50,000 − 3,000 (food) − 4,000 (advance to Sana) − 4,000 (paid now) — the 10 Nov transfer is not in yet
    }

    public function test_receivables_advances_payables_and_workers_owed(): void
    {
        $this->assertEquals(130000, Finance::receivables());                // 250,000 invoiced − 80,000 paid − 40,000 advance applied
        $this->assertEquals(60000, Finance::customerAdvances());            // 100,000 − 40,000
        $this->assertEquals(21000, Finance::vendorPayables());              // 25,000 + 6,000 credit − 10,000 paid
        [$payable, $advances] = Finance::workerBalances();
        $this->assertEquals([30000, 0], [$payable, $advances]);             // 14,000 + 16,000
        $this->assertEquals(14000, Worker::where('name', 'Ayesha')->first()->balance());
        $this->assertEquals(16000, Worker::where('name', 'Sana')->first()->balance());
    }

    public function test_profit_and_loss_on_the_paid_labour_basis(): void
    {
        $pl = Finance::profitLoss('2026-11-01', '2026-11-30');
        $this->assertEquals(200000, $pl['revenue']);                         // INV-2 is October
        $this->assertEquals(128000, $pl['expense_costs']);                   // 30+40+3+20+25+10 (credit and part-credit count in full when bought)
        $this->assertEquals(20000, $pl['labour_costs']);                     // everything paid to workers (5+1+4+10), old dues included
        $this->assertEquals(6000, $pl['old_dues_paid']);                     // of which settled Ayesha's old due (information)
        $this->assertEquals(148000, $pl['costs']);
        $this->assertEquals(52000, $pl['net']);

        $october = Finance::profitLoss('2026-10-01', '2026-10-31');
        $this->assertEquals([50000, 0, 50000], [$october['revenue'], $october['costs'], $october['net']]);   // only INV-2 (Oct 20), no costs
        $all = Finance::profitLoss(null, null);
        $this->assertEquals(102000, $all['net']);                            // 250,000 − 128,000 − 20,000
    }

    public function test_balance_sheet_identity_and_reconciliation_to_profit_and_loss(): void
    {
        $b = Finance::balanceSheet();
        $this->assertEquals(40000, $b['fixed']);
        $this->assertEquals(773000, $b['assets']);                           // 40,000 + 51,000 + 552,000 + 130,000
        $this->assertEquals(662000, $b['net']);                              // 773,000 − (30,000 + 60,000 + 21,000)
        $this->assertEquals(550000, $b['capital']);
        $this->assertEquals(112000, $b['earned']);

        // the two statements must tie: earned = all-time P&L + fixed assets bought − labour earned but unpaid − dues at start
        $r = $b['recon'] ?? null;
        $this->assertNotNull($r, 'the balance sheet should carry a reconciliation to the profit & loss');
        $this->assertEquals(102000, $r['pl']);
        $this->assertEquals(40000, $r['fixed']);
        $this->assertEquals(22000, $r['labour_unpaid']);                     // earned 42,000 − paid 20,000
        $this->assertEquals(8000, $r['previous_due']);
        $this->assertEquals($b['earned'], $r['pl'] + $r['fixed'] - $r['labour_unpaid'] - $r['previous_due']);
        $this->assertEquals(0, $r['difference']);
    }

    public function test_dashboard_and_reports_show_the_same_numbers(): void
    {
        $dash = $this->get('/dashboard')->assertOk();
        $dash->assertSee('Rs 603,000.00');                                    // Money available
        $dash->assertSee('Rs 130,000.00');                                    // receivable
        $dash->assertSee('Rs 51,000.00');                                     // hint: cash held
        $page = $this->get('/reports/profit_loss?date_from=2026-11-01&date_to=2026-11-30')->assertOk();
        foreach (['200,000.00', '148,000.00', '52,000.00', 'Wages & salaries paid'] as $s) {
            $page->assertSee($s);
        }
        $this->get('/reports/balance_sheet')->assertOk()->assertSee('662,000.00')->assertSee('112,000.00')->assertSee('Payable to vendors');
        $this->get('/reports/expenses?date_from=2026-11-01&date_to=2026-11-30')->assertOk()->assertSee('128,000.00');
        $this->get('/reports/cash')->assertOk()->assertSee('552,000.00')->assertSee('51,000.00')->assertSee('603,000.00');
        $this->get('/reports/vendor_payables')->assertOk()->assertSee('21,000.00');
        $this->get('/reports/labour?date_from=2026-11-01&date_to=2026-11-30')->assertOk()->assertSee('30,000.00');
    }

    public function test_a_restricted_user_sees_the_same_company_totals(): void
    {
        $role = \Spatie\Permission\Models\Role::findOrCreate('Accountant', 'web');
        $role->givePermissionTo(['reports.profit_loss', 'reports.balance_sheet', 'reports.cash', 'reports.expenses']);   // no data.all → "own records only" elsewhere
        $u = User::create(['name' => 'Acc', 'email' => 'acc@x.test', 'password' => 'secret123']);
        $u->assignRole($role);

        $this->actingAs($u);
        $this->get('/reports/profit_loss?date_from=2026-11-01&date_to=2026-11-30')->assertOk()->assertSee('52,000.00')->assertSee('148,000.00');
        $this->get('/reports/balance_sheet')->assertOk()->assertSee('662,000.00');
        $this->get('/reports/cash')->assertOk()->assertSee('603,000.00');
        $this->get('/reports/expenses?date_from=2026-11-01&date_to=2026-11-30')->assertOk()->assertSee('128,000.00');
    }

    public function test_cash_book_ties_to_the_dashboard_balances_and_lists_every_movement(): void
    {
        foreach (['bank' => 552000.0, 'cash' => 51000.0] as $account => $closing) {
            $page = $this->get("/reports/cashbook?account=$account")->assertOk();
            $page->assertSee('Closing balance')->assertSee(number_format($closing, 2));
            $m = \App\Support\Finance::cashMovements($account);
            $this->assertEquals($closing, round($m->sum('in') - $m->sum('out'), 2));                       // closing = what the dashboard shows
            $this->assertEquals(\App\Support\Finance::cashBank()[$account]['balance'], $closing);
        }

        $bank = $this->get('/reports/cashbook?account=bank')->assertOk();
        foreach (['Investment — Founder', 'Customer payment — Brand A (advance)', 'Customer payment — Brand A (INV-1)', 'Cash taken out of the bank',
            'Cash deposited into the bank', 'Vendor payment — Lace House', 'Wages paid — Ayesha', 'Wages paid — Sana'] as $line) {
            $bank->assertSee($line);
        }
        $this->get('/reports/cashbook?account=cash')->assertOk()->assertSee('Advance to worker — Ayesha')->assertSee('Expense (part paid now)')->assertSee('Cash taken out of the bank');

        // a date range starts from the correct opening balance and ends at the balance of that day
        $range = $this->get('/reports/cashbook?account=bank&date_from=2026-11-10&date_to=2026-11-12')->assertOk();
        $range->assertSee('Opening balance')->assertSee('590,000.00')->assertSee('Closing balance');         // bank on 9 Nov (see test 1)
        $bal = \App\Support\Finance::cashBank('2026-11-12')['bank']['balance'];
        $range->assertSee(number_format($bal, 2));                                                           // 590,000 − 15,000 + 2,000 − 10,000 = 567,000
        $this->assertEquals(567000, $bal);

        foreach (['xlsx', 'pdf'] as $fmt) {
            $this->get("/reports/cashbook?account=bank&export=$fmt")->assertOk();
        }
    }

    public function test_monthly_summary_matches_the_profit_and_loss_month_by_month(): void
    {
        $page = $this->get('/reports/monthly?date_from=2026-10-01&date_to=2026-11-30')->assertOk();
        $page->assertSee('October 2026')->assertSee('November 2026');
        foreach (['50,000.00', '200,000.00', '148,000.00', '52,000.00', '128,000.00', '20,000.00', '6,000.00'] as $s) {   // wages paid 20,000 · of which old dues 6,000 · costs 128,000 + 20,000
            $page->assertSee($s);
        }
        $nov = \App\Support\Finance::profitLoss('2026-11-01', '2026-11-30');
        $oct = \App\Support\Finance::profitLoss('2026-10-01', '2026-10-31');
        $this->assertEquals(round($oct['net'] + $nov['net'], 2), 102000.0);                                  // = all-time P&L
        $page->assertSee('102,000.00')->assertSee('of which old dues');                              // total net of the two months
        $this->get('/reports/monthly')->assertOk();                                                          // default: last 12 months
        $this->get('/reports/monthly?export=xlsx')->assertOk();
    }

    public function test_receivables_ageing_per_customer(): void
    {
        $page = $this->get('/reports/receivables')->assertOk();
        $page->assertSee('Brand A')->assertSee('Brand B')->assertSee('130,000.00')->assertSee('80,000.00')->assertSee('50,000.00')->assertSee('60,000.00');
        $this->assertEquals(130000, \App\Support\Finance::receivables());
        $this->assertEquals(130000, \App\Support\Finance::overdueReceivables());                              // INV-1 is 15 days old, INV-2 31 days: both past their (invoice-date) due date
        $this->assertEquals(0, \App\Support\Finance::overpaid());

        $buckets = \App\Support\Finance::invoiceBalances()->keyBy('number');
        $this->assertEquals(80000, $buckets['INV-1']['balance']);
        $this->assertEquals(120000, $buckets['INV-1']['paid']);                                              // 80,000 direct + 40,000 advance applied
        $this->get('/reports/receivables?export=xlsx')->assertOk();
        $this->get('/dashboard')->assertOk()->assertSee('past due Rs 130,000.00')->assertSee('advances held Rs 60,000.00');
    }

    public function test_an_overpaid_invoice_is_a_customer_credit_and_never_hides_another_customers_debt(): void
    {
        $before = Finance::balanceSheet();
        $c = Customer::create(['name' => 'Brand C']);
        $inv = Invoice::create(['number' => 'INV-3', 'customer_id' => $c->id, 'date' => '2026-11-15', 'subtotal' => 100000, 'total' => 100000]);
        CustomerPayment::create(['customer_id' => $c->id, 'invoice_id' => $inv->id, 'date' => '2026-11-16', 'amount' => 150000, 'mode' => 'bank']);   // 50,000 too much

        $this->assertEquals(130000, Finance::receivables());                  // still exactly what A and B owe (not 130,000 − 50,000)
        $this->assertEquals(50000, Finance::overpaid());
        $after = Finance::balanceSheet();
        $this->assertEquals(50000, $after['overpaid']);
        $this->assertEquals($before['net'] + 100000, $after['net']);          // invoice 100,000 earned; the extra 50,000 is a liability, not profit
        $this->assertEquals(0, $after['recon']['difference']);
        $this->assertEquals($before['earned'] + 100000, $after['earned']);

        $this->get('/reports/receivables')->assertOk()->assertSee('Brand C')->assertSee('50,000.00')->assertSee('Overpaid');
        $this->get('/reports/balance_sheet')->assertOk()->assertSee('Customer overpayments')->assertSee('50,000.00');
        $this->get('/dashboard')->assertOk()->assertSee('overpaid Rs 50,000.00');
    }

    public function test_balance_sheet_page_shows_the_reconciliation_and_it_has_no_unexplained_difference(): void
    {
        $page = $this->get('/reports/balance_sheet')->assertOk();
        $page->assertSee('HOW EARNINGS RETAINED TIES TO THE PROFIT')->assertSee('Unexplained difference (should be 0.00)')
            ->assertSee('Add: machinery / furniture bought')->assertSee('Less: labour earned but not yet paid')->assertSee('Less: dues owed to workers from before the app')->assertSee('102,000.00')->assertSee('22,000.00');
        $this->assertEquals(0, Finance::balanceSheet()['recon']['difference']);

        // still ties after more activity: a credit-card style purchase, a refund-like withdrawal, a sold machine
        $this->exp('other', '2026-11-19', 7000, 'credit', ['vendor_id' => Vendor::first()->id]);
        Investment::create(['investor_id' => Investor::first()->id, 'type' => 'withdrawal', 'mode' => 'bank', 'date' => '2026-11-19', 'amount' => 10000]);
        Asset::first()->update(['status' => 'sold']);
        WorkerPayment::create(['worker_id' => Worker::first()->id, 'type' => 'payment', 'date' => '2026-11-19', 'amount' => 30000, 'mode' => 'bank']);   // overpays Ayesha → advance held
        $b = Finance::balanceSheet();
        $this->assertEquals(0, $b['recon']['difference'], 'balance sheet and profit & loss no longer tie');
    }

    public function test_new_reports_need_their_own_permission_and_appear_in_the_list(): void
    {
        $this->get('/reports')->assertOk()->assertSee('Cash Book')->assertSee('Monthly Summary')->assertSee('Customer Receivables');

        $u = User::create(['name' => 'Clerk', 'email' => 'clerk@x.test', 'password' => 'secret123']);
        $u->assignRole('User');
        $this->actingAs($u);
        foreach (['cashbook', 'monthly', 'receivables'] as $k) {
            $this->get("/reports/$k")->assertForbidden();
        }
        $admin = User::create(['name' => 'Adm', 'email' => 'adm@x.test', 'password' => 'secret123']);
        $admin->assignRole('Admin');
        $this->actingAs($admin);
        foreach (['cashbook', 'monthly', 'receivables'] as $k) {
            $this->get("/reports/$k")->assertOk();
        }
    }

    public function test_cash_flow_over_all_time_equals_cash_plus_bank_and_ignores_transfers(): void
    {
        $all = Finance::cashFlow(null, null);
        $this->assertEquals(603000, $all['net']);                               // = 552,000 bank + 51,000 cash
        $this->assertEquals(730000, $all['in']);                                // 500,000 + 50,000 invested + 100,000 + 80,000 received
        $this->assertEquals(127000, $all['out']);                               // expenses 30+40+3+20+4 = 97 · vendor 10 · wages 20  (transfers excluded)

        $nov = Finance::cashFlow('2026-11-01', '2026-11-30');
        $this->assertEquals($all, $nov);                                        // everything happened in November

        $page = $this->get('/reports/monthly?date_from=2026-11-01&date_to=2026-11-30')->assertOk();
        $page->assertSee('Net cash flow')->assertSee('730,000.00')->assertSee('127,000.00')->assertSee('603,000.00');
    }

    public function test_expense_report_splits_what_was_paid_from_bank_cash_and_credit(): void
    {
        $page = $this->get('/reports/expenses?date_from=2026-11-01&date_to=2026-11-30')->assertOk();
        // bank 30+40+20 = 90,000 · cash 3 + 4 (paid now) = 7,000 · credit 25 + 6 = 31,000 · total 128,000
        $page->assertSee('128,000.00')->assertSee('paid from the bank')->assertSee('90,000.00')->assertSee('paid in cash')->assertSee('7,000.00')
            ->assertSee('bought on credit')->assertSee('31,000.00')->assertSee('Partly on credit');
        $this->get('/reports/expenses?date_from=2026-11-01&date_to=2026-11-30&export=xlsx')->assertOk();
    }

    public function test_wages_paid_toward_dues_from_before_the_app_are_shown_as_a_memo(): void
    {
        // Ayesha owed 8,000 before the app; her 5,000 payment + 1,000 advance in November clear that old due first
        $this->assertEquals(6000, Finance::previousDuePaid('2026-11-01', '2026-11-30'));
        $this->assertEquals(6000, Finance::previousDuePaid(null, null));
        $this->assertEquals(0, Finance::previousDuePaid('2026-12-01', '2026-12-31'));                    // nothing paid in December
        // all 20,000 paid to workers is labour cost and cash out; the 6,000 is only called out as information
        $this->assertEquals(20000, Finance::profitLoss('2026-11-01', '2026-11-30')['labour_costs']);
        $this->assertEquals(127000, Finance::cashFlow('2026-11-01', '2026-11-30')['out']);               // cash flow untouched
        $this->assertEquals(20000, Finance::periodStats(\Carbon\Carbon::parse('2026-11-01'), \Carbon\Carbon::parse('2026-11-30'))['wages']);
        $this->get('/dashboard')->assertOk()->assertSee('of which Rs 6,000.00 paid on old dues')->assertSee('expenses + wages paid');
        $this->get('/reports/profit_loss?date_from=2026-11-01&date_to=2026-11-30')->assertOk()->assertSee('Memo: of the wages paid, toward dues from before the app')->assertSee('6,000.00');

        // a payment in a later period only counts for what is still left of the old due
        WorkerPayment::create(['worker_id' => Worker::where('name', 'Ayesha')->first()->id, 'type' => 'payment', 'date' => '2026-12-03', 'amount' => 5000, 'mode' => 'bank']);
        $this->assertEquals(2000, Finance::previousDuePaid('2026-12-01', '2026-12-31'));                  // 8,000 − 6,000 already settled
        $this->assertEquals(5000, Finance::profitLoss('2026-12-01', '2026-12-31')['labour_costs']);       // the whole 5,000 paid in December is labour cost (2,000 of it settled the old due)
        $this->assertEquals(8000, Finance::previousDuePaid(null, null));
    }
}
