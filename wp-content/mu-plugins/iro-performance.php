<?php
/**
 * Plugin Name: IRO Performance Tweaks
 * Description: 站点级的小型性能修补：去掉 MailPoet 注入的 Google Fonts 大列表（国内会阻塞渲染），关闭核心 emoji 外部请求，补充资源预连接。
 * Version: 1.0.0
 * Requires PHP: 7.4
 *
 * 说明：
 * - 放在 mu-plugins 目录，自动加载，不受主题/插件更新影响。
 * - 只做前端优化，不改变后台编辑体验（MailPoet 表单编辑器仍能看到字体预览）。
 *
 * @package IRO_Performance
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * MailPoet 会在每个包含订阅表单的页面注入 3 个 fonts.googleapis.com 样式表，
 * 每个都列了几十个字体家族。国内网络访问 Google Fonts 通常超时，而样式表
 * 是渲染阻塞资源，会让页面白屏数秒。这里只在前台关闭它。
 */
add_filter(
    'mailpoet_display_custom_fonts',
    function ($display) {
        if (is_admin()) {
            return $display;
        }
        return false;
    }
);

/**
 * 双保险：如果 MailPoet 或缓存里仍然排入了这些样式，直接摘掉。
 */
add_action(
    'wp_enqueue_scripts',
    function () {
        if (is_admin()) {
            return;
        }
        foreach (array('mailpoet_custom_fonts_0', 'mailpoet_custom_fonts_1', 'mailpoet_custom_fonts_2') as $handle) {
            wp_dequeue_style($handle);
            wp_deregister_style($handle);
        }
    },
    100
);

/**
 * 关闭 WordPress 核心 emoji 脚本。
 *
 * 核心会内联一段脚本，把 emoji 换成 s.w.org 上的 SVG；国内访问 s.w.org
 * 经常要 2 秒以上。现代浏览器/系统本身就能渲染 emoji，不需要这层替换。
 */
add_action(
    'init',
    function () {
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('admin_print_styles', 'print_emoji_styles');
        remove_filter('the_content_feed', 'wp_staticize_emoji');
        remove_filter('comment_text_rss', 'wp_staticize_emoji');
        remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
    }
);

/**
 * 提前连接主题静态资源所在的 CDN。
 *
 * 主题的头像、表情、装饰图、smoothscroll.js 等来自 s.nmxc.ltd，首页有
 * 近十个请求；提前握手可以省掉每个域名的 DNS + TLS 时间。
 */
add_filter(
    'wp_resource_hints',
    function ($hints, $relation_type) {
        if ('preconnect' !== $relation_type) {
            return $hints;
        }

        $extra = array(
            'https://s.nmxc.ltd',
            'https://s4.zstatic.net',
        );

        foreach ($extra as $url) {
            if (!in_array($url, $hints, true)) {
                $hints[] = $url;
            }
        }

        return $hints;
    },
    10,
    2
);
