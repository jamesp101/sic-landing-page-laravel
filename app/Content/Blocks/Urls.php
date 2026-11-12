<?php

declare(strict_types=1);

namespace App\Content\Blocks;

use App\Content\Js;

/** URL filtering shared by the validator and the renderer. Returns `null` for anything unsafe. */
final class Urls
{
    private const CONTROL_OR_SPACE = '/[\x{0}-\x{20}\x{7f}-\x{9f}\x{200b}-\x{200f}\x{2028}\x{2029}\x{feff}]/u';

    private const LINK_SCHEMES = ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true];

    private const IMAGE_SCHEMES = ['http' => true, 'https' => true];

    private const VIDEO_FILE = '#^/storage/(videos/[0-9a-f]{32}\.(mp4|webm))$#D';

    private const YOUTUBE_HOSTS = [
        'youtube.com' => true,
        'www.youtube.com' => true,
        'm.youtube.com' => true,
        'youtube-nocookie.com' => true,
        'www.youtube-nocookie.com' => true,
    ];

    /** Link target: http(s), mailto, tel, site-relative paths and `#fragments`. */
    public static function safeHref(mixed $url): ?string
    {
        return self::filter($url, self::LINK_SCHEMES);
    }

    /** Image source: http(s) or a site-relative path (uploads live under `/storage/…`). */
    public static function safeImageSrc(mixed $url): ?string
    {
        return self::filter($url, self::IMAGE_SCHEMES);
    }

    /** Whether a (safe) href leaves this site and should open with `rel="noopener noreferrer"`. */
    public static function isExternalHref(string $href): bool
    {
        return (bool) preg_match('#^https?://#i', $href);
    }

    /**
     * Accepts YouTube / Vimeo page or embed URLs and our own uploaded videos; everything else is rejected.
     * Our own videos are only the app route `/storage/videos/<32 hex>.mp4|webm` (it redirects to the bucket
     * when one serves files directly).
     *
     * @return array{provider: string, id: string, embedUrl: string, mime?: string}|null
     */
    public static function parseVideoUrl(mixed $raw): ?array
    {
        if (! is_string($raw)) {
            return null;
        }
        $raw = Js::trim(Js::scrub($raw));
        if (preg_match(self::VIDEO_FILE, $raw, $match)) {
            return ['provider' => 'file', 'id' => $match[1], 'embedUrl' => $raw, 'mime' => $match[2] === 'webm' ? 'video/webm' : 'video/mp4'];
        }

        $url = self::parseUrl($raw);
        if ($url === null) {
            return null;
        }
        $host = $url['host'];
        $parts = array_values(array_filter(explode('/', $url['path']), static fn (string $part): bool => $part !== ''));

        $youtube = null;
        if ($host === 'youtu.be') {
            $youtube = $parts[0] ?? null;
        } elseif (isset(self::YOUTUBE_HOSTS[$host])) {
            if (($parts[0] ?? null) === 'watch') {
                $youtube = self::queryParam($url['query'], 'v');
            } elseif (in_array($parts[0] ?? null, ['embed', 'shorts', 'live'], true)) {
                $youtube = $parts[1] ?? null;
            }
        }
        if ($youtube !== null && preg_match('/^[A-Za-z0-9_-]{11}$/D', $youtube)) {
            return ['provider' => 'youtube', 'id' => $youtube, 'embedUrl' => "https://www.youtube-nocookie.com/embed/{$youtube}"];
        }

        if ($host === 'vimeo.com' || $host === 'www.vimeo.com' || $host === 'player.vimeo.com') {
            $id = $host === 'player.vimeo.com' ? (($parts[0] ?? null) === 'video' ? ($parts[1] ?? null) : null) : ($parts[0] ?? null);
            if ($id !== null && preg_match('/^\d{5,12}$/D', $id)) {
                return ['provider' => 'vimeo', 'id' => $id, 'embedUrl' => "https://player.vimeo.com/video/{$id}?dnt=1"];
            }
        }

        return null;
    }

    /**
     * The part of `new URL(raw)` the video parser needs, for http(s) URLs only (`null` for anything else or
     * anything WHATWG parsing rejects): lower-case ASCII host, normalised path, raw query.
     *
     * @return array{host: string, path: string, query: string|null}|null
     */
    private static function parseUrl(string $raw): ?array
    {
        $input = str_replace(["\t", "\n", "\r"], '', $raw);
        if (! preg_match('/^(https?):(.*)$/Dis', $input, $match)) {
            return null;
        }
        $rest = ltrim($match[2], '/\\');
        $fragment = strpos($rest, '#');
        if ($fragment !== false) {
            $rest = substr($rest, 0, $fragment);
        }
        $question = strpos($rest, '?');
        $query = $question === false ? null : substr($rest, $question + 1);
        if ($question !== false) {
            $rest = substr($rest, 0, $question);
        }
        $slash = strcspn($rest, '/\\');
        $authority = substr($rest, 0, $slash);
        $path = str_replace('\\', '/', substr($rest, $slash));

        $at = strrpos($authority, '@');
        $hostPort = $at === false ? $authority : substr($authority, $at + 1);
        if (! preg_match('/^(.*?)(?::(\d*))?$/Ds', $hostPort, $hp) || str_starts_with($hostPort, '[')) {
            return null;
        }
        if (isset($hp[2]) && $hp[2] !== '' && (int) $hp[2] > 65535) {
            return null;
        }
        $host = strtolower(rawurldecode($hp[1]));
        if ($host === '' || preg_match('/[\x00-\x20#\/:<>?@\[\\\]^|%\x7f]/', $host)) {
            return null;
        }
        if (preg_match('/[^\x00-\x7f]/', $host)) {
            $host = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            if ($host === false || $host === '') {
                return null;
            }
        }

        // Dot segments are resolved like WHATWG does (including their percent-encoded forms).
        $segments = [];
        $pieces = explode('/', $path);
        array_shift($pieces);
        foreach ($pieces as $index => $piece) {
            $lower = strtolower($piece);
            $last = $index === count($pieces) - 1;
            if ($lower === '..' || $lower === '.%2e' || $lower === '%2e.' || $lower === '%2e%2e') {
                array_pop($segments);
                if ($last) {
                    $segments[] = '';
                }
            } elseif ($lower === '.' || $lower === '%2e') {
                if ($last) {
                    $segments[] = '';
                }
            } else {
                $segments[] = $piece;
            }
        }

        return ['host' => $host, 'path' => '/'.implode('/', $segments), 'query' => $query];
    }

    /** `URLSearchParams.get`: the first value, `+` and percent escapes decoded. */
    private static function queryParam(?string $query, string $name): ?string
    {
        if ($query === null || $query === '') {
            return null;
        }
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            $eq = strpos($pair, '=');
            $key = urldecode($eq === false ? $pair : substr($pair, 0, $eq));
            if ($key === $name) {
                return $eq === false ? '' : urldecode(substr($pair, $eq + 1));
            }
        }

        return null;
    }

    /** @param array<string, true> $schemes */
    private static function filter(mixed $url, array $schemes): ?string
    {
        if (! is_string($url)) {
            return null;
        }
        $trimmed = Js::trim(Js::scrub($url));
        if ($trimmed === '') {
            return null;
        }
        // Browsers strip tabs/newlines/zero-width characters inside schemes, so test the stripped form.
        $probe = (string) preg_replace(self::CONTROL_OR_SPACE, '', $trimmed);
        if (preg_match('#^(?://|\\\\|/\\\\)#', $probe)) {
            return null;
        }
        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $probe, $scheme)) {
            return isset($schemes[strtolower($scheme[1])]) ? $trimmed : null;
        }

        // No scheme: only an absolute path, a relative path or a fragment/query is accepted.
        $first = preg_split('#[/?\#]#', $probe, 2)[0];

        return str_contains($first, ':') ? null : $trimmed;
    }
}
