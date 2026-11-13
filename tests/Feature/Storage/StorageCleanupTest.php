<?php

namespace Tests\Feature\Storage;

use App\Models\CarouselItem;
use App\Models\Department;
use App\Models\Member;
use App\Models\PendingUpload;
use App\Models\Post;
use App\Storage\KeyMatcher;
use App\Storage\Storage;
use App\Storage\StorageCleanup;
use App\Storage\StoredKeyReferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Unit\Storage\UsesUploadsDisk;

class StorageCleanupTest extends TestCase
{
    use RefreshDatabase;
    use UsesUploadsDisk;

    private const HOUR = 60 * 60;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useLocalUploads();
    }

    private function file(string $key, int $ageHours): void
    {
        $path = Storage::resolve($key);
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, 'x');
        touch($path, time() - $ageHours * self::HOUR);
    }

    private function exists(string $key): bool
    {
        return is_file(Storage::resolve($key));
    }

    private function makePost(array $attributes): Post
    {
        static $n = 0;
        $n++;

        return Post::query()->create($attributes + [
            'title' => 'P', 'type' => 'article', 'slug' => "p{$n}", 'content' => 'x',
            'user_id' => $this->actingAsSuperadmin()->id,
        ]);
    }

    // ---- KeyMatcher --------------------------------------------------------------------------------------------

    public function test_the_key_matcher_finds_bare_keys_and_url_forms(): void
    {
        $hits = [];
        (new KeyMatcher(['posts/a.webp', 'carousels/b.webp', 'posts/inline/c.webp']))->find(
            '![x](/storage/posts/a.webp?v=1) https://cdn.example/sic/carousels/b.webp {"src":"\\/storage\\/posts\\/inline\\/c.webp"}',
            function (string $key) use (&$hits): void {
                $hits[$key] = true;
            },
        );
        $found = array_keys($hits);
        sort($found);
        $this->assertSame(['carousels/b.webp', 'posts/a.webp', 'posts/inline/c.webp'], $found);
    }

    public function test_the_key_matcher_does_not_match_a_longer_name_that_merely_ends_the_same(): void
    {
        $hits = [];
        (new KeyMatcher(['posts/a.webp']))->find('/storage/posts/xa.webp', function (string $key) use (&$hits): void {
            $hits[] = $key;
        });
        $this->assertSame([], $hits);
    }

    // ---- cleanupStorage ----------------------------------------------------------------------------------------

    public function test_it_keeps_referenced_and_young_files_deletes_the_rest_and_dry_run_deletes_nothing(): void
    {
        $this->file('members/plain.webp', 48);
        $this->file('posts/inline/block.webp', 48);
        $this->file('carousels/layer.webp', 48);
        $this->file('posts/orphan.webp', 48);
        $this->file('posts/young.webp', 1);
        $this->file('posts/stale.webp.abc123.part', 48);

        Department::query()->create(['name' => 'D', 'slug' => 'd', 'logo_path' => 'members/plain.webp']);
        $this->makePost(['content' => json_encode(['type' => 'sic-blocks', 'version' => 1, 'blocks' => [['type' => 'image', 'src' => '/storage/posts/inline/block.webp']]])]);
        CarouselItem::query()->create([
            'image_path' => 'carousels/unlisted.webp',
            'overlay' => json_encode(['v' => 1, 'layers' => [['type' => 'image', 'src' => 'carousels/layer.webp']]]),
        ]);

        $expected = ['posts/orphan.webp', 'posts/stale.webp.abc123.part'];

        $dry = StorageCleanup::cleanupStorage(['dryRun' => true]);
        $keys = $dry['keys'];
        sort($keys);
        $this->assertSame($expected, $keys);
        $this->assertSame(2, $dry['deleted']);
        foreach ([...$expected, 'posts/young.webp', 'members/plain.webp'] as $key) {
            $this->assertTrue($this->exists($key), $key);
        }

        $real = StorageCleanup::cleanupStorage();
        $keys = $real['keys'];
        sort($keys);
        $this->assertSame($expected, $keys);
        $this->assertSame(2, $real['bytesFreed']);
        $this->assertSame(3, $real['referenced']);
        foreach ($expected as $key) {
            $this->assertFalse($this->exists($key), $key);
        }
        foreach (['posts/young.webp', 'members/plain.webp', 'posts/inline/block.webp', 'carousels/layer.webp'] as $key) {
            $this->assertTrue($this->exists($key), $key);
        }
    }

    public function test_it_stops_at_the_delete_cap_and_reports_what_is_left(): void
    {
        foreach (['o1', 'o2', 'o3'] as $name) {
            $this->file("posts/{$name}.webp", 48);
        }
        $report = StorageCleanup::cleanupStorage(['maxDeletes' => 2]);
        $this->assertSame(2, $report['deleted']);
        $this->assertSame(1, $report['remaining']);
    }

    public function test_it_keeps_a_key_issued_to_a_pending_direct_upload(): void
    {
        $key = 'downloads/0123456789abcdef0123456789abcdef.pdf';
        $this->file($key, 48);
        $user = $this->actingAsSuperadmin();
        PendingUpload::query()->create(['key' => $key, 'user_id' => $user->id, 'expires_at' => now()->addHour()]);
        $this->assertNotContains($key, StorageCleanup::cleanupStorage(['dryRun' => true])['keys']);
    }

    public function test_a_dry_run_lists_a_file_whose_only_reference_is_a_pending_upload_row_a_sweep_would_remove(): void
    {
        $key = 'videos/0123456789abcdef0123456789abcdef.mp4';
        $this->file($key, 48);
        $user = $this->actingAsSuperadmin();
        PendingUpload::query()->create(['key' => $key, 'user_id' => $user->id, 'expires_at' => now()->subHours(2)]);
        $report = StorageCleanup::cleanupStorage(['dryRun' => true]);
        $this->assertContains($key, $report['keys']);
        $this->assertSame(1, $report['pendingUploads']);
        $this->assertTrue($this->exists($key));
        $this->assertSame(1, PendingUpload::query()->count());
    }

    public function test_a_real_run_sweeps_the_expired_pending_rows_and_then_deletes_their_unreferenced_file(): void
    {
        $key = 'videos/0123456789abcdef0123456789abcdef.mp4';
        $this->file($key, 48);
        $user = $this->actingAsSuperadmin();
        PendingUpload::query()->create(['key' => $key, 'user_id' => $user->id, 'expires_at' => now()->subHours(2)]);
        $report = StorageCleanup::cleanupStorage();
        $this->assertSame(1, $report['pendingUploads']);
        $this->assertContains($key, $report['keys']);
        $this->assertFalse($this->exists($key));
        $this->assertSame(0, PendingUpload::query()->count());
    }

    public function test_it_keeps_a_variant_while_its_original_is_referenced_deletes_both_together_and_deletes_a_variant_without_original(): void
    {
        foreach (['posts/hl.webp', 'posts/hl.w640.webp', 'posts/gone.webp', 'posts/gone.w640.webp', 'posts/solo.w640.webp'] as $key) {
            $this->file($key, 48);
        }
        $this->makePost(['slug' => 'hl', 'highlight_image_path' => 'posts/hl.webp']);

        $dry = StorageCleanup::cleanupStorage(['dryRun' => true]);
        $this->assertContains('posts/gone.webp', $dry['keys']);
        $this->assertContains('posts/solo.w640.webp', $dry['keys']);
        foreach (['posts/hl.webp', 'posts/hl.w640.webp', 'posts/gone.w640.webp'] as $key) {
            $this->assertNotContains($key, $dry['keys'], $key);
        }

        StorageCleanup::cleanupStorage();
        foreach (['posts/hl.webp', 'posts/hl.w640.webp'] as $key) {
            $this->assertTrue($this->exists($key), $key);
        }
        foreach (['posts/gone.webp', 'posts/gone.w640.webp', 'posts/solo.w640.webp'] as $key) {
            $this->assertFalse($this->exists($key), $key);
        }
    }

    public function test_it_keeps_a_slide_photo_variant_with_its_photo_and_deletes_it_with_an_unreferenced_one(): void
    {
        foreach (['carousels/kept.webp', 'carousels/kept.w960.webp', 'carousels/gone.webp', 'carousels/gone.w960.webp'] as $key) {
            $this->file($key, 48);
        }
        CarouselItem::query()->create(['image_path' => 'carousels/kept.webp', 'order' => 1]);

        $dry = StorageCleanup::cleanupStorage(['dryRun' => true]);
        $this->assertContains('carousels/gone.webp', $dry['keys']);
        foreach (['carousels/kept.webp', 'carousels/kept.w960.webp', 'carousels/gone.w960.webp'] as $key) {
            $this->assertNotContains($key, $dry['keys'], $key);
        }

        StorageCleanup::cleanupStorage();
        foreach (['carousels/kept.webp', 'carousels/kept.w960.webp'] as $key) {
            $this->assertTrue($this->exists($key), $key);
        }
        foreach (['carousels/gone.webp', 'carousels/gone.w960.webp'] as $key) {
            $this->assertFalse($this->exists($key), $key);
        }
    }

    public function test_it_keeps_department_logo_and_member_portrait_variants_with_their_originals_and_deletes_them_with_unreferenced_ones(): void
    {
        foreach (['departments/kept', 'departments/gone', 'members/kept', 'members/gone'] as $key) {
            $this->file("{$key}.webp", 48);
            $this->file("{$key}.w128.webp", 48);
        }
        $department = Department::query()->create(['name' => 'Cleanup', 'slug' => 'cleanup', 'logo_path' => 'departments/kept.webp']);
        Member::query()->create(['name' => 'M', 'role' => 'R', 'department_id' => $department->id, 'image_path' => 'members/kept.webp']);

        $dry = StorageCleanup::cleanupStorage(['dryRun' => true]);
        foreach (['departments/gone.webp', 'members/gone.webp'] as $key) {
            $this->assertContains($key, $dry['keys']);
        }
        foreach (['departments/kept', 'members/kept'] as $key) {
            $this->assertNotContains("{$key}.webp", $dry['keys']);
            $this->assertNotContains("{$key}.w128.webp", $dry['keys']);
        }
        foreach (['departments/gone', 'members/gone'] as $key) {
            $this->assertNotContains("{$key}.w128.webp", $dry['keys']);
        }

        StorageCleanup::cleanupStorage();
        foreach (['departments/kept', 'members/kept'] as $key) {
            $this->assertTrue($this->exists("{$key}.webp"));
            $this->assertTrue($this->exists("{$key}.w128.webp"));
        }
        foreach (['departments/gone', 'members/gone'] as $key) {
            $this->assertFalse($this->exists("{$key}.webp"));
            $this->assertFalse($this->exists("{$key}.w128.webp"));
        }
    }

    public function test_it_finds_the_text_columns_from_the_schema_so_new_columns_count(): void
    {
        $tables = collect(StoredKeyReferences::stringTables())->keyBy('table');
        $this->assertContains('logo_path', $tables['departments']['columns']);
        $this->assertContains('highlight_image_path', $tables['posts']['columns']);
        $this->assertContains('overlay', $tables['carousel_items']['columns']);
        $this->assertArrayNotHasKey('sessions', $tables->all());
        $this->assertNotContains('order', $tables['carousel_items']['columns']);
    }

    public function test_the_report_reads_like_the_ts_summary(): void
    {
        $this->file('posts/orphan.webp', 48);
        $text = StorageCleanup::formatCleanupReport(StorageCleanup::cleanupStorage(['dryRun' => true]));
        $this->assertStringContainsString('Would delete 1 unreferenced file(s), 1 B', $text);
        $this->assertStringContainsString('  posts/orphan.webp', $text);
        $this->artisan('storage:cleanup', ['--dry-run' => true])->expectsOutputToContain('Would delete 1 unreferenced file(s)')->assertSuccessful();
        $this->artisan('storage:cleanup')->expectsOutputToContain('Deleted 1 unreferenced file(s)')->assertSuccessful();
        $this->assertFalse($this->exists('posts/orphan.webp'));
    }
}
