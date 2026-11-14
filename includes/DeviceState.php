<?php

declare(strict_types=1);

namespace PaymosGivewp;

use PaymosGivewpVendor\Paymos\Plugin\WordPressEncryptedOption;
use PaymosGivewpVendor\Paymos\Plugin\WordPressOrderLock;

defined('ABSPATH') || exit;

final class DeviceState
{
    public const OPTION = 'paymos_givewp_device_v1';

    public static function scope(): array
    {
        $blog = (int) get_current_blog_id();

        return [
            'site' => $blog,
            'user' => (int) get_current_user_id(),
            'session' => hash_hmac('sha256', wp_get_session_token(), wp_salt('nonce')),
            'source' => ConnectionStore::source($blog),
        ];
    }

    public static function load(int $blog): ?array
    {
        $state = WordPressEncryptedOption::load(self::OPTION, self::aad($blog));
        if ($state === []) {
            return null;
        }
        if (
            ($state['scope']['site'] ?? null) !== $blog
            || !is_int($state['expires_at'] ?? null)
            || !is_int($state['next_poll_at'] ?? null)
            || !is_int($state['interval'] ?? null)
        ) {
            throw new \UnexpectedValueException('Invalid Paymos device storage.');
        }

        return $state;
    }

    public static function owned(array $scope, int $now): ?array
    {
        $state = self::load($scope['site']);
        if ($state === null || $state['scope'] !== $scope || $state['expires_at'] <= $now) {
            return null;
        }

        return $state;
    }

    public static function begin(array $scope, string $environment, array $reply, int $now): array
    {
        $expiry = $reply['expires_in'];
        $interval = $reply['interval'];
        if (
            $expiry < 1 || $expiry > 1800
            || $interval < 1 || $interval > 60
            || preg_match('/\A[\x21-\x7E]{16,4096}\z/', $reply['device_code']) !== 1
            || preg_match('/\A[A-Za-z0-9-]{4,32}\z/', $reply['user_code']) !== 1
        ) {
            throw new \UnexpectedValueException('Invalid Paymos device authorization.');
        }

        return [
            'request_id' => bin2hex(random_bytes(16)),
            'scope' => $scope,
            'environment' => $environment,
            'device_code' => $reply['device_code'],
            'user_code' => $reply['user_code'],
            'verification_url' => $reply['verification_url'],
            'expires_at' => $now + $expiry,
            'interval' => $interval,
            'next_poll_at' => $now + $interval,
        ];
    }

    public static function presentation(array $state, int $now): array
    {
        return [
            'request_id' => $state['request_id'],
            'environment' => $state['environment'],
            'user_code' => $state['user_code'],
            'verification_url' => $state['verification_url'],
            'expires_in' => max(0, $state['expires_at'] - $now),
            'retry_after' => max(1, $state['next_poll_at'] - $now),
        ];
    }

    public static function save(array $state, WordPressOrderLock $lock): void
    {
        $aad = self::aad($state['scope']['site']);
        $lock->assertOwned();
        WordPressEncryptedOption::save(self::OPTION, $aad, $state);
    }

    public static function clear(int $blog, WordPressOrderLock $lock): void
    {
        $aad = self::aad($blog);
        $lock->assertOwned();
        WordPressEncryptedOption::delete(self::OPTION);
    }

    private static function aad(int $blog): string
    {
        if ($blog <= 0 || (int) get_current_blog_id() !== $blog) {
            throw new \InvalidArgumentException('Switch to the owning WordPress site before accessing its device state.');
        }

        return 'paymos-givewp-device-v1:' . $blog;
    }
}
