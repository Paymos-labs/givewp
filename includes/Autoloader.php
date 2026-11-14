<?php

declare(strict_types=1);

namespace PaymosGivewp;

defined('ABSPATH') || exit;

final class Autoloader
{
    public static function register(): void
    {
        spl_autoload_register([self::class, 'load']);
    }

    public static function load(string $class): void
    {
        $roots = [
            __NAMESPACE__ . '\\' => PAYMOS_GIVEWP_PLUGIN_DIR . 'includes/',
            'PaymosGivewpVendor\\Paymos\\' => PAYMOS_GIVEWP_PLUGIN_DIR . 'vendor/paymos/php-sdk/src/',
        ];

        foreach ($roots as $prefix => $directory) {
            $length = strlen($prefix);
            if (strncmp($class, $prefix, $length) !== 0) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($class, $length));
            if (preg_match('~\A[A-Za-z_][A-Za-z0-9_]*(?:/[A-Za-z_][A-Za-z0-9_]*)*\z~', $relative) === 1) {
                $file = $directory . $relative . '.php';
                if (is_file($file) && is_readable($file)) {
                    require_once $file;
                }
            }

            return;
        }
    }
}
