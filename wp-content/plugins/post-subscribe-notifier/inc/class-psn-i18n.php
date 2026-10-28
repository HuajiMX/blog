<?php
/**
 * 极简双语支持：界面按当前语言返回中文或英文文案。
 *
 * 邮件正文模板存在设置里（用户可改），因此不在此处翻译。
 *
 * @package Post_Subscribe_Notifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 全部界面文案。
 *
 * @return array
 */
function psn_strings() {
	static $strings = null;

	if ( null !== $strings ) {
		return $strings;
	}

	$strings = array(
		// 通用.
		'menu_title'                => array(
			'zh' => '订阅推送',
			'en' => 'Subscribe & Notify',
		),
		'page_settings'             => array(
			'zh' => '设置',
			'en' => 'Settings',
		),
		'page_subscribers'          => array(
			'zh' => '订阅者',
			'en' => 'Subscribers',
		),
		'page_jobs'                 => array(
			'zh' => '推送日志',
			'en' => 'Delivery log',
		),
		'settings_link'             => array(
			'zh' => '设置',
			'en' => 'Settings',
		),
		'saved'                     => array(
			'zh' => '设置已保存。',
			'en' => 'Settings saved.',
		),

		// 前台卡片.
		'card_title'                => array(
			'zh' => '订阅新文章',
			'en' => 'Subscribe to new posts',
		),
		'card_desc'                 => array(
			'zh' => '留下邮箱，以后每发布一篇新文章都会推送到你的邮箱。首次订阅需要点击验证邮件里的确认链接。',
			'en' => 'Leave your email and every new post will be delivered to your inbox. Please confirm via the link in the verification email.',
		),
		'field_email'               => array(
			'zh' => '邮箱地址',
			'en' => 'Email address',
		),
		'field_name'                => array(
			'zh' => '昵称（可选）',
			'en' => 'Name (optional)',
		),
		'btn_subscribe'             => array(
			'zh' => '订阅',
			'en' => 'Subscribe',
		),
		'status_pending'            => array(
			'zh' => '验证邮件已发送，请到邮箱点击确认链接完成订阅。',
			'en' => 'Verification email sent. Open it and click the confirmation link.',
		),
		'status_confirmed'          => array(
			'zh' => '订阅成功，新文章发布时会通知你。',
			'en' => 'Subscribed. You will be notified when a new post goes live.',
		),
		'status_invalid_email'      => array(
			'zh' => '请输入有效的邮箱地址。',
			'en' => 'Please enter a valid email address.',
		),
		'status_already_active'     => array(
			'zh' => '这个邮箱已经在订阅列表里了，无需重复订阅。',
			'en' => 'This email is already subscribed.',
		),
		'status_rate_limited'       => array(
			'zh' => '提交太频繁，请稍后再试。',
			'en' => 'Too many attempts. Please try again later.',
		),
		'status_mail_failed'        => array(
			'zh' => '验证邮件发送失败，请稍后再试。',
			'en' => 'The verification email could not be sent. Please try again later.',
		),
		'status_error'              => array(
			'zh' => '提交失败，请稍后再试。',
			'en' => 'Something went wrong. Please try again later.',
		),
		'status_expired'            => array(
			'zh' => '确认链接已过期，请重新订阅。',
			'en' => 'This confirmation link has expired. Please subscribe again.',
		),
		'status_unknown_token'      => array(
			'zh' => '链接无效或已被使用。',
			'en' => 'This link is invalid or has already been used.',
		),
		'status_unsubscribed'       => array(
			'zh' => '已退订，以后不会再收到新文章推送。',
			'en' => 'Unsubscribed. You will no longer receive new post emails.',
		),
		'status_unsub_invalid'      => array(
			'zh' => '退订链接无效。',
			'en' => 'Invalid unsubscribe link.',
		),
		'status_unsub_confirm'      => array(
			'zh' => '确定要退订新文章推送吗？',
			'en' => 'Unsubscribe from new post emails?',
		),
		'btn_unsubscribe_confirm'   => array(
			'zh' => '确认退订',
			'en' => 'Unsubscribe',
		),
		'status_disabled'           => array(
			'zh' => '订阅功能暂未开放。',
			'en' => 'Subscriptions are currently disabled.',
		),

		// 后台：通用设置.
		'section_general'           => array(
			'zh' => '模块开关',
			'en' => 'Modules',
		),
		'opt_mail_enabled'          => array(
			'zh' => '启用新文章邮件订阅',
			'en' => 'Enable new post email subscriptions',
		),
		'opt_mail_enabled_desc'     => array(
			'zh' => '关闭后前台订阅表单与推送邮件全部停止，已收集的订阅者保留。',
			'en' => 'When off, the signup form and post emails stop. Existing subscribers are kept.',
		),
		'opt_webhook_enabled'       => array(
			'zh' => '启用评论 WebHook 推送',
			'en' => 'Enable comment webhook delivery',
		),
		'opt_webhook_enabled_desc'  => array(
			'zh' => '审核通过的评论会以 JSON POST 推送到下面的地址。',
			'en' => 'Approved comments are POSTed as JSON to the URL below.',
		),
		'opt_delete_data'           => array(
			'zh' => '卸载插件时删除全部数据',
			'en' => 'Delete all data when the plugin is uninstalled',
		),
		'opt_delete_data_desc'      => array(
			'zh' => '默认保留订阅者与日志表，方便重新启用。',
			'en' => 'By default the tables are kept so the plugin can be re-enabled safely.',
		),

		// 后台：邮件.
		'section_mail'              => array(
			'zh' => '邮件推送',
			'en' => 'Post emails',
		),
		'field_post_types'          => array(
			'zh' => '触发推送的内容类型',
			'en' => 'Content types that trigger an email',
		),
		'field_post_types_desc'     => array(
			'zh' => '默认只推送「文章」。勾选「说说」后，说说发布也会推送。',
			'en' => 'Posts only by default. Tick Shuoshuo to also deliver short notes.',
		),
		'field_from_name'           => array(
			'zh' => '发件人名称',
			'en' => 'Sender name',
		),
		'field_from_email'          => array(
			'zh' => '发件邮箱',
			'en' => 'Sender email',
		),
		'field_from_email_desc'     => array(
			'zh' => '留空则使用 WordPress 默认发件地址。若 WP Mail SMTP 勾选了「强制发件人」，以那边为准。',
			'en' => 'Leave empty for the WordPress default. WP Mail SMTP forced-from settings take precedence.',
		),
		'field_reply_to'            => array(
			'zh' => '回复地址（Reply-To）',
			'en' => 'Reply-To',
		),
		'field_subject'             => array(
			'zh' => '推送邮件主题模板',
			'en' => 'Notification subject template',
		),
		'field_intro'               => array(
			'zh' => '推送邮件开场白',
			'en' => 'Notification intro text',
		),
		'field_include_excerpt'     => array(
			'zh' => '包含文章摘要',
			'en' => 'Include the excerpt',
		),
		'field_include_cover'       => array(
			'zh' => '包含封面图',
			'en' => 'Include the cover image',
		),
		'field_batch'               => array(
			'zh' => '每轮发送封数',
			'en' => 'Emails per run',
		),
		'field_batch_desc'          => array(
			'zh' => '每次 cron 执行最多发出的封数（1–100）。',
			'en' => 'Maximum emails per cron run (1–100).',
		),
		'field_interval'            => array(
			'zh' => '单封间隔（毫秒）',
			'en' => 'Delay between emails (ms)',
		),
		'field_interval_desc'       => array(
			'zh' => '防止触发 SMTP 服务商的频率限制（0–2000）。',
			'en' => 'Keeps you under SMTP provider rate limits (0–2000).',
		),
		'field_daily_cap'           => array(
			'zh' => '每日发信上限',
			'en' => 'Daily email cap',
		),
		'field_daily_cap_desc'      => array(
			'zh' => '0 表示不限制。达到上限后剩余任务留到下一天继续。',
			'en' => '0 means unlimited. Remaining jobs continue the next day.',
		),
		'field_republish'           => array(
			'zh' => '已发布文章再次保存时也推送',
			'en' => 'Also notify when an already published post is saved again',
		),
		'field_confirm_ttl'         => array(
			'zh' => '确认链接有效期（小时）',
			'en' => 'Confirmation link lifetime (hours)',
		),
		'field_confirm_ttl_desc'    => array(
			'zh' => '过期后订阅者需要重新提交邮箱（1–720）。',
			'en' => 'After expiry the subscriber has to submit the form again (1–720).',
		),
		'placeholder_subject'       => array(
			'zh' => '可用的占位符：{site_name} {post_title} {post_excerpt}',
			'en' => 'Available placeholders: {site_name} {post_title} {post_excerpt}',
		),

		// 后台：WebHook.
		'section_webhook'           => array(
			'zh' => 'WebHook 推送',
			'en' => 'WebHook delivery',
		),
		'field_webhook_url'         => array(
			'zh' => 'WebHook 地址',
			'en' => 'WebHook URL',
		),
		'field_webhook_url_desc'    => array(
			'zh' => '只接受 http/https 地址。QQ 机器人端收到的是一段 JSON，详见插件 readme。',
			'en' => 'http/https only. The bot receives a JSON body, see the plugin readme.',
		),
		'field_webhook_secret'      => array(
			'zh' => '签名密钥（可选）',
			'en' => 'Signing secret (optional)',
		),
		'field_webhook_secret_desc' => array(
			'zh' => '填写后每个请求会带 X-PSN-Signature: sha256=<hmac>，用它对原始请求体验签即可校验。',
			'en' => 'When set, each request carries X-PSN-Signature: sha256=<hmac> over the raw body.',
		),
		'field_webhook_headers'     => array(
			'zh' => '附加请求头（JSON）',
			'en' => 'Extra headers (JSON)',
		),
		'field_webhook_headers_desc' => array(
			'zh' => '例如 {"Authorization":"Bearer xxxx"}，留空即可。',
			'en' => 'For example {"Authorization":"Bearer xxxx"}. Leave empty if unused.',
		),
		'field_webhook_timeout'     => array(
			'zh' => '请求超时（秒）',
			'en' => 'Request timeout (s)',
		),
		'field_webhook_retries'     => array(
			'zh' => '失败重试次数',
			'en' => 'Retries on failure',
		),
		'field_webhook_retries_desc' => array(
			'zh' => '只对超时、连接失败、5xx、408、429 重试；4xx 直接失败。',
			'en' => 'Only timeouts, connection errors, 5xx, 408 and 429 are retried; other 4xx fail immediately.',
		),
		'field_notify_pending'      => array(
			'zh' => '待审核评论也推送',
			'en' => 'Also deliver comments awaiting moderation',
		),
		'field_notify_pending_desc' => array(
			'zh' => '默认只在评论审核通过后推送，避免垃圾评论刷屏。',
			'en' => 'By default only approved comments are delivered, keeping spam out of your bot.',
		),
		'field_include_paragraph'   => array(
			'zh' => '包含段评',
			'en' => 'Include paragraph comments',
		),
		'field_include_shuoshuo'    => array(
			'zh' => '包含「说说」下的评论',
			'en' => 'Include comments on Shuoshuo',
		),
		'field_notify_self'         => array(
			'zh' => '评论人是作者本人时也推送',
			'en' => 'Deliver when the commenter is the author',
		),
		'field_summary_tpl'         => array(
			'zh' => '单行摘要模板',
			'en' => 'One-line summary template',
		),
		'placeholder_summary'       => array(
			'zh' => '可用占位符：{site_name} {author} {post_title} {post_url} {comment_excerpt} {parent_author} {comment_url}',
			'en' => 'Placeholders: {site_name} {author} {post_title} {post_url} {comment_excerpt} {parent_author} {comment_url}',
		),

		// 后台：测试.
		'section_test'              => array(
			'zh' => '测试',
			'en' => 'Test tools',
		),
		'test_mail_btn'             => array(
			'zh' => '发送测试邮件',
			'en' => 'Send a test email',
		),
		'test_webhook_btn'          => array(
			'zh' => '发送测试 WebHook',
			'en' => 'Send a test webhook',
		),
		'test_mail_hint'            => array(
			'zh' => '测试邮件会发到站点管理员邮箱。',
			'en' => 'The test email goes to the site admin address.',
		),
		'test_webhook_hint'         => array(
			'zh' => '会用一条模拟评论真实请求上面的地址。',
			'en' => 'A fake comment payload is POSTed to the configured URL.',
		),
		'test_mail_ok'              => array(
			'zh' => '测试邮件已发出。',
			'en' => 'Test email sent.',
		),
		'test_mail_failed'          => array(
			'zh' => '测试邮件发送失败：',
			'en' => 'Test email failed: ',
		),
		'test_webhook_ok'           => array(
			'zh' => 'WebHook 请求成功',
			'en' => 'Webhook request succeeded',
		),
		'test_webhook_failed'       => array(
			'zh' => 'WebHook 请求失败',
			'en' => 'Webhook request failed',
		),
		'test_webhook_disabled'     => array(
			'zh' => '请先填写 WebHook 地址。',
			'en' => 'Please fill in the webhook URL first.',
		),

		// 后台：订阅者列表.
		'col_email'                 => array(
			'zh' => '邮箱',
			'en' => 'Email',
		),
		'col_name'                  => array(
			'zh' => '昵称',
			'en' => 'Name',
		),
		'col_status'                => array(
			'zh' => '状态',
			'en' => 'Status',
		),
		'col_source'                => array(
			'zh' => '来源',
			'en' => 'Source',
		),
		'col_created'               => array(
			'zh' => '订阅时间',
			'en' => 'Subscribed',
		),
		'col_confirmed'             => array(
			'zh' => '确认时间',
			'en' => 'Confirmed',
		),
		'col_last_mail'             => array(
			'zh' => '最近发信',
			'en' => 'Last email',
		),
		'status_label_pending'      => array(
			'zh' => '待确认',
			'en' => 'Pending',
		),
		'status_label_active'      => array(
			'zh' => '已确认',
			'en' => 'Active',
		),
		'status_label_unsubscribed' => array(
			'zh' => '已退订',
			'en' => 'Unsubscribed',
		),
		'status_label_bounced'      => array(
			'zh' => '已退信',
			'en' => 'Bounced',
		),
		'filter_all'                => array(
			'zh' => '全部',
			'en' => 'All',
		),
		'export_csv'                => array(
			'zh' => '导出 CSV',
			'en' => 'Export CSV',
		),
		'empty_subscribers'         => array(
			'zh' => '还没有订阅者。',
			'en' => 'No subscribers yet.',
		),
		'no_results'                => array(
			'zh' => '没有匹配的记录。',
			'en' => 'No matching records.',
		),
		'source_home_card'          => array(
			'zh' => '首页卡片',
			'en' => 'Home card',
		),
		'source_shortcode'          => array(
			'zh' => '短代码',
			'en' => 'Shortcode',
		),
		'source_test'               => array(
			'zh' => '测试',
			'en' => 'Test',
		),

		// 后台：日志.
		'col_type'                  => array(
			'zh' => '类型',
			'en' => 'Type',
		),
		'col_target'                => array(
			'zh' => '对象',
			'en' => 'Target',
		),
		'col_attempts'              => array(
			'zh' => '尝试',
			'en' => 'Attempts',
		),
		'col_response'              => array(
			'zh' => '响应',
			'en' => 'Response',
		),
		'col_available'             => array(
			'zh' => '下次尝试',
			'en' => 'Next attempt',
		),
		'col_error'                 => array(
			'zh' => '错误',
			'en' => 'Error',
		),
		'col_actions'               => array(
			'zh' => '操作',
			'en' => 'Actions',
		),
		'type_mail'                 => array(
			'zh' => '邮件',
			'en' => 'Email',
		),
		'type_webhook'              => array(
			'zh' => 'WebHook',
			'en' => 'WebHook',
		),
		'job_pending'               => array(
			'zh' => '待发送',
			'en' => 'Pending',
		),
		'job_done'                  => array(
			'zh' => '已发送',
			'en' => 'Sent',
		),
		'job_failed'                => array(
			'zh' => '失败',
			'en' => 'Failed',
		),
		'job_cancelled'             => array(
			'zh' => '已取消',
			'en' => 'Cancelled',
		),
		'action_resend'             => array(
			'zh' => '重发',
			'en' => 'Resend',
		),
		'action_view'               => array(
			'zh' => '查看载荷',
			'en' => 'View payload',
		),
		'resend_done'               => array(
			'zh' => '已重新入队。',
			'en' => 'Re-queued.',
		),
		'empty_jobs'                => array(
			'zh' => '暂无推送记录。',
			'en' => 'No deliveries yet.',
		),
		'job_post_label'            => array(
			'zh' => '文章',
			'en' => 'Post',
		),
		'job_comment_label'         => array(
			'zh' => '评论',
			'en' => 'Comment',
		),
		'job_subscriber_label'      => array(
			'zh' => '订阅者 #%d',
			'en' => 'Subscriber #%d',
		),
		'payload_empty'             => array(
			'zh' => '邮件任务不保存载荷，发送时按订阅者实时渲染。',
			'en' => 'Email jobs do not store a payload; content is rendered at send time.',
		),
	);
	return $strings;
}

/**
 * 当前界面语言：'zh' 或 'en'。
 *
 * @return string
 */
function psn_current_lang() {
	static $lang = null;

	if ( null !== $lang ) {
		return $lang;
	}

	$locale = '';
	if ( function_exists( 'get_user_locale' ) && is_admin() ) {
		$locale = (string) get_user_locale();
	} elseif ( function_exists( 'get_locale' ) ) {
		$locale = (string) get_locale();
	}

	$lang = ( 0 === strpos( $locale, 'zh' ) ) ? 'zh' : 'en';

	return $lang;
}

/**
 * 取一条界面文案。
 *
 * @param string $key 文案键名.
 * @return string
 */
function psn_t( $key ) {
	$strings = psn_strings();
	$lang    = psn_current_lang();

	if ( isset( $strings[ $key ][ $lang ] ) ) {
		return $strings[ $key ][ $lang ];
	}

	if ( isset( $strings[ $key ]['en'] ) ) {
		return $strings[ $key ]['en'];
	}

	return $key;
}
