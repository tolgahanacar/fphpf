<?php
/**
 * Plugin Name: FPHP Cache Accelerator
 * Plugin URI: https://github.com/fphp/cache
 * Description: Ultra-fast microsecond caching engine. Bypasses 100% of WordPress core & MySQL queries for visitors while guaranteeing zero data loss for admins and WooCommerce carts.
 * Version: 1.0.0
 * Author: FPHP Team
 * License: MIT
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

// Ensure FPHP library is loaded
$fphpBootstrap = __DIR__ . '/fphp-bootstrap.php';
if (!file_exists($fphpBootstrap)) {
    $fphpBootstrap = dirname(__DIR__) . '/fphp-bootstrap.php';
}

if (file_exists($fphpBootstrap)) {
    require_once $fphpBootstrap;
}

class FphpWordPressPlugin
{
    public static function init(): void
    {
        // 1. Activation & Deactivation Hooks
        register_activation_hook(__FILE__, [__CLASS__, 'onActivate']);
        register_deactivation_hook(__FILE__, [__CLASS__, 'onDeactivate']);

        // 2. Cache Invalidation Hooks (Zero Data Loss)
        add_action('save_post', [__CLASS__, 'purgePostCache'], 10, 2);
        add_action('edit_post', [__CLASS__, 'purgePostCache'], 10, 2);
        add_action('delete_post', [__CLASS__, 'purgePostCache'], 10, 2);
        add_action('comment_post', [__CLASS__, 'purgeCommentPostCache'], 10, 2);
        add_action('wp_set_comment_status', [__CLASS__, 'purgeCommentStatusCache'], 10, 2);

        // Theme / Customizer / Plugin updates
        add_action('switch_theme', [__CLASS__, 'purgeAll']);
        add_action('customize_save_after', [__CLASS__, 'purgeAll']);
        add_action('activated_plugin', [__CLASS__, 'purgeAll']);
        add_action('deactivated_plugin', [__CLASS__, 'purgeAll']);

        // 3. Admin Bar Quick Action
        add_action('admin_bar_menu', [__CLASS__, 'addAdminBarMenu'], 100);
        add_action('admin_init', [__CLASS__, 'handleAdminActions']);
    }

    public static function onActivate(): void
    {
        // Try installing advanced-cache.php drop-in
        $target = WP_CONTENT_DIR . '/advanced-cache.php';
        $source = __DIR__ . '/advanced-cache.php';

        if (file_exists($source) && !file_exists($target)) {
            @copy($source, $target);
        }
    }

    public static function onDeactivate(): void
    {
        // Remove advanced-cache.php if ours
        $target = WP_CONTENT_DIR . '/advanced-cache.php';
        if (file_exists($target)) {
            $content = @file_get_contents($target);
            if ($content && str_contains($content, 'FPHP Cache')) {
                @unlink($target);
            }
        }

        self::purgeAll();
    }

    /**
     * Purge specific post URL and homepage on content edit.
     */
    public static function purgePostCache(int $postId, ?\WP_Post $post = null): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (class_exists('\\Fphp\\FphpCache')) {
            $permalink = get_permalink($postId);
            if ($permalink) {
                // Delete post URL cache
                \Fphp\FphpCache::delete('page:' . hash('xxh128', $permalink));
            }

            // Invalidate homepage and feed
            $home = home_url('/');
            \Fphp\FphpCache::delete('page:' . hash('xxh128', $home));
        }
    }

    public static function purgeCommentPostCache(int $commentId, mixed $approved): void
    {
        $comment = get_comment($commentId);
        if ($comment && !empty($comment->comment_post_ID)) {
            self::purgePostCache((int)$comment->comment_post_ID);
        }
    }

    public static function purgeCommentStatusCache(int $commentId, string $status): void
    {
        $comment = get_comment($commentId);
        if ($comment && !empty($comment->comment_post_ID)) {
            self::purgePostCache((int)$comment->comment_post_ID);
        }
    }

    public static function purgeAll(): void
    {
        if (class_exists('\\Fphp\\FphpCache')) {
            \Fphp\FphpCache::clear();
        }
    }

    public static function addAdminBarMenu(\WP_Admin_Bar $adminBar): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $adminBar->add_node([
            'id' => 'fphp-cache-node',
            'title' => '<span class="ab-icon">⚡</span> FPHP Cache',
            'href' => wp_nonce_url(admin_url('?fphp_action=purge_all'), 'fphp_purge')
        ]);

        $adminBar->add_node([
            'id' => 'fphp-purge-all',
            'parent' => 'fphp-cache-node',
            'title' => '🗑️ Tüm Önbelleği Boşalt (Purge All)',
            'href' => wp_nonce_url(admin_url('?fphp_action=purge_all'), 'fphp_purge')
        ]);

        $adminBar->add_node([
            'id' => 'fphp-dashboard',
            'parent' => 'fphp-cache-node',
            'title' => '📊 FPHP Hız & Telemetri Paneli',
            'href' => home_url('/fphpf/public/dashboard.php'),
            'meta' => ['target' => '_blank']
        ]);
    }

    public static function handleAdminActions(): void
    {
        if (isset($_GET['fphp_action']) && $_GET['fphp_action'] === 'purge_all') {
            if (check_admin_referer('fphp_purge') && current_user_can('manage_options')) {
                self::purgeAll();
                add_action('admin_notices', function () {
                    echo '<div class="notice notice-success is-dismissible"><p><strong>FPHP Cache:</strong> Tüm önbellek mikrosaniyeler içinde temizlendi!</p></div>';
                });
            }
    }
}

FphpWordPressPlugin::init();

// 4. WP-CLI Command Registration
if (defined('WP_CLI') && WP_CLI && class_exists('\\WP_CLI')) {
    class FphpWpCliCommand
    {
        public function clear(array $args, array $assoc_args): void
        {
            if (class_exists('\\Fphp\\FphpCache')) {
                \Fphp\FphpCache::clear();
                \WP_CLI::success('FPHP Cache: Tüm önbellek temizlendi!');
            }
        }

        public function prune(array $args, array $assoc_args): void
        {
            if (class_exists('\\Fphp\\FphpCache')) {
                $res = \Fphp\FphpCache::prune();
                \WP_CLI::success('FPHP Cache: ' . $res['total_deleted'] . ' adet süresi dolmuş dosya temizlendi.');
            }
        }

        public function stats(array $args, array $assoc_args): void
        {
            if (class_exists('\\Fphp\\FphpCache')) {
                $stats = \Fphp\FphpCache::getStats();
                \WP_CLI::line('FPHP Cache Hit Ratio: ' . $stats['hit_ratio'] . '%');
                \WP_CLI::line('Total Requests: ' . $stats['total_requests']);
                \WP_CLI::line('Saved Time: ' . $stats['saved_time_seconds'] . 's');
            }
        }
    }

    \WP_CLI::add_command('fphp', 'FphpWpCliCommand');
}
