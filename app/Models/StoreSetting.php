<?php

namespace App\Models;

use App\Services\ImageService;
use Database\Factories\StoreSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The store's settings, one row per section.
 *
 * The shape follows App\Models\ShippingSetting — a key/value table the
 * admin screens read and write — with one difference: the value is
 * stored as JSON so a section keeps the types it was saved with. The
 * store section carries booleans, numbers and a nested social block;
 * a plain string column would turn every one of them into text.
 */
class StoreSetting extends Model
{
    /** @use HasFactory<StoreSettingFactory> */
    use HasFactory;

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

        if (! array_key_exists($key, $settings)) {
            return $default;
        }

        return static::decode($settings[$key]);
    }

    /**
     * Create or update a setting.
     */
    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
        );

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
     */
    public static function flush(): void
    {
        static::$cache = null;
    }

    /**
     * The URL of the store's logo: the one the admin uploaded, or the
     * logo that ships with the app.
     */
    public static function logoUrl(): string
    {
        return ImageService::publicUrl(static::get('store.logo')) ?: asset('images/abo-logo.svg');
    }

    /**
     * The URL of the store's favicon: the one the admin uploaded, or
     * the logo that ships with the app.
     */
    public static function faviconUrl(): string
    {
        return ImageService::publicUrl(static::get('store.favicon')) ?: asset('images/abo-logo.svg');
    }

    /**
     * The name the store trades under.
     */
    public static function storeName(): string
    {
        return (string) (static::get('store.name') ?: 'ABO');
    }

    /**
     * A stored value, decoded back into the type it was saved with.
     *
     * A row written before the section was JSON — or by hand — is not
     * valid JSON, and then the raw text is the value.
     */
    protected static function decode(mixed $raw): mixed
    {
        if ($raw === null) {
            return null;
        }

        $decoded = json_decode((string) $raw, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
    }
}
