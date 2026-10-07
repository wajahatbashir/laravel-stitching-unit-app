<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\BackupService;
use App\Services\OffsiteBackup;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** Rebuilds the TEST database (backup needs a real dump) and uses temp folders only. */
class OffsiteBackupTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('lumiere_test', config('database.connections.mysql.database'));
        $this->tmp = sys_get_temp_dir().'/lumiere-off-'.uniqid();
        config(['backup.dir' => $this->tmp.'/backups', 'backup.files_dir' => $this->tmp.'/public', 'backup.offsite_path' => null, 'backup.offsite_ssh' => null]);
        $this->artisan('migrate:fresh', ['--seed' => true, '--force' => true]);
        File::ensureDirectoryExists($this->tmp.'/public/uploads');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    public function test_not_configured_shows_setup_help_and_push_fails_cleanly(): void
    {
        $this->actingAs(User::where('email', 'owner@example.com')->first());
        $this->get('/backups')->assertOk()->assertSee('Not set up')->assertSee('BACKUP_OFFSITE_PATH');
        $m = BackupService::create('manual', 't');
        $this->assertFalse(OffsiteBackup::push($m['name'])['ok']);
    }

    public function test_backup_is_copied_to_the_folder_with_retention_and_status_is_shown(): void
    {
        $dest = $this->tmp.'/drive';
        config(['backup.offsite_path' => $dest]);
        $owner = User::where('email', 'owner@example.com')->first();
        $this->actingAs($owner);

        // 15 old nightly copies already off-site → the oldest ones are pruned after the new copy lands
        File::ensureDirectoryExists($dest);
        for ($i = 1; $i <= 15; $i++) {
            file_put_contents($dest.sprintf('/lumiere-202601%02d-020000-nightly.zip', $i), 'old');
        }
        file_put_contents($dest.'/lumiere-20250101-020000-manual.zip', 'keep'); // other types are never pruned

        $this->post('/backups')->assertSessionHas('success');           // manual backup also goes off-site
        $latest = BackupService::list()[0];
        $this->assertFileExists($dest.'/'.$latest['name']);
        $this->assertFileExists($dest.'/'.$latest['name'].'.json');
        $this->assertSame(filesize(BackupService::path($latest['name'])), filesize($dest.'/'.$latest['name']));
        $this->assertCount(14, glob($dest.'/lumiere-*-nightly.zip'));
        $this->assertFileExists($dest.'/lumiere-20250101-020000-manual.zip');
        $this->assertEmpty(glob($dest.'/*.part'));

        $this->get('/backups')->assertOk()->assertSee('Last copy OK')->assertSee($dest)->assertSee('Copy latest now');
        $this->post('/backups/offsite')->assertSessionHas('success');
    }

    public function test_unreachable_destination_is_reported_not_thrown(): void
    {
        config(['backup.offsite_path' => '/proc/nope/cannot']);
        $this->actingAs(User::where('email', 'owner@example.com')->first());
        $this->post('/backups')->assertSessionHas('success')->assertSessionHasErrors('backup');
        $this->assertFalse(json_decode(Setting::get('offsite_last'), true)['ok']);
        $this->get('/backups')->assertSee('Last copy FAILED');
    }

    public function test_scheduled_command_copies_offsite(): void
    {
        $dest = $this->tmp.'/drive2';
        config(['backup.offsite_path' => $dest]);
        $this->artisan('lumiere:backup')->expectsOutputToContain('Off-site copy OK')->assertSuccessful();
        $this->assertCount(1, glob($dest.'/lumiere-*-nightly.zip'));
    }
}
