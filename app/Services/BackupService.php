<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Backups = one zip holding a MySQL dump (database.sql), the uploaded files (files/…) and manifest.json.
 * Stored OUTSIDE the public web folder in storage/app/backups, with a small .json sidecar for the list screen.
 * Secrets (.env) are deliberately not included.
 */
class BackupService
{
    public const KEEP_NIGHTLY = 14;
    public const KEEP_MONTHLY = 6;
    private const NAME = '/^lumiere-\d{8}-\d{6}-(nightly|monthly|manual|uploaded|pre-restore|pre-reset)\.zip$/';

    public static function dir(): string
    {
        $d = config('backup.dir') ?: storage_path('app/backups');
        File::ensureDirectoryExists($d);

        return $d;
    }

    public static function filesDir(): string
    {
        return config('backup.files_dir') ?: storage_path('app/public');
    }

    /** Safe path for a backup file name (blocks traversal / odd names). */
    public static function path(string $name): string
    {
        abort_unless(preg_match(self::NAME, $name), 404);
        $p = self::dir().DIRECTORY_SEPARATOR.$name;
        abort_unless(is_file($p), 404);

        return $p;
    }

    /** @return array<int,array> newest first */
    public static function list(): array
    {
        $out = [];
        foreach (glob(self::dir().'/lumiere-*.zip') ?: [] as $zip) {
            $name = basename($zip);
            if (! preg_match(self::NAME, $name)) {
                continue;
            }
            $meta = is_file("$zip.json") ? (json_decode((string) file_get_contents("$zip.json"), true) ?: []) : [];
            $out[] = $meta + ['name' => $name, 'type' => preg_match(self::NAME, $name, $m) ? $m[1] : 'manual',
                'size' => filesize($zip), 'created_at' => date('c', filemtime($zip))];
        }
        usort($out, fn ($a, $b) => strcmp($b['created_at'], $a['created_at']) ?: strcmp($b['name'], $a['name']));

        return $out;
    }

    /** Create a backup. Throws on any failure (and removes a half-written file). */
    public static function create(string $type = 'manual', ?string $by = null): array
    {
        $stamp = now()->format('Ymd-His');
        $name = "lumiere-$stamp-$type.zip";
        $zipPath = self::dir().DIRECTORY_SEPARATOR.$name;
        $sql = tempnam(sys_get_temp_dir(), 'lumsql');

        try {
            self::dumpDatabase($sql);
            $tables = (int) preg_match_all('/^CREATE TABLE /m', (string) file_get_contents($sql));
            if ($tables < 10) {
                throw new \RuntimeException("Database dump looks incomplete ($tables tables).");
            }

            $zip = new ZipArchive;
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Could not create the backup file.');
            }
            $zip->addFile($sql, 'database.sql');
            $files = 0;
            $root = self::filesDir();
            if (is_dir($root)) {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $f) {
                    if ($f->isFile() && ! str_contains($f->getPathname(), DIRECTORY_SEPARATOR.'.gitignore')) {
                        $zip->addFile($f->getPathname(), 'files/'.ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($root))), '/'));
                        $files++;
                    }
                }
            }
            $manifest = ['app' => 'Lumiere Premium', 'created_at' => now()->toIso8601String(), 'type' => $type, 'by' => $by,
                'database' => config('database.connections.mysql.database'), 'tables' => $tables, 'files' => $files, 'laravel' => app()->version()];
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
            if (! $zip->close()) {
                throw new \RuntimeException('Could not finish writing the backup file.');
            }

            // verify: the zip must re-open and contain the pieces
            $check = new ZipArchive;
            if ($check->open($zipPath, ZipArchive::CHECKCONS) !== true || $check->locateName('database.sql') === false || $check->locateName('manifest.json') === false) {
                throw new \RuntimeException('Backup verification failed.');
            }
            $check->close();
        } catch (\Throwable $e) {
            @unlink($zipPath);
            throw $e;
        } finally {
            @unlink($sql);
        }

        $meta = $manifest + ['name' => $name, 'size' => filesize($zipPath), 'verified' => true];
        file_put_contents("$zipPath.json", json_encode($meta));

        return $meta;
    }

    /** Copy a backup under another type (used to keep a monthly copy of the 1st-of-month nightly backup). */
    public static function duplicateAs(string $name, string $type): array
    {
        $src = self::path($name);
        $new = preg_replace('/-(nightly|monthly|manual|uploaded|pre-restore|pre-reset)\.zip$/', "-$type.zip", $name);
        copy($src, self::dir().DIRECTORY_SEPARATOR.$new);
        $meta = (is_file("$src.json") ? json_decode((string) file_get_contents("$src.json"), true) : []) ?: [];
        $meta = ['name' => $new, 'type' => $type] + $meta;
        file_put_contents(self::dir().DIRECTORY_SEPARATOR."$new.json", json_encode($meta));

        return $meta;
    }

    /** Delete old automatic backups beyond the retention limits (manual / uploaded ones are never auto-deleted). */
    public static function prune(): int
    {
        $removed = 0;
        foreach (['nightly' => self::KEEP_NIGHTLY, 'monthly' => self::KEEP_MONTHLY, 'pre-restore' => 5, 'pre-reset' => 5] as $type => $keep) {
            $mine = array_values(array_filter(self::list(), fn ($b) => $b['type'] === $type));
            foreach (array_slice($mine, $keep) as $old) {
                self::delete($old['name']);
                $removed++;
            }
        }

        return $removed;
    }

    public static function delete(string $name): void
    {
        $p = self::path($name);
        @unlink($p);
        @unlink("$p.json");
    }

    /** Store an uploaded backup (e.g. downloaded earlier) so it can be restored on a fresh machine. */
    public static function import(UploadedFile $file): array
    {
        $zip = new ZipArchive;
        if ($zip->open($file->getRealPath()) !== true || $zip->locateName('database.sql') === false || $zip->locateName('manifest.json') === false) {
            throw new \RuntimeException('This is not a Lumiere backup file.');
        }
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true) ?: [];
        $zip->close();
        if (($manifest['app'] ?? '') !== 'Lumiere Premium') {
            throw new \RuntimeException('This is not a Lumiere backup file.');
        }
        $name = 'lumiere-'.now()->format('Ymd-His').'-uploaded.zip';
        $file->move(self::dir(), $name);
        $meta = ['name' => $name, 'type' => 'uploaded', 'size' => filesize(self::dir()."/$name"), 'verified' => true] + $manifest;
        file_put_contents(self::dir()."/$name.json", json_encode($meta));

        return $meta;
    }

    /**
     * Replace the current database and uploaded files with a backup.
     * A "pre-restore" backup of the current state is taken first, so a restore itself can be undone.
     */
    public static function restore(string $name, ?string $by = null): array
    {
        $zipPath = self::path($name);
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true || $zip->locateName('database.sql') === false) {
            throw new \RuntimeException('Backup file is damaged.');
        }
        $safety = self::create('pre-restore', $by);

        $work = storage_path('app/restore-'.uniqid());
        File::ensureDirectoryExists($work);
        try {
            $zip->extractTo($work);
            $zip->close();

            Schema::dropAllTables(); // start from an empty schema so tables newer than the backup cannot linger
            self::importSql($work.'/database.sql');

            // files: replace the public uploads with the ones from the backup
            $target = self::filesDir();
            $incoming = $work.'/files';
            if (is_dir($incoming)) {
                foreach (glob($target.'/*') ?: [] as $entry) {
                    is_dir($entry) ? File::deleteDirectory($entry) : @unlink($entry);
                }
                File::ensureDirectoryExists($target);
                File::copyDirectory($incoming, $target);
            }
            // an older backup may predate newer migrations
            Artisan::call('migrate', ['--force' => true]);
            Artisan::call('optimize:clear');
        } finally {
            File::deleteDirectory($work);
        }

        return ['restored' => $name, 'safety_backup' => $safety['name']];
    }

    // ---- mysql helpers -----------------------------------------------------------------------
    private static function cnf(): string
    {
        $c = config('database.connections.mysql');
        $f = tempnam(sys_get_temp_dir(), 'lumcnf');
        chmod($f, 0600);
        file_put_contents($f, "[client]\nuser=\"".addcslashes((string) $c['username'], '"\\')."\"\npassword=\"".addcslashes((string) $c['password'], '"\\')."\"\nhost=\"{$c['host']}\"\nport={$c['port']}\n");

        return $f;
    }

    private static function dumpDatabase(string $outFile): void
    {
        $cnf = self::cnf();
        try {
            $p = new Process(['mysqldump', "--defaults-extra-file=$cnf", '--single-transaction', '--routines', '--triggers',
                '--no-tablespaces', '--default-character-set=utf8mb4', '--add-drop-table', '--result-file='.$outFile,
                config('database.connections.mysql.database')]);
            $p->setTimeout(600)->mustRun();
        } finally {
            @unlink($cnf);
        }
    }

    private static function importSql(string $sqlFile): void
    {
        $cnf = self::cnf();
        try {
            $p = Process::fromShellCommandline('mysql --defaults-extra-file="$CNF" --default-character-set=utf8mb4 "$DB" < "$SQL"', null,
                ['CNF' => $cnf, 'DB' => config('database.connections.mysql.database'), 'SQL' => $sqlFile]);
            $p->setTimeout(900)->mustRun();
        } finally {
            @unlink($cnf);
        }
        DB::purge();
        DB::reconnect();
    }
}
