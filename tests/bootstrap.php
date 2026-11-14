<?php

declare(strict_types=1);

require_once __DIR__ . '/stubs/wordpress.php';
require_once __DIR__ . '/stubs/wp-cli.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!defined('WP_CLI')) {
    define('WP_CLI', true);
}

// Load the plugin the way WordPress does: the constant, the functions and
// the hooks all exist once this file has been included.
require_once dirname(__DIR__) . '/spend-meter.php';
