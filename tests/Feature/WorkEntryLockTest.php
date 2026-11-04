<?php

namespace Tests\Feature;

use App\Models\GarmentType;
use App\Models\PeriodClosure;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerPayment;
use App\Models\WorkEntry;
use App\Support\PeriodLock;
use App\Support\WorkPayStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WorkEntryLockTest extends TestCase
{
    use RefreshDatabase;

    private User $super;
    private User $admin;
    private Worker $w;
    private int $g;

    protected function setUp(): void
    {
        parent::setUp();
        PeriodLock::flush();
        $this->seed(DatabaseSeeder::class);
        $this->super = User::where('email', 'owner@example.com')->first();
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@x.test', 'password' => 'secret123']);
        $this->admin->assignRole('Admin');
        $this->w = Worker::create(['name' => 'Ayesha', 'type' => 'freelancer', 'pay_cycle' => 'weekly']);
        $this->g = GarmentType::first()->id;
        $this->actingAs($this->super);
    }

    private function entry(string $date, float $amount, ?Worker $w = null): WorkEntry
    {
        return WorkEntry::create(['worker_id' => ($w ?? $this->w)->id, 'garment_type_id' => $this->g, 'date' => $date, 'qty' => 1, 'rate' => $amount, 'amount' => $amount]);
    }

    private function payload(WorkEntry $e, array $x = []): array
    {
        return $x + ['worker_id' => $e->worker_id, 'garment_type_id' => $e->garment_type_id, 'date' => $e->date->toDateString(), 'qty' => $e->qty, 'rate' => $e->rate];
    }

    public function test_payments_clear_entries_oldest_first_and_the_list_shows_paid_partly_paid_unpaid(): void
    {
        $this->w->update(['opening_due' => 500, 'opening_date' => '2026-09-01']);
        $a = $this->entry('2026-10-01', 3000);
        $b = $this->entry('2026-10-05', 2000);
        $c = $this->entry('2026-10-10', 1000);
        WorkerPayment::create(['worker_id' => $this->w->id, 'type' => 'payment', 'date' => '2026-10-12', 'amount' => 3000, 'mode' => 'cash']);
        WorkerPayment::create(['worker_id' => $this->w->id, 'type' => 'advance', 'date' => '2026-10-13', 'amount' => 1000, 'mode' => 'cash']);

        // 4,000 paid: previous due 500 first, then entry A (3,000), then 500 of entry B
        $m = WorkPayStatus::map();
        $this->assertSame('paid', $m[$a->id]['status']);
        $this->assertSame('partial', $m[$b->id]['status']);
        $this->assertEquals(500, $m[$b->id]['paid']);
        $this->assertEquals(1500, $m[$b->id]['left']);
        $this->assertSame('unpaid', $m[$c->id]['status']);

        $this->get('/work-entries')->assertOk()->assertSee('Payment')->assertSee('Paid')->assertSee('Partly paid')->assertSee('Unpaid');
    }

    public function test_editing_or_deleting_a_paid_entry_shows_a_warning(): void
    {
        $a = $this->entry('2026-10-01', 3000);
        WorkerPayment::create(['worker_id' => $this->w->id, 'type' => 'payment', 'date' => '2026-10-12', 'amount' => 3000, 'mode' => 'cash']);
        $b = $this->entry('2026-10-20', 1000);

        $this->get("/work-entries/{$a->id}/edit")->assertOk()->assertSee('Payment status')->assertSee('Changing or deleting this entry changes the worker balance');
        $this->get("/work-entries/{$b->id}/edit")->assertOk()->assertDontSee('Changing or deleting this entry changes the worker balance');   // unpaid: no warning
        $list = $this->get('/work-entries')->assertOk()->getContent();
        $this->assertSame(1, substr_count($list, 'already covered by payments') / 2);   // the warning text is in the delete confirm of the paid row (mobile + desktop copy)
    }

    public function test_admin_can_close_an_entry_and_then_it_is_locked_for_the_admin(): void
    {
        $e = $this->entry('2026-10-01', 3000);

        $this->actingAs($this->admin);
        $this->get("/work-entries/{$e->id}/edit")->assertOk()->assertSee('Entry status')->assertSee('Closed — locked');
        $this->put("/work-entries/{$e->id}", $this->payload($e, ['status' => 'closed']))->assertSessionHasNoErrors();
        $e->refresh();
        $this->assertSame('closed', $e->status);
        $this->assertNotNull($e->closed_at);
        $this->assertSame($this->admin->id, $e->closed_by);

        // locked: no editing, no re-opening, no deleting for the admin
        $this->get("/work-entries/{$e->id}/edit")->assertRedirect('/work-entries');
        $this->put("/work-entries/{$e->id}", $this->payload($e, ['qty' => 9, 'status' => 'closed']))->assertSessionHasErrors('status');
        $this->put("/work-entries/{$e->id}", $this->payload($e, ['status' => 'open']))->assertSessionHasErrors('status');
        $this->delete("/work-entries/{$e->id}")->assertSessionHasErrors('delete');
        $e->refresh();
        $this->assertSame('closed', $e->status);
        $this->assertEquals(1, $e->qty);
        $this->assertEquals(3000, $e->amount);

        $page = $this->get('/work-entries')->assertOk()->assertSee('🔒')->assertSee('Closed')->getContent();
        $this->assertStringNotContainsString("work-entries/{$e->id}/edit", $page);   // no edit link for a locked row
    }

    public function test_super_admin_can_still_edit_reopen_and_delete_a_locked_entry(): void
    {
        $e = $this->entry('2026-10-01', 3000);
        $e->update(['status' => 'closed']);                 // Super Admin may close too
        $this->assertSame('closed', $e->fresh()->status);

        $this->get("/work-entries/{$e->id}/edit")->assertOk()->assertSee('closed (locked)')->assertSee('because you are a Super Admin');
        $this->put("/work-entries/{$e->id}", $this->payload($e, ['qty' => 2, 'status' => 'closed']))->assertSessionHasNoErrors();
        $this->assertEquals(2, $e->fresh()->qty);
        $this->assertEquals(6000, $e->fresh()->amount);

        $this->put("/work-entries/{$e->id}", $this->payload($e->fresh(), ['status' => 'open']))->assertSessionHasNoErrors();
        $e->refresh();
        $this->assertSame('open', $e->status);
        $this->assertNull($e->closed_at);

        $e->update(['status' => 'closed']);
        $this->delete("/work-entries/{$e->id}")->assertSessionHasNoErrors();
        $this->assertNull(WorkEntry::find($e->id));
        $this->entry('2026-10-09', 100);                                            // the list needs a row to show the grid actions
        $this->get('/work-entries')->assertOk()->assertSee('Re-open selected')->assertSee('Lock selected');   // super admin gets lock and re-open
    }

    public function test_users_without_the_close_permission_cannot_close_anything(): void
    {
        $role = Role::findOrCreate('Supervisor', 'web');
        $role->givePermissionTo(['work_entries.view', 'work_entries.create', 'work_entries.edit', 'work_entries.delete', 'data.all']);
        $sup = User::create(['name' => 'Sup', 'email' => 'sup@x.test', 'password' => 'secret123']);
        $sup->assignRole($role);
        $e = $this->entry('2026-10-01', 3000);

        $this->actingAs($sup);
        $this->get("/work-entries/{$e->id}/edit")->assertOk()->assertDontSee('Entry status');
        $this->put("/work-entries/{$e->id}", $this->payload($e, ['qty' => 3, 'status' => 'closed']))->assertSessionHasNoErrors();   // field is ignored
        $this->assertSame('open', $e->fresh()->status);
        $this->post('/work-entries', $this->payload($e, ['status' => 'closed']))->assertSessionHasNoErrors();
        $this->assertSame(['open', 'open'], WorkEntry::orderBy('id')->pluck('status')->all());
        $this->post('/work-entries/bulk', ['action' => 'lock', 'ids' => [$e->id]])->assertForbidden();
        $this->get('/work-entries')->assertOk()->assertDontSee('name="ids[]"', false);   // no checkbox column
        $this->expectException(ValidationException::class);                             // and the model itself refuses (other code paths too)
        $e->fresh()->update(['status' => 'closed']);
    }

    public function test_bulk_lock_from_the_grid_with_checkboxes(): void
    {
        $a = $this->entry('2026-10-01', 1000);
        $b = $this->entry('2026-10-02', 2000);
        $c = $this->entry('2026-10-03', 3000);
        $already = $this->entry('2026-10-04', 4000);
        $already->update(['status' => 'closed']);
        $old = $this->entry('2026-09-15', 5000);
        PeriodClosure::create(['month' => '2026-09-01']);
        PeriodLock::flush();

        $this->actingAs($this->admin);
        $page = $this->get('/work-entries')->assertOk()->assertSee('name="ids[]"', false)->assertSee('Lock selected')->assertSee('bulkForm', false)->getContent();
        $this->assertStringNotContainsString('Re-open selected', $page);                  // un-locking is Super Admin only

        $this->post('/work-entries/bulk', ['action' => 'lock', 'ids' => [$a->id, $b->id, $c->id, $already->id, $old->id]])
            ->assertRedirect()->assertSessionHas('success', fn ($m) => str_contains($m, '3 work entries locked') && str_contains($m, '2 skipped'));
        $this->assertSame(['closed', 'closed', 'closed', 'closed', 'open'], WorkEntry::orderBy('id')->pluck('status')->all());   // month-closed one is left alone
        $this->assertSame($this->admin->id, $a->fresh()->closed_by);

        $this->post('/work-entries/bulk', ['action' => 'unlock', 'ids' => [$a->id]])->assertForbidden();      // admin cannot re-open
        $this->post('/work-entries/bulk', ['action' => 'lock', 'ids' => []])->assertSessionHasErrors('ids');
        $this->assertSame('closed', $a->fresh()->status);

        $this->actingAs($this->super);
        $this->post('/work-entries/bulk', ['action' => 'unlock', 'ids' => [$a->id, $b->id]])->assertSessionHas('success', fn ($m) => str_contains($m, '2 work entries re-opened'));
        $this->assertSame(['open', 'open', 'closed', 'closed'], WorkEntry::whereIn('id', [$a->id, $b->id, $c->id, $already->id])->orderBy('id')->pluck('status')->all());
    }

    public function test_locking_does_not_change_what_the_worker_is_owed(): void
    {
        $a = $this->entry('2026-10-01', 3000);
        $this->entry('2026-10-02', 1000);
        $before = $this->w->fresh()->balance();
        $a->update(['status' => 'closed']);
        $this->assertEquals($before, $this->w->fresh()->balance());
        $this->assertEquals(4000, $this->w->fresh()->earned());
    }
}
