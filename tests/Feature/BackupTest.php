<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Services\BackupService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Real mysqldump/mysql round-trip against the TEST database (lumiere_test) — never the live one.
 * Not wrapped in a transaction (a restore drops and recreates tables), so it rebuilds the schema itself.
 */
class BackupTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('lumiere_test', config('database.connections.mysql.database'), 'refusing to run backup tests on a non-test database');
        // never touch the real backups or the real uploads
        $this->tmp = sys_get_temp_dir().'/lumiere-bk-'.uniqid();
        config(['backup.dir' => $this->tmp.'/backups', 'backup.files_dir' => $this->tmp.'/public']);
        $this->artisan('migrate:fresh', ['--seed' => true, '--force' => true]);
        File::ensureDirectoryExists($this->tmp.'/public/uploads');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    public function test_backup_restore_round_trip_brings_back_deleted_data_and_files(): void
    {
        Customer::create(['name' => 'Keep Me']);
        file_put_contents($this->tmp.'/public/uploads/backup-test.txt', 'receipt-bytes');

        $meta = BackupService::create('manual', 'tester');
        $this->assertTrue($meta['verified']);
        $this->assertGreaterThan(10, $meta['tables']);
        $this->assertFileExists(BackupService::path($meta['name']));

        // disaster: data and files lost, schema changed
        Customer::query()->delete();
        unlink($this->tmp.'/public/uploads/backup-test.txt');
        \DB::statement('CREATE TABLE stray_newer_table (id INT)');

        $res = BackupService::restore($meta['name'], 'tester');

        $this->assertSame(1, Customer::where('name', 'Keep Me')->count());
        $this->assertSame('receipt-bytes', file_get_contents($this->tmp.'/public/uploads/backup-test.txt'));
        $this->assertFalse(\Schema::hasTable('stray_newer_table'), 'tables newer than the backup must not linger');
        $this->assertTrue(\Schema::hasTable('users'));
        // the safety copy taken just before the restore exists and is listed
        $this->assertContains($res['safety_backup'], array_column(BackupService::list(), 'name'));
    }

    public function test_listing_pruning_traversal_and_import(): void
    {
        $a = BackupService::create('nightly');
        sleep(1);
        $manual = BackupService::create('manual');
        $this->assertSame([$manual['name'], $a['name']], array_column(BackupService::list(), 'name')); // newest first

        // retention: only automatic types are pruned; manual backups stay
        for ($i = 0; $i < BackupService::KEEP_NIGHTLY + 2; $i++) {
            $n = 'lumiere-2020010'.($i % 9 + 1).sprintf('-%06d', $i).'-nightly.zip';
            copy(BackupService::path($a['name']), BackupService::dir().'/'.$n);
        }
        $removed = BackupService::prune();
        $this->assertGreaterThan(0, $removed);
        $this->assertCount(BackupService::KEEP_NIGHTLY, array_filter(BackupService::list(), fn ($b) => $b['type'] === 'nightly'));
        $this->assertContains($manual['name'], array_column(BackupService::list(), 'name'));

        // names are validated — no path traversal
        foreach (['../.env', '..%2F.env', 'lumiere-x.zip', 'database.sql'] as $bad) {
            $this->assertSame(404, rescue(fn () => BackupService::path($bad), fn ($e) => $e->getStatusCode()));
        }

        // upload: a real backup is accepted, junk is rejected
        $copy = sys_get_temp_dir().'/up-'.uniqid().'.zip';
        copy(BackupService::path($manual['name']), $copy);
        $imp = BackupService::import(new UploadedFile($copy, 'mine.zip', 'application/zip', null, true));
        $this->assertSame('uploaded', $imp['type']);
        $junk = sys_get_temp_dir().'/junk-'.uniqid().'.zip';
        file_put_contents($junk, 'not a zip');
        $this->expectException(\RuntimeException::class);
        BackupService::import(new UploadedFile($junk, 'junk.zip', 'application/zip', null, true));
    }

    public function test_only_permitted_users_reach_the_backup_screens(): void
    {
        $owner = User::where('email', 'owner@example.com')->first();
        $admin = User::create(['name' => 'Adm', 'email' => 'a@x.com', 'password' => 'password123']);
        $admin->assignRole('Admin');
        $worker = User::create(['name' => 'Wk', 'email' => 'w@x.com', 'password' => 'password123']);
        $worker->assignRole('User');

        $this->actingAs($worker)->get('/backups')->assertForbidden();
        $this->actingAs($admin)->get('/backups')->assertOk();                       // admins can back up and download…
        $name = BackupService::create('manual')['name'];
        $this->actingAs($admin)->post("/backups/$name/restore", ['confirm' => 'RESTORE', 'password' => 'password123'])->assertForbidden(); // …but not restore
        $this->actingAs($owner)->post("/backups/$name/restore", ['confirm' => 'nope', 'password' => 'ChangeMe@123'])->assertSessionHasErrors('confirm');
        $this->actingAs($owner)->post("/backups/$name/restore", ['confirm' => 'RESTORE', 'password' => 'wrong'])->assertSessionHasErrors('password');
        $this->actingAs($owner)->get("/backups/$name/download")->assertOk()->assertDownload($name);
    }
}
