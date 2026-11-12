<?php

namespace App\Admin;

use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Case-insensitive substring search. SQLite and MySQL compare ASCII case-insensitively with LIKE already.
 */
final class Search
{
    /** `%value%` with LIKE wildcards of the value escaped (escape character `!`). */
    public static function textContains(string $value): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value).'%';
    }

    /**
     * Closure matching rows where ANY of `$columns` contains `$value` (one parenthesised OR group).
     *
     * @param  list<string>  $columns
     * @return Closure(Builder): void
     */
    public static function anyOf(array $columns, string $value): Closure
    {
        $pattern = self::textContains($value);

        return static function (Builder $q) use ($columns, $pattern): void {
            $q->where(static function (Builder $group) use ($columns, $pattern): void {
                $grammar = $group->getQuery()->getGrammar();
                foreach ($columns as $column) {
                    $group->orWhereRaw($grammar->wrap($column)." like ? escape '!'", [$pattern]);
                }
            });
        };
    }
}
