<?php

namespace App\Site;

use App\Carousel\Slides;
use App\Content\WelcomeHero;

/** The home page's view data. */
final class HomePage
{
    /** @return array{carousel: array, latestPosts: array, welcomeHero: ?string, heroSections: array, layout: array} */
    public static function read(): array
    {
        $layout = SiteLayout::read();

        return [
            'carousel' => Slides::findSlides(['showOnMain' => true]),
            'latestPosts' => Posts::newest(6),
            'welcomeHero' => WelcomeHero::getWelcomeHero(),
            'heroSections' => HeroSections::active(null),
            'layout' => $layout,
        ];
    }
}
