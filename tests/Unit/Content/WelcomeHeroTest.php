<?php

declare(strict_types=1);

namespace Tests\Unit\Content;

use App\Content\WelcomeHero;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WelcomeHeroTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_is_null_until_saved(): void
    {
        $this->assertNull(WelcomeHero::getWelcomeHero());
    }

    public function test_it_upserts_the_one_setting_row(): void
    {
        WelcomeHero::setWelcomeHero('# First');
        WelcomeHero::setWelcomeHero('# Second');

        $this->assertSame('# Second', WelcomeHero::getWelcomeHero());
        $this->assertSame(1, Setting::query()->where('key', 'welcome_hero_content')->count());
        $this->assertSame(1, Setting::query()->count());
    }

    public function test_an_empty_value_is_stored_as_empty_not_missing(): void
    {
        WelcomeHero::setWelcomeHero('');

        $this->assertSame('', WelcomeHero::getWelcomeHero());
    }
}
