<?php

namespace Tests\Unit\Carousel;

use App\Carousel\Slides;
use App\Models\CarouselItem;
use App\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Port of slides.test.ts. `fixtures/slides.json` holds the view models the TS read model produced for the same rows.
 */
class SlidesTest extends TestCase
{
    use RefreshDatabase;
    use StoresFiles;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useLocalUploads();
    }

    private static function wire(mixed $value): mixed
    {
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR));
    }

    private function create(array $columns): CarouselItem
    {
        return CarouselItem::query()->forceCreate($columns);
    }

    public function test_slide_video_views_cover_files_and_youtube_links(): void
    {
        $this->assertSame(['kind' => 'file', 'src' => '/storage/videos/a.webm', 'mime' => 'video/webm'], Slides::slideVideoView(['videoPath' => 'videos/a.webm', 'videoUrl' => null]));
        $this->assertSame('video/mp4', Slides::slideVideoView(['videoPath' => 'videos/a.mp4', 'videoUrl' => null])['mime']);
        $this->assertSame(['kind' => 'youtube', 'id' => 'dQw4w9WgXcQ'], Slides::slideVideoView(['videoPath' => null, 'videoUrl' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']));
        $this->assertNull(Slides::slideVideoView(['videoPath' => null, 'videoUrl' => 'https://vimeo.com/123456789']));
        $this->assertNull(Slides::slideVideoView(['videoPath' => null, 'videoUrl' => null]));
    }

    public function test_the_designer_shows_the_same_video_as_the_public_carousel(): void
    {
        $file = $this->create(['image_path' => 'carousels/a.webp', 'video_path' => 'videos/a.mp4']);
        $youtube = $this->create(['image_path' => 'carousels/b.webp', 'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']);
        $plain = $this->create(['image_path' => 'carousels/c.webp']);

        $designer = array_map(Slides::toDesignerSlide(...), [$file, $youtube, $plain]);
        $this->assertSame(
            [['/storage/videos/a.mp4', 'file'], ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'youtube'], ['', null]],
            array_map(fn (array $s) => [$s['video'], $s['videoKind']], $designer),
        );

        $public = Slides::findSlides(fn ($q) => $q->whereIn('id', [$file->id, $youtube->id, $plain->id]));
        $this->assertSame(array_column($designer, 'videoKind'), array_map(fn (array $s) => $s['video']['kind'] ?? null, $public));
    }

    public function test_stored_keys_site_paths_and_external_urls_map_the_same_in_designer_and_public_carousel(): void
    {
        $rows = array_map(fn (string $path) => $this->create(['image_path' => $path]), ['carousels/a.webp', '/images/hero.jpg', 'https://cdn.example.org/a.webp']);
        $expected = ['/storage/carousels/a.webp', '/images/hero.jpg', 'https://cdn.example.org/a.webp'];

        $this->assertSame($expected, array_map(fn (CarouselItem $row) => Slides::toDesignerSlide($row)['imageUrl'], $rows));
        $public = Slides::findSlides(fn ($q) => $q->whereIn('id', array_map(fn ($r) => $r->id, $rows)));
        $this->assertSame($expected, array_column($public, 'imageUrl'));
    }

    public function test_find_slides_orders_by_sequence_and_filters_by_column_map(): void
    {
        Department::query()->forceCreate(['id' => 7, 'name' => 'CIT', 'slug' => 'cit']);
        $second = $this->create(['image_path' => 'carousels/2.webp', 'order' => 2, 'show_on_main' => true]);
        $first = $this->create(['image_path' => 'carousels/1.webp', 'order' => 1, 'show_on_main' => true]);
        $this->create(['image_path' => 'carousels/3.webp', 'order' => 0, 'show_on_main' => false, 'department_id' => 7]);

        $this->assertSame([$first->id, $second->id], array_column(Slides::findSlides(['showOnMain' => true]), 'id'));
        $this->assertCount(1, Slides::findSlides(['departmentId' => 7]));
    }

    public function test_view_models_equal_the_ts_fixtures(): void
    {
        $fixtures = json_decode((string) file_get_contents(__DIR__.'/fixtures/slides.json'));
        Department::query()->forceCreate(['id' => 3, 'name' => 'CBA', 'slug' => 'cba']);

        $rows = [];
        foreach ($fixtures->views as $view) {
            $r = $view->row;
            $rows[] = $this->create([
                'id' => $r->id, 'image_path' => $r->imagePath, 'title' => $r->title, 'subtitle' => $r->subtitle, 'link_url' => $r->linkUrl,
                'overlay' => $r->overlay, 'video_path' => $r->videoPath, 'video_url' => $r->videoUrl, 'order' => $r->order,
                'department_id' => $r->departmentId, 'show_on_main' => $r->showOnMain,
            ]);
        }
        $public = Slides::findSlides(fn ($q) => $q);

        foreach ($fixtures->views as $index => $view) {
            $this->assertEquals($view->designer, self::wire(Slides::toDesignerSlide($rows[$index])), "designer slide {$view->row->id}");
            $this->assertEquals($view->public, self::wire($public[$index]), "public slide {$view->row->id}");
        }
    }

    public function test_youtube_id_accepts_watch_embed_shorts_live_and_short_links_only(): void
    {
        foreach (['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://youtu.be/dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
            'https://www.youtube.com/shorts/dQw4w9WgXcQ', 'https://www.youtube.com/live/dQw4w9WgXcQ', 'http://m.youtube.com/watch?feature=x&v=dQw4w9WgXcQ'] as $url) {
            $this->assertSame('dQw4w9WgXcQ', Slides::youtubeId($url), $url);
        }
        foreach (['https://vimeo.com/123456789', 'https://youtu.be/short', 'https://evil.test/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ', 'javascript:alert(1)', 'https://www.youtube.com/watch'] as $url) {
            $this->assertNull(Slides::youtubeId($url), $url);
        }
    }
}
