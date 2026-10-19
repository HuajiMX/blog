<?php
declare(strict_types=1);

/**
 * 轻量随机图跳转端点。
 *
 * 这个文件不加载 WordPress，只读图片池的 index.json 并返回 302。
 * 原来走 WP REST API 时，每张图都要引导整个 WordPress（约 110ms，
 * 6 张并发时接近 0.7s）；这里通常 1–3ms 就能完成。
 *
 * 地址形如：
 *   /wp-content/mu-plugins/iro-image-pool/random.php?type=post
 *
 * @package IRO_Image_Pool
 */

$iro_types = array('desktop', 'mobile', 'post');

$iro_type = isset($_GET['type']) && is_string($_GET['type']) ? strtolower($_GET['type']) : 'desktop';
$iro_type = preg_replace('/[^a-z]/', '', $iro_type);
if (!in_array($iro_type, $iro_types, true)) {
    $iro_type = 'desktop';
}

// .../wp-content/mu-plugins/iro-image-pool -> .../wp-content
$iro_content_dir = dirname(__DIR__, 2);
$iro_pool_root   = $iro_content_dir . '/uploads/iro-image-pool';
$iro_pool_url    = '/wp-content/uploads/iro-image-pool';

/**
 * 从某一类图片池里随机取一个文件名。
 *
 * @param string $root 图片池根目录。
 * @param string $type 类型。
 * @return string 文件名；无可用图片返回空字符串。
 */
function iro_pool_pick_file(string $root, string $type): string
{
    $dir   = $root . '/' . $type;
    $index = $dir . '/index.json';

    if (!is_file($index)) {
        return '';
    }

    $raw = @file_get_contents($index);
    if (!is_string($raw) || $raw === '') {
        return '';
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['items']) || !is_array($data['items'])) {
        return '';
    }

    $valid = array();
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
        if (is_file($dir . '/' . $file)) {
            $valid[] = $file;
        }
    }

    if (empty($valid)) {
        return '';
    }

    return $valid[array_rand($valid)];
}

// 与插件里的 pick() 保持一致：目标池为空时按 desktop / mobile / post 顺序兜底。
$iro_order = array($iro_type);
foreach ($iro_types as $iro_candidate) {
    if ($iro_candidate !== $iro_type) {
        $iro_order[] = $iro_candidate;
    }
}

$iro_file = '';
$iro_hit  = $iro_type;
foreach ($iro_order as $iro_candidate) {
    $iro_file = iro_pool_pick_file($iro_pool_root, $iro_candidate);
    if ($iro_file !== '') {
        $iro_hit = $iro_candidate;
        break;
    }
}

// 随机图不能缓存，否则每个页面加载看到的都是同一张。
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Robots-Tag: noindex');
header('Access-Control-Allow-Origin: *');
header('X-Iro-Pool: fast');
header('X-Iro-Pool-Type: ' . $iro_hit);

if ($iro_file !== '') {
    header('Location: ' . $iro_pool_url . '/' . $iro_hit . '/' . rawurlencode($iro_file), true, 302);
    exit;
}

// 池子还没准备好：回退到原来的 REST 接口，保证仍然有图可看。
header('X-Iro-Pool: fallback');
header('Location: /index.php/wp-json/iro-image-pool/v1/random?iro_type=' . rawurlencode($iro_type), true, 302);
exit;
