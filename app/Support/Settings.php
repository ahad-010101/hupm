<?php

namespace App\Support;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Typed access to the `settings` table, plus the gated-decision register.
 *
 * Twenty client questions are open and five block development
 * (06-Implementation-Plan.md §6). Rather than hard-code a guess, each ships as
 * a row here with a documented default, so a late answer is a configuration
 * change instead of a code change.
 *
 * `is_gated` marks the rows that are defaults rather than answers.
 * `unconfirmedGatedKeys()` is what WP-35 checks: go-live cannot complete while
 * any of them is still NULL-confirmed. Shipping a sensible default and having
 * a client decision are different states, and this is what keeps them apart.
 */
class Settings
{
    private const CACHE_KEY = 'hupm.settings';

    /** @var array<string, array{value: ?string, type: string}>|null */
    private ?array $loaded = null;

    public function __construct(private readonly Cache $cache) {}

    public function string(string $key, string $default = ''): string
    {
        $value = $this->raw($key);

        return $value === null ? $default : $value;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->raw($key);

        return $value === null || $value === '' ? $default : (int) $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->raw($key);

        if ($value === null || $value === '') {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return array<array-key, mixed> */
    public function json(string $key, array $default = []): array
    {
        $value = $this->raw($key);

        if ($value === null || $value === '') {
            return $default;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : $default;
    }

    /**
     * A monetary setting. Parsed through Money so a malformed value fails loudly
     * here rather than becoming a float somewhere downstream.
     */
    public function money(string $key, ?Money $default = null): Money
    {
        $value = $this->raw($key);

        if ($value === null || $value === '') {
            return $default ?? Money::zero();
        }

        return Money::fromString($value);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    public function set(string $key, string|int|bool|null $value): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => $key],
            ['value' => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value, 'updated_at' => now()],
        );

        $this->flush();
    }

    /**
     * Record that the client has confirmed a gated decision.
     *
     * Deliberately separate from set(): confirming the shipped default is a
     * legitimate outcome, and must be recorded as a decision rather than left
     * looking like nobody ever asked.
     */
    public function confirm(string $key, int $userId): void
    {
        $affected = DB::table('settings')->where('key', $key)->update([
            'confirmed_at' => now(),
            'confirmed_by_user_id' => $userId,
        ]);

        if ($affected === 0) {
            throw new RuntimeException("Cannot confirm unknown setting '{$key}'.");
        }

        $this->flush();
    }

    /**
     * Gated decisions still awaiting a client answer.
     *
     * WP-35 blocks while this is non-empty.
     *
     * @return list<string>
     */
    public function unconfirmedGatedKeys(): array
    {
        return DB::table('settings')
            ->where('is_gated', true)
            ->whereNull('confirmed_at')
            ->orderBy('key')
            ->pluck('key')
            ->all();
    }

    public function flush(): void
    {
        $this->loaded = null;
        $this->cache->forget(self::CACHE_KEY);
    }

    private function raw(string $key): ?string
    {
        return $this->all()[$key]['value'] ?? null;
    }

    /** @return array<string, array{value: ?string, type: string}> */
    private function all(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        // Cached because settings are read on nearly every request, and the
        // cache driver is `database` on this host — one query either way, but
        // the cache survives across the request for repeated reads.
        return $this->loaded = $this->cache->rememberForever(self::CACHE_KEY, function (): array {
            return DB::table('settings')
                ->get(['key', 'value', 'type'])
                ->keyBy('key')
                ->map(fn ($row) => ['value' => $row->value, 'type' => $row->type])
                ->all();
        });
    }
}
