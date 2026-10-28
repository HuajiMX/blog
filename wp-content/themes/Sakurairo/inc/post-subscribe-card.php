<?php
/**
 * 首页底部的新文章订阅卡片。
 *
 * 这一块 UI 属于主题：样式写在 css/subscribe-card.css 并随 theme.css 一起拼接，
 * 因此改了样式 cb 参数会自动变化，不需要清缓存。
 *
 * 观感直接复刻主题自己的评论表单（h3#comments-list-title + .comment-respond）：
 * 标题带主题色高亮条，卡片是 15px 圆角的半透明玻璃面，输入框与提交按钮
 * 沿用主题的 10px 圆角 / 45px 高度 / 主题色实底，暗色模式走主题的 --dark-* 变量。
 *
 * 插件的职责只是数据与接口（post-subscribe-notifier）：
 * - 通过 `psn_card_renderer` 过滤器把本文件注册进去；
 * - 表单提交走 admin-ajax 的 `psn_subscribe` action，返回 JSON 状态；
 * - JS 不可用时回退到原生 POST → 302。
 *
 * @package Sakurairo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 渲染订阅卡片。
 *
 * @param string $source 来源标识：home_card | shortcode.
 * @param array  $args   覆盖标题/描述.
 * @return string
 */
function iro_psn_card_html( $source = 'home_card', $args = array() ) {
	$status = class_exists( 'PSN_Signup' ) ? PSN_Signup::current_status() : '';

	// 退订二次确认：只给确认条，不显示订阅表单。
	if ( 'unsub_confirm' === $status ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- token 本身即凭证。
		$token = isset( $_GET['psn_unsub'] ) ? sanitize_text_field( wp_unslash( $_GET['psn_unsub'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$uid = isset( $_GET['psn_uid'] ) ? (int) $_GET['psn_uid'] : 0;

		$html  = '<section id="psn-subscribe" class="psn-subscribe psn-subscribe--confirm" data-source="unsub">';
		$html .= '<h2 class="psn-subscribe__title">' . esc_html__( '退订新文章推送', 'sakurairo' ) . '</h2>';
		$html .= '<div class="psn-card">';
		$html .= '<p class="psn-card__desc">' . esc_html__( '确认后，你将不再收到新文章推送邮件。此操作不可撤销，之后仍可重新订阅。', 'sakurairo' ) . '</p>';
		$html .= '<form class="psn-form" method="post" action="' . esc_url( home_url( '/' ) ) . '">';
		$html .= '<input type="hidden" name="psn_unsub_do" value="1" />';
		$html .= '<input type="hidden" name="psn_unsub" value="' . esc_attr( $token ) . '" />';
		$html .= '<input type="hidden" name="psn_uid" value="' . esc_attr( (string) $uid ) . '" />';
		$html .= '<div class="psn-actions">';
		$html .= '<button type="submit" class="psn-button psn-button--danger">' . esc_html__( '确认退订', 'sakurairo' ) . '</button>';
		$html .= '</div></form></div></section>';

		return $html;
	}

	$title = ! empty( $args['title'] ) ? (string) $args['title'] : __( '订阅新文章', 'sakurairo' );
	$desc  = ! empty( $args['desc'] ) ? (string) $args['desc'] : __( '留下邮箱，每发布一篇新文章都会推送到你的邮箱。首次订阅需要点击验证邮件里的确认链接。', 'sakurairo' );

	$notice_class = '';
	$notice_text  = '';

	if ( '' !== $status && class_exists( 'PSN_Signup' ) ) {
		$notice_text  = PSN_Signup::status_message( $status );
		$notice_class = ' is-' . PSN_Signup::status_tone( $status );
	}

	$action = esc_url( remove_query_arg( array( 'psn_status', 'psn_confirm', 'psn_unsub', 'psn_uid' ) ) );
	$ajax   = esc_url( admin_url( 'admin-ajax.php' ) );

	// 颜色一律交给主题：卡片 CSS 里直接用 --article-theme-highlight / --theme-skin-matching。
	$html  = '<section id="psn-subscribe" class="psn-subscribe" data-source="' . esc_attr( $source ) . '">';
	$html .= '<h2 class="psn-subscribe__title">' . esc_html( $title ) . '<span>' . esc_html__( 'Subscribe', 'sakurairo' ) . '</span></h2>';
	$html .= '<div class="psn-card" data-ajax="' . $ajax . '">';
	$html .= '<p class="psn-card__desc">' . esc_html( $desc ) . '</p>';

	$html .= '<p class="psn-notice' . esc_attr( $notice_class ) . '" role="status" aria-live="polite"' . ( '' === $notice_text ? ' hidden' : '' ) . '>' . esc_html( $notice_text ) . '</p>';

	$html .= '<form class="psn-form" method="post" action="' . $action . '" novalidate>';
	$html .= '<input type="hidden" name="' . esc_attr( PSN_Signup::FORM_ACTION ) . '" value="1" />';
	$html .= '<input type="hidden" name="psn_source" value="' . esc_attr( $source ) . '" />';
	// 蜜罐：真人看不见也不会填，填了就是机器人。
	$html .= '<div class="psn-honeypot" aria-hidden="true"><label>Website<input type="text" name="psn_website" value="" tabindex="-1" autocomplete="off" /></label></div>';
	$html .= '<div class="psn-fields">';
	$html .= '<label class="psn-field psn-field--email"><span class="psn-label screen-reader-text">' . esc_html__( '邮箱地址', 'sakurairo' ) . '</span>';
	$html .= '<input type="email" name="psn_email" class="psn-input" required maxlength="254" autocomplete="email" placeholder="' . esc_attr__( '邮箱（必填）', 'sakurairo' ) . '" /></label>';
	$html .= '<label class="psn-field psn-field--name"><span class="psn-label screen-reader-text">' . esc_html__( '昵称（可选）', 'sakurairo' ) . '</span>';
	$html .= '<input type="text" name="psn_name" class="psn-input" maxlength="100" autocomplete="nickname" placeholder="' . esc_attr__( '昵称（可选）', 'sakurairo' ) . '" /></label>';
	$html .= '</div>';
	$html .= '<div class="psn-actions">';
	$html .= '<button type="submit" class="psn-button"><i class="fa-regular fa-paper-plane" aria-hidden="true"></i><span class="psn-button__text">' . esc_html__( '订阅', 'sakurairo' ) . '</span></button>';
	// 备用订阅渠道：桌面端与按钮同一行，补上按钮左侧的空白；窄屏由 CSS 排到按钮下方。
	$html .= '<p class="psn-alt">' . esc_html__( '【通过 QQ 订阅】添加 QQ：2776553225 后发送“博客订阅”，按提示操作完成订阅。', 'sakurairo' ) . '</p>';
	$html .= '</div>';
	$html .= '</form>';
	$html .= '</div></section>';

	return $html;
}

/**
 * 把本主题的渲染函数注册给插件。
 *
 * 插件不包含任何 UI，只认这个过滤器；没装插件时过滤器没人为它注册，
 * 也就不渲染卡片（下方的 add_filter 只有在插件激活时才生效）。
 *
 * @param callable|null $renderer 已有渲染器.
 * @return callable
 */
function iro_psn_register_card_renderer( $renderer ) {
	return 'iro_psn_card_html';
}
add_filter( 'psn_card_renderer', 'iro_psn_register_card_renderer' );

/**
 * 首页底部输出卡片（只在首页第一页）。
 *
 * 挂在 get_footer：此时 #content 还没闭合，输出落在 #page 里，
 * 主题 PJAX 替换 #page 时会一起重建。
 *
 * @return void
 */
function iro_psn_render_home_card() {
	if ( is_admin() || ! is_home() || is_paged() ) {
		return;
	}

	if ( ! class_exists( 'PSN_Signup' ) || ! PSN_Signup::card_available() ) {
		return;
	}

	echo iro_psn_card_html( 'home_card' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 内部已转义。
}
add_action( 'get_footer', 'iro_psn_render_home_card' );

/**
 * 需要卡片时加载交互脚本。
 *
 * 脚本用事件委托 + 幂等初始化，PJAX 换页后无需重新绑定；
 * 版本按 filemtime 生成，避免边缘缓存住旧脚本。
 *
 * @return void
 */
function iro_psn_enqueue_script() {
	if ( ! class_exists( 'PSN_Signup' ) || ! PSN_Signup::card_available() ) {
		return;
	}

	$rel  = '/js/post-subscribe.js';
	$path = get_template_directory() . $rel;
	$ver  = @filemtime( $path ) ?: IRO_VERSION;

	wp_enqueue_script(
		'iro-post-subscribe',
		get_template_directory_uri() . $rel,
		array(),
		$ver,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'iro_psn_enqueue_script' );
