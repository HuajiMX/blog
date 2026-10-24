<?php
/**
 * Plugin Name: IRO Editor Image Paste
 * Description: 在文章 Markdown 编辑器中直接粘贴或拖入图片，自动上传到媒体库，并在光标处插入图片 Markdown。
 * Version: 1.0.0
 * Requires PHP: 7.4
 *
 * 说明：
 * - 放在 mu-plugins 目录，WordPress 自动加载，不受主题/插件更新影响。
 * - 前端资源只在“使用 Githuber MD 编辑器的文章编辑页”加载。
 * - 即使 Githuber MD 自带的 Image Paste 功能开启，本插件也会先接管图片粘贴，避免重复上传。
 *
 * @package IRO_Editor_Image_Paste
 */

if (!defined('ABSPATH')) {
    exit;
}

define('IRO_EDITOR_IMAGE_PASTE_VERSION', '1.0.0');
define('IRO_EDITOR_IMAGE_PASTE_DIR', __DIR__ . '/iro-editor-image-paste');
define(
    'IRO_EDITOR_IMAGE_PASTE_URL',
    defined('WPMU_PLUGIN_URL') ? WPMU_PLUGIN_URL . '/iro-editor-image-paste' : content_url('mu-plugins/iro-editor-image-paste')
);

/**
 * 允许上传的图片 MIME。
 *
 * @return string[]
 */
function iro_editor_image_paste_allowed_mimes()
{
    return array(
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/avif',
    );
}

/**
 * 单张图片的最大字节数。
 *
 * 取 20 MB 与站点实际上传上限中较小的一个，避免前端提示“可上传”但 PHP 拒收。
 *
 * @return int
 */
function iro_editor_image_paste_max_bytes()
{
    $limit = 20 * MB_IN_BYTES;
    $site_limit = (int) wp_max_upload_size();

    if ($site_limit > 0) {
        $limit = min($limit, $site_limit);
    }

    return (int) apply_filters('iro_editor_image_paste_max_bytes', $limit);
}

/**
 * 当前文章 ID。
 *
 * @return int
 */
function iro_editor_image_paste_post_id()
{
    if (function_exists('githuber_get_current_post_id')) {
        return (int) githuber_get_current_post_id();
    }

    if (!empty($_GET['post'])) {
        return absint($_GET['post']);
    }

    if (!empty($_POST['post_id'])) {
        return absint($_POST['post_id']);
    }

    return 0;
}

/**
 * 在文章编辑页加载粘贴图片所需资源。
 *
 * @param string $hook_suffix 当前后台页面。
 * @return void
 */
function iro_editor_image_paste_enqueue($hook_suffix)
{
    if ('post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix) {
        return;
    }

    if (!current_user_can('upload_files')) {
        return;
    }

    if (!function_exists('githuber_get_option')) {
        return;
    }

    $screen = get_current_screen();

    if (!$screen || !post_type_supports($screen->post_type, 'githuber_markdown')) {
        return;
    }

    $current_post_id = function_exists('githuber_get_current_post_id') ? (int) githuber_get_current_post_id() : 0;
    $markdown_this_post = $current_post_id ? get_metadata('post', $current_post_id, '_is_githuber_markdown_enabled', true) : '';

    if ('no' === $markdown_this_post) {
        return;
    }

    // 与 Githuber MD 的“默认使用富文本编辑器”开关保持一致。
    if ('yes' === githuber_get_option('richeditor_by_default', 'githuber_preferences') && 'yes' !== $markdown_this_post) {
        return;
    }

    $script_path = IRO_EDITOR_IMAGE_PASTE_DIR . '/editor.js';
    $style_path = IRO_EDITOR_IMAGE_PASTE_DIR . '/editor.css';
    $script_version = file_exists($script_path) ? (string) filemtime($script_path) : IRO_EDITOR_IMAGE_PASTE_VERSION;
    $style_version = file_exists($style_path) ? (string) filemtime($style_path) : IRO_EDITOR_IMAGE_PASTE_VERSION;

    wp_enqueue_style(
        'iro-editor-image-paste',
        IRO_EDITOR_IMAGE_PASTE_URL . '/editor.css',
        array(),
        $style_version
    );

    wp_enqueue_script(
        'iro-editor-image-paste',
        IRO_EDITOR_IMAGE_PASTE_URL . '/editor.js',
        array(),
        $script_version,
        true
    );

    wp_localize_script(
        'iro-editor-image-paste',
        'iroImagePaste',
        array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('iro_paste_image'),
            'postId' => iro_editor_image_paste_post_id(),
            'maxSize' => iro_editor_image_paste_max_bytes(),
            'maxSizeLabel' => size_format(iro_editor_image_paste_max_bytes()),
            'strings' => array(
                'uploading' => '正在上传图片…',
                'uploadingProgress' => '正在上传图片… %d%%',
                'inserted' => '图片已上传并插入',
                'insertedButMoved' => '图片已上传，但插入位置已改变，已插入到当前光标处',
                'notImage' => '只能上传图片文件。',
                'tooLarge' => '图片超过 %s，请压缩后再试。',
                'uploadFailed' => '图片上传失败，请重试。',
                'networkError' => '网络异常，图片上传失败。',
                'serverError' => '服务器返回异常，图片上传失败。',
                'noPermission' => '你没有上传图片的权限。',
                'expired' => '页面已过期，请刷新后重试。',
                'dropHint' => '松开即可上传图片',
                'dropSubHint' => '图片会自动插入 Markdown',
            ),
        )
    );
}
// 优先级 5：比 Githuber MD 的编辑器脚本更早入队。
// editor.js 会轮询等待 githuber_md_editor，所以不需要依赖 githuber-md 句柄；
// 这样可以避免编辑器已经出现、但粘贴脚本还排在队尾没加载的空窗期。
add_action('admin_enqueue_scripts', 'iro_editor_image_paste_enqueue', 5);

/**
 * 把 PHP 上传错误码转成可读提示。
 *
 * @param int $error_code $_FILES 中的错误码。
 * @return string
 */
function iro_editor_image_paste_upload_error_message($error_code)
{
    switch ((int) $error_code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return sprintf('图片超过服务器允许的大小（%s）。', size_format(iro_editor_image_paste_max_bytes()));
        case UPLOAD_ERR_PARTIAL:
            return '图片只上传了一部分，请重试。';
        case UPLOAD_ERR_NO_FILE:
            return '没有收到图片文件。';
        case UPLOAD_ERR_NO_TMP_DIR:
            return '服务器缺少临时目录，无法接收图片。';
        case UPLOAD_ERR_CANT_WRITE:
            return '服务器无法写入图片，请检查上传目录权限。';
        case UPLOAD_ERR_EXTENSION:
            return '上传被服务器扩展中止。';
        default:
            return '图片上传失败，请重试。';
    }
}

/**
 * 处理粘贴图片上传。
 *
 * @return void
 */
function iro_editor_image_paste_handle_upload()
{
    if (!check_ajax_referer('iro_paste_image', 'nonce', false)) {
        wp_send_json_error(array('message' => '页面已过期，请刷新后重试。'), 403);
    }

    if (!current_user_can('upload_files')) {
        wp_send_json_error(array('message' => '你没有上传图片的权限。'), 403);
    }

    $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;

    if ($post_id > 0 && !current_user_can('edit_post', $post_id)) {
        wp_send_json_error(array('message' => '你没有编辑这篇文章的权限。'), 403);
    }

    if (0 === $post_id && !current_user_can('edit_posts')) {
        wp_send_json_error(array('message' => '你没有新建文章的权限。'), 403);
    }

    if (empty($_FILES['file']) || !is_array($_FILES['file']) || !isset($_FILES['file']['error'])) {
        wp_send_json_error(array('message' => '没有收到图片文件。'), 400);
    }

    $file = $_FILES['file'];

    if (UPLOAD_ERR_OK !== (int) $file['error']) {
        wp_send_json_error(array('message' => iro_editor_image_paste_upload_error_message($file['error'])), 400);
    }

    $max_bytes = iro_editor_image_paste_max_bytes();

    if ((int) $file['size'] > $max_bytes) {
        wp_send_json_error(
            array('message' => sprintf('图片超过 %s，请压缩后再试。', size_format($max_bytes))),
            413
        );
    }

    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        wp_send_json_error(array('message' => '没有收到有效的图片文件。'), 400);
    }

    // 不信任浏览器传来的 MIME，直接读取文件头。
    $image_info = @getimagesize($file['tmp_name']);

    if (empty($image_info) || empty($image_info['mime'])) {
        wp_send_json_error(array('message' => '这个文件不是有效的图片。'), 400);
    }

    $mime = strtolower((string) $image_info['mime']);

    if (!in_array($mime, iro_editor_image_paste_allowed_mimes(), true)) {
        wp_send_json_error(array('message' => '不支持这种图片格式，请使用 PNG、JPG、GIF、WebP 或 AVIF。'), 400);
    }

    $extension = image_type_to_extension((int) $image_info[2], false);

    if (!$extension) {
        $extension = 'png';
    }

    $filename = isset($file['name']) ? sanitize_file_name((string) $file['name']) : '';

    if ('' === $filename || !preg_match('/\.[a-z0-9]{2,5}$/i', $filename)) {
        $filename = 'pasted-' . gmdate('Ymd-His') . '-' . wp_generate_password(4, false, false) . '.' . $extension;
    } else {
        // 让扩展名与实际图片类型一致，避免 wp_handle_upload 的 MIME 校验失败。
        $filename = preg_replace('/\.[a-z0-9]{2,5}$/i', '.' . $extension, $filename);
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $_FILES['file']['name'] = $filename;

    $attachment_id = media_handle_upload(
        'file',
        $post_id,
        array(
            'post_title' => '粘贴图片 ' . wp_date('Y-m-d H:i'),
        ),
        array(
            'test_form' => false,
        )
    );

    if (is_wp_error($attachment_id)) {
        wp_send_json_error(array('message' => $attachment_id->get_error_message()), 500);
    }

    $url = wp_get_attachment_url($attachment_id);

    if (!$url) {
        wp_send_json_error(array('message' => '图片已上传，但无法获取图片地址。'), 500);
    }

    if (is_ssl()) {
        $url = str_replace('http://', 'https://', $url);
    }

    $metadata = wp_get_attachment_metadata($attachment_id);
    $alt = (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true);

    wp_send_json_success(
        array(
            'id' => (int) $attachment_id,
            'url' => $url,
            'alt' => $alt,
            'width' => isset($metadata['width']) ? (int) $metadata['width'] : (int) $image_info[0],
            'height' => isset($metadata['height']) ? (int) $metadata['height'] : (int) $image_info[1],
        )
    );
}
add_action('wp_ajax_iro_paste_image', 'iro_editor_image_paste_handle_upload');
