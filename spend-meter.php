<?php

/**
 * Plugin Name:       Spend Meter
 * Plugin URI:        https://github.com/IndianOpinion/spend-meter
 * Description:       One ledger, one price table, caps and a kill switch for every model call other plugins make. Callers check before they spend and record after.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Indian Opinion
 * Author URI:        https://indianopinion.org
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       spend-meter
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (defined('SPEND_METER_API')) {
    // Another copy (for example a must-use copy) is already loaded.
    return;
}

/** Contract version. Increases by one on every breaking change. */
define('SPEND_METER_API', 1);
/** Plugin version. */
define('SPEND_METER_VERSION', '0.1.0');
define('SPEND_METER_FILE', __FILE__);

require_once __DIR__ . '/src/autoload.php';
require_once __DIR__ . '/src/functions.php';

IndianOpinion\SpendMeter\Plugin::register(__FILE__);
