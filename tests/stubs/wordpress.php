<?php

/**
 * A small stand-in for the WordPress functions spend-meter calls, so the
 * suite runs without a WordPress install. State lives in WpStub and is reset
 * before every test.
 */

declare(strict_types=1);

// phpcs:disable PSR1.Files.SideEffects,PSR1.Classes.ClassDeclaration,Squiz.Functions.GlobalFunction

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

final class WpStub
{
    /** @var array<string, array<int, list<array{0: callable, 1: int}>>> */
    public static array $hooks = [];
    /** @var array<string, int> */
    public static array $fired = [];
    /** @var list<array{0: string, 1: array<mixed>}> */
    public static array $actions = [];
    /** @var array<string, mixed> */
    public static array $options = [];
    /** @var array<string, mixed> */
    public static array $siteOptions = [];
    /** @var array<string, bool> */
    public static array $autoload = [];
    /** @var array<string, array{0: mixed, 1: int}> */
    public static array $transients = [];
    /** @var array<string, mixed> */
    public static array $cache = [];
    /** @var array<string, int> */
    public static array $cron = [];
    /** @var list<array{0: string, 1: string}> */
    public static array $widgets = [];
    /** @var list<string> */
    public static array $menus = [];
    public static bool $multisite = false;
    public static int $blogId = 1;
    public static int $userId = 1;
    /** @var array<string, bool> */
    public static array $caps = ['manage_options' => true, 'manage_network_options' => true];
    public static string $timezone = 'UTC';
    public static bool $extCache = false;
    public static int $uuid = 0;
    public static ?string $redirect = null;
    /** @var list<int> */
    public static array $sites = [1];

    public static function reset(): void
    {
        self::$hooks = [];
        self::$fired = [];
        self::$actions = [];
        self::$options = [];
        self::$siteOptions = [];
        self::$autoload = [];
        self::$transients = [];
        self::$cache = [];
        self::$cron = [];
        self::$widgets = [];
        self::$menus = [];
        self::$multisite = false;
        self::$blogId = 1;
        self::$userId = 1;
        self::$caps = ['manage_options' => true, 'manage_network_options' => true];
        self::$timezone = 'UTC';
        self::$extCache = false;
        self::$uuid = 0;
        self::$redirect = null;
        self::$sites = [1];
    }

    /**
     * Calls recorded for one action name.
     *
     * @return list<array<mixed>>
     */
    public static function calls(string $hook): array
    {
        $out = [];
        foreach (self::$actions as [$name, $args]) {
            if ($name === $hook) {
                $out[] = $args;
            }
        }
        return $out;
    }
}

final class WpDieException extends RuntimeException
{
}

function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted = 1): bool
{
    WpStub::$hooks[$hook][$priority][] = [$callback, $accepted];
    ksort(WpStub::$hooks[$hook]);
    return true;
}

function add_action(string $hook, callable $callback, int $priority = 10, int $accepted = 1): bool
{
    return add_filter($hook, $callback, $priority, $accepted);
}

function has_filter(string $hook): bool
{
    return isset(WpStub::$hooks[$hook]) && [] !== WpStub::$hooks[$hook];
}

function has_action(string $hook): bool
{
    return has_filter($hook);
}

function remove_all_filters(string $hook): bool
{
    unset(WpStub::$hooks[$hook]);
    return true;
}

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    foreach (WpStub::$hooks[$hook] ?? [] as $callbacks) {
        foreach ($callbacks as [$callback, $accepted]) {
            $value = $callback(...array_slice(array_merge([$value], $args), 0, $accepted));
        }
    }
    return $value;
}

function do_action(string $hook, mixed ...$args): void
{
    WpStub::$fired[$hook] = (WpStub::$fired[$hook] ?? 0) + 1;
    WpStub::$actions[] = [$hook, $args];
    foreach (WpStub::$hooks[$hook] ?? [] as $callbacks) {
        foreach ($callbacks as [$callback, $accepted]) {
            $callback(...array_slice($args, 0, $accepted));
        }
    }
}

function did_action(string $hook): int
{
    return WpStub::$fired[$hook] ?? 0;
}

function get_option(string $name, mixed $default = false): mixed
{
    return array_key_exists($name, WpStub::$options) ? WpStub::$options[$name] : $default;
}

function update_option(string $name, mixed $value, bool|string|null $autoload = null): bool
{
    WpStub::$options[$name] = $value;
    WpStub::$autoload[$name] = true === $autoload || 'yes' === $autoload || null === $autoload;
    return true;
}

function add_option(string $name, mixed $value = '', string $deprecated = '', bool|string $autoload = true): bool
{
    return update_option($name, $value, (bool) $autoload);
}

function delete_option(string $name): bool
{
    unset(WpStub::$options[$name], WpStub::$autoload[$name]);
    return true;
}

function get_site_option(string $name, mixed $default = false): mixed
{
    if (!WpStub::$multisite) {
        return get_option($name, $default);
    }
    return array_key_exists($name, WpStub::$siteOptions) ? WpStub::$siteOptions[$name] : $default;
}

function update_site_option(string $name, mixed $value): bool
{
    if (!WpStub::$multisite) {
        return update_option($name, $value);
    }
    WpStub::$siteOptions[$name] = $value;
    return true;
}

function delete_site_option(string $name): bool
{
    if (!WpStub::$multisite) {
        return delete_option($name);
    }
    unset(WpStub::$siteOptions[$name]);
    return true;
}

function get_transient(string $key): mixed
{
    $entry = WpStub::$transients['t:' . $key] ?? null;
    return null === $entry ? false : $entry[0];
}

function set_transient(string $key, mixed $value, int $ttl = 0): bool
{
    WpStub::$transients['t:' . $key] = [$value, $ttl];
    return true;
}

function delete_transient(string $key): bool
{
    unset(WpStub::$transients['t:' . $key]);
    return true;
}

function get_site_transient(string $key): mixed
{
    $entry = WpStub::$transients['s:' . $key] ?? null;
    return null === $entry ? false : $entry[0];
}

function set_site_transient(string $key, mixed $value, int $ttl = 0): bool
{
    WpStub::$transients['s:' . $key] = [$value, $ttl];
    return true;
}

function delete_site_transient(string $key): bool
{
    unset(WpStub::$transients['s:' . $key]);
    return true;
}

function wp_cache_get(string $key, string $group = ''): mixed
{
    return WpStub::$cache[$group . ':' . $key] ?? false;
}

function wp_cache_set(string $key, mixed $value, string $group = '', int $ttl = 0): bool
{
    WpStub::$cache[$group . ':' . $key] = $value;
    return true;
}

function wp_cache_delete(string $key, string $group = ''): bool
{
    unset(WpStub::$cache[$group . ':' . $key]);
    return true;
}

function wp_using_ext_object_cache(): bool
{
    return WpStub::$extCache;
}

function is_multisite(): bool
{
    return WpStub::$multisite;
}

function get_current_blog_id(): int
{
    return WpStub::$blogId;
}

function get_current_user_id(): int
{
    return WpStub::$userId;
}

/**
 * @param array<string, mixed> $args
 * @return list<int>
 */
function get_sites(array $args = []): array
{
    return WpStub::$sites;
}

function current_user_can(string $cap): bool
{
    return WpStub::$caps[$cap] ?? false;
}

function wp_create_nonce(string $action): string
{
    return 'nonce-' . $action;
}

function wp_verify_nonce(string $nonce, string $action): int|false
{
    return 'nonce-' . $action === $nonce ? 1 : false;
}

function wp_nonce_url(string $url, string $action): string
{
    return add_query_arg('_wpnonce', wp_create_nonce($action), $url);
}

function wp_nonce_field(string $action, string $name = '_wpnonce', bool $referer = true, bool $display = true): string
{
    return '<input type="hidden" name="' . $name . '" value="' . wp_create_nonce($action) . '">';
}

/**
 * @param string|array<string, string> $key
 */
function add_query_arg(string|array $key, ?string $value = null, ?string $url = null): string
{
    if (is_array($key)) {
        $url = $value ?? '';
        $pairs = $key;
    } else {
        $url ??= '';
        $pairs = [$key => (string) $value];
    }
    return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($pairs);
}

function admin_url(string $path = ''): string
{
    return 'https://example.test/wp-admin/' . $path;
}

function network_admin_url(string $path = ''): string
{
    return 'https://example.test/wp-admin/network/' . $path;
}

function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_attr(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_textarea(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_url(string $url): string
{
    return 1 === preg_match('#^https?://#', $url) ? htmlspecialchars($url, ENT_QUOTES, 'UTF-8') : '';
}

function wp_json_encode(mixed $data, int $flags = 0): string|false
{
    return json_encode($data, $flags);
}

function wp_generate_uuid4(): string
{
    WpStub::$uuid++;
    return sprintf('00000000-0000-4000-8000-%012d', WpStub::$uuid);
}

function wp_timezone(): DateTimeZone
{
    return new DateTimeZone(WpStub::$timezone);
}

function wp_next_scheduled(string $hook): int|false
{
    return WpStub::$cron[$hook] ?? false;
}

/**
 * @param array<mixed> $args
 */
function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = []): bool
{
    WpStub::$cron[$hook] = $timestamp;
    return true;
}

function wp_clear_scheduled_hook(string $hook): int
{
    unset(WpStub::$cron[$hook]);
    return 1;
}

function register_activation_hook(string $file, callable $callback): void
{
    add_action('activate_spend-meter', $callback);
}

function register_deactivation_hook(string $file, callable $callback): void
{
    add_action('deactivate_spend-meter', $callback);
}

function wp_add_dashboard_widget(string $id, string $title, callable $callback): void
{
    WpStub::$widgets[] = [$id, $title];
}

function add_options_page(string $title, string $menu, string $cap, string $slug, callable $callback): string
{
    WpStub::$menus[] = 'options:' . $slug . ':' . $cap;
    return $slug;
}

function add_submenu_page(string $parent, string $title, string $menu, string $cap, string $slug, callable $callback): string
{
    WpStub::$menus[] = $parent . ':' . $slug . ':' . $cap;
    return $slug;
}

function wp_unslash(mixed $value): mixed
{
    return $value;
}

function wp_die(string $message = '', mixed $title = '', mixed $args = []): never
{
    throw new WpDieException($message);
}

function wp_safe_redirect(string $location, int $status = 302): bool
{
    WpStub::$redirect = $location;
    return true;
}
