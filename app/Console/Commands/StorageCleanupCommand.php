<?php

namespace App\Console\Commands;

use App\Storage\StorageCleanup;
use Illuminate\Console\Command;

/** Deletes stored files no row references (older than the 24 h grace) and sweeps expired pending uploads. */
class StorageCleanupCommand extends Command
{
    protected $signature = 'storage:cleanup {--dry-run : List what would be deleted without deleting}';

    protected $description = 'Delete unreferenced stored files older than 24 hours and expired pending uploads';

    public function handle(): int
    {
        $this->line(StorageCleanup::formatCleanupReport(StorageCleanup::cleanupStorage(['dryRun' => (bool) $this->option('dry-run')])));

        return self::SUCCESS;
    }
}
