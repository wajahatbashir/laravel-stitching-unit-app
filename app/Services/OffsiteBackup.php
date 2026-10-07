<?php

namespace App\Services;

use App\Models\Setting;
use Symfony\Component\Process\Process;

/**
 * Copies finished backups somewhere that survives this server dying (see config/backup.php).
 * Folder mode keeps the same 14 nightly + 6 monthly there; SSH mode only adds files (prune on that server).
 */
class OffsiteBackup
{
    public static function mode(): ?string
    {
        if (config('backup.offsite_path')) {
            return 'path';
        }

        return config('backup.offsite_ssh') ? 'ssh' : null;
    }

    public static function target(): string
    {
        return (string) (config('backup.offsite_path') ?: config('backup.offsite_ssh'));
    }

    /** @return array{mode:?string,target:string,last:?array} */
    public static function status(): array
    {
        return ['mode' => self::mode(), 'target' => self::target(), 'last' => json_decode((string) Setting::get('offsite_last'), true) ?: null];
    }

    /** Copy one backup (zip + manifest). Never throws: returns ['ok'=>bool,'error'=>?string] and remembers the result. */
    public static function push(string $name): array
    {
        $res = ['ok' => false, 'error' => null, 'name' => $name, 'at' => now()->toIso8601String()];
        try {
            $zip = BackupService::path($name);
            match (self::mode()) {
                'path' => self::toFolder($zip, (string) config('backup.offsite_path')),
                'ssh' => self::toSsh($zip),
                default => throw new \RuntimeException('Off-site backup is not configured.'),
            };
            $res['ok'] = true;
        } catch (\Throwable $e) {
            $res['error'] = $e->getMessage();
        }
        Setting::put('offsite_last', json_encode($res));

        return $res;
    }

    private static function toFolder(string $zip, string $dir): void
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException("Cannot create or reach the folder $dir (is the drive connected?)");
        }
        foreach ([$zip, "$zip.json"] as $f) {
            if (! is_file($f)) {
                continue;
            }
            $dest = $dir.DIRECTORY_SEPARATOR.basename($f);
            if (! @copy($f, "$dest.part") || ! @rename("$dest.part", $dest)) {
                @unlink("$dest.part");
                throw new \RuntimeException('Could not write to '.$dir.' (disk full or read-only?)');
            }
        }
        self::prune($dir);
    }

    private static function toSsh(string $zip): void
    {
        $ssh = 'ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new -p '.(int) config('backup.offsite_ssh_port', 22)
            .(config('backup.offsite_ssh_key') ? ' -i '.escapeshellarg((string) config('backup.offsite_ssh_key')) : '');
        $files = array_values(array_filter([$zip, "$zip.json"], 'is_file'));
        $p = new Process(array_merge(['rsync', '-t', '-e', $ssh], $files, [rtrim((string) config('backup.offsite_ssh'), '/').'/']));
        $p->setTimeout(1800)->run();
        if (! $p->isSuccessful()) {
            throw new \RuntimeException('rsync failed: '.trim($p->getErrorOutput() ?: $p->getOutput()));
        }
    }

    /** Same retention as the server itself, applied to the off-site folder. */
    private static function prune(string $dir): void
    {
        foreach (['nightly' => BackupService::KEEP_NIGHTLY, 'monthly' => BackupService::KEEP_MONTHLY] as $type => $keep) {
            $mine = glob($dir.DIRECTORY_SEPARATOR."lumiere-*-$type.zip") ?: [];
            rsort($mine); // names start with the timestamp → newest first
            foreach (array_slice($mine, $keep) as $old) {
                @unlink($old);
                @unlink("$old.json");
            }
        }
    }
}
