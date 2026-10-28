<?php
/**
 * 生成一个管理员登录 cookie，写入临时文件供截图脚本使用。
 *
 * 仅在本地调试时使用；不会修改任何账号数据。
 *
 * 用法（站点根目录）：php tests/psn/tools/mint-cookie.php [userId]
 */

$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
$_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? 'localhost';

$root = dirname( __DIR__, 3 );
require $root . '/wp-load.php';

$user_id = isset( $argv[1] ) ? (int) $argv[1] : 1;

$user = get_user_by( 'id', $user_id );
if ( ! $user || ! user_can( $user, 'manage_options' ) ) {
	fwrite( STDERR, "用户 {$user_id} 不存在或不是管理员。\n" );
	exit( 1 );
}

wp_set_current_user( $user_id );

$expiration = time() + 3600;
$manager    = WP_Session_Tokens::get_instance( $user_id );
$token      = $manager->create( $expiration );

$cookies = array(
	'wordpress_logged_in_' . COOKIEHASH => wp_generate_auth_cookie( $user_id, $expiration, 'logged_in', $token ),
	'wordpress_sec_' . COOKIEHASH       => wp_generate_auth_cookie( $user_id, $expiration, 'secure_auth', $token ),
	// 后台登录还会校验已登录 cookie 的 auth cookie；两个 name 都给出，浏览器侧按 host 决定发送。
	'wordpress_' . COOKIEHASH           => wp_generate_auth_cookie( $user_id, $expiration, 'auth', $token ),
);

$target = getenv( 'PSN_COOKIE_FILE' );
if ( ! $target ) {
	// 默认写到仓库内，保证 WSL 与 Windows 侧看到同一份文件。
	$target = dirname( __DIR__, 1 ) . '/_cookies.json';
}

file_put_contents( $target, wp_json_encode( $cookies, JSON_UNESCAPED_SLASHES ) );

echo "cookies written to {$target}, user={$user->user_login}\n";
