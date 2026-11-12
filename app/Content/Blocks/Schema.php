<?php

declare(strict_types=1);

namespace App\Content\Blocks;

use App\Content\Js;
use stdClass;

/**
 * Validation of block documents: known Block types and props, size limits, safe URLs.
 * {@see validateDocument()} returns a normalised document: unknown props are dropped and defaults are
 * filled, so only sanitised JSON is ever stored. Issues are phrased for editors ("Image 2: Describe the
 * image …") and carry the offending block id.
 *
 * Result shape: `['ok' => true, 'document' => array]` or `['ok' => false, 'issues' => list<array{message: string, blockId?: string}>]`.
 */
final class Schema
{
    /** Decoded JSON nests at most this deep (JSON.parse has no practical limit). */
    private const JSON_DEPTH = 4096;

    public static function validateDocument(mixed $input): array
    {
        $issues = [];
        $seen = [];
        $total = 0;
        $ordinal = 0;

        if (! $input instanceof stdClass
            || ($input->type ?? null) !== Limits::DOCUMENT_TYPE
            || ! Js::isNumber($input->version ?? null) || $input->version != Limits::DOCUMENT_VERSION
            || ! is_array($input->blocks ?? null)) {
            return ['ok' => false, 'issues' => [['message' => 'This content is not a valid block document.']]];
        }

        $walk = function (array $raw, int $depth, bool $rootLevel) use (&$walk, &$issues, &$seen, &$total, &$ordinal): array {
            $out = [];
            foreach ($raw as $item) {
                $shell = self::shell($item);
                if (is_string($shell)) {
                    $issues[] = ['message' => $shell];

                    continue;
                }
                ['id' => $id, 'type' => $type, 'props' => $props, 'children' => $children] = $shell;
                $kind = Registry::get($type);
                $name = $kind->meta()['label'].' '.++$ordinal;
                $fail = static function (string $message) use (&$issues, $id, $name): void {
                    $issues[] = ['blockId' => $id, 'message' => "{$name}: {$message}"];
                };

                if (++$total > Limits::BLOCKS) {
                    if ($total === Limits::BLOCKS + 1) {
                        $issues[] = ['message' => 'Use at most '.Limits::BLOCKS.' elements.'];
                    }

                    continue;
                }
                if (isset($seen[$id])) {
                    $fail('Duplicate element id.');
                }
                $seen[$id] = true;
                if ($depth > Limits::DEPTH) {
                    $fail('Elements can be nested at most '.Limits::DEPTH.' levels deep.');
                }
                if ($kind->rootOnly() && ! $rootLevel) {
                    $fail('Sections can only be placed at the top level.');
                }

                $propIssues = [];
                $parsed = Z::parse($kind->schema(), $props, [], $propIssues);
                if ($propIssues !== []) {
                    foreach ($propIssues as $issue) {
                        $field = $issue['path'] !== [] ? implode(' › ', $issue['path']).': ' : '';
                        $fail($field.$issue['message']);
                    }

                    continue;
                }
                $kind->rules($parsed, static function (string $message, array $path = []) use ($fail): void {
                    $fail($message);
                });

                $block = ['id' => $id, 'type' => $type, 'props' => $parsed];
                if ($children !== null) {
                    if (! $kind->container()) {
                        $fail('This element cannot contain other elements.');
                    } else {
                        $block['children'] = $walk($children, $depth + 1, false);
                    }
                } elseif ($kind->container()) {
                    $block['children'] = [];
                }
                $out[] = $block;
            }

            return $out;
        };

        $blocks = $walk($input->blocks, 1, true);
        if ($issues !== []) {
            return ['ok' => false, 'issues' => $issues];
        }

        return ['ok' => true, 'document' => ['type' => Limits::DOCUMENT_TYPE, 'version' => Limits::DOCUMENT_VERSION, 'blocks' => $blocks]];
    }

    /**
     * A stored JSON string, parsed once: the raw value (when it parses and fits) plus its validation.
     *
     * @return array{raw: mixed, result: array}
     */
    public static function parseBlockJson(string $json): array
    {
        if (Js::length($json) > Limits::DOCUMENT_CHARS) {
            return ['raw' => null, 'result' => ['ok' => false, 'issues' => [['message' => 'This content is too large. Remove some elements.']]]];
        }
        $raw = json_decode($json, false, self::JSON_DEPTH);
        if ($raw === null && json_last_error() !== JSON_ERROR_NONE) {
            return ['raw' => null, 'result' => ['ok' => false, 'issues' => [['message' => 'This content is not valid JSON.']]]];
        }

        return ['raw' => $raw, 'result' => self::validateDocument($raw)];
    }

    /** Parses a stored JSON string and validates it. */
    public static function parseBlockDocument(string $json): array
    {
        return self::parseBlockJson($json)['result'];
    }

    /** Stored form of a validated document, like `JSON.stringify`. */
    public static function serialize(array $document): string
    {
        return json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR);
    }

    /**
     * The shape of one raw block (`id`, `type`, `props`, `children`): the parsed parts, or the message of
     * the first thing wrong with it.
     *
     * @return array{id: string, type: string, props: stdClass, children: list<mixed>|null}|string
     */
    private static function shell(mixed $item): array|string
    {
        if (! $item instanceof stdClass) {
            return 'Invalid input: expected object, received '.Js::kind($item);
        }
        $id = property_exists($item, 'id') ? $item->id : null;
        if (! is_string($id)) {
            return 'Invalid input: expected string, received '.(property_exists($item, 'id') ? Js::kind($id) : 'undefined');
        }
        if (! preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $id)) {
            return 'Invalid block id.';
        }
        $type = $item->type ?? null;
        if (! Registry::isBlockType($type)) {
            return 'Unknown block type.';
        }
        $props = property_exists($item, 'props') ? $item->props : new stdClass;
        if (! $props instanceof stdClass) {
            return 'Invalid input: expected record, received '.Js::kind($props);
        }
        $children = null;
        if (property_exists($item, 'children')) {
            if (! is_array($item->children)) {
                return 'Invalid input: expected array, received '.Js::kind($item->children);
            }
            $children = $item->children;
        }

        return ['id' => $id, 'type' => $type, 'props' => $props, 'children' => $children];
    }
}
