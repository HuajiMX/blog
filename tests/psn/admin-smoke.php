<?php
/**
 * 在 CLI 里模拟管理员请求，渲染后台页面并检查是否有 PHP 错误。
 *
 * 用法（站点根目录）：
 *   php tests/psn/admin-smoke.php
 *
 * 不联网、不发信；只把「页面能渲染、无 fatal/warning、关键元素存在」验证一遍。
 */

$_SERVER['HTTP_HOST']    = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI']  = '/wp-admin/';
$_SERVER['SERVER_NAME']  = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';

$root = dirname( __DIR__, 2 );
require $root . '/wp-load.php';

wp_set_current_user( 1 );

if ( ! current_user_can( 'manage_options' ) ) {
	fwrite( STDERR, "用户 1 不是管理员，无法测试后台。\n" );
	exit( 1 );
}

require_once ABSPATH . 'wp-admin/includes/admin.php';
if ( ! function_exists( 'add_menu_page' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

// CLI 下 is_admin() 为 false，插件的后台类不会自动加载；这里手动引入。
require_once PSN_DIR . 'admin/class-psn-admin.php';
require_once PSN_DIR . 'admin/class-psn-actions.php';
require_once PSN_DIR . 'admin/class-psn-subscribers-table.php';
require_once PSN_DIR . 'admin/class-psn-jobs-table.php';

$failures = 0;
$total    = 0;

/**
 * 断言。
 *
 * @param string $name 用例.
 * @param bool   $cond 条件.
 * @param string $why  说明.
 * @return void
 */
function psn_admin_check( $name, $cond, $why = '' ) {
	global $failures, $total;

	$total++;

	if ( $cond ) {
		echo "ok    {$name}\n";
		return;
	}

	$failures++;
	echo "FAIL  {$name}\n";
	if ( '' !== $why ) {
		echo "        {$why}\n";
	}
}

// 收集 PHP 错误（页面里混进 warning 就是 bug，线上 display_errors=On）。
$errors = array();
set_error_handler(
	function ( $no, $str, $file, $line ) use ( &$errors ) {
		$errors[] = sprintf( '%s in %s:%d', $str, basename( $file ), $line );
		return true;
	}
);

/**
 * 渲染一个后台页面。
 *
 * @param callable $render 渲染回调.
 * @return string
 */
function psn_render( $render ) {
	ob_start();
	try {
		call_user_func( $render );
	} catch ( Throwable $e ) {
		ob_end_clean();
		return '__THROW__ ' . $e->getMessage();
	}
	return (string) ob_get_clean();
}

$pages = array(
	'设置页'   => array( 'PSN_Admin', 'render_settings' ),
	'订阅者页' => array( 'PSN_Admin', 'render_subscribers' ),
	'日志页'   => array( 'PSN_Admin', 'render_jobs' ),
);

foreach ( $pages as $label => $callback ) {
	$html = psn_render( $callback );

	psn_admin_check( "{$label} 无抛错", 0 !== strpos( $html, '__THROW__' ), mb_substr( $html, 0, 300 ) );
	psn_admin_check( "{$label} 有内容", strlen( $html ) > 500, 'len=' . strlen( $html ) );
}

$settings_html = psn_render( array( 'PSN_Admin', 'render_settings' ) );
foreach ( array( 'psn_settings[mail_enabled]', 'psn_settings[webhook_url]', 'psn_settings[summary_tpl]', 'psn_settings[batch]', 'psn_settings[daily_cap]' ) as $field ) {
	psn_admin_check( '设置页含字段 ' . $field, false !== strpos( $settings_html, $field ) );
}
psn_admin_check( '设置页含测试邮件按钮', false !== strpos( $settings_html, PSN_Diagnostics::ACTION_MAIL ) );
psn_admin_check( '设置页含测试 WebHook 按钮', false !== strpos( $settings_html, PSN_Diagnostics::ACTION_WEBHOOK ) );

$subs_html = psn_render( array( 'PSN_Admin', 'render_subscribers' ) );
// 列表为空时 WP 的 search_box 会主动不输出，所以这里只断言筛选视图与导出入口。
foreach ( array( 'psn-subscribers', 'subsubsub', 'action=psn_export' ) as $needle ) {
	psn_admin_check( '订阅者页含 ' . $needle, false !== strpos( $subs_html, $needle ), 'needle=' . $needle );
}

// 插入一行，验证列表与行内删除链接真的会渲染。
$seed_email = 'psn-admin-smoke-' . wp_generate_password( 6, false ) . '@example.com';
$seed       = PSN_Subscribers::create(
	array(
		'email'  => $seed_email,
		'source' => 'test',
	)
);
PSN_Subscribers::confirm( (string) $seed['token'] );

$list_html = psn_render( array( 'PSN_Admin', 'render_subscribers' ) );
// 邮箱入库时统一小写，断言也按小写比较。
psn_admin_check( '列表渲染出订阅者行', false !== strpos( $list_html, strtolower( $seed_email ) ), 'seed=' . $seed_email );
psn_admin_check( '行内删除链接带 nonce', false !== strpos( $list_html, 'action=delete' ) && false !== strpos( $list_html, '_wpnonce' ) );
psn_admin_check( '行内删除走 admin.php?page=psn-subscribers', false !== strpos( $list_html, 'page=psn-subscribers' ) && false !== strpos( $list_html, 'action=delete' ) );
psn_admin_check( '批量删除下拉存在', false !== strpos( $list_html, 'value="delete"' ) );
psn_admin_check( '状态徽章按状态着色', false !== strpos( $list_html, 'psn-badge-active' ) );
psn_admin_check( 'CSV 导出链接带 action', false !== strpos( $list_html, 'action=psn_export' ) );

// 清理种子数据。
PSN_Subscribers::delete( array( (int) $seed['id'] ) );

// 日志表也要有行才能验证「重发」链接；先种一条 webhook 任务。
$seed_comment = wp_insert_comment(
	array(
		'comment_post_ID'      => 0,
		'comment_content'      => '后台冒烟测试用的评论。',
		'comment_author'       => '冒烟',
		'comment_author_email' => 'smoke@example.com',
		'comment_approved'     => 1,
	)
);
PSN_Queue::enqueue_webhook( (int) $seed_comment, 'comment.approved' );

$jobs_html = psn_render( array( 'PSN_Admin', 'render_jobs' ) );
foreach ( array( 'psn-jobs', '重发', 'psn_resend' ) as $needle ) {
	psn_admin_check( '日志页含 ' . $needle, false !== strpos( $jobs_html, $needle ) );
}

// 清理种子任务与评论。
global $wpdb;
$wpdb->delete( PSN_Install::jobs_table(), array( 'ref' => (int) $seed_comment ), array( '%d' ) );
wp_delete_comment( (int) $seed_comment, true );

restore_error_handler();

psn_admin_check( '后台渲染无 PHP 警告', empty( $errors ), implode( ' | ', array_slice( $errors, 0, 8 ) ) );

// 设置清洗：非法值被拒绝，合法值被保留。
$old = get_option( PSN_OPTION );

$clean = PSN_Settings::sanitize(
	array_merge(
		PSN_Settings::defaults(),
		array(
			'webhook_url'   => 'javascript:alert(1)',
			'from_email'    => 'not-an-email',
			'batch'         => 999,
			'daily_cap'     => -5,
			'interval'      => 99999,
			'webhook_retries' => 99,
			'post_types'    => array( 'post', 'not-exist' ),
		)
	)
);

psn_admin_check( '非法 URL 被清空', '' === $clean['webhook_url'], 'got ' . $clean['webhook_url'] );
psn_admin_check( '非法发件邮箱被拒（保留旧值）', 'not-an-email' !== $clean['from_email'] );
psn_admin_check( 'batch 被钳到 100', 100 === (int) $clean['batch'], 'got ' . $clean['batch'] );
psn_admin_check( 'daily_cap 被钳到 0', 0 === (int) $clean['daily_cap'], 'got ' . $clean['daily_cap'] );
psn_admin_check( 'interval 被钳到 2000', 2000 === (int) $clean['interval'], 'got ' . $clean['interval'] );
psn_admin_check( 'webhook_retries 被钳到 5', 5 === (int) $clean['webhook_retries'], 'got ' . $clean['webhook_retries'] );
psn_admin_check( '未知内容类型被丢弃', ! in_array( 'not-exist', $clean['post_types'], true ) && in_array( 'post', $clean['post_types'], true ) );

$no_url = PSN_Settings::sanitize(
	array_merge(
		PSN_Settings::defaults(),
		array(
			'webhook_enabled' => 1,
			'webhook_url'     => '',
		)
	)
);
psn_admin_check( '空 URL 时自动关闭 WebHook', 0 === (int) $no_url['webhook_enabled'] );

$bad_headers = PSN_Settings::sanitize(
	array_merge(
		PSN_Settings::defaults(),
		array( 'webhook_headers' => 'not-json' )
	)
);
psn_admin_check( '非法 JSON 请求头保留原值', is_string( $bad_headers['webhook_headers'] ) );
psn_admin_check( '非法 JSON 不写入空值', 'not-json' !== $bad_headers['webhook_headers'] );

// CSV 公式注入防护。
psn_admin_check( 'CSV 公式前缀被转义', "'=cmd" === PSN_Actions::csv_cell( '=cmd' ) );
psn_admin_check( 'CSV 普通值不变', 'user@example.com' === PSN_Actions::csv_cell( 'user@example.com' ) );

// 颜色对比度工具。
psn_admin_check( '主题浅蓝配深色文字达到 4.5:1', PSN_Color::contrast( ( PSN_Color::button_palette( '#a4cdf6' ) )['on_accent'], ( PSN_Color::button_palette( '#a4cdf6' ) )['accent'] ) >= 4.5 );
psn_admin_check( '深色主题配白字达到 4.5:1', PSN_Color::contrast( ( PSN_Color::button_palette( '#2b2b2b' ) )['on_accent'], ( PSN_Color::button_palette( '#2b2b2b' ) )['accent'] ) >= 4.5 );
psn_admin_check( '中灰也能收敛到 4.5:1', PSN_Color::contrast( ( PSN_Color::button_palette( '#808080' ) )['on_accent'], ( PSN_Color::button_palette( '#808080' ) )['accent'] ) >= 4.5 );

// 恢复。
if ( false === $old ) {
	delete_option( PSN_OPTION );
} else {
	update_option( PSN_OPTION, $old );
}

echo "\n{$total} case(s), {$failures} failure(s)\n";
exit( $failures ? 1 : 0 );
