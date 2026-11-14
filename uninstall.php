<?php

declare(strict_types=1);

defined('ABSPATH') && defined('WP_UNINSTALL_PLUGIN') || exit;

if (!defined('PAYMOS_GIVEWP_PLUGIN_DIR')) {
    define('PAYMOS_GIVEWP_PLUGIN_DIR', __DIR__ . '/');
}

require_once PAYMOS_GIVEWP_PLUGIN_DIR . 'includes/Autoloader.php';

PaymosGivewp\Autoloader::register();
PaymosGivewp\Install::uninstall();
