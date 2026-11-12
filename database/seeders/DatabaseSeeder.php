<?php

namespace Database\Seeders;

use App\Admin\Slug;
use App\Auth\PasswordLogin;
use App\Content\Content;
use App\Models\Department;
use App\Models\HeroSection;
use App\Models\Member;
use App\Models\NavigationLink;
use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds a fresh database with the demo content of `DemoData` (port of `prisma/seed.ts`).
 *
 * Skipped on purpose (no real asset / document exists for them):
 *  - image columns pointing at the non-existent /images/* files (stored as null)
 *  - carousel Slides (`image_path` is required and there is no slide imagery)
 *  - Downloads (the original seeder wrote mock text files named *.pdf, not real documents)
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DemoData::assertLocalDatabase(
            (array) config('database.connections.'.config('database.default')),
            (bool) config('sic.seed.allow_remote'),
        );

        DB::transaction(function (): void {
            $this->navigation();
            $departmentIds = $this->departments();
            $userIds = $this->users($departmentIds);
            $this->posts($departmentIds, $userIds);
            $this->members($departmentIds);
            Setting::query()->updateOrCreate(['key' => 'welcome_hero_content'], ['value' => DemoData::WELCOME_HERO]);
            $this->heroSections($departmentIds);
        });

        $this->command?->info('Seeded navigation, departments, users, posts, members, settings and hero sections.');
        $this->command?->warn('NOTICE: carousel slides and downloads were not seeded; real slide imagery and documents are required.');
    }

    /** The instant of an ISO time with an offset, in the app timezone: Eloquent writes a Carbon in its own timezone. */
    private function utc(string $iso): Carbon
    {
        return Carbon::parse($iso)->setTimezone((string) config('app.timezone'));
    }

    private function navigation(): void
    {
        NavigationLink::query()->create(['label' => 'Home', 'url' => '/', 'order' => 1]);
        NavigationLink::query()->create(['label' => 'Departments', 'url' => '#departments', 'order' => 2]);
        $downloads = NavigationLink::query()->create(['label' => 'Downloads', 'url' => '/downloads', 'order' => 3]);
        foreach (DemoData::DOWNLOAD_NAV_CHILDREN as $index => $label) {
            NavigationLink::query()->create(['label' => $label, 'url' => '/downloads', 'order' => $index + 1, 'parent_id' => $downloads->id]);
        }
        NavigationLink::query()->create(['label' => 'Contact Us', 'url' => '/contact', 'order' => 4]);
    }

    /** @return array<string, int> Department key => id (logo_path stays null: /images/*-logo.svg do not exist) */
    private function departments(): array
    {
        $ids = [];
        foreach (DemoData::DEPARTMENTS as $key => $fields) {
            $ids[$key] = Department::query()->create($fields)->id;
        }

        return $ids;
    }

    /**
     * @param  array<string, int>  $departmentIds
     * @return array<string, int> User key => id
     */
    private function users(array $departmentIds): array
    {
        $ids = [];
        foreach (DemoData::USERS as $key => $row) {
            $hash = Hash::make(DemoData::seedPassword($key));
            $user = User::query()->create([
                'name' => $row['name'],
                'email' => $row['email'],
                'role' => $row['role'],
                'department_id' => $row['department'] === null ? null : $departmentIds[$row['department']],
                'email_verified' => true,
                'email_verified_at' => now(),
            ]);
            PasswordLogin::setPasswordLogin($user->id, $hash);
            $ids[$key] = $user->id;
        }

        return $ids;
    }

    /**
     * @param  array<string, int>  $departmentIds
     * @param  array<string, int>  $userIds
     */
    private function posts(array $departmentIds, array $userIds): void
    {
        foreach (DemoData::POSTS as $row) {
            Post::query()->create([
                'title' => $row['title'],
                'content' => $row['content'],
                'type' => $row['type'],
                'start_date' => $row['start_date'] === null ? null : $this->utc($row['start_date']),
                'end_date' => $row['end_date'] === null ? null : $this->utc($row['end_date']),
                'is_featured' => $row['is_featured'],
                'department_id' => $row['department'] === null ? null : $departmentIds[$row['department']],
                'user_id' => $userIds[$row['author']],
                'slug' => Slug::slugify($row['title']),
                'search_text' => Content::searchTextOf($row['content']),
            ]);
        }
    }

    /** @param  array<string, int>  $departmentIds */
    private function members(array $departmentIds): void
    {
        $ids = [];
        foreach (DemoData::MEMBERS as $key => $row) {
            $ids[$key] = Member::query()->create([
                'name' => $row['name'],
                'role' => $row['role'],
                'order' => $row['order'],
                'department_id' => $departmentIds[$row['department']],
                'parent_id' => $row['parent'] === null ? null : $ids[$row['parent']],
            ])->id;
        }
    }

    /** @param  array<string, int>  $departmentIds */
    private function heroSections(array $departmentIds): void
    {
        foreach (DemoData::HERO_SECTIONS as $row) {
            HeroSection::query()->create([
                'title' => $row['title'],
                'content' => $row['content'],
                'order' => $row['order'],
                'is_active' => $row['is_active'],
                'department_id' => $row['department'] === null ? null : $departmentIds[$row['department']],
                'search_text' => Content::searchTextOf($row['content']),
            ]);
        }
    }
}
