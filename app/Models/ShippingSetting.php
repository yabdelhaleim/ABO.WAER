<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingSetting extends Model
{
    protected $guarded = [];

    /**
     * Per-request memo of the settings table so reading every key costs
     * a single query. Invalidated by set() so writes are always read back.
     *
     * @var array<string, string|null>|null
     */
    protected static ?array $cache = null;

    /**
     * Clear the in-memory memo cache.
     *
     * The same thing flush() does, under the name the query-count
     * measurements use.
     */
    public static function clearCache(): void
    {
        static::flush();
    }

    /**
     * Read a single setting, falling back to $default when the key is absent.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $settings = static::allSettings();

        return array_key_exists($key, $settings) ? $settings[$key] : $default;
    }

    /**
     * Create or update a setting.
     */
    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);

        static::$cache = null;
    }

    /**
     * Every stored setting, keyed by setting key.
     *
     * @return array<string, string|null>
     */
    public static function allSettings(): array
    {
        return static::$cache ??= static::query()->pluck('value', 'key')->all();
    }

    /**
     * Forget the memo — for a write that did not go through set().
     *
     * The mirror of StoreSetting::flush(), so a test (or any caller
     * that edits the table directly) can force the next read to go
     * back to the database.
     */
    public static function flush(): void
    {
        static::$cache = null;
    }
}
