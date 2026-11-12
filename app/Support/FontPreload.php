<?php

namespace App\Support;

use Illuminate\Support\Facades\Vite;

/**
 * The two font files every page paints its first text with (Inter for body text, Playfair Display for h1/h2),
 * as built asset URLs for `<link rel="preload">`. The stylesheet names them only after it has loaded, so without
 * the preload the largest text of a page (usually a serif heading) waits one more round trip.
 */
final class FontPreload
{
    /** Source file names inside the @fontsource packages; the latin subsets cover the site's English content. */
    private const FILES = [
        '/inter/files/inter-latin-wght-normal.woff2',
        '/playfair-display/files/playfair-display-latin-wght-normal.woff2',
    ];

    /** @var list<string>|null */
    private static ?array $hrefs = null;

    /**
     * @return list<string> empty while the Vite dev server runs or before the first build
     */
    public static function hrefs(): array
    {
        if (Vite::isRunningHot()) {
            return [];
        }

        return self::$hrefs ??= self::fromManifest(public_path('build/manifest.json'));
    }

    /** @return list<string> */
    public static function fromManifest(string $path): array
    {
        $manifest = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($manifest)) {
            return [];
        }
        $hrefs = [];
        foreach (self::FILES as $suffix) {
            foreach ($manifest as $source => $entry) {
                if (str_ends_with((string) $source, $suffix) && is_string($entry['file'] ?? null)) {
                    $hrefs[] = '/build/'.$entry['file'];
                    break;
                }
            }
        }

        return $hrefs;
    }
}
