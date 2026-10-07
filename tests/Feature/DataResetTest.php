<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Models\Worker;
use App\Services\BackupService;
use App\Services\DataReset;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** TRUNCATE commits implicitly, so (like BackupTest) this rebuilds the TEST database itself and refuses to run elsewhere. */
class DataResetTest extends TestCase
{
    private string $tmp;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('lumiere_test', config('database.connections.mysql.database'), 'refusing to run on a non-test database');
        $this->tmp = sys_get_temp_dir().'/lumiere-rs-'.uniqid();
        config(['backup.dir' => $this->tmp.'/backups', 'backup.files_dir' => $this->tmp.'/public']);
        $this->artisan('migrate:fresh', ['--seed' => true, '--force' => true]);
        File::ensureDirectoryExists($this->tmp.'/public/uploads/orders');
        File::ensureDirectoryExists($this->tmp.'/public/brand');
        $this->owner = User::where('email', 'owner@example.com')->first();
        $c = Customer::create(['name' => 'Demo Brand']);
        Order::create(['order_no' => 'ORD-0001', 'date' => '2026-10-01', 'customer_id' => $c->id, 'qty' => 10]);
        Worker::create(['name' => 'Demo', 'type' => 'freelancer', 'pay_cycle' => 'weekly']);
        file_put_contents($this->tmp.'/public/uploads/orders/p.jpg', 'x');
        file_put_contents($this->tmp.'/public/brand/logo.png', 'x');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    public function test_every_table_is_classified_as_wipe_or_keep(): void
    {
        $this->assertSame([], DataReset::unclassified(), 'new table: add it to DataReset::WIPE or ::KEEP');
    }

    public function test_only_the_super_admin_can_open_or_run_it(): void
    {
        $admin = User::create(['name' => 'Adm', 'email' => 'adm@x.test', 'password' => 'secret123']);
        $admin->assignRole('Admin');
        $this->actingAs($admin)->get('/reset-data')->assertForbidden();
        $this->post('/reset-data', ['confirm' => 'RESET', 'password' => 'secret123'])->assertForbidden();
        $this->assertSame(1, Customer::count());
        $this->get('/dashboard')->assertOk()->assertDontSee('/reset-data', false);

        $this->actingAs($this->owner)->get('/reset-data')->assertOk()->assertSee('Will be deleted');
        $this->get('/dashboard')->assertSee('/reset-data', false);
    }

    public function test_wrong_word_or_password_changes_nothing(): void
    {
        $this->actingAs($this->owner);
        $this->post('/reset-data', ['confirm' => 'reset', 'password' => 'ChangeMe@123'])->assertSessionHasErrors('confirm');
        $this->post('/reset-data', ['confirm' => 'RESET', 'password' => 'wrong'])->assertSessionHasErrors('password');
        $this->assertSame(1, Customer::count());
        $this->assertSame([], BackupService::list());
    }

    public function test_reset_clears_business_data_keeps_setup_and_takes_a_safety_backup(): void
    {
        Setting::put('business_name', 'Lumiere Test');
        $this->actingAs($this->owner);
        $this->get('/reset-data')->assertOk()->assertSee('Delete all test data');

        $this->post('/reset-data', ['confirm' => 'RESET', 'password' => 'ChangeMe@123'])->assertRedirect('/dashboard')->assertSessionHas('success');

        $this->assertSame(0, Customer::count());
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Worker::count());
        $this->assertFileDoesNotExist($this->tmp.'/public/uploads/orders/p.jpg');
        $this->assertFileExists($this->tmp.'/public/brand/logo.png');          // branding kept
        $this->assertSame('Lumiere Test', Setting::get('business_name'));      // settings kept
        $this->assertSame(1, User::where('email', 'owner@example.com')->count());
        $this->assertGreaterThan(0, \App\Models\GarmentType::count());          // rate card kept
        $this->assertSame(1, \App\Models\AuditLog::where('action', 'reset')->count());

        $list = BackupService::list();
        $this->assertCount(1, $list);
        $this->assertSame('pre-reset', $list[0]['type']);

        // numbering starts again and the demo data can be brought back from the safety backup
        $this->assertSame(1, Customer::create(['name' => 'Real'])->id);
        BackupService::restore($list[0]['name'], 'tester');
        $this->assertSame('Demo Brand', Customer::first()->name);
    }
}
