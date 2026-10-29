<?php

declare(strict_types=1);

namespace Fphp\Fragment;

/**
 * Dynamic Island & Hole Punching Engine
 * 
 * Solves the ultimate full-page caching paradox:
 * Enables 99% of a dynamic web page to be served from ultra-fast cache
 * while dynamically computing and injecting personalized slots (e.g., user badges,
 * cart counters, CSRF tokens, notification widgets) in microseconds.
 */
class HolePuncher
{
    /**
     * Registered hole resolver closures.
     *
     * @var array<string, callable>
     */
    protected static array $resolvers = [];

    /**
     * Static hole values for the current request.
     *
     * @var array<string, string>
     */
    protected static array $values = [];

    /**
     * Register a dynamic hole resolver.
     *
     * @param string $slot Unique hole identifier
     * @param callable $resolver Callback returning the dynamic string content
     */
    public static function register(string $slot, callable $resolver): void
    {
        self::$resolvers[$slot] = $resolver;
    }

    /**
     * Set a static string value for a hole in the current request.
     *
     * @param string $slot
     * @param string $value
     */
    public static function set(string $slot, string $value): void
    {
        self::$values[$slot] = $value;
    }

    /**
     * Declare an island/hole within template markup.
     * 
     * Usage in views/templates:
     *   <?= HolePuncher::hole('user_badge', function() use ($user) {
     *       return $user->isLoggedIn() ? "Hello, " . $user->name : "Sign In";
     *   }, fallback: '<span>Sign In</span>') ?>
     *
     * @param string $slot Hole slot identifier
     * @param callable|null $dynamicResolver Optional runtime resolver
     * @param string $fallback Default fallback content
     * @return string Wrapped HTML hole marker
     */
    public static function hole(string $slot, ?callable $dynamicResolver = null, string $fallback = ''): string
    {
        if ($dynamicResolver !== null) {
            self::register($slot, $dynamicResolver);
        }

        $inner = !empty($fallback) ? $fallback : '';
        if (isset(self::$values[$slot])) {
            $inner = self::$values[$slot];
        } elseif (isset(self::$resolvers[$slot])) {
            try {
                $inner = (string)(self::$resolvers[$slot])();
            } catch (\Throwable $e) {
                // Keep fallback on error
            }
        }

        return "<!--fphp:hole:{$slot}-->" . $inner . "<!--/fphp:hole:{$slot}-->";
    }

    /**
     * Substitute all dynamic holes in cached HTML with current runtime values.
     *
     * @param string $html
     * @return string Dynamic page with holes populated
     */
    public static function renderHoles(string $html): string
    {
        if (!str_contains($html, '<!--fphp:hole:')) {
            return $html;
        }

        return preg_replace_callback(
            '#<!--fphp:hole:([a-zA-Z0-9_\-]+)-->(.*?)<!--/fphp:hole:\1-->#s',
            function ($matches) {
                $slot = $matches[1];
                $fallback = $matches[2];

                if (isset(self::$values[$slot])) {
                    return self::$values[$slot];
                }

                if (isset(self::$resolvers[$slot])) {
                    try {
                        return (string)(self::$resolvers[$slot])();
                    } catch (\Throwable $e) {
                        return $fallback;
                    }
                }

                return $fallback;
            },
            $html
        );
    }

    /**
     * Check if a string contains hole markers.
     */
    public static function hasHoles(string $html): bool
    {
        return str_contains($html, '<!--fphp:hole:');
    }

    /**
     * Clear registered resolvers and values.
     */
    public static function clear(): void
    {
        self::$resolvers = [];
        self::$values = [];
    }
}
