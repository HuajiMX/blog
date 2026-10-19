<?php
/**
 * Plugin Name: IRO Image Pool
 * Description: 把 Sakurairo 的三类外部随机图（桌面端封面 / 手机端封面 / 文章特色图）在服务器端异步缓存为本地图片池，按计划轮换更新。前台访问本地池，不再等待慢速外部 API。
 * Version: 1.0.0
 * Requires PHP: 7.4
 * Requires at least: 5.8
 *
 * 说明：
 * - 本文件放在 mu-plugins 目录，WordPress 会自动加载，不需要在后台启用，也不会被主题更新覆盖。
 * - 后台主题设置里的三个 API 地址不会被修改；本插件只在“前台请求”期间把内存中的选项替换为本站图片池接口。
 * - 图片池更新任务从后台设置中的原始 API 地址下载图片，保存在 uploads/iro-image-pool/ 下并定期轮换。
 *
 * 可选常量（写在 wp-config.php 中可在不修改本文件的情况下调整）：
 *   IRO_IMAGE_POOL_DISABLE  设为 true 可整体停用（应急回滚）。
 *   IRO_IMAGE_POOL_TARGET   每种类型保留的图片数量，默认 10。
 *   IRO_IMAGE_POOL_ROTATE   每次更新轮换的新图片数量，默认 1。
 *   IRO_IMAGE_POOL_MAX      单次运行最多下载数量，默认 20。
 *   IRO_IMAGE_POOL_INTERVAL 图片池过期判定间隔（秒），默认 3600；计划任务固定每小时运行一次。
 *   IRO_IMAGE_POOL_TIMEOUT  单次外部请求超时（秒），默认 15。
 *   IRO_IMAGE_POOL_BUDGET   单次后台任务时间预算（秒），默认 90。
 *
 * @package IRO_Image_Pool
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('IRO_IMAGE_POOL_DIRNAME')) {
    define('IRO_IMAGE_POOL_DIRNAME', 'iro-image-pool');
}
if (!defined('IRO_IMAGE_POOL_TARGET')) {
    define('IRO_IMAGE_POOL_TARGET', 10);
}
if (!defined('IRO_IMAGE_POOL_ROTATE')) {
    define('IRO_IMAGE_POOL_ROTATE', 1);
}
if (!defined('IRO_IMAGE_POOL_MAX')) {
    define('IRO_IMAGE_POOL_MAX', 20);
}
if (!defined('IRO_IMAGE_POOL_INTERVAL')) {
    define('IRO_IMAGE_POOL_INTERVAL', defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600);
}
if (!defined('IRO_IMAGE_POOL_MIN_INTERVAL')) {
    define('IRO_IMAGE_POOL_MIN_INTERVAL', defined('MINUTE_IN_SECONDS') ? 30 * MINUTE_IN_SECONDS : 1800);
}
if (!defined('IRO_IMAGE_POOL_TIMEOUT')) {
    define('IRO_IMAGE_POOL_TIMEOUT', 15);
}
if (!defined('IRO_IMAGE_POOL_BUDGET')) {
    define('IRO_IMAGE_POOL_BUDGET', 90);
}
if (!defined('IRO_IMAGE_POOL_MAX_BYTES')) {
    define('IRO_IMAGE_POOL_MAX_BYTES', 8 * 1024 * 1024);
}
if (!defined('IRO_IMAGE_POOL_MIN_BYTES')) {
    define('IRO_IMAGE_POOL_MIN_BYTES', 512);
}
if (!defined('IRO_IMAGE_POOL_MIN_DIMENSION')) {
    define('IRO_IMAGE_POOL_MIN_DIMENSION', 64);
}

/*
 * 图片压缩：外部随机图 API 常见返回 2–6MB 的 4K 原图，直接落地会让
 * 首页一次滚动就下载十几 MB。下载后统一缩到合理宽度并转成 WebP。
 */
if (!defined('IRO_IMAGE_POOL_OPTIMIZE')) {
    define('IRO_IMAGE_POOL_OPTIMIZE', true);
}
if (!defined('IRO_IMAGE_POOL_WEBP_QUALITY')) {
    define('IRO_IMAGE_POOL_WEBP_QUALITY', 82);
}
if (!defined('IRO_IMAGE_POOL_MAX_WIDTH_DESKTOP')) {
    define('IRO_IMAGE_POOL_MAX_WIDTH_DESKTOP', 1920);
}
if (!defined('IRO_IMAGE_POOL_MAX_WIDTH_POST')) {
    define('IRO_IMAGE_POOL_MAX_WIDTH_POST', 1280);
}
if (!defined('IRO_IMAGE_POOL_MAX_WIDTH_MOBILE')) {
    define('IRO_IMAGE_POOL_MAX_WIDTH_MOBILE', 1080);
}
if (!defined('IRO_IMAGE_POOL_MAX_PIXELS')) {
    define('IRO_IMAGE_POOL_MAX_PIXELS', 40000000);
}

/**
 * 图片池核心类。
 */
class IRO_Image_Pool
{
    const OPTION_STATUS    = 'iro_image_pool_status';
    const OPTION_LOCK      = 'iro_image_pool_lock';
    const OPTION_LAST_KICK = 'iro_image_pool_last_kick';
    const CRON_HOOK        = 'iro_image_pool_refresh_event';

    /** @var string[] */
    private static $types = array('desktop', 'mobile', 'post');

    /**
     * 注册钩子。mu-plugin 加载时调用一次。
     *
     * @return void
     */
    public static function boot()
    {
        // 主题在 functions.php 中把 iro_options 读入 $GLOBALS，之后所有 iro_opt() 都走内存数组。
        // 在 after_setup_theme 时改写内存数组：只影响前台输出，不会写回数据库。
        add_action('after_setup_theme', array(__CLASS__, 'install_frontend_override'), 1);

        add_action('init', array(__CLASS__, 'schedule'), 5);
        add_action('init', array(__CLASS__, 'maybe_kick_on_init'), 20);
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
        add_action(self::CRON_HOOK, array(__CLASS__, 'cron_refresh'));
        add_action('admin_menu', array(__CLASS__, 'admin_menu'));

        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('iro-image-pool', 'IRO_Image_Pool_CLI');
        }
    }

    /* ---------------------------------------------------------------------
     * 前台地址替换
     * ------------------------------------------------------------------ */

    /**
     * 把内存中的三个随机图地址替换为本站图片池接口。
     *
     * 注意：只改 $GLOBALS['iro_options']，绝不 update_option()。
     * 后台保存设置时读取的是数据库原始值，因此不会把图片池地址写回配置。
     *
     * @return void
     */
    public static function install_frontend_override()
    {
        if (is_admin() || wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) {
            return;
        }
        if (!isset($GLOBALS['iro_options']) || !is_array($GLOBALS['iro_options'])) {
            return;
        }

        $GLOBALS['iro_options'] = self::rewrite_options($GLOBALS['iro_options']);

        // 让主题的 get_random_bg_url() 等函数在渲染时使用新地址。
        if (!defined('IRO_IMAGE_POOL_ACTIVE')) {
            define('IRO_IMAGE_POOL_ACTIVE', true);
        }
    }

    /**
     * 返回改写后的选项数组（纯函数，方便测试）。
     *
     * @param array $options 主题选项。
     * @return array
     */
    public static function rewrite_options($options)
    {
        if (!is_array($options)) {
            return $options;
        }

        $cover_mode = isset($options['random_graphs_options']) ? (string) $options['random_graphs_options'] : '';
        if ($cover_mode === 'external_api') {
            $options['random_graphs_link'] = self::endpoint_url('desktop');

            if (!empty($options['random_graphs_mts'])) {
                $options['random_graphs_link_mobile'] = self::endpoint_url('mobile');
            }
        }

        $post_mode = isset($options['post_cover_options']) ? (string) $options['post_cover_options'] : '';
        if ($post_mode === 'type_2') {
            $options['post_cover'] = self::endpoint_url('post');
        }

        return $options;
    }

    /**
     * 生成图片池接口地址。
     *
     * 前台使用 wp-content/mu-plugins/iro-image-pool/random.php 这个独立文件，
     * 它不加载 WordPress（约 2ms 完成 302），避免每张图都走一次完整的
     * REST API 引导（约 110ms，6 张并发时接近 0.7s）。
     *
     * @param string $type desktop|mobile|post。
     * @return string
     */
    public static function endpoint_url($type)
    {
        $url = content_url('mu-plugins/iro-image-pool/random.php');

        /**
         * 允许切换到其他端点（例如需要回滚到 REST 接口时）。
         *
         * @param string $url  端点地址。
         * @param string $type 类型。
         */
        return apply_filters(
            'iro_image_pool_endpoint_url',
            add_query_arg('type', $type, $url),
            $type
        );
    }

    /**
     * 根据主题设置判断需要维护哪些类型的图片池。
     *
     * @return string[]
     */
    public static function required_types()
    {
        $types = array();

        if ((string) self::raw('random_graphs_options', '') === 'external_api') {
            $types[] = 'desktop';
            if (!empty(self::raw('random_graphs_mts', false))) {
                $types[] = 'mobile';
            }
        }

        if ((string) self::raw('post_cover_options', '') === 'type_2') {
            $types[] = 'post';
        }

        $types = array_values(array_unique($types));

        /**
         * 允许其他代码调整需要维护的类型。
         *
         * @param string[] $types 类型列表。
         */
        return apply_filters('iro_image_pool_required_types', $types);
    }

    /**
     * 读取主题选项的原始值（数据库值，不是被前台替换后的内存值）。
     *
     * @param string $key     选项键。
     * @param mixed  $default 默认值。
     * @return mixed
     */
    private static function raw($key, $default = '')
    {
        $options = get_option('iro_options', array());
        if (!is_array($options) || !array_key_exists($key, $options)) {
            return $default;
        }
        return $options[$key];
    }

    /**
     * 解析某一类图片对应的外部 API 地址。
     *
     * - desktop：主题“桌面端随机图地址”。
     * - mobile：主题“手机端随机图地址”，为空时退回桌面端地址，并模拟主题 JS 的 type=mobile 参数。
     * - post：文章特色图地址，为空时退回桌面端地址。
     *
     * @param string $type desktop|mobile|post。
     * @return string 空字符串表示没有可用地址。
     */
    public static function resolve_source($type)
    {
        $desktop = trim((string) self::raw('random_graphs_link', ''));
        $mobile  = trim((string) self::raw('random_graphs_link_mobile', ''));
        $post    = trim((string) self::raw('post_cover', ''));

        switch ($type) {
            case 'desktop':
                $url = $desktop;
                break;
            case 'mobile':
                $url = $mobile !== '' ? $mobile : $desktop;
                break;
            case 'post':
                $url = $post !== '' ? $post : $desktop;
                break;
            default:
                $url = '';
        }

        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return '';
        }

        // 主题前端对手机端会追加 type=mobile（Sakurairo 的 swicher/app.js 行为）。
        // 服务端尽量按同样方式请求，保证缓存到的图与原来一致。
        if ($type === 'mobile' && !empty(self::raw('random_graphs_mts', false))) {
            $url = add_query_arg('type', 'mobile', $url);
        }

        return $url;
    }

    /**
     * 源地址指纹（带站点盐，避免公开的 index.json 泄露可被猜测的 API 地址散列）。
     *
     * @param string $source 源地址。
     * @return string
     */
    private static function source_hash($source)
    {
        return wp_hash('iro-image-pool|' . $source);
    }

    /* ---------------------------------------------------------------------
     * 图片池存储
     * ------------------------------------------------------------------ */

    /**
     * 图片池目录（会按需创建）。
     *
     * @param string $type 类型。
     * @return string 失败返回空字符串。
     */
    private static function pool_dir($type)
    {
        if (!in_array($type, self::$types, true)) {
            return '';
        }

        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) {
            return '';
        }

        $dir = trailingslashit($uploads['basedir']) . IRO_IMAGE_POOL_DIRNAME . '/' . $type;
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return '';
        }

        if (is_dir($dir) && !file_exists($dir . '/index.html')) {
            @file_put_contents($dir . '/index.html', '');
        }

        return $dir;
    }

    /**
     * 图片池的公开 URL 前缀。
     *
     * @param string $type 类型。
     * @return string
     */
    private static function pool_base_url($type)
    {
        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) {
            return '';
        }
        return trailingslashit($uploads['baseurl']) . IRO_IMAGE_POOL_DIRNAME . '/' . $type . '/';
    }

    /**
     * 读取索引。
     *
     * @param string $type 类型。
     * @return array{version:int,source_hash:string,updated:int,items:array}
     */
    public static function read_index($type)
    {
        $empty = array(
            'version'     => 1,
            'source_hash' => '',
            'updated'     => 0,
            'items'       => array(),
        );

        $dir = self::pool_dir($type);
        if ($dir === '') {
            return $empty;
        }

        $path = $dir . '/index.json';
        if (!is_file($path)) {
            return $empty;
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return $empty;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
            return $empty;
        }

        $items = array();
        foreach ($data['items'] as $item) {
            if (!is_array($item) || empty($item['file'])) {
                continue;
            }
            $file = (string) $item['file'];
            if (basename($file) !== $file) {
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9._-]+\.(?:jpe?g|png|webp|gif)$/i', $file)) {
                continue;
            }
            $items[] = array(
                'file'  => $file,
                'mime'  => isset($item['mime']) ? (string) $item['mime'] : '',
                'size'  => isset($item['size']) ? (int) $item['size'] : 0,
                'added' => isset($item['added']) ? (int) $item['added'] : 0,
            );
        }

        return array(
            'version'     => 1,
            'source_hash' => isset($data['source_hash']) ? (string) $data['source_hash'] : '',
            'updated'     => isset($data['updated']) ? (int) $data['updated'] : 0,
            'items'       => $items,
        );
    }

    /**
     * 原子写入索引。
     *
     * @param string $type  类型。
     * @param array  $index 索引。
     * @return bool
     */
    private static function write_index($type, $index)
    {
        $dir = self::pool_dir($type);
        if ($dir === '') {
            return false;
        }

        $path = $dir . '/index.json';
        $tmp  = $path . '.tmp-' . wp_generate_password(8, false, false);
        $json = wp_json_encode($index);
        if (!is_string($json)) {
            return false;
        }

        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            @unlink($tmp);
            return false;
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }

        @chmod($path, 0644);
        return true;
    }

    /**
     * 清空某一类图片池（仅图片与临时文件，保留 index.html）。
     *
     * @param string $type 类型。
     * @return void
     */
    private static function wipe_pool($type)
    {
        $dir = self::pool_dir($type);
        if ($dir === '') {
            return;
        }

        $index = self::read_index($type);
        foreach ($index['items'] as $item) {
            @unlink($dir . '/' . $item['file']);
        }

        $files = glob($dir . '/*');
        if (is_array($files)) {
            foreach ($files as $file) {
                if (!is_file($file)) {
                    continue;
                }
                $name = basename($file);
                if ($name === 'index.html' || $name === 'index.json') {
                    continue;
                }
                if (preg_match('/\.(?:jpe?g|png|webp|gif|part)$/i', $name) || strpos($name, 'index.json.tmp-') === 0) {
                    @unlink($file);
                }
            }
        }
    }

    /* ---------------------------------------------------------------------
     * 下载与校验
     * ------------------------------------------------------------------ */

    /**
     * 随机文件名片段（random_bytes 失败时退回 wp_generate_password）。
     *
     * @param int $bytes 字节数。
     * @return string
     */
    private static function random_token($bytes = 6)
    {
        try {
            return bin2hex(random_bytes($bytes));
        } catch (Exception $e) {
            return wp_generate_password($bytes * 2, false, false);
        }
    }

    /**
     * 清理上次任务中断留下的临时文件。
     *
     * @param string $dir 图片池目录。
     * @return void
     */
    private static function cleanup_temp_files($dir)
    {
        $patterns = array(
            $dir . '/upload-*.part',
            $dir . '/index.json.tmp-*',
        );
        foreach ($patterns as $pattern) {
            $files = glob($pattern);
            if (!is_array($files)) {
                continue;
            }
            foreach ($files as $file) {
                if (is_file($file) && (time() - (int) @filemtime($file)) > 3600) {
                    @unlink($file);
                }
            }
        }
    }

    /**
     * MIME 别名归一化。
     *
     * @param string $mime MIME。
     * @return string
     */
    private static function normalize_mime($mime)
    {
        $mime = strtolower(trim((string) $mime));
        $aliases = array(
            'image/jpg'   => 'image/jpeg',
            'image/pjpeg' => 'image/jpeg',
            'image/x-png' => 'image/png',
        );
        return isset($aliases[$mime]) ? $aliases[$mime] : $mime;
    }

    /**
     * 允许保存的图片类型 => 扩展名。
     *
     * @return array<string,string>
     */
    private static function allowed_mimes()
    {
        return array(
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
        );
    }

    /**
     * 按类型选择请求 UA（手机端用移动端 UA，更贴近原来的前端行为）。
     *
     * @param string $type 类型。
     * @return string
     */
    private static function user_agent($type)
    {
        if ($type === 'mobile') {
            return 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
        }
        return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
    }

    /**
     * 从外部 API 下载一张图片并保存到池目录。
     *
     * @param string $source 已解析的 API 地址。
     * @param string $type   类型。
     * @return array|WP_Error
     */
    private static function download($source, $type)
    {
        $allowed = self::allowed_mimes();

        $response = wp_safe_remote_get(
            $source,
            array(
                'timeout'             => IRO_IMAGE_POOL_TIMEOUT,
                'redirection'         => 5,
                'limit_response_size' => IRO_IMAGE_POOL_MAX_BYTES + 1024,
                'user-agent'          => self::user_agent($type),
                'headers'             => array(
                    'Accept'        => 'image/webp,image/apng,image/png,image/jpeg,image/gif,image/*;q=0.8',
                    'Accept-Language' => 'zh-CN,zh;q=0.9,en;q=0.8',
                    'Referer'       => home_url('/'),
                    'Cache-Control' => 'no-cache',
                ),
            )
        );

        if (is_wp_error($response)) {
            return new WP_Error('iro_pool_http', '请求失败：' . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('iro_pool_status', '接口返回 HTTP ' . $code);
        }

        $ctype = self::normalize_mime((string) wp_remote_retrieve_header($response, 'content-type'));
        if (strpos($ctype, ';') !== false) {
            $ctype = self::normalize_mime(substr($ctype, 0, strpos($ctype, ';')));
        }
        if ($ctype !== '' && strpos($ctype, 'image/') !== 0 && $ctype !== 'application/octet-stream') {
            return new WP_Error('iro_pool_mime', '接口返回的不是图片（' . $ctype . '）');
        }

        $content_length = (int) wp_remote_retrieve_header($response, 'content-length');
        if ($content_length > IRO_IMAGE_POOL_MAX_BYTES) {
            return new WP_Error('iro_pool_too_large', '图片过大（' . size_format($content_length) . '）');
        }

        $body = wp_remote_retrieve_body($response);
        if (!is_string($body) || strlen($body) < IRO_IMAGE_POOL_MIN_BYTES) {
            return new WP_Error('iro_pool_empty', '接口响应为空或内容过小');
        }
        if (strlen($body) > IRO_IMAGE_POOL_MAX_BYTES) {
            return new WP_Error('iro_pool_too_large', '图片超过大小限制');
        }

        $dir = self::pool_dir($type);
        if ($dir === '') {
            return new WP_Error('iro_pool_dir', '图片池目录不可写');
        }

        $tmp = $dir . '/upload-' . wp_generate_password(12, false, false) . '.part';
        if (@file_put_contents($tmp, $body, LOCK_EX) === false) {
            @unlink($tmp);
            return new WP_Error('iro_pool_write', '写入临时文件失败');
        }

        $info = @getimagesize($tmp);
        if (!is_array($info) || empty($info[0]) || empty($info[1])) {
            @unlink($tmp);
            return new WP_Error('iro_pool_invalid', '下载内容不是有效图片');
        }
        if ((int) $info[0] < IRO_IMAGE_POOL_MIN_DIMENSION || (int) $info[1] < IRO_IMAGE_POOL_MIN_DIMENSION) {
            @unlink($tmp);
            return new WP_Error('iro_pool_small', '图片尺寸过小（' . (int) $info[0] . 'x' . (int) $info[1] . '）');
        }
        if (((int) $info[0] * (int) $info[1]) > IRO_IMAGE_POOL_MAX_PIXELS) {
            // 例如 12000×6912：GD 解码要 300MB 以上内存，直接拒绝，让接口换一张。
            @unlink($tmp);
            return new WP_Error('iro_pool_pixels', '图片像素过大（' . (int) $info[0] . 'x' . (int) $info[1] . '）');
        }

        $mime = '';
        if (!empty($info['mime'])) {
            $mime = self::normalize_mime($info['mime']);
        }
        if (!isset($allowed[$mime]) && function_exists('wp_get_image_mime')) {
            $mime = self::normalize_mime((string) wp_get_image_mime($tmp));
        }
        if (!isset($allowed[$mime])) {
            @unlink($tmp);
            return new WP_Error('iro_pool_unsupported', '不支持的图片格式（' . ($mime !== '' ? $mime : 'unknown') . '）');
        }

        // 外部 API 经常返回 4K 原图（单张 2–6MB），先压缩再入库。
        $optimized = self::optimize_image($tmp, $mime, $type);
        if (is_array($optimized)) {
            @unlink($tmp);
            $tmp  = $optimized['path'];
            $mime = $optimized['mime'];
        }

        $ext   = $allowed[$mime];
        $name  = gmdate('YmdHis') . '-' . self::random_token(6) . '.' . $ext;
        $final = $dir . '/' . $name;

        if (!@rename($tmp, $final)) {
            @unlink($tmp);
            return new WP_Error('iro_pool_save', '保存图片失败');
        }
        @chmod($final, 0644);

        $size = @filesize($final);

        return array(
            'file'  => $name,
            'mime'  => $mime,
            'size'  => $size === false ? strlen($body) : (int) $size,
            'added' => time(),
        );
    }

    /* ---------------------------------------------------------------------
     * 图片压缩
     * ------------------------------------------------------------------ */

    /**
     * 某一类图片的目标最大宽度。
     *
     * @param string $type 类型。
     * @return int
     */
    private static function max_width($type)
    {
        switch ($type) {
            case 'desktop':
                $width = IRO_IMAGE_POOL_MAX_WIDTH_DESKTOP;
                break;
            case 'mobile':
                $width = IRO_IMAGE_POOL_MAX_WIDTH_MOBILE;
                break;
            default:
                $width = IRO_IMAGE_POOL_MAX_WIDTH_POST;
        }

        /**
         * 允许按类型调整最大宽度。
         *
         * @param int    $width 目标宽度。
         * @param string $type  类型。
         */
        return (int) apply_filters('iro_image_pool_max_width', (int) $width, $type);
    }

    /**
     * 把图片缩到目标宽度并转为 WebP。
     *
     * 返回 false 表示“保持原样”（GIF、GD 不可用、图片过大等），调用方
     * 继续使用原文件即可。
     *
     * @param string $path 文件路径。
     * @param string $mime 当前 MIME。
     * @param string $type 类型。
     * @return array|false
     */
    private static function optimize_image($path, $mime, $type)
    {
        if (!IRO_IMAGE_POOL_OPTIMIZE || $mime === 'image/gif' || !function_exists('imagewebp')) {
            return false;
        }
        if (!is_file($path) || (int) @filesize($path) < 10 * 1024) {
            return false;
        }

        $info = @getimagesize($path);
        if (!is_array($info) || empty($info[0]) || empty($info[1])) {
            return false;
        }

        $width  = (int) $info[0];
        $height = (int) $info[1];
        if ($width < 1 || $height < 1 || ($width * $height) > IRO_IMAGE_POOL_MAX_PIXELS) {
            return false;
        }

        $max = self::max_width($type);
        if ($max < 1) {
            return false;
        }

        $target_w = min($width, $max);
        $target_h = (int) max(1, round($height * $target_w / $width));

        if (function_exists('set_time_limit')) {
            @set_time_limit(60);
        }
        // 4000×3000 的图解码后约 48MB，默认 128M 限制在并发时容易被顶爆。
        @ini_set('memory_limit', '256M');

        $data = @file_get_contents($path);
        if (!is_string($data) || $data === '') {
            return false;
        }
        $src = @imagecreatefromstring($data);
        unset($data);
        if (!$src) {
            return false;
        }

        $dst = @imagecreatetruecolor($target_w, $target_h);
        if (!$dst) {
            imagedestroy($src);
            return false;
        }

        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $target_w, $target_h, $width, $height);
        imagedestroy($src);

        $out = $path . '.webp';
        $ok  = @imagewebp($dst, $out, (int) IRO_IMAGE_POOL_WEBP_QUALITY);
        imagedestroy($dst);

        if (!$ok || !is_file($out) || (int) @filesize($out) < 1024) {
            @unlink($out);
            return false;
        }

        return array(
            'path'   => $out,
            'mime'   => 'image/webp',
            'width'  => $target_w,
            'height' => $target_h,
        );
    }

    /**
     * 压缩某一类图片池中已有的图片（一次性维护用）。
     *
     * 新文件名带 -opt 后缀，URL 随之变化，可绕开 CDN 上按旧 URL 缓存的
     * 大图；处理完成后同步更新 index.json。
     *
     * @param string $type 类型。
     * @return array
     */
    public static function optimize_type($type)
    {
        $result = array(
            'type'    => $type,
            'files'   => 0,
            'skipped' => 0,
            'errors'  => 0,
            'before'  => 0,
            'after'   => 0,
        );

        $dir = self::pool_dir($type);
        if ($dir === '') {
            return $result;
        }

        $index = self::read_index($type);
        if (empty($index['items'])) {
            return $result;
        }

        $changed = false;
        foreach ($index['items'] as $i => $item) {
            $file = $dir . '/' . $item['file'];
            if (!is_file($file)) {
                continue;
            }

            $before = (int) @filesize($file);
            $result['before'] += $before;

            if (strpos($item['file'], '-opt.') !== false) {
                $result['after'] += $before;
                $result['skipped']++;
                continue;
            }

            $mime = isset($item['mime']) ? self::normalize_mime($item['mime']) : '';
            if ($mime === '' && function_exists('wp_get_image_mime')) {
                $mime = self::normalize_mime((string) wp_get_image_mime($file));
            }

            $optimized = self::optimize_image($file, $mime, $type);
            if (!is_array($optimized)) {
                $result['after'] += $before;
                $result['errors']++;
                continue;
            }

            $stem     = pathinfo($item['file'], PATHINFO_FILENAME);
            $new_name = $stem . '-opt.webp';
            $new_path = $dir . '/' . $new_name;

            if (!@rename($optimized['path'], $new_path)) {
                @unlink($optimized['path']);
                $result['after'] += $before;
                $result['errors']++;
                continue;
            }
            @chmod($new_path, 0644);

            if ($new_path !== $file) {
                @unlink($file);
            }

            $size = (int) @filesize($new_path);

            $index['items'][$i]['file'] = $new_name;
            $index['items'][$i]['mime'] = 'image/webp';
            $index['items'][$i]['size'] = $size;

            $result['after'] += $size;
            $result['files']++;
            $changed = true;

            unset($optimized);
        }

        if ($changed) {
            $index['updated'] = time();
            self::write_index($type, $index);
        }

        return $result;
    }

    /**
     * 压缩全部类型的已有图片。
     *
     * @return array
     */
    public static function optimize_all()
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $results = array();
        foreach (self::$types as $type) {
            $results[$type] = self::optimize_type($type);
        }

        return $results;
    }

    /* ---------------------------------------------------------------------
     * 更新任务
     * ------------------------------------------------------------------ */

    /**
     * 更新单个类型的图片池。
     *
     * @param string   $type   类型。
     * @param bool     $force  是否忽略最小间隔。
     * @param float|null $started 本次任务开始时间（microtime），用于时间预算。
     * @return array
     */
    public static function refresh_type($type, $force = false, $started = null)
    {
        $result = array(
            'type'    => $type,
            'fetched' => 0,
            'count'   => 0,
            'skipped' => false,
            'error'   => '',
        );

        $source = self::resolve_source($type);
        if ($source === '') {
            $result['error'] = '未配置可用的外部 API 地址';
            self::update_type_status($type, array('last_error' => $result['error']));
            return $result;
        }

        $dir = self::pool_dir($type);
        if ($dir === '') {
            $result['error'] = '图片池目录不可写';
            self::update_type_status($type, array('last_error' => $result['error']));
            return $result;
        }

        self::cleanup_temp_files($dir);

        $status     = self::get_status();
        $last_fetch = isset($status['types'][$type]['last_fetch']) ? (int) $status['types'][$type]['last_fetch'] : 0;
        $index      = self::read_index($type);
        $source_hash = self::source_hash($source);

        if ($index['source_hash'] !== '' && $index['source_hash'] !== $source_hash) {
            // API 地址换了，旧图不再具有代表性，直接清空重建。
            self::wipe_pool($type);
            $index = array(
                'version'     => 1,
                'source_hash' => '',
                'updated'     => 0,
                'items'       => array(),
            );
        }
        $index['source_hash'] = $source_hash;

        $valid = array();
        foreach ($index['items'] as $item) {
            if (is_file($dir . '/' . $item['file'])) {
                $valid[] = $item;
            }
        }
        $index['items'] = $valid;
        $count = count($valid);

        if (!$force && $count >= IRO_IMAGE_POOL_TARGET && (time() - $last_fetch) < IRO_IMAGE_POOL_MIN_INTERVAL) {
            $result['count']   = $count;
            $result['skipped'] = true;
            return $result;
        }

        $need   = max(0, IRO_IMAGE_POOL_TARGET - $count);
        $rotate = $count >= IRO_IMAGE_POOL_TARGET ? (int) IRO_IMAGE_POOL_ROTATE : 0;
        $fetch  = min((int) IRO_IMAGE_POOL_MAX, $need + $rotate);

        $failures = 0;
        for ($i = 0; $i < $fetch; $i++) {
            if ($started !== null && (microtime(true) - $started) > IRO_IMAGE_POOL_BUDGET) {
                $result['error'] = '达到单次运行时间上限，剩余图片将在下次更新';
                break;
            }

            $item = self::download($source, $type);
            if (is_wp_error($item)) {
                $failures++;
                $result['error'] = $item->get_error_message();
                if ($failures >= 2) {
                    break;
                }
                continue;
            }

            $failures = 0;
            $index['items'][] = $item;
            $result['fetched']++;

            // 每成功一张就落盘，任务被超时中断时已下载的图片不会丢。
            $index['updated'] = time();
            self::write_index($type, $index);
        }

        // 轮换：按加入时间保留最新的 TARGET 张，删除更早的。
        usort(
            $index['items'],
            function ($a, $b) {
                return (int) $a['added'] <=> (int) $b['added'];
            }
        );
        while (count($index['items']) > IRO_IMAGE_POOL_TARGET) {
            $old = array_shift($index['items']);
            @unlink($dir . '/' . $old['file']);
        }

        $index['updated'] = time();
        self::write_index($type, $index);

        $bytes = 0;
        foreach ($index['items'] as $item) {
            $bytes += (int) $item['size'];
        }

        $result['count'] = count($index['items']);
        self::update_type_status(
            $type,
            array(
                'last_fetch' => time(),
                'last_error' => $result['error'],
                'count'      => $result['count'],
                'bytes'      => $bytes,
                'source'     => self::mask_url($source),
            )
        );

        return $result;
    }

    /**
     * 更新所有启用的类型。
     *
     * @param bool $force 是否强制（后台按钮 / WP-CLI）。
     * @return array|WP_Error
     */
    public static function refresh_all($force = false)
    {
        $types = self::required_types();
        if (empty($types)) {
            return new WP_Error('iro_pool_no_types', '主题设置中没有启用外部随机图，图片池无需更新。');
        }

        if (!self::acquire_lock()) {
            return new WP_Error('iro_pool_locked', '图片池正在更新中，请稍后再试。');
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(IRO_IMAGE_POOL_BUDGET + 30);
        }
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }

        $started = microtime(true);
        $summary = array(
            'started' => time(),
            'types'   => array(),
        );

        try {
            foreach ($types as $type) {
                $summary['types'][$type] = self::refresh_type($type, $force, $started);
                if ((microtime(true) - $started) > IRO_IMAGE_POOL_BUDGET) {
                    break;
                }
            }

            self::update_status(array('last_run' => time()));
        } finally {
            self::release_lock();
        }

        return $summary;
    }

    /**
     * 判断图片池是否需要更新。
     *
     * @return bool
     */
    public static function is_stale()
    {
        $types = self::required_types();
        if (empty($types)) {
            return false;
        }

        $has_source = false;
        $status     = self::get_status();

        foreach ($types as $type) {
            $source = self::resolve_source($type);
            if ($source === '') {
                continue;
            }
            $has_source = true;

            $index  = self::read_index($type);
            $dir    = self::pool_dir($type);
            $usable = 0;
            if ($dir !== '') {
                foreach ($index['items'] as $item) {
                    if (is_file($dir . '/' . $item['file'])) {
                        $usable++;
                    }
                }
            }
            if ($usable < IRO_IMAGE_POOL_TARGET) {
                return true;
            }
            if ($index['source_hash'] !== self::source_hash($source)) {
                return true;
            }
        }

        if (!$has_source) {
            return false;
        }

        $last_run = isset($status['last_run']) ? (int) $status['last_run'] : 0;
        return (time() - $last_run) > (int) IRO_IMAGE_POOL_INTERVAL;
    }

    /**
     * 通过非阻塞回环请求触发后台更新。
     *
     * @param bool $force 池为空时强制尝试。
     * @return bool
     */
    public static function maybe_kick($force = false)
    {
        if (defined('IRO_POOL_REFRESHING')) {
            return false;
        }
        if (empty(self::required_types())) {
            return false;
        }

        // 用自动加载的小选项做频率限制：每次图片请求最多只有一次缓存命中，
        // 不会为每张图都去读索引文件或查询非自动加载选项。
        $last_kick = (int) get_option(self::OPTION_LAST_KICK, 0);
        $window    = 10 * (defined('MINUTE_IN_SECONDS') ? MINUTE_IN_SECONDS : 60);
        if ($last_kick > 0 && (time() - $last_kick) < $window) {
            return false;
        }
        update_option(self::OPTION_LAST_KICK, time(), true);

        if (!$force && !self::is_stale()) {
            return false;
        }

        $url = add_query_arg(
            'token',
            self::refresh_token(),
            rest_url('iro-image-pool/v1/refresh')
        );

        wp_remote_post(
            $url,
            array(
                'timeout'   => 0.05,
                'blocking'  => false,
                'sslverify' => apply_filters('https_local_ssl_verify', false),
                'headers'   => array('Connection' => 'close'),
                'body'      => array('token' => self::refresh_token()),
            )
        );

        return true;
    }

    /**
     * 页面请求时顺带检查一次（全页缓存生效时由 /random 接口兜底）。
     *
     * @return void
     */
    public static function maybe_kick_on_init()
    {
        if (is_admin() || wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) {
            return;
        }
        self::maybe_kick(false);
    }

    /**
     * WP-Cron 回调。
     *
     * @return void
     */
    public static function cron_refresh()
    {
        self::refresh_all(false);
    }

    /**
     * 注册定时任务。
     *
     * @return void
     */
    public static function schedule()
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 5 * (defined('MINUTE_IN_SECONDS') ? MINUTE_IN_SECONDS : 60), 'hourly', self::CRON_HOOK);
        }
    }

    /* ---------------------------------------------------------------------
     * REST 接口
     * ------------------------------------------------------------------ */

    /**
     * 注册路由。
     *
     * @return void
     */
    public static function register_routes()
    {
        register_rest_route(
            'iro-image-pool/v1',
            '/random',
            array(
                'methods'             => 'GET',
                'callback'            => array(__CLASS__, 'rest_random'),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            'iro-image-pool/v1',
            '/refresh',
            array(
                'methods'             => 'POST',
                'callback'            => array(__CLASS__, 'rest_refresh'),
                'permission_callback' => '__return_true',
            )
        );
    }

    /**
     * 随机图接口：302 到本地缓存的图片。
     *
     * @param WP_REST_Request $request 请求。
     * @return void
     */
    public static function rest_random($request)
    {
        if (!defined('IRO_POOL_REFRESHING')) {
            self::maybe_kick(false);
        }

        // 主题 JS 在手机端会额外追加 type=mobile（即使页面缓存来自桌面请求），
        // 因此优先识别它，保证手机端拿到手机池。
        $legacy = $request->get_param('type');
        if (is_string($legacy) && $legacy === 'mobile') {
            $type = 'mobile';
        } else {
            $type_param = $request->get_param('iro_type');
            $type       = is_string($type_param) ? sanitize_key($type_param) : '';
            if (!in_array($type, self::$types, true)) {
                $type = 'desktop';
            }
        }

        $image = self::pick($type);
        if ($image !== '') {
            nocache_headers();
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Access-Control-Allow-Origin: *');
            header('X-Iro-Pool: hit');
            header('X-Iro-Pool-Type: ' . $type);
            wp_redirect($image, 302);
            exit;
        }

        // 池为空：立即再尝试一次后台填充（仍受 10 分钟频率限制）。
        self::maybe_kick(true);

        // 兜底 1：直接回源，保持“有图”而不是裂图。
        $source = self::resolve_source($type);
        if ($source !== '') {
            nocache_headers();
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Access-Control-Allow-Origin: *');
            header('X-Iro-Pool: miss');
            header('X-Iro-Pool-Type: ' . $type);
            wp_redirect($source, 302);
            exit;
        }

        // 兜底 2：主题截图。
        $fallback_file = get_template_directory() . '/screenshot.jpg';
        if (is_file($fallback_file)) {
            nocache_headers();
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('X-Iro-Pool: fallback');
            wp_redirect(get_template_directory_uri() . '/screenshot.jpg', 302);
            exit;
        }

        // 兜底 3：1x1 透明 GIF，至少不出现 404。
        status_header(200);
        header('Content-Type: image/gif');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('X-Iro-Pool: empty');
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    /**
     * 后台更新接口（回环请求调用，token 校验）。
     *
     * @param WP_REST_Request $request 请求。
     * @return WP_REST_Response|WP_Error
     */
    public static function rest_refresh($request)
    {
        $token_param = $request->get_param('token');
        $token       = is_string($token_param) ? $token_param : '';
        if ($token === '' || !hash_equals(self::refresh_token(), $token)) {
            return new WP_Error('iro_pool_forbidden', '无效的更新令牌。', array('status' => 403));
        }

        if (!defined('IRO_POOL_REFRESHING')) {
            define('IRO_POOL_REFRESHING', true);
        }

        $result = self::refresh_all(false);
        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response(
            array(
                'ok'      => true,
                'summary' => $result,
            )
        );
    }

    /**
     * 更新令牌。
     *
     * @return string
     */
    public static function refresh_token()
    {
        return wp_hash('iro-image-pool-refresh');
    }

    /**
     * 随机选一张本地图片。
     *
     * @param string $type 类型。
     * @return string 图片 URL；无图返回空字符串。
     */
    public static function pick($type)
    {
        $order = array($type);
        foreach (self::$types as $candidate) {
            if ($candidate !== $type) {
                $order[] = $candidate;
            }
        }

        foreach ($order as $candidate) {
            $dir = self::pool_dir($candidate);
            if ($dir === '') {
                continue;
            }

            $index = self::read_index($candidate);
            if (empty($index['items'])) {
                continue;
            }

            $valid = array();
            foreach ($index['items'] as $item) {
                if (is_file($dir . '/' . $item['file'])) {
                    $valid[] = $item;
                }
            }
            if (empty($valid)) {
                continue;
            }

            $item = $valid[array_rand($valid)];
            $base = self::pool_base_url($candidate);
            if ($base === '') {
                continue;
            }

            return $base . rawurlencode($item['file']);
        }

        return '';
    }

    /* ---------------------------------------------------------------------
     * 状态与锁
     * ------------------------------------------------------------------ */

    /**
     * 读取状态选项。
     *
     * @return array
     */
    private static function get_status()
    {
        $status = get_option(self::OPTION_STATUS, array());
        if (!is_array($status)) {
            $status = array();
        }
        if (!isset($status['types']) || !is_array($status['types'])) {
            $status['types'] = array();
        }
        if (!isset($status['last_run'])) {
            $status['last_run'] = 0;
        }
        return $status;
    }

    /**
     * 合并写入全局状态。
     *
     * @param array $patch 字段。
     * @return void
     */
    private static function update_status($patch)
    {
        $status = self::get_status();
        $status = array_merge($status, $patch);
        update_option(self::OPTION_STATUS, $status, true);
    }

    /**
     * 合并写入单类型状态。
     *
     * @param string $type  类型。
     * @param array  $patch 字段。
     * @return void
     */
    private static function update_type_status($type, $patch)
    {
        $status = self::get_status();
        $current = isset($status['types'][$type]) && is_array($status['types'][$type])
            ? $status['types'][$type]
            : array();
        $status['types'][$type] = array_merge($current, $patch);
        update_option(self::OPTION_STATUS, $status, true);
    }

    /**
     * 获取状态快照（图片数量实时读取索引）。
     *
     * @return array
     */
    public static function snapshot()
    {
        $status   = self::get_status();
        $required = self::required_types();
        $out      = array(
            'last_run' => (int) $status['last_run'],
            'types'    => array(),
        );

        foreach (self::$types as $type) {
            $index   = self::read_index($type);
            $bytes   = 0;
            $usable  = 0;
            $dir     = self::pool_dir($type);
            foreach ($index['items'] as $item) {
                $bytes += (int) $item['size'];
                if ($dir !== '' && is_file($dir . '/' . $item['file'])) {
                    $usable++;
                }
            }

            $type_status = isset($status['types'][$type]) && is_array($status['types'][$type])
                ? $status['types'][$type]
                : array();

            $source = self::resolve_source($type);

            $out['types'][$type] = array(
                'label'      => self::type_label($type),
                'active'     => in_array($type, $required, true),
                'source'     => self::mask_url($source),
                'has_source' => $source !== '',
                'count'      => $usable,
                'target'     => (int) IRO_IMAGE_POOL_TARGET,
                'bytes'      => $bytes,
                'last_fetch' => isset($type_status['last_fetch']) ? (int) $type_status['last_fetch'] : 0,
                'last_error' => isset($type_status['last_error']) ? (string) $type_status['last_error'] : '',
            );
        }

        return $out;
    }

    /**
     * 类型中文名。
     *
     * @param string $type 类型。
     * @return string
     */
    private static function type_label($type)
    {
        $labels = array(
            'desktop' => '桌面端封面随机图',
            'mobile'  => '手机端封面随机图',
            'post'    => '文章特色图随机图',
        );
        return isset($labels[$type]) ? $labels[$type] : $type;
    }

    /**
     * 隐去 URL 的查询参数，便于在状态里展示而不泄露 token。
     *
     * @param string $url 地址。
     * @return string
     */
    private static function mask_url($url)
    {
        if ($url === '') {
            return '';
        }
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return $url;
        }
        $masked = (isset($parts['scheme']) ? $parts['scheme'] : 'http') . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $masked .= ':' . $parts['port'];
        }
        if (!empty($parts['path'])) {
            $masked .= $parts['path'];
        }
        return $masked;
    }

    /**
     * 获取更新锁。
     *
     * @return bool
     */
    private static function acquire_lock()
    {
        $now      = time();
        $existing = (int) get_option(self::OPTION_LOCK, 0);

        if ($existing > 0) {
            if (($now - $existing) < 15 * (defined('MINUTE_IN_SECONDS') ? MINUTE_IN_SECONDS : 60)) {
                return false;
            }
            delete_option(self::OPTION_LOCK);
        }

        return add_option(self::OPTION_LOCK, $now, '', 'no');
    }

    /**
     * 释放更新锁。
     *
     * @return void
     */
    private static function release_lock()
    {
        delete_option(self::OPTION_LOCK);
    }

    /* ---------------------------------------------------------------------
     * 后台页面
     * ------------------------------------------------------------------ */

    /**
     * 添加 Tools 菜单。
     *
     * @return void
     */
    public static function admin_menu()
    {
        add_management_page(
            '随机图片池',
            '随机图片池',
            'manage_options',
            'iro-image-pool',
            array(__CLASS__, 'render_admin')
        );
    }

    /**
     * 后台页面。
     *
     * @return void
     */
    public static function render_admin()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $notice = '';

        if (isset($_POST['iro_pool_action']) && $_POST['iro_pool_action'] === 'refresh') {
            check_admin_referer('iro_image_pool_admin');

            // 后台手动更新允许同步等待，最多受 PHP 超时限制。
            if (function_exists('set_time_limit')) {
                @set_time_limit(IRO_IMAGE_POOL_BUDGET + 60);
            }
            ignore_user_abort(true);

            $result = self::refresh_all(true);
            if (is_wp_error($result)) {
                $notice = '<div class="notice notice-error"><p>' . esc_html($result->get_error_message()) . '</p></div>';
            } else {
                $fetched = 0;
                $errors  = array();
                foreach ($result['types'] as $type_result) {
                    $fetched += (int) $type_result['fetched'];
                    if (!empty($type_result['error'])) {
                        $errors[] = $type_result['error'];
                    }
                }
                $message = '图片池更新完成，本次新下载 ' . $fetched . ' 张。';
                if (!empty($errors)) {
                    $message .= ' 注意：' . implode('；', array_unique($errors));
                    $notice = '<div class="notice notice-warning"><p>' . esc_html($message) . '</p></div>';
                } else {
                    $notice = '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
                }
            }
        }

        $snapshot = self::snapshot();
        $next_run = wp_next_scheduled(self::CRON_HOOK);
        $required = self::required_types();
        ?>
        <div class="wrap">
            <h1>随机图片池</h1>
            <?php echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 已在上面转义。 ?>

            <p>
                本插件把 Sakurairo 的三类外部随机图缓存到服务器本地：浏览器只访问本站的图片池接口，不再直接等待外部 API。
                后台主题设置中的 API 地址<strong>不会被修改</strong>；图片池从原始地址异步下载并定期轮换。
            </p>

            <?php if (empty($required)) : ?>
                <div class="notice notice-info"><p>当前主题设置没有启用外部随机图（封面或文章特色图）。启用后本页才会维护对应图片池。</p></div>
            <?php endif; ?>

            <form method="post" action="">
                <?php wp_nonce_field('iro_image_pool_admin'); ?>
                <input type="hidden" name="iro_pool_action" value="refresh" />
                <?php submit_button('立即更新图片池', 'primary', 'submit', false); ?>
                <span class="description">同步执行，首次填充可能需要 10–60 秒；已下载的图片会即时保存，即使超时也不会丢失。</span>
            </form>

            <table class="widefat striped" style="max-width:1200px;margin-top:18px;">
                <thead>
                    <tr>
                        <th>类型</th>
                        <th>状态</th>
                        <th>原始 API（已隐藏参数）</th>
                        <th>池内图片</th>
                        <th>占用空间</th>
                        <th>最近下载</th>
                        <th>最近错误</th>
                        <th>测试</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($snapshot['types'] as $type => $info) : ?>
                        <tr>
                            <td><strong><?php echo esc_html($info['label']); ?></strong></td>
                            <td>
                                <?php if ($info['active']) : ?>
                                    <span style="color:#1e7e34;">启用</span>
                                <?php else : ?>
                                    <span style="color:#888;">未使用</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($info['source'] !== '') : ?>
                                    <code><?php echo esc_html($info['source']); ?></code>
                                <?php else : ?>
                                    <span style="color:#b32d2e;">未配置</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo (int) $info['count']; ?> / <?php echo (int) $info['target']; ?>
                                <?php if (!$info['active']) : ?>
                                    <span class="description">（不参与更新）</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html(size_format($info['bytes'], 1)); ?></td>
                            <td>
                                <?php
                                echo $info['last_fetch'] > 0
                                    ? esc_html(date_i18n('Y-m-d H:i:s', $info['last_fetch']))
                                    : '—';
                                ?>
                            </td>
                            <td>
                                <?php echo $info['last_error'] !== '' ? esc_html($info['last_error']) : '—'; ?>
                            </td>
                            <td>
                                <a class="button button-small" target="_blank" rel="noopener" href="<?php echo esc_url(self::endpoint_url($type)); ?>">打开随机图</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p class="description" style="margin-top:12px;">
                最近一次后台更新时间：
                <?php echo $snapshot['last_run'] > 0 ? esc_html(date_i18n('Y-m-d H:i:s', $snapshot['last_run'])) : '尚未运行'; ?>；
                下一次计划任务：
                <?php echo $next_run ? esc_html(date_i18n('Y-m-d H:i:s', $next_run)) : '未安排'; ?>。
            </p>
            <p class="description">
                图片目录：<code>wp-content/uploads/<?php echo esc_html(IRO_IMAGE_POOL_DIRNAME); ?>/</code>。
                池内图片是公开静态文件，浏览器会长期缓存；接口本身返回 302，确保每次页面加载都重新随机。
            </p>
            <p class="description">
                命令行预热：<code>wp iro-image-pool refresh</code>；查看状态：<code>wp iro-image-pool status</code>。
            </p>
        </div>
        <?php
    }
}

/**
 * WP-CLI 命令。
 */
class IRO_Image_Pool_CLI
{
    /**
     * 查看图片池状态。
     *
     * @param array $args       位置参数。
     * @param array $assoc_args 关联参数。
     * @return void
     */
    public function status($args, $assoc_args)
    {
        $snapshot = IRO_Image_Pool::snapshot();
        foreach ($snapshot['types'] as $type => $info) {
            \WP_CLI::line(
                sprintf(
                    '%-8s count=%d/%d bytes=%s active=%s last_fetch=%s error=%s',
                    $type,
                    $info['count'],
                    $info['target'],
                    size_format($info['bytes'], 1),
                    $info['active'] ? 'yes' : 'no',
                    $info['last_fetch'] > 0 ? date_i18n('Y-m-d H:i:s', $info['last_fetch']) : '-',
                    $info['last_error'] !== '' ? $info['last_error'] : '-'
                )
            );
        }
    }

    /**
     * 立即更新图片池。
     *
     * @param array $args       位置参数。
     * @param array $assoc_args 关联参数。
     * @return void
     */
    public function refresh($args, $assoc_args)
    {
        $result = IRO_Image_Pool::refresh_all(true);
        if (is_wp_error($result)) {
            \WP_CLI::error($result->get_error_message());
            return;
        }

        foreach ($result['types'] as $type => $type_result) {
            \WP_CLI::line(
                sprintf(
                    '%s: fetched=%d count=%d%s',
                    $type,
                    $type_result['fetched'],
                    $type_result['count'],
                    $type_result['error'] !== '' ? ' error=' . $type_result['error'] : ''
                )
            );
        }
        \WP_CLI::success('图片池更新完成。');
    }

    /**
     * 压缩图片池里已有的图片（一次性维护）。
     *
     * @param array $args       位置参数。
     * @param array $assoc_args 关联参数。
     * @return void
     */
    public function optimize($args, $assoc_args)
    {
        $results = IRO_Image_Pool::optimize_all();

        foreach ($results as $type => $result) {
            \WP_CLI::line(
                sprintf(
                    '%-8s converted=%d skipped=%d errors=%d %s -> %s',
                    $type,
                    $result['files'],
                    $result['skipped'],
                    $result['errors'],
                    size_format($result['before'], 1),
                    size_format($result['after'], 1)
                )
            );
        }

        \WP_CLI::success('图片池压缩完成。');
    }
}

if (!defined('IRO_IMAGE_POOL_DISABLE') || !IRO_IMAGE_POOL_DISABLE) {
    IRO_Image_Pool::boot();
}
