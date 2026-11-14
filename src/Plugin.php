<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter;

use IndianOpinion\SpendMeter\Admin\Notices;
use IndianOpinion\SpendMeter\Admin\SettingsPage;
use IndianOpinion\SpendMeter\Admin\Widget;
use IndianOpinion\SpendMeter\Store\Store;
use IndianOpinion\SpendMeter\Store\WpdbStore;
use Throwable;

/**
 * Service container and hook registration.
 *
 * Hooks are registered when the plugin file loads. Services are built on
 * first use, so a front-end page view that makes no model call runs no query.
 */
final class Plugin
{
    private static ?Plugin $instance = null;

    private ?Store $store;
    private Clock $clock;
    private ?Settings $settings = null;
    private ?PriceTable $prices = null;
    private ?Caps $caps = null;
    private ?KillSwitch $kill = null;
    private ?Alerts $alerts = null;
    private ?Counters $counters = null;
    private ?Decision $decision = null;
    private ?Ledger $ledger = null;
    private ?Widget $widget = null;

    public function __construct(?Store $store = null, ?Clock $clock = null, private ?string $seedPath = null)
    {
        $this->store = $store;
        $this->clock = $clock ?? new Clock();
    }

    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Replace the container (tests, or a site that supplies its own store).
     */
    public static function setInstance(?self $plugin): void
    {
        self::$instance = $plugin;
    }

    /**
     * Register every hook. Called once from the plugin file.
     */
    public static function register(string $file): void
    {
        add_filter('spend_meter_pre_spend', [self::class, 'filterPreSpend'], 10, 2);
        add_action('spend_meter_record', [self::class, 'actionRecord'], 10, 1);
        add_action('spend_meter_release', [self::class, 'actionRelease'], 10, 1);

        add_action(Cron::HOURLY, [Cron::class, 'hourly']);
        add_action(Cron::DAILY, [Cron::class, 'daily']);

        add_action('admin_init', [self::class, 'adminInit']);
        add_action('admin_menu', [SettingsPage::class, 'menu']);
        add_action('network_admin_menu', [SettingsPage::class, 'networkMenu']);
        add_action('admin_post_' . SettingsPage::SAVE_ACTION, [SettingsPage::class, 'handlePost']);
        add_action('admin_post_' . SettingsPage::IMPORT_ACTION, [SettingsPage::class, 'handleImport']);
        add_action('admin_post_' . Widget::REFRESH_ACTION, [Widget::class, 'handleRefresh']);
        add_action('wp_dashboard_setup', [Widget::class, 'setup']);
        add_action('wp_network_dashboard_setup', [Widget::class, 'setupNetwork']);
        add_action('admin_notices', [Notices::class, 'render']);
        add_action('network_admin_notices', [Notices::class, 'render']);

        register_activation_hook($file, [self::class, 'activate']);
        register_deactivation_hook($file, [Cron::class, 'unschedule']);

        if (defined('WP_CLI') && true === constant('WP_CLI') && class_exists('WP_CLI')) {
            \WP_CLI::add_command('spend-meter', Cli\Command::class);
        }
    }

    /**
     * @param mixed $value  Ignored; spend-meter always overwrites it.
     * @param mixed $intent
     * @return array<string, mixed>
     */
    public static function filterPreSpend(mixed $value = null, mixed $intent = null): array
    {
        return self::instance()->decision()->check(is_array($intent) ? $intent : []);
    }

    public static function actionRecord(mixed $call = null): void
    {
        self::instance()->ledger()->record(is_array($call) ? $call : []);
    }

    public static function actionRelease(mixed $reservationId = null): void
    {
        self::instance()->ledger()->release(is_string($reservationId) ? $reservationId : '');
    }

    public static function activate(): void
    {
        $plugin = self::instance();
        Schema::install($plugin->store(), $plugin->clock());
        Cron::schedule();
    }

    public static function adminInit(): void
    {
        try {
            $plugin = self::instance();
            Schema::maybeInstall($plugin->store(), $plugin->clock());
            Cron::schedule();
        } catch (Throwable $e) {
            Logger::error('schema', 'install on admin_init failed: ' . $e->getMessage());
        }
    }

    public function store(): Store
    {
        if (null === $this->store) {
            global $wpdb;
            $this->store = new WpdbStore($wpdb);
        }

        return $this->store;
    }

    public function clock(): Clock
    {
        return $this->clock;
    }

    public function settings(): Settings
    {
        return $this->settings ??= new Settings();
    }

    public function prices(): PriceTable
    {
        return $this->prices ??= new PriceTable(
            $this->seedPath ?? dirname(__DIR__) . '/data/prices.json',
            $this->settings()->all()['currency']
        );
    }

    public function caps(): Caps
    {
        return $this->caps ??= new Caps();
    }

    public function kill(): KillSwitch
    {
        return $this->kill ??= new KillSwitch();
    }

    public function alerts(): Alerts
    {
        return $this->alerts ??= new Alerts($this->clock);
    }

    public function counters(): Counters
    {
        return $this->counters ??= new Counters($this->clock);
    }

    public function decision(): Decision
    {
        return $this->decision ??= new Decision(
            $this->store(),
            $this->clock,
            $this->settings(),
            $this->prices(),
            $this->caps(),
            $this->kill(),
            $this->alerts(),
            $this->counters()
        );
    }

    public function ledger(): Ledger
    {
        return $this->ledger ??= new Ledger(
            $this->store(),
            $this->clock,
            $this->settings(),
            $this->prices(),
            $this->caps(),
            $this->alerts(),
            $this->counters()
        );
    }

    public function widget(): Widget
    {
        return $this->widget ??= new Widget($this);
    }

    /**
     * Rebuild the price table after the currency or the overrides change.
     */
    public function resetPrices(): void
    {
        $this->prices = null;
        $this->decision = null;
        $this->ledger = null;
    }
}
