<?php
/**
 * 验证评论 WebHook 不依赖 wp-cron：评论入库后，WebHook 应该在当前请求内立刻送达。
 *
 * 判定依据：
 * 1. 插入一条评论后，webhook 任务在几秒内变成 done（而不是等 cron 的分钟级调度）；
 * 2. 收件方确实收到了请求（用预置的 pre_http_request 拦截并记录时间差）。
 *
 * 用法（站点根目录）：php tests/psn/webhook-immediate.php
 */

$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
$_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? 'localhost';

require dirname( __DIR__, 2 ) . '/wp-load.php';

global $wpdb;

$queued_at   = null;
$delivered_at = null;

// 拦截真实 HTTP，记录「第几次请求、什么时间」。
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$delivered_at ) {
		$delivered_at = microtime( true );

		return array(
			'headers'  => array(),
			'body'     => '{"ok":true}',
			'response' => array(
				'code'    => 200,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

// 确保 WebHook 模块开启，并指向一个假地址（会被上面的过滤器拦下）。
$settings = PSN_Settings::get();
$settings['webhook_enabled'] = 1;
$settings['webhook_url']     = 'http://127.0.0.1:9/hook';
update_option( PSN_OPTION, $settings );

$wpdb->query( 'DELETE FROM ' . PSN_Install::jobs_table() );

$post_id = wp_insert_post(
	array(
		'post_title'   => '立即推送验证文章',
		'post_content' => '正文',
		'post_status'  => 'publish',
		'post_type'    => 'post',
		'post_author'  => 1,
	)
);

$start = microtime( true );

$comment_id = wp_insert_comment(
	array(
		'comment_post_ID'      => $post_id,
		'comment_content'      => '验证立即推送的评论',
		'comment_author'       => '立即推送测试',
		'comment_author_email' => 'immediate@example.com',
		'comment_approved'     => 1,
	)
);

$queued_at = microtime( true );

// 触发 shutdown 钩子（CLI 下脚本结束时才会跑，这里手动模拟一次请求收尾）。
do_action( 'shutdown' );

$job = $wpdb->get_row(
	$wpdb->prepare(
		'SELECT * FROM ' . PSN_Install::jobs_table() . ' WHERE type = %s AND ref = %d',
		PSN_Queue::TYPE_WEBHOOK,
		$comment_id
	)
);

echo 'comment_id      = ' . (int) $comment_id . "\n";
echo 'job_status      = ' . ( $job ? $job->status : '(无任务)' ) . "\n";
echo 'http_delivered  = ' . ( null === $delivered_at ? '否' : '是' ) . "\n";

if ( null !== $delivered_at ) {
	echo 'latency_ms      = ' . (int) round( ( $delivered_at - $queued_at ) * 1000 ) . "\n";
}

$ok = $job
	&& 'done' === $job->status
	&& null !== $delivered_at
	&& ( $delivered_at - $queued_at ) < 2.0;

echo $ok ? "PASS\n" : "FAIL\n";

// 清理
wp_delete_comment( $comment_id, true );
wp_delete_post( $post_id, true );
$wpdb->query( 'DELETE FROM ' . PSN_Install::jobs_table() );
update_option( PSN_OPTION, $settings );

exit( $ok ? 0 : 1 );
