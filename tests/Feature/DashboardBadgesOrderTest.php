<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerPayment;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The period badges: Expenses, Wages paid and Total costs are separate and appear in a fixed, readable order. */
class DashboardBadgesOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 12:00:00');
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'owner@example.com')->first());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_badges_show_in_order_with_expenses_wages_and_total_costs_separately(): void
    {
        $c = Customer::create(['name' => 'Brand']);
        Invoice::create(['number' => 'T-1', 'customer_id' => $c->id, 'date' => '2026-11-02', 'subtotal' => 100000, 'total' => 100000]);
        Expense::create(['type' => 'other', 'date' => '2026-11-02', 'amount' => 55795, 'base_amount' => 55795, 'payment_mode' => 'bank']);
        $w = Worker::create(['name' => 'Ayesha', 'type' => 'freelancer', 'pay_cycle' => 'weekly']);
        WorkerPayment::create(['worker_id' => $w->id, 'type' => 'payment', 'date' => '2026-11-03', 'amount' => 16500, 'mode' => 'bank']);

        $html = $this->get('/dashboard')->assertOk()->getContent();
        $html = substr($html, strpos($html, 'Selected period'));      // only the period badges

        $order = ['Revenue (invoiced)', 'Payments received', 'Orders received', 'Labour earned', 'Expenses', 'Wages paid', 'Total costs', 'Net profit'];
        $pos = [];
        foreach ($order as $label) {
            $p = strpos($html, '>'.$label.'</p>');
            $this->assertNotFalse($p, "badge '$label' is missing");
            $pos[$label] = $p;
        }
        $sorted = $pos;
        asort($sorted);
        $this->assertSame($order, array_keys($sorted), 'badges are not in the expected order');

        // the three cost badges carry their own amounts: 55,795 + 16,500 = 72,295
        foreach (['>Expenses</p>' => 'Rs 55,795.00', '>Wages paid</p>' => 'Rs 16,500.00', '>Total costs</p>' => 'Rs 72,295.00'] as $needle => $amount) {
            $chunk = substr($html, strpos($html, $needle), 400);
            $this->assertStringContainsString($amount, $chunk, "$needle should show $amount");
        }
        $this->assertStringNotContainsString('Labour still unpaid', $html);    // no second "unpaid" badge — the Payable card covers it
    }
}
