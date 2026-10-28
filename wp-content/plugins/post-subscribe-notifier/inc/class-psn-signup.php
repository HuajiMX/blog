<?php
/**
 * 前台订阅：卡片渲染、表单处理、确认与退订端点。
 *
 * 表单是纯服务端 POST → 302 回跳，不依赖 JS、不依赖 nonce，
 * 因此页面被 W3TC / CDN 缓存、以及主题 PJAX 只重放 #app-js-before 脚本时都不会失效。
 *
 * @package Post_Subscribe_Notifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 前台入口。
 */
class PSN_Signup {

	const FORM_ACTION = 'psn_subscribe';

	/**
	 * 同一 IP 10 分钟内允许的提交次数。
	 */
	const RATE_LIMIT = 3;

	/**
	 * 允许回显的状态（白名单，不反射任何用户输入）。
	 *
	 * @return string[]
	 */
	private static function status_keys() {
		return array(
			'pending',
			'confirmed',
			'invalid_email',
			'already_active',
			'rate_limited',
			'mail_failed',
			'error',
			'expired',
			'unknown_token',
			'unsubscribed',
			'unsub_invalid',
			'unsub_confirm',
			'disabled',
		);
	}

	/**
	 * 挂载钩子。
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'handle_requests' ) );
		add_action( 'get_footer', array( __CLASS__, 'render_home_card' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_shortcode( 'psn_subscribe', array( __CLASS__, 'shortcode' ) );
	}

	/**
	 * 前台样式（只在需要时加载）。
	 *
	 * @return void
	 */
	public static function enqueue_assets() {
		$settings = PSN_Settings::get();

		if ( empty( $settings['mail_enabled'] ) ) {
			return;
		}

		$needs = ( is_home() && ! is_paged() ) || ! empty( $GLOBALS['psn_shortcode_used'] );

		// 表单提交后的落地页也要样式（提示条）。
		if ( isset( $_GET['psn_status'] ) ) {
			$needs = true;
		}

		if ( ! $needs ) {
			return;
		}

		$path = PSN_DIR . 'assets/subscribe.css';

		wp_enqueue_style(
			'psn-subscribe',
			PSN_URL . 'assets/subscribe.css',
			array(),
			@filemtime( $path ) ?: PSN_VERSION
		);
	}

	/**
	 * 首页底部卡片。
	 *
	 * 挂在 get_footer：此时 #content 还没闭合，输出落在 #page 内，
	 * 主题 PJAX 替换 #page 时会一起重建。
	 *
	 * @return void
	 */
	public static function render_home_card() {
		if ( is_admin() || ! is_home() || is_paged() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 模板内部已转义。
		echo self::card( 'home_card' );
	}

	/**
	 * 短代码。
	 *
	 * @param array $atts 属性.
	 * @return string
	 */
	public static function shortcode( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'title' => '',
				'desc'  => '',
			),
			(array) $atts,
			'psn_subscribe'
		);

		$GLOBALS['psn_shortcode_used'] = true;

		return self::card( 'shortcode', $atts );
	}

	/**
	 * 渲染卡片。
	 *
	 * @param string $source 来源标识.
	 * @param array  $atts   覆盖标题/描述.
	 * @return string
	 */
	public static function card( $source = 'home_card', $atts = array() ) {
		$settings = PSN_Settings::get();

		if ( empty( $settings['mail_enabled'] ) ) {
			return '';
		}

		$status = self::current_status();

		// 退订二次确认：只渲染确认条，不渲染表单。
		if ( 'unsub_confirm' === $status ) {
			return self::unsub_confirm_block();
		}

		$title = '' !== (string) ( $atts['title'] ?? '' ) ? (string) $atts['title'] : psn_t( 'card_title' );
		$desc  = '' !== (string) ( $atts['desc'] ?? '' ) ? (string) $atts['desc'] : psn_t( 'card_desc' );

		$notice = self::notice( $status );

		// 主题色可能被封面提取成很浅的颜色，先算到满足对比度再输出。
		$palette = PSN_Color::button_palette();

		// 保留路径上的 query，避免回跳时丢掉别的参数（如分页）。
		$action = esc_url( remove_query_arg( array( 'psn_status', 'psn_confirm', 'psn_unsub', 'psn_uid' ) ) );

		$style = sprintf(
			'--psn-accent:%1$s;--psn-on-accent:%2$s;--psn-ring:%3$s;',
			esc_attr( $palette['accent'] ),
			esc_attr( $palette['on_accent'] ),
			esc_attr( $palette['ring'] )
		);

		$html  = '<section id="psn-subscribe" class="psn-card" data-source="' . esc_attr( $source ) . '" style="' . $style . '">';
		$html .= '<div class="psn-card-inner">';
		$html .= '<h2 class="psn-card-title">' . esc_html( $title ) . '</h2>';
		$html .= '<p class="psn-card-desc">' . esc_html( $desc ) . '</p>';
		$html .= $notice;
		$html .= '<form class="psn-form" method="post" action="' . $action . '">';
		$html .= '<input type="hidden" name="' . esc_attr( self::FORM_ACTION ) . '" value="1" />';
		$html .= '<input type="hidden" name="psn_source" value="' . esc_attr( $source ) . '" />';
		// 蜜罐：真人看不到也不会填，填了就是机器人。
		$html .= '<div class="psn-honeypot" aria-hidden="true"><label>Website<input type="text" name="psn_website" value="" tabindex="-1" autocomplete="off" /></label></div>';
		$html .= '<div class="psn-fields">';
		$html .= '<label class="psn-field"><span class="psn-label">' . esc_html( psn_t( 'field_email' ) ) . '</span>';
		$html .= '<input type="email" name="psn_email" class="psn-input" required maxlength="254" autocomplete="email" placeholder="you@example.com" /></label>';
		$html .= '<label class="psn-field psn-field-name"><span class="psn-label">' . esc_html( psn_t( 'field_name' ) ) . '</span>';
		$html .= '<input type="text" name="psn_name" class="psn-input" maxlength="100" autocomplete="nickname" placeholder="' . esc_attr( psn_current_lang() === 'zh' ? '可选' : 'optional' ) . '" /></label>';
		$html .= '</div>';
		$html .= '<button type="submit" class="psn-submit">' . esc_html( psn_t( 'btn_subscribe' ) ) . '</button>';
		$html .= '</form>';
		$html .= '</div>';
		$html .= '</section>';

		return $html;
	}

	/**
	 * 当前展示的状态（只读 query，白名单校验）。
	 *
	 * @return string
	 */
	private static function current_status() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 只读的白名单状态值。
		$status = isset( $_GET['psn_status'] ) ? sanitize_key( wp_unslash( $_GET['psn_status'] ) ) : '';

		return in_array( $status, self::status_keys(), true ) ? $status : '';
	}

	/**
	 * 状态提示条。
	 *
	 * @param string $status 状态.
	 * @return string
	 */
	private static function notice( $status ) {
		if ( '' === $status || 'unsub_confirm' === $status ) {
			return '';
		}

		$map = array(
			'pending'        => array( 'status_pending', 'is-info' ),
			'confirmed'      => array( 'status_confirmed', 'is-success' ),
			'invalid_email'  => array( 'status_invalid_email', 'is-error' ),
			'already_active' => array( 'status_already_active', 'is-info' ),
			'rate_limited'   => array( 'status_rate_limited', 'is-error' ),
			'mail_failed'    => array( 'status_mail_failed', 'is-error' ),
			'error'          => array( 'status_error', 'is-error' ),
			'expired'        => array( 'status_expired', 'is-error' ),
			'unknown_token'  => array( 'status_unknown_token', 'is-error' ),
			'unsubscribed'   => array( 'status_unsubscribed', 'is-info' ),
			'unsub_invalid'  => array( 'status_unsub_invalid', 'is-error' ),
			'disabled'       => array( 'status_disabled', 'is-info' ),
		);

		if ( ! isset( $map[ $status ] ) ) {
			return '';
		}

		return '<p class="psn-notice ' . esc_attr( $map[ $status ][1] ) . '" role="status">' . esc_html( psn_t( $map[ $status ][0] ) ) . '</p>';
	}

	/**
	 * 退订二次确认块（邮件客户端的一键退订走 POST，这里的 GET 走二次确认）。
	 *
	 * @return string
	 */
	private static function unsub_confirm_block() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- token 本身即凭证。
		$token = isset( $_GET['psn_unsub'] ) ? sanitize_text_field( wp_unslash( $_GET['psn_unsub'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$uid = isset( $_GET['psn_uid'] ) ? (int) $_GET['psn_uid'] : 0;

		$row = PSN_Subscribers::resolve_unsub_token( $uid, $token );
		if ( ! $row ) {
			return '<section id="psn-subscribe" class="psn-card"><div class="psn-card-inner">'
				. '<p class="psn-notice is-error" role="status">' . esc_html( psn_t( 'status_unsub_invalid' ) ) . '</p>'
				. '</div></section>';
		}

		$html  = '<section id="psn-subscribe" class="psn-card"><div class="psn-card-inner">';
		$html .= '<h2 class="psn-card-title">' . esc_html( psn_t( 'status_unsub_confirm' ) ) . '</h2>';
		$html .= '<form class="psn-form" method="post" action="' . esc_url( home_url( '/' ) ) . '">';
		$html .= '<input type="hidden" name="psn_unsub_do" value="1" />';
		$html .= '<input type="hidden" name="psn_unsub" value="' . esc_attr( $token ) . '" />';
		$html .= '<input type="hidden" name="psn_uid" value="' . esc_attr( (string) $uid ) . '" />';
		$html .= '<button type="submit" class="psn-submit psn-submit-danger">' . esc_html( psn_t( 'btn_unsubscribe_confirm' ) ) . '</button>';
		$html .= '</form></div></section>';

		return $html;
	}

	/**
	 * 处理提交、确认、退订，处理完一律 302 回跳。
	 *
	 * @return void
	 */
	public static function handle_requests() {
		if ( is_admin() ) {
			return;
		}

		// 新订阅（POST）。
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- 用蜜罐 + 频率限制 + 二次确认替代。
		if ( isset( $_POST[ self::FORM_ACTION ] ) ) {
			self::handle_subscribe();

			return;
		}

		// 退订（POST，邮件客户端 List-Unsubscribe-Post 也打到这里）。
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['psn_unsub_do'] ) ) {
			self::handle_unsubscribe_post();

			return;
		}

		// 确认链接（GET）。
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['psn_confirm'] ) ) {
			self::handle_confirm();

			return;
		}

		// 退订链接（GET，先给二次确认）。
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['psn_unsub'] ) ) {
			self::redirect( 'unsub_confirm', array( 'psn_unsub' => sanitize_text_field( wp_unslash( $_GET['psn_unsub'] ) ), 'psn_uid' => (int) ( $_GET['psn_uid'] ?? 0 ) ) );
		}
	}

	/**
	 * 处理订阅表单（从 $_POST 读取后交给纯函数）。
	 *
	 * @return void
	 */
	private static function handle_subscribe() {
		$input = array(
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			'website' => isset( $_POST['psn_website'] ) ? (string) wp_unslash( $_POST['psn_website'] ) : '',
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			'email'   => isset( $_POST['psn_email'] ) ? (string) wp_unslash( $_POST['psn_email'] ) : '',
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			'name'    => isset( $_POST['psn_name'] ) ? (string) wp_unslash( $_POST['psn_name'] ) : '',
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			'source'  => isset( $_POST['psn_source'] ) ? (string) wp_unslash( $_POST['psn_source'] ) : 'home_card',
		);

		self::redirect( self::process_subscription( $input, self::ip_hash() ) );
	}

	/**
	 * 订阅流程（纯逻辑，方便测试）。
	 *
	 * @param array  $input   表单字段 website/email/name/source.
	 * @param string $ip_hash 访客 IP 哈希.
	 * @return string 状态键
	 */
	public static function process_subscription( $input, $ip_hash = '' ) {
		$settings = PSN_Settings::get();

		if ( empty( $settings['mail_enabled'] ) ) {
			return 'disabled';
		}

		// 蜜罐命中：对机器人假装成功，不产生任何写入。
		if ( '' !== trim( (string) ( $input['website'] ?? '' ) ) ) {
			return 'pending';
		}

		$email = strtolower( trim( preg_replace( '#[\r\n\t]#', '', (string) ( $input['email'] ?? '' ) ) ) );

		if ( ! is_email( $email ) || strlen( $email ) > 254 ) {
			return 'invalid_email';
		}

		$name   = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
		$source = sanitize_key( (string) ( $input['source'] ?? 'home_card' ) );
		$source = in_array( $source, array( 'home_card', 'shortcode' ), true ) ? $source : 'home_card';

		if ( self::is_rate_limited( $email ) ) {
			return 'rate_limited';
		}

		$result = PSN_Subscribers::create(
			array(
				'email'   => $email,
				'name'    => $name,
				'source'  => $source,
				'ip_hash' => $ip_hash,
			)
		);

		if ( empty( $result['ok'] ) ) {
			return 'error';
		}

		// 已确认的邮箱不计入频率（否则连续试探已订阅地址会被误伤）。
		if ( PSN_Subscribers::STATUS_ACTIVE !== $result['status'] ) {
			self::mark_attempt( $email );
		}

		if ( PSN_Subscribers::STATUS_ACTIVE === $result['status'] ) {
			return 'already_active';
		}

		$token = (string) $result['token'];

		if ( '' === $token ) {
			// 并发撞库时 create 返回空 token：再走一次即可轮换出新 token。
			$again = PSN_Subscribers::create(
				array(
					'email'   => $email,
					'name'    => $name,
					'source'  => $source,
					'ip_hash' => $ip_hash,
				)
			);
			$token = (string) ( $again['token'] ?? '' );
		}

		if ( '' === $token ) {
			return 'error';
		}

		return PSN_Mailer::send_confirmation( $email, $token, $name ) ? 'pending' : 'mail_failed';
	}

	/**
	 * 处理 GET 确认链接。
	 *
	 * @return void
	 */
	private static function handle_confirm() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = sanitize_text_field( wp_unslash( $_GET['psn_confirm'] ) );

		self::redirect( self::process_confirm( $token ) );
	}

	/**
	 * 确认流程（纯逻辑）。
	 *
	 * @param string $token 明文 token.
	 * @return string 状态键
	 */
	public static function process_confirm( $token ) {
		$result = PSN_Subscribers::confirm( $token );

		if ( $result['ok'] ) {
			return 'confirmed';
		}

		return 'expired' === $result['reason'] ? 'expired' : 'unknown_token';
	}

	/**
	 * 处理 POST 退订。
	 *
	 * @return void
	 */
	private static function handle_unsubscribe_post() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$token = sanitize_text_field( wp_unslash( $_POST['psn_unsub'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$uid = (int) ( $_POST['psn_uid'] ?? 0 );

		self::redirect( self::process_unsubscribe( $uid, $token ) );
	}

	/**
	 * 退订流程（纯逻辑）。
	 *
	 * @param int    $uid   订阅者 ID.
	 * @param string $token 明文 token.
	 * @return string 状态键
	 */
	public static function process_unsubscribe( $uid, $token ) {
		return PSN_Subscribers::unsubscribe( (int) $uid, (string) $token ) ? 'unsubscribed' : 'unsub_invalid';
	}

	/**
	 * 302 回跳到首页并带上状态。
	 *
	 * @param string $status 状态键.
	 * @param array  $keep   需要保留的参数.
	 * @return void
	 */
	private static function redirect( $status, $keep = array() ) {
		$args = array_merge( $keep, array( 'psn_status' => $status ) );

		$url = add_query_arg( $args, home_url( '/' ) ) . '#psn-subscribe';

		wp_safe_redirect( $url, 302 );
		exit;
	}

	/**
	 * 简单的频率限制：同一 IP 10 分钟内最多 3 次，同一邮箱 1 分钟内不重复。
	 *
	 * @param string $email 邮箱.
	 * @return bool
	 */
	public static function is_rate_limited( $email ) {
		if ( (int) get_transient( 'psn_rate_ip_' . self::ip_hash() ) >= self::RATE_LIMIT ) {
			return true;
		}

		return (bool) get_transient( 'psn_rate_mail_' . md5( strtolower( trim( (string) $email ) ) ) );
	}

	/**
	 * 记录一次提交。
	 *
	 * IP 与邮箱分开计：IP 限制批量尝试，邮箱限制同一地址的连续重发。
	 *
	 * @param string $email 邮箱.
	 * @return void
	 */
	public static function mark_attempt( $email ) {
		$key   = 'psn_rate_ip_' . self::ip_hash();
		$count = (int) get_transient( $key );

		set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );
		set_transient( 'psn_rate_mail_' . md5( strtolower( trim( (string) $email ) ) ), 1, MINUTE_IN_SECONDS );
	}

	/**
	 * 清掉频率限制的 transient（测试用）。
	 *
	 * 邮箱按小写匹配，与 process_subscription 的规范化保持一致。
	 *
	 * @param string $email 邮箱.
	 * @return void
	 */
	public static function reset_rate_limits( $email = '' ) {
		delete_transient( 'psn_rate_ip_' . self::ip_hash() );

		$email = strtolower( trim( (string) $email ) );
		if ( '' !== $email ) {
			delete_transient( 'psn_rate_mail_' . md5( $email ) );
		}
	}

	/**
	 * 访客 IP 的哈希（不留明文 IP）。
	 *
	 * IP 取值走全站唯一入口 iro_get_client_ip()（mu-plugins/iro-real-ip.php），
	 * 它已处理 CDN 转发头与伪造校验，本插件不再自己解析。
	 *
	 * @return string
	 */
	private static function ip_hash() {
		$ip = '';

		if ( function_exists( 'iro_get_client_ip' ) ) {
			$ip = (string) iro_get_client_ip();
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = (string) wp_unslash( $_SERVER['REMOTE_ADDR'] );
		}

		/**
		 * 过滤访客 IP（在哈希之前）。
		 *
		 * @param string $ip IP.
		 */
		$ip = (string) apply_filters( 'psn_client_ip', $ip );

		return substr( hash( 'sha1', $ip . '|' . wp_salt( 'nonce' ) ), 0, 40 );
	}
}
