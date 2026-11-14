<?php

declare(strict_types=1);

function givewpTestCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function givewpTestNativeSource(string $archive): void
{
    $path = realpath($archive);
    if ($path === false || hash_file('sha256', $path) !== 'e4c2e75aeb7225105950e5d1e251af5050fbcdac7fee6bb20dd0853b536f5ecf') {
        throw new RuntimeException('Expected the pinned GiveWP 4.19.1.1 ZIP.');
    }

    $root = 'phar://' . str_replace('\\', '/', $path) . '/give/src/';
    spl_autoload_register(static function (string $class) use ($root): void {
        if (strncmp($class, 'Give\\', 5) === 0) {
            $file = $root . str_replace('\\', '/', substr($class, 5)) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        }
    });
}

function add_action(string $tag, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
{
    $GLOBALS['givewpTestHooks'][$tag][$priority][] = [$callback, $acceptedArgs];
}

function add_filter(string $tag, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
{
    add_action($tag, $callback, $priority, $acceptedArgs);
}

function apply_filters(string $tag, $value, ...$arguments)
{
    $callbacks = $GLOBALS['givewpTestHooks'][$tag] ?? [];
    ksort($callbacks);
    foreach ($callbacks as $atPriority) {
        foreach ($atPriority as [$callback, $acceptedArgs]) {
            $value = $callback(...array_slice(array_merge([$value], $arguments), 0, $acceptedArgs));
        }
    }

    return $value;
}

function do_action(string $tag, ...$arguments): void
{
    $callbacks = $GLOBALS['givewpTestHooks'][$tag] ?? [];
    ksort($callbacks);
    foreach ($callbacks as $atPriority) {
        foreach ($atPriority as [$callback, $acceptedArgs]) {
            $callback(...array_slice($arguments, 0, $acceptedArgs));
        }
    }
}

function give(?string $class = null)
{
    static $container;
    if ($container === null) {
        $container = new class {
            private $registered = [];
            private $instances = [];

            public function singleton(string $class, callable $factory): void
            {
                $this->registered[$class] = $factory;
            }

            public function make(string $class)
            {
                givewpTestCheck(isset($this->registered[$class]), 'GiveWP did not register the gateway with its container.');
                if (!isset($this->instances[$class])) {
                    $this->instances[$class] = new $class();
                }

                return $this->instances[$class];
            }
        };
    }

    return $class === null ? $container : $container->make($class);
}

function plugin_dir_url(string $path): string
{
    return 'https://example.invalid/wp-content/plugins/givewp-paymos/';
}

function esc_attr(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function wp_enqueue_script(string $handle, string $url, array $dependencies, string $version, bool $footer): void
{
    $GLOBALS['givewpTestScripts'][$handle] = [$url, $dependencies, $version, $footer];
}

function __($value, $domain = ''): string
{
    return (string) $value;
}

function esc_html__($value, $domain = ''): string
{
    return htmlspecialchars(__($value, $domain), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
