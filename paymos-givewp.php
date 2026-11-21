<?php
/**
 * Plugin Name: Paymos Stablecoin Donations
 * Plugin URI: https://paymos.io/docs/cms-givewp
 * Description: Accept stablecoin donations through Paymos in GiveWP.
 * Version: 1.1.0
 * Author: Paymos
 * Author URI: https://paymos.io
 * License: GPL-2.0-or-later
 * Text Domain: paymos-stablecoin-donations
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Requires Plugins: give
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('PAYMOS_GIVEWP_PLUGIN_FILE', __FILE__);
define('PAYMOS_GIVEWP_PLUGIN_DIR', __DIR__ . '/');
define('PAYMOS_GIVEWP_PLUGIN_VERSION', '1.1.0');

require_once PAYMOS_GIVEWP_PLUGIN_DIR . 'includes/Autoloader.php';

PaymosGivewp\Autoloader::register();
PaymosGivewp\Plugin::registerHooks();

if (function_exists('register_activation_hook')) {
    register_activation_hook(PAYMOS_GIVEWP_PLUGIN_FILE, [PaymosGivewp\Install::class, 'activate']);
    register_deactivation_hook(PAYMOS_GIVEWP_PLUGIN_FILE, [PaymosGivewp\Install::class, 'deactivate']);
}

if (function_exists('plugin_basename')) {
    add_filter('plugin_action_links_' . plugin_basename(PAYMOS_GIVEWP_PLUGIN_FILE), [PaymosGivewp\RefundAdmin::class, 'pluginLinks']);
}
