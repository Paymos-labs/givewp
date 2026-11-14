<?php

declare(strict_types=1);

namespace PaymosGivewp;

use PaymosGivewpVendor\Paymos\Plugin\CredentialSet;
use PaymosGivewpVendor\Paymos\Plugin\WordPressEncryptedOption;
use PaymosGivewpVendor\Paymos\Plugin\WordPressOrderLock;

defined('ABSPATH') || exit;

final class ConnectionStore
{
    public const OPTION = 'paymos_givewp_connections_v1';

    public static function lock(int $blog): WordPressOrderLock
    {
        self::assertBlog($blog);

        return new WordPressOrderLock('paymos-givewp-connection', $blog);
    }

    public static function source(int $blog): string
    {
        self::assertBlog($blog);
        $home = get_option('home');
        if (!is_string($home) || strlen($home) > 2048 || filter_var($home, FILTER_VALIDATE_URL) === false) {
            throw new \UnexpectedValueException('A canonical HTTPS home URL is required.');
        }

        $parts = wp_parse_url($home);
        if (
            !is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host'])
            || array_intersect_key($parts, array_flip(['user', 'pass', 'query', 'fragment']))
        ) {
            throw new \UnexpectedValueException('A canonical HTTPS home URL is required.');
        }

        $path = rtrim($parts['path'] ?? '', '/');
        if (preg_match('#\A(?:/[A-Za-z0-9._~-]+)*\z#', $path) !== 1 || preg_match('#/(?:\.|\.\.)(?:/|\z)#', $path)) {
            throw new \UnexpectedValueException('The home URL path must be canonical.');
        }

        $port = $parts['port'] ?? 443;

        return 'https://' . strtolower($parts['host']) . ($port === 443 ? '' : ':' . $port) . $path;
    }

    /** Exact active binding for local checkout. Never returns credentials. */
    public static function active(int $blog): ?array
    {
        $state = self::load($blog);
        if ($state['active'] === null) {
            return null;
        }

        $binding = $state['generations'][$state['active']]['binding'];
        if ($binding['source'] !== self::source($blog)) {
            return null;
        }

        return $binding;
    }

    public static function hasRetained(int $blog): bool
    {
        return self::load($blog)['generations'] !== [];
    }

    /**
     * Resolve only the original site/source/environment/project/generation.
     * This still works when the shop is disconnected or its home URL changed.
     */
    public static function forAttempt(array $attempt): ?array
    {
        $blog = (int) get_current_blog_id();
        $generation = $attempt['generation'] ?? null;
        if (($attempt['site'] ?? null) !== $blog || !is_string($generation) || preg_match('/\A[a-f0-9]{32}\z/', $generation) !== 1) {
            return null;
        }

        $entry = self::load($blog)['generations'][$generation] ?? null;
        if ($entry === null) {
            return null;
        }
        foreach ($entry['binding'] as $key => $value) {
            if (($attempt[$key] ?? null) !== $value) {
                return null;
            }
        }

        return CredentialSet::normalizeEnvironment($entry['binding']['environment'], $entry['credentials']);
    }

    /**
     * Returns null on a project conflict. Opposite-mode credentials are discarded.
     * A historical generation is never replaced by a new credential set.
     */
    public static function connect(
        int $blog,
        string $source,
        string $environment,
        array $environments,
        WordPressOrderLock $lock
    ): ?array {
        $lock->assertOwned();
        if ($source !== self::source($blog) || !in_array($environment, ['sandbox', 'live'], true)) {
            throw new \UnexpectedValueException('Invalid connection binding.');
        }

        $normalized = CredentialSet::normalize($environments);
        if (!isset($normalized[$environment])) {
            throw new \UnexpectedValueException('No credentials for the chosen mode.');
        }
        $credentials = $normalized[$environment];
        $project = $credentials['project_id'];
        foreach ($normalized as $candidate) {
            if ($candidate['project_id'] !== $project) {
                throw new \UnexpectedValueException('The Paymos modes do not use the same project.');
            }
        }

        $state = self::load($blog);
        foreach ($state['generations'] as $entry) {
            if ($entry['binding']['source'] === $source && $entry['binding']['project_id'] !== $project) {
                return null;
            }
        }

        do {
            $generation = bin2hex(random_bytes(16));
        } while (isset($state['generations'][$generation]));

        $binding = [
            'site' => $blog,
            'source' => $source,
            'generation' => $generation,
            'environment' => $environment,
            'project_id' => $project,
        ];

        $state['generations'][$generation] = ['binding' => $binding, 'credentials' => $credentials];
        $state['active'] = $generation;
        $lock->assertOwned();
        WordPressEncryptedOption::save(self::OPTION, self::aad($blog), $state);

        return $binding;
    }

    public static function disable(int $blog, WordPressOrderLock $lock): void
    {
        $state = self::load($blog);
        if ($state['active'] !== null) {
            $state['active'] = null;
            $lock->assertOwned();
            WordPressEncryptedOption::save(self::OPTION, self::aad($blog), $state);
        }
    }

    public static function reset(int $blog, WordPressOrderLock $lock): bool
    {
        $state = self::load($blog);
        if ($state['active'] !== null) {
            return false;
        }
        if ($state['generations'] === []) {
            return true;
        }

        // Clearing is unsafe until both financial stores can prove there is no unresolved work.
        if (
            !is_callable([AttemptStore::class, 'hasUnresolvedBindings'])
            || !is_callable([RefundIntentStore::class, 'hasUnresolvedBindings'])
            || AttemptStore::hasUnresolvedBindings($blog) !== false
            || RefundIntentStore::hasUnresolvedBindings($blog) !== false
        ) {
            return false;
        }

        $lock->assertOwned();
        WordPressEncryptedOption::delete(self::OPTION);

        return true;
    }

    private static function load(int $blog): array
    {
        self::assertBlog($blog);
        $state = WordPressEncryptedOption::load(self::OPTION, self::aad($blog));
        if ($state === []) {
            return ['schema' => 1, 'site' => $blog, 'active' => null, 'generations' => []];
        }
        if (
            ($state['schema'] ?? null) !== 1
            || ($state['site'] ?? null) !== $blog
            || !is_array($state['generations'] ?? null)
            || !array_key_exists('active', $state)
            || ($state['active'] !== null && (!is_string($state['active']) || !isset($state['generations'][$state['active']])))
        ) {
            throw new \UnexpectedValueException('Invalid Paymos connection storage.');
        }

        return $state;
    }

    private static function assertBlog(int $blog): void
    {
        if ($blog <= 0 || (int) get_current_blog_id() !== $blog) {
            throw new \InvalidArgumentException('Switch to the owning WordPress site before accessing the connection.');
        }
    }

    private static function aad(int $blog): string
    {
        return 'paymos-givewp-connection-v1:' . $blog;
    }
}
