<?php

namespace Tests\Feature;

use App\Models\GarmentType;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkEntry;
use App\Models\WorkerPayment;
use App\Support\Finance;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Labour counts as a cost only when paid; the dashboard still shows what was earned and what is still unpaid. */
class LabourBadgesTest extends TestCase
{
    use RefreshDatabase;

    private Worker $w;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 12:00:00');
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'owner@example.com')->first());
        $this->w = Worker::create(['name' => 'Ayesha', 'type' => 'freelancer', 'pay_cycle' => 'weekly']);
        WorkEntry::create(['worker_id' => $this->w->id, 'garment_type_id' => GarmentType::first()->id, 'date' => '2026-11-03', 'qty' => 2, 'rate' => 2000, 'amount' => 4000]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function nov(): array
    {
        $s = Finance::periodStats(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));

        return [$s['costs'], $s['labour_earned'], Finance::workerBalances()[0]];
    }

    public function test_unpaid_work_is_not_a_cost_but_shows_as_earned_and_unpaid(): void
    {
        [$costs, $earned, $unpaid] = $this->nov();
        $this->assertEquals(0, $costs);              // nothing paid, so no labour cost yet
        $this->assertEquals(4000, $earned);          // …but 4,000 was earned
        $this->assertEquals(4000, $unpaid);          // …and is owed to the worker

        // 'Labour earned' is a badge; what is still owed is the Payable card at the top (no duplicate 'unpaid' badge)
        $this->get('/dashboard')->assertOk()->assertSee('Labour earned')->assertDontSee('Labour still unpaid')->assertSee('workers Rs 4,000.00');
    }

    public function test_paying_the_worker_moves_it_from_unpaid_to_cost_and_closes_it(): void
    {
        $pay = fn ($amt, $type = 'payment') => WorkerPayment::create(['worker_id' => $this->w->id, 'type' => $type, 'date' => '2026-11-04', 'amount' => $amt, 'mode' => 'cash']);

        $pay(1500);
        [$costs, $earned, $unpaid] = $this->nov();
        $this->assertEquals([1500, 4000, 2500], [$costs, $earned, $unpaid]);   // 1,500 paid = cost; 2,500 still owed

        $pay(2500);
        [$costs, $earned, $unpaid] = $this->nov();
        $this->assertEquals([4000, 4000, 0], [$costs, $earned, $unpaid]);      // fully paid: nothing unpaid, cost = what was earned
        $this->get("/workers/{$this->w->id}")->assertOk()->assertSee('Paid in full');
        $this->get('/dashboard')->assertOk()->assertDontSee('Labour still unpaid')->assertSee('workers Rs 0.00');   // Payable card is back to zero
    }

    public function test_an_advance_is_cash_paid_so_it_counts_when_paid_and_the_work_adjusts_it_later(): void
    {
        WorkerPayment::create(['worker_id' => $this->w->id, 'type' => 'advance', 'date' => '2026-11-04', 'amount' => 1000, 'mode' => 'cash']);
        [$costs, $earned, $unpaid] = $this->nov();
        $this->assertEquals([1000, 4000, 3000], [$costs, $earned, $unpaid]);   // 4,000 earned − 1,000 advance already paid
    }

    public function test_profit_and_loss_report_and_chart_use_wages_paid(): void
    {
        WorkerPayment::create(['worker_id' => $this->w->id, 'type' => 'payment', 'date' => '2026-11-04', 'amount' => 2500, 'mode' => 'bank']);
        $pl = Finance::profitLoss('2026-11-01', '2026-11-30');
        $this->assertEquals(2500, $pl['costs']);
        $this->assertContains('Wages & salaries paid', collect($pl['rows'])->pluck(0)->all());
        $this->get('/reports/profit_loss?date_from=2026-11-01&date_to=2026-11-30')->assertOk()->assertSee('Wages & salaries paid')->assertSee('2,500.00');
        $this->assertEquals(2500, collect(Finance::monthlySeries(1))->last()['costs']);
    }
}
