<?php
/**
 * 后台测试按钮的处理（测试邮件 / 测试 WebHook）。
 *
 * @package Post_Subscribe_Notifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 诊断动作。
 */
class PSN_Diagnostics {

	const ACTION_MAIL    = 'psn_test_mail';
	const ACTION_WEBHOOK = 'psn_test_webhook';
	const NONCE          = 'psn_test_action';

	/**
	 * 挂载钩子。
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_post_' . self::ACTION_MAIL, array( __CLASS__, 'handle_test_mail' ) );
		add_action( 'admin_post_' . self::ACTION_WEBHOOK, array( __CLASS__, 'handle_test_webhook' ) );
	}

	/**
	 * 测试 URL。
	 *
	 * @param string $action 动作.
	 * @return string
	 */
	public static function action_url( $action ) {
		$url = admin_url( 'admin-post.php' );

		return wp_nonce_url(
			add_query_arg( 'action', $action, $url ),
			self::NONCE
		);
	}

	/**
	 * 测试邮件。
	 *
	 * @return void
	 */
	public static function handle_test_mail() {
		self::guard();

		$to   = get_option( 'admin_email' );
		$sent = PSN_Mailer::send_test( $to );

		$args = $sent
			? array( 'psn_test' => 'mail_ok' )
			: array( 'psn_test' => 'mail_failed' );

		self::back( $args );
	}

	/**
	 * 测试 WebHook。
	 *
	 * @return void
	 */
	public static function handle_test_webhook() {
		self::guard();

		$result = PSN_Webhook::send_test();

		$args = array(
			'psn_test'     => $result['ok'] ? 'webhook_ok' : 'webhook_failed',
			'psn_code'     => (int) $result['code'],
			'psn_response' => rawurlencode( mb_substr( (string) $result['error'], 0, 300 ) ),
		);

		self::back( $args );
	}

	/**
	 * 权限与 nonce 校验。
	 *
	 * @return void
	 */
	private static function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'post-subscribe-notifier' ), 403 );
		}

		check_admin_referer( self::NONCE );
	}

	/**
	 * 回跳到设置页。
	 *
	 * @param array $args query 参数.
	 * @return void
	 */
	private static function back( $args ) {
		$url = add_query_arg( $args, admin_url( 'admin.php?page=psn-settings' ) );

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * 渲染设置页顶部的测试结果提示。
	 *
	 * @return void
	 */
	public static function render_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 只读提示。
		$test = isset( $_GET['psn_test'] ) ? sanitize_key( wp_unslash( $_GET['psn_test'] ) ) : '';

		if ( '' === $test ) {
			return;
		}

		$class  = 'notice-info';
		$text   = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code = isset( $_GET['psn_code'] ) ? (int) $_GET['psn_code'] : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$resp = isset( $_GET['psn_response'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['psn_response'] ) ) ) : '';

		switch ( $test ) {
			case 'mail_ok':
				$class = 'notice-success';
				$text  = psn_t( 'test_mail_ok' );
				break;
			case 'mail_failed':
				$class = 'notice-error';
				$text  = psn_t( 'test_mail_failed' ) . esc_html__( '见 WP Mail SMTP 的邮件日志。', 'post-subscribe-notifier' );
				break;
			case 'webhook_ok':
				$class = 'notice-success';
				$text  = sprintf( '%s（HTTP %d）%s', psn_t( 'test_webhook_ok' ), $code, '' !== $resp ? '：' . $resp : '' );
				break;
			case 'webhook_failed':
				$class = 'notice-error';
				$text  = sprintf( '%s（HTTP %d）%s', psn_t( 'test_webhook_failed' ), $code, '' !== $resp ? '：' . $resp : '' );
				break;
			default:
				return;
		}

		printf(
			'<div class="notice %s is-dismissible"><p>%s</p></div>',
			esc_attr( $class ),
			esc_html( $text )
		);
	}
}
