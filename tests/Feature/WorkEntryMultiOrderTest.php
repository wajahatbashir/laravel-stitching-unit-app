<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\GarmentType;
use App\Models\Order;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkEntry;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** One worker, one day, pieces on several orders: one work entry is saved per order line. */
class WorkEntryMultiOrderTest extends TestCase
{
    use RefreshDatabase;

    private Worker $w;
    private Order $a;
    private Order $b;
    private GarmentType $shirt;
    private GarmentType $kurta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        \App\Support\PeriodLock::flush();
        $this->actingAs(User::where('email', 'owner@example.com')->first());
        $c = Customer::create(['name' => 'Swaiba Rahman', 'order_code' => 'SWA']);
        $this->a = Order::create(['order_no' => 'SWA-0006', 'date' => '2026-11-01', 'customer_id' => $c->id, 'qty' => 50, 'status' => 'in_progress']);
        $this->b = Order::create(['order_no' => 'SWA-0007', 'date' => '2026-11-01', 'customer_id' => $c->id, 'qty' => 30, 'status' => 'in_progress']);
        $this->shirt = GarmentType::updateOrCreate(['name' => 'Shirt'], ['default_rate' => 100]);
        $this->kurta = GarmentType::updateOrCreate(['name' => 'Kurta'], ['default_rate' => 250]);
        $this->w = Worker::create(['name' => 'Asif', 'is_active' => true]);
    }

    private function submit(array $lines, array $x = [])
    {
        return $this->postJson('/work-entries', $x + ['worker_id' => $this->w->id, 'date' => '2026-11-10', 'lines' => $lines]);
    }

    public function test_one_submit_saves_one_entry_per_order_line_with_rate_card_or_typed_rate(): void
    {
        $this->submit([
            ['order_id' => $this->a->id, 'garment_type_id' => $this->shirt->id, 'qty' => 10],                       // rate from the card: 100
            ['order_id' => $this->b->id, 'garment_type_id' => $this->kurta->id, 'qty' => 4, 'rate' => 300],        // typed rate
            ['order_id' => '', 'garment_type_id' => $this->shirt->id, 'qty' => 2],                                  // no order
        ], ['notes' => 'late night'])->assertOk();

        $e = WorkEntry::orderBy('id')->get();
        $this->assertCount(3, $e);
        $this->assertSame([$this->a->id, $this->b->id, null], $e->pluck('order_id')->all());
        $this->assertEquals([1000, 1200, 200], $e->pluck('amount')->map(fn ($v) => (float) $v)->all());
        $this->assertEquals([100, 300, 100], $e->pluck('rate')->map(fn ($v) => (float) $v)->all());
        $this->assertSame(['late night'], $e->pluck('notes')->unique()->all());
        $this->assertSame(1, $e->pluck('worker_id')->unique()->count());

        // each order is costed for its own pieces; the worker is owed the sum
        $this->assertEquals(1000, $this->a->labourCost());
        $this->assertEquals(1200, $this->b->labourCost());
        $this->assertEquals(2400, $this->w->earned());
    }

    public function test_replay_with_the_same_uuid_saves_nothing_twice_and_every_line_gets_its_own_uuid(): void
    {
        $uuid = (string) \Str::uuid();
        $lines = [['order_id' => $this->a->id, 'garment_type_id' => $this->shirt->id, 'qty' => 10], ['order_id' => $this->b->id, 'garment_type_id' => $this->shirt->id, 'qty' => 5]];
        $this->submit($lines, ['uuid' => $uuid])->assertOk();
        $this->submit($lines, ['uuid' => $uuid])->assertOk();

        $this->assertSame(2, WorkEntry::count());
        $this->assertSame(2, WorkEntry::pluck('uuid')->unique()->count());
        $this->assertTrue(WorkEntry::where('uuid', $uuid)->exists());
    }

    public function test_one_bad_line_saves_nothing(): void
    {
        $this->submit([['order_id' => $this->a->id, 'garment_type_id' => $this->shirt->id, 'qty' => 10], ['order_id' => $this->b->id, 'garment_type_id' => $this->shirt->id, 'qty' => 0]])
            ->assertStatus(422)->assertJsonValidationErrors('lines.1.qty');
        $this->submit([['order_id' => 99999, 'garment_type_id' => $this->shirt->id, 'qty' => 1]])->assertStatus(422)->assertJsonValidationErrors('lines.0.order_id');
        $this->submit([])->assertStatus(422);
        $this->assertSame(0, WorkEntry::count());
    }

    public function test_closed_month_blocks_the_whole_submit(): void
    {
        \App\Models\PeriodClosure::create(['month' => '2026-11-01']);
        \App\Support\PeriodLock::flush();
        $this->submit([['order_id' => $this->a->id, 'garment_type_id' => $this->shirt->id, 'qty' => 10], ['order_id' => $this->b->id, 'garment_type_id' => $this->shirt->id, 'qty' => 5]])->assertStatus(422);
        $this->assertSame(0, WorkEntry::count());
    }

    public function test_single_flat_entry_and_editing_still_work_and_the_form_renders_both_ways(): void
    {
        $this->postJson('/work-entries', ['worker_id' => $this->w->id, 'date' => '2026-11-10', 'order_id' => $this->a->id, 'garment_type_id' => $this->shirt->id, 'qty' => 3])->assertOk();
        $e = WorkEntry::first();
        $this->assertEquals(300, $e->amount);

        $this->putJson("/work-entries/{$e->id}", ['worker_id' => $this->w->id, 'date' => '2026-11-10', 'order_id' => $this->b->id, 'garment_type_id' => $this->kurta->id, 'qty' => 2])->assertOk();
        $this->assertEquals(500, $e->fresh()->amount);

        $this->get('/work-entries/create')->assertOk()->assertSee('Add another order')->assertSee('lines[', false);
        $this->get('/work-entries/create?order_id='.$this->b->id)->assertOk()->assertSee('\\u0022order_id\\u0022:\\u0022'.$this->b->id.'\\u0022', false);
        $this->get("/work-entries/{$e->id}/edit")->assertOk()->assertDontSee('Add another order');
    }
}
