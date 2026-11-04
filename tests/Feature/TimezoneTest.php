<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\User;
use App\Support\TimezoneShift;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The whole app runs in Pakistan Standard Time (Asia/Karachi, UTC+5). */
class TimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function owner(): User
    {
        $this->seed(DatabaseSeeder::class);

        return tap(User::where('email', 'owner@example.com')->first(), fn ($u) => $this->actingAs($u));
    }

    public function test_the_app_and_the_database_clock_both_run_in_pakistan_time(): void
    {
        $this->assertSame('Asia/Karachi', config('app.timezone'));
        $this->assertSame('+05:00', config('database.connections.mysql.timezone'));
        $this->assertSame('Asia/Karachi', now()->timezoneName);
        $this->assertSame(5 * 3600, now()->utcOffset() * 60);

        // PHP time and the database's own CURRENT_TIMESTAMP (used by e.g. the audit log) must be the same wall clock
        Carbon::setTestNow();
        $db = Carbon::parse(DB::selectOne('select now() n')->n);
        $this->assertLessThanOrEqual(3, abs($db->diffInSeconds(now(), false)), 'database clock and app clock disagree');
    }

    public function test_after_midnight_pakistan_time_the_app_already_shows_the_new_day(): void
    {
        $this->owner();
        Carbon::setTestNow(Carbon::parse('2026-11-04 00:30:00', 'Asia/Karachi'));   // 19:30 on 3 Nov in UTC — the old app showed "3 Nov" here
        $this->assertSame('2026-11-04', now()->toDateString());

        $this->get('/dashboard')->assertOk()->assertSee('Wednesday, 04 November 2026');
        $this->get('/expenses/create')->assertOk()->assertSee('2026-11-04', false);       // default date of a new entry
        $this->get('/work-entries/create')->assertOk()->assertSee('2026-11-04', false);
    }

    public function test_records_are_stamped_with_pakistan_time(): void
    {
        $this->owner();
        Carbon::setTestNow(Carbon::parse('2026-11-04 09:15:00', 'Asia/Karachi'));
        $e = Expense::create(['type' => 'other', 'date' => '2026-11-04', 'amount' => 10, 'base_amount' => 10, 'payment_mode' => 'cash']);
        $this->assertSame('2026-11-04 09:15:00', $e->fresh()->created_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-04 09:15:00', DB::table('expenses')->where('id', $e->id)->value('created_at'));   // stored as local wall-clock time
    }

    public function test_the_one_time_shift_moves_utc_times_forward_five_hours_and_back(): void
    {
        $this->owner();
        $c = Customer::create(['name' => 'Brand']);
        DB::table('customers')->where('id', $c->id)->update(['created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:05:00']);
        $e = Expense::create(['type' => 'other', 'date' => '2026-11-01', 'amount' => 10, 'base_amount' => 10, 'payment_mode' => 'cash']);
        DB::table('users')->update(['last_login_at' => '2026-11-03 05:37:43']);
        $dbRow = AuditLog::create(['action' => 'created', 'model' => 'X', 'model_id' => 1]);                                  // time from the database clock: already local
        DB::table('audit_logs')->where('id', $dbRow->id)->update(['created_at' => '2026-11-01 12:00:00']);
        $reset = AuditLog::create(['action' => 'reset', 'model' => 'System', 'model_id' => 0]);                               // written by PHP in UTC before the change
        DB::table('audit_logs')->where('id', $reset->id)->update(['created_at' => '2026-11-01 12:00:00']);

        $touched = TimezoneShift::apply(5);

        $this->assertSame('2026-11-01 15:00:00', DB::table('customers')->where('id', $c->id)->value('created_at'));
        $this->assertSame('2026-11-01 15:05:00', DB::table('customers')->where('id', $c->id)->value('updated_at'));
        $this->assertSame('2026-11-03 10:37:43', DB::table('users')->value('last_login_at'));
        $this->assertSame('2026-11-01', DB::table('expenses')->where('id', $e->id)->value('date'));                          // plain dates never move
        $this->assertSame('2026-11-01 12:00:00', DB::table('audit_logs')->where('id', $dbRow->id)->value('created_at'));     // audit log rows stay…
        $this->assertSame('2026-11-01 17:00:00', DB::table('audit_logs')->where('id', $reset->id)->value('created_at'));     // …except the ones PHP wrote
        $this->assertArrayHasKey('customers', $touched);
        $this->assertArrayNotHasKey('migrations', $touched);

        TimezoneShift::apply(-5);                                                                                              // and it can be undone
        $this->assertSame('2026-11-01 10:00:00', DB::table('customers')->where('id', $c->id)->value('created_at'));
        $this->assertSame('2026-11-03 05:37:43', DB::table('users')->value('last_login_at'));
        $this->assertSame('2026-11-01 12:00:00', DB::table('audit_logs')->where('id', $reset->id)->value('created_at'));
    }

    public function test_settings_tells_the_admin_which_time_zone_is_used(): void
    {
        $this->owner();
        $this->get('/settings')->assertOk()->assertSee('Pakistan Standard Time')->assertSee('Asia/Karachi');
    }
}
