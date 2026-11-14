<?php

/**
 * Plugin Name: spend-meter (must-use loader)
 * Description: Loads spend-meter from wp-content/mu-plugins/spend-meter/. Copy this file to wp-content/mu-plugins/spend-meter-loader.php.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$spend_meter_file = __DIR__ . '/spend-meter/spend-meter.php';
if (is_readable($spend_meter_file)) {
    require_once $spend_meter_file;
}
unset($spend_meter_file);
