<?php
/**
 * 真实访客 IP 解析（纯函数，无 WordPress 依赖）。
 *
 * 单独成文件是为了能被 tests/run-ip.php 直接加载，不需要启动 WordPress。
 *
 * @package IRO_Real_IP
 */

if (!defined('ABSPATH') && !defined('IRO_REAL_IP_STANDALONE')) {
    define('IRO_REAL_IP_STANDALONE', true);
}

/**
 * 去掉端口、方括号，返回裸地址。
 *
 * X-Real-IP 一类的头偶尔会带端口（1.2.3.4:5678）或 IPv6 方括号
 * （[2001:db8::1]:443），统一剥成可校验的形式。
 *
 * @param string $value 原始取值。
 * @return string
 */
function iro_ip_strip_port($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    // [IPv6]:port 或 [IPv6]
    if (preg_match('/^\[([0-9a-fA-F:.]+)\](?::\d+)?$/', $value, $m)) {
        return $m[1];
    }
    // IPv4:port（只在恰好一个冒号时视为端口，避免误伤 IPv6）
    if (substr_count($value, ':') === 1 && strpos($value, '.') !== false) {
        $parts = explode(':', $value);
        if (ctype_digit($parts[1])) {
            return $parts[0];
        }
    }
    return $value;
}

/**
 * 判断是否为可公开路由的 IP（排除私有、保留、环回地址）。
 *
 * @param string $ip 待检查的 IP。
 * @return bool
 */
function iro_ip_is_public($ip)
{
    if (!is_string($ip) || $ip === '') {
        return false;
    }
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }
    return (bool) filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    );
}

/**
 * 解析逗号分隔的转发链，返回第一个合法且可公开路由的地址。
 *
 * @param string $value   头部的原始值。
 * @param bool   $reverse true 时从链尾（最接近源站的一跳）开始找。
 * @return string 找不到时返回空串。
 */
function iro_ip_from_chain($value, $reverse = false)
{
    if (!is_string($value) || trim($value) === '') {
        return '';
    }
    $chain = array_map('iro_ip_strip_port', explode(',', $value));
    if ($reverse) {
        $chain = array_reverse($chain);
    }
    foreach ($chain as $candidate) {
        if (iro_ip_is_public($candidate)) {
            return $candidate;
        }
    }
    return '';
}

/**
 * 判断 IP 是否落在 CIDR 列表内（支持 IPv4 / IPv6）。
 *
 * @param string   $ip    待检查的 IP。
 * @param string[] $cidrs CIDR 列表，例如 ['36.150.185.0/24']。
 * @return bool
 */
function iro_ip_in_cidrs($ip, array $cidrs)
{
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }
    foreach ($cidrs as $cidr) {
        $cidr = trim((string) $cidr);
        if ($cidr === '' || strpos($cidr, '/') === false) {
            continue;
        }
        list($subnet, $bits) = explode('/', $cidr, 2);
        $bits       = (int) $bits;
        $subnet_bin = @inet_pton($subnet);
        $ip_bin     = @inet_pton($ip);
        if ($subnet_bin === false || $ip_bin === false || strlen($subnet_bin) !== strlen($ip_bin)) {
            continue;
        }
        $max_bits = strlen($subnet_bin) * 8;
        if ($bits < 0 || $bits > $max_bits) {
            continue;
        }
        $bytes     = intdiv($bits, 8);
        $remainder = $bits % 8;
        if ($bytes > 0 && substr($ip_bin, 0, $bytes) !== substr($subnet_bin, 0, $bytes)) {
            continue;
        }
        if ($remainder === 0) {
            return true;
        }
        $mask = chr((0xFF << (8 - $remainder)) & 0xFF);
        if ((substr($ip_bin, $bytes, 1) & $mask) === (substr($subnet_bin, $bytes, 1) & $mask)) {
            return true;
        }
    }
    return false;
}

/**
 * 判断本次请求是否确实经由可信 CDN/反向代理回源。
 *
 * 源站可被绕过 CDN 直连（实测 47.117.246.218:443 直接可达），此时客户端能自带
 * 同名的转发头。因此转发头只有在"确认来自边缘节点"时才可信，两种判定满足其一：
 *
 * 1. 配置了 CIDR 列表时，直连地址（REMOTE_ADDR）必须落在列表内；
 * 2. 未配置时，要求同时满足又拍云回源的两个特征：
 *    a) 标记头存在且匹配（默认 X-Via: shanks/…，CDN 会覆盖客户端同名头）；
 *    b) X-Real-IP 与 Client-IP 同时存在且相等（CDN 用它俩写同一个值，
 *       而 X-Forwarded-For 在部分回源模式下原样透传，单独不可信）。
 *
 * @param array    $server  $_SERVER 数组。
 * @param string[] $cidrs   可信回源地址段。
 * @param string   $marker  标记头的 $_SERVER 键名。
 * @param string   $pattern 标记头的正则（含分隔符）。
 * @return bool
 */
function iro_request_from_trusted_edge(array $server, array $cidrs = array(), $marker = 'HTTP_X_VIA', $pattern = '#^shanks/#i')
{
    $remote = isset($server['REMOTE_ADDR']) ? (string) $server['REMOTE_ADDR'] : '';

    if (!empty($cidrs)) {
        return $remote !== '' && iro_ip_in_cidrs($remote, $cidrs);
    }

    if ($marker === '' || $pattern === '' || empty($server[$marker])) {
        return false;
    }
    if (!preg_match($pattern, (string) $server[$marker])) {
        return false;
    }

    $real   = iro_ip_strip_port(isset($server['HTTP_X_REAL_IP']) ? $server['HTTP_X_REAL_IP'] : '');
    $client = iro_ip_strip_port(isset($server['HTTP_CLIENT_IP']) ? $server['HTTP_CLIENT_IP'] : '');
    if ($real === '' || $client === '') {
        return false;
    }
    return $real === $client && iro_ip_is_public($real);
}

/**
 * 解析真实访客 IP。
 *
 * 取值优先级（仅在确认请求来自可信边缘节点后才会看转发头）：
 *   1. X-Real-IP —— 又拍云必定用它覆盖客户端同名头，是本栈最可靠的一跳；
 *   2. Client-IP —— 又拍云为兼容老程序额外提供的等价头；
 *   3. X-Forwarded-For —— 又拍云可能原样透传、可被客户端伪造，仅作兜底；
 *   4. REMOTE_ADDR —— 不在 CDN 后时就是真实地址。
 *
 * @param array $server $_SERVER 数组。
 * @param array $opts   {
 *     @type string[] $trusted_cidrs 可信回源地址段。
 *     @type string   $marker        回源标记头键名。
 *     @type string   $marker_regex  回源标记头正则。
 * }
 * @return array{ip:string, source:string, edge:string, trusted:bool}
 */
function iro_resolve_client_ip(array $server, array $opts = array())
{
    $cidrs  = isset($opts['trusted_cidrs']) && is_array($opts['trusted_cidrs']) ? $opts['trusted_cidrs'] : array();
    $marker = isset($opts['marker']) ? $opts['marker'] : 'HTTP_X_VIA';
    $regex  = isset($opts['marker_regex']) ? $opts['marker_regex'] : '#^shanks/#i';

    $remote  = isset($server['REMOTE_ADDR']) ? (string) $server['REMOTE_ADDR'] : '';
    $trusted = iro_request_from_trusted_edge($server, $cidrs, $marker, $regex);

    if ($trusted) {
        foreach (array('HTTP_X_REAL_IP', 'HTTP_CLIENT_IP') as $key) {
            if (!empty($server[$key])) {
                $candidate = iro_ip_strip_port($server[$key]);
                if (iro_ip_is_public($candidate)) {
                    return array(
                        'ip'      => $candidate,
                        'source'  => $key,
                        'edge'    => $remote,
                        'trusted' => true,
                    );
                }
            }
        }
        if (!empty($server['HTTP_X_FORWARDED_FOR'])) {
            $candidate = iro_ip_from_chain($server['HTTP_X_FORWARDED_FOR'], true);
            if ($candidate !== '' && iro_ip_is_public($candidate)) {
                return array(
                    'ip'      => $candidate,
                    'source'  => 'HTTP_X_FORWARDED_FOR',
                    'edge'    => $remote,
                    'trusted' => true,
                );
            }
        }
    }

    return array(
        'ip'      => $remote,
        'source'  => 'REMOTE_ADDR',
        'edge'    => $remote,
        'trusted' => false,
    );
}
