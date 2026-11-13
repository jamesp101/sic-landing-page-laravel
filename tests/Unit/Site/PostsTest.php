<?php

namespace Tests\Unit\Site;

use App\Content\Content;
use App\Models\Post;
use App\Site\Posts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Site\SeedsSite;
use Tests\TestCase;

class PostsTest extends TestCase
{
    use RefreshDatabase, SeedsSite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedOwner();
        $this->makeDepartment('CIT', 'cit', ['id' => 1]);
    }

    public function test_a_summary_excerpt_is_the_clamped_search_text_and_falls_back_to_the_content_only_when_it_is_blank(): void
    {
        $long = str_repeat('word ', 200);
        $this->makePost('indexed', ['content' => 'ignored body', 'search_text' => $long, 'created_at' => $this->day(3)]);
        $this->makePost('unindexed', ['content' => "# Title\n\nFrom the content", 'search_text' => '', 'created_at' => $this->day(2)]);
        $this->makePost('nothing', ['content' => '', 'search_text' => '', 'created_at' => $this->day(1)]);

        $by = array_column(Posts::newest(10), 'excerpt', 'slug');

        $this->assertSame(Content::clampExcerpt($long), $by['indexed']);
        $this->assertSame(Content::excerptOf("# Title\n\nFrom the content"), $by['unindexed']);
        $this->assertSame(Content::excerptOf(''), $by['nothing']);
    }

    public function test_related_posts_never_include_the_post_itself_and_hold_at_most_three(): void
    {
        foreach (range(1, 5) as $n) {
            $this->makePost("p{$n}", ['department_id' => 1, 'created_at' => $this->day($n)]);
        }
        $self = Posts::page('p3')['post'];

        $related = array_column(Posts::related($self), 'slug');

        $this->assertSame(['p5', 'p4', 'p2'], $related);
    }

    public function test_related_posts_without_a_department_are_the_newest_of_the_same_type(): void
    {
        $this->makePost('a', ['created_at' => $this->day(1)]);
        $this->makePost('b', ['created_at' => $this->day(2)]);
        $this->makePost('c', ['created_at' => $this->day(3)]);
        $this->makePost('d', ['created_at' => $this->day(4)]);
        $this->makePost('event', ['type' => 'event', 'created_at' => $this->day(9)]);

        $related = fn (string $slug) => array_column(Posts::related(Posts::page($slug)['post']), 'slug');

        $this->assertSame(['d', 'c', 'b'], $related('a'));
        $this->assertSame([], $related('event'));
    }

    public function test_an_unknown_slug_is_null(): void
    {
        $this->assertNull(Posts::page('nope'));
        $this->assertSame(0, Post::query()->count());
    }
}
