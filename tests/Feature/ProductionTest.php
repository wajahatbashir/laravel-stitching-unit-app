<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Delivery;
use App\Models\GarmentType;
use App\Models\Order;
use App\Models\ProductionReject;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAdjustment;
use App\Support\Finance;
use App\Support\Production;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->seed(DatabaseSeeder::class);
        $this->owner = User::where('email', 'owner@example.com')->first();
        $c = Customer::create(['name' => 'Brand A']);
        $this->order = Order::create(['order_no' => 'ORD-1', 'date' => '2026-09-20', 'customer_id' => $c->id, 'qty' => 1000, 'status' => 'pending',
            'collection_name' => 'Eid Lawn', 'due_date' => '2026-10-05']);
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function log(string $stage, int $qty, array $x = [])
    {
        return $this->postJson('/production-logs', ['order_id' => $this->order->id, 'stage' => $stage, 'date' => '2026-10-06', 'qty' => $qty] + $x);
    }

    public function test_stage_counts_progress_and_over_quantity_guard(): void
    {
        $this->log('cutting', 1000)->assertOk();
        $this->log('stitching', 400)->assertOk();
        $this->log('finishing', 120)->assertOk();
        $this->assertSame('in_progress', $this->order->fresh()->status);   // first log starts the order

        $s = Production::summary($this->order->fresh());
        $this->assertSame(['cutting' => 1000, 'stitching' => 400, 'finishing' => 120, 'quality' => 0, 'packing' => 0], $s['stages']);
        $this->assertSame('finishing', $s['current']);
        $this->assertSame(12, $s['percent']);

        // 10 % tolerance on the order quantity: 400 + 700 = 1100 ok, one more is refused
        $this->log('stitching', 700)->assertOk();
        $this->log('stitching', 1)->assertStatus(422)->assertJsonValidationErrors('qty');
        $this->log('stitching', 0)->assertStatus(422);
        $this->assertSame(1100, Production::summary($this->order->fresh())['stages']['stitching']);

        // editing an entry is checked against the rest, not against itself
        $e = \App\Models\ProductionLog::where('stage', 'finishing')->first();
        $this->putJson("/production-logs/{$e->id}", ['order_id' => $this->order->id, 'stage' => 'finishing', 'date' => '2026-10-06', 'qty' => 1100])->assertOk();
        $this->putJson("/production-logs/{$e->id}", ['order_id' => $this->order->id, 'stage' => 'finishing', 'date' => '2026-10-06', 'qty' => 1101])->assertStatus(422);

        // idempotent offline replay
        $uuid = 'b1f0c7a0-0000-4000-8000-0000000000aa';
        $this->log('packing', 10, ['uuid' => $uuid])->assertOk();
        $this->log('packing', 10, ['uuid' => $uuid])->assertOk();
        $this->assertSame(10, Production::summary($this->order->fresh())['stages']['packing']);
    }

    public function test_board_and_dashboard_show_stage_and_late_orders(): void
    {
        $this->log('cutting', 500);
        $this->log('stitching', 200);
        $this->get('/production')->assertOk()->assertSee('ORD-1')->assertSee('Stitching')->assertSee('days late')->assertSee('Eid Lawn');
        $this->get('/production?customer_id=999')->assertOk()->assertDontSee('ORD-1');
        $this->get('/dashboard')->assertOk()->assertSee('Overdue orders')->assertSee('2 days late');
        $this->get("/orders/{$this->order->id}")->assertOk()->assertSee('Production')->assertSee('Log pieces');
    }

    public function test_rejects_can_deduct_from_worker_pay_and_stay_in_sync(): void
    {
        $g = GarmentType::where('name', 'Shirt')->first();
        $g->update(['default_rate' => 150]);
        $w = Worker::create(['name' => 'Ayesha', 'type' => 'freelancer', 'pay_cycle' => 'weekly']);
        $this->post('/work-entries', ['worker_id' => $w->id, 'garment_type_id' => $g->id, 'date' => '2026-10-01', 'qty' => 20])->assertRedirect(); // earns 3000
        $this->assertEquals(3000, $w->fresh()->balance());

        $base = ['order_id' => $this->order->id, 'kind' => 'reject', 'stage' => 'stitching', 'date' => '2026-10-06', 'qty' => 5, 'worker_id' => $w->id, 'reason' => 'stains'];

        // a deduction needs a responsible worker
        $this->postJson('/production-rejects', ['worker_id' => null, 'deduct_amount' => 500] + $base)->assertStatus(422)->assertJsonValidationErrors('worker_id');

        $this->postJson('/production-rejects', $base + ['deduct_amount' => 500])->assertOk();
        $r = ProductionReject::first();
        $this->assertEquals(2500, $w->fresh()->balance());                       // earned − deduction
        $this->assertSame('deduction', WorkerAdjustment::first()->type);
        $this->get("/workers/{$w->id}")->assertOk()->assertSee('Deduction')->assertSee('stains');
        $this->assertEquals(2500, Finance::profitLoss('2026-10-01', '2026-10-31')['costs']);

        // changing the amount updates the same adjustment; clearing it removes it
        $this->putJson("/production-rejects/{$r->id}", $base + ['deduct_amount' => 800])->assertOk();
        $this->assertSame(1, WorkerAdjustment::count());
        $this->assertEquals(2200, $w->fresh()->balance());
        $this->putJson("/production-rejects/{$r->id}", $base + ['deduct_amount' => ''])->assertOk();
        $this->assertSame(0, WorkerAdjustment::count());
        $this->assertEquals(3000, $w->fresh()->balance());

        // deleting a reject removes its deduction as well
        $this->putJson("/production-rejects/{$r->id}", $base + ['deduct_amount' => 300])->assertOk();
        $this->assertEquals(2700, $w->fresh()->balance());
        $this->delete("/production-rejects/{$r->id}")->assertRedirect();
        $this->assertSame(0, WorkerAdjustment::count());
        $this->assertEquals(3000, $w->fresh()->balance());

        // rework is counted separately and needs no deduction
        $this->postJson('/production-rejects', ['kind' => 'rework'] + $base)->assertOk();
        $s = Production::summary($this->order->fresh());
        $this->assertSame([0, 5], [$s['rejected'], $s['rework']]);

        // manual bonus / deduction screen
        $this->postJson('/worker-adjustments', ['worker_id' => $w->id, 'type' => 'bonus', 'date' => '2026-10-06', 'amount' => 250, 'reason' => 'overtime'])->assertOk();
        $this->assertEquals(3250, $w->fresh()->balance());
    }

    public function test_deduction_needs_payment_permission_and_open_month(): void
    {
        $w = Worker::create(['name' => 'W', 'type' => 'freelancer', 'pay_cycle' => 'weekly']);
        $base = ['order_id' => $this->order->id, 'kind' => 'reject', 'date' => '2026-10-06', 'qty' => 1, 'worker_id' => $w->id];

        $worker = User::create(['name' => 'Floor', 'email' => 'f@x.com', 'password' => 'password123']);
        $worker->assignRole('User');                               // may log production, may not touch worker pay
        $this->actingAs($worker)->postJson('/production-rejects', $base + ['deduct_amount' => 100])->assertStatus(422)->assertJsonValidationErrors('deduct_amount');
        $this->actingAs($worker)->postJson('/production-rejects', $base)->assertOk();    // reject without a deduction is fine
        $this->actingAs($worker)->get('/deliveries')->assertForbidden();

        // a deduction dated in a closed month is blocked by month close
        $this->actingAs($this->owner);
        \App\Models\PeriodClosure::create(['month' => '2026-08-01']);
        \App\Support\PeriodLock::flush();
        $this->postJson('/production-rejects', ['date' => '2026-08-10', 'deduct_amount' => 50] + $base)->assertStatus(422)->assertJsonValidationErrors('period');
    }

    public function test_deliveries_challan_status_and_limits(): void
    {
        $send = fn (int $qty, array $x = []) => $this->postJson('/deliveries', ['order_id' => $this->order->id, 'date' => '2026-10-06', 'qty' => $qty, 'vehicle' => 'Bike 123', 'received_by' => 'Mr Ali'] + $x);

        $send(400)->assertOk();
        $d1 = Delivery::first();
        $this->assertSame('DC-0001', $d1->challan_no);
        $this->assertSame('in_progress', $this->order->fresh()->status);
        $this->assertSame(400, Production::summary($this->order->fresh())['delivered']);

        $send(701)->assertStatus(422)->assertJsonValidationErrors('qty');          // 400 + 701 > 1100
        $send(600)->assertOk();                                                     // completes the order
        $this->assertSame('delivered', $this->order->fresh()->status);
        $this->assertSame('DC-0002', Delivery::latest('id')->first()->challan_no);

        // duplicate challan numbers (stale offline form) are renumbered, not rejected
        $send(1, ['challan_no' => 'DC-0001'])->assertOk();
        $this->assertSame(3, Delivery::distinct('challan_no')->count());

        // pages and the PDF
        $this->get("/deliveries/{$d1->id}")->assertOk()->assertSee('DC-0001')->assertSee('Mr Ali')->assertSee('Challan PDF');
        $pdf = $this->get("/deliveries/{$d1->id}/pdf");
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->get('/deliveries')->assertOk()->assertSee('DC-0002');

        // deleting a delivery re-opens a fully delivered order
        $last = Delivery::latest('id')->first();
        $this->delete("/deliveries/{$last->id}")->assertRedirect();
        $this->delete('/deliveries/'.Delivery::where('qty', 600)->first()->id)->assertRedirect();
        $this->assertSame('in_progress', $this->order->fresh()->status);
    }

    public function test_reports_and_daily_overdue_alert(): void
    {
        $this->log('stitching', 300);
        $this->postJson('/production-rejects', ['order_id' => $this->order->id, 'kind' => 'reject', 'date' => '2026-10-06', 'qty' => 7])->assertOk();

        $this->get('/reports/production')->assertOk()->assertSee('ORD-1')->assertSee('LATE');
        $this->get('/reports/production?export=xlsx')->assertOk();
        $this->get('/reports/production?export=pdf')->assertOk();
        $this->get('/reports/quality')->assertOk()->assertSee('Rejected pieces');
        $this->get('/reports/quality?export=xlsx')->assertOk();

        $this->artisan('lumiere:due-orders')->assertSuccessful();
        $note = $this->owner->notifications()->get()->first(fn ($n) => str_contains($n->data['title'], 'late'));
        $this->assertNotNull($note);
        $this->assertStringContainsString('ORD-1', $note->data['title']);
    }
}
