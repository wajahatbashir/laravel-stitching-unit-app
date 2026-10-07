<?php

return [
    // where backups are written (default: storage/app/backups) and which uploads folder is backed up (default: storage/app/public)
    'dir' => env('BACKUP_DIR'),
    'files_dir' => env('BACKUP_FILES_DIR'),

    // Off-site copy (leave both empty to disable). Use ONE of:
    //  - a folder on another disk: an external drive, or a folder synced by Google Drive / OneDrive / Dropbox for Desktop
    //    (from WSL, Windows drives are /mnt/c, /mnt/d …  e.g. /mnt/d/LumiereBackups)
    'offsite_path' => env('BACKUP_OFFSITE_PATH'),
    //  - another server over SSH (rsync): user@host:/remote/folder   (key-based login, no password)
    'offsite_ssh' => env('BACKUP_OFFSITE_SSH'),
    'offsite_ssh_key' => env('BACKUP_OFFSITE_SSH_KEY'),
    'offsite_ssh_port' => env('BACKUP_OFFSITE_SSH_PORT', 22),
];
