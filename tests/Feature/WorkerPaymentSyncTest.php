<?php

namespace Tests\Feature;

use App\Models\GarmentType;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerPayment;
use App\Models\WorkEntry;
use App\Support\Finance;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Worker Payments & Advances" (/worker-payments) and the "Record payment" box on a worker's profile (/workers/{id}) are two doors
 * into the SAME records: both post to the same route, one table, one ledger.
 */
class WorkerPaymentSyncTest extends TestCase
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
        WorkEntry::create(['worker_id' => $this->w->id, 'garment_type_id' => GarmentType::first()->id, 'date' => '2026-11-02', 'qty' => 1, 'rate' => 10000, 'amount' => 10000]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** the box on the worker's profile */
    private function fromProfile(float $amount, string $type = 'payment'): void
    {
        $this->post('/worker-payments', ['worker_id' => $this->w->id, 'type' => $type, 'date' => '2026-11-04', 'amount' => $amount, 'mode' => 'cash',
            'back_to_worker' => $this->w->id, 'period_from' => '', 'period_to' => ''])->assertRedirect(route('workers.show', $this->w->id));
    }

    /** the Worker Payments & Advances page */
    private function fromList(float $amount, string $type = 'payment')
    {
        return $this->post('/worker-payments', ['worker_id' => $this->w->id, 'type' => $type, 'date' => '2026-11-04', 'amount' => $amount, 'mode' => 'bank']);
    }

    public function test_the_profile_box_posts_to_the_very_same_route_as_the_payments_page(): void
    {
        $page = $this->get("/workers/{$this->w->id}")->assertOk()->getContent();
        $this->assertStringContainsString('action="'.route('worker-payments.store').'"', $page);      // not a separate endpoint
        $this->assertStringContainsString('name="back_to_worker"', $page);                              // the only difference: you land back on the profile
        $this->get('/worker-payments/create')->assertOk()->assertSee('action="'.route('worker-payments.store').'"', false);
    }

    public function test_a_payment_from_either_place_shows_up_in_both_places(): void
    {
        $this->fromProfile(3000);                       // Record payment box on the profile
        $this->fromList(2000, 'advance')->assertSessionHasNoErrors();   // Worker Payments & Advances page

        $this->assertSame(2, WorkerPayment::count());   // one table
        $this->assertSame(2, $this->w->payments()->count());

        // the Payments & Advances list shows both…
        $list = $this->get('/worker-payments')->assertOk();
        $list->assertSee('Rs 3,000.00')->assertSee('Rs 2,000.00')->assertSee('Ayesha');
        // …and so does the worker's ledger (balance 10,000 − 3,000 − 2,000 = 5,000)
        $profile = $this->get("/workers/{$this->w->id}")->assertOk();
        $profile->assertSee('Payment (Cash)')->assertSee('Advance (Bank)')->assertSee('Rs 5,000.00');
        $this->assertEquals(5000, $this->w->fresh()->balance());
    }

    public function test_editing_or_deleting_in_one_place_changes_the_other(): void
    {
        $this->fromProfile(3000);
        $p = WorkerPayment::first();

        // edit it from the Payments & Advances page → the profile ledger follows
        $this->put("/worker-payments/{$p->id}", ['worker_id' => $this->w->id, 'type' => 'payment', 'date' => '2026-11-04', 'amount' => 4000, 'mode' => 'cash'])->assertSessionHasNoErrors();
        $this->get("/workers/{$this->w->id}")->assertOk()->assertSee('Rs 6,000.00');                 // 10,000 − 4,000

        // delete from the list → gone from the profile too
        $this->delete("/worker-payments/{$p->id}")->assertRedirect();
        $this->assertEquals(10000, $this->w->fresh()->balance());
        $this->get("/workers/{$this->w->id}")->assertOk()->assertDontSee('Payment (Cash)');
    }

    public function test_every_other_figure_follows_whichever_door_was_used(): void
    {
        $this->fromProfile(3000);
        $this->fromList(2000)->assertSessionHasNoErrors();
        $cb = Finance::cashBank();
        $this->assertEquals(-3000, $cb['cash']['balance'] + 0);                      // 3,000 paid in cash (profile box)
        $this->assertEquals(-2000, $cb['bank']['balance'] + 0);                      // 2,000 paid by bank (payments page)
        $this->assertEquals(5000, Finance::periodStats(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'))['wages']);   // dashboard Wages paid
        $this->assertEquals(5000, Finance::workerBalances()[0]);                      // Payable card
        $this->get('/reports/cashbook?account=cash')->assertOk()->assertSee('Wages paid — Ayesha');
        $this->get('/reports/labour')->assertOk()->assertSee('5,000.00');
    }

    public function test_a_linked_worker_sees_payments_from_both_in_my_ledger(): void
    {
        $u = User::create(['name' => 'Ayesha', 'email' => 'ay@x.test', 'password' => 'secret123']);
        $u->assignRole('User');
        $this->w->update(['user_id' => $u->id]);
        $this->fromProfile(3000);
        $this->fromList(2000)->assertSessionHasNoErrors();
        WorkerPayment::query()->update(['created_by' => $u->id]);                       // visible under the worker's own-records scope

        $this->actingAs($u)->get('/my-ledger')->assertOk()->assertSee('Rs 5,000.00');
    }
}
