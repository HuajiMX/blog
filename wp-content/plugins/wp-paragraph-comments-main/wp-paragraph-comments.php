<?php
/**
 * Plugin Name: Paragraph Comments
 * Plugin URI:  https://github.com/HuajiMX/wp-paragraph-comments
 * Description: A WordPress plugin which allows users to leave comments in a specific paragraph.
 * Version:     1.0.2
 * Author:      HuajiMC
 * Author URI:  https://huajimc.cn/
 * License:     MIT
 * License URI: https://opensource.org/licenses/MIT
 */


// If this file is called directly, abort.
if(!defined('WPINC')) {
	die;
}

require_once __DIR__ . '/includes/class-comment.php';
require_once __DIR__ . '/includes/functions.php';

define('PLUGIN_NAME_VERSION', '1.0.2');

/**
 * 在每个段落后面加上段评入口
 */
add_filter('the_content', 'wp_paragraph_comments_add_entry_after_paragraphs');

function wp_paragraph_comments_add_entry_after_paragraphs($content) {
    // 检查是否在文章单页且不是后台管理界面
    if(is_single() && !is_admin()) {
        $counter = 0;
        $post_id = get_the_ID();

        // 一次性查出各段落的评论数，HTML 直接渲染真实数值（不依赖 JS）
        $counts = WP_Paragraph_Comment::get_count_list($post_id);
        $count_map = array();
        foreach($counts as $row) {
            $count_map[(int)$row->paragraph_index] = (int)$row->comment_count;
        }

        // 把配置数据内嵌到文章内容中：
        // - 跟随 PJAX 替换的内容一起更新，换页后能读到新值
        // - 避免 wp_localize_script 的全局变量在脚本被延迟/优化加载时拿不到
        $config = array(
            'post_id'  => $post_id,
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('paragraph_comment_nonce'),
            'comment_counts' => $counts,
        );
        $content = '<script type="application/json" id="wp-paragraph-comments-data">' . wp_json_encode($config) . '</script>' . $content;

        $content = preg_replace_callback(
            '/<\/p>/',
            function($matches) use (&$counter, $post_id, $count_map) {
                $counter++;
                $count = isset($count_map[$counter]) ? $count_map[$counter] : 0;
                return '<span class="wp-paragraph-comments-entry" data-paragraph-index="' . $counter . '" data-post-id="' . $post_id . '"><i class="fa-solid fa-feather-pointed"></i><span class="wp-paragraph-comments-count" data-paragraph-index="' . $counter . '">' . $count . '</span></span></p>';
            },
            $content
        );
    }
    return $content;
}

/**
 * 在文章单页加载样式文件
 */
add_action('wp_enqueue_scripts', 'wp_paragraph_comments_include_styles');

function wp_paragraph_comments_include_styles() {
    if(is_single() && !is_admin()) {
        wp_enqueue_style(
            'wp-paragraph-comments-style',
            plugin_dir_url( __FILE__ ) . 'assets/style.css',
            array(),
            '1.0.0'
        );
    }
}

/**
 * 在文章单页加载 js 脚本文件
 */
add_action('wp_enqueue_scripts', 'wp_paragraph_comments_include_scripts');

function wp_paragraph_comments_include_scripts() {
    if(is_single() && !is_admin()) {
        wp_enqueue_script(
            'wp-paragraph-comments-script',
            plugin_dir_url( __FILE__ ) . 'assets/script.js',
            array( 'jquery' ),
            '1.0.0',
            true
        );

        wp_localize_script(
            'wp-paragraph-comments-script',
            'wp_paragraph_comments',
            array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('paragraph_comment_nonce'),
                'post_id'  => get_the_ID(), // 获取当前文章 ID
                'comment_counts' => WP_Paragraph_Comment::get_count_list(get_the_ID())
            )
        );
    }
}

/**
 * AJAX：根据邮箱获取头像
 */
add_action('wp_ajax_wp_paragraph_comments_get_avatar', 'wp_paragraph_comments_get_avatar');
add_action('wp_ajax_nopriv_wp_paragraph_comments_get_avatar', 'wp_paragraph_comments_get_avatar');

function wp_paragraph_comments_get_avatar() {
    check_ajax_referer('paragraph_comment_nonce', 'nonce');

    $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';

    if(empty($email)) {
        wp_send_json_error(array('message' => '邮箱不能为空'));
    }

    $avatar_url = get_avatar_url($email);
    wp_send_json_success(array('avatar' => $avatar_url));
}

/**
 * AJAX：获取段评表单
 */
add_action('wp_ajax_wp_paragraph_comments_get_form', 'wp_paragraph_comments_get_form');
add_action('wp_ajax_nopriv_wp_paragraph_comments_get_form', 'wp_paragraph_comments_get_form');

function wp_paragraph_comments_get_form() {
    check_ajax_referer('paragraph_comment_nonce', 'nonce');

    $commenter = wp_paragraph_comments_get_current_commenter();

    $html = '<div class="wp-paragraph-comments-form">
        <img class="wp-paragraph-comments-form-avatar" id="wp-paragraph-comments-form-avatar-img" src="' . get_avatar_url($commenter['author_email'] ?: '') . '" alt="头像">
        <div class="wp-paragraph-comments-form-fields">
            <input type="text" id="wp-paragraph-comments-form-name" placeholder="昵称 *" value="' . ($commenter['author'] ?: '') . '">
            <input type="email" id="wp-paragraph-comments-form-email" placeholder="邮箱 *" value="' . ($commenter['author_email'] ?: '') . '">
            <input type="url" id="wp-paragraph-comments-form-url" placeholder="网站（可选）" value="' . ($commenter['author_url'] ?: '') . '">
            <textarea id="wp-paragraph-comments-form-content" rows="3" placeholder="写下你的评论..."></textarea>
            <div class="wp-paragraph-comments-form-actions">
                <span class="wp-paragraph-comments-form-message" id="wp-paragraph-comments-form-message"></span>
                <button type="button" id="wp-paragraph-comments-form-submit">发送</button>
            </div>
        </div>
    </div>';

    wp_send_json_success(array('html' => $html));
}

/**
 * AJAX：获取段评列表
 */
add_action('wp_ajax_wp_paragraph_comments_get_list', 'wp_paragraph_comments_get_list');
add_action('wp_ajax_nopriv_wp_paragraph_comments_get_list', 'wp_paragraph_comments_get_list');

function wp_paragraph_comments_get_list() {
    check_ajax_referer('paragraph_comment_nonce', 'nonce');

    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    $paragraph_index = isset($_POST['paragraph_index']) ? intval($_POST['paragraph_index']) : 0;
    $page = isset($_POST['page']) ? intval($_POST['page']) : 1;

    if($paragraph_index < 0 || $page < 1) {
        wp_send_json_error(array('message' => '段落索引和页码不合法'));
    }

    $comment = new WP_Paragraph_Comment();
    $comments = $comment->get_all($post_id, $paragraph_index, $page, 20);

    // 只返回前端需要的字段，避免暴露 IP、UA 等敏感信息
    $data = array();
    foreach($comments as $c) {
        $data[] = array(
            'author' => $c->comment_author,
            'author_url' => esc_url($c->comment_author_url),
            'comment_date' => $c->comment_date,
            'comment_content' => $c->comment_content,
            'avatar' => get_avatar_url($c->comment_author_email),
        );
    }

    wp_send_json_success(array('comments' => $data));
}

/**
 * AJAX：发送段评
 */
add_action('wp_ajax_wp_paragraph_comments_create', 'wp_paragraph_comments_create');
add_action('wp_ajax_nopriv_wp_paragraph_comments_create', 'wp_paragraph_comments_create');

function wp_paragraph_comments_create() {
    check_ajax_referer('paragraph_comment_nonce', 'nonce');

    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    $paragraph_index = isset($_POST['paragraph_index']) ? intval($_POST['paragraph_index']) : 0;
    $content = isset($_POST['content']) ? trim($_POST['content']) : '';
    $author = isset($_POST['author']) ? trim($_POST['author']) : '';
    $author_email = isset($_POST['author_email']) ? trim($_POST['author_email']) : '';
    $author_url = isset($_POST['author_url']) ? trim($_POST['author_url']) : '';

    $comment = (new WP_Paragraph_Comment())->create(array(
        'post_id' => $post_id,
        'paragraph_index' => $paragraph_index,
        'content' => $content,
        'author' => $author,
        'author_email' => $author_email,
        'author_url' => $author_url,
    ));

    if($comment !== null) {
        // 只返回前端需要的字段，避免暴露 IP、UA 等敏感信息
        $wp_comment = get_comment($comment->get_id());
        wp_send_json_success(array(
            'comment' => array(
                'author' => $wp_comment->comment_author,
                'author_url' => esc_url($wp_comment->comment_author_url),
                'comment_date' => $wp_comment->comment_date,
                'comment_content' => $wp_comment->comment_content,
                'avatar' => get_avatar_url($wp_comment->comment_author_email),
            )
        ));
    } else {
        wp_send_json_error(array('message' => '发送失败'));
    }
}