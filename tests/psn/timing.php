<?php
/**
 * 验证「AJAX 提交不等 SMTP」：处理器应在几十毫秒内返回，并把确认邮件入队。
 *
 * wp_send_json_success() 内部会 wp_die() 直接结束进程，所以这里用输出缓冲 +
 * shutdown 钩子取结果：既能量到真实耗时，也能看到入队情况。
 *
 * 用法（站点根目录）：php tests/psn/timing.php
 */

define( 'WP_USE_THEMES', false );

$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SERVER_NAME'] = 'localhost';

require dirname( __DIR__, 2 ) . '/wp-load.php';

global $wpdb;

$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_psn_rate%' OR option_name LIKE '_transient_timeout_psn_rate%'" );
$wpdb->query( 'DELETE FROM ' . PSN_Install::jobs_table() );

// 拦住真实发信，避免本地 SMTP 超时干扰计时。
add_filter( 'pre_wp_mail', '__return_true' );

$_POST    = array(
	'action'     => 'psn_subscribe',
	'psn_email'  => 'timing-' . time() . '@example.com',
	'psn_source' => 'home_card',
);
$_REQUEST = $_POST;

$start = microtime( true );

// wp_die 会结束进程，因此结果在 shutdown 里输出。
add_action(
	'shutdown',
	static function () use ( $start ) {
		$elapsed = microtime( true ) - $start;

		// 确认任务在 shutdown 里已被处理（或仍待发），两者都算入队成功；
		// 关键是总数 > 0 且 AJAX 本身不等 SMTP。
		$total = PSN_Queue::count( PSN_Queue::TYPE_CONFIRM, PSN_Queue::STATUS_PENDING )
			+ PSN_Queue::count( PSN_Queue::TYPE_CONFIRM, PSN_Queue::STATUS_DONE )
			+ PSN_Queue::count( PSN_Queue::TYPE_CONFIRM, PSN_Queue::STATUS_FAILED );

		$ok = $elapsed < 2.0 && $total >= 1;

		echo "\nelapsed_ms=" . (int) round( $elapsed * 1000 ) . "\n";
		echo 'confirm_jobs=' . (int) $total . "\n";
		echo $ok ? "PASS\n" : "FAIL\n";
	},
	999
);

ob_start();
PSN_Signup::ajax_subscribe();
