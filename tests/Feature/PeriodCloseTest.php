<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\Investment;
use App\Models\Investor;
use App\Models\Invoice;
use App\Models\PeriodClosure;
use App\Models\SalaryEntry;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerPayment;
use App\Models\WorkEntry;
use App\Support\PeriodLock;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PeriodCloseTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-07 12:00:00');
        PeriodLock::flush();
        PeriodLock::allow(null);
        $this->seed(DatabaseSeeder::class);
        $this->owner = User::where('email', 'owner@example.com')->first();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        PeriodLock::flush();
        PeriodLock::allow(null);
        parent::tearDown();
    }

    private function exp(string $date, float $amt = 100): array
    {
        return ['type' => 'other', 'date' => $date, 'amount' => $amt, 'payment_mode' => 'cash'];
    }

    public function test_only_finished_months_can_be_closed_once(): void
    {
        $this->actingAs($this->owner);
        $this->post('/month-close', ['month' => '2026-10'])->assertSessionHasErrors('month');   // current month
        $this->post('/month-close', ['month' => '2026-11'])->assertSessionHasErrors('month');   // future
        $this->post('/month-close', ['month' => 'garbage'])->assertSessionHasErrors('month');
        $this->post('/month-close', ['month' => '2026-08', 'note' => 'bank reconciled'])->assertSessionHasNoErrors();
        $this->post('/month-close', ['month' => '2026-08'])->assertSessionHasErrors('month');   // already closed
        $this->assertSame(1, PeriodClosure::count());
        $this->get('/month-close')->assertOk()->assertSee('August 2026')->assertSee('bank reconciled');
        $this->assertTrue(PeriodLock::isClosed('2026-08-15'));
        $this->assertFalse(PeriodLock::isClosed('2025-08-15'));   // same month, other year
        $this->assertFalse(PeriodLock::isClosed('2026-09-01'));
    }

    public function test_closed_month_blocks_create_edit_move_and_delete(): void
    {
        $this->actingAs($this->owner);
        $open = Expense::create($this->exp('2026-09-10') + ['base_amount' => 100]);
        $this->post('/month-close', ['month' => '2026-08']);

        // create inside the closed month
        $this->postJson('/expenses', $this->exp('2026-08-15'))->assertStatus(422)->assertJsonValidationErrors('period');
        $this->assertSame(1, Expense::count());

        // move an open record INTO the closed month
        $this->putJson("/expenses/{$open->id}", $this->exp('2026-08-20'))->assertStatus(422)->assertJsonValidationErrors('period');
        $this->assertSame('2026-09-10', $open->fresh()->date->toDateString());

        // a record already in the closed month: edit, move out, delete
        PeriodLock::allow('seed data'); // test setup only
        $locked = Expense::create($this->exp('2026-08-05') + ['base_amount' => 100]);
        PeriodLock::allow(null);
        $this->putJson("/expenses/{$locked->id}", $this->exp('2026-08-05', 999))->assertStatus(422);
        $this->putJson("/expenses/{$locked->id}", $this->exp('2026-09-05'))->assertStatus(422);   // moving it out is also a change
        $this->delete("/expenses/{$locked->id}")->assertSessionHasErrors('period');
        $this->assertSame('100.00', $locked->fresh()->amount);

        // list shows a padlock, form shows the override box
        $this->get('/expenses')->assertOk()->assertSee('🔒', false);
        $this->get("/expenses/{$locked->id}/edit")->assertOk()->assertSee('override_reason', false)->assertSee('Closed month');
    }

    public function test_every_money_record_type_is_protected(): void
    {
        $c = Customer::create(['name' => 'B']);
        $w = Worker::create(['name' => 'W', 'type' => 'freelancer', 'pay_cycle' => 'weekly']);
        $g = \App\Models\GarmentType::first();
        $inv = Investor::create(['name' => 'I']);
        PeriodClosure::create(['month' => '2026-08-01']);
        PeriodLock::flush();

        $attempts = [
            'expense' => fn () => Expense::create($this->exp('2026-08-03') + ['base_amount' => 1]),
            'customer payment' => fn () => CustomerPayment::create(['customer_id' => $c->id, 'date' => '2026-08-03', 'amount' => 1, 'mode' => 'cash']),
            'worker payment' => fn () => WorkerPayment::create(['worker_id' => $w->id, 'date' => '2026-08-03', 'amount' => 1, 'mode' => 'cash', 'type' => 'payment']),
            'salary' => fn () => SalaryEntry::create(['worker_id' => $w->id, 'period_month' => '2026-08-01', 'amount' => 1]),
            'work entry' => fn () => WorkEntry::create(['worker_id' => $w->id, 'garment_type_id' => $g->id, 'date' => '2026-08-03', 'qty' => 1, 'rate' => 1, 'amount' => 1]),
            'invoice' => fn () => Invoice::create(['number' => 'X-1', 'customer_id' => $c->id, 'date' => '2026-08-03', 'total' => 1]),
            'investment' => fn () => Investment::create(['investor_id' => $inv->id, 'date' => '2026-08-03', 'amount' => 1, 'type' => 'investment', 'mode' => 'bank']),
        ];
        foreach ($attempts as $what => $try) {
            try {
                $try();
                $this->fail("$what in a closed month was allowed");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('period', $e->errors(), $what);
            }
        }
        // same records in an open month are fine
        $this->assertNotNull(Expense::create($this->exp('2026-09-03') + ['base_amount' => 1]));
        $this->assertNotNull(SalaryEntry::create(['worker_id' => $w->id, 'period_month' => '2026-09-01', 'amount' => 1]));
    }

    public function test_override_needs_permission_and_a_reason_and_is_audited(): void
    {
        $this->actingAs($this->owner);
        $this->post('/month-close', ['month' => '2026-08']);

        $this->postJson('/expenses', $this->exp('2026-08-15') + ['override_reason' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('override_reason');
        $this->postJson('/expenses', $this->exp('2026-08-15', 250) + ['override_reason' => 'Supplier bill arrived late'])->assertOk();
        $e = Expense::where('amount', 250)->first();
        $this->assertSame('2026-08-15', $e->date->toDateString());
        $log = AuditLog::where('action', 'override')->latest('id')->first();
        $this->assertSame('Expense', $log->model);
        $this->assertSame('Supplier bill arrived late', $log->changes['reason']);
        $this->assertSame('August 2026', $log->changes['month']);

        // the override lasts for that one request only
        $this->postJson('/expenses', $this->exp('2026-08-16'))->assertStatus(422);
        // editing with a reason works too
        $this->putJson("/expenses/{$e->id}", $this->exp('2026-08-15', 260) + ['override_reason' => 'amount corrected'])->assertOk();
        $this->assertSame('260.00', $e->fresh()->amount);

        // a user without period.override cannot use it, even with a reason
        $user = User::create(['name' => 'U', 'email' => 'u@x.com', 'password' => 'password123']);
        $user->assignRole('User');
        $this->actingAs($user)->postJson('/expenses', $this->exp('2026-08-17') + ['override_reason' => 'I would like to'])
            ->assertStatus(422)->assertJsonValidationErrors('period');
        $this->actingAs($user)->get('/month-close')->assertForbidden();
    }

    public function test_reopen_requires_a_reason_and_unlocks_the_month(): void
    {
        $this->actingAs($this->owner);
        $this->post('/month-close', ['month' => '2026-08']);
        $c = PeriodClosure::first();
        $this->post("/month-close/{$c->id}/reopen", ['reason' => 'x'])->assertSessionHasErrors('reason');
        $this->postJson('/expenses', $this->exp('2026-08-09'))->assertStatus(422);

        $this->post("/month-close/{$c->id}/reopen", ['reason' => 'missing supplier bill'])->assertSessionHasNoErrors();
        $this->postJson('/expenses', $this->exp('2026-08-09'))->assertOk();
        $this->get('/month-close')->assertOk()->assertSee('missing supplier bill');   // kept in the history
        $this->post("/month-close/{$c->id}/reopen", ['reason' => 'again again'])->assertNotFound();    // already open

        // closing it again creates a fresh closure; old history row stays
        $this->post('/month-close', ['month' => '2026-08'])->assertSessionHasNoErrors();
        $this->assertSame(2, PeriodClosure::count());
        $this->postJson('/expenses', $this->exp('2026-08-10'))->assertStatus(422);
    }

    public function test_salary_generation_respects_a_closed_month(): void
    {
        $this->actingAs($this->owner);
        Worker::create(['name' => 'Emp', 'type' => 'salaried', 'pay_cycle' => 'monthly', 'monthly_salary' => 20000]);
        $this->post('/month-close', ['month' => '2026-08']);
        $this->post('/salaries/generate', ['month' => '2026-08'])->assertSessionHasErrors('period');
        $this->assertSame(0, SalaryEntry::count());
        $this->post('/salaries/generate', ['month' => '2026-09'])->assertSessionHasNoErrors();
        $this->assertSame(1, SalaryEntry::count());
    }
}
