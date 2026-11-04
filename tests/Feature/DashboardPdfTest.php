<?php

namespace Tests\Feature;

use App\Http\Controllers\DashboardController;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerPayment;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardPdfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 12:30:00');
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'owner@example.com')->first());
        $c = Customer::create(['name' => 'Brand A']);
        Invoice::create(['number' => 'INV-1', 'customer_id' => $c->id, 'date' => '2026-11-02', 'subtotal' => 100000, 'total' => 100000]);
        Order::create(['order_no' => 'ORD-1', 'date' => '2026-11-01', 'customer_id' => $c->id, 'qty' => 40, 'rate' => 1000, 'amount' => 40000, 'status' => 'in_progress', 'due_date' => '2026-10-30']);
        Expense::create(['type' => 'order', 'date' => '2026-11-03', 'amount' => 30000, 'base_amount' => 30000, 'payment_mode' => 'bank', 'title' => 'Laces']);
        $w = Worker::create(['name' => 'Ayesha', 'type' => 'freelancer', 'pay_cycle' => 'weekly']);
        WorkerPayment::create(['worker_id' => $w->id, 'type' => 'payment', 'date' => '2026-11-03', 'amount' => 5000, 'mode' => 'cash']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** the same data the dashboard page is built from, rendered through the PDF template */
    private function html(array $query = []): string
    {
        $m = new \ReflectionMethod(DashboardController::class, 'data');
        $m->setAccessible(true);
        $d = $m->invoke(app(DashboardController::class), Request::create('/dashboard/pdf', 'GET', $query));

        return view('dashboard.pdf', $d)->render();
    }

    public function test_the_pdf_downloads_for_the_period_on_screen(): void
    {
        $r = $this->get('/dashboard/pdf')->assertOk();
        $this->assertStringContainsString('application/pdf', $r->headers->get('content-type'));
        $this->assertStringContainsString('dashboard-2026-11-01_2026-11-30.pdf', $r->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', $r->getContent());
        $this->assertLessThan(300 * 1024, strlen($r->getContent()), 'the PDF should stay small');

        $this->get('/dashboard/pdf?period=year')->assertOk()->assertHeader('content-disposition', 'attachment; filename=dashboard-2026-01-01_2026-12-31.pdf');
        $this->get('/dashboard/pdf?period=custom&from=2026-10-01&to=2026-10-15')->assertOk()->assertHeader('content-disposition', 'attachment; filename=dashboard-2026-10-01_2026-10-15.pdf');
        $this->get('/dashboard/pdf?period=month&offset=-1')->assertOk()->assertHeader('content-disposition', 'attachment; filename=dashboard-2026-10-01_2026-10-31.pdf');
    }

    public function test_the_page_has_a_download_button_that_keeps_the_chosen_period(): void
    {
        $this->get('/dashboard')->assertOk()->assertSee('Download PDF')->assertSee('/dashboard/pdf', false);
        $this->get('/dashboard?period=year')->assertOk()->assertSee('/dashboard/pdf?period=year', false);
    }

    public function test_the_pdf_shows_the_same_figures_and_sections_as_the_dashboard(): void
    {
        $html = $this->html();
        foreach (['Lumiere Premium', 'Dashboard report', 'November 2026', 'Money position', 'Selected period', 'Revenue vs costs', 'Where the money went',
            'Orders', 'Overdue orders', 'ORD-1', 'Brand A', 'Wednesday, 04 November 2026 · 12:30'] as $s) {
            $this->assertStringContainsString($s, $html, "missing: $s");
        }
        // badges in the dashboard order, with the dashboard's numbers: revenue 100,000 · expenses 30,000 · wages paid 5,000 · costs 35,000 · net 65,000
        $order = ['Revenue (invoiced)', 'Payments received', 'Orders received', 'Labour earned', 'Expenses', 'Wages paid', 'Total costs', 'Net profit'];
        $pos = array_map(fn ($l) => strpos($html, '>'.$l.'</div>'), $order);
        $this->assertNotContains(false, $pos);
        $sorted = $pos;
        sort($sorted);
        $this->assertSame($sorted, $pos, 'badges are not in the dashboard order');
        foreach (['Rs 100,000.00', 'Rs 30,000.00', 'Rs 5,000.00', 'Rs 35,000.00', 'Rs 65,000.00'] as $money) {
            $this->assertStringContainsString($money, $html);
        }
        // and they agree with what the page itself shows
        $this->get('/dashboard')->assertOk()->assertSee('Rs 100,000.00')->assertSee('Rs 35,000.00')->assertSee('Rs 65,000.00');
    }

    public function test_it_follows_the_period_and_the_user_s_permissions(): void
    {
        $this->assertStringContainsString('October 2026', $this->html(['period' => 'month', 'offset' => -1]));
        $this->assertStringContainsString('Rs 0.00', $this->html(['period' => 'month', 'offset' => -1]));            // nothing happened in October

        // someone who may only see expenses gets only that part — no money position, no revenue
        $role = Role::findOrCreate('Clerk', 'web');
        $role->givePermissionTo(['expenses.view']);
        $u = User::create(['name' => 'Clerk', 'email' => 'clerk@x.test', 'password' => 'secret123']);
        $u->assignRole($role);
        $this->actingAs($u);
        $html = $this->html();
        $this->assertStringContainsString('Expenses', $html);
        $this->assertStringNotContainsString('Money position', $html);
        $this->assertStringNotContainsString('Revenue (invoiced)', $html);
        $this->assertStringNotContainsString('Overdue orders', $html);
        $this->get('/dashboard/pdf')->assertOk();
    }

    public function test_it_needs_a_login_and_handles_an_empty_database(): void
    {
        auth()->logout();
        $this->app['auth']->forgetGuards();
        $this->get('/dashboard/pdf')->assertRedirect('/login');

        \Illuminate\Support\Facades\DB::table('invoices')->delete();
        \Illuminate\Support\Facades\DB::table('expenses')->delete();
        \Illuminate\Support\Facades\DB::table('worker_payments')->delete();
        \Illuminate\Support\Facades\DB::table('orders')->delete();
        $this->actingAs(User::where('email', 'owner@example.com')->first());
        $html = $this->html();
        $this->assertStringContainsString('No invoices or costs in this period yet.', $html);
        $this->assertStringContainsString('No costs recorded in this period.', $html);
        $this->get('/dashboard/pdf')->assertOk();
    }
}
