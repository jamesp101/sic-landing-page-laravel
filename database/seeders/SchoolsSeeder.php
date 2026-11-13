<?php

namespace Database\Seeders;

use App\Admin\Slug;
use App\Content\Content;
use App\Models\Department;
use App\Models\Member;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Sample content for five schools: the Department, 20 Posts and an org chart of 7-15 Members each (port of
 * `prisma/seed-schools.ts`). Member portraits are DiceBear avatar URLs (external URLs pass through `publicUrl`).
 *
 * Schools whose slug already exists are skipped; `--reset` (`SchoolsSeeder::$reset`, or `SCHOOLS_RESET=1`) deletes
 * their posts and members and recreates them. Posts are authored by the first superadmin, so seed the demo
 * data or create an admin first. Post bodies are stored as Markdown, which the site renders as legacy content.
 */
class SchoolsSeeder extends Seeder
{
    public bool $reset = false;

    public function run(): void
    {
        DemoData::assertLocalDatabase(
            (array) config('database.connections.'.config('database.default')),
            (bool) config('sic.seed.allow_remote'),
        );
        $reset = $this->reset || (bool) env('SCHOOLS_RESET');
        $author = User::query()->where('role', User::ROLE_SUPERADMIN)->orderBy('id')->first();
        if ($author === null) {
            throw new InvalidArgumentException('No superadmin user found; seed the demo data or create an admin first.');
        }

        foreach (SchoolsData::SCHOOLS as $schoolIndex => $school) {
            $total = $this->countMembers($school['members']);
            if (count($school['posts']) !== 20 || $total < 5 || $total > 15) {
                throw new InvalidArgumentException(sprintf('%s: expected 20 posts and 5-15 members, got %d and %d', $school['name'], count($school['posts']), $total));
            }
            $existing = Department::query()->where('slug', $school['slug'])->first();
            if ($existing !== null && ! $reset) {
                $this->command?->info("{$school['name']}: slug \"{$school['slug']}\" exists; skipped (use --reset to recreate its posts and members).");

                continue;
            }

            DB::transaction(function () use ($school, $schoolIndex, $existing, $author, $total): void {
                $fields = ['name' => $school['name'], 'description' => $school['description'], 'hero_content' => $school['hero_content']];
                if ($existing !== null) {
                    $existing->update($fields);
                    $department = $existing;
                    Post::query()->where('department_id', $department->id)->delete();
                    Member::query()->where('department_id', $department->id)->update(['parent_id' => null]);
                    Member::query()->where('department_id', $department->id)->delete();
                } else {
                    $department = Department::query()->create($fields + ['slug' => $school['slug']]);
                }

                $eventIndex = 0;
                foreach ($school['posts'] as $index => $post) {
                    [$type, $title] = $post;
                    $createdAt = now()->subDays($index * 6 + $schoolIndex);
                    // Events alternate between recent past and upcoming dates so both listings have entries.
                    $startDate = $type === 'event' ? $this->manilaDay(($eventIndex % 2 === 0 ? 7 : -14) + $eventIndex * 5, 9) : null;
                    if ($type === 'event') {
                        $eventIndex++;
                    }
                    $content = $this->body($school, $post);
                    $row = new Post([
                        'title' => $title,
                        'slug' => $school['slug'].'-'.Slug::slugify($title),
                        'content' => $content,
                        'search_text' => Content::forStorage($content)->searchText,
                        'type' => $type,
                        'start_date' => $startDate,
                        'end_date' => $startDate?->copy()->addHours(4),
                        'department_id' => $department->id,
                        'user_id' => $author->id,
                        'is_featured' => $index === 0,
                    ]);
                    $row->created_at = $createdAt;
                    $row->updated_at = $createdAt;
                    $row->save();
                }

                $this->createMembers($school['members'], $department->id, null, 1);
                $this->command?->info("{$school['name']}: ".count($school['posts'])." posts, {$total} members.");
            });
        }
    }

    /** Midnight today in Manila plus `$days`, at `$hour` local time, in the app timezone (what Eloquent writes). */
    private function manilaDay(int $days, int $hour): Carbon
    {
        return Carbon::now('Asia/Manila')->startOfDay()->addHours($hour)->addDays($days)->setTimezone((string) config('app.timezone'));
    }

    /** @param array{venue: string, short: string, name: string, members: array{name: string, role: string}} $school @param array{0: string, 1: string, 2: string} $post */
    private function body(array $school, array $post): string
    {
        [$type, , $summary] = $post;
        if ($type === 'event') {
            return "{$summary}\n\n## Event details\n\n- **Venue:** {$school['venue']}\n- **Open to:** {$school['short']} students, faculty and guests\n- **Registration:** at the {$school['short']} Dean's Office or through your class adviser\n\nPlease arrive fifteen minutes early and bring your school ID.";
        }
        if ($type === 'announcement') {
            return "{$summary}\n\n## Reminders\n\n- Read the full guidelines posted on the {$school['short']} bulletin board.\n- Bring your school ID for all transactions.\n- Direct questions to the {$school['short']} Dean's Office during office hours (8:00 AM–5:00 PM, Monday to Friday).";
        }

        return "{$summary}\n\n## Why it matters\n\nThe {$school['name']} continues to connect classroom learning with real work and service to the community, in keeping with the San Isidro College tradition of values, excellence and service.\n\n> \"Our students learn best when they apply what they know to the needs of the people around them.\" — {$school['members']['name']}, {$school['members']['role']}";
    }

    /** @param array{children?: list<array>} $member */
    private function countMembers(array $member): int
    {
        return 1 + array_sum(array_map(fn (array $child) => $this->countMembers($child), $member['children'] ?? []));
    }

    /** @param array{name: string, role: string, children?: list<array>} $member */
    private function createMembers(array $member, int $departmentId, ?int $parentId, int $order): void
    {
        $row = Member::query()->create([
            'name' => $member['name'],
            'role' => $member['role'],
            'department_id' => $departmentId,
            'parent_id' => $parentId,
            'order' => $order,
            'image_path' => 'https://api.dicebear.com/9.x/notionists/svg?seed='.rawurlencode($member['name']),
        ]);
        foreach ($member['children'] ?? [] as $index => $child) {
            $this->createMembers($child, $departmentId, $row->id, $index + 1);
        }
    }
}
