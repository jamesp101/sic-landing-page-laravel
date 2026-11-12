<?php

declare(strict_types=1);

namespace App\Content;

use App\Models\Setting;

/**
 * The Welcome hero: the rich content at the top of the home page, stored as the one Setting row under this
 * key. Everything that reads or writes it goes through here.
 */
final class WelcomeHero
{
    private const KEY = 'welcome_hero_content';

    /** The stored content, or null while none was ever saved. */
    public static function getWelcomeHero(): ?string
    {
        return Setting::query()->where('key', self::KEY)->value('value');
    }

    public static function setWelcomeHero(string $value): void
    {
        Setting::query()->updateOrCreate(['key' => self::KEY], ['value' => $value]);
    }
}
