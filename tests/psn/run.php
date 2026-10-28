<?php
/**
 * 订阅推送插件的集成测试。
 *
 * 引导 WordPress，然后用 pre_wp_mail / pre_http_request 拦截真实外发，
 * 端到端验证订阅、发布推送、评论 WebHook 三条链路。
 *
 * 用法（在站点根目录）：
 *
 *   php tests/psn/run.php
 *
 * 退出码 0 表示全部通过。测试产生的数据会自己清理。
 */

$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
$_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? 'localhost';

$root = dirname( __DIR__, 2 );
require $root . '/wp-load.php';

// 插件未启用时手动加载，保证测试独立于启用状态。
if ( ! defined( 'PSN_VERSION' ) ) {
	$plugin = $root . '/wp-content/plugins/post-subscribe-notifier/post-subscribe-notifier.php';
	echo "post-subscribe-notifier 未启用，手动加载插件文件。\n";

	// 插件的 plugins_loaded 钩子已经过去了，直接调用初始化。
	require $plugin;
	psn_init();
}

global $wpdb;

/* -------------------------------------------------------------------------
 * 测试脚手架
 * ---------------------------------------------------------------------- */

$failures = 0;
$total    = 0;

/**
 * 断言。
 *
 * @param string $name    用例名.
 * @param bool   $cond    条件.
 * @param string $detail  失败说明.
 * @return void
 */
function psn_check( $name, $cond, $detail = '' ) {
	global $failures, $total;

	$total++;

	if ( $cond ) {
		echo "ok    {$name}\n";

		return;
	}

	$failures++;
	echo "FAIL  {$name}\n";
	if ( '' !== $detail ) {
		echo "        {$detail}\n";
	}
}

/**
 * 相等断言。
 *
 * @param string $name     用例名.
 * @param mixed  $expected 期望.
 * @param mixed  $actual   实际.
 * @return void
 */
function psn_eq( $name, $expected, $actual ) {
	psn_check(
		$name,
		$expected === $actual,
		sprintf( 'expected %s, got %s', var_export( $expected, true ), var_export( $actual, true ) )
	);
}

/* -------------------------------------------------------------------------
 * 拦截外部 IO
 * ---------------------------------------------------------------------- */

$GLOBALS['psn_test_mails']    = array();
$GLOBALS['psn_test_http']     = array();
$GLOBALS['psn_test_http_reply'] = array(
	'code' => 200,
	'body' => '{"ok":true}',
);

add_filter(
	'pre_wp_mail',
	function ( $return, $atts ) {
		$GLOBALS['psn_test_mails'][] = $atts;

		return true;
	},
	10,
	2
);

add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		$GLOBALS['psn_test_http'][] = array(
			'url'     => $url,
			'args'    => $args,
			'payload' => json_decode( (string) ( $args['body'] ?? '' ), true ),
		);

		return array(
			'headers'  => array(),
			'body'     => $GLOBALS['psn_test_http_reply']['body'],
			'response' => array(
				'code'    => $GLOBALS['psn_test_http_reply']['code'],
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

/* -------------------------------------------------------------------------
 * 环境准备：开启两个模块，指向测试 WebHook
 * ---------------------------------------------------------------------- */

$original_settings = get_option( PSN_OPTION );

update_option(
	PSN_OPTION,
	array_merge(
		PSN_Settings::defaults(),
		array(
			'mail_enabled'    => 1,
			'webhook_enabled' => 1,
			'webhook_url'     => 'http://127.0.0.1:8899/hook',
			'webhook_secret'  => 'test-secret',
			'batch'           => 100,
			'interval'        => 0,
			'daily_cap'       => 0,
			'post_types'      => array( 'post' ),
			'subject_tpl'     => '[{site_name}] 新文章：{post_title}',
			'summary_tpl'     => '【新评论】{author} 评论了《{post_title}》：{comment_excerpt} {comment_url}',
		)
	)
);

/**
 * 清空捕获的邮件。
 *
 * @return void
 */
function psn_reset_mail() {
	$GLOBALS['psn_test_mails'] = array();
}

/**
 * 清空捕获的 HTTP 请求。
 *
 * @return void
 */
function psn_reset_http() {
	$GLOBALS['psn_test_http'] = array();
}

/**
 * 提高内存/时间上限，避免批量任务触发限制。
 */
wp_raise_memory_limit( 'admin' );

// 清掉历史运行留下的测试数据，保证计数类断言可重复。
$wpdb->query( 'DELETE FROM ' . PSN_Install::jobs_table() );
$wpdb->query( "DELETE FROM " . PSN_Install::subscribers_table() . " WHERE email LIKE 'psn-%@example.com' OR email LIKE 'dbg%@example.com'" );
delete_option( 'psn_daily_counter' );

echo "\n== 订阅流程 ==\n";

$test_email = 'psn-test-' . wp_generate_password( 6, false ) . '@example.com';

PSN_Signup::reset_rate_limits( $test_email );
psn_reset_mail();

// 1. 非法邮箱。
$status = PSN_Signup::process_subscription( array( 'email' => 'not-an-email' ) );
psn_eq( '非法邮箱被拒绝', 'invalid_email', $status );

// 2. 蜜罐。
$status = PSN_Signup::process_subscription(
	array(
		'email'   => 'honeypot-' . $test_email,
		'website' => 'http://spam.example',
	)
);
psn_eq( '蜜罐字段非空时假装成功且不落库', 'pending', $status );
psn_check( '蜜罐提交未写入订阅者', null === PSN_Subscribers::find_by_email( 'honeypot-' . $test_email ) );

// 3. 正常订阅。
$status = PSN_Signup::process_subscription( array( 'email' => $test_email, 'name' => '测试订阅者', 'source' => 'home_card' ) );
psn_eq( '新邮箱进入待确认', 'pending', $status );

// 确认邮件现在是异步的（AJAX 不等 SMTP），测试里手动跑一次投递。
psn_eq( '确认邮件已入队', 1, PSN_Queue::count( PSN_Queue::TYPE_CONFIRM ) );
PSN_Cron::dispatch_confirmations();
psn_eq( '发出 1 封验证邮件', 1, count( $GLOBALS['psn_test_mails'] ) );

$row = PSN_Subscribers::find_by_email( $test_email );
psn_check( '订阅者已入库', null !== $row );

if ( $row ) {
	psn_eq( '初始状态为 pending', 'pending', $row->status );
	psn_check( '库里不存明文确认 token', false === strpos( (string) $row->confirm_hash, 'psn_confirm' ) && 64 === strlen( (string) $row->confirm_hash ) );
}

// 验证邮件里能拿到 token。
$mail = $GLOBALS['psn_test_mails'][0] ?? array();
$body = (string) ( $mail['message'] ?? '' );
psn_check( '验证邮件包含确认链接', false !== strpos( $body, 'psn_confirm=' ), 'body: ' . mb_substr( $body, 0, 200 ) );

$token = '';
if ( preg_match( '#psn_confirm=([a-f0-9]+)#', $body, $m ) ) {
	$token = $m[1];
}
psn_check( '能从邮件里解析出 token', '' !== $token );

// 4. 同一邮箱立刻再提交会被频率拦。
psn_reset_mail();
$status = PSN_Signup::process_subscription( array( 'email' => $test_email ) );
psn_eq( '同一邮箱 1 分钟内重复提交被限流', 'rate_limited', $status );
psn_eq( '限流时不发信', 0, count( $GLOBALS['psn_test_mails'] ) );
PSN_Signup::reset_rate_limits( $test_email );

// 5. 确认。
if ( '' !== $token ) {
	psn_eq( '确认链接生效', 'confirmed', PSN_Signup::process_confirm( $token ) );

	$row = PSN_Subscribers::find_by_email( $test_email );
	psn_eq( '确认后状态为 active', 'active', $row->status );
	psn_check( '确认后清空 token', empty( $row->confirm_hash ) );

	// 同一 token 再点一次：库里已无 confirm_hash，按无效链接处理（幂等的安全边界）。
	psn_eq( '重复确认按已使用链接处理', 'unknown_token', PSN_Signup::process_confirm( $token ) );
}

psn_eq( '无效 token 被拒', 'unknown_token', PSN_Signup::process_confirm( 'deadbeefdeadbeef' ) );

// 6. 已 active 再订阅 → 提示已订阅，不再发信。
psn_reset_mail();
PSN_Signup::reset_rate_limits( $test_email );
$status = PSN_Signup::process_subscription( array( 'email' => $test_email ) );
psn_eq( '已确认邮箱再次提交提示已订阅', 'already_active', $status );
psn_eq( '已订阅时不重复发验证邮件', 0, count( $GLOBALS['psn_test_mails'] ) );
PSN_Signup::reset_rate_limits( $test_email );

// 7. token 过期。
$expired_email = 'psn-expired-' . wp_generate_password( 6, false ) . '@example.com';
PSN_Signup::reset_rate_limits( $expired_email );
psn_reset_mail();
PSN_Signup::process_subscription( array( 'email' => $expired_email ) );
PSN_Cron::dispatch_confirmations();
$expired_body = (string) ( $GLOBALS['psn_test_mails'][0]['message'] ?? '' );
$expired_token = preg_match( '#psn_confirm=([a-f0-9]+)#', $expired_body, $m ) ? $m[1] : '';

if ( '' !== $expired_token ) {
	// 把 expiry 调到过去。
	$wpdb->update(
		PSN_Install::subscribers_table(),
		array( 'token_expires' => PSN_Queue::now( -3600 ) ),
		array( 'email' => $expired_email ),
		array( '%s' ),
		array( '%s' )
	);

	psn_eq( '过期链接被拒', 'expired', PSN_Signup::process_confirm( $expired_token ) );
}
PSN_Signup::reset_rate_limits( $expired_email );

// 8. 退订。
$row = PSN_Subscribers::find_by_email( $test_email );
if ( $row ) {
	$unsub_token = PSN_Subscribers::unsub_token( $row );

	psn_eq( '错误 token 无法退订', 'unsub_invalid', PSN_Signup::process_unsubscribe( (int) $row->id, 'wrong-token' ) );
	psn_eq( '正确 token 退订成功', 'unsubscribed', PSN_Signup::process_unsubscribe( (int) $row->id, $unsub_token ) );
	psn_eq( '重复退订仍返回成功', 'unsubscribed', PSN_Signup::process_unsubscribe( (int) $row->id, $unsub_token ) );

	$row = PSN_Subscribers::find_by_email( $test_email );
	psn_eq( '退订后状态正确', 'unsubscribed', $row->status );
}

echo "\n== 分发链接（邮件）==\n";

// 重新订阅一个 active 用户，用于接收推送。
$active_email = 'psn-active-' . wp_generate_password( 6, false ) . '@example.com';
PSN_Signup::reset_rate_limits( $active_email );
psn_reset_mail();
PSN_Signup::process_subscription( array( 'email' => $active_email ) );
PSN_Cron::dispatch_confirmations();
$active_body  = (string) ( $GLOBALS['psn_test_mails'][0]['message'] ?? '' );
$active_token = preg_match( '#psn_confirm=([a-f0-9]+)#', $active_body, $m2 ) ? $m2[1] : '';
if ( '' !== $active_token ) {
	PSN_Signup::process_confirm( $active_token );
}

$active_row = PSN_Subscribers::find_by_email( $active_email );
psn_check( '准备了一个 active 订阅者', $active_row && 'active' === $active_row->status );

// 9. 草稿 → 发布。
$post_id = wp_insert_post(
	array(
		'post_title'   => 'PSN 测试文章 ' . wp_generate_password( 5, false ),
		'post_content' => '这是用于测试推送的文章正文。',
		'post_status'  => 'draft',
		'post_type'    => 'post',
		'post_author'  => 1,
	)
);

psn_eq( '草稿状态不产生任务', 0, PSN_Queue::count( PSN_Queue::TYPE_MAIL ) );

psn_reset_mail();
wp_publish_post( $post_id );

psn_check( '发布后产生了邮件任务', PSN_Queue::count( PSN_Queue::TYPE_MAIL ) > 0 );

// 10. 再保存一次已发布文章：默认不重复入队。
$before = PSN_Queue::count( PSN_Queue::TYPE_MAIL );
wp_update_post(
	array(
		'ID'           => $post_id,
		'post_content' => '更新后的正文。',
	)
);
psn_eq( 'publish→publish 不新增任务', $before, PSN_Queue::count( PSN_Queue::TYPE_MAIL ) );

// 11. 跑一轮分发。
delete_option( 'psn_daily_counter' );
PSN_Cron::dispatch_mail();

psn_check( '分发后发出了推送邮件', count( $GLOBALS['psn_test_mails'] ) > 0 );

$push_mail = null;
foreach ( $GLOBALS['psn_test_mails'] as $candidate ) {
	if ( stripos( (string) $candidate['subject'], '新文章' ) !== false ) {
		$push_mail = $candidate;
		break;
	}
}

psn_check( '找到新文章推送邮件', null !== $push_mail );

if ( $push_mail ) {
	psn_check( '推送邮件主题含文章标题', false !== strpos( (string) $push_mail['subject'], 'PSN 测试文章' ) );

	$headers = (array) $push_mail['headers'];
	$joined  = implode( "\n", array_map( 'strval', $headers ) );
	psn_check( '推送邮件带 List-Unsubscribe', false !== stripos( $joined, 'List-Unsubscribe:' ) );
	psn_check( '推送邮件带 One-Click 退订', false !== stripos( $joined, 'List-Unsubscribe-Post:' ) );
	psn_check( '推送邮件正文含退订链接', false !== strpos( (string) $push_mail['message'], 'psn_unsub=' ) );
}

psn_eq( '推送完成后没有残留邮件任务', 0, PSN_Queue::count( PSN_Queue::TYPE_MAIL ) );

// 此时库里只有一个 active 订阅者（$test_email 已退订），所以本轮应只发 1 封。
psn_eq( '今日发送计数等于本轮封数', count( $GLOBALS['psn_test_mails'] ), PSN_Queue::sent_today() );

// 12. 撤回文章会取消剩余任务。
$post2 = wp_insert_post(
	array(
		'post_title'   => 'PSN 撤销测试 ' . wp_generate_password( 5, false ),
		'post_content' => '撤销用。',
		'post_status'  => 'draft',
		'post_type'    => 'post',
		'post_author'  => 1,
	)
);
wp_publish_post( $post2 );
psn_check( '第二篇文章入队', PSN_Queue::count( PSN_Queue::TYPE_MAIL ) > 0 );

wp_update_post(
	array(
		'ID'          => $post2,
		'post_status' => 'draft',
	)
);
psn_eq( '撤回后任务被取消', 0, PSN_Queue::count( PSN_Queue::TYPE_MAIL ) );

echo "\n== 评论 WebHook ==\n";

$comment_post = wp_insert_post(
	array(
		'post_title'   => 'PSN 评论测试文章',
		'post_content' => '正文。',
		'post_status'  => 'publish',
		'post_type'    => 'post',
		'post_author'  => 1,
	)
);

/**
 * 造一条评论。
 *
 * @param array $args 覆盖字段.
 * @return int
 */
function psn_make_comment( $args = array() ) {
	return wp_insert_comment(
		array_merge(
			array(
				'comment_post_ID'      => 0,
				'comment_content'      => '这是一条测试评论。',
				'comment_author'       => '评论者',
				'comment_author_email' => 'commenter@example.com',
				'comment_approved'     => 1,
				'comment_type'         => 'comment',
				'comment_parent'       => 0,
			),
			$args
		)
	);
}

psn_reset_http();

// 13. 普通评论（已通过）。
$comment_id = psn_make_comment(
	array(
		'comment_post_ID' => $comment_post,
		'comment_content' => '第一条评论，含表情 :smile: 与 <strong>HTML</strong>。',
	)
);

psn_check( '已通过评论入队', PSN_Queue::count( PSN_Queue::TYPE_WEBHOOK ) > 0 );

PSN_Cron::dispatch_webhooks();

psn_eq( '推送到 1 个 WebHook', 1, count( $GLOBALS['psn_test_http'] ) );

$request = $GLOBALS['psn_test_http'][0] ?? array();
$payload = $request['payload'] ?? array();
$headers = array_change_key_case( (array) ( $request['args']['headers'] ?? array() ), CASE_LOWER );

psn_check( '请求到达配置的 URL', ( $request['url'] ?? '' ) === 'http://127.0.0.1:8899/hook' );
psn_eq( '事件为 comment.approved', 'comment.approved', $payload['event'] ?? '' );
psn_check( '载荷含评论内容', false !== strpos( (string) ( $payload['comment']['content'] ?? '' ), '第一条评论' ) );
psn_check( '纯文本去掉了 HTML 标签', false === strpos( (string) ( $payload['comment']['content_text'] ?? '' ), '<strong>' ) );
psn_check( '纯文本去掉了表情语法', false === strpos( (string) ( $payload['comment']['content_text'] ?? '' ), ':smile:' ) );
psn_check( '载荷含评论人 QQ 字段', array_key_exists( 'qq', (array) ( $payload['comment']['author'] ?? array() ) ) );
psn_check( '载荷含文章作者', ! empty( $payload['comment']['post']['author']['email'] ) );
psn_eq( 'targets 含文章作者', 'post_author', $payload['targets'][0] ?? '' );
psn_check( 'summary 是单行非空', '' !== ( $payload['summary'] ?? '' ) && false === strpos( (string) $payload['summary'], "\n" ) );
psn_check( '按规则生成了签名头', isset( $headers['x-psn-signature'] ) );

// 验证签名。
$raw_body = (string) ( $request['args']['body'] ?? '' );
$expected = 'sha256=' . hash_hmac( 'sha256', $raw_body, 'test-secret' );
psn_eq( '签名可被验证', $expected, $headers['x-psn-signature'] ?? '' );

psn_eq( '推送后任务标记完成', 0, PSN_Queue::count( PSN_Queue::TYPE_WEBHOOK ) );

// 14. 同一评论重复审核不重复推。
psn_reset_http();
wp_set_comment_status( $comment_id, 'approve' );
psn_eq( '同一评论再次通过审核不重复入队', 0, PSN_Queue::count( PSN_Queue::TYPE_WEBHOOK ) );

// 15. 待审核评论不推；通过后才推。
psn_reset_http();
$held_id = psn_make_comment(
	array(
		'comment_post_ID'      => $comment_post,
		'comment_content'      => '待审核评论。',
		'comment_approved'     => 0,
		'comment_author'       => '等待审核的人',
		'comment_author_email' => 'waiting@example.com',
	)
)

;
psn_eq( '待审核评论不入队', 0, PSN_Queue::count( PSN_Queue::TYPE_WEBHOOK ) );

wp_set_comment_status( $held_id, 'approve' );
psn_eq( '审核通过后入队 1 条', 1, PSN_Queue::count( PSN_Queue::TYPE_WEBHOOK ) );

PSN_Cron::dispatch_webhooks();
psn_eq( '审核通过后推送 1 次', 1, count( $GLOBALS['psn_test_http'] ) );

// 16. 段评。
psn_reset_http();
$para_id = psn_make_comment(
	array(
		'comment_post_ID'      => $comment_post,
		'comment_content'      => '这是段评内容，段落三。',
		'comment_type'         => 'wp_paragraph_comment',
		'comment_author'       => '段评用户',
		'comment_author_email' => 'para@example.com',
	)
);
add_comment_meta( $para_id, 'paragraph_index', 3, true );

psn_check( '段评入队', PSN_Queue::count( PSN_Queue::TYPE_WEBHOOK ) > 0 );

PSN_Cron::dispatch_webhooks();

$para_payload = $GLOBALS['psn_test_http'][0]['payload'] ?? array();
psn_check( '段评被标记 is_paragraph', true === ( $para_payload['comment']['is_paragraph'] ?? null ) );
psn_eq( '段评带段落序号', 3, $para_payload['comment']['paragraph_index'] ?? null );

// 17. 回复评论：带父评论信息。
psn_reset_http();
$reply_id = psn_make_comment(
	array(
		'comment_post_ID'      => $comment_post,
		'comment_content'      => '我回复楼上。',
		'comment_parent'       => $comment_id,
		'comment_author'       => '回复者',
		'comment_author_email' => 'replier@example.com',
	)
);

PSN_Cron::dispatch_webhooks();

$reply_payload = $GLOBALS['psn_test_http'][0]['payload'] ?? array();
psn_check( '回复评论带 parent 块', null !== ( $reply_payload['comment']['parent'] ?? null ) );
psn_eq( 'parent 指向被回复的评论', $comment_id, $reply_payload['comment']['parent']['id'] ?? 0 );
psn_check( 'targets 含父评论作者', in_array( 'parent_author', (array) ( $reply_payload['targets'] ?? array() ), true ) );

// 18. 说说下的评论。
$shuoshuo_id = wp_insert_post(
	array(
		'post_title'   => 'PSN 说说',
		'post_content' => '说说内容。',
		'post_status'  => 'publish',
		'post_type'    => 'shuoshuo',
		'post_author'  => 1,
	)
);

if ( $shuoshuo_id && ! is_wp_error( $shuoshuo_id ) ) {
	psn_reset_http();
	psn_make_comment(
		array(
			'comment_post_ID'      => $shuoshuo_id,
			'comment_content'      => '说说下的评论。',
			'comment_author_email' => 'shuoshuo@example.com',
		)
	);

	PSN_Cron::dispatch_webhooks();
	$shuoshuo_payload = $GLOBALS['psn_test_http'][0]['payload'] ?? array();
	psn_eq( '说说评论的 post.type 正确', 'shuoshuo', $shuoshuo_payload['comment']['post']['type'] ?? '' );
}

// 19. HTTP 失败的重试与不重试。
psn_reset_http();
$fail_id = psn_make_comment(
	array(
		'comment_post_ID'      => $comment_post,
		'comment_content'      => '用来测试失败重试的评论。',
		'comment_author_email' => 'retry@example.com',
	)
);

$GLOBALS['psn_test_http_reply'] = array(
	'code' => 500,
	'body' => 'boom',
);
PSN_Cron::dispatch_webhooks();

$job = $wpdb->get_row(
	$wpdb->prepare(
		'SELECT * FROM ' . PSN_Install::jobs_table() . ' WHERE type = %s AND ref = %d',
		PSN_Queue::TYPE_WEBHOOK,
		$fail_id
	)
);
psn_check( '500 后任务回到 pending', $job && 'pending' === $job->status );
psn_eq( '500 后 attempts 增加', 1, (int) ( $job->attempts ?? 0 ) );
psn_check( '500 后 available_at 被推后', $job && strtotime( $job->available_at ) > current_time( 'timestamp' ) );

$GLOBALS['psn_test_http_reply'] = array(
	'code' => 404,
	'body' => 'nope',
);

// 让重试立即可跑。
$wpdb->update(
	PSN_Install::jobs_table(),
	array( 'available_at' => PSN_Queue::now( -1 ) ),
	array( 'id' => (int) $job->id ),
	array( '%s' ),
	array( '%d' )
);

PSN_Cron::dispatch_webhooks();

$job = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . PSN_Install::jobs_table() . ' WHERE id = %d', (int) $job->id ) );
psn_eq( '404 直接失败不重试', 'failed', $job->status );

// 20. 手动重发。
psn_eq( '失败任务可重新入队', true, PSN_Queue::requeue( (int) $job->id ) );
$job = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . PSN_Install::jobs_table() . ' WHERE id = %d', (int) $job->id ) );
psn_eq( '重发后回到 pending', 'pending', $job->status );
psn_eq( '重发后 attempts 归零', 0, (int) $job->attempts );

$GLOBALS['psn_test_http_reply'] = array(
	'code' => 200,
	'body' => '{"ok":true}',
);
PSN_Cron::dispatch_webhooks();

// 21. 载荷契约（完整字段）。
psn_reset_http();
$contract_id = psn_make_comment(
	array(
		'comment_post_ID'      => $comment_post,
		'comment_content'      => '契约测试评论。',
		'comment_author'       => '契约用户',
		'comment_author_email' => 'contract@example.com',
	)
);
PSN_Cron::dispatch_webhooks();

$contract = $GLOBALS['psn_test_http'][0]['payload'] ?? array();

foreach ( array( 'event', 'delivery_id', 'sent_at', 'site', 'comment', 'targets', 'summary' ) as $key ) {
	psn_check( "顶层字段 {$key} 存在", array_key_exists( $key, $contract ) );
}
foreach ( array( 'id', 'type', 'url', 'content', 'content_text', 'content_excerpt', 'approved', 'is_private', 'is_paragraph', 'created_at', 'author', 'parent', 'post' ) as $key ) {
	psn_check( "comment.{$key} 存在", array_key_exists( $key, (array) ( $contract['comment'] ?? array() ) ) );
}
foreach ( array( 'id', 'name', 'email', 'url', 'qq', 'avatar', 'ip_location' ) as $key ) {
	psn_check( "comment.author.{$key} 存在", array_key_exists( $key, (array) ( $contract['comment']['author'] ?? array() ) ) );
}

// 22. 关闭「含说说」后不再推说说评论。
$settings = PSN_Settings::get();
$settings['include_shuoshuo'] = 0;
update_option( PSN_OPTION, $settings );

if ( $shuoshuo_id && ! is_wp_error( $shuoshuo_id ) ) {
	psn_reset_http();
	psn_make_comment(
		array(
			'comment_post_ID'      => $shuoshuo_id,
			'comment_content'      => '关闭后不该推的说说评论。',
			'comment_author_email' => 'shuoshuo2@example.com',
		)
	);
	psn_eq( '关闭后说说评论不入队', 0, PSN_Queue::count( PSN_Queue::TYPE_WEBHOOK ) );
}

// 还原设置。
$settings['include_shuoshuo'] = 1;
update_option( PSN_OPTION, $settings );

echo "\n== 清理 ==\n";

// 清理测试数据。
foreach ( array( $comment_id, $held_id, $para_id, $reply_id, $fail_id, $contract_id ) as $cid ) {
	wp_delete_comment( $cid, true );
}
foreach ( array( $post_id, $post2, $comment_post, $shuoshuo_id ) as $pid ) {
	if ( $pid && ! is_wp_error( $pid ) ) {
		wp_delete_post( $pid, true );
	}
}

$wpdb->query( 'DELETE FROM ' . PSN_Install::jobs_table() );
$wpdb->query(
	$wpdb->prepare(
		'DELETE FROM ' . PSN_Install::subscribers_table() . ' WHERE email IN (%s, %s)',
		$test_email,
		$expired_email
	)
);
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . PSN_Install::subscribers_table() . ' WHERE email = %s', $active_email ) );
delete_option( 'psn_daily_counter' );

// 还原插件设置。
if ( false === $original_settings ) {
	delete_option( PSN_OPTION );
} else {
	update_option( PSN_OPTION, $original_settings );
}

echo "\n{$total} case(s), {$failures} failure(s)\n";
exit( $failures ? 1 : 0 );
