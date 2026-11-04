<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerPayment;
use App\Support\Finance;
use App\Support\Ledger;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerDueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 10:00:00');
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'owner@example.com')->first());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function staff(array $x = []): Worker
    {
        $this->post('/workers', $x + ['name' => 'Sana', 'type' => 'salaried', 'pay_cycle' => 'monthly', 'monthly_salary' => 30000,
            'opening_due' => 8000, 'opening_date' => '2026-09-30', 'is_active' => 1])->assertSessionHasNoErrors();

        return Worker::orderByDesc('id')->first();
    }

    private function pay(Worker $w, string $type, float $amt, string $date, string $mode = 'cash'): void
    {
        $this->post('/worker-payments', ['worker_id' => $w->id, 'type' => $type, 'date' => $date, 'amount' => $amt, 'mode' => $mode])->assertSessionHasNoErrors();
    }

    public function test_worker_form_has_a_previous_due_field_and_saves_it(): void
    {
        $this->get('/workers/create')->assertOk()->assertSee('name="opening_due"', false)->assertSee('Previous due amount (we owe this worker)')->assertSee('Previous due as of');
        $w = $this->staff();
        $this->assertEquals(8000, $w->opening_due);
        $this->assertSame('2026-09-30', $w->opening_date->toDateString());
        $this->get("/workers/{$w->id}/edit")->assertOk()->assertSee('8000.00')->assertSee('2026-09-30');

        // empty date defaults to today; zero clears the date
        $b = $this->staff(['name' => 'Bushra', 'opening_due' => 1500, 'opening_date' => '']);
        $this->assertSame('2026-11-04', $b->opening_date->toDateString());
        $this->put("/workers/{$b->id}", ['name' => 'Bushra', 'type' => 'salaried', 'pay_cycle' => 'monthly', 'monthly_salary' => 30000, 'opening_due' => 0, 'is_active' => 1])->assertSessionHasNoErrors();
        $this->assertNull($b->fresh()->opening_date);
        $this->assertEquals(0, $b->fresh()->balance());

        $this->post('/workers', ['name' => 'X', 'type' => 'freelancer', 'pay_cycle' => 'weekly', 'opening_due' => 'abc'])->assertSessionHasErrors('opening_due');
        $this->get('/workers')->assertOk()->assertSee('Previous due')->assertSee('Rs 8,000.00');
    }

    public function test_monthly_staff_previous_due_salary_advance_and_payment_add_up(): void
    {
        $w = $this->staff();
        $this->assertEquals(8000, $w->balance());                                   // only the previous due so far

        $this->post('/salaries/generate', ['month' => '2026-10'])->assertRedirect();
        $this->assertEquals(38000, $w->fresh()->balance());                         // + October salary
        $this->pay($w, 'advance', 5000, '2026-10-10');
        $this->pay($w, 'payment', 10000, '2026-10-31', 'bank');
        $this->assertEquals(23000, $w->fresh()->balance());                         // 8,000 + 30,000 − 5,000 − 10,000

        $l = Ledger::build($w->fresh(), null, null);
        $this->assertEquals(0, $l['opening']);
        $this->assertEquals(8000, $l['prevDue']);
        $this->assertEquals(30000, $l['earned']);                                   // previous due is not "earned"
        $this->assertEquals(10000, $l['payments']);
        $this->assertEquals(5000, $l['advances']);
        $this->assertEquals(15000, $l['paid']);
        $this->assertEquals(23000, $l['closing']);
        $this->assertSame('partial', $l['status']);
        $this->assertSame('opening', $l['lines'][0]['kind']);                        // previous due is the first ledger line

        $this->get("/workers/{$w->id}")->assertOk()
            ->assertSee('Previous due')->assertSee('Advances given')->assertSee('Pending — balance to pay')
            ->assertSee('Rs 8,000.00')->assertSee('Rs 30,000.00')->assertSee('Rs 10,000.00')->assertSee('Rs 5,000.00')->assertSee('Rs 23,000.00')
            ->assertSee('Previous due (brought forward)')->assertSee('Partially paid')->assertSee('Salary Oct 2026')->assertSee('Advance (Cash)');

        // October only: the Sep-30 due is "brought forward", not a new charge in the period
        $l = Ledger::build($w->fresh(), '2026-10-01', '2026-10-31');
        $this->assertEquals(8000, $l['opening']);
        $this->assertEquals(0, $l['prevDue']);
        $this->assertEquals(23000, $l['closing']);
        // before the due date: nothing carried yet
        $l = Ledger::build($w->fresh(), null, '2026-09-29');
        $this->assertEquals(0, $l['prevDue']);
        $this->assertEquals(0, $l['closing']);
        $this->get("/workers/{$w->id}?date_from=2026-10-01&date_to=2026-10-31")->assertOk()->assertSee('Rs 23,000.00');
    }

    public function test_advance_before_salary_shows_as_advance_then_is_adjusted_by_the_salary(): void
    {
        $w = $this->staff(['name' => 'Hina', 'opening_due' => 0, 'opening_date' => '']);
        $this->pay($w, 'advance', 5000, '2026-11-02');
        $this->assertEquals(-5000, $w->fresh()->balance());
        $this->get("/workers/{$w->id}")->assertOk()->assertSee('Advance still to adjust')->assertSee('Rs 5,000.00');

        $this->post('/salaries/generate', ['month' => '2026-11'])->assertRedirect();
        $this->assertEquals(25000, $w->fresh()->balance());                         // 30,000 salary − 5,000 advance already given
        $this->get("/workers/{$w->id}")->assertOk()->assertSee('Pending — balance to pay')->assertSee('Rs 25,000.00');

        $this->pay($w, 'payment', 25000, '2026-11-30');
        $this->assertEquals(0, $w->fresh()->balance());
        $this->get("/workers/{$w->id}")->assertOk()->assertSee('Paid in full');
    }

    public function test_negative_previous_due_means_the_worker_owes_us(): void
    {
        $w = $this->staff(['name' => 'Zara', 'type' => 'freelancer', 'pay_cycle' => 'weekly', 'monthly_salary' => 0, 'opening_due' => -2000]);
        $this->assertEquals(-2000, $w->balance());
        $this->assertEquals(-2000, Ledger::build($w, null, null)['closing']);
    }

    public function test_previous_due_is_a_liability_not_a_cost(): void
    {
        $w = $this->staff();
        $this->post('/salaries/generate', ['month' => '2026-10'])->assertRedirect();
        $this->pay($w, 'advance', 5000, '2026-10-10');

        [$payable, $advances] = Finance::workerBalances();
        $this->assertEquals(33000, $payable);                                         // 8,000 + 30,000 − 5,000 owed to the worker
        $this->assertEquals(0, $advances);
        $this->assertEquals(33000, Finance::balanceSheet()['payable']);               // shows as payable on the balance sheet
        $this->assertEquals(5000, Finance::profitLoss('2026-10-01', '2026-10-31')['costs']);    // …a cost only when paid: the 5,000 advance (it happens to go toward the old due), not the unpaid salary
        $this->assertEquals(5000, Finance::previousDuePaid('2026-10-01', '2026-10-31'));       // information: that 5,000 settled part of the old due
        $this->get('/dashboard')->assertOk()->assertSee('Rs 33,000.00');
    }

    public function test_reports_payslip_and_voucher_use_the_previous_due(): void
    {
        $w = $this->staff();
        $this->post('/salaries/generate', ['month' => '2026-10'])->assertRedirect();
        $this->pay($w, 'advance', 5000, '2026-10-10');
        $p = WorkerPayment::first();

        $this->get('/reports/labour')->assertOk()->assertSee('Previous due')->assertSee('Rs 8,000.00')->assertSee('Rs 33,000.00');
        $this->get('/reports/labour?export=xlsx')->assertOk();
        $this->get("/reports/worker_statement?worker_id={$w->id}")->assertOk()->assertSee('Previous due (brought forward)')->assertSee('Pending balance');
        $this->get("/workers/{$w->id}?export=xlsx")->assertOk();
        $this->get("/documents/payslip/{$w->id}")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get("/documents/voucher/{$p->id}")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get("/worker-payments/{$p->id}")->assertOk();
    }

    public function test_a_linked_staff_member_sees_their_previous_due_in_my_ledger(): void
    {
        $u = User::create(['name' => 'Sana', 'email' => 'sana@x.test', 'password' => 'secret123']);
        $u->assignRole('User');
        $w = $this->staff();
        $w->update(['user_id' => $u->id]);
        $this->actingAs($u)->get('/my-ledger')->assertOk()->assertSee('Previous due')->assertSee('Rs 8,000.00');
    }
}
