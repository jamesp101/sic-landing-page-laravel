<?php

namespace Tests\Unit\Support;

use App\Support\FontPreload;
use PHPUnit\Framework\TestCase;

class FontPreloadTest extends TestCase
{
    public function test_it_finds_the_latin_files_of_both_fonts_whatever_the_package_path(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'manifest');
        file_put_contents($path, json_encode([
            'node_modules/.pnpm/@fontsource-variable+inter@5.3.0/node_modules/@fontsource-variable/inter/files/inter-latin-ext-wght-normal.woff2' => ['file' => 'assets/inter-latin-ext-A.woff2'],
            'node_modules/.pnpm/@fontsource-variable+inter@5.3.0/node_modules/@fontsource-variable/inter/files/inter-latin-wght-normal.woff2' => ['file' => 'assets/inter-latin-B.woff2'],
            'node_modules/@fontsource-variable/playfair-display/files/playfair-display-latin-wght-normal.woff2' => ['file' => 'assets/playfair-C.woff2'],
            'resources/js/site.ts' => ['file' => 'assets/site-D.js'],
        ]));

        $this->assertSame(['/build/assets/inter-latin-B.woff2', '/build/assets/playfair-C.woff2'], FontPreload::fromManifest($path));
        unlink($path);
    }

    public function test_a_missing_or_broken_manifest_preloads_nothing(): void
    {
        $this->assertSame([], FontPreload::fromManifest('/nonexistent/manifest.json'));
        $path = tempnam(sys_get_temp_dir(), 'manifest');
        file_put_contents($path, 'not json');
        $this->assertSame([], FontPreload::fromManifest($path));
        unlink($path);
    }
}
