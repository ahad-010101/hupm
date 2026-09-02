<?php

namespace App\Support;

use Closure;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\Request;

/**
 * Resolves `?sort=` and `?direction=` for an admin list screen.  [WP-38]
 *
 * Three registry screens need identical behaviour, and all three would
 * otherwise be one careless line away from `orderBy($request->input('sort'))` —
 * which is a SQL injection, because `orderBy` interpolates its column name
 * rather than binding it. So the sort key never reaches the query at all: it is
 * only ever a *lookup* into a whitelist the controller declares, and anything
 * not in that whitelist is silently the default.
 *
 * Silently, not an error. A stale bookmark, a hand-edited URL or a column
 * removed in a later release should show the list, not a 500 — the sort order
 * is a presentational preference and nothing downstream depends on it.
 *
 * The whitelist maps a public key to either a column name or a closure, so a
 * screen can order by something that is not a column (a curated status
 * precedence, a correlated subquery) without this class knowing anything about
 * it.
 */
final class ListSort
{
    private const ASCENDING = 'asc';

    private const DESCENDING = 'desc';

    /**
     * @param  array<string, string|Closure>  $allowed  public key => column or closure
     * @return array{key: string, direction: string}
     */
    public static function resolve(
        Request $request,
        array $allowed,
        string $default,
        string $defaultDirection = self::DESCENDING,
    ): array {
        $key = $request->string('sort')->trim()->value();

        if (! array_key_exists($key, $allowed)) {
            $key = $default;
        }

        return ['key' => $key, 'direction' => self::direction($request, $key, $default, $defaultDirection)];
    }

    /**
     * Apply a resolved sort to a query.
     *
     * @param  array{key: string, direction: string}  $sort
     * @param  array<string, string|Closure>  $allowed
     */
    public static function apply(Builder $query, array $sort, array $allowed): Builder
    {
        $target = $allowed[$sort['key']];

        if ($target instanceof Closure) {
            $target($query, $sort['direction']);

            return $query;
        }

        return $query->orderBy($target, $sort['direction']);
    }

    /**
     * An explicit direction always wins.
     *
     * Without one, the default column keeps the caller's stated default —
     * "newest added first" — while any other column starts ascending, which is
     * what a first click on a name or a date is understood to mean. Only the
     * first click is ever ambiguous: after that the UI sends the direction.
     */
    private static function direction(Request $request, string $key, string $default, string $defaultDirection): string
    {
        $requested = strtolower($request->string('direction')->trim()->value());

        if (in_array($requested, [self::ASCENDING, self::DESCENDING], true)) {
            return $requested;
        }

        return $key === $default ? $defaultDirection : self::ASCENDING;
    }
}
