<?php

namespace App\Console\Commands;

use Database\Seeders\DemoData;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Prints `Database\Seeders\DemoData` as JSON for the Playwright specs (`e2e/demo-data.ts`), so the demo data has one
 * source. Row keys are camelCase (`heroContent`, `startDate`, `isFeatured`), the shape the TS exports had.
 */
class DemoDataJson extends Command
{
    protected $signature = 'sic:demo-data';

    protected $description = 'Print the demo data as JSON (used by the e2e specs)';

    public function handle(): int
    {
        $this->line(json_encode([
            'DEMO_DEPARTMENTS' => self::rows(DemoData::DEPARTMENTS),
            'DEMO_USERS' => self::rows(DemoData::USERS),
            'DEMO_POSTS' => self::rows(DemoData::POSTS),
            'DEMO_HERO_SECTIONS' => self::rows(DemoData::HERO_SECTIONS),
            'DEMO_MEMBERS' => self::rows(DemoData::MEMBERS),
            'WELCOME_HERO' => DemoData::WELCOME_HERO,
            'AI_LAB_HEADING' => DemoData::AI_LAB_HEADING,
            'AI_LAB_QUOTE' => DemoData::AI_LAB_QUOTE,
            'DEMO_DOWNLOAD_NAV_CHILDREN' => DemoData::DOWNLOAD_NAV_CHILDREN,
            'SEED_SUPERADMIN_PASSWORD' => DemoData::seedPassword('superAdmin'),
            'SEED_DEPARTMENT_PASSWORD' => DemoData::seedPassword('cit'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows  rows by key
     * @return array<string, array<string, mixed>> the same rows with camelCase column names
     */
    private static function rows(array $rows): array
    {
        return array_map(
            fn (array $row): array => array_combine(array_map(Str::camel(...), array_keys($row)), array_values($row)),
            $rows,
        );
    }
}
