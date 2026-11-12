<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Command;

/** Seeds a fresh database with the demo content (`DatabaseSeeder`). `--if-empty` does nothing once any User exists. */
class Seed extends Command
{
    protected $signature = 'sic:seed {--if-empty : Skip when the database already has users}';

    protected $description = 'Seed the database with the demo content';

    public function handle(): int
    {
        if ($this->option('if-empty') && User::query()->exists()) {
            $this->info('Database already has users; skipping seed (--if-empty).');

            return self::SUCCESS;
        }

        return $this->call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);
    }
}
