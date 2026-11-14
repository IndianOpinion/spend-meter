<?php

/**
 * Uninstall: remove options, transients and cron events; drop the ledger
 * unless "keep the ledger on uninstall" is on or SPEND_METER_KEEP_LEDGER is true.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/src/autoload.php';

if (!defined('SPEND_METER_API')) {
    define('SPEND_METER_API', 1);
}

IndianOpinion\SpendMeter\Uninstaller::run(IndianOpinion\SpendMeter\Plugin::instance());
