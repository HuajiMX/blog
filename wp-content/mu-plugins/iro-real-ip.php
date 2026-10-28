<?php
/**
 * Plugin Name: IRO Real IP
 * Description: 站点在又拍云 CDN 之后，但源站 nginx 没有 ngx_http_realip_module 规则，导致 WordPress 记录的是 CDN 节点 IP（江苏南京），而不是访客真实 IP。本插件在请求早期解析可信转发头并回填 REMOTE_ADDR，同时接管评论写入的 IP，修复评论/注册/人机验证/友链提交全部拿到节点 IP 的问题。
 * Version: 1.0.0
 * Requires PHP: 7.4
 *
 * 说明：
 * - 放在 mu-plugins 目录，自动加载，不受主题/插件更新影响。
 * - 又拍云回源时必定用真实访客 IP 覆盖 X-Real-IP / Client-IP / X-Forwarded-For，
 *   并写入 X-Via: shanks/<version> 标记头；但 X-Forwarded-For 在部分回源模式下会被
 *   原样透传（客户端可伪造），因此取值顺序把 X-Real-IP / Client-IP 放在 XFF 之前。
 * - 源站可被 CDN 直连绕过（实测 47.117.246.218:443 直接可达），此时客户端能自带
 *   同名的转发头。所以只有"确认本次请求确实来自边缘节点"时才采信转发头：
 *     1) 配置了 IRO_REAL_IP_TRUSTED_CIDRS 时，直连地址必须落在这些网段内；
 *     2) 未配置时，要求回源标记头（默认 X-Via: shanks/…）存在，且
 *        X-Real-IP / Client-IP / X-Forwarded-For 三者一致（又拍云回源的真实特征）。
 *   要获得最强防护，请在 nginx 侧把源站限制为只接受 CDN 回源地址（见 AGENTS.md）。
 *
 * 可选常量（写在 wp-config.php 中，不必改本文件）：
 *   IRO_REAL_IP_DISABLE         设为 true 可整体停用（应急回滚）。
 *   IRO_REAL_IP_TRUSTED_CIDRS   可信回源地址段数组，如 array('36.150.185.16/28')。设置后不再看标记头。
 *   IRO_REAL_IP_MARKER          回源标记头的 $_SERVER 键名，默认 'HTTP_X_VIA'；设为 '' 可关闭标记判定。
 *   IRO_REAL_IP_MARKER_REGEX    标记头正则，默认 '#^shanks/#i'。
 *
 * @package IRO_Real_IP
 */

if (!defined('ABSPATH')) {
    exit;
}

// mu-plugins 下的单文件是被扁平加载的，__DIR__ 指向 mu-plugins 本身，
// 子目录需显式拼进去（与 iro-image-pool.php 的做法一致）。
require_once __DIR__ . '/iro-real-ip/resolver.php';

if (!defined('IRO_REAL_IP_EDGE')) {
    /**
     * 原始直连地址（CDN 节点 IP）。即使在改写 REMOTE_ADDR 之后也需要它来
     * 排查"请求是否真的经过 CDN"，所以单独留一份。
     */
    define('IRO_REAL_IP_EDGE', isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '');
}

/**
 * 读取配置并解析当前请求的真实访客 IP。
 *
 * @return array{ip:string, source:string, edge:string, trusted:bool}
 */
function iro_real_ip_resolve()
{
    $cidrs  = defined('IRO_REAL_IP_TRUSTED_CIDRS') ? (array) IRO_REAL_IP_TRUSTED_CIDRS : array();
    $marker = defined('IRO_REAL_IP_MARKER') ? (string) IRO_REAL_IP_MARKER : 'HTTP_X_VIA';
    $regex  = defined('IRO_REAL_IP_MARKER_REGEX') ? (string) IRO_REAL_IP_MARKER_REGEX : '#^shanks/#i';

    return iro_resolve_client_ip($_SERVER, array(
        'trusted_cidrs' => $cidrs,
        'marker'        => $marker,
        'marker_regex'  => $regex,
    ));
}

/**
 * 真实访客 IP（本插件的公共取值入口）。
 *
 * 主题的 get_the_user_ip()、以及需要 IP 的第三方插件都应走这里，
 * 保证全站只有一种判定逻辑。
 *
 * @return string
 */
function iro_get_client_ip()
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    if (defined('IRO_REAL_IP_DISABLE') && IRO_REAL_IP_DISABLE) {
        $cached = IRO_REAL_IP_EDGE;
        return $cached;
    }

    $resolved = iro_real_ip_resolve();
    $cached   = $resolved['ip'];
    return $cached;
}

/**
 * 请求早期把 REMOTE_ADDR 换成真实访客 IP。
 *
 * WordPress 核心（wp_new_comment / wp_filter_comment / 评论洪水检查）、
 * Akismet、wp-mail-smtp、notification-master 等都在各处直接读 $_SERVER['REMOTE_ADDR']，
 * 与其逐个打补丁，不如像 nginx 的 realip 模块一样在入口改一次。
 */
if (!defined('IRO_REAL_IP_DISABLE') || !IRO_REAL_IP_DISABLE) {
    $iro_real_ip = iro_real_ip_resolve();
    if ($iro_real_ip['trusted'] && $iro_real_ip['ip'] !== '' && $iro_real_ip['ip'] !== $iro_real_ip['edge']) {
        $_SERVER['REMOTE_ADDR'] = $iro_real_ip['ip'];
        // 保留直连地址，便于排查与审计（部分插件会读它做日志）。
        $_SERVER['IRO_EDGE_ADDR'] = $iro_real_ip['edge'];
    }
    unset($iro_real_ip);
}

/**
 * 兜底：评论写入时再确认一次 IP。
 *
 * 正常情况下上面已把 REMOTE_ADDR 改好，核心不会走到这里；
 * 但若某个插件在 preprocess_comment 里重新赋值了 comment_author_IP，
 * 这个过滤器排在最后，仍能把它纠正为真实访客 IP。
 *
 * @param string $ip 核心/插件给出的 IP。
 * @return string
 */
function iro_real_ip_filter_comment_ip($ip)
{
    if (defined('IRO_REAL_IP_DISABLE') && IRO_REAL_IP_DISABLE) {
        return $ip;
    }
    $real = iro_get_client_ip();
    return $real !== '' ? $real : $ip;
}
add_filter('pre_comment_user_ip', 'iro_real_ip_filter_comment_ip', PHP_INT_MAX);

/**
 * 主题里 get_the_user_ip() 的历史过滤器名，保持兼容。
 *
 * @param string $ip 解析结果。
 * @return string
 */
function iro_real_ip_filter_wpb_get_ip($ip)
{
    if (defined('IRO_REAL_IP_DISABLE') && IRO_REAL_IP_DISABLE) {
        return $ip;
    }
    $real = iro_get_client_ip();
    return $real !== '' ? $real : $ip;
}
add_filter('wpb_get_ip', 'iro_real_ip_filter_wpb_get_ip', PHP_INT_MAX);
