<?php

namespace App\Actions;

use ReflectionClass;
use ReflectionMethod;

/**
 * The declared actions: `module.action` → `App\Actions\{Module}Actions::{action}()` with its `#[Action]` access.
 * `ActionController` dispatches through `find`; the request policy asks `isPublic` for the signed-out allowance,
 * so the public list exists only as declarations.
 */
final class ActionRegistry
{
    /** The declared action `module.action`: `[class, method, access]`, or null when there is none (unknown, non-public, static or undeclared). */
    public static function find(string $name): ?array
    {
        if (preg_match('/^([a-z][A-Za-z]*)\.([a-z][A-Za-z]*)$/', $name, $match) !== 1) {
            return null;
        }
        $class = 'App\\Actions\\'.ucfirst($match[1]).'Actions';
        if (! class_exists($class) || ! method_exists($class, $match[2])) {
            return null;
        }
        $method = new ReflectionMethod($class, $match[2]);
        $access = self::accessOf($method, $class);

        return $access === null ? null : [$class, $match[2], $access];
    }

    public static function isPublic(string $name): bool
    {
        return (self::find($name)[2] ?? null) === Access::Public;
    }

    /**
     * Every public action method of `app/Actions/*Actions.php`: `module.action` → its declared access, null when undeclared.
     *
     * @return array<string, Access|null>
     */
    public static function all(): array
    {
        $found = [];
        foreach (glob(__DIR__.'/*Actions.php') ?: [] as $file) {
            $module = basename($file, 'Actions.php');
            $class = 'App\\Actions\\'.$module.'Actions';
            foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isConstructor() || self::isDispatchable($method, $class) === false) {
                    continue;
                }
                $found[lcfirst($module).'.'.$method->getName()] = self::accessOf($method, $class);
            }
        }
        ksort($found);

        return $found;
    }

    /** @return list<string> */
    public static function publicNames(): array
    {
        return array_keys(array_filter(self::all(), fn (?Access $access): bool => $access === Access::Public));
    }

    private static function isDispatchable(ReflectionMethod $method, string $class): bool
    {
        return $method->isPublic() && ! $method->isStatic() && $method->getDeclaringClass()->getName() === $class && ! str_starts_with($method->getName(), '__');
    }

    private static function accessOf(ReflectionMethod $method, string $class): ?Access
    {
        if (! self::isDispatchable($method, $class)) {
            return null;
        }
        $declared = $method->getAttributes(Action::class)[0] ?? null;

        return $declared?->newInstance()->access;
    }
}
