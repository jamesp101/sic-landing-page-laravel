<?php

namespace App\Storage;

/** Finds which stored keys occur in a text as a bare key or as the tail of a URL/path (`/storage/<key>`, the S3_PUBLIC_URL form). */
final class KeyMatcher
{
    private const SAFE_KEY = '#^[\w.\-/]+$#D';

    private const TOKEN = '#[^\s"\'`()<>\[\]{},;|\\\\?\\#]+#';

    /** @var array<string, true> */
    private array $plain = [];

    /** @var list<string> */
    private array $unusual = [];

    /** @param  iterable<string>  $keys */
    public function __construct(iterable $keys)
    {
        foreach ($keys as $key) {
            if (preg_match(self::SAFE_KEY, $key) === 1) {
                $this->plain[$key] = true;
            } else {
                $this->unusual[] = $key;
            }
        }
    }

    /** @param  callable(string): void  $onHit */
    public function find(string $text, callable $onHit): void
    {
        $normalised = str_replace('\\/', '/', $text);
        if (preg_match_all(self::TOKEN, $normalised, $tokens) > 0) {
            foreach ($tokens[0] as $token) {
                if (! str_contains($token, '/')) {
                    continue;
                }
                // `https://cdn/prefix/posts/a.webp` and `/storage/posts/a.webp` both end in the key.
                $parts = explode('/', $token);
                for ($i = 0, $n = count($parts); $i < $n; $i++) {
                    $suffix = implode('/', array_slice($parts, $i));
                    if (isset($this->plain[$suffix])) {
                        $onHit($suffix);
                    }
                }
            }
        }
        foreach ($this->unusual as $key) {
            if (str_contains($normalised, $key) || str_contains($normalised, self::encodeUri($key))) {
                $onHit($key);
            }
        }
    }

    /** JavaScript `encodeURI`: keeps reserved characters and `-_.!~*'()`. */
    private static function encodeUri(string $value): string
    {
        return strtr(rawurlencode($value), [
            '%3B' => ';', '%2C' => ',', '%2F' => '/', '%3F' => '?', '%3A' => ':', '%40' => '@', '%26' => '&',
            '%3D' => '=', '%2B' => '+', '%24' => '$', '%23' => '#', '%21' => '!', '%2A' => '*', '%27' => "'",
            '%28' => '(', '%29' => ')',
        ]);
    }
}
